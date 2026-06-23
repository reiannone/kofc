-- kb_tuning.sql — single-row store for supervisor-set retrieval weighting.
-- Empty/absent = collections.php defaults apply. Load:  mysql ... kofc_advisor < sql/kb_tuning.sql
CREATE TABLE IF NOT EXISTS kb_tuning (
  id         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  config     JSON NOT NULL,
  updated_by VARCHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
