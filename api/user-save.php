<?php
/**
 * user-save.php — admin: create a user, change a role, or reset a password.
 * Body: { username, role:'agent'|'admin', password? }
 * New users and password resets set must_change_password = 1 (forced change on next login).
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
    $b = json_decode(file_get_contents('php://input'), true);
    $username = is_array($b) ? trim((string)($b['username'] ?? '')) : '';
    $role     = (($b['role'] ?? '') === 'admin') ? 'admin' : 'agent';
    $password = is_array($b) ? (string)($b['password'] ?? '') : '';
    if ($username === '') { http_response_code(422); echo json_encode(['error' => 'username required']); exit; }

    $pdo = kofc_db();
    $stmt = $pdo->prepare('SELECT role FROM users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Guard: don't demote the last admin.
        if ($existing['role'] === 'admin' && $role === 'agent') {
            $admins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'];
            if ($admins <= 1) { http_response_code(409); echo json_encode(['error' => 'cannot remove the last admin']); exit; }
        }
        if ($password !== '') {
            $pdo->prepare('UPDATE users SET role=:r, password_hash=:h, must_change_password=1 WHERE username=:u')
                ->execute([':r' => $role, ':h' => password_hash($password, PASSWORD_DEFAULT), ':u' => $username]);
        } else {
            $pdo->prepare('UPDATE users SET role=:r WHERE username=:u')
                ->execute([':r' => $role, ':u' => $username]);
        }
    } else {
        if ($password === '') { http_response_code(422); echo json_encode(['error' => 'password required for new user']); exit; }
        $pdo->prepare('INSERT INTO users (username, password_hash, role, must_change_password, created_at)
                       VALUES (:u, :h, :r, 1, NOW())')
            ->execute([':u' => $username, ':h' => password_hash($password, PASSWORD_DEFAULT), ':r' => $role]);
    }

    echo json_encode(['ok' => true, 'username' => $username, 'role' => $role]);
} catch (Throwable $e) {
    error_log('user-save.php error: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
