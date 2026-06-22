<?php
/** user-delete.php — admin. Body: { username }. Can't delete yourself or the last admin. */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';
kofc_cors();

try {
    $adminId = kofc_require_admin();
    $b = json_decode(file_get_contents('php://input'), true);
    $username = is_array($b) ? trim((string)($b['username'] ?? '')) : '';
    if ($username === '') { http_response_code(422); echo json_encode(['error' => 'username required']); exit; }
    if ($username === ($_SESSION['agent_id'] ?? '')) { http_response_code(409); echo json_encode(['error' => 'cannot delete your own account']); exit; }

    $pdo = kofc_db();
    $stmt = $pdo->prepare('SELECT role FROM users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    if ($row['role'] === 'admin') {
        $admins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'];
        if ($admins <= 1) { http_response_code(409); echo json_encode(['error' => 'cannot delete the last admin']); exit; }
    }

    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute([':u' => $username]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('user-delete.php error: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
