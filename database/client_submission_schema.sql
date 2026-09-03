-- =============================================================
-- StockEx: Open Form Submission Schema
-- =============================================================

CREATE TABLE IF NOT EXISTS client_submission_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  cds_account VARCHAR(20) NOT NULL,
  submitted_name VARCHAR(255) NOT NULL,
  match_pct INT NOT NULL DEFAULT 0 COMMENT 'Name match percentage vs stored name',
  phone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  bank_name VARCHAR(100) DEFAULT NULL,
  bank_account_number VARCHAR(50) DEFAULT NULL,
  bank_branch VARCHAR(100) DEFAULT NULL,
  currency VARCHAR(10) DEFAULT 'TZS',
  status ENUM('pending','approved','rejected','used') DEFAULT 'pending',
  review_notes TEXT DEFAULT NULL,
  reviewed_by INT DEFAULT NULL COMMENT 'staff user id',
  reviewed_at DATETIME DEFAULT NULL,
  client_ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_client (client_id),
  KEY idx_cds (cds_account),
  KEY idx_status (status),
  CONSTRAINT fk_sq_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Extend audit action enum
ALTER TABLE client_profile_update_log
  MODIFY COLUMN action ENUM(
    'link_minted','profile_read','profile_updated','link_revoked','link_expired',
    'otp_sent','otp_verified','first_claim','phone_verified','code_redeemed','change_phone',
    'form_submission','form_submission_approved','form_submission_rejected'
  ) NOT NULL;
