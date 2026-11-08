-- Optional cleanup of the discontinued password-recovery feature.
-- This removes only recovery requests, not users or account passwords.
-- Run ONCE on an existing database after pulling the removal commit.
DROP TABLE IF EXISTS password_recovery_requests;
DELETE FROM notification_reads WHERE event_key LIKE 'recovery:%';
