<?php
/**
 * Deterministic guardrails — the "validity" half of the supervised loop.
 * These run on the AI output BEFORE an agent sees it. They never silently rewrite the
 * recommendation; they attach flags. This file is where suitability policy lives in code —
 * KofC's compliance team should own the rules.
 */

function kofc_run_guardrails(array $profile, array $aiItems): array
{
    $flags = [];

    $age           = isset($profile['age']) ? (int)$profile['age'] : null;
    $income        = isset($profile['annual_income']) ? (float)$profile['annual_income'] : null;
    $employed      = !empty($profile['currently_employed']);

    foreach ($aiItems as $idx => $item) {
        $pid = $item['product_id'] ?? '';
        $est = isset($item['estimated_annual_premium']) ? (float)$item['estimated_annual_premium'] : null;

        if ($pid === 'term_life' && $age !== null && $age >= 65) {
            $flags[] = _flag($idx, $pid, 'age_term_mismatch',
                "Term life flagged: applicant age {$age}. Confirm term length is issuable and a better fit than permanent coverage.");
        }

        if ($pid === 'disability_income' && (!$employed || ($age !== null && $age >= 67))) {
            $flags[] = _flag($idx, $pid, 'di_no_income',
                "Disability income flagged: applicant not recorded as employed / past typical working age. DI protects earned income only.");
        }

        if (in_array($pid, ['annuity_spda', 'annuity_fpa', 'annuity_spia'], true)
            && $age !== null && $age < 50) {
            $flags[] = _flag($idx, $pid, 'annuity_early_liquidity',
                "Annuity flagged: applicant age {$age}. Pre-59.5 withdrawals carry surrender charges and a 10% tax penalty. Confirm horizon and liquidity.");
        }

        if ($pid === 'ltc' && $age !== null && $age < 40) {
            $flags[] = _flag($idx, $pid, 'ltc_premature',
                "LTC flagged: applicant age {$age}. Confirm asset-protection rationale; LTC is typically considered from age 50+.");
        }

        if ($est !== null && $income !== null && $income > 0) {
            $ratio = $est / $income;
            if ($ratio > 0.15) {
                $pct = round($ratio * 100);
                $flags[] = _flag($idx, $pid, 'affordability',
                    "Affordability flagged: estimated premium is ~{$pct}% of stated annual income. Re-check coverage amount or product.");
            }
        }
    }

    $required = ['age', 'marital_status', 'has_dependents', 'annual_income', 'primary_goal'];
    $missing  = array_values(array_filter($required, fn($k) => !isset($profile[$k]) || $profile[$k] === ''));
    if ($missing) {
        $flags[] = [
            'item_index' => null,
            'product_id' => null,
            'code'       => 'incomplete_profile',
            'severity'   => 'warning',
            'message'    => 'Incomplete profile: missing ' . implode(', ', $missing) . '. Treat all recommendations as low-confidence.',
        ];
    }

    return $flags;
}

function _flag(int $idx, string $pid, string $code, string $msg): array
{
    return [
        'item_index' => $idx,
        'product_id' => $pid,
        'code'       => $code,
        'severity'   => 'warning',
        'message'    => $msg,
    ];
}
