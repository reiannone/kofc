<?php
/**
 * ask.php — free-form Q&A grounded in the KofC product catalog + retrieved source passages.
 * Stays on-product, declines member-specific suitability/tax/legal advice, carries the
 * agent-review disclaimer. Mock-aware. Logs to advisor_questions (best-effort).
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/kb.php';

kofc_cors();

try {
    $agentId = kofc_require_agent();

    $body     = json_decode(file_get_contents('php://input'), true);
    $question = is_array($body) ? trim((string)($body['question'] ?? '')) : '';
    if ($question === '') {
        http_response_code(422);
        echo json_encode(['error' => 'question is required']);
        exit;
    }

    $kb = require __DIR__ . '/products.php';
    [$system, $userMsg] = kofc_ask_prompt($question, $kb);

    // Retrieval: append the closest KofC source passages to the prompt.
    // Returns '' safely when the KB is empty, errors, or ai_mock is on.
    $kbCtx = kofc_kb_context($question);
    if ($kbCtx !== '') { $userMsg .= "\n\n" . $kbCtx; }

    $answer = AI_MOCK ? kofc_mock_ask($question) : kofc_ai_complete($system, $userMsg, false);

    try {
        kofc_db()->prepare(
            'INSERT INTO advisor_questions (agent_id, question, answer, ai_model, created_at)
             VALUES (:a, :q, :ans, :m, NOW())'
        )->execute([
            ':a'   => $agentId,
            ':q'   => $question,
            ':ans' => $answer,
            ':m'   => AI_MOCK ? AI_MODEL . ' (mock)' : AI_MODEL,
        ]);
    } catch (Throwable $e) {
        error_log('ask.php log warning: ' . $e->getMessage());
    }

    echo json_encode([
        'answer'                => $answer,
        'requires_agent_review' => true,
        'disclaimer'            => 'General product information for KofC field-agent use. '
                                 . 'Not financial, tax, or suitability advice.',
    ]);

} catch (Throwable $e) {
    error_log('ask.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}

function kofc_ask_prompt(string $question, array $kb): array
{
    $catalog = json_encode(['products' => $kb['products'], 'riders' => $kb['riders']], JSON_PRETTY_PRINT);
    $system =
        "You are an assistant for Knights of Columbus field agents. Answer questions about KofC\n"
      . "insurance and annuity products using ONLY the provided catalog, any retrieved KofC source\n"
      . "passages, and general, well-established insurance concepts. Prefer the retrieved passages\n"
      . "when present and cite the source in brackets. Never invent products, rates, or guarantees.\n"
      . "If a question falls outside KofC products, or needs member-specific suitability, tax, or\n"
      . "legal advice, say so briefly and tell the agent to confirm with licensed/compliance\n"
      . "resources. Be concise and plain-spoken.\n\n"
      . "Eligibility context: " . $kb['eligibility_note'];
    $user = "QUESTION:\n{$question}\n\nKofC PRODUCT CATALOG:\n{$catalog}";
    return [$system, $user];
}

function kofc_mock_ask(string $question): string
{
    return "Mock answer: a grounded response about KofC products would appear here (you asked: \""
         . mb_substr($question, 0, 140) . "\"). Set ai_mock to false and add your API key for live answers.";
}
