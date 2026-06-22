<?php
/**
 * CORS for the Chrome extension. Credentialed requests can't use "*", so we echo the
 * specific origin back. Dev: allows chrome-extension:// and localhost/127.0.0.1.
 * Tighten the allow-list for production.
 */
function kofc_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $ok = preg_match('#^chrome-extension://#', $origin)
        || preg_match('#^https?://localhost(:\d+)?$#', $origin)
        || preg_match('#^https?://127\.0\.0\.1(:\d+)?$#', $origin);

    if ($ok) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
