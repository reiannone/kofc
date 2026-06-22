-- Feedback capture for the supervised loop. Self-contained (carries the Q/A text) so the
-- review/promotion workflow doesn't need cross-table lookups.
CREATE TABLE IF NOT EXISTS advisor_feedback (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ref_type         ENUM('chat','recommendation') NOT NULL,
    ref_id           BIGINT UNSIGNED NULL,
    agent_id         VARCHAR(64)     NOT NULL,
    vote             ENUM('up','down') NOT NULL,
    reason_code      VARCHAR(40)     NULL,
    comment          TEXT            NULL,
    question_text    MEDIUMTEXT      NULL,
    answer_text      MEDIUMTEXT      NULL,
    suggested_answer MEDIUMTEXT      NULL,
    status           ENUM('new','approved','dismissed','promoted') NOT NULL DEFAULT 'new',
    reviewed_by      VARCHAR(64)     NULL,
    reviewed_at      DATETIME        NULL,
    created_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_status  (status),
    KEY idx_vote    (vote),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
