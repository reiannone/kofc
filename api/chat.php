<?php
/**
 * chat.php — conversational planning advisor for KofC field agents.
 * The rep describes a client in their own words; the assistant helps build a plan and
 * answers follow-ups with conversation context. Stateful (persisted per turn), grounded
 * in the product catalog + retrieved KofC source/training passages. Mock-aware.
 *
 * Body: { "message": "...", "conversation_id": 12 (optional) }
 * Returns: { conversation_id, reply, requires_agent_review, disclaimer }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/kb.php';
require __DIR__ . '/prompts.php';
require __DIR__ . '/product-gates.php';
require __DIR__ . '/licensing-lib.php';

kofc_cors();

const KOFC_CHAT_HISTORY = 12; // max prior turns sent back to the model

try {
    $agentId = kofc_require_agent();

    $body    = json_decode(file_get_contents('php://input'), true);
    $message = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
    $convId  = (is_array($body) && isset($body['conversation_id'])) ? (int)$body['conversation_id'] : 0;
    if ($message === '') {
        http_response_code(422);
        echo json_encode(['error' => 'message is required']);
        exit;
    }

    $pdo = kofc_db();

    if ($convId <= 0) {
        $pdo->prepare('INSERT INTO conversations (agent_id, title, created_at, updated_at)
                       VALUES (:a, :t, NOW(), NOW())')
            ->execute([':a' => $agentId, ':t' => mb_substr($message, 0, 60)]);
        $convId = (int)$pdo->lastInsertId();
    }

    // Store the rep's message.
    $pdo->prepare('INSERT INTO conversation_messages (conversation_id, role, content, created_at)
                   VALUES (:c, "user", :m, NOW())')
        ->execute([':c' => $convId, ':m' => $message]);

    // Load recent history (oldest-first) to give the model context.
    $h = $pdo->prepare('SELECT role, content FROM conversation_messages
                        WHERE conversation_id = :c ORDER BY id DESC LIMIT :lim');
    $h->bindValue(':c', $convId, PDO::PARAM_INT);
    $h->bindValue(':lim', KOFC_CHAT_HISTORY, PDO::PARAM_INT);
    $h->execute();
    $history = array_reverse($h->fetchAll());

    // Retrieval on the latest message (product + training passages).
    $kbCtx = kofc_kb_context($message, 5);
    $kb = require __DIR__ . '/products.php';

    // Structured, state-keyed licensing facts (bypasses fuzzy KB retrieval for data
    // that must be exact). Null unless the message names exactly one U.S. state.
    $licensing = kofc_licensing_for_query($pdo, $message);

    // Known structured facts (from the Recommend tab), so the model doesn't re-ask.
    $known = (is_array($body) && isset($body['profile']) && is_array($body['profile']))
        ? kofc_known_profile_block($body['profile']) : '';
    $profileForGaps = (is_array($body) && isset($body['profile']) && is_array($body['profile'])) ? $body['profile'] : [];

    // ---- Intent detection + interactive gap elicitation ----
    // Elicitation runs in any advisor chat (no saved deal required), but only ONCE a
    // product direction is detectable. We detect via: an explicit product, a goal already
    // in the structured profile (no extra model call), else a cheap classifier pass.
    // When required facts are missing, we HOLD: the reply is constrained to gathering and
    // must not recommend (enforced by swapping the system prompt, not just asking nicely).
    $explicitProduct = is_array($body) && isset($body['product']) ? (string)$body['product'] : '';
    $gaps   = null;
    $held   = false;
    $detect = ['detectable' => false, 'category' => 'none', 'confidence' => 0.0];

    if (!AI_MOCK) {
        if ($explicitProduct !== '') {
            $detect = ['detectable' => true, 'category' => $explicitProduct, 'confidence' => 1.0];
        } elseif (!empty($profileForGaps['primary_goal'])) {
            $cat = kofc_goal_to_category((string)$profileForGaps['primary_goal']);
            if ($cat !== 'none') $detect = ['detectable' => true, 'category' => $cat, 'confidence' => 1.0];
        } else {
            $detect = kofc_detect_intent($message, $history);
        }
    }

    if ($detect['detectable'] && $detect['category'] !== 'none') {
        $gaps = kofc_product_gaps($detect['category'], $profileForGaps);
        $held = !empty($gaps['hold']);
        if (!empty($gaps['unknown_keys'])) {
            error_log('chat.php gap guard: unknown field_keys dropped: ' . implode(',', $gaps['unknown_keys']));
        }
    }

    // Assemble the model messages. Held -> gathering-only mode (the floor). Otherwise the
    // normal planning advisor, optionally nudged with a sharpening note.
    if ($held) {
        $messages = [['role' => 'system', 'content' => kofc_elicitation_system($gaps)]];
        if ($known !== '') {
            $messages[] = ['role' => 'system', 'content' => $known];
        }
    } else {
        $messages = [['role' => 'system', 'content' => kofc_chat_system($kb)]];
        if ($known !== '') {
            $messages[] = ['role' => 'system', 'content' => $known];
        }
        if ($gaps !== null && !empty($gaps['needs'])) {
            $brief = kofc_gaps_prompt_brief($gaps); // sharpening-only nudge
            if ($brief !== '') {
                $messages[] = ['role' => 'system', 'content' => $brief];
            }
        }
    }

    foreach ($history as $row) {
        $messages[] = ['role' => $row['role'], 'content' => $row['content']];
    }
    // Authoritative licensing facts lead the KB passages (planning mode only) — exact,
    // state-keyed data must win over any fuzzy-retrieved text.
    if (!$held && $licensing !== null) {
        $messages[] = ['role' => 'system', 'content' => $licensing['text']];
    }
    // KB grounding only in planning mode — a gathering turn is just asking questions.
    if (!$held && $kbCtx !== '') {
        $messages[] = ['role' => 'system', 'content' => "Relevant KofC passages for this turn:\n\n" . $kbCtx];
    }

    $reply = AI_MOCK ? kofc_mock_chat($message) : kofc_ai_chat($messages, false);

    // Store the assistant reply.
    $pdo->prepare('INSERT INTO conversation_messages (conversation_id, role, content, created_at)
                   VALUES (:c, "assistant", :m, NOW())')
        ->execute([':c' => $convId, ':m' => $reply]);
    $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = :c')
        ->execute([':c' => $convId]);

    // Per-response citations: KB sources the reply was grounded in, plus the structured
    // licensing source (DOI) when a state was resolved, so SourceFooter can link it.
    $sources = $held ? [] : kofc_kb_last_sources();
    if (!is_array($sources)) { $sources = []; }
    if (!$held && $licensing !== null) {
        $sources[] = $licensing['citation'];
    }

    echo json_encode([
        'conversation_id'       => $convId,
        'reply'                 => $reply,
        'requires_agent_review' => true,
        'needs'                 => $gaps['needs'] ?? [],
        'hold'                  => $gaps['hold'] ?? false,
        'gap_mode'              => $gaps['mode'] ?? null,
        'gap_category'          => $detect['category'] ?? null,
        // Per-response citation: KofC source documents the reply was grounded in
        // (empty while gathering/held, or until real source docs are ingested).
        'sources'               => $sources,
        'disclaimer'            => 'AI planning support for KofC field-agent use. Not a suitability '
                                 . 'determination; the licensed agent owns the final plan and all client contact.',
    ]);

} catch (Throwable $e) {
    error_log('chat.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}

function kofc_chat_system(array $kb): string
{
    return kofc_render(kofc_prompt_or('chat_system'), [
        'eligibility_note' => $kb['eligibility_note'] ?? '',
    ]);
}

/**
 * Cheap classifier pass: does the agent's description yet point to a product direction?
 * Returns ['detectable'=>bool, 'category'=>term_life|whole_life|annuity|ltc|none, 'confidence'=>float].
 * Fails safe to not-detectable so a classifier hiccup never blocks the agent.
 */
