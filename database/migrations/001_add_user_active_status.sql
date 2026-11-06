-- Apply once to an existing NaapSetu database before deploying Admin Account Management.
ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
