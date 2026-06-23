<?php
/**
 * kb-tuning-save.php — validate + persist supervisor retrieval weighting (single row id=1).
 * Body: { weights:{col:num}, floor:num, mix:{col:int}, min:{col:int}, k:int }. Supervisor-gated.
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

function kofc_clampf($v, float $lo, float $hi, float $def): float {
    if (!is_numeric($v)) return $def;
    $v = (float)$v; return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
}
function kofc_clampi($v, int $lo, int $hi, int $def): int {
    if (!is_numeric($v)) return $def;
    $v = (int)$v; return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
}

try {
    $me = kofc_require_agent();
    if (!kofc_is_supervisor()) { http_response_code(403); echo json_encode(['error' => 'supervisor only']); exit; }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'invalid JSON body']); exit; }

    // Known collections come from the file defaults — ignore anything else the client sends.
    $defaults = kofc_kb_defaults();
    $cols = array_keys($defaults['weights'] ?? []);
    if (!$cols) { http_response_code(500); echo json_encode(['error' => 'no collections configured']); exit; }

    $cfg = ['weights' => [], 'mix' => [], 'min' => [],
            'floor' => kofc_clampf($body['floor'] ?? null, 0.0, 1.0, (float)($defaults['floor'] ?? 0.2)),
            'k'     => kofc_clampi($body['k'] ?? null, 1, 20, (int)($defaults['k'] ?? 6))];
    foreach ($cols as $c) {
        $cfg['weights'][$c] = kofc_clampf($body['weights'][$c] ?? null, 0.0, 3.0, (float)($defaults['weights'][$c] ?? 1.0));
        $cfg['mix'][$c]     = kofc_clampi($body['mix'][$c] ?? null, 0, 20, (int)($defaults['mix'][$c] ?? 0));
        $cfg['min'][$c]     = kofc_clampi($body['min'][$c] ?? null, 0, ($cfg['mix'][$c] ?: 20), (int)($defaults['min'][$c] ?? 0));
    }

    $pdo = kofc_db();
    $pdo->prepare('INSERT INTO kb_tuning (id, config, updated_by, updated_at)
                   VALUES (1, :c, :u, NOW())
                   ON DUPLICATE KEY UPDATE config = VALUES(config), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([':c' => json_encode($cfg), ':u' => $me]);

    echo json_encode(['ok' => true, 'config' => $cfg]);
} catch (Throwable $e) {
    error_log('kb-tuning-save.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
