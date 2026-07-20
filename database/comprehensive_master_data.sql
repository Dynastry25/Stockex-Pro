-- Comprehensive Master Data for Stock Exchange System
-- This file contains all master data tables and default data from the screenshots

-- Payment Frequencies
CREATE TABLE IF NOT EXISTS payment_frequencies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    compounding_periods_per_year INT NOT NULL DEFAULT 1,
    cash_flow DECIMAL(15,2) DEFAULT 0.00,
    nominal_interest_rate DECIMAL(8,4) DEFAULT 0.00,
    discounting_factor DECIMAL(8,4) DEFAULT 0.00,
    effective_annual_rate DECIMAL(8,4) DEFAULT 0.00,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bonds Economic Sectors
CREATE TABLE IF NOT EXISTS bonds_economic_sectors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Share Types
CREATE TABLE IF NOT EXISTS share_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Share Market Trends
CREATE TABLE IF NOT EXISTS share_market_trends (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- GL Account Types
CREATE TABLE IF NOT EXISTS gl_account_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    allocation_from INT NOT NULL,
    allocation_upto INT NOT NULL,
    closing_entry_type VARCHAR(50) NOT NULL,
    socf_effects VARCHAR(50) DEFAULT 'SOCF-EFFECTS',
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- GL Account Formats
CREATE TABLE IF NOT EXISTS gl_account_formats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Balance Sheet Reporting Formats
CREATE TABLE IF NOT EXISTS balance_sheet_reporting_formats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bond Types
CREATE TABLE IF NOT EXISTS bond_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    ytm_spread DECIMAL(8,4) DEFAULT 0.0000,
    ex_coupon_days INT DEFAULT 1,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bond Issuers
CREATE TABLE IF NOT EXISTS bond_issuers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Coupon Determiners
CREATE TABLE IF NOT EXISTS coupon_determiners (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL UNIQUE,
    description VARCHAR(100) NOT NULL,
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Equities Settings (Enhanced)
CREATE TABLE IF NOT EXISTS equities_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) NOT NULL UNIQUE,
    description VARCHAR(200) NOT NULL,
    isin VARCHAR(50),
    costing_basis VARCHAR(20) DEFAULT 'WAUC',
    market_price DECIMAL(15,2) DEFAULT 0.00,
    valuation_price DECIMAL(15,2) DEFAULT 0.00,
    share_type VARCHAR(10) DEFAULT '0',
    market_segment VARCHAR(10) DEFAULT 'MIM',
    economic_sector VARCHAR(10) DEFAULT 'F',
    market_trend VARCHAR(10) DEFAULT '3',
    priority VARCHAR(20) DEFAULT 'P0001',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default data for Payment Frequencies
INSERT IGNORE INTO payment_frequencies (code, description, compounding_periods_per_year, cash_flow, nominal_interest_rate, discounting_factor, effective_annual_rate, priority) VALUES
('A', 'ANNUALLY', 1, 0.00, 0.00, 0.8264, 10.0000, 'P0001'),
('H', 'BIANNUALLY', 2, 0.00, 0.00, 0.8227, 10.2500, 'P0002'),
('Q', 'QUARTERLY', 4, 0.00, 0.00, 0.8207, 10.3813, 'P0003'),
('M', 'MONTHLY', 12, 0.00, 0.00, 0.8194, 10.4713, 'P0004'),
('T', 'BIMONTHLY', 24, 0.00, 0.00, 0.8191, 10.4941, 'P0005'),
('W', 'WEEKLY', 52, 0.00, 0.00, 0.8189, 10.5065, 'P0006'),
('K', 'BIWEEKLY', 26, 0.00, 0.00, 0.8190, 10.4959, 'P0007'),
('D', 'DAILY', 365, 0.00, 0.00, 0.8188, 10.5156, 'P0008');

-- Insert default data for Bonds Economic Sectors
INSERT IGNORE INTO bonds_economic_sectors (code, description, priority) VALUES
('C', 'CORPORATES & COMPANIES', 'P0001'),
('F', 'INSTITUITIONS(FOREIGN)', 'P0002'),
('G', 'GOVERMENTS & PARASTATALS', 'P0003'),
('I', 'INSTITUITIONS(LOCAL)', 'P0004'),
('L', 'LOCAL AUTHORITIES & MUNICIPALITIES', 'P0005');

-- Insert default data for Share Types
INSERT IGNORE INTO share_types (code, description, priority) VALUES
('O', 'ORDINARY', 'P0001'),
('P', 'PREFERENCE', 'P0002'),
('U', 'UNQUOTED', 'P0003');

-- Insert default data for Share Market Trends
INSERT IGNORE INTO share_market_trends (code, description, priority) VALUES
('1', 'SPECULATION', 'P0001'),
('2', 'SHORT TERM', 'P0002'),
('3', 'LONG TERM', 'P0003'),
('4', 'PENDING', 'P0004'),
('5', 'EXIT', 'P0005');

-- Insert default data for GL Account Types
INSERT IGNORE INTO gl_account_types (code, description, allocation_from, allocation_upto, closing_entry_type, priority) VALUES
('I', 'INCOME', 10000, 19999, 'TEMPORARY ACCOUNT', 'P0001'),
('E', 'EXPENSE', 30000, 39999, 'TEMPORARY ACCOUNT', 'P0002'),
('A', 'ASSET', 70000, 79999, 'PERMANENT ACCOUNT', 'P0003'),
('L', 'LIABILITY', 70000, 79999, 'PERMANENT ACCOUNT', 'P0004');

-- Insert default data for GL Account Formats
INSERT IGNORE INTO gl_account_formats (code, description, priority) VALUES
('HD', 'HEADER', 'P0001'),
('VP', 'VALID POSTING', 'P0002'),
('ST', 'SUB TOTAL', 'P0003'),
('TT', 'SUB SUB TOTAL', 'P0004'),
('GT', 'GRAND TOTAL', 'P0005');

-- Insert default data for Balance Sheet Reporting Formats
INSERT IGNORE INTO balance_sheet_reporting_formats (code, description, priority) VALUES
('FA', 'FIXED ASSETS', 'P0001'),
('CA', 'CURRENT ASSETS', 'P0002'),
('CL', 'CURRENT LIABILITIES', 'P0003'),
('SF', 'SHAREHOLDERS FUNDS', 'P0004');

-- Insert default data for Bond Types
INSERT IGNORE INTO bond_types (code, description, ytm_spread, ex_coupon_days, priority) VALUES
('COB', 'CORPORATE BOND', 0.0100, 15, 'P0001'),
('FXD', 'FIXED RATE TREASURY BOND', 0.0000, 1, 'P0002'),
('MTN', 'MEDIUM TERM NOTE', 0.0100, 15, 'P0003'),
('MUB', 'MUNICIPAL BOND', 0.0100, 15, 'P0004'),
('ZCO', 'ZERO COUPON BOND', 0.0100, 15, 'P0005');

-- Insert default data for Bond Issuers
INSERT IGNORE INTO bond_issuers (code, description, priority) VALUES
('BNR', 'NATIONAL BANK OF RWANDA', 'P0001'),
('BOT', 'BANK OF TANZANIA', 'P0002'),
('CBK', 'CENTRAL BANK OF KENYA', 'P0003'),
('BOU', 'BANK OF UGANDA', 'P0004'),
('NMB', 'NMB BANK PLC', 'P0005'),
('NBC', 'NATIONALBANK OF COMMERCE', 'P0006'),
('CRDB', 'CRDB BANK PLC', 'P0007'),
('AZAN', 'AZANIA BANK PLC', 'P0008');

-- Insert default data for Coupon Determiners
INSERT IGNORE INTO coupon_determiners (code, description, priority) VALUES
('FIXED', 'FIXED', 'P0001'),
('FLOATING', 'FLOATING', 'P0002'),
('ZERO', 'ZERO', 'P0003');

-- Insert default data for Equities Settings
INSERT IGNORE INTO equities_settings (code, description, isin, market_price, valuation_price, priority) VALUES
('ACA', 'ACACIA MINING PLC', 'GB00B61D2N63', 4850.00, 4850.00, 'P0001'),
('CRDB', 'CRDB BANK PUBLIC LIMITED COMPANY', 'TZ1996100305', 480.00, 480.00, 'P0002'),
('DSE', 'DAR ES SALAAM STOCK EXCHANGE PLC', 'TZ1996102434', 1140.00, 1140.00, 'P0003'),
('DCB', 'DCB COMMERCIAL BANK PLC', 'TZ1996100214', 380.00, 380.00, 'P0004'),
('EABL', 'EAST AFRICAN BREWERIES LIMITED', 'KE0000000216', 5150.00, 5150.00, 'P0005'),
('JATU', 'JATU PUBLIC LIMITED COMPANY', 'TZ1996103804', 250.00, 250.00, 'P0006'),
('JHL', 'JUBILEE HOLDINGS LIMITED', 'KE0000000273', 10100.00, 10100.00, 'P0007'),
('KA', 'KENYA AIRWAYS LIMITED', 'KE0000000307', 110.00, 110.00, 'P0008'),
('KCB', 'KENYA COMMERCIAL BANK LIMITED', 'KE0000000315', 950.00, 950.00, 'P0009'),
('MBP', 'MAENDELEO BANK PUBLIC LIMITED COMPANY', 'TZ1996101683', 600.00, 600.00, 'P0010'),
('MKCB', 'MKOMBOZI COMMERCIAL BANK PLC', 'TZ1996101972', 890.00, 890.00, 'P0011'),
('MUCOBA', 'MUCOBA BANK PLC', 'TZ1996102419', 400.00, 400.00, 'P0012'),
('MCB', 'MWALIMU COMMERCIAL BANK PLC', 'TZ1996102129', 500.00, 500.00, 'P0013'),
('NMG', 'NATION MEDIA GROUP LIMITED', 'KE0000000380', 2520.00, 2520.00, 'P0014'),
('NICO', 'NATIONAL INVESTMENT COMPANY LIMITED', 'TZ1996103077', 510.00, 510.00, 'P0015'),
('NMB', 'NATIONAL MICROFINANCE BANK PLC', 'TZ1996100222', 2750.00, 2750.00, 'P0016'),
('PAL', 'PRECISION AIR SERVICES PLC', 'TZ1996101048', 470.00, 470.00, 'P0017'),
('SWALA', 'SWALA OIL AND GAS (TANZANIA) PLC', 'TZ1996101865', 500.00, 500.00, 'P0018'),
('SWIS', 'SWISSPORT TANZANIA PLC', 'TZ1996100040', 3500.00, 3500.00, 'P0019'),
('TOCL', 'TANGA CEMENT COMPANY LIMITED', 'TZ1996100057', 1200.00, 1200.00, 'P0020'),
('TBL', 'TANZANIA BREWERIES LIMITED', 'TZ1996100016', 13200.00, 13200.00, 'P0021'),
('TCC', 'TANZANIA CIGARETTE COMPANY LIMITED', 'TZ1996100032', 16800.00, 16800.00, 'P0022'),
('TPCC', 'TANZANIA PORTLAND CEMENT COMPANY LIMITED', 'TZ1996100024', 1460.00, 1460.00, 'P0023'),
('TIP', 'TATEPA LIMITED', 'TZ1996100065', 600.00, 600.00, 'P0024'),
('TCIL', 'TCCIA INVESTMENT PLC', 'TZ1996105010', 170.00, 170.00, 'P0025'),
('TOL', 'TOL GASES LIMITED', 'TZ1996100008', 780.00, 780.00, 'P0026'),
('USL', 'UCHUMI SUPERMARKET LIMITED', 'KE0000000489', 30.00, 30.00, 'P0027'),
('UIT', 'UNIT TRUST OF TANZANIA', '', 0.00, 0.00, 'P0028'),
('VODA', 'VODACOM TANZANIA PUBLIC LIMITED COMPANY', 'TZ1996102715', 850.00, 850.00, 'P0029'),
('YETU', 'YETU MICROFINANCE PUBLIC LIMITED COMPANY', 'TZ1996102344', 600.00, 600.00, 'P0030');

-- Update existing master data tables with additional default data

-- Enhanced Brokers data
INSERT IGNORE INTO brokers (broker_code, broker_name, license_number, contact_person, phone, email, address) VALUES
('B01', 'ARCH FINANCIAL INVESTMENT ADVISORY', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B02', 'COMMERCIAL BANK OF AFRICA', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B03', 'CORE SECURITIES LIMITED', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B04', 'CRDB BANK PLC', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B05', 'ORBIT SECURITIES COMPANY LIMITED', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B06', 'VERTEX INTERNATIONAL SECURITIES LIMITED', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B07', 'CORE SECURITIES LIMITED', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B13', 'VICTORY FINANCIAL SERVICES LTD', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B15', 'EXODUS ADVISORY SERVICES LIMITED', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B16', 'GLOBAL ALPHA CAPITAL LTD', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('B18', 'ITRUST FINANCE LIMITED', 'TBA', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam');

-- Enhanced Custodians data
INSERT IGNORE INTO custodians (custodian_code, custodian_name, license_number, contact_person, phone, email, address) VALUES
('CB01', 'STANCHART BANK LTD', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('CB02', 'CRDB BANK LTD', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('CB03', 'STANBIC BANK LTD', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('CB04', 'NATIONAL MICROFINACE BANK', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('CB05', 'NMB BANK LTD', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('CB06', 'ABSA BANK LTD', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam'),
('CB07', 'IM BANK LTD', 'LOCAL COMPANY', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 'Dar es Salaam');

-- Create indexes for better performance
CREATE INDEX idx_payment_frequencies_code ON payment_frequencies(code);
CREATE INDEX idx_bonds_economic_sectors_code ON bonds_economic_sectors(code);
CREATE INDEX idx_share_types_code ON share_types(code);
CREATE INDEX idx_share_market_trends_code ON share_market_trends(code);
CREATE INDEX idx_gl_account_types_code ON gl_account_types(code);
CREATE INDEX idx_gl_account_formats_code ON gl_account_formats(code);
CREATE INDEX idx_balance_sheet_reporting_formats_code ON balance_sheet_reporting_formats(code);
CREATE INDEX idx_bond_types_code ON bond_types(code);
CREATE INDEX idx_bond_issuers_code ON bond_issuers(code);
CREATE INDEX idx_coupon_determiners_code ON coupon_determiners(code);
CREATE INDEX idx_equities_settings_code ON equities_settings(code);
CREATE INDEX idx_equities_settings_isin ON equities_settings(isin);
