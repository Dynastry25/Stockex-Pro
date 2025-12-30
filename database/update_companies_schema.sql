-- Update companies table to include image fields
ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN header_image_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN footer_image_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Create uploads directory structure (this will be handled by PHP)
-- uploads/companies/logos/
-- uploads/companies/headers/
-- uploads/companies/footers/
