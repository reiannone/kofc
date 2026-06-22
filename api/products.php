<?php
/**
 * KofC product knowledge base.
 * Single source of truth the AI reasons over AND the basis for deterministic guardrails.
 * Update products here without touching prompt logic. Promote to a DB table later if you
 * want non-developers to edit it; the shape stays the same.
 */

return [
    'eligibility_note' =>
        'KofC insurance is available exclusively to practicing Catholic men age 18+ who are '
        . 'members of the Order, and their eligible family members. All products are distributed '
        . 'through licensed KofC field agents. AI output is an INITIAL recommendation for the '
        . 'agent to review, never a binding suitability determination.',

    'products' => [
        [
            'id' => 'perm_whole_life',
            'name' => 'Permanent Life Insurance — Whole Life',
            'category' => 'life',
            'purpose' => 'Lifelong death benefit with guaranteed cash value accumulation; estate and legacy planning.',
            'fit_signals' => ['permanent need', 'wants cash value', 'estate/legacy goal', 'lifelong dependents', 'wants level premium for life'],
            'poor_fit_signals' => ['only a short-term/temporary need', 'very tight budget needing max coverage per dollar'],
            'riders' => ['chronic_illness_armor'],
        ],
        [
            'id' => 'perm_universal_life',
            'name' => 'Permanent Life Insurance — Universal Life',
            'category' => 'life',
            'purpose' => 'Permanent coverage with flexible premiums and adjustable death benefit.',
            'fit_signals' => ['permanent need', 'wants premium flexibility', 'variable cash flow'],
            'poor_fit_signals' => ['only a short-term need', 'wants simplest guaranteed structure'],
            'riders' => ['chronic_illness_armor'],
        ],
        [
            'id' => 'term_life',
            'name' => 'Term Life Insurance',
            'category' => 'life',
            'purpose' => 'Affordable protection for temporary needs (mortgage, income replacement during working years).',
            'fit_signals' => ['temporary need', 'mortgage protection', 'young family on a budget', 'income replacement until retirement'],
            'poor_fit_signals' => ['needs lifelong coverage', 'wants cash value'],
            'riders' => [],
        ],
        [
            'id' => 'annuity_spda',
            'name' => 'Retirement Annuity — Single Premium Deferred (SPDA)',
            'category' => 'annuity',
            'purpose' => 'Lump sum that accrues at a guaranteed minimum rate until withdrawals begin; guaranteed principal.',
            'fit_signals' => ['has a lump sum to deploy', 'retirement savings goal', 'wants guaranteed principal', 'horizon before retirement'],
            'poor_fit_signals' => ['needs liquidity soon', 'under 59.5 with short horizon (surrender + tax penalty risk)'],
            'riders' => [],
        ],
        [
            'id' => 'annuity_fpa',
            'name' => 'Retirement Annuity — Flexible Premium',
            'category' => 'annuity',
            'purpose' => 'Build retirement savings with regular or irregular contributions; guaranteed principal.',
            'fit_signals' => ['wants to contribute over time', 'no single lump sum', 'retirement savings goal'],
            'poor_fit_signals' => ['needs liquidity soon', 'no retirement objective'],
            'riders' => [],
        ],
        [
            'id' => 'annuity_spia',
            'name' => 'Retirement Annuity — Single Premium Immediate (SPIA)',
            'category' => 'annuity',
            'purpose' => 'Lump sum converted immediately into a guaranteed income stream for life.',
            'fit_signals' => ['at or near retirement', 'wants income now', 'has a lump sum', 'longevity concern'],
            'poor_fit_signals' => ['still accumulating', 'needs access to principal'],
            'riders' => [],
        ],
        [
            'id' => 'ltc',
            'name' => 'Long-Term Care Insurance',
            'category' => 'ltc',
            'purpose' => 'Covers extended care costs in a facility or at home; protects assets later in life.',
            'fit_signals' => ['age 50+', 'has assets to protect', 'family history of long-term care needs', 'planning for aging'],
            'poor_fit_signals' => ['very young with no assets', 'cannot afford the premium long-term'],
            'riders' => ['compound_inflation'],
        ],
        [
            'id' => 'disability_income',
            'name' => 'Disability Income Insurance',
            'category' => 'disability',
            'purpose' => 'Replaces a portion of income if illness or injury prevents working.',
            'fit_signals' => ['currently employed/earning', 'dependents rely on income', 'working years ahead'],
            'poor_fit_signals' => ['retired or not earning income', 'no income to protect'],
            'riders' => [],
        ],
    ],

    'riders' => [
        [
            'id' => 'chronic_illness_armor',
            'name' => 'Chronic Illness Armor Rider',
            'attaches_to' => ['perm_whole_life', 'perm_universal_life'],
            'purpose' => 'Accelerates the death benefit if the policyholder is diagnosed with a qualifying chronic illness.',
        ],
        [
            'id' => 'compound_inflation',
            'name' => 'Compound Inflation Rider',
            'attaches_to' => ['ltc'],
            'purpose' => 'Increases the maximum lifetime LTC benefit annually to offset rising care costs.',
        ],
    ],
];
