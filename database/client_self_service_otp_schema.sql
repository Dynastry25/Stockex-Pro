-- =============================================================
-- StockEx: Client Self-Service — OTP + verification migration
-- Additive only. Supports the public self-service flow:
--
--   1) otp_verifications      — DB-backed OTP codes (hashed, single-use)
--   2) client_verification_queue — staff review queue (first claim /
--      bank-flag / duplicate-phone)
--   3) clients flags          — phone_verified, verified_phone_at,
--      bank_pending_verification
--   4) client_access_tokens   — `source` ('staff' | 'self') so the API
--      can distinguish staff-minted links from self-claim sessions.
--
-- Safe to run on MariaDB/MySQL 8+ (uses `ADD COLUMN IF NOT EXISTS`).
-- =============================================================

CREATE TABLE IF NOT EXISTS otp_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cds_account VARCHAR(20) NOT NULL COMMENT 'Upper-cased CDS account the OTP belongs to',
  phone_e164 VARCHAR(15) NOT NULL COMMENT 'E.164 destination number (2557XXXXXXXX)',
  purpose ENUM('bind_phone','session','change_phone_old','change_phone_new') NOT NULL DEFAULT 'bind_phone',
  code_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the code; raw code never stored',
  expires_at DATETIME NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0,
  attempts INT NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cds (cds_account),
  KEY idx_phone (phone_e164),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS client_verification_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  kind ENUM('first_claim','bank_flag','duplicate_phone') NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  status ENUM('open','resolved','rejected') NOT NULL DEFAULT 'open',
  notes VARCHAR(255) DEFAULT NULL,
  client_ip VARCHAR(45) DEFAULT NULL,
  resolved_by INT DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_client (client_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS phone_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set when the phone number was confirmed by SMS OTP',
  ADD COLUMN IF NOT EXISTS verified_phone_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS bank_pending_verification TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Bank details changed via self-service; requires staff verification';

ALTER TABLE client_access_tokens
  ADD COLUMN IF NOT EXISTS `source` ENUM('staff','self') NOT NULL DEFAULT 'staff' COMMENT 'staff = minted link; self = OTP-verified self-claim session';

-- Extend the audit action enum with the new self-service events (idempotent).
ALTER TABLE client_profile_update_log
  MODIFY COLUMN action ENUM('link_minted','profile_read','profile_updated','link_revoked','link_expired','otp_sent','otp_verified','first_claim','phone_verified','code_redeemed','change_phone') NOT NULL;

-- =============================================================
-- Post-deploy checks:
--   SHOW COLUMNS FROM clients LIKE '%verified%';
--   SHOW COLUMNS FROM client_access_tokens LIKE 'source';
--   SHOW TABLES LIKE '%otp%'; SHOW TABLES LIKE '%verification%';
-- =============================================================