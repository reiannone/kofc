-- feedback-add-title.sql
-- Adds a human-readable title to feedback rows so the supervisor review queue
-- can lead each row (and the expanded detail) with a title, matching the Deals tab.
-- Nullable on purpose: older rows and untitled contexts fall back to the Q/A excerpt
-- in the UI, so this is safe to apply without backfilling.
--
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/feedback-add-title.sql

ALTER TABLE advisor_feedback
  ADD COLUMN title VARCHAR(200) NULL AFTER ref_id;
