<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';
kofc_cors();
try {
    kofc_require_admin();
    $rows = kofc_db()->query(
        'SELECT id, username, role, must_change_password, created_at FROM users ORDER BY username'
    )->fetchAll();
    echo json_encode(['users' => $rows]);
} catch (Throwable $e) {
    error_log('user-list.php error: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
