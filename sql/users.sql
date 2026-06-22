-- App users for login. Passwords stored as PHP password_hash() (bcrypt). Never plaintext.
CREATE TABLE IF NOT EXISTS users (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username             VARCHAR(64)     NOT NULL,
    password_hash        VARCHAR(255)    NOT NULL,
    role                 ENUM('agent','admin') NOT NULL DEFAULT 'agent',
    must_change_password TINYINT         NOT NULL DEFAULT 0,
    created_at           DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
