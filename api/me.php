<?php
/** me.php — rehydrate the current user from the session cookie on page load. */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

if (empty($_SESSION['agent_id'])) {
    http_response_code(401); echo json_encode(['error' => 'not authenticated']); exit;
}

// Prefer the role written at login. Fall back for sessions that predate the
// role key: an old admin session still carries is_admin, everyone else is agent.
$role = $_SESSION['role'] ?? (!empty($_SESSION['is_admin']) ? 'admin' : 'agent');
if ($role !== 'admin' && $role !== 'supervisor') {
    $role = 'agent';
}

echo json_encode([
    'username'      => $_SESSION['agent_id'],
    'role'          => $role,
    'is_admin'      => $role === 'admin',
    'is_supervisor' => $role === 'supervisor' || $role === 'admin',
    'must_change'   => !empty($_SESSION['must_change']),
]);
