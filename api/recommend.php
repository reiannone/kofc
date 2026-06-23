<?php
/**
 * recommend.php — core endpoint.
 * Flow: cors -> auth -> validate profile -> load KB -> build strict-JSON prompt -> call AI (or mock)
 *       -> parse -> run guardrails -> persist (audit) -> return.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/guardrails.php';

kofc_cors();

try {
    $agentId = kofc_require_agent();

    $raw     = file_get_contents('php://input');
    $profile = json_decode($raw, true);
    if (!is_array($profile)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON body']);
        exit;
    }

    if (!isset($profile['age']) || (int)$profile['age'] < 18) {
        http_response_code(422);
        echo json_encode(['error' => 'age is required and must be 18+']);
        exit;
    }

    $kb = require __DIR__ . '/products.php';

    [$system, $userMsg] = kofc_build_prompt($profile, $kb);
    $aiRaw   = kofc_call_ai($system, $userMsg);
    $parsed  = kofc_extract_json($aiRaw);

    $items   = $parsed['recommendations'] ?? [];
    $flags   = kofc_run_guardrails($profile, $items);

    $pdo = kofc_db();
    $stmt = $pdo->prepare(
        'INSERT INTO recommendations
            (agent_id, member_profile, ai_model, ai_output, guardrail_flags, status, created_at)
         VALUES (:agent, :profile, :model, :out, :flags, "pending_review", NOW())'
    );
    $stmt->execute([
        ':agent'   => $agentId,
        ':profile' => json_encode($profile),
        ':model'   => AI_MOCK ? AI_MODEL . ' (mock)' : AI_MODEL,
        ':out'     => json_encode($parsed),
        ':flags'   => json_encode($flags),
    ]);
    $recId = (int)$pdo->lastInsertId();

    echo json_encode([
        'recommendation_id'     => $recId,
        'recommendations'       => $items,
        'guardrail_flags'       => $flags,
        'requires_agent_review' => true,
        'disclaimer'            => 'Initial AI-generated recommendation for KofC field-agent review. '
                                 . 'Not a suitability determination. Final recommendation rests with the licensed agent.',
    ]);

} catch (Throwable $e) {
    error_log('recommend.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}

/* ------------------------------------------------------------------ */

function kofc_build_prompt(array $profile, array $kb): array
{
    $catalog = json_encode([
        'products' => $kb['products'],
        'riders'   => $kb['riders'],
    ], JSON_PRETTY_PRINT);

    $system =
        "You are an initial-recommendation engine for Knights of Columbus field agents.\n"
      . "You ONLY recommend from the provided KofC product catalog. You never invent products,\n"
      . "rates, or guarantees. You produce an INITIAL recommendation for a licensed agent to\n"
      . "review — not a suitability determination.\n\n"
      . "Eligibility context: " . $kb['eligibility_note'] . "\n\n"
      . "Rank up to 4 products by fit. For each, give a confidence (0.0-1.0), a plain-language\n"
      . "rationale tied to the member's stated facts, relevant rider suggestions, and a rough\n"
      . "estimated_annual_premium ONLY if reasonably inferable (else null). Be conservative;\n"
      . "if data is thin, lower confidence and say what's missing.\n\n"
      . "Respond with ONLY a JSON object, no markdown, no preamble, in exactly this shape:\n"
      . '{"recommendations":[{"product_id":"","product_name":"","confidence":0.0,'
      . '"rationale":"","suggested_riders":[],"estimated_annual_premium":null,"missing_info":[]}]}';

    $userMsg =
        "MEMBER PROFILE:\n" . json_encode($profile, JSON_PRETTY_PRINT) . "\n\n"
      . "KofC PRODUCT CATALOG:\n" . $catalog;

    return [$system, $userMsg];
}

function kofc_call_ai(string $system, string $userMsg): string
{
    if (AI_MOCK) {
        return kofc_mock_ai();
    }

    $payload = [
        'model'      => AI_MODEL,
        'max_tokens' => AI_MAX_TOKENS,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userMsg]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 45,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code >= 300) {
        throw new RuntimeException('AI call failed (HTTP ' . $code . ')');
    }
    curl_close($ch);

    $data = json_decode($resp, true);
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    return $text;
}

/**
 * Canned response for AI_MOCK mode — lets the full pipeline run with no key/cost.
 */
function kofc_mock_ai(): string
{
    return json_encode(['recommendations' => [
        [
            'product_id' => 'term_life', 'product_name' => 'Term Life Insurance',
            'confidence' => 0.82,
            'rationale' => 'Mock: working-age member with dependents and a mortgage; affordable income replacement during peak obligation years.',
            'suggested_riders' => [], 'estimated_annual_premium' => 720, 'missing_info' => [],
        ],
        [
            'product_id' => 'annuity_fpa', 'product_name' => 'Retirement Annuity — Flexible Premium',
            'confidence' => 0.55,
            'rationale' => 'Mock: secondary retirement-savings option via flexible contributions.',
            'suggested_riders' => [], 'estimated_annual_premium' => null, 'missing_info' => ['retirement_horizon'],
        ],
    ]]);
}

function kofc_extract_json(string $text): array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false) {
        return ['recommendations' => []];
    }
    $json = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : ['recommendations' => []];
}
