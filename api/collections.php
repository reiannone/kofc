<?php
/**
 * Knowledge-base collections and their AUTHORITY ORDER.
 * regulations (binding) > policy (factual) > vetted (supervisor-approved) > sales (approach).
 */
return [
    'collections' => [
        'regulations' => ['label' => 'Licensing & Regulations', 'authority' => 1, 'note' => 'Binding constraints; override everything.'],
        'policy'      => ['label' => 'Policy & Product Docs',     'authority' => 2, 'note' => 'Authoritative facts: terms, rates, eligibility.'],
        'vetted'      => ['label' => 'Vetted Answers',            'authority' => 2, 'note' => 'Supervisor-approved exemplars; strongly prefer for matching questions.'],
        'sales'       => ['label' => 'Sales & Training',          'authority' => 3, 'note' => 'Approach and positioning only; never overrides above.'],
    ],
    'authority_order' => ['regulations', 'policy', 'vetted', 'sales'],
    'labels' => [
        'regulations' => 'Licensing & Regulations',
        'policy'      => 'Policy & Product',
        'vetted'      => 'Vetted (Supervisor-Approved)',
        'sales'       => 'Sales & Training',
    ],
    'preamble' =>
        "Use the following KofC source passages. AUTHORITY ORDER — when sources conflict, follow this "
      . "priority strictly: (1) Licensing & Regulations are BINDING and override everything; (2) "
      . "Policy/Product facts are authoritative for terms, rates, and eligibility; Vetted answers are "
      . "supervisor-approved exemplars — strongly prefer them for closely matching questions; (3) Sales "
      . "& Training informs approach and positioning only and must NEVER override the above. Cite the "
      . "source filename in brackets.",
];
