-- prompt_templates.sql — versioned, supervisor-editable system prompts.
-- Empty table = built-in defaults from prompts.php apply (and seed on first Prompts-tab load).
-- Load:  mysql ... kofc_advisor < sql/prompt_templates.sql
CREATE TABLE IF NOT EXISTS prompt_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prompt_key  VARCHAR(64)  NOT NULL,
  version     INT UNSIGNED NOT NULL DEFAULT 1,
  body        MEDIUMTEXT   NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 0,
  edited_by   VARCHAR(64)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_key_version (prompt_key, version),
  KEY idx_key_active (prompt_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
