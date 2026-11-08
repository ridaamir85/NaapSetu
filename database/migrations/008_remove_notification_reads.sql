-- Remove the old notification read-status table from an existing database.
-- This does not affect requests, quotations, orders, feedback, or alterations.
DROP TABLE IF EXISTS notification_reads;
