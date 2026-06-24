<?php
/**
 * product-gates.php — category-level gap elicitation for the advisor.
 *
 * As the agent works a deal, this works out what the advisor still needs before it
 * can recommend responsibly:
 *   - REQUIRED gaps  -> hard-gate floor: hold the deal sheet, ask for these first.
 *   - SHARPENING gaps-> non-gating: recommend, but note it'd be sharper with these.
 *
 * Early in a deal, before a product is chosen, callers use the SHARED CORE — the
 * required fields common to every active category — so the questions asked are
 * exactly the ones that cause the deal to narrow toward a product.
 *
 * Deadlock guard: a field whose key the fact-find schema doesn't support is DROPPED
 * from gating and reported in unknown_keys — so a bad/typo'd key can never trap the
 * agent in an un-satisfiable ask.
 *
 * Requires: kofc_db() (config.php).
 */
declare(strict_types=1);

/**
 * Canonical 38-field fact-find key set (mirrors NUM_KEYS + STR_KEYS + currently_employed
 * in App.jsx). Single source of truth for the deadlock guard. If the fact-find schema
 * changes, update this list to match.
 */
function kofc_factfind_keys(): array
{
    static $keys = null;
    if ($keys !== null) return $keys;
    $num = [
        'age', 'annual_income', 'spouse_income', 'budget_monthly',
        'need_debts', 'need_mortgage', 'need_education', 'need_final_expenses', 'need_income_replace',
        'retire_target_number', 'retire_age', 'combined_liquid_savings', 'combined_nonqualified',
    ];
    $str = [
        'member_name', 'member_dob', 'council_number', 'member_occupation',
        'marital_status', 'spouse_name', 'spouse_dob', 'anniversary_date', 'spouse_occupation',
        'has_dependents', 'children',
        'has_will', 'will_last_updated', 'has_trust', 'special_needs_trust',
        'wealth_transfer_plan', 'estate_tax_plan', 'ltc_plan', 'ltc_plan_details',
        'retire_income_goal', 'expecting_inheritance',
        'primary_goal', 'existing_coverage', 'coverage_feeling',
    ];
    return $keys = array_merge($num, $str, ['currently_employed']);
}

/**
 * Distinct active category names.
 */
