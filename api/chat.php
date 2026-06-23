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
    return
        "You are a planning partner for a Knights of Columbus field agent (sales rep). The agent\n"
      . "describes a client's situation in their own words; you help them build and refine a plan.\n"
      . "Converse naturally — never demand a form. Ask at most one or two clarifying questions when\n"
      . "something important is missing; otherwise proceed with reasonable assumptions and state them.\n\n"
      . "Ground everything in the provided KofC product catalog and any retrieved KofC source/training\n"
      . "passages. Prefer retrieved passages and cite the source in brackets. Never invent products,\n"
      . "rates, or guarantees.\n\n"
      . "When you have enough to work with, give the agent a working plan they can refine: the client's\n"
      . "needs as you understand them, a prioritized product approach with plain rationale, how to\n"
      . "position it for THIS client (informed by KofC training material when available), discovery\n"
      . "questions still worth asking, likely objections and suggested responses, and any suitability\n"
      . "flags to keep in mind.\n\n"
      . "This is support for the licensed agent, who owns the final recommendation and all client\n"
      . "contact. It is not a suitability determination. Frame guidance as coaching for the agent, not\n"
      . "a script to read verbatim to the member.\n\n"
      . "Eligibility context: " . $kb['eligibility_note'];
}

function kofc_mock_chat(string $message): string
{
    return "Mock advisor reply. In live mode I'd read what you told me about the client "
         . "(you said: \"" . mb_substr($message, 0, 120) . "\"), pull the relevant KofC product and "
         . "training passages, and lay out a working plan — needs, prioritized products, how to position "
         . "it, questions still to ask, likely objections, and any suitability flags. Set ai_mock to false "
         . "for real, grounded planning.";
}
