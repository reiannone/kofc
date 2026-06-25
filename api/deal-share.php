<?php
/**
 * deal-share.php — agent shares a DRAFT deal for early supervisor review (agent-owned).
 * Body: { id }
 *
 * One-way: sets shared_draft = 1. The agent keeps editing; the draft becomes visible
 * in the supervisor Deals "Draft" filter. Cleared automatically on submit.
 * Returns { ok, shared_draft }.
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
    $st  = $pdo->prepare('SELECT agent_id, status FROM deals WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }
    if ($row['agent_id'] !== $agentId) { http_response_code(403); echo json_encode(['error' => 'not your deal']); exit; }
    if (($row['status'] ?? '') !== 'draft') {
        http_response_code(422); echo json_encode(['error' => 'only drafts can be shared for review']); exit;
    }

    $pdo->prepare('UPDATE deals SET shared_draft = 1, updated_at = NOW() WHERE id = :id')
        ->execute([':id' => $id]);
    echo json_encode(['ok' => true, 'shared_draft' => 1]);
} catch (Throwable $e) {
    error_log('deal-share.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
