<?php
/**
 * prompt-activate.php — make an existing version active (rollback/forward). Supervisor-gated.
 * Body: { key, version }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/prompts.php';
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
    } catch (Throwable $e) { /* deny */ }
    return false;
}

try {
    kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $in  = json_decode(file_get_contents('php://input'), true);
    $key = (is_array($in) && isset($in['key'])) ? (string)$in['key'] : '';
    $ver = (is_array($in) && isset($in['version'])) ? (int)$in['version'] : 0;
    if ($key === '' || $ver <= 0) { http_response_code(422); echo json_encode(['error' => 'key and version required']); exit; }

    $pdo = kofc_db();
    $chk = $pdo->prepare('SELECT id FROM prompt_templates WHERE prompt_key = :k AND version = :v');
    $chk->execute([':k' => $key, ':v' => $ver]);
    if (!$chk->fetch()) { http_response_code(404); echo json_encode(['error' => 'version not found']); exit; }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE prompt_templates SET is_active = 0 WHERE prompt_key = :k')->execute([':k' => $key]);
    $pdo->prepare('UPDATE prompt_templates SET is_active = 1, updated_at = NOW() WHERE prompt_key = :k AND version = :v')
        ->execute([':k' => $key, ':v' => $ver]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'key' => $key, 'version' => $ver]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('prompt-activate.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
