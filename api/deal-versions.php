<?php
/**
 * deal-versions.php — full deal-sheet version history for a deal (supervisor only).
 * GET ?deal_id=N  ->  { items: [ {id, version_no, source, edited_by, created_at, deal_sheet}, ... ] }
 * Ordered oldest-first (version 1 = agent baseline).
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/deal-versions-lib.php';
kofc_cors();

try {
    kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $dealId = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : 0;
    if ($dealId <= 0) { http_response_code(422); echo json_encode(['error' => 'deal_id required']); exit; }

    $st = kofc_db()->prepare(
        'SELECT id, version_no, source, edited_by, created_at, deal_sheet
           FROM deal_sheet_versions
          WHERE deal_id = :d
          ORDER BY version_no ASC'
    );
    $st->execute([':d' => $dealId]);
    echo json_encode(['items' => $st->fetchAll()]);
} catch (Throwable $e) {
    error_log('deal-versions.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
