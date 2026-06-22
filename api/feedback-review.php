<?php
/**
 * feedback-review.php — supervisor acts on a feedback item (admin).
 * Body: { id, action:'promote'|'dismiss'|'approve', final_question?, final_answer? }
 * promote -> embeds a vetted exemplar into kb_chunks (collection 'vetted') and feeds retrieval.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/admin-auth.php';
require __DIR__ . '/kb.php';

kofc_cors();

try {
    $adminId = kofc_require_admin();
    $b = json_decode(file_get_contents('php://input'), true);
    $id = (int)($b['id'] ?? 0);
    $action = $b['action'] ?? '';
    if ($id <= 0 || !in_array($action, ['promote', 'dismiss', 'approve'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'id and valid action required']);
        exit;
    }

    $pdo = kofc_db();
    $stmt = $pdo->prepare('SELECT * FROM advisor_feedback WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $fb = $stmt->fetch();
    if (!$fb) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    if ($action === 'promote') {
        $question = trim((string)($b['final_question'] ?? $fb['question_text'] ?? ''));
        $answer   = trim((string)($b['final_answer'] ?? ($fb['suggested_answer'] ?: $fb['answer_text']) ?? ''));
        if ($answer === '') {
            http_response_code(422);
            echo json_encode(['error' => 'no answer text to promote']);
            exit;
        }
        $exemplar = "Question: " . $question . "\n\nApproved answer: " . $answer;
        // Embed into the vetted collection — becomes retrievable knowledge.
        kofc_kb_store('vetted:fb' . $id, 'vetted', $exemplar);
        $status = 'promoted';
    } elseif ($action === 'dismiss') {
        $status = 'dismissed';
    } else {
        $status = 'approved';
    }

    $pdo->prepare('UPDATE advisor_feedback SET status=:st, reviewed_by=:by, reviewed_at=NOW() WHERE id=:id')
        ->execute([':st' => $status, ':by' => $adminId, ':id' => $id]);

    echo json_encode(['ok' => true, 'id' => $id, 'status' => $status]);

} catch (Throwable $e) {
    error_log('feedback-review.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
