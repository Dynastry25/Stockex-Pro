# Finance System Audit Report

## 1. Overview
This audit covers the financial system in `stockex`, focusing on client/investor money handling and integration with the General Ledger (GL). The system automates trade accounting, cash flow reporting, and HR payroll accounting. The system appears to be a stock exchange management platform that handles trade processing (bonds and equities/ETFs), client management, brokerage fee calculation, regulatory fee collection, and GL posting.

## 2. Data Flow: Client/Investor Money to GL

### 2.1 Trade Upload Process
1. **CSV Import** (`trader/upload_bonds.php`, `trader/upload_shares.php`):
   - Users upload CSV files with trade data
   - System validates rows (duplicate check via Exchange Reference)
   - Auto-creates clients, bonds/equities if not found
   - Uses database transaction for atomicity

2. **Trade Creation**:
   - Trade records inserted into `trades` table
   - For **Company trades** (client_name matches company_name):
     - Recorded to Marketable Securities (1151 for equities, 1152 for bonds) via `recordCompanyEquityInvestment` / `recordCompanyBondInvestment`
     - Brokerage commission EXEMPTED (not posted to GL)
     - All other fees (VAT, CMSA, DSE, CSDR, VRF) still recorded
   - For **Client trades**:
     - SKIPPED from Marketable Securities investment recording
     - Expected to be entered manually via receipts later
     - All fees including brokerage are recorded to GL

3. **Fee Calculation & GL Posting**:
   - **Bonds** (`trader/upload_bonds.php`):
     - Brokerage: 0.063132% on first 100M face value, 0.035% on excess
     - VAT: 18% on brokerage
     - CMSA: 0.0100% on consideration
     - CSD: 0.0118% on face value
     - DSE: 0.02006% on face value
   - **Equities** (`trader/upload_shares.php`):
     - Brokerage: Tiered (1.7% ≤ 10M, 1.5% 10-50M, 0.8% > 50M)
     - VAT: 18% on brokerage
     - CMSA: 0.01% on consideration
     - CSD: 0.0118% on consideration
     - DSE: 0.02006% on consideration
     - VRF: 0.0025% on consideration
   - Fees posted to GL via `createBondAccountingEntries` / `createEquityAccountingEntries`:
     - Credit: Brokerage Income (411) - EXEMPT for company trades
     - Credit: VAT Payable (213)
     - Credit: CMSA Payable (2111)
     - Credit: DSE Payable (2112)
     - Credit: CSDR Payable (2113)
     - Credit: VRF Payable (2114) - equities only
   - Duplicate prevention: `isGLDuplicateEntry()` checks `reference_no`, `account_code`, and description pattern

4. **Regulatory Fee Assignment**:
   - All trades recorded to `regulatory_fee_assignments` table for tracking

5. **Custodian Trades** (SCA Code ≠ Company Code):
   - Recorded in `custodians_trades` table
   - Fee split: Brokerage fees (exempt for company) + Other fees

### 2.2 Receipt/Invoice Generation
- `trader/enhanced_import.php` auto-generates `trade_receipts` (for buys) and `trade_invoices` (for sells)
- `finance/receipt.php` and `finance/payment.php` handle manual receipts/payments with full GL mirroring

### 2.3 HR/Payroll Integration
- Payroll calculations create entries in `general_ledger` with `reference_type = 'payroll'`
- Payroll control account (216) and statutory payables (2121-2126) used
- Expense accounts under 512 (Staff Benefits) for employer contributions

## 3. Key Account Mapping

## 2. Account Mapping Configuration (`config/account_mapping.php`)
The system uses a centralized mapping configuration to map various inputs (trade uploads, HR payroll data, charges) to GL account codes.

### Key Account Codes:
- **Trade Receivable:** 1121
- **Brokerage Commission Income:** 411
- **Cash at Bank:** 1112
- **VAT Payable:** 213
- **Regulatory Fees (CMSA, DSE, CSDR, VRF):** Payable accounts 2111, 2112, 2113, 2114
- **Payroll (Liability/Expense):** Various accounts under 212 (Accrued Expenses) and 512 (Staff Benefits), with Payroll Control at 216.

