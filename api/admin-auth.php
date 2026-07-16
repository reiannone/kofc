<?php
/**
 * Role gates for KB-management and supervisor endpoints.
 *
 * kofc_require_admin()      — admin only (KB tuning, prompts, ingest).
 * kofc_require_supervisor() — supervisor OR admin (review queue, metrics, deals).
 *
 * Both honour AUTH_DISABLED for local testing. Role is read from the session
 * stamped by login.php ($_SESSION['role']); is_admin is kept for back-compat.
 */

function kofc_require_admin(): string
{
    if (!empty($_SESSION['is_admin'])) {
        return $_SESSION['admin_id'] ?? 'admin';
    }
    if (defined('AUTH_DISABLED') && AUTH_DISABLED) {
        return 'admin_demo';
    }
    http_response_code(401);
    echo json_encode(['error' => 'admin only']);
    exit;
}

function kofc_require_supervisor(): string
{
    // Prefer the role stamped at login; fall back to the legacy is_admin flag
    // for any session created before role was written.
    $role = $_SESSION['role'] ?? (!empty($_SESSION['is_admin']) ? 'admin' : null);

    if ($role === 'admin' || $role === 'supervisor') {
        return (string)($_SESSION['agent_id'] ?? $_SESSION['admin_id'] ?? $role);
    }
    if (defined('AUTH_DISABLED') && AUTH_DISABLED) {
        return 'supervisor_demo';
    }
    // Authenticated-but-wrong-role and unauthenticated both land here.
    http_response_code(403);
    echo json_encode(['error' => 'supervisor only']);
    exit;
}
