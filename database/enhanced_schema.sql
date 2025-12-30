-- Enhanced Stock Exchange Schema for International Use
-- Supports auto-creation of clients, bonds, brokers, and custodians

-- Drop existing tables for clean update (in reverse dependency order)
DROP TABLE IF EXISTS trade_receipts;
DROP TABLE IF EXISTS trade_invoices;
DROP TABLE IF EXISTS trades;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS equities;
DROP TABLE IF EXISTS bonds;
DROP TABLE IF EXISTS brokers;
DROP TABLE IF EXISTS custodians;

-- Create custodians table first since clients references it
-- Custodians table
CREATE TABLE custodians (
    id INT PRIMARY KEY AUTO_INCREMENT,
    custodian_code VARCHAR(20) UNIQUE NOT NULL,
    custodian_name VARCHAR(200) NOT NULL,
    license_number VARCHAR(50),
    address TEXT,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_custodian_code (custodian_code)
);

-- Brokers table
CREATE TABLE brokers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    broker_code VARCHAR(20) UNIQUE NOT NULL,
    broker_name VARCHAR(200) NOT NULL,
    license_number VARCHAR(50),
    address TEXT,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_broker_code (broker_code)
);

-- Now create clients table after custodians exists
-- Clients table (identified by CDS account number)
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cds_account VARCHAR(50) UNIQUE NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    client_type ENUM('individual', 'corporate', 'institutional') DEFAULT 'individual',
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    custodian_id INT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (custodian_id) REFERENCES custodians(id),
    INDEX idx_cds_account (cds_account)
);

-- Enhanced Bonds table with auto-creation support
CREATE TABLE bonds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    security_id VARCHAR(50) UNIQUE NOT NULL, -- ATS format: 675-15-T16-A1
    bond_name VARCHAR(200) NOT NULL,
    issuer VARCHAR(200) NOT NULL,
    coupon_rate DECIMAL(8,4) NOT NULL,
    face_value DECIMAL(15,2) NOT NULL,
    issue_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    status ENUM('active', 'matured', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_id (security_id)
);

-- Enhanced Equities table
CREATE TABLE equities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    security_id VARCHAR(50) UNIQUE NOT NULL, -- Stock symbol
    stock_name VARCHAR(200) NOT NULL,
    company_name VARCHAR(200) NOT NULL,
    isin_code VARCHAR(20),
    sector VARCHAR(100),
    currency VARCHAR(3) DEFAULT 'USD',
    par_value DECIMAL(10,4),
    listing_date DATE,
    status ENUM('active', 'suspended', 'delisted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_id (security_id)
);

-- Enhanced Trades table with all required fields
CREATE TABLE trades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trade_reference VARCHAR(50) UNIQUE NOT NULL,
    asset_class ENUM('bond', 'equity') NOT NULL,
    security_id VARCHAR(50) NOT NULL,
    security_name VARCHAR(200) NOT NULL,
    
    -- Client information
    client_cds_account VARCHAR(50) NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    capacity ENUM('principal', 'agency') NOT NULL,
    
    -- Broker information
    broker_name VARCHAR(200) NOT NULL,
    counterparty_broker VARCHAR(200) NOT NULL,
    counterparty_name VARCHAR(200) NOT NULL,
    counterparty_cds_account VARCHAR(50),
    
    -- Trade details
    trade_side ENUM('buy', 'sell') NOT NULL,
    quantity BIGINT NOT NULL,
    price DECIMAL(15,6) NOT NULL,
    rate DECIMAL(15,6) NOT NULL,
    consideration DECIMAL(20,2) NOT NULL,
    
    -- Dates
    trade_date DATE NOT NULL,
    settlement_date DATE NOT NULL,
    maturity_date DATE,
    
    -- Additional fields
    exchange_reference VARCHAR(50),
    origin VARCHAR(50),
    time_executed TIME,
    currency VARCHAR(3) DEFAULT 'USD',
    
    -- Status and audit
    status ENUM('active', 'cancelled', 'settled') DEFAULT 'active',
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_trade_date (trade_date),
    INDEX idx_client_cds (client_cds_account),
    INDEX idx_security (security_id),
    INDEX idx_status (status)
);

-- Enhanced receipts and invoices with auto-generation
CREATE TABLE trade_receipts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    trade_id INT NOT NULL,
    client_cds_account VARCHAR(50) NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    security_name VARCHAR(200) NOT NULL,
    quantity BIGINT NOT NULL,
    unit_price DECIMAL(15,6) NOT NULL,
    gross_amount DECIMAL(20,2) NOT NULL,
    fees DECIMAL(10,2) DEFAULT 0,
    taxes DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(20,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    receipt_date DATE NOT NULL,
    auto_generated BOOLEAN DEFAULT TRUE,
    generated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trade_id) REFERENCES trades(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

CREATE TABLE trade_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    trade_id INT NOT NULL,
    client_cds_account VARCHAR(50) NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    security_name VARCHAR(200) NOT NULL,
    quantity BIGINT NOT NULL,
    unit_price DECIMAL(15,6) NOT NULL,
    gross_amount DECIMAL(20,2) NOT NULL,
    fees DECIMAL(10,2) DEFAULT 0,
    taxes DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(20,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    invoice_date DATE NOT NULL,
    auto_generated BOOLEAN DEFAULT TRUE,
    generated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trade_id) REFERENCES trades(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- Insert default brokers and custodians
INSERT INTO brokers (broker_code, broker_name, contact_person) VALUES
('MAIN', 'Main Broker (System Default)', 'System Administrator'),
('VICT', 'Victoria Securities', 'Trading Desk'),
('ZANS', 'ZAN Securities Limited', 'Operations Manager');

INSERT INTO custodians (custodian_code, custodian_name, contact_person) VALUES
('CENTRAL', 'Central Securities Depository', 'CSD Operations'),
('CUSTODY1', 'Primary Custodian Services', 'Custody Manager');
