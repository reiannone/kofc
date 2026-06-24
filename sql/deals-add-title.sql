-- deals-add-title.sql — add an agent-editable deal title/header.
-- Replaces client_name as the *display* identifier for a deal.
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/deals-add-title.sql
SET NAMES utf8mb4;

-- 1) New column: agent-supplied title shown as the deal header.
--    Sits right after client_name; client_name stays (still feeds the sheet/profile).
ALTER TABLE deals
  ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT '' AFTER client_name;

-- 2) Backfill existing rows so none render nameless.
--    Prefer the client name when present, else the agent id, suffixed with creation time.
--    NOTE: created_at is stored UTC, so these legacy labels carry a UTC time — cosmetic only.
UPDATE deals
SET title = CASE
  WHEN TRIM(client_name) <> '' THEN CONCAT(TRIM(client_name), ' — ', DATE_FORMAT(created_at, '%Y-%m-%d %H:%i'))
  ELSE CONCAT(agent_id, ' — ', DATE_FORMAT(created_at, '%Y-%m-%d %H:%i'))
END
WHERE title = '';
