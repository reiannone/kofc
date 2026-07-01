-- licensing-update-tristate.sql
-- Verified fills for NY, NJ, CT (tristate). Run after licensing-schema.sql.
-- Sources verified 2026-07-01 against state DOIs and corroborating providers:
--   NY: NY DFS (dfs.ny.gov) - prelicensing, 15-hr CE, Reg 187 for annuities, NYS Partnership LTC.
--   NJ: NJ DOBI (nj.gov/dobi) - 40-hr PLE Life+A&H, 24-hr CE, 8+4 LTC, 4-hr annuity.
--   CT: CT Insurance Dept (portal.ct.gov/cid) - 20-hr/line PLE, 24-hr CE, 8+4 LTC + Partnership, 4-hr annuity BI (eff. 3/1/2022).
-- Note: NY annuity_bi_adopted stays 'no' (Reg 187, not the revised NAIC model);
--       NJ stays 'pending' (best-interest revision not confirmed adopted); CT is 'yes'.

-- Widen the three free-text verify columns to 255 (idempotent; safe to re-run).
-- Tristate rules (Partnership programs, DEI hours, carryover) exceed the original 160.
ALTER TABLE licensing_state_requirements
  MODIFY prelicensing_hours VARCHAR(255) NULL,
  MODIFY ltc_training       VARCHAR(255) NULL,
  MODIFY ce_cycle           VARCHAR(255) NULL;


UPDATE licensing_state_requirements
SET
  annuity_training   = 'Governed by NY Reg 187 (best-interest; covers life + annuities). Rule sets no fixed hours - insurer sets training standards; a 4-hr annuity suitability course is commonly used to satisfy it.',
  prelicensing_hours = '20 hrs Life (20 hrs Health); 40 hrs Life & Health combined; required before the licensing exam.',
  ltc_training       = 'NYS Partnership for Long-Term Care certification required before selling NYS Partnership LTC policies (administered via NY Dept. of Health / DFS). Verify current hours and approved provider.',
  ce_cycle           = '15 hrs / 2 yrs single line (30 if combined L&H + P&C); renew on birthday biennially; includes required ethics, NY insurance law, and DEI/elimination-of-bias hours; no carryover; no course repeats.'
WHERE state_code = 'NY';


UPDATE licensing_state_requirements
SET
  annuity_training   = 'One-time 4-hr annuity training required before selling annuities. Adoption of the 2020 NAIC best-interest revision was pending as of early 2025 - verify current status.',
  prelicensing_hours = '20 hrs per line of authority; 40 hrs total for Life, Accident & Health; separate certificate per LOA; required before the exam.',
  ltc_training       = 'One-time 8-hr initial LTC training before selling LTC, then 4 hrs ongoing every 24 months.',
  ce_cycle           = '24 hrs / 2 yrs (renew last day of birth month); >=3 hrs ethics; up to 12 non-ethics credits may carry over once (eff. 6/2023); no course repeat in period.'
WHERE state_code = 'NJ';


UPDATE licensing_state_requirements
SET
  annuity_training   = 'One-time 4-hr Annuity Best Interest course before selling annuities (NAIC best-interest standard, effective 3/1/2022).',
  prelicensing_hours = '20 hrs per line of authority; 40 hrs for Life & Health combined; required before the licensing exam.',
  ltc_training       = 'One-time 8-hr LTC course + 4 hrs ongoing every 24 months. Selling CT Partnership LTC policies requires additional Partnership Certification Training (via CT OPM; verify current hours).',
  ce_cycle           = '24 hrs / 2 yrs (renew last day of birth month); >=3 hrs ethics/law/reg; min 6 hrs per line of authority held; up to 24 excess hrs may carry over; no course repeats.'
WHERE state_code = 'CT';


-- Verify all three (expect filled = 1 for each):
SELECT state_code, annuity_bi_adopted,
       (prelicensing_hours IS NOT NULL AND ltc_training IS NOT NULL AND ce_cycle IS NOT NULL) AS filled
FROM licensing_state_requirements
WHERE state_code IN ('NY','NJ','CT')
ORDER BY state_code;
