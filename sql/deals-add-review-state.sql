-- deals-add-review-state.sql
-- Adds an orthogonal review marker to deals, independent of workflow `status`.
--   review_state: none      -> no supervisor edits
--                 redlined  -> supervisor edited the sheet (set by deal-update.php)
--                 accepted  -> agent accepted the supervisor's edits (set by deal-accept.php)
-- An agent revision (deal-save.php writing the sheet) resets this to 'none' — a fresh round.
-- Nullable redlined_by/at give the row a "who/when" for the pill tooltip.
--
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/deals-add-review-state.sql

ALTER TABLE deals
  ADD COLUMN review_state ENUM('none','redlined','accepted') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN redlined_by  VARCHAR(64) NULL AFTER review_state,
  ADD COLUMN redlined_at  TIMESTAMP NULL AFTER redlined_by;
