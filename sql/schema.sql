-- KofC AI Advisor — schema
-- recommendations = the compliance record (exact profile + model that produced each output).
-- recommendation_reviews = agent supervision capture.

CREATE TABLE IF NOT EXISTS recommendations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id        VARCHAR(64)     NOT NULL,
    member_profile  JSON            NOT NULL,
    ai_model        VARCHAR(64)     NOT NULL,
    ai_output       JSON            NOT NULL,
    guardrail_flags JSON            NULL,
    status          ENUM('pending_review','reviewed') NOT NULL DEFAULT 'pending_review',
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_agent   (agent_id),
    KEY idx_status  (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recommendation_reviews (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recommendation_id BIGINT UNSIGNED NOT NULL,
    agent_id          VARCHAR(64)     NOT NULL,
    accuracy_rating   TINYINT         NULL,
    decisions         JSON            NOT NULL,
    notes             TEXT            NULL,
    reviewed_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rec (recommendation_id),
    CONSTRAINT fk_review_rec FOREIGN KEY (recommendation_id)
        REFERENCES recommendations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE OR REPLACE VIEW v_acceptance AS
SELECT
    r.ai_model,
    DATE(rv.reviewed_at) AS review_day,
    COUNT(*)             AS reviews,
    AVG(rv.accuracy_rating) AS avg_rating
FROM recommendation_reviews rv
JOIN recommendations r ON r.id = rv.recommendation_id
GROUP BY r.ai_model, DATE(rv.reviewed_at);
