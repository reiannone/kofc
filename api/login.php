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

    // Normalise role. 'admin' and 'supervisor' are the elevated roles; anything
    // else (including a null/blank column on legacy rows) is a plain agent.
    $role = (string)($user['role'] ?? 'agent');
    if ($role !== 'admin' && $role !== 'supervisor') {
        $role = 'agent';
    }

    session_regenerate_id(true);
    $_SESSION['agent_id']     = $user['username'];
    $_SESSION['must_change']  = (int)$user['must_change_password'];
    // Authoritative role for this session. me.php and the admin guard read this.
    $_SESSION['role']         = $role;

    // Back-compat: keep the is_admin/admin_id keys the existing admin-only
    // endpoints already check, set ONLY for true admins. Supervisors never get
    // is_admin, so admin-only endpoints reject them without any further change.
    if ($role === 'admin') {
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_id'] = $user['username'];
    }

    echo json_encode([
        'username'       => $user['username'],
        'role'           => $role,
        'is_admin'       => $role === 'admin',
        // "supervisor access" = supervisor OR admin. Admin implies supervisor.
        'is_supervisor'  => $role === 'supervisor' || $role === 'admin',
        'must_change'    => (bool)$user['must_change_password'],
    ]);
} catch (Throwable $e) {
    error_log('login.php error: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
