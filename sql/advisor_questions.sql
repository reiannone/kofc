-- Audit log for free-form Ask questions.
CREATE TABLE IF NOT EXISTS advisor_questions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id   VARCHAR(64)     NOT NULL,
    question   TEXT            NOT NULL,
    answer     MEDIUMTEXT      NULL,
    ai_model   VARCHAR(64)     NOT NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_agent   (agent_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
