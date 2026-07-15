<?php
/**
 * eligibility.php — the SINGLE source of truth for hard eligibility floors that gate
 * ANY recommendation, regardless of product. Both the conversational path (chat.php)
 * and the structured recommender (recommend.php) call kofc_eligibility(), so the rule
 * the advisor ASKS about and the rule the recommender ENFORCES can never drift apart.
 *
 * Deliberately NOT stored in product_requirements: those rows answer "what do I need to
 * recommend THIS product well" (presence-only — kofc_field_filled can't express a value
 * rule like ">= 18"). An eligibility floor is a value rule that applies universally, so
 * it lives here and is checked by value. Genuinely product-specific issue-age bands
 * belong with the product specs / KB, not in this universal floor.
 *
 * No DB or other includes required — pure functions over a fact-find array.
 */
declare(strict_types=1);

/** Minimum insured age for KofC products. The one place this number is defined. */
const KOFC_MIN_AGE = 18;

/** Whole-year age from a date-of-birth string, or null if unparseable / out of range. */
function kofc_age_from_dob(string $dob): ?int
{
    try {
        $d   = new DateTime($dob);
        $age = (new DateTime('today'))->diff($d)->y;
        return ($age >= 0 && $age <= 120) ? $age : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve age from an explicit `age` or, failing that, from `member_dob`.
 * Does NOT mutate the caller's profile. Returns null when neither is usable.
 */
function kofc_resolve_age(array $profile): ?int
{
    if (isset($profile['age']) && $profile['age'] !== '' && $profile['age'] !== null && is_numeric($profile['age'])) {
        return (int)$profile['age'];
    }
    if (!empty($profile['member_dob'])) {
        return kofc_age_from_dob((string)$profile['member_dob']);
    }
    return null;
}

/**
 * Evaluate the universal eligibility floor against a fact-find profile.
 *
 * @return array {
 *   ok:         bool,            // false if any hard violation must gate a recommendation
 *   age:        int|null,        // resolved age (explicit or derived from DOB)
 *   violations: array<array{field:string, kind:string, message:string}>,  // kind: 'missing' | 'value'
 *   needs:      array<array{field,label,why,importance,input_type,options}> // chip-shaped, for chat.php gaps
 * }
 *
 * 'missing' => nothing was provided, so it can be ASKED for (a chip is offered in `needs`).
 * 'value'   => a value was provided but fails the floor (e.g. under 18) — nothing to ask,
 *              the caller must explain the ineligibility instead.
 */
function kofc_eligibility(array $profile): array
{
    $violations = [];
    $needs      = [];
    $age        = kofc_resolve_age($profile);

    if ($age === null) {
        $violations[] = [
            'field'   => 'age',
            'kind'    => 'missing',
            'message' => 'Member age (or date of birth) is required.',
        ];
        $needs[] = [
            'field'      => 'age',
            'label'      => 'Member age',
            'why'        => 'Eligibility floor — KofC products require the insured to be ' . KOFC_MIN_AGE . '+.',
            'importance' => 'required',
            'input_type' => 'number',
            'options'    => [],
        ];
    } elseif ($age < KOFC_MIN_AGE) {
        $violations[] = [
            'field'   => 'age',
            'kind'    => 'value',
            'message' => 'Member appears to be under ' . KOFC_MIN_AGE
                       . '; KofC products require the insured to be at least ' . KOFC_MIN_AGE . '.',
        ];
    }

    return [
        'ok'         => count($violations) === 0,
        'age'        => $age,
        'violations' => $violations,
        'needs'      => $needs,
    ];
}
