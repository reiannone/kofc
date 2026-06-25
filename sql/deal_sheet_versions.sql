-- deal_sheet_versions.sql
-- Full edit history of a deal's AI deal sheet, so the supervisor review queue can
-- show a redline (any version vs any other) and a clean current view.
--
-- One row per saved revision of deal_sheet. version_no increments per deal.
-- source distinguishes the agent's authored versions from supervisor edits.
-- The live current text still lives on deals.deal_sheet; this table is the history.
--
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/deal_sheet_versions.sql

CREATE TABLE IF NOT EXISTS deal_sheet_versions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  deal_id     INT UNSIGNED NOT NULL,
  version_no  INT UNSIGNED NOT NULL,
  deal_sheet  MEDIUMTEXT NULL,
  source      ENUM('agent','supervisor') NOT NULL DEFAULT 'agent',
  edited_by   VARCHAR(64) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_deal_version (deal_id, version_no),
  KEY idx_deal (deal_id, version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
