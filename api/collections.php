<?php
/**
 * Knowledge-base collections, AUTHORITY ORDER, and retrieval TUNING.
 * regulations (binding) > policy (factual) > vetted (supervisor-approved) > sales (approach).
 *
 * Two distinct levers, kept separate on purpose:
 *  - authority_order / preamble  -> conflict resolution (which source wins when they disagree).
 *  - weights / floor / mix / min -> retrieval selection (which chunks make it into the prompt).
 *
 * 'weights' boosts a collection's cosine score so internal/vetted outrank external on marginal slots.
 * 'floor'   drops sub-threshold cosine noise before anything is considered.
 * 'mix'     is the per-collection CAP (max chunks from each) — stops one collection monopolizing.
 * 'min'     guarantees a collection appears if it has anything above the floor (e.g. binding regs).
 * 'k'       is the total passage budget across all collections.
 *
 * These five are the supervisor-tunable dials; kofc_kb_context() also accepts a $tuning override
 * so a stored supervisor config can feed values in without editing this file.
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

    // ---- retrieval tuning (supervisor-tunable) ----
    'weights' => ['regulations' => 1.5, 'policy' => 1.3, 'vetted' => 1.4, 'sales' => 1.0],
    'floor'   => 0.20,                                                  // min raw cosine to be eligible
    'mix'     => ['regulations' => 3, 'policy' => 3, 'vetted' => 3, 'sales' => 2], // per-collection caps
    'min'     => ['regulations' => 1],                                 // guarantee binding regs surface
    'k'       => 6,                                                     // total passages in the block

    'preamble' =>
        "Use the following KofC source passages. AUTHORITY ORDER — when sources conflict, follow this "
      . "priority strictly: (1) Licensing & Regulations are BINDING and override everything; (2) "
      . "Policy/Product facts are authoritative for terms, rates, and eligibility; Vetted answers are "
      . "supervisor-approved exemplars — strongly prefer them for closely matching questions; (3) Sales "
      . "& Training informs approach and positioning only and must NEVER override the above. Cite the "
      . "source filename in brackets.",
];
