-- deals.sql — agent deal workspace: WIP drafts, submission, supervisor review, AI deal sheet.
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/deals.sql
CREATE TABLE IF NOT EXISTS deals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id        VARCHAR(64)  NOT NULL,
  conversation_id INT UNSIGNED NULL,
  client_name     VARCHAR(160) NOT NULL DEFAULT '',
  profile_json    JSON NULL,
  status          ENUM('draft','submitted','approved','returned') NOT NULL DEFAULT 'draft',
  deal_sheet      MEDIUMTEXT NULL,
  submit_note     TEXT NULL,
  review_notes    TEXT NULL,
  reviewed_by     VARCHAR(64) NULL,
  reviewed_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_status (agent_id, status),
  KEY idx_status (status),
  KEY idx_conversation (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
