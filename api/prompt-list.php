<?php
/**
 * prompt-list.php — all prompt templates grouped by key (active body, default body, version history).
 * Lazily seeds defaults on first call. Supervisor-gated.
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

    kofc_prompt_seed();

    $defaults = kofc_prompt_defaults();
    $out = [];
    foreach ($defaults as $key => $d) {
        $out[$key] = [
            'label' => $d['label'], 'vars' => $d['vars'], 'default_body' => $d['body'],
            'active_version' => null, 'active_body' => $d['body'], 'versions' => [],
        ];
    }
    $rows = kofc_db()->query(
        'SELECT prompt_key, version, body, is_active, edited_by, updated_at
         FROM prompt_templates ORDER BY prompt_key, version'
    )->fetchAll();
    foreach ($rows as $r) {
        $k = $r['prompt_key'];
        if (!isset($out[$k])) {
            $out[$k] = ['label' => $k, 'vars' => [], 'default_body' => '', 'active_version' => null, 'active_body' => '', 'versions' => []];
        }
        $active = ((int)$r['is_active'] === 1);
        $out[$k]['versions'][] = [
            'version' => (int)$r['version'], 'is_active' => $active,
            'edited_by' => $r['edited_by'], 'updated_at' => $r['updated_at'], 'body' => $r['body'],
        ];
        if ($active) { $out[$k]['active_version'] = (int)$r['version']; $out[$k]['active_body'] = $r['body']; }
    }
    echo json_encode(['templates' => $out]);
} catch (Throwable $e) {
    error_log('prompt-list.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
