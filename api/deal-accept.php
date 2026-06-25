<?php
/**
 * deal-accept.php — agent accepts the supervisor's redline edits as-is (agent-owned).
 * Body: { id }
 *
 * Marks review_state='accepted', pushes the deal back into the supervisor queue
 * (status='submitted'). Only valid on a returned deal that was actually redlined.
 * Returns { ok, status, review_state }.
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
    if ($id <= 0) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

    $pdo = kofc_db();
    $st  = $pdo->prepare('SELECT agent_id, status, review_state FROM deals WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }
    if ($row['agent_id'] !== $agentId) { http_response_code(403); echo json_encode(['error' => 'not your deal']); exit; }
    if (($row['review_state'] ?? 'none') !== 'redlined') {
        http_response_code(422); echo json_encode(['error' => 'no supervisor edits to accept']); exit;
    }

    $pdo->prepare('UPDATE deals SET review_state = "accepted", status = "submitted", updated_at = NOW() WHERE id = :id')
        ->execute([':id' => $id]);
    echo json_encode(['ok' => true, 'status' => 'submitted', 'review_state' => 'accepted']);
} catch (Throwable $e) {
    error_log('deal-accept.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
