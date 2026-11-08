-- Apply ONCE to an existing NaapSetu MySQL database.
-- Only one pending request can exist per registered account.
CREATE TABLE password_recovery_requests (
    recovery_id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
    handled_at DATETIME NULL,
    handled_by INT NULL,
    pending_user_id INT GENERATED ALWAYS AS
        (CASE WHEN status = 'pending' THEN user_id ELSE NULL END) STORED,
    PRIMARY KEY (recovery_id),
    UNIQUE KEY uq_password_recovery_pending_user (pending_user_id),
    KEY ix_password_recovery_status_date (status, requested_at),
    CONSTRAINT fk_password_recovery_user
        FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_password_recovery_admin
        FOREIGN KEY (handled_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
