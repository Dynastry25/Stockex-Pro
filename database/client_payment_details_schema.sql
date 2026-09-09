-- =============================================================
-- StockEx Client Portal: address + flexible payment details
-- Existing-production migration. Idempotent on MariaDB/MySQL versions
-- that support ADD COLUMN IF NOT EXISTS (the project already relies on it).
--
-- Run this once BEFORE deploying the frontend that submits payment_methods.
-- =============================================================

ALTER TABLE client_submission_queue
  ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL AFTER email,
  ADD COLUMN IF NOT EXISTS payment_methods JSON DEFAULT NULL COMMENT 'Selected payout methods: bank, phone, selcom' AFTER currency;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS payment_methods JSON DEFAULT NULL COMMENT 'Approved payout methods from client portal' AFTER currency;

-- Optional verification:
-- SHOW COLUMNS FROM client_submission_queue LIKE 'address';
-- SHOW COLUMNS FROM client_submission_queue LIKE 'payment_methods';
-- SHOW COLUMNS FROM clients LIKE 'payment_methods';
