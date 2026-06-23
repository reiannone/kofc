<?php
/**
 * recommend.php — core endpoint.
 * Flow: cors -> auth -> validate -> load KB -> strict-JSON prompt (+ retrieved passages)
 *       -> AI (or mock) -> parse -> guardrails -> persist (audit) -> return.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/guardrails.php';
require __DIR__ . '/kb.php';
require __DIR__ . '/prompts.php';

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

    // Derive age from DOB when age wasn't supplied (keeps the 18+ gate working either way).
    if ((!isset($profile['age']) || $profile['age'] === '' || $profile['age'] === null) && !empty($profile['member_dob'])) {
        $profile['age'] = kofc_age_from_dob((string)$profile['member_dob']);
    }

    if (!isset($profile['age']) || (int)$profile['age'] < 18) {
        http_response_code(422);
        echo json_encode(['error' => 'age is required and must be 18+']);
        exit;
    }

    $kb = require __DIR__ . '/products.php';

    [$system, $userMsg] = kofc_build_prompt($profile, $kb);

    // Retrieval: pull the closest KofC source passages and append them to the prompt.
    // Returns '' safely when the KB is empty, errors, or ai_mock is on.
    $kbCtx = kofc_kb_context(($profile['primary_goal'] ?? '') . ' KofC insurance options');
    if ($kbCtx !== '') { $userMsg .= "\n\n" . $kbCtx; }

    $aiRaw   = AI_MOCK ? kofc_mock_ai() : kofc_ai_complete($system, $userMsg, true);
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

    $system = kofc_render(kofc_prompt_or('recommend_system'), [
        'eligibility_note' => $kb['eligibility_note'] ?? '',
    ]);

    $userMsg =
        "MEMBER PROFILE:\n" . json_encode($profile, JSON_PRETTY_PRINT) . "\n\n"
      . "KofC PRODUCT CATALOG:\n" . $catalog;

    return [$system, $userMsg];
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

/** Whole-year age from a date-of-birth string, or null if unparseable / out of range. */
function kofc_age_from_dob(string $dob): ?int
{
    try {
        $d   = new DateTime($dob);
        $age = (new DateTime('today'))->diff($d)->y;
        return ($age >= 0 && $age <= 120) ? $age : null;
    } catch (Throwable $e) {
        return null;
    }
}
