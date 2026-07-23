-- Performance Indexes Migration
-- Adds missing composite and single-column indexes based on actual query patterns
-- Safe to run multiple times (IF NOT EXISTS)

-- ============================================================
-- TRADES TABLE
-- ============================================================
-- settlement.php: WHERE settlement_status = ? (filtering by settlement status)
ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_settlement_status (settlement_status);

-- settlement.php: WHERE id IN (...) for bulk operations
-- Already has PRIMARY KEY on id, so no index needed for IN lookups

-- settlement.php: Bulk payment - trades by status + settlement_status
ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_status_settlement (status, settlement_status);

-- trader/dashboard.php: ORDER BY created_at DESC LIMIT (recent trades)
ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_trade_date_id (trade_date, id);

-- view_trade.php: WHERE client_cds_account = ? AND asset_class = ? AND status = 'active'
ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_cds_asset_status (client_cds_account, asset_class, status);

-- view_trade.php: WHERE client_cds_account = ? AND status = 'active' (distinct security_id)
ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_cds_status (client_cds_account, status);

-- CEO dashboard: trades aggregation queries
ALTER TABLE trades ADD INDEX IF NOT EXISTS idx_status_consideration (status, consideration);

-- ============================================================
-- PAYMENTS TABLE
-- ============================================================
-- settlement.php: WHERE source_type = 'trade_settlement' AND source_id IN (...)
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_source_type_id (source_type, source_id);

-- finance/dashboard.php: WHERE status = 'active' AND record_in_financial = 'no'
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_status_record (status, record_in_financial);

-- ============================================================
-- RECEIPTS TABLE
-- ============================================================
-- finance/dashboard.php: WHERE status = 'active' AND record_in_financial = 'no'
ALTER TABLE receipts ADD INDEX IF NOT EXISTS idx_status_record (status, record_in_financial);

-- ============================================================
-- PENDING_PAY TABLE
-- ============================================================
-- CEO dashboard: WHERE status = 'pending' AND ceo_approved_at IS NULL
ALTER TABLE pending_pay ADD INDEX IF NOT EXISTS idx_status_ceo_approved (status, ceo_approved_at);

-- Finance dashboard: WHERE status = 'approved_ceo' AND finance_approved_at IS NULL
ALTER TABLE pending_pay ADD INDEX IF NOT EXISTS idx_status_finance_approved (status, finance_approved_at);

-- CEO dashboard: WHERE subject LIKE 'Salary Payment%' (recent salary payments)
ALTER TABLE pending_pay ADD INDEX IF NOT EXISTS idx_subject (subject);

-- ============================================================
-- USERS TABLE
-- ============================================================
-- HR dashboard: WHERE status = 'active' AND role IN (...)
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_status_role (status, role);

-- HR dashboard: WHERE MONTH(hire_date) = MONTH(CURDATE()) (birthday/hire anniversary)
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_hire_date (hire_date);

-- ============================================================
-- CHART OF ACCOUNTS TABLE
-- ============================================================
-- Finance dashboard: WHERE account_type IN (...) AND is_active = 1
ALTER TABLE chart_of_accounts ADD INDEX IF NOT EXISTS idx_type_active (account_type, is_active);

-- Finance dashboard: WHERE is_active = 1 (general count)
-- Already covered by idx_type_active composite index

-- ============================================================
-- GENERAL LEDGER TABLE
-- ============================================================
-- Finance dashboard: JOIN with chart_of_accounts, SUM aggregates
-- Already has idx_account_id and idx_transaction_date
-- Add composite for income/expense aggregation
ALTER TABLE general_ledger ADD INDEX IF NOT EXISTS idx_status_account (status, account_id);

-- ============================================================
-- LEAVE REQUESTS TABLE
-- ============================================================
-- HR dashboard: WHERE status = 'pending'
-- Already has idx_status

-- ============================================================
-- PERFORMANCE TARGETS TABLE
-- ============================================================
-- HR dashboard: WHERE status = 'active' AND end_date < CURDATE()
ALTER TABLE performance_targets ADD INDEX IF NOT EXISTS idx_status_end_date (status, end_date);

-- ============================================================
-- BONDS TABLE
-- ============================================================
-- Already has idx_security_id

-- ============================================================
-- EQUITIES TABLE
-- ============================================================
-- Already has idx_security_id
