<?php
/**
 * kb-tuning-get.php — current retrieval weighting (supervisor-saved merged over file defaults),
 * plus the raw defaults for the UI's "reset". Supervisor-gated.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/kb.php';
kofc_cors();

function kofc_is_supervisor(): bool {
    if (defined('AUTH_DISABLED') && AUTH_DISABLED) return true;
    if (!empty($_SESSION['is_admin'] ?? 0)) return true;
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin' || $role === 'supervisor') return true;
    try {
        $pdo = kofc_db();
        $uid = $_SESSION['user_id'] ?? ($_SESSION['agent_id'] ?? null);
        if ($uid !== null) {
            $st = $pdo->prepare('SELECT * FROM users WHERE id = :u1 OR username = :u2 LIMIT 1');
            $st->execute([':u1' => $uid, ':u2' => $uid]);
            $r = $st->fetch();
            if ($r) {
                if (!empty($r['is_admin'] ?? 0)) return true;
                if (in_array(($r['role'] ?? ''), ['admin', 'supervisor'], true)) return true;
            }
        }
    } catch (Throwable $e) { /* deny */ }
    return false;
}

try {
    kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $defaults  = kofc_kb_defaults();
    $stored    = kofc_kb_tuning();
    $effective = array_replace($defaults, $stored); // stored maps fully replace a default key when present

    echo json_encode(['config' => $effective, 'defaults' => $defaults]);
} catch (Throwable $e) {
    error_log('kb-tuning-get.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
