<?php
/**
 * kb-delete.php — admin: remove a document's chunks (and its stored original).
 * Body: { "source": "filename.pdf" }
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

    $body   = json_decode(file_get_contents('php://input'), true);
    $source = is_array($body) ? trim((string)($body['source'] ?? '')) : '';
    if ($source === '') {
        http_response_code(422);
        echo json_encode(['error' => 'source is required']);
        exit;
    }

    // Find collection(s) to also clean the stored original.
    $stmt = kofc_db()->prepare('SELECT DISTINCT collection FROM kb_chunks WHERE source = :s');
    $stmt->execute([':s' => $source]);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    kofc_db()->prepare('DELETE FROM kb_chunks WHERE source = :s')->execute([':s' => $source]);

    foreach ($cols as $c) {
        $f = __DIR__ . '/../storage/docs/' . $c . '/' . $source;
        if (is_file($f)) { @unlink($f); }
    }

    echo json_encode(['ok' => true, 'source' => $source]);

} catch (Throwable $e) {
    error_log('kb-delete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
