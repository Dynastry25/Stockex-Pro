-- Fee Configuration Schema
-- Stores all configurable fee rates for the stock exchange system

CREATE TABLE fee_configurations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fee_type VARCHAR(50) NOT NULL,
    fee_name VARCHAR(100) NOT NULL,
    rate_percentage DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    fixed_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    applies_to ENUM('ALL', 'EQUITY', 'BOND', 'TREASURY_BILL', 'CORPORATE_BOND') DEFAULT 'ALL',
    calculation_base ENUM('CONSIDERATION', 'COMMISSION') DEFAULT 'CONSIDERATION',
    is_active BOOLEAN DEFAULT TRUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fee_type (fee_type),
    INDEX idx_applies_to (applies_to),
    UNIQUE KEY unique_fee_asset (fee_type, applies_to)
);

-- Insert default fee configurations
INSERT INTO fee_configurations (fee_type, fee_name, rate_percentage, applies_to, calculation_base, description) VALUES
('BROKERAGE', 'Brokerage Commission - General', 1.7000, 'ALL', 'CONSIDERATION', 'General brokerage commission rate'),
('BROKERAGE', 'Brokerage Commission - Equities', 2.0600, 'EQUITY', 'CONSIDERATION', 'Brokerage commission rate for equities'),
('BROKERAGE', 'Brokerage Commission - Treasury Bills', 0.3000, 'TREASURY_BILL', 'CONSIDERATION', 'Brokerage commission rate for treasury bills'),
('BROKERAGE', 'Brokerage Commission - Treasury Bonds', 0.2000, 'BOND', 'CONSIDERATION', 'Brokerage commission rate for treasury bonds'),
('BROKERAGE', 'Brokerage Commission - Corporate Bonds', 0.0300, 'CORPORATE_BOND', 'CONSIDERATION', 'Brokerage commission rate for corporate bonds'),
('VAT', 'VAT on Brokerage', 18.0000, 'ALL', 'COMMISSION', 'Value Added Tax on brokerage commission'),
('CMSA', 'CMSA Transaction Fee', 0.1400, 'ALL', 'CONSIDERATION', 'Capital Markets and Securities Authority transaction fee'),
('DSE', 'DSE Transaction Fee', 0.1652, 'ALL', 'CONSIDERATION', 'Dar es Salaam Stock Exchange transaction fee (VAT inclusive)'),
('FIDELITY', 'Fidelity Fee', 0.0200, 'ALL', 'CONSIDERATION', 'Fidelity insurance fee'),
('CDS', 'CDS Transaction Fee', 0.0708, 'ALL', 'CONSIDERATION', 'Central Depository System transaction fee (VAT inclusive)');

-- Create audit table for fee changes
CREATE TABLE fee_configuration_audit (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fee_configuration_id INT NOT NULL,
    old_rate_percentage DECIMAL(8,4),
    new_rate_percentage DECIMAL(8,4),
    old_fixed_amount DECIMAL(10,2),
    new_fixed_amount DECIMAL(10,2),
    changed_by INT NOT NULL,
    change_reason TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fee_configuration_id) REFERENCES fee_configurations(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
);
