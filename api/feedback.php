<?php
/**
 * feedback.php — agent submits feedback on an AI output (chat reply or recommendation).
 * Body: { ref_type:'chat'|'recommendation', ref_id?, vote:'up'|'down', reason_code?,
 *         comment?, question_text?, answer_text?, suggested_answer? }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';

kofc_cors();

try {
    $agentId = kofc_require_agent();
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) { http_response_code(400); echo json_encode(['error' => 'invalid body']); exit; }

    $refType = ($b['ref_type'] ?? '') === 'recommendation' ? 'recommendation' : 'chat';
    $vote    = ($b['vote'] ?? '') === 'down' ? 'down' : (($b['vote'] ?? '') === 'up' ? 'up' : '');
    if ($vote === '') { http_response_code(422); echo json_encode(['error' => 'vote must be up or down']); exit; }

    $stmt = kofc_db()->prepare(
        'INSERT INTO advisor_feedback
            (ref_type, ref_id, agent_id, vote, reason_code, comment, question_text, answer_text, suggested_answer, status, created_at)
         VALUES (:rt, :rid, :a, :v, :rc, :cm, :q, :ans, :sg, "new", NOW())'
    );
    $stmt->execute([
        ':rt'  => $refType,
        ':rid' => isset($b['ref_id']) ? (int)$b['ref_id'] : null,
        ':a'   => $agentId,
        ':v'   => $vote,
        ':rc'  => $b['reason_code'] ?? null,
        ':cm'  => $b['comment'] ?? null,
        ':q'   => $b['question_text'] ?? null,
        ':ans' => $b['answer_text'] ?? null,
        ':sg'  => $b['suggested_answer'] ?? null,
    ]);

    echo json_encode(['ok' => true, 'id' => (int)kofc_db()->lastInsertId()]);

} catch (Throwable $e) {
    error_log('feedback.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
