<?php
/**
 * chat.php — conversational planning advisor for KofC field agents.
 * The rep describes a client in their own words; the assistant helps build a plan and
 * answers follow-ups with conversation context. Stateful (persisted per turn), grounded
 * in the product catalog + retrieved KofC source/training passages. Mock-aware.
 *
 * Body: { "message": "...", "conversation_id": 12 (optional) }
 * Returns: { conversation_id, reply, requires_agent_review, disclaimer }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/kb.php';
require __DIR__ . '/prompts.php';

kofc_cors();

const KOFC_CHAT_HISTORY = 12; // max prior turns sent back to the model

try {
    $agentId = kofc_require_agent();

    $body    = json_decode(file_get_contents('php://input'), true);
    $message = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
    $convId  = (is_array($body) && isset($body['conversation_id'])) ? (int)$body['conversation_id'] : 0;
    if ($message === '') {
        http_response_code(422);
        echo json_encode(['error' => 'message is required']);
        exit;
    }

    $pdo = kofc_db();

    if ($convId <= 0) {
        $pdo->prepare('INSERT INTO conversations (agent_id, title, created_at, updated_at)
                       VALUES (:a, :t, NOW(), NOW())')
            ->execute([':a' => $agentId, ':t' => mb_substr($message, 0, 60)]);
        $convId = (int)$pdo->lastInsertId();
    }

    // Store the rep's message.
    $pdo->prepare('INSERT INTO conversation_messages (conversation_id, role, content, created_at)
                   VALUES (:c, "user", :m, NOW())')
        ->execute([':c' => $convId, ':m' => $message]);

    // Load recent history (oldest-first) to give the model context.
    $h = $pdo->prepare('SELECT role, content FROM conversation_messages
                        WHERE conversation_id = :c ORDER BY id DESC LIMIT :lim');
    $h->bindValue(':c', $convId, PDO::PARAM_INT);
    $h->bindValue(':lim', KOFC_CHAT_HISTORY, PDO::PARAM_INT);
    $h->execute();
    $history = array_reverse($h->fetchAll());

    // Retrieval on the latest message (product + training passages).
    $kbCtx = kofc_kb_context($message, 5);

    $kb = require __DIR__ . '/products.php';
    $messages = [['role' => 'system', 'content' => kofc_chat_system($kb)]];

    // If the agent has already recorded structured profile facts on the Recommend tab,
    // hand them to the model so it doesn't re-ask and can build on them.
    $known = (is_array($body) && isset($body['profile']) && is_array($body['profile']))
        ? kofc_known_profile_block($body['profile']) : '';
    if ($known !== '') {
        $messages[] = ['role' => 'system', 'content' => $known];
    }

    foreach ($history as $row) {
        $messages[] = ['role' => $row['role'], 'content' => $row['content']];
    }
    if ($kbCtx !== '') {
        $messages[] = ['role' => 'system', 'content' => "Relevant KofC passages for this turn:\n\n" . $kbCtx];
    }

    $reply = AI_MOCK ? kofc_mock_chat($message) : kofc_ai_chat($messages, false);

    // Store the assistant reply.
    $pdo->prepare('INSERT INTO conversation_messages (conversation_id, role, content, created_at)
                   VALUES (:c, "assistant", :m, NOW())')
        ->execute([':c' => $convId, ':m' => $reply]);
    $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = :c')
        ->execute([':c' => $convId]);

    echo json_encode([
        'conversation_id'       => $convId,
        'reply'                 => $reply,
        'requires_agent_review' => true,
        'disclaimer'            => 'AI planning support for KofC field-agent use. Not a suitability '
                                 . 'determination; the licensed agent owns the final plan and all client contact.',
    ]);

} catch (Throwable $e) {
    error_log('chat.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}

function kofc_chat_system(array $kb): string
{
    return kofc_render(kofc_prompt_or('chat_system'), [
        'eligibility_note' => $kb['eligibility_note'] ?? '',
    ]);
}

function kofc_mock_chat(string $message): string
{
    return "Mock advisor reply. In live mode I'd read what you told me about the client "
         . "(you said: \"" . mb_substr($message, 0, 120) . "\"), pull the relevant KofC product and "
         . "training passages, and lay out a working plan — needs, prioritized products, how to position "
         . "it, questions still to ask, likely objections, and any suitability flags. Set ai_mock to false "
         . "for real, grounded planning.";
}

/**
 * Format the structured Recommend-tab profile as authoritative known facts for the model.
 * Skips blank/unknown fields. Returns '' when nothing is set.
 */
function kofc_known_profile_block(array $p): string
{
    $humanize = static fn ($s) => ucfirst(str_replace('_', ' ', (string) $s));
    $lines = [];

    if (isset($p['age']) && $p['age'] !== '' && $p['age'] !== null) {
        $lines[] = 'Age: ' . (int) $p['age'];
    }
    if (!empty($p['marital_status'])) {
        $lines[] = 'Marital status: ' . $humanize($p['marital_status']);
    }
    if (isset($p['has_dependents']) && $p['has_dependents'] !== '' && $p['has_dependents'] !== null) {
        $hd = $p['has_dependents'];
        $hd = is_bool($hd) ? ($hd ? 'yes' : 'no') : (string) $hd;
        $lines[] = 'Has dependents: ' . $hd;
    }
    if (isset($p['annual_income']) && $p['annual_income'] !== '' && $p['annual_income'] !== null) {
        $lines[] = 'Annual income: $' . number_format((float) $p['annual_income']);
    }
    if (array_key_exists('currently_employed', $p) && is_bool($p['currently_employed'])) {
        $lines[] = 'Currently employed: ' . ($p['currently_employed'] ? 'yes' : 'no');
    }
    if (!empty($p['primary_goal'])) {
        $lines[] = 'Primary goal: ' . $humanize($p['primary_goal']);
    }
    if (!empty($p['existing_coverage'])) {
        $lines[] = 'Existing coverage: ' . trim((string) $p['existing_coverage']);
    }
    if (isset($p['budget_monthly']) && $p['budget_monthly'] !== '' && $p['budget_monthly'] !== null) {
        $lines[] = 'Monthly budget: $' . number_format((float) $p['budget_monthly']);
    }

    if (!$lines) {
        return '';
    }

    return "The agent has already recorded these client facts in the structured profile. "
         . "Treat them as authoritative, do NOT re-ask them, and build on them:\n- "
         . implode("\n- ", $lines);
}
