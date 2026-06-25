<?php
/**
 * deal-versions-lib.php — shared helpers for deal-sheet version history.
 * Required by deal-save.php (agent versions) and deal-update.php (supervisor edits).
 *
 * Requires: kofc_db() (config.php).
 */
declare(strict_types=1);

/**
 * Latest version row for a deal, or null if none yet.
 */
function kofc_latest_sheet_version(PDO $pdo, int $dealId): ?array
{
    $st = $pdo->prepare(
        'SELECT id, version_no, deal_sheet, source, edited_by, created_at
           FROM deal_sheet_versions
          WHERE deal_id = :d
          ORDER BY version_no DESC
          LIMIT 1'
    );
    $st->execute([':d' => $dealId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Append a version snapshot of a deal sheet — but only if the text actually changed
 * from the latest version (no-op on identical text, so repeated saves don't spam rows).
 * Returns the new version_no, or the existing latest version_no when unchanged.
 *
 * @param string $source 'agent' | 'supervisor'
 */
function kofc_snapshot_deal_sheet(PDO $pdo, int $dealId, ?string $text, ?string $editedBy, string $source): int
{
    $source = $source === 'supervisor' ? 'supervisor' : 'agent';
    $norm   = $text === null ? '' : trim($text);

    $latest = kofc_latest_sheet_version($pdo, $dealId);
    if ($latest !== null && trim((string)($latest['deal_sheet'] ?? '')) === $norm) {
        return (int)$latest['version_no']; // unchanged — no new row
    }

    $next = $latest === null ? 1 : ((int)$latest['version_no'] + 1);
    $ins  = $pdo->prepare(
        'INSERT INTO deal_sheet_versions (deal_id, version_no, deal_sheet, source, edited_by, created_at)
         VALUES (:d, :v, :s, :src, :by, NOW())'
    );
    $ins->execute([
        ':d'   => $dealId,
        ':v'   => $next,
        ':s'   => $text,
        ':src' => $source,
        ':by'  => $editedBy,
    ]);
    return $next;
}

/**
 * Ensure a baseline (agent) version exists before a supervisor's first edit, so the
 * redline always has the agent's original to compare against — even for deals created
 * before version history existed. No-op if any version already exists.
 */
function kofc_ensure_agent_baseline(PDO $pdo, int $dealId, ?string $currentSheet, ?string $agentId): void
{
    if (kofc_latest_sheet_version($pdo, $dealId) !== null) return;
    if ($currentSheet === null || trim($currentSheet) === '') return;
    kofc_snapshot_deal_sheet($pdo, $dealId, $currentSheet, $agentId, 'agent');
}

/**
 * Supervisor gate, mirrored from deal-review.php so edit endpoints share one check.
 * Guarded so it never collides if another included file already defined it.
 */
if (!function_exists('kofc_is_supervisor')) {
    function kofc_is_supervisor(): bool
    {
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
        } catch (Throwable $e) { /* deny on any error */ }
        return false;
    }
}
