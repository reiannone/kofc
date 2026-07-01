<?php
/**
 * licensing-admin.php — read-only feed of per-state licensing data for the
 * supervisor/admin console. Returns every row plus a verification summary.
 *
 * GET only. Admin-guarded. No writes. Pairs with web/src/LicensingAdmin.jsx.
 *
 * Returns: { summary: {total, verified, pending}, rows: [ {..per state..} ] }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';

kofc_cors();

try {
    // Admin/supervisor only. If your admin guard helper has a different name,
    // swap it here (chat.php uses kofc_require_agent(); admin pages use the
    // admin-session check). This endpoint exposes no secrets, but keep it gated.
    kofc_require_admin();

    $pdo = kofc_db();

    $rows = $pdo->query(
        "SELECT state_code, state_name, regulator, doi_url,
                annuity_bi_adopted, annuity_bi_note, annuity_training,
                prelicensing_hours, ltc_training, ce_cycle, updated_at
         FROM licensing_state_requirements
         ORDER BY state_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // A row is "verified" when all three hand-off fields have been confirmed.
    $verified = 0;
    foreach ($rows as $r) {
        if (($r['prelicensing_hours'] ?? null) !== null
            && ($r['ltc_training'] ?? null) !== null
            && ($r['ce_cycle'] ?? null) !== null) {
            $verified++;
        }
    }

    echo json_encode([
        'summary' => [
            'total'    => count($rows),
            'verified' => $verified,
            'pending'  => count($rows) - $verified,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('licensing-admin.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
