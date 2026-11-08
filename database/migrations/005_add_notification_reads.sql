-- Apply once to existing NaapSetu databases after migration 004.
-- Read receipts are per-user; original business records remain the source of truth.
CREATE TABLE notification_reads (
    user_id INT NOT NULL,
    event_key VARCHAR(100) NOT NULL,
    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, event_key),
    CONSTRAINT fk_notification_reads_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
