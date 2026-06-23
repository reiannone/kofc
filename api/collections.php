<?php
/**
 * Knowledge-base collections and their AUTHORITY ORDER.
 * When retrieved passages conflict, the model is told to follow this priority:
 *   regulations (binding) > policy (factual) > sales (approach only).
 */
return [
    'collections' => [
        'regulations' => ['label' => 'Licensing & Regulations', 'authority' => 1, 'note' => 'Binding constraints; override everything.'],
        'policy'      => ['label' => 'Policy & Product Docs',     'authority' => 2, 'note' => 'Authoritative facts: terms, rates, eligibility.'],
        'sales'       => ['label' => 'Sales & Training',          'authority' => 3, 'note' => 'Approach and positioning only; never overrides 1 or 2.'],
    ],
    'authority_order' => ['regulations', 'policy', 'sales'],
    'labels' => [
        'regulations' => 'Licensing & Regulations',
        'policy'      => 'Policy & Product',
        'sales'       => 'Sales & Training',
    ],
    'preamble' =>
        "Use the following KofC source passages. AUTHORITY ORDER — when sources conflict, follow this "
      . "priority strictly: (1) Licensing & Regulations are BINDING and override everything; (2) "
      . "Policy/Product facts are authoritative for terms, rates, and eligibility; (3) Sales & Training "
      . "informs approach and positioning only and must NEVER override (1) or (2). Cite the source "
      . "filename in brackets.",
];
