-- Master Data Schema for Stock Exchange System
-- Contains all configuration and reference data tables

-- Sub Ledger Groups
CREATE TABLE sub_ledger_groups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    classification VARCHAR(100) NOT NULL,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_priority (priority)
);

-- Sub Ledger Categories
CREATE TABLE sub_ledger_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    classification ENUM('LOCAL', 'FOREIGN') NOT NULL,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_classification (classification)
);

-- Sub Ledger Related Parties
CREATE TABLE sub_ledger_related_parties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Sub Ledger Status
CREATE TABLE sub_ledger_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    inactivity_days_from INT DEFAULT 0,
    inactivity_days_upto INT DEFAULT 0,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Titles
CREATE TABLE titles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Identity Types
CREATE TABLE identity_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Investment Asset Classes
CREATE TABLE investment_asset_classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    min_quantity BIGINT DEFAULT 1,
    lot_size BIGINT DEFAULT 1,
    commission_rate DECIMAL(8,4) DEFAULT 0.00,
    min_commission DECIMAL(10,2) DEFAULT 0.00,
    return_commission DECIMAL(10,2) DEFAULT 0.00,
    return_commission_days INT DEFAULT 0,
    costing_method ENUM('FIFO', 'WAUC', 'LIFO') DEFAULT 'FIFO',
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Transaction Types
CREATE TABLE transaction_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    is_document_numbering_auto BOOLEAN DEFAULT FALSE,
    is_post_dated BOOLEAN DEFAULT FALSE,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Payment Methods
CREATE TABLE payment_methods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    cashbook VARCHAR(100) NOT NULL,
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Ledger Types
CREATE TABLE ledger_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    gl_account VARCHAR(100) NOT NULL,
    is_account_no_auto BOOLEAN DEFAULT FALSE,
    is_file_no_auto BOOLEAN DEFAULT FALSE,
    is_csdn_required BOOLEAN DEFAULT FALSE,
    is_bank_required BOOLEAN DEFAULT FALSE,
    is_joint_holder_required BOOLEAN DEFAULT FALSE,
    is_cashbook_disabled BOOLEAN DEFAULT FALSE,
    receipt_narrative VARCHAR(200),
    payment_narrative VARCHAR(200),
    petty_cash_narrative VARCHAR(200),
    priority VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Enhanced Companies table
CREATE TABLE companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    branches VARCHAR(100),
    division VARCHAR(100),
    business_type VARCHAR(100),
    country VARCHAR(100),
    nationality VARCHAR(100),
    currency VARCHAR(10),
    language VARCHAR(50),
    exchange VARCHAR(50),
    direct_code VARCHAR(50),
    mobile VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    logo_path VARCHAR(255),
    header_image_path VARCHAR(255),
    footer_image_path VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- Insert default data for Sub Ledger Groups
INSERT INTO sub_ledger_groups (code, description, classification, priority) VALUES
('COMPANY', 'CORPORATE', 'CORPORATE', 'P0001'),
('HNWI', 'HIGH NET WORTH INDIVIDUAL', 'INDIVIDUAL', 'P0002'),
('II', 'INSTITUTIONAL INVESTOR', 'INSTITUTIONAL', 'P0003'),
('RETAIL', 'INDIVIDUAL', 'INDIVIDUAL', 'P0004'),
('SME', 'SMALL & MEDIUM ENTERPRISE', 'CORPORATE', 'P0005'),
('VHNWI', 'VERY HIGH NET WORTH INDIVIDUAL', 'INDIVIDUAL', 'P0006');

-- Insert default data for Sub Ledger Categories
INSERT INTO sub_ledger_categories (code, description, classification, priority) VALUES
('EC', 'EAST AFRICA COMPANY', 'LOCAL', 'P0001'),
('EI', 'EAST AFRICA INDIVIDUAL', 'LOCAL', 'P0002'),
('LC', 'LOCAL COMPANY', 'LOCAL', 'P0003'),
('LI', 'LOCAL INDIVIDUAL', 'LOCAL', 'P0004'),
('CE', 'COMPANY EMPLOYEE', 'LOCAL', 'P0005'),
('FC', 'FOREIGN COMPANY', 'FOREIGN', 'P0006'),
('FI', 'FOREIGN INDIVIDUAL', 'FOREIGN', 'P0007');

-- Insert default data for Sub Ledger Related Parties
INSERT INTO sub_ledger_related_parties (code, description, priority) VALUES
('CLIENT', 'EXTERNAL CLIENT', 'P0001'),
('CUSTODIAN', 'CUSTODIAN', 'P0002'),
('DIRECTOR', 'DIRECTOR', 'P0003'),
('DIRECTOR COMPANY', 'DIRECTOR COMPANY', 'P0004'),
('FUND MANAGER', 'FUND MANAGER', 'P0005'),
('FUND ADMINISTRATOR', 'FUND ADMINISTRATOR', 'P0006'),
('SHAREHOLDER', 'SHAREHOLDER', 'P0007'),
('STAFF', 'EMPLOYEE', 'P0008');

