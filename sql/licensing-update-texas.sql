-- licensing-update-texas.sql
-- Worked example: fill the verify-only fields for one fully-verified state (Texas).
-- Run against kofc_advisor after licensing-schema.sql has seeded the table.
--
-- Sources (verified 2026-07-01):
--   Prelicensing:  Texas Dept. of Insurance; Tex. Ins. Code Sec. 4001.105
--                  https://www.tdi.texas.gov/agent/
--   CE cycle:      Texas Dept. of Insurance, Continuing education for agents
--                  https://www.tdi.texas.gov/agent/agcehome.html  (Tex. Ins. Code Ch. 4004)
--   LTC training:  TDI product-training rules (8-hr initial + 4-hr ongoing LTC Partnership)
--                  https://www.tdi.texas.gov/agent/
--   Annuity:       TDI one-time 4-hr Best Interest + 8-hr annuity CE per period
--                  https://www.tdi.texas.gov/agent/agcehome.html

UPDATE licensing_state_requirements
SET
  prelicensing_hours = 'Not required (optional; ~40 hrs recommended). Temp 90-day license needs 40 hrs within 14 days. Cite: Tex. Ins. Code Sec. 4001.105',
  ltc_training       = 'One-time 8-hr LTC Partnership certification before selling LTC, then 4 hrs LTC CE each renewal period (requires Life/Accident/Health license)',
  ce_cycle           = '24 hrs / 2 yrs (renew last day of birth month); >=3 hrs ethics; >=12 hrs classroom or equivalent; no carryover; no course repeat in period',
  annuity_training   = 'One-time 4-hr Annuity Suitability & Best Interest course before selling annuities; then 8 hrs annuity CE per license period (4-hr BI counts toward part of the 8)'
WHERE state_code = 'TX';

-- Verify the row after the update:
SELECT state_code, annuity_bi_adopted, annuity_training,
       prelicensing_hours, ltc_training, ce_cycle, updated_at
FROM licensing_state_requirements
WHERE state_code = 'TX';
