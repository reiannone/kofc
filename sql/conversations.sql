-- Conversational advisor: persisted turns (audit + context across follow-ups).
CREATE TABLE IF NOT EXISTS conversations (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id   VARCHAR(64)     NOT NULL,
    title      VARCHAR(120)    NULL,
    created_at DATETIME        NOT NULL,
    updated_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_agent   (agent_id),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role            ENUM('user','assistant') NOT NULL,
    content         MEDIUMTEXT      NOT NULL,
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_conv (conversation_id),
    CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
