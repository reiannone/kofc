<?php
/**
 * deal-submit.php — push a deal into the supervisor review queue (agent-owned).
 * Body: { id, note? }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

try {
    $agentId = kofc_require_agent();
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (is_array($body) && isset($body['id'])) ? (int)$body['id'] : 0;
    $note = (is_array($body) && isset($body['note'])) ? mb_substr(trim((string)$body['note']), 0, 1000) : '';
    if ($id <= 0) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

    $pdo = kofc_db();
    $st = $pdo->prepare('SELECT agent_id, conversation_id, profile_json, deal_sheet FROM deals WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }
    if ($row['agent_id'] !== $agentId) { http_response_code(403); echo json_encode(['error' => 'not your deal']); exit; }

    $pj = $row['profile_json'];
    $hasContent = !empty($row['conversation_id'])
        || !empty($row['deal_sheet'])
        || (!empty($pj) && $pj !== '[]' && $pj !== '{}');
    if (!$hasContent) {
        http_response_code(422);
        echo json_encode(['error' => 'add a conversation, profile, or deal sheet before submitting']);
        exit;
    }

    $pdo->prepare('UPDATE deals SET status = "submitted", shared_draft = 0, submit_note = :n, updated_at = NOW() WHERE id = :id')
        ->execute([':n' => $note, ':id' => $id]);
    echo json_encode(['ok' => true, 'status' => 'submitted']);
} catch (Throwable $e) {
    error_log('deal-submit.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
