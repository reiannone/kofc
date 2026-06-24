-- product_requirements.sql
-- Category-level "what does the advisor still need before it can recommend this?"
-- Drives interactive gap-elicitation in chat.php: as the agent works a deal, the
-- advisor diffs this against the 38-field fact-find and asks for what's missing —
-- conversationally in chat AND as tap-to-fill chips. Supervisor-editable (same
-- spirit as kb_tuning / prompt_templates) so the ask list tunes without a redeploy.
--
-- importance:
--   'required'   -> HARD GATE floor. Deal sheet is held until supplied.
--   'sharpening' -> non-gating. Advisor proceeds; notes it'd be sharper with this.
--
-- input_type / options drive the chip UI: 'select' uses the CSV in `options`
-- ("value|Label, value|Label"); 'boolean' renders yes/no; 'number' a numeric input.
--
-- Every field_key below is a REAL fact-find key (NUM_KEYS/STR_KEYS in App.jsx),
-- verified against the schema. The gating helper still drops any unknown key so a
-- future typo can't deadlock the hard gate.
--
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/product_requirements.sql

CREATE TABLE IF NOT EXISTS product_requirements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category    VARCHAR(40)  NOT NULL,                 -- 'term_life' | 'whole_life' | ...
  field_key   VARCHAR(64)  NOT NULL,                 -- must match a fact-find profile key
  label       VARCHAR(160) NOT NULL,                 -- agent-facing prompt for this field
  importance  ENUM('required','sharpening') NOT NULL DEFAULT 'sharpening',
  why         VARCHAR(240) NULL,                     -- one-line rationale
  input_type  ENUM('text','number','select','boolean') NOT NULL DEFAULT 'text',
  options     VARCHAR(500) NULL,                     -- for select: "value|Label, value|Label"
  sort_order  INT NOT NULL DEFAULT 100,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  updated_by  VARCHAR(64) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_field (category, field_key),
  KEY idx_cat_active (category, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shared option lists (kept in sync with App.jsx fSel definitions):
--   primary_goal:   income_replacement|Income replacement, mortgage_protection|Mortgage protection,
--                   retirement_income|Retirement income, long_term_care|Long-term care planning,
--                   estate_legacy|Estate / legacy
--   marital_status: single|Single, married|Married, widowed|Widowed, divorced|Divorced

INSERT INTO product_requirements (category, field_key, label, importance, why, input_type, options, sort_order) VALUES
-- ===================== TERM LIFE (temporary / income-replacement need) =====================
('term_life', 'primary_goal',      'Primary goal for this coverage',        'required',   'Sets the purpose and the basis for the face amount.',            'select',  'income_replacement|Income replacement, mortgage_protection|Mortgage protection, retirement_income|Retirement income, long_term_care|Long-term care planning, estate_legacy|Estate / legacy', 10),
('term_life', 'has_dependents',    'Are there dependents?',                 'required',   'Dependents drive the income-replacement need.',                  'boolean', NULL, 20),
('term_life', 'annual_income',     'Annual income',                         'required',   'Anchors income-replacement (DIME / multiple-of-income) sizing.', 'number',  NULL, 30),
('term_life', 'budget_monthly',    'Monthly premium budget',                'required',   'Affordability bounds term length and face amount.',              'number',  NULL, 40),
('term_life', 'existing_coverage', 'Existing life coverage in force',       'required',   'Gap analysis — avoids over- or under-insuring.',                 'text',    NULL, 50),
('term_life', 'need_income_replace','Income to replace (annual)',           'sharpening', 'Sharpens the DIME face-amount calculation.',                     'number',  NULL, 60),
('term_life', 'need_mortgage',     'Mortgage balance to cover',             'sharpening', 'DIME component — mortgage payoff.',                              'number',  NULL, 70),
('term_life', 'need_debts',        'Other debts to cover',                  'sharpening', 'DIME component — debt payoff.',                                  'number',  NULL, 80),
('term_life', 'marital_status',    'Marital status',                        'sharpening', 'Survivor and spousal-income considerations.',                    'select',  'single|Single, married|Married, widowed|Widowed, divorced|Divorced', 90),
('term_life', 'coverage_feeling',  'How they feel about current coverage',  'sharpening', 'Surfaces under-insured anxiety or over-coverage.',               'text',    NULL, 100),

-- ===================== WHOLE LIFE (permanent / legacy need) =====================
('whole_life', 'primary_goal',      'Primary goal for this coverage',       'required',   'Permanent vs. temporary need — justifies a whole-life basis.',   'select',  'income_replacement|Income replacement, mortgage_protection|Mortgage protection, retirement_income|Retirement income, long_term_care|Long-term care planning, estate_legacy|Estate / legacy', 10),
('whole_life', 'has_dependents',    'Are there dependents?',                'required',   'Dependents and legacy intent drive the permanent need.',         'boolean', NULL, 20),
('whole_life', 'annual_income',     'Annual income',                        'required',   'Affordability and needs-analysis sizing.',                       'number',  NULL, 30),
('whole_life', 'budget_monthly',    'Monthly premium budget',               'required',   'Whole-life premiums are higher — affordability is decisive.',     'number',  NULL, 40),
('whole_life', 'existing_coverage', 'Existing life coverage in force',      'required',   'Gap analysis against the permanent need.',                       'text',    NULL, 50),
('whole_life', 'has_will',          'Do they have a will?',                 'sharpening', 'Estate-readiness context for a permanent/legacy plan.',          'boolean', NULL, 60),
('whole_life', 'has_trust',         'Do they have / need a trust?',         'sharpening', 'Trust structure shapes legacy positioning.',                    'boolean', NULL, 70),
('whole_life', 'wealth_transfer_plan','Is there a wealth-transfer plan?',   'sharpening', 'Whole life is often wealth-transfer driven.',                   'boolean', NULL, 80),
('whole_life', 'need_final_expenses','Final-expense target',                'sharpening', 'Common whole-life anchor for smaller permanent needs.',          'number',  NULL, 90),
('whole_life', 'marital_status',    'Marital status',                       'sharpening', 'Survivor and estate considerations.',                            'select',  'single|Single, married|Married, widowed|Widowed, divorced|Divorced', 100)
ON DUPLICATE KEY UPDATE
  label = VALUES(label), importance = VALUES(importance), why = VALUES(why),
  input_type = VALUES(input_type), options = VALUES(options), sort_order = VALUES(sort_order), active = 1;
