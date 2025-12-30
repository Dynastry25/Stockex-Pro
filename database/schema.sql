-- Stock Exchange Data Storage System Database Schema
-- Created for comprehensive trade management and reporting

-- Drop existing tables if they exist (for clean setup)
DROP TABLE IF EXISTS trade_receipts;
DROP TABLE IF EXISTS trade_invoices;
DROP TABLE IF EXISTS expenses_benefits;
DROP TABLE IF EXISTS trades;
DROP TABLE IF EXISTS equities;
DROP TABLE IF EXISTS bonds;
DROP TABLE IF EXISTS bond_auctions;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;

-- Users table for authentication and role management
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('system_admin', 'trader', 'ceo', 'finance_officer') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    mandate_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Companies table
CREATE TABLE companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_code VARCHAR(20) UNIQUE NOT NULL,
    company_name VARCHAR(200) NOT NULL,
    registration_number VARCHAR(50),
    address TEXT,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bond auctions table
CREATE TABLE bond_auctions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    auction_number VARCHAR(10) NOT NULL,
    auction_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    coupon_rate DECIMAL(5,2) NOT NULL,
    face_value DECIMAL(15,2) NOT NULL,
    total_amount DECIMAL(20,2) NOT NULL,
    status ENUM('planned', 'active', 'completed', 'cancelled') DEFAULT 'planned',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Bonds table with ATS code format (675-15-T16-A1)
CREATE TABLE bonds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ats_code VARCHAR(20) UNIQUE NOT NULL, -- Format: bond_number-coupon_rate-term-auction_number
    bond_number VARCHAR(10) NOT NULL,
    coupon_rate DECIMAL(5,2) NOT NULL,
    term_years INT NOT NULL,
    auction_id INT NOT NULL,
    face_value DECIMAL(15,2) NOT NULL,
    issue_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    status ENUM('active', 'matured', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES bond_auctions(id),
    INDEX idx_ats_code (ats_code)
);

-- Equities/Stocks table (based on Excel structure)
CREATE TABLE equities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    stock_symbol VARCHAR(10) NOT NULL,
    stock_name VARCHAR(200) NOT NULL,
    isin_code VARCHAR(20),
    sector VARCHAR(100),
    market_cap DECIMAL(20,2),
    shares_outstanding BIGINT,
    par_value DECIMAL(10,2),
    listing_date DATE,
    current_price DECIMAL(10,2),
    previous_close DECIMAL(10,2),
    day_high DECIMAL(10,2),
    day_low DECIMAL(10,2),
    volume BIGINT DEFAULT 0,
    status ENUM('active', 'suspended', 'delisted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY unique_symbol (stock_symbol),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_company (company_id)
);

-- Trades table for matched trades
CREATE TABLE trades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trade_reference VARCHAR(50) UNIQUE NOT NULL,
    trade_type ENUM('bond', 'equity') NOT NULL,
    instrument_id INT NOT NULL, -- References bonds.id or equities.id
    trade_side ENUM('buy', 'sell') NOT NULL,
    quantity BIGINT NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    total_value DECIMAL(20,2) NOT NULL,
    buyer_name VARCHAR(200) NOT NULL,
    seller_name VARCHAR(200) NOT NULL,
    buyer_account VARCHAR(50),
    seller_account VARCHAR(50),
    trade_date DATE NOT NULL,
    settlement_date DATE NOT NULL,
    status ENUM('active', 'cancelled', 'settled') DEFAULT 'active',
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_trade_date (trade_date),
    INDEX idx_status (status),
    INDEX idx_instrument (trade_type, instrument_id)
);

-- Trade receipts for buyers
CREATE TABLE trade_receipts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    trade_id INT NOT NULL,
    buyer_name VARCHAR(200) NOT NULL,
    buyer_account VARCHAR(50),
    instrument_name VARCHAR(200) NOT NULL,
    quantity BIGINT NOT NULL,
    unit_price DECIMAL(15,4) NOT NULL,
    total_amount DECIMAL(20,2) NOT NULL,
    fees DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(20,2) NOT NULL,
    receipt_date DATE NOT NULL,
    generated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trade_id) REFERENCES trades(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- Trade invoices for sellers
CREATE TABLE trade_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    trade_id INT NOT NULL,
    seller_name VARCHAR(200) NOT NULL,
    seller_account VARCHAR(50),
    instrument_name VARCHAR(200) NOT NULL,
    quantity BIGINT NOT NULL,
    unit_price DECIMAL(15,4) NOT NULL,
    gross_amount DECIMAL(20,2) NOT NULL,
    fees DECIMAL(10,2) DEFAULT 0,
    taxes DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(20,2) NOT NULL,
    invoice_date DATE NOT NULL,
    generated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trade_id) REFERENCES trades(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- Expenses and benefits tracking
CREATE TABLE expenses_benefits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reference_number VARCHAR(50) UNIQUE NOT NULL,
    type ENUM('expense', 'benefit') NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    transaction_date DATE NOT NULL,
    bank_reference VARCHAR(100),
    supporting_document VARCHAR(255),
    recorded_by INT NOT NULL,
    approved_by INT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_type (type),
    INDEX idx_date (transaction_date)
);

-- System settings
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- Insert default system admin user (password: admin123)
INSERT INTO users (username, email, password_hash, role, full_name, mandate_enabled) VALUES
('admin', 'admin@stockexchange.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'system_admin', 'System Administrator', TRUE);

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('system_name', 'Stock Exchange Data Storage System', 'Name of the system'),
('company_name', 'National Stock Exchange', 'Company operating the exchange'),
('receipt_prefix', 'RCP', 'Prefix for receipt numbers'),
('invoice_prefix', 'INV', 'Prefix for invoice numbers'),
('trade_prefix', 'TRD', 'Prefix for trade reference numbers');

-- Create indexes for better performance
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_active ON users(is_active);
CREATE INDEX idx_bonds_status ON bonds(status);
CREATE INDEX idx_equities_status ON equities(status);
CREATE INDEX idx_trades_date_status ON trades(trade_date, status);
