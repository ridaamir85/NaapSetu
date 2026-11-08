-- Apply once to an existing NaapSetu database before deploying the password flow.
-- Does not change any existing passwords or accounts.
ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;
