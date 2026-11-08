-- Run ONCE on existing NaapSetu database after migration 005.
-- Keep legacy services for FK history, but stop offering them on new requests.
ALTER TABLE tailor_services
  ADD COLUMN specialization ENUM('men','women') NULL,
  ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD UNIQUE KEY uq_tailor_specialization (tailor_id, specialization);
UPDATE tailor_services SET is_enabled=0 WHERE specialization IS NULL;

-- Text-based garment requirements are independent of service specialization.
ALTER TABLE order_requests
  ADD COLUMN stitching_details TEXT NULL,
  ADD COLUMN fabric_type VARCHAR(80) NULL,
  ADD COLUMN fabric_color VARCHAR(80) NULL,
  ADD COLUMN fabric_preferences TEXT NULL,
  ADD COLUMN reference_image_path VARCHAR(200) NULL;

-- Only the tailor arranges fabric on NEW requests; old data stays intact.
ALTER TABLE order_requests ALTER COLUMN fabric_source SET DEFAULT 'tailor';
