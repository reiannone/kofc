<?php
/**
 * feedback-list.php — supervisor review queue (supervisor or admin). ?status=new|approved|dismissed|promoted|all
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';

kofc_cors();

try {
    kofc_require_supervisor();
    $status = $_GET['status'] ?? 'new';
    $allowed = ['new', 'approved', 'dismissed', 'promoted', 'all'];
    if (!in_array($status, $allowed, true)) $status = 'new';

    if ($status === 'all') {
        $rows = kofc_db()->query(
            'SELECT * FROM advisor_feedback ORDER BY created_at DESC LIMIT 200'
        )->fetchAll();
    } else {
        $stmt = kofc_db()->prepare(
            'SELECT * FROM advisor_feedback WHERE status = :s ORDER BY created_at DESC LIMIT 200'
        );
        $stmt->execute([':s' => $status]);
        $rows = $stmt->fetchAll();
    }

    echo json_encode(['items' => $rows]);

} catch (Throwable $e) {
    error_log('feedback-list.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