-- Insert default data for Sub Ledger Status
INSERT INTO sub_ledger_status (code, description, inactivity_days_from, inactivity_days_upto, priority) VALUES
('P', 'PENDING', 0, 0, 'P0001'),
('A', 'ACTIVE', 0, 365, 'P0002'),
('I', 'INACTIVE', 366, 729, 'P0003'),
('D', 'DORMANT', 730, 999999, 'P0004'),
('F', 'FROZEN', 0, 0, 'P0005'),
('B', 'BLOCKED', 0, 0, 'P0006'),
('C', 'CLOSED', 0, 0, 'P0007');

-- Insert default data for Titles
INSERT INTO titles (code, description, priority) VALUES
('BRG', 'BRIGADIER', 'P0001'),
('CPT', 'CAPTAIN', 'P0002'),
('COL', 'COLONEL', 'P0003'),
('CO', 'COMPANY', 'P0004'),
('CPL', 'CORPORAL', 'P0005'),
('DR', 'DOCTOR', 'P0006'),
('ENG', 'ENGINEER', 'P0007'),
('GEN', 'GENERAL', 'P0008'),
('HON', 'HONOURABLE', 'P0009'),
('LT', 'LIEUTENANT', 'P0010'),
('MAJ', 'MAJOR', 'P0011'),
('MESS', 'MESSRS', 'P0012'),
('MINOR', 'MINOR', 'P0013'),
('MISS', 'MISS', 'P0014'),
('MR', 'MR', 'P0015'),
('MRS', 'MRS', 'P0016'),
('MS', 'MS', 'P0017'),
('PROF', 'PROFESSOR', 'P0018'),
('REV', 'REVEREND', 'P0019'),
('SGT', 'SERGEANT', 'P0020'),
('SR', 'SISTER', 'P0021');

-- Insert default data for Identity Types
INSERT INTO identity_types (code, description, priority) VALUES
('ID', 'NATIONAL IDENTITY CARD', 'P0001'),
('PP', 'PASSPORT', 'P0002'),
('CR', 'CERTIFICATE OF INCORPORATION/REGISTRATION', 'P0003'),
('DL', 'DRIVING LICENSE', 'P0004'),
('VI', 'VOTER IDENTITY CARD', 'P0005'),
('BC', 'BIRTH CERTIFICATE', 'P0006'),
('SD', 'STUDENT IDENTITY CARD', 'P0007'),
('WL', 'WARD LETTER', 'P0008');

-- Insert default data for Investment Asset Classes
INSERT INTO investment_asset_classes (code, description, min_quantity, lot_size, commission_rate, min_commission, return_commission, return_commission_days, costing_method, priority) VALUES
('CASH', 'CASH', 1, 1, 0.00, 0.00, 0.00, 0, 'FIFO', 'P0001'),
('EQUT', 'EQUITIES', 1, 1, 2.06, 100.00, 0.00, 3, 'WAUC', 'P0002'),
('TBIL', 'TREASURY BILLS', 500000, 10000, 0.30, 100.00, 50000.00, 1, 'FIFO', 'P0003'),
('TBON', 'TREASURY BONDS', 100000, 100, 0.20, 100.00, 50000.00, 1, 'FIFO', 'P0004'),
('FDEP', 'FIXED TERM DEPOSITS', 1, 1, 0.00, 0.00, 0.00, 0, 'FIFO', 'P0005'),
('CPAP', 'COMMERCIAL PAPER', 50000, 10000, 0.06, 100.00, 0.00, 3, 'WAUC', 'P0006'),
('CBON', 'CORPORATE BONDS', 100000, 100000, 0.03, 100.00, 50000.00, 1, 'FIFO', 'P0007'),
('MBON', 'MUNICIPAL BONDS', 100000, 100000, 0.03, 100.00, 50000.00, 1, 'FIFO', 'P0008'),
('REIT', 'REAL ESTATE INVESTMENT TRUSTS', 100000, 100000, 0.03, 100.00, 50000.00, 1, 'FIFO', 'P0009');

