<?php
/**
 * deal-sheet.php — generate an AI-written, editable KofC deal sheet (Markdown) from the
 * conversation + profile, grounded in the catalog and retrieved KofC passages. Mock-aware.
 * Does not persist — the agent edits, then saves via deal-save.php.
 * Body: { deal_id? , conversation_id?, profile? }
 * Returns: { deal_sheet }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/kb.php';
require __DIR__ . '/prompts.php';
kofc_cors();

try {
    $agentId = kofc_require_agent();
    $body = json_decode(file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];

    $id      = isset($body['deal_id']) ? (int)$body['deal_id'] : (isset($body['id']) ? (int)$body['id'] : 0);
    $convId  = (array_key_exists('conversation_id', $body) && $body['conversation_id'] !== null) ? (int)$body['conversation_id'] : 0;
    $profile = (isset($body['profile']) && is_array($body['profile'])) ? $body['profile'] : [];

    $pdo = kofc_db();

    if ($id > 0) {
        $st = $pdo->prepare('SELECT agent_id, conversation_id, profile_json FROM deals WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if ($row) {
            if ($row['agent_id'] !== $agentId) { http_response_code(403); echo json_encode(['error' => 'not your deal']); exit; }
            if (!$convId && !empty($row['conversation_id'])) { $convId = (int)$row['conversation_id']; }
            if (!$profile && !empty($row['profile_json'])) { $p = json_decode($row['profile_json'], true); if (is_array($p)) $profile = $p; }
        }
    }

    $transcript = '';
    if ($convId > 0) {
        $m = $pdo->prepare('SELECT role, content FROM conversation_messages WHERE conversation_id = :c ORDER BY id ASC LIMIT 60');
        $m->bindValue(':c', $convId, PDO::PARAM_INT);
        $m->execute();
        foreach ($m->fetchAll() as $r) {
            $who = ($r['role'] === 'assistant') ? 'ASSISTANT' : 'AGENT';
            $transcript .= $who . ': ' . trim((string)$r['content']) . "\n\n";
        }
        $transcript = trim($transcript);
    }

    if ($transcript === '' && !$profile) {
        http_response_code(422); echo json_encode(['error' => 'no conversation or profile to build a sheet from']); exit;
    }

    $kb = require __DIR__ . '/products.php';
    $sheet = AI_MOCK ? kofc_mock_sheet($profile) : kofc_generate_sheet($transcript, $profile, $kb);

    echo json_encode(['deal_sheet' => $sheet]);
} catch (Throwable $e) {
    error_log('deal-sheet.php: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'internal error']);
}

function kofc_generate_sheet(string $transcript, array $profile, array $kb): string
{
    $catalog = json_encode(['products' => $kb['products'], 'riders' => $kb['riders']], JSON_PRETTY_PRINT);
    $kbCtx   = kofc_kb_context(($profile['primary_goal'] ?? '') . ' KofC insurance options');

    $system = kofc_render(kofc_prompt_or('deal_sheet_system'), [
        'eligibility_note' => $kb['eligibility_note'] ?? '',
    ]);

    $user =
        "CLIENT PROFILE (structured):\n" . json_encode($profile, JSON_PRETTY_PRINT) . "\n\n"
      . "AGENT CONVERSATION:\n" . ($transcript !== '' ? $transcript : '(none provided)') . "\n\n"
      . "KofC PRODUCT CATALOG:\n" . $catalog
      . ($kbCtx !== '' ? "\n\n" . $kbCtx : '');

    return kofc_ai_complete($system, $user, false);
}

function kofc_mock_sheet(array $profile): string
{
    $age  = $profile['age'] ?? '—';
    $goal = str_replace('_', ' ', (string)($profile['primary_goal'] ?? 'general planning'));
    return "### Client Summary\nMock deal sheet for a client (age $age) focused on $goal.\n\n"
         . "### Identified Needs\n- Income protection during peak obligation years\n- Long-term savings\n\n"
         . "### Recommended Products\n\n| Product | Why it fits | Riders | Est. annual premium |\n|---|---|---|---|\n"
         . "| Term Life | Affordable income replacement | — | \$720 |\n"
         . "| Retirement Annuity (FPA) | Flexible retirement savings | — | — |\n\n"
         . "### Rationale\nMock rationale tied to the client's stated facts.\n\n"
         . "### Positioning & Talking Points\n- Lead with protection, then savings.\n\n"
         . "### Suitability Flags\n- Confirm budget and existing coverage before recommending.\n\n"
         . "### Next Steps\n- Gather missing details and schedule a follow-up.\n\n"
         . "_Set ai_mock to false for a real, grounded deal sheet._";
}
