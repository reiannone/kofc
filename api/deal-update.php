<?php
/**
 * deal-update.php — supervisor edits a deal's deal sheet (any status, any agent's deal).
 * Body: { id, deal_sheet }
 *
 * Captures the agent's current sheet as a baseline version (if none yet), writes the
 * edited text to deals.deal_sheet, and appends a supervisor version to the history.
 * Returns { ok, version_no }.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/deal-versions-lib.php';
kofc_cors();

try {
    $me = kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $body  = json_decode(file_get_contents('php://input'), true);
    $id    = (is_array($body) && isset($body['id'])) ? (int)$body['id'] : 0;
    if ($id <= 0 || !is_array($body) || !array_key_exists('deal_sheet', $body)) {
        http_response_code(422); echo json_encode(['error' => 'id and deal_sheet required']); exit;
    }
    $newSheet = $body['deal_sheet'] !== null ? (string)$body['deal_sheet'] : '';

    $pdo = kofc_db();
    $st  = $pdo->prepare('SELECT agent_id, deal_sheet FROM deals WHERE id = :id');
    $st->execute([':id' => $id]);
    $deal = $st->fetch();
    if (!$deal) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }

    $pdo->beginTransaction();
    try {
        // Preserve the agent's original as the baseline before the first supervisor edit.
        kofc_ensure_agent_baseline($pdo, $id, $deal['deal_sheet'] ?? null, $deal['agent_id'] ?? null);

        // Write the supervisor's edit to the live sheet and mark it redlined.
        $pdo->prepare('UPDATE deals SET deal_sheet = :ds, review_state = "redlined", redlined_by = :rb, redlined_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute([':ds' => $newSheet, ':rb' => $me, ':id' => $id]);

        // Append the supervisor version (no-op if identical to the baseline we just made).
        $version = kofc_snapshot_deal_sheet($pdo, $id, $newSheet, $me, 'supervisor');

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['ok' => true, 'version_no' => $version]);
} catch (Throwable $e) {
    error_log('deal-update.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
