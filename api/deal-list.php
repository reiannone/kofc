<?php
/**
 * deal-list.php — the calling agent's deals (list view). GET ?status=draft|submitted|approved|returned|all
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

try {
    $agentId = kofc_require_agent();
    $status  = $_GET['status'] ?? 'all';
    $valid   = ['draft', 'submitted', 'approved', 'returned'];
    $pdo     = kofc_db();
    $cols    = 'id, client_name, status, conversation_id, updated_at,
                (deal_sheet IS NOT NULL AND deal_sheet <> "") AS has_sheet';
    if (in_array($status, $valid, true)) {
        $st = $pdo->prepare("SELECT $cols FROM deals WHERE agent_id = :a AND status = :s ORDER BY updated_at DESC");
        $st->execute([':a' => $agentId, ':s' => $status]);
    } else {
        $st = $pdo->prepare("SELECT $cols FROM deals WHERE agent_id = :a ORDER BY updated_at DESC");
        $st->execute([':a' => $agentId]);
    }
    echo json_encode(['items' => $st->fetchAll()]);
} catch (Throwable $e) {
    error_log('deal-list.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
