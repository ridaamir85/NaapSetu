-- Apply once to an existing NaapSetu database before using the new request form.
ALTER TABLE order_requests
    ADD COLUMN fabric_source ENUM('customer', 'tailor') NOT NULL DEFAULT 'customer' AFTER style_id,
    ADD COLUMN customer_notes TEXT NULL AFTER fabric_source;
