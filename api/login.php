<?php
/** login.php — verify credentials and start a session. Body: { username, password } */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

try {
    $b = json_decode(file_get_contents('php://input'), true);
    $username = is_array($b) ? trim((string)($b['username'] ?? '')) : '';
    $password = is_array($b) ? (string)($b['password'] ?? '') : '';
    if ($username === '' || $password === '') {
        http_response_code(422); echo json_encode(['error' => 'username and password required']); exit;
    }

    $stmt = kofc_db()->prepare('SELECT username, password_hash, role, must_change_password FROM users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401); echo json_encode(['error' => 'invalid credentials']); exit;
    }

    session_regenerate_id(true);
    $_SESSION['agent_id'] = $user['username'];
    $_SESSION['must_change'] = (int)$user['must_change_password'];
    if ($user['role'] === 'admin') {
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_id'] = $user['username'];
    }

    echo json_encode([
        'username'     => $user['username'],
        'role'         => $user['role'],
        'is_admin'     => $user['role'] === 'admin',
        'must_change'  => (bool)$user['must_change_password'],
    ]);
} catch (Throwable $e) {
    error_log('login.php error: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
