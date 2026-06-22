<?php
/**
 * kb-upload.php — admin: accept an uploaded document, extract text, embed, store under a
 * collection. Multipart POST: file=<upload>, collection=<id>.
 * Keeps the original in a non-web storage dir for provenance. Embeddings need a live OpenAI key.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';
require __DIR__ . '/kb.php';
require __DIR__ . '/kb-extract.php';

kofc_cors();

try {
    kofc_require_admin();

    $meta        = require __DIR__ . '/collections.php';
    $collection  = $_POST['collection'] ?? '';
    if (!isset($meta['collections'][$collection])) {
        http_response_code(422);
        echo json_encode(['error' => 'unknown collection']);
        exit;
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'no file uploaded (or upload exceeded php.ini limits)']);
        exit;
    }

    $orig = $_FILES['file']['name'];
    $tmp  = $_FILES['file']['tmp_name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['txt', 'docx', 'pdf'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'unsupported type; use .txt, .docx, or .pdf']);
        exit;
    }

    // Sanitize the filename — it becomes the citation/source key.
    $source = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);

    $text = kofc_extract_text($tmp, $ext);
    if (trim($text) === '') {
        http_response_code(422);
        echo json_encode(['error' => $ext === 'pdf'
            ? 'PDF text extraction unavailable. Install smalot/pdfparser (composer require smalot/pdfparser) or upload .txt/.docx.'
            : 'could not extract text from file']);
        exit;
    }

    // Persist the original outside any directly useful web path (storage/ is denied via .htaccess).
    $store = __DIR__ . '/../storage/docs/' . $collection;
    if (!is_dir($store)) { mkdir($store, 0775, true); }
    @move_uploaded_file($tmp, $store . '/' . $source);

    $n = kofc_kb_store($source, $collection, $text);

    echo json_encode([
        'ok'         => true,
        'source'     => $source,
        'collection' => $collection,
        'chunks'     => $n,
    ]);

} catch (Throwable $e) {
    error_log('kb-upload.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