function kofc_product_categories(): array
{
    $rows = kofc_db()->query(
        'SELECT DISTINCT category FROM product_requirements WHERE active = 1 ORDER BY category'
    )->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

/**
 * Active requirements for a category, required-first then by sort_order.
 */
function kofc_product_requirements(string $category): array
{
    static $cache = [];
    if (array_key_exists($category, $cache)) return $cache[$category];
    $stmt = kofc_db()->prepare(
        'SELECT field_key, label, importance, why, input_type, options, sort_order
           FROM product_requirements
          WHERE category = :c AND active = 1
          ORDER BY (importance = "required") DESC, sort_order ASC, id ASC'
    );
    $stmt->execute([':c' => $category]);
    return $cache[$category] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Shared-core requirements: the REQUIRED fields common to every active category,
 * as synthetic requirement rows. Used before a product is chosen so the advisor
 * asks the always-relevant questions that drive the narrowing.
 */
function kofc_shared_core_requirements(): array
{
    $cats = kofc_product_categories();
    if (!$cats) return [];
    $byKey = [];   // field_key => requirement row (first seen)
    $count = [];   // field_key => how many categories mark it required
    foreach ($cats as $c) {
        foreach (kofc_product_requirements($c) as $r) {
            if ($r['importance'] !== 'required') continue;
            $k = $r['field_key'];
            $count[$k] = ($count[$k] ?? 0) + 1;
            if (!isset($byKey[$k])) $byKey[$k] = $r;
        }
    }
    $n = count($cats);
    $shared = [];
    foreach ($byKey as $k => $r) {
        if ($count[$k] === $n) $shared[] = $r;   // required in ALL active categories
    }
    usort($shared, static fn ($a, $b) => ($a['sort_order'] <=> $b['sort_order']));
    return $shared;
}

/**
 * Is a profile value "filled"? Present, non-null, non-empty-string.
 * 0 and false ARE filled (valid answers — e.g. currently_employed = false).
 */
function kofc_field_filled($val): bool
{
    if ($val === null) return false;
    if (is_string($val) && trim($val) === '') return false;
    return true;
}

/**
 * Parse a "value|Label, value|Label" options string into [{value,label}, ...].
 */
function kofc_parse_options(?string $opts): array
{
    if (!$opts) return [];
    $out = [];
    foreach (explode(',', $opts) as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        $parts = array_map('trim', explode('|', $pair, 2));
        $out[] = ['value' => $parts[0], 'label' => $parts[1] ?? $parts[0]];
    }
    return $out;
}

/**
 * Diff a set of requirement rows against a member profile.
 *
 * @param array      $reqs       requirement rows (from kofc_product_requirements or shared core)
 * @param array      $profile    the fact-find (key => value)
 * @param string[]   $validKeys  keys the fact-find supports; [] disables the guard
 *
 * @return array {
 *   required_missing, sharpening_missing, needs (required first), hold(bool), unknown_keys[]
 * }
 * need = { field, label, why, importance, input_type, options:[{value,label}] }
 */
function kofc_gaps_from_requirements(array $reqs, array $profile, array $validKeys): array
{
    $out = [
        'required_missing'   => [],
        'sharpening_missing' => [],
        'needs'              => [],
        'hold'               => false,
        'unknown_keys'       => [],
    ];
    $guard    = !empty($validKeys);
    $validSet = $guard ? array_flip($validKeys) : [];

    foreach ($reqs as $r) {
        $k = $r['field_key'];
        if ($guard && !isset($validSet[$k])) { $out['unknown_keys'][] = $k; continue; }
        if (kofc_field_filled($profile[$k] ?? null)) continue;

        $need = [
            'field'      => $k,
            'label'      => $r['label'],
            'why'        => $r['why'],
            'importance' => $r['importance'],
            'input_type' => $r['input_type'] ?? 'text',
            'options'    => kofc_parse_options($r['options'] ?? null),
        ];
        $out['needs'][] = $need;
        if ($r['importance'] === 'required') $out['required_missing'][] = $need;
        else $out['sharpening_missing'][] = $need;
    }
    $out['hold'] = count($out['required_missing']) > 0;
    return $out;
}

/**
 * Top-level entry. Resolve which requirement set applies, then diff.
 *
 * @param string|null $category  the deal's chosen product, or null/'' before one is set
 * @param array       $profile   the fact-find
 * @param array       $validKeys defaults to the canonical fact-find keys
 *
 * When $category is empty, the SHARED CORE is used (drives the narrowing phase).
 * Adds 'category' and 'mode' to the result for the caller/UI.
 */
function kofc_product_gaps(?string $category, array $profile, ?array $validKeys = null): array
{
    $validKeys = $validKeys ?? kofc_factfind_keys();
    $category  = $category !== null ? trim($category) : '';

    if ($category !== '' && kofc_product_requirements($category)) {
        $reqs = kofc_product_requirements($category);
        $mode = 'category';
    } else {
        $reqs = kofc_shared_core_requirements();
        $mode = 'shared_core';
        $category = '';
    }

    $res = kofc_gaps_from_requirements($reqs, $profile, $validKeys);
    $res['category'] = $category;
    $res['mode']     = $mode;
    return $res;
}

/**
 * Compact, model-facing brief — drop into the chat prompt so the model asks for the
 * right things in its own voice. Caps the number of items so the reply stays focused.
 * Returns '' when nothing is missing.
 */
function kofc_gaps_prompt_brief(array $gaps, int $maxAsk = 3): string
{
    if (empty($gaps['needs'])) return '';
    $lines = [];
    $req = $gaps['required_missing'];
    $shp = $gaps['sharpening_missing'];

    if ($req) {
        $lines[] = 'You do NOT yet have enough to recommend a specific product. Before recommending one, '
                 . 'naturally ask the agent for the most important missing facts below (ask at most '
                 . $maxAsk . ' this turn, most important first). Do not present a final product recommendation yet:';
        foreach (array_slice($req, 0, $maxAsk) as $n) {
            $lines[] = '- ' . $n['label'] . ($n['why'] ? ' — ' . $n['why'] : '');
        }
    } elseif ($shp) {
        $lines[] = 'You have enough to recommend. If it flows naturally, you may ask for one of these to '
                 . 'sharpen the plan, but a recommendation is appropriate now:';
        foreach (array_slice($shp, 0, $maxAsk) as $n) {
            $lines[] = '- ' . $n['label'] . ($n['why'] ? ' — ' . $n['why'] : '');
        }
    }
    return implode("\n", $lines);
}
