<?php
/**
 * deal-save.php — create or update a work-in-progress deal (agent-owned).
 * Body: { deal_id?, conversation_id?, client_name?, profile?, deal_sheet? }
 * Only provided fields are written. Returns { deal_id, status }.
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
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'invalid JSON body']); exit; }

    $pdo    = kofc_db();
    $dealId = isset($body['deal_id']) ? (int)$body['deal_id'] : 0;

    $clientName = array_key_exists('client_name', $body) ? mb_substr(trim((string)$body['client_name']), 0, 160) : null;
    $convId     = array_key_exists('conversation_id', $body)
        ? ($body['conversation_id'] !== null ? (int)$body['conversation_id'] : null) : false; // false = not provided
    $profile    = array_key_exists('profile', $body)
        ? json_encode(is_array($body['profile']) ? $body['profile'] : []) : false;
    $sheet      = array_key_exists('deal_sheet', $body)
        ? ($body['deal_sheet'] !== null ? (string)$body['deal_sheet'] : null) : false;

    if ($dealId > 0) {
        $own = $pdo->prepare('SELECT agent_id, status FROM deals WHERE id = :id');
        $own->execute([':id' => $dealId]);
        $row = $own->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }
        if ($row['agent_id'] !== $agentId) { http_response_code(403); echo json_encode(['error' => 'not your deal']); exit; }

        $sets = []; $params = [':id' => $dealId];
        if ($clientName !== null) { $sets[] = 'client_name = :cn';     $params[':cn'] = $clientName; }
        if ($convId !== false)    { $sets[] = 'conversation_id = :cv'; $params[':cv'] = $convId; }
        if ($profile !== false)   { $sets[] = 'profile_json = :pj';    $params[':pj'] = $profile; }
        if ($sheet !== false)     { $sets[] = 'deal_sheet = :ds';      $params[':ds'] = $sheet; }
        if ($sets) {
            $pdo->prepare('UPDATE deals SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')
                ->execute($params);
        }
        $status = $row['status'];
    } else {
        $ins = $pdo->prepare('INSERT INTO deals (agent_id, conversation_id, client_name, profile_json, deal_sheet, status, created_at, updated_at)
                              VALUES (:a, :cv, :cn, :pj, :ds, "draft", NOW(), NOW())');
        $ins->execute([
            ':a'  => $agentId,
            ':cv' => ($convId !== false ? $convId : null),
            ':cn' => ($clientName !== null ? $clientName : ''),
            ':pj' => ($profile !== false ? $profile : null),
            ':ds' => ($sheet !== false ? $sheet : null),
        ]);
        $dealId = (int)$pdo->lastInsertId();
        $status = 'draft';
    }

    echo json_encode(['deal_id' => $dealId, 'status' => $status]);
} catch (Throwable $e) {
    error_log('deal-save.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
