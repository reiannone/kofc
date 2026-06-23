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
        'age' => null, 'marital_status' => '', 'has_dependents' => '',
        'annual_income' => null, 'currently_employed' => null,
        'primary_goal' => '', 'existing_coverage' => '', 'budget_monthly' => null,
    ];
}

function kofc_run_extract(string $transcript): array
{
    $system =
        "You extract a structured insurance member profile from a conversation between a Knights of\n"
      . "Columbus field AGENT and an AI ASSISTANT about the agent's client. Use ONLY facts the agent\n"
      . "stated or that are clearly implied. NEVER guess or infer beyond what's said. If a field is\n"
      . "unknown, return null (numbers) or \"\" (text/enums) — do not fill it.\n\n"
      . "Respond with ONLY a JSON object, no markdown, no preamble, in exactly this shape:\n"
      . '{"age":null,"marital_status":"","has_dependents":"","annual_income":null,'
      . '"currently_employed":null,"primary_goal":"","existing_coverage":"","budget_monthly":null,'
      . '"missing":[]}' . "\n\n"
      . "Field rules:\n"
      . "- age: integer years, or null.\n"
      . "- marital_status: exactly one of \"single\", \"married\", \"widowed\", or \"\".\n"
      . "- has_dependents: \"yes\", \"no\", or \"\".\n"
      . "- annual_income: integer US dollars per year, or null.\n"
      . "- currently_employed: true, false, or null.\n"
      . "- primary_goal: exactly one of \"income_replacement\", \"mortgage_protection\",\n"
      . "  \"retirement_income\", \"long_term_care\", \"estate_legacy\", or \"\". Pick the single best fit.\n"
      . "- existing_coverage: a short phrase describing coverage the client already has, or \"\".\n"
      . "- budget_monthly: integer US dollars per month the client can spend, or null.\n"
      . "- missing: array of the field names above that remain unknown after reading.\n";

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

    if (preg_match('/\b(\d{2})\s*[- ]?\s*year[- ]?old\b/', $t, $m)) {
        $p['age'] = (int)$m[1];
    } elseif (preg_match('/\bage\s*(?:is\s*)?(\d{2})\b/', $t, $m)) {
        $p['age'] = (int)$m[1];
    }

    if (strpos($t, 'married') !== false)       $p['marital_status'] = 'married';
    elseif (strpos($t, 'widow') !== false)     $p['marital_status'] = 'widowed';
    elseif (strpos($t, 'single') !== false)    $p['marital_status'] = 'single';

    if (preg_match('/\b(kids|children|dependents?|son|daughter)\b/', $t)) {
        $p['has_dependents'] = 'yes';
    }

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
 * Force the model output into the exact Recommend shape and valid enum values,
 * then recompute `missing` from the sanitized result (don't trust the model's list).
 */
function kofc_sanitize_profile(array $in): array
{
    $marital = ['single', 'married', 'widowed'];
    $goals   = ['income_replacement', 'mortgage_protection', 'retirement_income', 'long_term_care', 'estate_legacy'];

    $age = (isset($in['age']) && is_numeric($in['age'])) ? (int)$in['age'] : null;
    if ($age !== null && ($age < 0 || $age > 120)) $age = null;

    $income = (isset($in['annual_income']) && is_numeric($in['annual_income'])) ? (int)$in['annual_income'] : null;
    if ($income !== null && $income < 0) $income = null;

    $budget = (isset($in['budget_monthly']) && is_numeric($in['budget_monthly'])) ? (int)$in['budget_monthly'] : null;
    if ($budget !== null && $budget < 0) $budget = null;

    $ms = (isset($in['marital_status']) && in_array($in['marital_status'], $marital, true)) ? $in['marital_status'] : '';

    $hd = $in['has_dependents'] ?? '';
    if (is_bool($hd)) $hd = $hd ? 'yes' : 'no';
    $hd = in_array($hd, ['yes', 'no'], true) ? $hd : '';

    $emp = $in['currently_employed'] ?? null;
    if (!is_bool($emp)) {
        if ($emp === 'yes')      $emp = true;
        elseif ($emp === 'no')   $emp = false;
        else                     $emp = null;
    }

    $goal = (isset($in['primary_goal']) && in_array($in['primary_goal'], $goals, true)) ? $in['primary_goal'] : '';

    $cov = isset($in['existing_coverage']) ? trim((string)$in['existing_coverage']) : '';
    if (mb_strlen($cov) > 300) $cov = mb_substr($cov, 0, 300);

    $profile = [
        'age'                => $age,
        'marital_status'     => $ms,
        'has_dependents'     => $hd,
        'annual_income'      => $income,
        'currently_employed' => $emp,
        'primary_goal'       => $goal,
        'existing_coverage'  => $cov,
        'budget_monthly'     => $budget,
    ];

    $missing = [];
    foreach ($profile as $k => $v) {
        if ($v === null || $v === '') $missing[] = $k;
    }

    return [$profile, $missing];
}
