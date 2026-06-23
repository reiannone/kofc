<?php
/**
 * prompt-save.php — save a new version of a prompt (auto-activates it). Supervisor-gated.
 * Body: { key, body }
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
    $me = kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $in   = json_decode(file_get_contents('php://input'), true);
    $key  = (is_array($in) && isset($in['key'])) ? (string)$in['key'] : '';
    $body = (is_array($in) && isset($in['body'])) ? (string)$in['body'] : '';
    if (!array_key_exists($key, kofc_prompt_defaults())) { http_response_code(422); echo json_encode(['error' => 'unknown prompt key']); exit; }
    if (trim($body) === '') { http_response_code(422); echo json_encode(['error' => 'body is required']); exit; }
    if (mb_strlen($body) > 20000) { http_response_code(422); echo json_encode(['error' => 'body too long']); exit; }

    $pdo = kofc_db();
    $pdo->beginTransaction();
    $vMax = (int)$pdo->query('SELECT COALESCE(MAX(version),0) FROM prompt_templates WHERE prompt_key = ' . $pdo->quote($key))->fetchColumn();
    $ver  = $vMax + 1;
    $pdo->prepare('UPDATE prompt_templates SET is_active = 0 WHERE prompt_key = :k')->execute([':k' => $key]);
    $pdo->prepare('INSERT INTO prompt_templates (prompt_key, version, body, is_active, edited_by, created_at, updated_at)
                   VALUES (:k, :v, :b, 1, :u, NOW(), NOW())')
        ->execute([':k' => $key, ':v' => $ver, ':b' => $body, ':u' => $me]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'key' => $key, 'version' => $ver]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('prompt-save.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
