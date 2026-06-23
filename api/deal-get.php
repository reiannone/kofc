<?php
/**
 * deal-get.php — full deal + conversation messages, for resume (owner) or review (supervisor).
 * GET ?id=
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

/**
 * Fail-closed supervisor/admin check. Mirrors session auth, falls back to the users table.
 * If the Supervisor Deals tab 403s, the session key differs — confirm and adjust here.
 */
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
                if (in_array(($r['role'] ?? ''), ['admin','supervisor'], true)) return true;
            }
        }
    } catch (Throwable $e) { /* deny on any error */ }
    return false;
}

try {
    $agentId = kofc_require_agent();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

    $pdo = kofc_db();
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = :id');
    $st->execute([':id' => $id]);
    $deal = $st->fetch();
    if (!$deal) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }
    if ($deal['agent_id'] !== $agentId && !kofc_is_supervisor()) {
        http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
    }

    if (isset($deal['profile_json']) && $deal['profile_json'] !== null) {
        $deal['profile'] = json_decode($deal['profile_json'], true);
    }
    $messages = [];
    if (!empty($deal['conversation_id'])) {
        $m = $pdo->prepare('SELECT role, content, created_at FROM conversation_messages
                            WHERE conversation_id = :c ORDER BY id ASC');
        $m->execute([':c' => (int)$deal['conversation_id']]);
        $messages = $m->fetchAll();
    }
    echo json_encode(['deal' => $deal, 'messages' => $messages]);
} catch (Throwable $e) {
    error_log('deal-get.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
