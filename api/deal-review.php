<?php
/**
 * deal-review.php — supervisor approves or returns a submitted deal.
 * Body: { id, action: 'approve'|'return', review_notes? }
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
    $me = kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $body   = json_decode(file_get_contents('php://input'), true);
    $id     = (is_array($body) && isset($body['id'])) ? (int)$body['id'] : 0;
    $action = (is_array($body) && isset($body['action'])) ? (string)$body['action'] : '';
    $notes  = (is_array($body) && isset($body['review_notes'])) ? mb_substr(trim((string)$body['review_notes']), 0, 4000) : '';
    if ($id <= 0 || !in_array($action, ['approve', 'return'], true)) {
        http_response_code(422); echo json_encode(['error' => 'id and valid action required']); exit;
    }
    $status = $action === 'approve' ? 'approved' : 'returned';

    $pdo = kofc_db();
    $chk = $pdo->prepare('SELECT id FROM deals WHERE id = :id');
    $chk->execute([':id' => $id]);
    if (!$chk->fetch()) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }

    $pdo->prepare('UPDATE deals SET status = :s, review_notes = :n, reviewed_by = :rb, reviewed_at = NOW(), updated_at = NOW() WHERE id = :id')
        ->execute([':s' => $status, ':n' => $notes, ':rb' => $me, ':id' => $id]);
    echo json_encode(['ok' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('deal-review.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
