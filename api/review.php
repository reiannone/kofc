<?php
/**
 * review.php — captures the agent's review of an AI recommendation.
 * The "accuracy" half of the supervised loop: the delta between AI suggestion and agent
 * decision is your tuning signal and acceptance metric.
 *
 * Body:
 * {
 *   "recommendation_id": 123,
 *   "accuracy_rating": 4,
 *   "notes": "...",
 *   "items": [{"product_id":"term_life","ai_confidence":0.8,"decision":"accept"}, ...]
 * }
 * decision ∈ accept | reject | modify | add
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';

kofc_cors();

try {
    $agentId = kofc_require_agent();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['recommendation_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'recommendation_id required']);
        exit;
    }

    $recId  = (int)$body['recommendation_id'];
    $rating = isset($body['accuracy_rating']) ? (int)$body['accuracy_rating'] : null;
    $notes  = $body['notes'] ?? null;
    $items  = $body['items'] ?? [];

    $pdo = kofc_db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO recommendation_reviews
            (recommendation_id, agent_id, accuracy_rating, decisions, notes, reviewed_at)
         VALUES (:rid, :agent, :rating, :decisions, :notes, NOW())'
    );
    $stmt->execute([
        ':rid'       => $recId,
        ':agent'     => $agentId,
        ':rating'    => $rating,
        ':decisions' => json_encode($items),
        ':notes'     => $notes,
    ]);
    $reviewId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE recommendations SET status = "reviewed" WHERE id = :rid')
        ->execute([':rid' => $recId]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'review_id' => $reviewId]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('review.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
