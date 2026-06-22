<?php
/**
 * Admin gate for KB-management and supervisor endpoints. Requires an authenticated admin.
 * 401s otherwise, unless AUTH_DISABLED is set for local testing.
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
