<?php
/**
 * extract-profile.php — read a saved AI Agent conversation and extract the structured
 * member-profile fields the Recommend tab uses, so the rep doesn't re-key what they
 * already discussed. Read-only: pulls the persisted turns, runs one JSON-mode model
 * call, returns a sanitized profile + a list of still-missing fields. Mock-aware.
 *
 * Body:    { "conversation_id": 12 }
 * Returns: { "profile": { ...Recommend EMPTY shape... }, "missing": ["budget_monthly", ...] }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
require __DIR__ . '/ai.php';

kofc_cors();

const KOFC_EXTRACT_HISTORY = 40; // cap turns scanned

try {
    $agentId = kofc_require_agent();

    $body   = json_decode(file_get_contents('php://input'), true);
    $convId = (is_array($body) && isset($body['conversation_id'])) ? (int)$body['conversation_id'] : 0;
    if ($convId <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'conversation_id is required']);
        exit;
    }

    $pdo = kofc_db();

    // Ownership check — an agent can only extract from their own conversation.
    $own = $pdo->prepare('SELECT agent_id FROM conversations WHERE id = :c');
    $own->execute([':c' => $convId]);
    $ownerId = $own->fetchColumn();
    if ($ownerId === false) {
        http_response_code(404);
        echo json_encode(['error' => 'conversation not found']);
        exit;
    }
    if ((int)$ownerId !== (int)$agentId) {
        http_response_code(403);
        echo json_encode(['error' => 'not your conversation']);
        exit;
    }

    // Pull the transcript (oldest-first).
    $h = $pdo->prepare('SELECT role, content FROM conversation_messages
                        WHERE conversation_id = :c ORDER BY id ASC LIMIT :lim');
    $h->bindValue(':c', $convId, PDO::PARAM_INT);
    $h->bindValue(':lim', KOFC_EXTRACT_HISTORY, PDO::PARAM_INT);
    $h->execute();
    $rows = $h->fetchAll();

    $transcript = '';
    foreach ($rows as $r) {
        $who = ($r['role'] === 'assistant') ? 'ASSISTANT' : 'AGENT';
        $transcript .= $who . ': ' . trim((string)$r['content']) . "\n\n";
    }
    $transcript = trim($transcript);

    $extracted = ($transcript === '')
        ? kofc_blank_profile()
        : (AI_MOCK ? kofc_mock_extract($transcript) : kofc_run_extract($transcript));

    [$profile, $missing] = kofc_sanitize_profile($extracted);

    echo json_encode(['profile' => $profile, 'missing' => $missing]);

} catch (Throwable $e) {
    error_log('extract-profile.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}

/* ------------------------------------------------------------------ */

function kofc_blank_profile(): array
{
    return [
        'member_name' => '', 'member_dob' => '', 'council_number' => '', 'member_occupation' => '',
        'age' => null, 'annual_income' => null, 'currently_employed' => null,
        'marital_status' => '', 'spouse_name' => '', 'spouse_dob' => '', 'anniversary_date' => '',
        'spouse_occupation' => '', 'spouse_income' => null,
        'has_dependents' => '', 'children' => '',
        'has_will' => '', 'will_last_updated' => '', 'has_trust' => '', 'special_needs_trust' => '',
        'wealth_transfer_plan' => '', 'estate_tax_plan' => '', 'ltc_plan' => '', 'ltc_plan_details' => '',
        'need_debts' => null, 'need_mortgage' => null, 'need_education' => null,
        'need_final_expenses' => null, 'need_income_replace' => null,
        'retire_target_number' => null, 'retire_age' => null, 'retire_income_goal' => '',
        'combined_liquid_savings' => null, 'combined_nonqualified' => null, 'expecting_inheritance' => '',
        'primary_goal' => '', 'budget_monthly' => null, 'existing_coverage' => '', 'coverage_feeling' => '',
    ];
}

function kofc_run_extract(string $transcript): array
{
    $shape = json_encode(kofc_blank_profile());
    $system =
        "You extract a structured insurance fact-find from a conversation between a Knights of\n"
      . "Columbus field AGENT and an AI ASSISTANT about the agent's client. Use ONLY facts the agent\n"
      . "stated or clearly implied. NEVER guess. Unknown numeric fields = null; unknown text/enum = \"\".\n\n"
      . "Respond with ONLY a JSON object — no markdown, no preamble — with exactly these keys:\n"
      . $shape . "\n(plus a \"missing\" array of the field names still unknown).\n\n"
      . "Field rules:\n"
      . "- age, retire_age: integer years, or null.\n"
      . "- member_dob, spouse_dob, anniversary_date, will_last_updated: short date strings as stated, or \"\".\n"
      . "- marital_status: one of \"single\",\"married\",\"widowed\",\"divorced\", or \"\".\n"
      . "- annual_income, spouse_income, budget_monthly, need_debts, need_mortgage, need_education,\n"
      . "  need_final_expenses, need_income_replace, retire_target_number, combined_liquid_savings,\n"
      . "  combined_nonqualified: integer US dollars, or null.\n"
      . "- currently_employed: true, false, or null.\n"
      . "- has_dependents, has_will, has_trust, special_needs_trust, wealth_transfer_plan,\n"
      . "  estate_tax_plan, ltc_plan, expecting_inheritance: \"yes\", \"no\", or \"\".\n"
      . "- primary_goal: one of \"income_replacement\",\"mortgage_protection\",\"retirement_income\",\n"
      . "  \"long_term_care\",\"estate_legacy\", or \"\". Pick the single best fit.\n"
      . "- member_name, member_occupation, spouse_name, spouse_occupation, council_number, children,\n"
      . "  ltc_plan_details, retire_income_goal, existing_coverage, coverage_feeling: short text, or \"\".\n";

    $user = "CONVERSATION:\n" . $transcript;

    $raw = kofc_ai_complete($system, $user, true);
    $decoded = json_decode(trim($raw), true);
    return is_array($decoded) ? $decoded : kofc_blank_profile();
}

