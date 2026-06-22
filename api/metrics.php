<?php
/**
 * metrics.php — supervisor dashboard summary (admin).
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';

kofc_cors();

try {
    kofc_require_admin();
    $pdo = kofc_db();
    $q = fn($sql) => $pdo->query($sql)->fetchAll();

    $votes = $q("SELECT vote, COUNT(*) c FROM advisor_feedback GROUP BY vote");
    $up = 0; $down = 0;
    foreach ($votes as $r) { if ($r['vote'] === 'up') $up = (int)$r['c']; else $down = (int)$r['c']; }
    $totalFb = $up + $down;

    $pending = (int)($pdo->query("SELECT COUNT(*) c FROM advisor_feedback WHERE status='new'")->fetch()['c'] ?? 0);
    $vetted  = (int)($pdo->query("SELECT COUNT(DISTINCT source) c FROM kb_chunks WHERE collection='vetted'")->fetch()['c'] ?? 0);

    $rec = $pdo->query("SELECT AVG(accuracy_rating) avg, COUNT(*) n FROM recommendation_reviews")->fetch();

    $reasons = $q("SELECT reason_code, COUNT(*) c FROM advisor_feedback
                   WHERE vote='down' AND reason_code IS NOT NULL AND reason_code<>''
                   GROUP BY reason_code ORDER BY c DESC LIMIT 8");

    $trend = $q("SELECT DATE(created_at) d, SUM(vote='up') up, COUNT(*) total
                 FROM advisor_feedback WHERE created_at >= (NOW() - INTERVAL 14 DAY)
                 GROUP BY DATE(created_at) ORDER BY d");

    echo json_encode([
        'feedback_total'   => $totalFb,
        'positive_rate'    => $totalFb ? round($up * 100 / $totalFb) : null,
        'pending_review'   => $pending,
        'vetted_exemplars' => $vetted,
        'rec_avg_rating'   => $rec['avg'] !== null ? round((float)$rec['avg'], 2) : null,
        'rec_reviews'      => (int)$rec['n'],
        'top_reasons'      => $reasons,
        'trend'            => $trend,
    ]);

} catch (Throwable $e) {
    error_log('metrics.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
