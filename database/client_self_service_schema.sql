-- =============================================================
-- StockEx: Client Self-Service — migration
-- Additive only. Creates the token + audit tables used by
-- the secured client portal API (api/portal/index.php).
--
-- NOTE: This migration assumes the production `clients` table
-- already contains the columns phone, email, bank_name,
-- bank_account_number, bank_branch, currency plus the encrypted
-- copies phone_encrypted, email_encrypted, bank_account_encrypted.
-- If any of those columns are missing, the API degrades to the
-- plaintext columns automatically.
-- =============================================================

CREATE TABLE IF NOT EXISTS client_access_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the raw token; the raw token is never stored',
  expires_at DATETIME NOT NULL,
  max_uses INT DEFAULT 20,
  times_used INT DEFAULT 0,
  revoked TINYINT(1) DEFAULT 0,
  created_by INT DEFAULT NULL COMMENT 'staff user id who minted the link',
  ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP from which the link was minted',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME DEFAULT NULL,
  last_ip VARCHAR(45) DEFAULT NULL,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_client (client_id),
  KEY idx_expires (expires_at),
  CONSTRAINT fk_cat_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS client_profile_update_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  token_id INT DEFAULT NULL COMMENT 'client_access_tokens.id that performed the change',
  action ENUM('link_minted','profile_read','profile_updated','link_revoked','link_expired') NOT NULL,
  changed_fields JSON DEFAULT NULL COMMENT 'masked before/after values only',
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_by INT DEFAULT NULL COMMENT 'staff user id for mint/revoke actions',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_client (client_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================
-- Post-deploy checks:
--   SHOW TABLES LIKE 'client_%';
--   SHOW CREATE TABLE client_access_tokens\G
-- Do NOT rotate ENCRYPTION_KEY after existing *_encrypted
-- columns have been written, or they become unreadable.
-- =============================================================