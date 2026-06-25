<?php
/**
 * kb-doc.php — admin: return the ingested chunks for one source document, in order,
 * so the Knowledge Base admin can show exactly what text was embedded (and how it was split).
 * Excludes the embedding column (large float array, not for display).
 *
 * GET ?source=<source key>
 * Returns: { source, collection, chunks: [ {id, chunk_index, chunk_text}, ... ] }
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

    $source = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
    if ($source === '') { http_response_code(422); echo json_encode(['error' => 'source required']); exit; }

    $st = kofc_db()->prepare(
        'SELECT id, chunk_index, chunk_text, collection
           FROM kb_chunks
          WHERE source = :s
          ORDER BY chunk_index ASC'
    );
    $st->execute([':s' => $source]);
    $rows = $st->fetchAll();
    if (!$rows) { http_response_code(404); echo json_encode(['error' => 'document not found']); exit; }

    $collection = $rows[0]['collection'] ?? '';
    $chunks = array_map(static fn ($r) => [
        'id'          => (int)$r['id'],
        'chunk_index' => (int)$r['chunk_index'],
        'chunk_text'  => (string)$r['chunk_text'],
    ], $rows);

    echo json_encode(['source' => $source, 'collection' => $collection, 'chunks' => $chunks]);
} catch (Throwable $e) {
    error_log('kb-doc.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