### Key Logic:
- `isVictoryOrB13Trade()`: Identifies trades involving 'Victory Financial Services' or those marked with 'B13' (e.g., in `sca_code`, `security_id`, or `client_cds`). These trades are likely special and qualify for specific handling (Trade Receivables).
- `generateGLDuplicateKey()`: Creates unique identifiers for GL entries to prevent double-posting of the same transaction (using MD5 of `upload_id` + `trade_reference` + `account_code` + `posting_type`).

## 3. Cash Flow Configuration (`admin/cash_flow_configuration.php`)
The system allows administrative configuration of cash flow line items.
- Items are categorized by Activity: `OPERATING`, `INVESTING`, `FINANCING`, `TT` (Total).
- Formatting options include `HD` (Header), `VP` (Value Part), `ST` (Sub Total).
- Configurable rules: `reverse`, `hpal` (likely related to Header/Part Association Logic), `hbral` (likely related to Header/Branch Association Logic).

## 4. Trade and Investor Money Handling
- Trades are processed through `trader/trades.php`.
- Trade matching logic and account mapping happen during uploads in `trader/upload_bonds.php` and `trader/upload_shares.php`.
- The system handles both equities (`equities` table) and bonds (`bonds` table).
- Trade receipts (`trade_receipts`) and invoices (`trade_invoices`) are generated based on matched trades.
- **GL Integration:**
  - The `general_ledger` table is the core of the accounting system.
  - Functions `createBondAccountingEntries` (in `trader/upload_bonds.php`) and `createEquityAccountingEntries` (in `trader/upload_shares.php`) handle automated posting of fees (brokerage, VAT, CMSA, DSE, CSDR, VRF) to the GL.
  - Company trades are treated differently from client trades; for example, company trades are recorded as investments (`Marketable Securities`) and are exempt from brokerage commission fees.
  - GL entries are recorded via `recordGeneralLedgerEntry`, which checks for existing accounts in `chart_of_accounts` and uses `isGLDuplicateEntry` to prevent double-posting.
  - The system uses `reference_type` (e.g., 'trade', 'payroll', 'fee', 'company_investment') in `general_ledger` for categorization.

## 5. GL Integration
- The GL is driven by automated processes that use the centralized mapping configuration.
- Database schema (`database/schema.sql`) defines basic entities, and `database/migration_payroll_accounting.sql` shows how the GL (`general_ledger` table) was extended to support new `reference_type` enum values (`payroll`).
- **GL Structure & Postings:**
  - The `general_ledger` table is the core of the accounting system. It is supported by a `journal_entries` table. Every financial transaction is expected to create dual journal entries (debit + credit).
  - Transactions are recorded with a `reference_no` (e.g., `RCP` for receipts, `INV` for invoices, `TRD` for trades) and a `reference_type` (e.g., 'trade', 'payroll', 'fee', 'company_investment').
  - The system uses `isGLDuplicateEntry()` to check the `general_ledger` table using `reference_no`, `account_code`, and a `description` pattern to prevent double-posting.

## 7. Audit Summary & Observations
- **Strong Centralization:** The `config/account_mapping.php` file is a critical component for mapping trade uploads, HR data, and charges to GL accounts.
- **Automated Validation:** The presence of `tests/test_trade_accounting.php` suggests there is a test suite for the accounting logic, which is vital.
- **Data Integrity:** The use of `beginTransaction()`/`commit()` in the upload scripts (e.g., `trader/upload_bonds.php`, `trader/upload_shares.php`) ensures atomic operations for trade creation, financial entry recording, and fee assignments.
- **Separation of Concerns:** Company trades vs. Client trades are handled differently, with company trades going to Marketable Securities and client trades requiring manual receipt entry.
- **Duplicate Prevention:** The `isGLDuplicateEntry()` function and Exchange Reference duplicate checks provide good protection against double-posting.
- **Comprehensive Fee Structure:** The system handles complex fee calculations including tiered brokerage, liberty rates, and multiple regulatory fees.
- **Potential Areas for Deep Dive:**
  - Trace the exact code path from a trade upload to the final GL entry insertion.
  - Review how cash flows are calculated from the `general_ledger` data based on the `cash_flow_formats` configurations.
  - Examine security around automated postings—are there approvals required for specific GL adjustments? (`expenses_benefits` table shows an `approved_by` column, which is good).
  - Verify the reconciliation process between `custodians_trades` and GL entries.
  - Check if manual receipt entry for client trades actually happens and how it integrates with the skipped trades.
