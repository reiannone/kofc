<?php
/**
 * deal-save.php — create or update a work-in-progress deal (agent-owned).
 * Body: { deal_id?, conversation_id?, client_name?, title?, profile?, deal_sheet? }
 * Only provided fields are written. Title falls back to a default when blank
 * (see deal-title.php). Returns { deal_id, status, title }.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/deal-title.php';
require __DIR__ . '/deal-versions-lib.php';
kofc_cors();

try {
    $agentId = kofc_require_agent();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'invalid JSON body']); exit; }

    $pdo    = kofc_db();
    $dealId = isset($body['deal_id']) ? (int)$body['deal_id'] : 0;

    $clientName = array_key_exists('client_name', $body) ? mb_substr(trim((string)$body['client_name']), 0, 160) : null;
    $titleProvided = array_key_exists('title', $body);
    $titleRaw      = $titleProvided ? (string)$body['title'] : '';
    $convId     = array_key_exists('conversation_id', $body)
        ? ($body['conversation_id'] !== null ? (int)$body['conversation_id'] : null) : false; // false = not provided
    $profile    = array_key_exists('profile', $body)
        ? json_encode(is_array($body['profile']) ? $body['profile'] : []) : false;
    $sheet      = array_key_exists('deal_sheet', $body)
        ? ($body['deal_sheet'] !== null ? (string)$body['deal_sheet'] : null) : false;

    if ($dealId > 0) {
        $own = $pdo->prepare('SELECT agent_id, status, client_name, title FROM deals WHERE id = :id');
        $own->execute([':id' => $dealId]);
        $row = $own->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'deal not found']); exit; }
        if ($row['agent_id'] !== $agentId) { http_response_code(403); echo json_encode(['error' => 'not your deal']); exit; }

        $sets = []; $params = [':id' => $dealId];
        $title = (string)$row['title'];
        if ($clientName !== null) { $sets[] = 'client_name = :cn';     $params[':cn'] = $clientName; }
        if ($titleProvided) {
            // re-default on blank, using the incoming client name if given, else the stored one
            $cnForDefault = $clientName !== null ? $clientName : (string)$row['client_name'];
            $title = kofc_deal_title($titleRaw, $cnForDefault, $agentId);
            $sets[] = 'title = :ti'; $params[':ti'] = $title;
        }
        if ($convId !== false)    { $sets[] = 'conversation_id = :cv'; $params[':cv'] = $convId; }
        if ($profile !== false)   { $sets[] = 'profile_json = :pj';    $params[':pj'] = $profile; }
        if ($sheet !== false)     { $sets[] = 'deal_sheet = :ds';      $params[':ds'] = $sheet;
                                    // an agent revision supersedes a supervisor redline
                                    $sets[] = 'review_state = "none"'; }
        if ($sets) {
            $pdo->prepare('UPDATE deals SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')
                ->execute($params);
        }
        $status = $row['status'];
    } else {
        $cnForInsert = ($clientName !== null ? $clientName : '');
        $title = kofc_deal_title($titleProvided ? $titleRaw : '', $cnForInsert, $agentId);
        $ins = $pdo->prepare('INSERT INTO deals (agent_id, conversation_id, client_name, title, profile_json, deal_sheet, status, created_at, updated_at)
                              VALUES (:a, :cv, :cn, :ti, :pj, :ds, "draft", NOW(), NOW())');
        $ins->execute([
            ':a'  => $agentId,
            ':cv' => ($convId !== false ? $convId : null),
            ':cn' => $cnForInsert,
            ':ti' => $title,
            ':pj' => ($profile !== false ? $profile : null),
            ':ds' => ($sheet !== false ? $sheet : null),
        ]);
        $dealId = (int)$pdo->lastInsertId();
        $status = 'draft';
    }

    // Version the sheet when the agent provided one (no-op if unchanged from last version).
    if ($sheet !== false) {
        kofc_snapshot_deal_sheet($pdo, $dealId, $sheet !== false ? $sheet : null, $agentId, 'agent');
    }

    echo json_encode(['deal_id' => $dealId, 'status' => $status, 'title' => $title]);
} catch (Throwable $e) {
    error_log('deal-save.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}