function kofc_detect_intent(string $message, array $history): array
{
    $default = ['detectable' => false, 'category' => 'none', 'confidence' => 0.0];

    $ctx = '';
    foreach ($history as $row) {
        if (($row['role'] ?? '') === 'user') {
            $ctx .= '- ' . mb_substr((string)$row['content'], 0, 300) . "\n";
        }
    }

    $sys = "You classify whether an insurance field agent's notes about a client yet point to a "
         . "specific product direction. Categories:\n"
         . "- term_life: temporary income-replacement; young family, mortgage term, budget temporary coverage.\n"
         . "- whole_life: permanent / legacy; estate, cash value, lifelong coverage.\n"
         . "- annuity: retirement income, steady income stream, outliving savings.\n"
         . "- ltc: long-term care, disability, assisted living, care-cost protection.\n"
         . "- none: not enough yet, or a general information question.\n"
         . "Respond with STRICT JSON only, no markdown, no prose: "
         . '{"detectable": boolean, "category": "term_life|whole_life|annuity|ltc|none", "confidence": number between 0 and 1}';
    $user = "Client notes so far:\n" . ($ctx !== '' ? $ctx : "(none)\n") . "\nLatest message: " . $message;

    try {
        $out  = kofc_ai_chat([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ], false);
        $json = kofc_extract_json((string)$out);
        if (!is_array($json)) return $default;

        $cat = $json['category'] ?? 'none';
        if (!in_array($cat, ['term_life', 'whole_life', 'annuity', 'ltc', 'none'], true)) {
            $cat = 'none';
        }
        return [
            'detectable' => !empty($json['detectable']) && $cat !== 'none',
            'category'   => $cat,
            'confidence' => (float)($json['confidence'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('kofc_detect_intent error: ' . $e->getMessage());
        return $default;
    }
}

/**
 * Pull the first JSON object out of a model reply, tolerating ```fences``` and stray prose.
 */
function kofc_extract_json(string $s): ?array
{
    $s = trim($s);
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/\s*```$/', '', $s);
    if (preg_match('/\{.*\}/s', $s, $m)) {
        $s = $m[0];
    }
    $d = json_decode($s, true);
    return is_array($d) ? $d : null;
}

/**
 * Map a fact-find primary_goal to a product category, so a known goal skips the detector.
 */
function kofc_goal_to_category(string $goal): string
{
    switch ($goal) {
        case 'income_replacement':
        case 'mortgage_protection':
            return 'term_life';
        case 'estate_legacy':
            return 'whole_life';
        case 'retirement_income':
            return 'annuity';
        case 'long_term_care':
            return 'ltc';
        default:
            return 'none';
    }
}

/**
 * THE FLOOR: information-gathering system prompt used when required facts are missing.
 * Replaces the planning prompt entirely so the model has no instruction to recommend —
 * it acknowledges briefly, then asks for the missing facts, and must not name products.
 */
function kofc_elicitation_system(array $gaps): string
{
    $req   = $gaps['required_missing'] ?? [];
    $lines = [];
    foreach (array_slice($req, 0, 3) as $n) {
        $lines[] = '- ' . $n['label'] . (!empty($n['why']) ? ' (' . $n['why'] . ')' : '');
    }
    $list = $lines ? implode("\n", $lines) : '- (none specified)';

    return "You are a KofC field-agent planning assistant, currently in INFORMATION-GATHERING mode.\n"
         . "You do NOT yet have enough information to recommend a product, and you MUST NOT recommend, "
         . "name, compare, or describe any specific product, plan, rider, or strategy in this reply.\n\n"
         . "Do exactly this, briefly and conversationally:\n"
         . "1. Acknowledge the client's situation in ONE short sentence.\n"
         . "2. Ask the agent for the most important missing facts below — at most three, most important first.\n"
         . "3. Stop there. No recommendations, no 'you might consider', no product names.\n\n"
         . "Missing facts to collect:\n" . $list;
}

function kofc_mock_chat(string $message): string
{
    return "Mock Agent reply. In live mode I'd read what you told me about the client "
         . "(you said: \"" . mb_substr($message, 0, 120) . "\"), pull the relevant KofC product and "
         . "training passages, and lay out a working plan — needs, prioritized products, how to position "
         . "it, questions still to ask, likely objections, and any suitability flags. Set ai_mock to false "
         . "for real, grounded planning.";
}

/**
 * Format the structured Recommend-tab profile as authoritative known facts for the model.
 * Skips blank/unknown fields. Returns '' when nothing is set.
 */
function kofc_known_profile_block(array $p): string
{
    $humanize = static fn ($s) => ucfirst(str_replace('_', ' ', (string) $s));
    $lines = [];

    if (isset($p['age']) && $p['age'] !== '' && $p['age'] !== null) {
        $lines[] = 'Age: ' . (int) $p['age'];
    }
    if (!empty($p['marital_status'])) {
        $lines[] = 'Marital status: ' . $humanize($p['marital_status']);
    }
    if (isset($p['has_dependents']) && $p['has_dependents'] !== '' && $p['has_dependents'] !== null) {
        $hd = $p['has_dependents'];
        $hd = is_bool($hd) ? ($hd ? 'yes' : 'no') : (string) $hd;
        $lines[] = 'Has dependents: ' . $hd;
    }
    if (isset($p['annual_income']) && $p['annual_income'] !== '' && $p['annual_income'] !== null) {
        $lines[] = 'Annual income: $' . number_format((float) $p['annual_income']);
    }
    if (array_key_exists('currently_employed', $p) && is_bool($p['currently_employed'])) {
        $lines[] = 'Currently employed: ' . ($p['currently_employed'] ? 'yes' : 'no');
    }
    if (!empty($p['primary_goal'])) {
        $lines[] = 'Primary goal: ' . $humanize($p['primary_goal']);
    }
    if (!empty($p['existing_coverage'])) {
        $lines[] = 'Existing coverage: ' . trim((string) $p['existing_coverage']);
    }
    if (isset($p['budget_monthly']) && $p['budget_monthly'] !== '' && $p['budget_monthly'] !== null) {
        $lines[] = 'Monthly budget: $' . number_format((float) $p['budget_monthly']);
    }

    if (!$lines) {
        return '';
    }

    return "The agent has already recorded these client facts in the structured profile. "
         . "Treat them as authoritative, do NOT re-ask them, and build on them:\n- "
         . implode("\n- ", $lines);
}