-- Insert default data for Transaction Types
INSERT INTO transaction_types (code, description, is_document_numbering_auto, is_post_dated, priority) VALUES
('CIN', 'SUPPLIERS INVOICES', FALSE, FALSE, 'P0001'),
('CDN', 'SUPPLIERS DEBIT NOTES', FALSE, FALSE, 'P0002'),
('DIN', 'CUSTOMERS INVOICES', TRUE, FALSE, 'P0003'),
('DCN', 'CUSTOMERS CREDIT NOTES', TRUE, FALSE, 'P0004'),
('QUT', 'QUOTATIONS', TRUE, FALSE, 'P0005'),
('POL', 'POLICIES', TRUE, FALSE, 'P0006'),
('DBN', 'DEBIT NOTES', TRUE, FALSE, 'P0007'),
('CRN', 'CREDIT NOTES', TRUE, FALSE, 'P0008'),
('JVC', 'JOURNAL VOUCHERS', TRUE, FALSE, 'P0009'),
('RCT', 'RECEIPTS', TRUE, FALSE, 'P0010'),
('PYT', 'PAYMENTS', TRUE, FALSE, 'P0011'),
('PET', 'PETTY CASH', TRUE, FALSE, 'P0012'),
('DIP', 'DIRECT PAYMENTS', TRUE, FALSE, 'P0013'),
('IPO', 'INITIAL PUBLIC OFFER', FALSE, FALSE, 'P0014'),
('BUY', 'BUY', FALSE, FALSE, 'P0015'),
('SEL', 'SELL', FALSE, FALSE, 'P0016'),
('SPL', 'SPLITS', FALSE, FALSE, 'P0017'),
('DIV', 'DIVIDENDS', FALSE, FALSE, 'P0018'),
('INT', 'INTEREST', FALSE, FALSE, 'P0019'),
('RGT', 'RIGHTS ISSUE', FALSE, FALSE, 'P0020'),
('BON', 'BONUS', FALSE, FALSE, 'P0023'),
('FAS', 'FIXED ASSET', TRUE, FALSE, 'P0026'),
('PJV', 'PAYROLL JOURNALS', TRUE, FALSE, 'P0027'),
('NON', 'NONE', FALSE, FALSE, 'P0028');

-- Insert default data for Payment Methods
INSERT INTO payment_methods (code, description, cashbook, priority) VALUES
('BC', 'BANKERS CHEQUE', 'CRDB BANK', 'P0001'),
('BO', 'BOT DIRECT TRANSFER', 'CRDB BANK', 'P0002'),
('CA', 'CASH', 'PETTY CASH', 'P0003'),
('CH', 'CHEQUE', 'CRDB BANK', 'P0004'),
('DB', 'DIRECT BANKING', 'CRDB BANK', 'P0005'),
('DD', 'DSE DIRECT DEBIT', 'CRDB BANK', 'P0006'),
('DE', 'DIRECT DEBIT', 'CRDB BANK', 'P0007'),
('DS', 'DSE DIRECT BANKING', 'CRDB BANK', 'P0008'),
('DT', 'DIRECT TRANSFER', 'CRDB BANK', 'P0009'),
('FO', 'FOREX', 'CRDB BANK', 'P0010'),
('MO', 'MONEY ORDER', 'CRDB BANK', 'P0011'),
('TT', 'TELEGRAPHIC TRANSFER', 'CRDB BANK', 'P0012'),
('WA', 'WARRANT', 'CRDB BANK', 'P0013');

-- Insert default data for Ledger Types
INSERT INTO ledger_types (code, description, gl_account, is_account_no_auto, is_file_no_auto, is_csdn_required, is_bank_required, is_joint_holder_required, is_cashbook_disabled, receipt_narrative, payment_narrative, petty_cash_narrative, priority) VALUES
('A', 'AGENT', '73111-AGENTS CONTROL A/C', TRUE, TRUE, FALSE, FALSE, FALSE, FALSE, 'RETURN COMM', 'RETURN COMM', 'RETURN COMM', 'P0001'),
('B', 'BROKER', '72714-BROKERS CONTROL A/C', FALSE, TRUE, FALSE, FALSE, FALSE, FALSE, 'SETTLEMENT OF', 'SETTLEMENT OF', 'SETTLEMENT OF', 'P0002'),
('C', 'SUPPLIER', '73101-SUPPLIERS CONTROL A/C', TRUE, TRUE, TRUE, TRUE, FALSE, FALSE, 'RECEIPT', 'PAYMENT', 'REIMBURSEMENT', 'P0003'),
('D', 'CUSTOMER', '72711-CUSTOMERS CONTROL A/C', TRUE, TRUE, FALSE, TRUE, FALSE, FALSE, 'RECEIPT', 'PAYMENT', 'REIMBURSEMENT', 'P0004'),
('O', 'NOMINAL', '', FALSE, TRUE, FALSE, FALSE, FALSE, FALSE, 'RECEIPT', 'PAYMENT', 'REIMBURSEMENT', 'P0006'),
('R', 'CLIENT', '73113-CLIENTS CONTROL A/C', TRUE, TRUE, TRUE, TRUE, TRUE, FALSE, 'RECEIPT ON ACCOUNT', 'PAYMENT ON ACCOUNT', 'SETTLEMENT OF', 'P0008'),
('U', 'CUSTODIAN', '72114-CUSTODIANS CONTROL A/C', FALSE, TRUE, FALSE, TRUE, FALSE, FALSE, 'SETTLEMENT OF', 'SETTLEMENT OF', 'SETTLEMENT OF', 'P0009');

-- Insert default company data
INSERT INTO companies (code, name, branches, division, business_type, country, nationality, currency, language, exchange, direct_code, mobile, email) VALUES
('B13', 'VICTORY FINANCIAL SERVICES LIMITED', 'HOD', 'B13', 'SB', 'TZ', 'TANZANIAN', 'TSH', 'ENGLISH', 'DSE', 'D00001', '+254708623200', 'bett@softbasetechnologies.com');