/**
 * Lightweight, dependency-free mock so the round-trip works with no key/cost.
 * Pattern-matches a handful of common phrasings; everything else stays unknown.
 */
function kofc_mock_extract(string $transcript): array
{
    $t = strtolower($transcript);
    $p = kofc_blank_profile();

    if (preg_match('/\b(\d{2})\s*[- ]?\s*year[- ]?old\b/', $t, $m))      $p['age'] = (int)$m[1];
    elseif (preg_match('/\bage\s*(?:is\s*)?(\d{2})\b/', $t, $m))         $p['age'] = (int)$m[1];

    if (strpos($t, 'married') !== false)       $p['marital_status'] = 'married';
    elseif (strpos($t, 'widow') !== false)     $p['marital_status'] = 'widowed';
    elseif (strpos($t, 'divorc') !== false)    $p['marital_status'] = 'divorced';
    elseif (strpos($t, 'single') !== false)    $p['marital_status'] = 'single';

    if (preg_match('/\b(kids|children|dependents?|son|daughter)\b/', $t)) $p['has_dependents'] = 'yes';

    if (preg_match('/\$?\s*(\d{2,3})\s*k\b/', $t, $m))                    $p['annual_income'] = (int)$m[1] * 1000;
    elseif (preg_match('/\$\s*(\d{2,3}(?:,\d{3})+)/', $t, $m))           $p['annual_income'] = (int)str_replace(',', '', $m[1]);

    if (strpos($t, 'retire') !== false)                                          $p['primary_goal'] = 'retirement_income';
    elseif (strpos($t, 'mortgage') !== false)                                    $p['primary_goal'] = 'mortgage_protection';
    elseif (strpos($t, 'long-term care') !== false || strpos($t, 'long term care') !== false) $p['primary_goal'] = 'long_term_care';
    elseif (strpos($t, 'income replacement') !== false)                          $p['primary_goal'] = 'income_replacement';
    elseif (strpos($t, 'estate') !== false || strpos($t, 'legacy') !== false)    $p['primary_goal'] = 'estate_legacy';

    if (strpos($t, 'term through work') !== false || strpos($t, 'term life') !== false) {
        $p['existing_coverage'] = 'Term life (mentioned)';
    }

    return $p;
}

/**
 * Force the model output into the exact fact-find shape and valid enum values,
 * then recompute `missing` from the sanitized result (don't trust the model's list).
 */
function kofc_sanitize_profile(array $in): array
{
    $marital = ['single', 'married', 'widowed', 'divorced'];
    $goals   = ['income_replacement', 'mortgage_protection', 'retirement_income', 'long_term_care', 'estate_legacy'];
    $yn      = ['yes', 'no'];
    $ynKeys  = ['has_dependents', 'has_will', 'has_trust', 'special_needs_trust',
                'wealth_transfer_plan', 'estate_tax_plan', 'ltc_plan', 'expecting_inheritance'];
    $intKeys = ['annual_income', 'spouse_income', 'budget_monthly',
                'need_debts', 'need_mortgage', 'need_education', 'need_final_expenses', 'need_income_replace',
                'retire_target_number', 'combined_liquid_savings', 'combined_nonqualified'];
    $txtKeys = ['member_name', 'member_dob', 'council_number', 'member_occupation',
                'spouse_name', 'spouse_dob', 'anniversary_date', 'spouse_occupation', 'children',
                'will_last_updated', 'ltc_plan_details', 'retire_income_goal', 'existing_coverage', 'coverage_feeling'];

    $out = kofc_blank_profile();

    foreach (['age', 'retire_age'] as $k) {
        if (isset($in[$k]) && is_numeric($in[$k])) { $v = (int)$in[$k]; if ($v >= 0 && $v <= 120) $out[$k] = $v; }
    }
    foreach ($intKeys as $k) {
        if (isset($in[$k]) && is_numeric($in[$k])) { $v = (int)$in[$k]; if ($v >= 0) $out[$k] = $v; }
    }
    foreach ($ynKeys as $k) {
        $v = $in[$k] ?? '';
        if (is_bool($v)) $v = $v ? 'yes' : 'no';
        $out[$k] = in_array($v, $yn, true) ? $v : '';
    }
    $out['marital_status'] = (isset($in['marital_status']) && in_array($in['marital_status'], $marital, true)) ? $in['marital_status'] : '';
    $out['primary_goal']   = (isset($in['primary_goal']) && in_array($in['primary_goal'], $goals, true)) ? $in['primary_goal'] : '';

    $emp = $in['currently_employed'] ?? null;
    if (!is_bool($emp)) { $emp = ($emp === 'yes') ? true : (($emp === 'no') ? false : null); }
    $out['currently_employed'] = $emp;

    foreach ($txtKeys as $k) {
        $v = isset($in[$k]) ? trim((string)$in[$k]) : '';
        if (mb_strlen($v) > 300) $v = mb_substr($v, 0, 300);
        $out[$k] = $v;
    }

    $missing = [];
    foreach ($out as $k => $v) { if ($v === null || $v === '') $missing[] = $k; }
    return [$out, $missing];
}
