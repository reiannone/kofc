<?php
/** change-password.php — logged-in user changes own password. Body: { current_password, new_password } */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

try {
    $me = kofc_require_agent();
    $b = json_decode(file_get_contents('php://input'), true);
    $current = is_array($b) ? (string)($b['current_password'] ?? '') : '';
    $new     = is_array($b) ? (string)($b['new_password'] ?? '') : '';
    if (strlen($new) < 8) { http_response_code(422); echo json_encode(['error' => 'new password must be at least 8 characters']); exit; }

    $pdo = kofc_db();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE username = :u');
    $stmt->execute([':u' => $me]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        http_response_code(401); echo json_encode(['error' => 'current password is incorrect']); exit;
    }

    $pdo->prepare('UPDATE users SET password_hash=:h, must_change_password=0 WHERE username=:u')
        ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':u' => $me]);
    $_SESSION['must_change'] = 0;

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('change-password.php error: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
