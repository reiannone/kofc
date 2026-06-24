<?php
/**
 * deals-review-list.php — submitted deals across all agents, for the Supervisor Deals tab.
 * GET ?status=submitted|approved|returned|all   (default submitted)
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();

function kofc_is_supervisor(): bool {
    if (defined("AUTH_DISABLED") && AUTH_DISABLED) return true;
    if (!empty($_SESSION["is_admin"] ?? 0)) return true;
    $role = $_SESSION["role"] ?? "";
    if ($role === "admin" || $role === "supervisor") return true;
    try {
        $pdo = kofc_db();
        $uid = $_SESSION["user_id"] ?? ($_SESSION["agent_id"] ?? null);
        if ($uid !== null) {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = :u1 OR username = :u2 LIMIT 1");
            $st->execute([":u1" => $uid, ":u2" => $uid]);
            $r = $st->fetch();
            if ($r) {
                if (!empty($r["is_admin"] ?? 0)) return true;
                if (in_array(($r["role"] ?? ""), ["admin","supervisor"], true)) return true;
            }
        }
    } catch (Throwable $e) { /* deny on any error */ }
    return false;
}

try {
    kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $status = $_GET['status'] ?? 'submitted';
    $valid  = ['submitted', 'approved', 'returned'];
    $pdo    = kofc_db();
    $cols   = 'id, agent_id, title, client_name, status, updated_at, reviewed_by, reviewed_at';
    if (in_array($status, $valid, true)) {
        $st = $pdo->prepare("SELECT $cols FROM deals WHERE status = :s ORDER BY updated_at DESC");
        $st->execute([':s' => $status]);
    } else {
        $st = $pdo->query("SELECT $cols FROM deals WHERE status <> 'draft' ORDER BY updated_at DESC");
    }
    echo json_encode(['items' => $st->fetchAll()]);
} catch (Throwable $e) {
    error_log('deals-review-list.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
