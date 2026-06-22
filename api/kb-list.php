<?php
/**
 * kb-list.php — admin: list ingested documents grouped by collection, with the collection
 * definitions so the UI can render categories.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';

kofc_cors();

try {
    kofc_require_admin();
    $meta = require __DIR__ . '/collections.php';

    $rows = kofc_db()->query(
        'SELECT source, collection, COUNT(*) AS chunks, MAX(created_at) AS updated
         FROM kb_chunks GROUP BY source, collection ORDER BY updated DESC'
    )->fetchAll();

    echo json_encode(['collections' => $meta['collections'], 'docs' => $rows]);

} catch (Throwable $e) {
    error_log('kb-list.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
