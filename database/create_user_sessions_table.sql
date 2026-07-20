-- Migration: Create user_sessions table for tracking daily popup displays
-- Table tracks user login sessions to determine if first-daily-popup should be shown

CREATE TABLE IF NOT EXISTS user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    last_login_date DATE,
    first_daily_popup_shown BOOLEAN DEFAULT FALSE,
    popup_display_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_login (last_login_date)
);

-- Insert initial records for existing users
INSERT INTO user_sessions (user_id, last_login_date, first_daily_popup_shown)
SELECT id, NULL, FALSE FROM users
ON DUPLICATE KEY UPDATE user_id = user_id;
