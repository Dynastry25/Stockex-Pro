# Finance Module - Complete Documentation

## Table of Contents
1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Schema](#database-schema)
4. [Module Breakdown](#module-breakdown)
5. [Business Rules & Logic](#business-rules--logic)
6. [Integration Flow](#integration-flow)
7. [Security Features](#security-features)
8. [Test Cases](#test-cases)

---

## Overview

The Finance module is a comprehensive double-entry accounting system built for a stock exchange application. It handles **receipts, payments, general ledger, chart of accounts, bank reconciliation, financial reporting, and debtor/creditor tracking**. The system follows traditional accounting principles with automatic journal entry creation for all financial transactions.

**Key Design Principles:**
- Every financial transaction creates dual journal entries (debit + credit)
- All money movements are tracked through bank accounts
- The system supports multi-currency (Tsh, Ksh, USD, UGsh)
- Role-based access control (finance_officer, finance_manager, ceo, system_admin)
- CSRF protection on all forms
- PDO prepared statements for SQL injection prevention

---

## Architecture

### File Structure (33 files)
```
finance/
├── dashboard.php              # Finance officer overview
├── chart_of_accounts.php      # COA management (CRUD)
├── journal_entries.php        # Manual journal entries
├── journal_entry_view.php     # View individual entry
├── balance_sheet.php          # Balance sheet report
├── income_statement.php       # Income statement (P&L)
├── cashflow_statement.php     # Cash flow statement
├── equity_statement.php       # Statement of changes in equity
├── entity_ledger.php          # Sub-ledger by entity
├── payment.php                # Outgoing payments
├── receipt.php                # Incoming receipts
├── reconciliation.php         # Bank reconciliation
├── recon.php                  # Numeric reference receipt approval
├── banks.php                  # Bank account CRUD
├── debtors.php                # Multi-entity debtor/creditor tracking
├── debt_credit_tracking.php   # Client-specific aging
├── settings.php               # Financial statement config
├── distribute_money.php       # Fund allocation
├── generate_receipt_pdf.php   # PDF receipt generation
├── print_receipt.php          # Print receipt
├── hr_payables.php            # HR payable balances
├── financial_data.php         # Interactive GL explorer
├── financial_data_export.php  # Excel/PDF export
├── export_account_details.php # PDF account details
├── export_accounts.php        # CSV export of COA
├── journal_entries_export.php # Excel/PDF journal export
├── manage_lookups.php         # Reference data CRUD
├── reports_dashboard.php      # Reports hub
├── upload_mtp.php             # CSV bulk trade upload
├── assign_regulatory_fees.php # (placeholder)
└── backups/                   # SQL backups
```

---

## Database Schema

### Core Financial Tables

#### `chart_of_accounts`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| account_code | VARCHAR | Hierarchical code (e.g., 1112) |
| account_name | VARCHAR | Display name |
| account_type | ENUM | asset, liability, equity, income, expense |
| normal_balance | ENUM | debit, credit |
| is_group_account | BOOLEAN | Group accounts can't have transactions |
| level | INT | Hierarchy level (1-5+) |
| parent_id | INT (FK) | Parent account reference |
| is_active | BOOLEAN | Soft delete |

**Account Code Hierarchy:**
- `1xxx` - Assets
- `2xxx` - Liabilities
- `3xxx` - Equity
- `4xxx` - Income
- `5xxx` - Expenses
- Each digit represents a hierarchy level (e.g., `1213` = Level 4)

#### `general_ledger`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| journal_id | INT (FK) | Links to journal_entries |
| transaction_date | DATE | Transaction date |
| account_id | INT (FK) | Chart of accounts reference |
| account_code | VARCHAR | Denormalized for performance |
| account_name | VARCHAR | Denormalized |
| debit_amount | DECIMAL | Debit amount |
| credit_amount | DECIMAL | Credit amount |
| running_balance | DECIMAL | Running balance |
| balance_type | ENUM | debit, credit |
| description | TEXT | Transaction description |
| reference_no | VARCHAR | Source reference (receipt_no, payment_no) |
| reference_type | VARCHAR | receipt, payment, trade, journal, etc. |
| entity_id | VARCHAR | Entity identifier |
| entity_name | VARCHAR | Entity name |
| entity_type | VARCHAR | client, broker, supplier, etc. |
| currency | VARCHAR | Transaction currency |
| fiscal_year | VARCHAR | Fiscal year |
| fiscal_period | VARCHAR | Month (01-12) |
| is_reconciled | BOOLEAN | Bank reconciliation status |
| status | VARCHAR | active, void |

#### `journal_entries`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| journal_no | VARCHAR | Unique journal number (JRNLYYYYMMDDXXXX) |
| transaction_date | DATE | Transaction date |
| reference_no | VARCHAR | Source reference |
| reference_type | VARCHAR | Source type |
| description | TEXT | Description |
| account_code | VARCHAR | Account code |
| account_name | VARCHAR | Account name |
| debit_amount | DECIMAL | Debit amount |
| credit_amount | DECIMAL | Credit amount |
| currency | VARCHAR | Currency |
| fiscal_year | VARCHAR | Fiscal year |
| fiscal_period | VARCHAR | Fiscal period |
| status | VARCHAR | posted, void |
| created_by | INT | User ID |

#### `receipts`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| receipt_no | VARCHAR | Unique receipt number (RCPYYYYMMDDXXXX) |
| receipt_date | DATE | Receipt date |
| payment_mode | INT | Payment method ID |
| account_of | VARCHAR | Ledger type code (C, D, A, B, S, E, O) |
| name | VARCHAR | Payer name |
| name_id | INT | Entity ID |
| source_type | VARCHAR | Entity type |
| record_in_financial | VARCHAR | yes/no - affects GL posting |
| ac_debit | INT | Bank account ID |
| currency | VARCHAR | Currency |
| amount | DECIMAL | Receipt amount |
| cheque_no | VARCHAR | Cheque reference |
| narration | TEXT | Description |
| money_distribution | VARCHAR | yes/no - enables fund distribution |
| document_path | VARCHAR | Uploaded document |
| bank_name | VARCHAR | Bank name |
| bank_account_number | VARCHAR | Bank account number |
| status | VARCHAR | active |

#### `payments`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| payment_no | VARCHAR | Unique payment number (PMTYYYYMMDDXXXX) |
| payment_date | DATE | Payment date |
| payment_mode | INT | Payment method ID |
| paid_to | VARCHAR | Ledger type code |
| name | VARCHAR | Payee name |
| name_id | INT | Entity ID |
| source_type | VARCHAR | Entity type |
| record_in_financial | VARCHAR | yes/no |
| ac_credit | INT | Bank account ID |
| currency | VARCHAR | Currency |
| amount | DECIMAL | Payment amount |
| cheque_no | VARCHAR | Cheque reference |
| narration | TEXT | Description |
| trade_reference | VARCHAR | Trade reference for settlement |
| payment_type | VARCHAR | general, trade, statutory |
| bulk_payment_id | INT | Bulk payment reference |
| bulk_payment_no | VARCHAR | Bulk payment number |
| bank_name | VARCHAR | Bank name |
| bank_account_number | VARCHAR | Bank account number |
| status | VARCHAR | active |

#### `banks_accounts`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | GL account code (links to chart_of_accounts) |
| bank_name | VARCHAR | Bank name |
| account_name | VARCHAR | Account name |
| account_number | VARCHAR | Account number |
| account_type | VARCHAR | Account type |
| currency | VARCHAR | Default currency |
| current_balance | DECIMAL | Current balance |
| available_balance | DECIMAL | Available balance |
| status | VARCHAR | active, inactive |

#### `receipt_distributions`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| receipt_id | INT (FK) | Receipt reference |
| receipt_no | VARCHAR | Receipt number |
| total_amount | DECIMAL | Total receipt amount |
| distributed_amount | DECIMAL | Amount distributed |
| remaining_balance | DECIMAL | Remaining balance |
| currency | VARCHAR | Currency |
| status | VARCHAR | pending, completed |

#### `distribution_activities`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| distribution_id | INT (FK) | Distribution reference |
| amount | DECIMAL | Distribution amount |
| description | TEXT | Distribution purpose |
| distribution_type | VARCHAR | trade, expense, investment, other |
| distributed_at | DATETIME | Timestamp |
| created_by | INT | User ID |
| created_by_username | VARCHAR | Username |

---

## Module Breakdown

### 1. Receipt Processing (`receipt.php`)

**Purpose:** Record incoming money from clients and entities.

**Receipt Number Format:** `RCPYYYYMMDDXXXX` (e.g., RCP202512210001)

**Entity Types (Ledger Codes):**
| Code | Entity Type | Control Account |
|------|-------------|-----------------|
| C | Client | 73101 (Clients Control A/C) |
| D | Custodian | 72114 (Custodians Control A/C) |
| A | Agent | 73111 (Agents Control A/C) |
| B | Broker | 72714 (Brokers Control A/C) |
| S | Supplier | 73101 (Suppliers Control A/C) |
| E | Employee | 72715 (Employees Control A/C) |
| O | Chart Account | Selected account directly |

**Business Logic:**
1. Validate all inputs (date, amount, currency, bank account)
2. Generate unique receipt number
3. Insert receipt record
4. If `record_in_financial = 'yes'`:
   - **DEBIT** Bank Account (increases bank balance)
   - **CREDIT** Control Account (or specific income account for type 'O')
5. If `money_distribution = 'yes'`:
   - Create distribution record in `receipt_distributions`
6. Update bank account balance
7. Create distribution record if enabled

**Journal Entry Example (Client Receipt):**
```
DR: 1112 - Cash at Bank     Tsh 1,000,000
CR: 73101 - Clients Control  Tsh 1,000,000
Reference: RCP202512210001
```

**Validation Rules:**
- Amount must be > 0 and <= 999,999,999.99
- Currency must be in: Tsh, Ksh, USD, UGsh
- Receipt date must be valid date format
- Bank account must exist and be active
- Payment mode must be active
- CSRF token must match

### 2. Payment Processing (`payment.php`)

**Purpose:** Record outgoing payments to entities.

**Payment Number Formats:**
- Single: `PMTYYYYMMDDXXXX`
- Bulk: `BPMTYYYYMMDDXXXX`

**Statutory Account Codes:**
| Code | Name |
|------|------|
| 2121 | NSSF Payable |
| 2122 | SDL Payable |
| 2123 | WCF Payable |
| 2124 | OSHA Payable |
| 2125 | Health Insurance Payable |
| 2126 | PAYE Payable |

**Single Payment Logic:**
1. Validate inputs
2. Generate payment number
3. Insert payment record
4. If `record_in_financial = 'yes'`:
   - **DEBIT** Payee Control Account
   - **CREDIT** Bank Account
5. If trade reference provided, update trade settlement status
6. Update bank balance (subtract amount)

**Bulk Payment Logic:**
1. Validate all payment items
2. Verify total matches sum of items
3. Separate statutory vs non-statutory payments
4. **Statutory payments:** Group by account code, create single payment per account
5. **Non-statutory payments:** Create individual payment records
6. For each payment:
   - Create journal entries (DR payee, CR bank)
   - Update trade settlement if trade reference provided
7. Update bank balance once for total amount

**Bulk Payment Journal Entry Example:**
```
For statutory payment (NSSF):
DR: 2121 - NSSF Payable     Tsh 500,000
CR: 1112 - Cash at Bank     Tsh 500,000
Reference: PMT202512210001

For client payment:
DR: 73101 - Clients Control  Tsh 1,000,000
CR: 1112 - Cash at Bank     Tsh 1,000,000
Reference: PMT202512210002
```

**Duplicate Prevention:**
```php
// Check for duplicate journal entries
$check_stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM journal_entries 
    WHERE reference_no = ? 
    AND account_code = ? 
    AND debit_amount = ? 
    AND credit_amount = ?
    AND DATE(transaction_date) = ?
    AND reference_type = ?
");
```

### 3. MTP CSV Upload (`upload_mtp.php`)

**Purpose:** Bulk upload Market Transaction Processing payments via CSV.

**CSV Format:**
```
Date, Name, Phone, CDS, Amount, Control No, Bank, Broker
1-Oct-25, John Doe, +255712345678, CDS001, 500000, 998550001, CRDB Bank, ABC Securities
```

**Processing Flow:**
1. **CSV Validation:**
   - File must be CSV format
   - File size <= 10MB
   - Required fields: Name, CDS, Amount, Control No, Bank

2. **Data Cleaning:**
   - CDS normalized: Remove non-alphanumeric, uppercase
   - Control number: Remove non-numeric
   - Amount: Remove non-numeric except decimal
   - Date parsing: Supports multiple formats (d-M-y, d/m/Y, Y-m-d, etc.)

3. **Duplicate Detection:**
   - Checks existing receipts with `MTP%` prefix
   - Checks existing journal entries with `MTP%` prefix
   - Rejects rows with duplicate control numbers

4. **Client Management:**
   - Find existing client by CDS account
   - Find existing client by name
   - Create new client if not found

5. **Receipt Creation:**
   - Generate receipt number (MTP prefix)
   - Insert receipt with source = 'MTP'
   - Create journal entries:
     - DR: 1112 - Cash at Bank
     - CR: 73101 - Clients Control A/C
   - Update bank balance

6. **Transaction:**
   - All records created in single database transaction
   - Rollback on any failure

### 4. Fund Distribution (`distribute_money.php`)

**Purpose:** Allocate received money to specific purposes (trades, expenses, investments).

**Distribution Types:**
- `trade` - Buy/Sell shares
- `expense` - Operating expenses
- `investment` - Investment activities
- `other` - Other purposes

**Business Logic:**
1. Only receipts with `money_distribution = 'yes'` can be distributed
2. Distribution tracks:
   - Total amount
   - Distributed amount
   - Remaining balance
3. Activities are logged with:
   - Amount
   - Description
   - Distribution type
   - Timestamp
   - User who distributed
4. Status auto-updates: `completed` when `distributed_amount >= total_amount`

**Balance Calculation:**
```
Remaining Balance = Total Amount - Sum(Distribution Activities)
```

### 5. Bank Reconciliation (`reconciliation.php`)

**Purpose:** Reconcile bank accounts with system records.

**Reconciliation Formula:**
```
Expected GL Balance = Opening Balance + (Total Debits - Total Credits)
Difference = Bank Statement Balance - Expected GL Balance
Reconciled = (abs(Difference) < 0.01) AND (uncleared_transactions == 0)
```

**Data Sources:**
- Receipts (money in)
- Payments (money out)
- GL entries (direct journal entries)

**Bank Account Selection:**
- All active bank accounts from `banks_accounts`
- Cash account (GL code 1111 - Cash on Hand)
- Links to GL via `code` field matching `account_code`

**Transaction Display:**
- Running balance calculated
- Source badges: RCPT, PMT, GL
- Type badges: Deposit, Withdrawal, GL Debit, GL Credit
- Reference tracking: Cheque numbers, payment references

### 6. Receipt Approval Workflow (`recon.php`)

**Purpose:** Finance officer reviews and approves receipts for trades with numeric `additional_reference`.

**Eligibility Rules:**
- Bonds: All trades
- Equities/ETFs: BUY trades only
- `additional_reference` must be numeric only (REGEXP `^[0-9]+$`)

**Workflow:**
1. Trader uploads receipt for trade
2. Finance officer reviews receipt
3. Approve or Reject with comment
4. Status tracked: pending (0), approved (1), rejected (2)
5. Comments can be added by both parties

**Status Values:**
- `0` / NULL - Pending
- `1` - Approved
- `2` - Rejected

### 7. Debtors & Credit Tracking (`debtors.php`)

**Purpose:** Track balances across all entity types with aging analysis.

**Entity Types Tracked:**
- Clients
- Custodians
- Employees
- Agents
- Brokers
- Suppliers
- Chart Accounts
- Bank Accounts

**Balance Calculation:**
```
For most entities:
  Total Receipts = Sum(receipts) + GL debits
  Total Payments = Sum(payments) + GL credits
  Net Balance = Total Payments - Total Receipts
  - Positive = Credit Balance (We Owe)
  - Negative = Debit Balance (Entity Owes Us)

For bank accounts:
  Net Balance = current_balance (from banks_accounts table)
```

**Aging Buckets:**
| Bucket | Days | Description |
|--------|------|-------------|
| Current | 0-30 | Current receivables |
| 31-60 Days | 31-60 | Slightly overdue |
| 61-90 Days | 61-90 | Overdue |
| 90+ Days | 90+ | Severely overdue |

**Aging Calculation:**
```php
function getAgingCategory($days) {
    if ($days <= 30) return 'Current';
    if ($days <= 60) return '31-60 Days';
    if ($days <= 90) return '61-90 Days';
    return '90+ Days';
}
```

### 8. Chart of Accounts (`chart_of_accounts.php`)

**Purpose:** Manage the hierarchical chart of accounts.

**Account Types:**
| Type | Normal Balance | Example |
|------|----------------|---------|
| Asset | Debit | Cash, Receivables |
| Liability | Credit | Payables, Loans |
| Equity | Credit | Capital, Retained Earnings |
| Income | Credit | Commission, Interest |
| Expense | Debit | Salaries, Rent |

**Hierarchy Rules:**
- Account codes are hierarchical (each digit = one level)
- Parent account cannot be a child of its descendants
- Group accounts cannot have direct transactions
- Soft delete (is_active = 0)

**Account Code Generation:**
```php
function generateHierarchicalAccountCode($db, $parent_code, $account_type) {
    if ($parent_code) {
        // Child account: parent_code + next_sequence
        // Example: 111 + 2 = 1112
    } else {
        // Top-level: category_code + sequence
        // Assets: 1xxx, Liabilities: 2xxx, etc.
    }
}
```

### 9. Financial Data Explorer (`financial_data.php`)

**Purpose:** Interactive drill-down from COA into transaction details.

**Features:**
- Account balances sidebar
- Click account to view transactions
- Running balance calculation
- Export to Excel/PDF
- Print support
- Filters: date range, account, category, reference type, search

**Balance Calculation:**
```php
if ($normal_balance == 'debit') {
    $balance = $debit - $credit;
} else {
    $balance = $credit - $debit;
}
```

---

## Business Rules & Logic

### 1. Double-Entry Accounting Rules
- Every transaction must have equal debits and credits
- Journal entries are created automatically for receipts and payments
- Manual journal entries can be created via `journal_entries.php`
- All entries are mirrored to both `journal_entries` and `general_ledger`

### 2. Bank Balance Rules
- Receipts INCREASE bank balance: `current_balance + amount`
- Payments DECREASE bank balance: `current_balance - amount`
- Balance updates happen atomically with transaction creation

### 3. Control Account Mapping
```
Entity Type → Control Account Code
Client (C) → 73101
Custodian (D) → 72114
Agent (A) → 73111
Broker (B) → 72714
Employee (E) → 72715
Supplier (S) → 73101
Chart Account (O) → Selected account directly
```

### 4. Receipt Processing Rules
- Receipts with `record_in_financial = 'no'` do NOT create journal entries
- Receipts with `money_distribution = 'yes'` create distribution records
- Bank balance is always updated regardless of `record_in_financial`

### 5. Payment Processing Rules
- Single payments create individual journal entries
- Bulk payments group statutory accounts
- Trade references update settlement status
- Statutory payments check payable balance before posting

### 6. Duplicate Prevention
- Journal entries checked for duplicates before insertion
- MTP uploads check existing control numbers
- Receipt numbers are unique per day (sequential)

### 7. Fiscal Period Rules
- Fiscal year = transaction date year
- Fiscal period = transaction date month (01-12)
- All entries are tagged with fiscal year and period

### 8. Currency Rules
- Supported currencies: Tsh, Ksh, USD, UGsh
- Currency is stored with each transaction
- No automatic currency conversion

### 9. Security Rules
- CSRF token validation on all POST requests
- Security headers on all pages
- PDO prepared statements for all queries
- Role-based access control
- Input sanitization with htmlspecialchars

---

## Integration Flow

### Trade → Finance Flow
```
1. Trade Created (trade module)
   ↓
2. Fee Calculation (fee_configurations)
   ↓
3. GL Posting (postTradeToGL)
   - DR: 1121 Trade Receivables (for buys)
   - CR: 411 Commission Income
   - CR: VAT Payable
   - CR: CMSA Fee Payable
   - CR: DSE Access Fee Payable
   ↓
4. Receipt Created (finance/receipt.php)
   - DR: Bank Account
   - CR: 73101 Clients Control
   ↓
5. Payment Created (finance/payment.php)
   - DR: 73101 Clients Control
   - CR: Bank Account
   ↓
6. Trade Settlement Updated
```

### HR → Finance Flow
```
1. Payroll Calculated (payroll module)
   ↓
2. GL Posting (postPayrollToGL)
   - DR: 51x Expense Accounts
   - CR: 2121 NSSF Payable
   - CR: 2122 SDL Payable
   - CR: 2123 WCF Payable
   - CR: 2124 OSHA Payable
   - CR: 2125 Health Insurance Payable
   - CR: 2126 PAYE Payable
   - CR: 216 Net Salary Payable
   ↓
3. Statutory Payment (finance/payment.php)
   - DR: 2121-2126 Statutory Accounts
   - CR: Bank Account
```

### MTP Upload → Finance Flow
```
1. CSV Uploaded (upload_mtp.php)
   ↓
2. Data Validated & Cleaned
   ↓
3. Client Found/Created
   ↓
4. Receipt Created (MTP prefix)
   - DR: 1112 Cash at Bank
   - CR: 73101 Clients Control
   ↓
5. Bank Balance Updated
```

---

## Security Features

### 1. CSRF Protection
```php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// In form:
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
// In handler:
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF validation failed");
}
```

### 2. Security Headers
```php
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; ...");
```

### 3. Input Sanitization
```php
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
```

### 4. Prepared Statements
```php
$stmt = $db->prepare("SELECT * FROM receipts WHERE id = ?");
$stmt->execute([$id]);
```

### 5. Role-Based Access
```php
require_finance_officer(); // Checks role >= 2
```

**Role Levels:**
| Level | Role |
|-------|------|
| 5 | system_admin |
| 4 | ceo |
| 3 | finance_manager |
| 2 | finance_officer |
| 1 | accountant |

---

## Test Cases

### Test Case 1: Create Receipt
**Input:**
```json
{
  "receipt_date": "2025-12-21",
  "payment_mode": 1,
  "account_of": "C",
  "name": "John Doe",
  "entity_id": 123,
  "entity_type": "client",
  "record_in_financial": "yes",
  "ac_debit": 1,
  "currency": "Tsh",
  "amount": 1000000,
  "narration": "Payment for CRDB shares"
}
```
**Expected:**
- Receipt created with number RCP202512210001
- Journal entry: DR 1112 (Bank) 1,000,000 / CR 73101 (Clients) 1,000,000
- Bank balance increased by 1,000,000
- Distribution record created (if money_distribution = yes)

### Test Case 2: Create Payment
**Input:**
```json
{
  "payment_date": "2025-12-21",
  "payment_mode": 1,
  "paid_to": "C",
  "name": "John Doe",
  "name_id": 123,
  "record_in_financial": "yes",
  "ac_credit": 1,
  "currency": "Tsh",
  "amount": 500000,
  "narration": "Settlement for trade T20251221001",
  "trade_reference": "T20251221001"
}
```
**Expected:**
- Payment created with number PMT202512210001
- Journal entry: DR 73101 (Clients) 500,000 / CR 1112 (Bank) 500,000
- Bank balance decreased by 500,000
- Trade T20251221001 settlement_status = 'settled'

### Test Case 3: Bulk Payment with Statutory
**Input:**
```json
{
  "bulk_payment_date": "2025-12-21",
  "bulk_payment_mode": 1,
  "bulk_ac_credit": 1,
  "bulk_currency": "Tsh",
  "bulk_total_amount": 2000000,
  "payments": [
    {"paid_to": "2121", "name": "NSSF", "amount": 500000},
    {"paid_to": "2126", "name": "PAYE", "amount": 800000},
    {"paid_to": "C", "name": "John Doe", "amount": 700000}
  ]
}
```
**Expected:**
- Bulk payment created: BPMT202512210001
- 3 individual payment records created
- Journal entries:
  - DR 2121 (NSSF) 500,000 / CR Bank 500,000
  - DR 2126 (PAYE) 800,000 / CR Bank 800,000
  - DR 73101 (Clients) 700,000 / CR Bank 700,000
- Bank balance decreased by 2,000,000

### Test Case 4: MTP CSV Upload
**CSV Content:**
```
Date,Name,Phone,CDS,Amount,Control No,Bank,Broker
1-Oct-25,John Doe,+255712345678,CDS001,500000,998550001,CRDB Bank,ABC Securities
1-Oct-25,Jane Smith,+255798765432,CDS002,750000,998550002,NMB Bank,XYZ Securities
```
**Expected:**
- 2 receipts created with MTP prefix
- 2 clients found/created
- Journal entries for each:
  - DR 1112 (Bank) / CR 73101 (Clients)
- Bank balances updated
- No duplicates (second upload with same control numbers rejected)

### Test Case 5: Fund Distribution
**Input:**
```json
{
  "action": "add_distribution",
  "receipt_id": 1,
  "amount": 300000,
  "description": "Buy CRDB shares",
  "distribution_type": "trade"
}
```
**Expected:**
- Distribution activity created
- Receipt distributed_amount updated
- Remaining_balance = total_amount - distributed_amount
- Status = 'completed' if fully distributed

### Test Case 6: Receipt Approval
**Input:**
```json
{
  "trade_id": 456,
  "is_approved": 1,
  "approval_comment": "Receipt verified"
}
```
**Expected:**
- numeric_trade_receipts.is_approved = 1
- approved_by = current username
- approved_at = NOW()
- approval_comment = "Receipt verified"

### Test Case 7: Bank Reconciliation
**Scenario:**
- Opening balance: 10,000,000
- Receipts in period: 5,000,000
- Payments in period: 3,000,000
- Bank statement balance: 12,000,000

**Expected:**
- Expected GL balance = 10,000,000 + 5,000,000 - 3,000,000 = 12,000,000
- Difference = 12,000,000 - 12,000,000 = 0
- Status = Reconciled

### Test Case 8: Aging Analysis
**Scenario:**
- Transaction 1: 15 days old, amount 500,000
- Transaction 2: 45 days old, amount 300,000
- Transaction 3: 75 days old, amount 200,000
- Transaction 4: 100 days old, amount 100,000

**Expected:**
- Current: 500,000 (50%)
- 31-60 Days: 300,000 (30%)
- 61-90 Days: 200,000 (20%)
- 90+ Days: 100,000 (10%)
- Total Overdue: 600,000 (60%)

### Test Case 9: Chart of Accounts Creation
**Input:**
```json
{
  "account_name": "Cash at Bank",
  "account_type": "asset",
  "parent_code": "111",
  "is_group_account": false
}
```
**Expected:**
- Account code auto-generated: 1112 (next in sequence)
- normal_balance = 'debit' (asset type)
- level = parent_level + 1
- is_active = 1

### Test Case 10: Journal Entry with Duplicate Prevention
**Input (first time):**
```json
{
  "reference_no": "RCP202512210001",
  "account_code": "1112",
  "debit_amount": 1000000,
  "credit_amount": 0,
  "transaction_date": "2025-12-21",
  "reference_type": "receipt"
}
```
**Input (second time - duplicate):**
```json
{
  "reference_no": "RCP202512210001",
  "account_code": "1112",
  "debit_amount": 1000000,
  "credit_amount": 0,
  "transaction_date": "2025-12-21",
  "reference_type": "receipt"
}
```
**Expected:**
- First entry: Created successfully
- Second entry: Skipped (duplicate detected)

### Test Case 11: Edge Case - Zero Amount Receipt
**Input:**
```json
{
  "amount": 0
}
```
**Expected:**
- Validation error: "Invalid amount. Amount must be greater than 0"

### Test Case 12: Edge Case - Invalid Currency
**Input:**
```json
{
  "currency": "EUR"
}
```
**Expected:**
- Validation error: "Invalid currency selected"

### Test Case 13: Edge Case - Bank Balance Insufficient
**Scenario:**
- Bank balance: 100,000
- Payment amount: 500,000

**Expected:**
- Payment created (no balance check in current implementation)
- Bank balance becomes -400,000
- Note: Consider adding balance validation

### Test Case 14: Edge Case - Concurrent Receipt Number
**Scenario:**
- Two users create receipts at the same time on the same day

**Expected:**
- Sequential numbering ensures unique receipt numbers
- Database unique constraint prevents duplicates

### Test Case 15: Integration Test - Complete Trade Lifecycle
**Steps:**
1. Create trade with fees
2. Post to GL (trade module)
3. Create receipt from client
4. Create payment to settle
5. Verify GL balances
6. Verify bank reconciliation

**Expected:**
- All journal entries balance
- Bank reconciliation matches
- Trade settlement status = 'settled'
- No orphaned records

---

## Additional Database Tables

### `trial_balance`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| period_date | DATE | Period end date |
| account_id | INT (FK) | Chart of accounts reference |
| account_code | VARCHAR | Denormalized account code |
| account_name | VARCHAR | Denormalized account name |
| debit_balance | DECIMAL | Debit balance amount |
| credit_balance | DECIMAL | Credit balance amount |
| is_closing | BOOLEAN | Closing entry flag |
| created_at | DATETIME | Creation timestamp |

### `balance_sheet_items`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| category | VARCHAR | asset, liability, equity |
| class | VARCHAR | current, non_current, owner_capital |
| item_name | VARCHAR | Display name |
| description | TEXT | Description |
| account_code | VARCHAR | Linked account code |
| status | VARCHAR | active, inactive |

### `cashflow_components`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| category | VARCHAR | operating, investing, financing |
| component_name | VARCHAR | Display name |
| description | TEXT | Description |
| calculation_basis | VARCHAR | direct, indirect |
| status | VARCHAR | active, inactive |

### `income_statement_items`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| category | VARCHAR | Free-text category |
| item_name | VARCHAR | Display name |
| description | TEXT | Description |
| item_type | VARCHAR | revenue, expense |
| status | VARCHAR | active, inactive |

### `brokerage_rates`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| rate_name | VARCHAR | Rate identifier |
| rate_value | DECIMAL | Rate percentage |
| is_rebated | BOOLEAN | Rebate flag |
| rebate_percentage | DECIMAL | Rebate rate |
| is_active | BOOLEAN | Active flag |

### `custodians_trades`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| trade_reference | VARCHAR | Trade reference |
| custodian_code | VARCHAR | Custodian code |
| custodian_name | VARCHAR | Custodian name |
| asset_class | VARCHAR | bond, equity, etf |
| security_id | INT | Security reference |
| security_name | VARCHAR | Security name |
| client_cds_account | VARCHAR | Client CDS account |
| client_name | VARCHAR | Client name |
| trade_side | VARCHAR | buy, sell |
| quantity | DECIMAL | Trade quantity |
| price | DECIMAL | Trade price |
| consideration | DECIMAL | Total value |
| trade_date | DATE | Trade date |
| settlement_date | DATE | Settlement date |
| brokerage_fees | DECIMAL | Brokerage amount |
| other_fees | DECIMAL | Regulatory fees |
| total_fees | DECIMAL | Total fees |
| created_at | DATETIME | Creation timestamp |

### `numeric_trade_receipts`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| trade_id | INT (FK) | Trade reference |
| receipt_path | VARCHAR | Receipt file path |
| additional_reference | VARCHAR | Numeric reference |
| uploaded_by | VARCHAR | Uploader username |
| uploaded_at | DATETIME | Upload timestamp |
| is_approved | INT | 0=pending, 1=approved, 2=rejected |
| approved_by | VARCHAR | Approver username |
| approved_at | DATETIME | Approval timestamp |
| approval_comment | TEXT | Approval notes |

### `vendors`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| name | VARCHAR | Vendor name |
| contact_person | VARCHAR | Contact person |
| phone | VARCHAR | Phone number |
| email | VARCHAR | Email address |
| address | TEXT | Physical address |
| status | VARCHAR | active, inactive |

### `payment_modes`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| mode_name | VARCHAR | Cash, Bank Transfer, Cheque, etc. |
| is_active | BOOLEAN | Active flag |

### Lookup Tables (managed by manage_lookups.php)

#### `bond_issuers`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Issuer code (unique) |
| issuer_name | VARCHAR | Issuer name |
| status | VARCHAR | active, inactive |

#### `bond_types`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Type code (unique) |
| description | VARCHAR | Type description |
| status | VARCHAR | active, inactive |

#### `bonds_economic_sectors`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Sector code (unique) |
| description | VARCHAR | Sector description |
| status | VARCHAR | active, inactive |

#### `coupon_determiners`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Code (unique) |
| description | VARCHAR | Description |
| status | VARCHAR | active, inactive |

#### `payment_frequencies`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Code (unique) |
| description | VARCHAR | Description |
| status | VARCHAR | active, inactive |

#### `investments_costing_basis`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Code (unique) |
| description | VARCHAR | Description |

#### `share_market_segments`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Code (unique) |
| description | VARCHAR | Description |

#### `share_market_trends`
| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| code | VARCHAR | Code (unique) |
| description | VARCHAR | Description |

---

## Summary of Key Account Codes

| Code | Name | Type | Normal Balance |
|------|------|------|----------------|
| 1111 | Cash on Hand | Asset | Debit |
| 1112 | Cash at Bank | Asset | Debit |
| 1121 | Trade Receivables | Asset | Debit |
| 2121 | NSSF Payable | Liability | Credit |
| 2122 | SDL Payable | Liability | Credit |
| 2123 | WCF Payable | Liability | Credit |
| 2124 | OSHA Payable | Liability | Credit |
| 2125 | Health Insurance Payable | Liability | Credit |
| 2126 | PAYE Payable | Liability | Credit |
| 216 | Net Salary Payable | Liability | Credit |
| 411 | Commission Income | Income | Credit |
| 51x | Expense Accounts | Expense | Debit |
| 72114 | Custodians Control | Asset | Debit |
| 72714 | Brokers Control | Asset | Debit |
| 72715 | Employees Control | Asset | Debit |
| 73101 | Clients Control | Asset | Debit |
| 73111 | Agents Control | Asset | Debit |
| 73113 | Nominal Clients Control | Asset | Debit |

---

## Detailed Module Documentation

### 10. Balance Sheet (`balance_sheet.php`)

**Purpose:** Generate Statement of Financial Position with comparative periods.

**Modes:**
1. **Main View** - Comparative balance sheet (current vs. comparison period)
2. **Drill-Down View** - Transaction-level details for specific accounts
3. **Export** - PDF, Excel (.xls), CSV formats

**Query Structure (16 queries in `getBalanceSheetData()`):**

| Section | Account Codes | Query Pattern |
|---------|---------------|---------------|
| Non-Current Assets | `121%` (PPE), `122%` (Intangible), `123,124,125,1251-1253` | SUM(debit - credit) |
| Current Assets | `113` (Inventory), `1121` (Trade Receivables), `111%` (Cash), `114%` (Other) | SUM(debit - credit) |
| Equity | `31` (Share Capital), `33` (Retained Earnings), `32,34,35` (Other) | SUM(credit - debit) |
| Non-Current Liabilities | `221` (Long-term Loans), `223` (Deferred Tax), `222,224` | SUM(credit - debit) |
| Current Liabilities | `211%` (Trade Payables), `214` (Short-term Loans), `212` (Accrued), `213%,214%,215%` (Other) | SUM(credit - debit) |

**Drill-Down Logic (lines 70-208):**
- `111` (Cash) → Groups by first 4 chars, shows bank accounts
- `211` (Trade Payables) → JOINs vendors table
- `213-215` (Other Liabilities) → Groups by first 3 chars
- Default → Exact account match

**Export Functions:**
- `exportToPDF()` (lines 618-766): TCPDF landscape A4
- `exportToExcel()` (lines 768-905): HTML-based .xls
- `exportToCSV()` (lines 907-976): php://output stream

**Balance Check:**
```php
// Line 1854-1880
$current_balance_diff = abs($current_totals['total_assets'] - $current_totals['total_liabilities_equity']);
if ($current_balance_diff < 0.01) {
    // Balanced - show success alert
} else {
    // Unbalanced - show danger alert
}
```

**Clickable Accounts (drill-down enabled):**
`111`, `211`, `213-215`, `1121`, `113`, `114`

---

### 11. Income Statement (`income_statement.php`)

**Purpose:** Generate Profit & Loss statement with financial ratios.

**Core Query (lines 470-491):**
```sql
SELECT 
    gl.account_code,
    gl.account_name,
    coa.account_type,
    SUM(CASE 
        WHEN coa.account_type = 'income' THEN gl.credit_amount - gl.debit_amount
        WHEN coa.account_type = 'expense' THEN gl.debit_amount - gl.credit_amount
        ELSE 0
    END) as net_amount
FROM general_ledger gl
JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
WHERE gl.transaction_date BETWEEN ? AND ?
AND gl.status = 'active'
AND coa.account_type IN ('income', 'expense')
GROUP BY gl.account_code, gl.account_name, coa.account_type
HAVING net_amount != 0
```

**Category Mapping:**

| Category | Account Codes | Description |
|----------|---------------|-------------|
| Revenue | 411, 412, 413, 414 | Brokerage, Advisory, Portfolio Mgmt, Custodial |
| Other Income | 421, 422, 423, 424 | Interest, Dividends, FX Gain, Asset Disposal |
| Operating Expenses | 511-512, 521-524, 53x, 561-564 | Staff, Admin, Depreciation, Regulatory Fees |
| Finance Costs | 541, 542 | Interest Expense, Lease Interest (IFRS 16) |
| Tax Expenses | 551, 552 | Current Tax, Deferred Tax |

**Financial Ratios (lines 1054-1087):**
```php
$operating_margin = ($totals['operating_profit'] / $totals['revenue']) * 100;
$net_profit_margin = ($totals['net_income'] / $totals['revenue']) * 100;
$expense_ratio = ($totals['operating_expenses'] / $totals['revenue']) * 100;
// Color-coded: green if < 80%, red if >= 80%
```

**Statement Structure:**
1. Revenue
2. Operating Expenses
3. **Operating Profit** = Revenue - Operating Expenses
4. Other Income
5. Finance Costs
6. **Profit Before Tax** = Operating Profit + Other Income - Finance Costs
7. Tax Expense
8. **Net Income** = Profit Before Tax - Tax Expenses

**Debug Mode:** Accessible via `?debug=1` parameter - shows raw query results.

---

### 12. Cash Flow Statement (`cashflow_statement.php`)

**Purpose:** Generate Statement of Cash Flows using indirect method.

**Three-Activity Structure:**

#### Operating Activities
| Component | Account Pattern | Logic |
|-----------|-----------------|-------|
| Net Income | Income/Expense accounts | credit - debit (income), debit - credit (expense) |
| Depreciation | `53%` | Non-cash adjustment (add back) |
| Accounts Receivable | `12%` | Decrease = source, Increase = use |
| Inventory | `13%` | Decrease = source, Increase = use |
| Prepaid Expenses | `14%` | Decrease = source, Increase = use |
| Accounts Payable | `21%` | Increase = source, Decrease = use |
| Accrued Expenses | `22%` | Increase = source, Decrease = use |
| Deferred Revenue | `23%` | Increase = source, Decrease = use |

#### Investing Activities
| Component | Account Pattern | Logic |
|-----------|-----------------|-------|
| Fixed Asset Purchases | `16%` (excluding `165%`) | Debit amount (negative - outflow) |
| Asset Sale Proceeds | `16%` + description LIKE '%sale%' | Credit amount (positive - inflow) |
| Investment Purchases | `17%` | Debit amount (negative - outflow) |

#### Financing Activities
| Component | Account Pattern | Logic |
|-----------|-----------------|-------|
| Loan Proceeds | `31%` | Credit amount (positive - inflow) |
| Loan Repayments | `31%` | Debit amount (negative - outflow) |
| Dividends Paid | `33%` | Debit amount (negative - outflow) |
| Equity Contributions | `32%` | Credit amount (positive - inflow) |

**Cash Account Balance Query (lines 113-149):**
```sql
-- Opening balance (before period)
SUM(CASE WHEN transaction_date < start_date THEN debit - credit ELSE 0 END) as opening_balance
-- Period change
SUM(CASE WHEN transaction_date BETWEEN start_date AND end_date THEN debit - credit ELSE 0 END) as period_change
-- Closing balance
SUM(CASE WHEN transaction_date <= end_date THEN debit - credit ELSE 0 END) as closing_balance
```

**Key Functions:**
- `getCashFlowHierarchicalData()` - Master orchestrator
- `getCashAccountBalances()` - Cash account balances
- `calculateNetIncome()` - Net income from GL
- `getNonCashAdjustments()` - Depreciation/amortization
- `getWorkingCapitalChanges()` - Current asset/liability changes
- `getInvestingActivities()` - Fixed asset transactions
- `getFinancingActivities()` - Debt/equity transactions

---

### 13. Statement of Changes in Equity (`equity_statement.php`)

**Purpose:** Track equity movements over a period.

**Equity Components Tracked:**
| Component | Account Name Pattern |
|-----------|---------------------|
| Owner's Capital | Contains 'owner' or 'capital' |
| Retained Earnings | Contains 'retained' |
| Common Stock | Contains 'common' |
| Preferred Stock | Contains 'preferred' |
| Additional Paid-in Capital | Contains 'additional' or 'paid-in' |
| Treasury Stock | Contains 'treasury' |

**Core Queries:**

1. **Beginning Equity Balance (lines 179-188):**
```sql
SELECT COALESCE(SUM(credit_amount - debit_amount), 0) as balance
FROM general_ledger 
WHERE status = 'active'
AND transaction_date < ?
AND account_code LIKE '3%'  -- Equity accounts
```

2. **Net Income (lines 191-200):**
```sql
SELECT COALESCE(SUM(credit_amount - debit_amount), 0) as net_income
FROM general_ledger 
WHERE status = 'active'
AND transaction_date BETWEEN ? AND ?
AND account_code LIKE '4%'  -- Income accounts
```

3. **Brokerage Income (lines 243-252):**
```sql
SELECT COALESCE(SUM(credit_amount - debit_amount), 0) as income
FROM general_ledger 
WHERE status = 'active'
AND transaction_date BETWEEN ? AND ?
AND account_code IN ('4111', '4112', '4113')
```

**Balance Calculation:**
```php
$total_equity_changes = $total_owners_capital + $total_retained_earnings + 
                       $total_common_stock + $total_preferred_stock + 
                       $total_additional_paid_in_capital + $total_treasury_stock + $net_income;
$ending_equity = $beginning_equity + $total_equity_changes;
```

**Financial Ratios (lines 542-574):**
- **Equity Growth** = (Total Equity Changes / Beginning Equity) * 100
- **Return on Equity** = (Net Income / Ending Equity) * 100
- **Income Contribution** = (Net Income / Total Equity Changes) * 100

**Pagination:** 10 records per page with URL-based navigation.

---

### 14. Entity Ledger (`entity_ledger.php`)

**Purpose:** Unified sub-ledger viewer for any entity type.

**Supported Entity Types:**
| Type | Table | Query Logic |
|------|-------|-------------|
| client | clients | Receipts + Payments + GL (by name or trade reference) |
| custodian | custodians | GL by entity_id |
| employee | users | GL by entity_id + payroll entries |
| agent | agents | GL by entity_id |
| broker | brokers | GL by entity_id |
| supplier | suppliers | GL by entity_id |
| chart_account | chart_of_accounts | GL by account_code |
| bank_account | banks_accounts | GL by entity_name |

**Client Transaction Merging (lines 191-367):**
1. **Receipts** → Treated as credits (money received)
2. **Payments** → Treated as debits (money paid)
3. **GL Entries** → Matched by entity_name or trade reference

**Running Balance Logic:**
```php
// Bank accounts (inverted)
if ($entity_type === 'bank_account') {
    $running_balance += $credit - $debit;  // Deposits increase, withdrawals decrease
} else {
    $running_balance += $debit - $credit;  // Debits increase what entity owes
}
```

**Export Limits:**
- PDF: Limited to 100 transactions
- Excel: No limit

**N+1 Query Pattern (Performance Concern):**
Account name resolution runs inside loops (lines 354-361, 479-486, 555-562).

---

### 15. Financial Statement Settings (`settings.php`)

**Purpose:** Configure line items for financial statements.

**Three Configuration Tables:**

| Table | Fields | Purpose |
|-------|--------|---------|
| balance_sheet_items | category, class, item_name, description, account_code | BS line items |
| cashflow_components | category, component_name, description, calculation_basis | Cash flow components |
| income_statement_items | category, item_name, description, item_type | P&L line items |

**Balance Sheet Categories:**
- Categories: `asset`, `liability`, `equity`
- Classes: `current`, `non_current`, `owner_capital`

**Cash Flow Categories:**
- `operating`, `investing`, `financing`
- Calculation basis: `direct`, `indirect`

**Income Statement:**
- Item types: `revenue`, `expense`
- Category: Free-text (e.g., "Sales", "Cost of Goods")

**Issues Identified:**
1. No CSRF protection on forms
2. No server-side validation of dropdown values
3. Delete buttons are non-functional stubs
4. No edit/update capability
5. Duplicate file exists: `setting.php` (without 's')

---

### 16. Lookup Table Management (`manage_lookups.php`)

**Purpose:** CRUD for reference/lookup tables used in dropdowns.

**Managed Tables (8):**
| Table | Purpose |
|-------|---------|
| bond_issuers | Bond issuer names |
| bond_types | Bond classification types |
| bonds_economic_sectors | Economic sector codes |
| coupon_determiners | Coupon calculation methods |
| payment_frequencies | Payment frequency codes |
| investments_costing_basis | Investment costing methods |
| share_market_segments | Market segment codes |
| share_market_trends | Market trend codes |

**CRUD Operations:**

1. **Add:** Duplicate code check → INSERT with status='active'
2. **Update:** UPDATE code, description, (optional) status
3. **Delete:** Dependency check (only for bonds_economic_sectors) → DELETE

**Schema-Aware Handling:**
```php
// Tables without status column
$no_status_tables = ['investments_costing_basis', 'share_market_segments', 'share_market_trends'];

if (in_array($table, $no_status_tables)) {
    $stmt = $db->prepare("INSERT INTO {$table} (code, description) VALUES (?, ?)");
} else {
    $stmt = $db->prepare("INSERT INTO {$table} (code, description, status) VALUES (?, ?, ?)");
}
```

**Dependency Check (bonds_economic_sectors only):**
```sql
SELECT COUNT(*) FROM trades 
WHERE asset_class = 'bond' 
AND economic_sector = (SELECT code FROM {$table} WHERE id = ?)
```

---

### 17. Trial Balance (`trial_balance.php` - MISSING)

**Status:** File does not exist. Backend logic exists in `includes/financial_helpers.php`.

**Helper Function: `generateTrialBalance($db, $period_date)` (lines 683-775)**

**Logic:**
1. DELETE existing non-closing entries for period
2. SELECT aggregated balances from GL + COA
3. INSERT into trial_balance table
4. Validate balance check

**Core Query:**
```sql
SELECT 
    coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance,
    COALESCE(SUM(gl.debit_amount), 0) as total_debit,
    COALESCE(SUM(gl.credit_amount), 0) as total_credit,
    CASE 
        WHEN coa.normal_balance = 'debit' THEN COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0)
        ELSE 0
    END as debit_balance,
    CASE 
        WHEN coa.normal_balance = 'credit' THEN COALESCE(SUM(gl.credit_amount - gl.debit_amount), 0)
        ELSE 0
    END as credit_balance
FROM chart_of_accounts coa
LEFT JOIN general_ledger gl ON coa.id = gl.account_id 
    AND gl.transaction_date <= ?
WHERE coa.is_active = 1
GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance
```

**Balance Validation:**
```php
$is_balanced = abs($total_debits - $total_credits) < 0.01;
```

---

### 18. HR Payables (`hr_payables.php`)

**Purpose:** Track and manage statutory payment obligations (NSSF, SDL, WCF, OSHA, Health Insurance, PAYE).

**Statutory Accounts:**
| Code | Name | Rate |
|------|------|------|
| 2121 | NSSF Payable | Employee + Employer contributions |
| 2122 | SDL Payable | Skills Development Levy |
| 2123 | WCF Payable | Workers Compensation Fund |
| 2124 | OSHA Payable | Occupational Safety |
| 2125 | Health Insurance Payable | NHIF contributions |
| 2126 | PAYE Payable | Income tax |

---

### 19. Reports Dashboard (`reports_dashboard.php`)

**Purpose:** Central hub for accessing all financial reports.

**Available Reports:**
1. Balance Sheet
2. Income Statement
3. Cash Flow Statement
4. Statement of Changes in Equity
5. Trial Balance (broken link - file missing)
6. Financial Data Explorer
7. Journal Entries
8. Account Balances
9. Debtors Report
10. Client Statement

---

### 20. Financial Data Export (`financial_data_export.php`)

**Purpose:** Export financial data in various formats.

**Export Formats:**
- PDF (TCPDF)
- Excel (.xls via HTML)
- CSV (php://output)

**Data Sources:**
- General Ledger
- Chart of Accounts
- Receipts
- Payments

---

### 21. Export Account Details (`export_account_details.php`)

**Purpose:** Export detailed account transaction history.

**Features:**
- Single account drill-down
- Date range filtering
- Running balance calculation
- PDF and Excel export

---

### 22. Export Accounts (`export_accounts.php`)

**Purpose:** Export Chart of Accounts listing.

**Output:** CSV format with account codes, names, types, and balances.

---

### 23. Journal Entries Export (`journal_entries_export.php`)

**Purpose:** Export journal entries in bulk.

**Features:**
- Date range filtering
- Account filtering
- Reference type filtering
- PDF and Excel export

---

### 24. Generate Receipt PDF (`generate_receipt_pdf.php`)

**Purpose:** Generate PDF receipt for a specific receipt record.

**Features:**
- TCPDF generation
- Company header
- Receipt details
- Payment information
- Bank details

---

### 25. Print Receipt (`print_receipt.php`)

**Purpose:** Print-friendly receipt view.

**Features:**
- Simplified layout
- Print CSS
- Browser print dialog integration

---

### 26. Dashboard (`dashboard.php`)

**Purpose:** Finance officer overview dashboard.

**Dashboard Widgets:**
1. Total Receipts (today, week, month)
2. Total Payments (today, week, month)
3. Outstanding Balances
4. Bank Balances
5. Recent Transactions
6. Pending Approvals
7. Chart of Accounts summary

**Report Cards:**
- Balance Sheet (links to `balance_sheet.php`)
- Income Statement (links to `income_statement.php`)
- Cash Flow (links to `cashflow_statement.php`)
- Trial Balance (links to `trial_balance.php` - BROKEN)

---

### 27. Banks (`banks.php`)

**Purpose:** Manage bank accounts linked to GL.

**GL Linking:**
```php
// Bank account code maps to chart_of_accounts.account_code
$bank_code = '1112';  // Cash at Bank
```

**Fields:**
- bank_name
- account_name
- account_number
- account_type
- currency
- current_balance
- available_balance
- status (active/inactive)

---

### 28. Debt Credit Tracking (`debt_credit_tracking.php`)

**Purpose:** Client-specific aging analysis.

**Aging Calculation:**
```php
$days_overdue = (strtotime($end_date) - strtotime($due_date)) / 86400;
$aging_bucket = getAgingCategory($days_overdue);
```

**PDF Generation:** Uses TCPDF for aging report export.

---

### 29. Journal Entry View (`journal_entry_view.php`)

**Purpose:** View individual journal entry details.

**Features:**
- Entry header (date, reference, description)
- Debit/credit lines
- Running balance
- Audit trail (created_by, created_at)
- Void capability

---

### 30. Assign Regulatory Fees (`assign_regulatory_fees.php`)

**Status:** Placeholder file (not yet implemented).

**Intended Purpose:** Assign CMSA, DSE, CSDR fees to trades.

---

## Missing Files

| File | Status | Notes |
|------|--------|-------|
| `trial_balance.php` | MISSING | Backend logic exists in financial_helpers.php |
| `invoice.php` | DOES NOT EXIST | Invoices are in `reports/invoices.php` |
| `client_statement.php` | DOES NOT EXIST | Client statements via `entity_ledger.php` |

---

## Financial Helpers (`includes/financial_helpers.php`)

**Purpose:** Core financial calculation and double-entry bookkeeping engine.

### Functions Overview

| # | Function | Lines | Purpose |
|---|----------|-------|---------|
| 1 | `getCompanyDetails($db)` | 10-27 | Fetch active company code and name |
| 2 | `isCustodianTrade($sca_code, $company_code)` | 32-34 | Check if trade is custodian |
| 3 | `getAccountIdByCode($db, $account_code)` | 39-54 | Lookup account ID by code |
| 4 | `getDefaultAccountId($account_code)` | 59-124 | Hardcoded fallback mappings (59 accounts) |
| 5 | `recordGeneralLedgerEntry($db, ...)` | 129-174 | Core GL insertion |
| 6 | `calculateBondFees($quantity, $price, $consideration)` | 179-217 | Tiered bond fee calculation |
| 7 | `createBondAccountingEntries($db, ...)` | 222-323 | GL entries for bond trade fees |
| 8 | `calculateEquityFees($db, $consideration)` | 328-375 | 3-tier equity fee calculation |
| 9 | `createEquityAccountingEntries($db, ...)` | 380-496 | GL entries for equity trade fees |
| 10 | `recordCompanyBondInvestment($db, ...)` | 501-559 | Proprietary bond investment entries |
| 11 | `recordCompanyEquityInvestment($db, ...)` | 564-622 | Proprietary equity investment entries |
| 12 | `calculateCustodianFees($fees)` | 627-637 | Aggregate custodian fee buckets |
| 13 | `recordCustodianTrade($db, $trade_data)` | 642-678 | Insert custodian trade record |
| 14 | `generateTrialBalance($db, $period_date)` | 683-775 | Generate trial balance |
| 15 | `getTieredBrokerageRates($db)` | 780-809 | Fetch tiered rates from DB |
| 16 | `safe_number_format($value, $decimals)` | 814-820 | Safe numeric formatting |
| 17 | `safe_int_format($value)` | 825-831 | Safe integer formatting |

### Bond Fee Calculation

**Tiered Brokerage (on face value):**
| Tier | Range | Rate |
|------|-------|------|
| 1 | First 100M | 0.063132% |
| 2 | Above 100M | 0.035% |

**Regulatory Fees:**
| Fee | Rate | Basis |
|-----|------|-------|
| VAT | 18% | Of brokerage |
| CMSA | 0.01% | Consideration |
| CSD&R | 0.0118% | Face value |
| DSE | 0.02006% | Face value |

**GL Entry Pattern:**
```
DR: 1112 - Cash at Bank (total fees received)
CR: 411 - Brokerage Commission Income
CR: 213 - VAT Payable
CR: 2141 - CMSA Fee Payable
CR: 2142 - CSD&R Fee Payable
CR: 2143 - DSE Fee Payable
```

### Equity Fee Calculation

**3-Tier Brokerage (on consideration):**
| Tier | Range | Rate |
|------|-------|------|
| 1 | First 10M | 1.7% |
| 2 | 10M - 50M | 1.5% |
| 3 | Above 50M | 0.8% |

**Regulatory Fees:**
| Fee | Rate | Basis |
|-----|------|-------|
| VAT | 18% | Of brokerage |
| CMSA | 0.01% | Consideration |
| CSD&R | 0.0118% | Consideration |
| DSE | 0.02006% | Consideration |
| VRF | 0.0025% | Consideration |

**GL Entry Pattern:**
```
DR: 1112 - Cash at Bank (total fees received)
CR: 411 - Brokerage Commission Income
CR: 213 - VAT Payable
CR: 2141 - CMSA Fee Payable
CR: 2142 - CSD&R Fee Payable
CR: 2143 - DSE Fee Payable
CR: 2144 - VRF Fee Payable
```

### Company Investment Entries

**Bond Investment:**
```
Buy:  DR 1011 - Bond Investments / CR 1008 - Cash at Bank
Sell: DR 1008 - Cash at Bank / CR 1011 - Bond Investments
```

**Equity Investment:**
```
Buy:  DR 1012 - Equity Investments / CR 1009 - Cash at Bank
Sell: DR 1009 - Cash at Bank / CR 1012 - Equity Investments
```

### Trial Balance Generation

**Process:**
1. DELETE existing non-closing entries for period
2. SELECT aggregated balances from GL + COA
3. INSERT into trial_balance table
4. Validate balance check

**Balance Validation:**
```php
$is_balanced = abs($total_debits - $total_credits) < 0.01;
```

### Default Account Mappings (59 accounts)

| Code | ID | Name |
|------|----|----|
| 1001 | 1 | Cash on Hand |
| 1002 | 2 | Petty Cash |
| 1008 | 3 | Cash at Bank - Main |
| 1009 | 4 | Cash at Bank - USD |
| 1011 | 5 | Bond Investments |
| 1012 | 6 | Equity Investments |
| 1111 | 7 | Cash on Hand (GL) |
| 1112 | 8 | Cash at Bank (GL) |
| 1121 | 9 | Trade Receivables |
| 213 | 10 | VAT Payable |
| 216 | 11 | Net Salary Payable |
| 2121 | 12 | NSSF Payable |
| 2122 | 13 | SDL Payable |
| 2123 | 14 | WCF Payable |
| 2124 | 15 | OSHA Payable |
| 2125 | 16 | Health Insurance Payable |
| 2126 | 17 | PAYE Payable |
| 2141 | 18 | CMSA Fee Payable |
| 2142 | 19 | CSD&R Fee Payable |
| 2143 | 20 | DSE Fee Payable |
| 2144 | 21 | VRF Fee Payable |
| 411 | 22 | Brokerage Commission Income |
| 511 | 23 | Salaries & Wages |
| 512 | 24 | Staff Benefits |
| 521 | 25 | Rent & Utilities |
| 522 | 26 | Professional Fees |
| 523 | 27 | Compliance & Legal |
| 524 | 28 | Market Data & Systems |
| 531 | 29 | Depreciation |
| 532 | 30 | Amortization |
| 541 | 31 | Interest Expense |
| 551 | 32 | Current Tax |
| 552 | 33 | Deferred Tax |
| 561 | 34 | CMSA Fees |
| 562 | 35 | DSE Fees |
| 563 | 36 | CSDR Fees |
| 564 | 37 | VRF Fees |
| 72114 | 38 | Custodians Control |
| 72714 | 39 | Brokers Control |
| 72715 | 40 | Employees Control |
| 73101 | 41 | Clients Control |
| 73111 | 42 | Agents Control |
| 73113 | 43 | Nominal Clients Control |

---

## Integration Map

### External Dependencies

| Module | Depends On | Provides |
|--------|------------|----------|
| receipt.php | banks_accounts, chart_of_accounts, general_ledger, receipts, receipt_distributions | Receipt processing, GL posting |
| payment.php | banks_accounts, chart_of_accounts, general_ledger, payments, trades | Payment processing, GL posting |
| upload_mtp.php | clients, receipts, general_ledger, banks_accounts | Bulk receipt creation |
| distribute_money.php | receipt_distributions, distribution_activities | Fund allocation |
| reconciliation.php | banks_accounts, receipts, payments, general_ledger | Bank reconciliation |
| recon.php | trades, numeric_trade_receipts | Receipt approval |
| debtors.php | receipts, payments, general_ledger, clients, custodians, agents, brokers, suppliers, users, banks_accounts | Aging analysis |
| balance_sheet.php | general_ledger, chart_of_accounts, companies | Balance sheet report |
| income_statement.php | general_ledger, chart_of_accounts, companies | P&L report |
| cashflow_statement.php | general_ledger, chart_of_accounts, companies | Cash flow report |
| equity_statement.php | general_ledger, chart_of_accounts, companies | Equity changes report |
| entity_ledger.php | general_ledger, receipts, payments, chart_of_accounts, clients, custodians, agents, brokers, suppliers, users, banks_accounts | Sub-ledger view |
| financial_helpers.php | chart_of_accounts, general_ledger, trial_balance, companies, brokerage_rates, custodians_trades | Fee calculation, GL posting, trial balance |
| settings.php | balance_sheet_items, cashflow_components, income_statement_items | Statement configuration |
| manage_lookups.php | bond_issuers, bond_types, bonds_economic_sectors, coupon_determiners, payment_frequencies, investments_costing_basis, share_market_segments, share_market_trends | Reference data CRUD |

### Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        TRADE MODULE                             │
│  upload_bonds.php / upload_shares.php                           │
│    ↓                                                            │
│  financial_helpers.php                                          │
│    - calculateBondFees / calculateEquityFees                   │
│    - createBondAccountingEntries / createEquityAccountingEntries│
│    - recordCompanyBondInvestment / recordCompanyEquityInvestment│
│    - recordCustodianTrade                                       │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                     GENERAL LEDGER                              │
│  - All journal entries stored here                              │
│  - Double-entry: every transaction has DR and CR                │
│  - Fiscal year/period tagging                                   │
│  - Status: active, void                                         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                   FINANCE MODULE                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │   receipts   │  │   payments   │  │   journals   │          │
│  │   (IN)       │  │   (OUT)      │  │   (MANUAL)   │          │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘          │
│         ↓                 ↓                  ↓                  │
│  ┌─────────────────────────────────────────────────────┐       │
│  │              GENERAL LEDGER UPDATE                   │       │
│  │  - DR Bank / CR Control (receipts)                   │       │
│  │  - DR Control / CR Bank (payments)                   │       │
│  │  - DR/CR as specified (journals)                     │       │
│  └─────────────────────────────────────────────────────┘       │
│                              ↓                                  │
│  ┌─────────────────────────────────────────────────────┐       │
│  │              FINANCIAL REPORTS                       │       │
│  │  - Balance Sheet                                     │       │
│  │  - Income Statement                                  │       │
│  │  - Cash Flow Statement                               │       │
│  │  - Statement of Changes in Equity                    │       │
│  │  - Trial Balance                                     │       │
│  │  - Bank Reconciliation                               │       │
│  │  - Aging Analysis                                    │       │
│  └─────────────────────────────────────────────────────┘       │
└─────────────────────────────────────────────────────────────────┘
```

### Bank Account GL Linking

```php
// banks_accounts.code → chart_of_accounts.account_code
// Example:
// banks_accounts.code = '1112'
// chart_of_accounts.account_code = '1112' (Cash at Bank)

// Balance update on receipt:
UPDATE banks_accounts SET current_balance = current_balance + ? WHERE id = ?

// Balance update on payment:
UPDATE banks_accounts SET current_balance = current_balance - ? WHERE id = ?
```

### Entity Type → Control Account Mapping

```
┌─────────────┬──────────────┬─────────────────────┐
│ Entity Type │ Ledger Code  │ Control Account     │
├─────────────┼──────────────┼─────────────────────┤
│ Client      │ C            │ 73101               │
│ Custodian   │ D            │ 72114               │
│ Agent       │ A            │ 73111               │
│ Broker      │ B            │ 72714               │
│ Employee    │ E            │ 72715               │
│ Supplier    │ S            │ 73101               │
│ Chart Acct  │ O            │ Selected directly   │
└─────────────┴──────────────┴─────────────────────┘
```

### Statutory Payment Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    HR PAYROLL MODULE                            │
│  postPayrollToGL()                                             │
│    DR: 51x Expense Accounts                                    │
│    CR: 2121 NSSF Payable                                       │
│    CR: 2122 SDL Payable                                        │
│    CR: 2123 WCF Payable                                        │
│    CR: 2124 OSHA Payable                                       │
│    CR: 2125 Health Insurance Payable                            │
│    CR: 2126 PAYE Payable                                       │
│    CR: 216 Net Salary Payable                                  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                    FINANCE MODULE                               │
│  payment.php (statutory payment)                               │
│    DR: 2121 NSSF Payable                                       │
│    DR: 2122 SDL Payable                                        │
│    DR: 2123 WCF Payable                                        │
│    DR: 2124 OSHA Payable                                       │
│    DR: 2125 Health Insurance Payable                            │
│    DR: 2126 PAYE Payable                                       │
│    CR: 1112 Cash at Bank                                       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Known Issues & Recommendations

### Critical Issues

| Issue | Location | Impact | Recommendation |
|-------|----------|--------|----------------|
| Missing trial_balance.php | finance/trial_balance.php | Broken dashboard link | Create the file using generateTrialBalance() helper |
| Function duplication | upload_bonds.php, upload_shares.php | Inconsistent fee logic | Use centralized financial_helpers.php |
| N+1 query pattern | entity_ledger.php lines 354-361 | Performance degradation | Use JOIN or batch lookup |
| Ledger code collision | entity_ledger.php line 69 | broker and bank_account both map to 'B' | Use distinct codes |

### Security Issues

| Issue | Location | Impact | Recommendation |
|-------|----------|--------|----------------|
| No CSRF protection | settings.php, manage_lookups.php | Cross-site request forgery | Add CSRF tokens to all forms |
| No server-side validation | settings.php | Invalid data insertion | Validate dropdown values server-side |
| Debug mode accessible | income_statement.php ?debug=1 | Information exposure | Restrict to system_admin role |
| CSRF in GET URL | income_statement.php, cashflow_statement.php | Token leakage via logs/history | Use POST for exports |

### Data Integrity Issues

| Issue | Location | Impact | Recommendation |
|-------|----------|--------|----------------|
| No delete/update | settings.php | Cannot modify configuration | Implement edit/delete functionality |
| Duplicate code on update | manage_lookups.php | Allow duplicate codes | Check uniqueness on update |
| No dependency checks | manage_lookups.php | Orphaned references | Add FK checks before delete |
| Dashboard data inconsistency | dashboard.php | Counts from wrong table | Use settings tables consistently |

### Code Quality Issues

| Issue | Location | Impact | Recommendation |
|-------|----------|--------|----------------|
| Dead code | manage_lookups.php get_lookup_entry() | Unused function | Remove or implement |
| Unused function | entity_ledger.php getLedgerCode() | Unused function | Remove or use |
| Hardcoded account mappings | balance_sheet.php | Brittle if COA changes | Drive from chart_of_accounts |
| Relative URL redirect | settings.php line 48 | May fail in some configs | Use redirect() helper |
| No format parameter | cashflow_statement.php formatAmount() | Currency param unused | Implement currency formatting |

### Performance Recommendations

1. **Replace N+1 queries in entity_ledger.php** with JOIN or batch lookup
2. **Cache company details** instead of querying on every page load
3. **Add database indexes** on frequently queried columns:
   - `general_ledger(transaction_date)`
   - `general_ledger(account_code)`
   - `general_ledger(reference_no)`
   - `general_ledger(entity_id)`
   - `receipts(receipt_no)`
   - `payments(payment_no)`

### Architecture Recommendations

1. **Consolidate financial helpers** - Remove duplicated functions from trader files
2. **Create missing trial_balance.php** - Implement presentation layer for existing helper
3. **Add CSRF protection** to all forms in settings.php and manage_lookups.php
4. **Implement edit/delete** in settings.php for configuration management
5. **Add validation layers** for all input fields
6. **Standardize export mechanism** - Use POST for all exports to prevent CSRF leakage
7. **Add audit logging** for all financial transactions
8. **Implement proper soft delete** with deleted_by and deleted_at fields

---

## Appendix A: Account Code Reference

### Asset Accounts (1xxx)

| Code | Name | Type |
|------|------|------|
| 1001 | Cash on Hand | Current Asset |
| 1002 | Petty Cash | Current Asset |
| 1008 | Cash at Bank - Main | Current Asset |
| 1009 | Cash at Bank - USD | Current Asset |
| 1011 | Bond Investments | Non-Current Asset |
| 1012 | Equity Investments | Non-Current Asset |
| 1111 | Cash on Hand (GL) | Current Asset |
| 1112 | Cash at Bank (GL) | Current Asset |
| 1121 | Trade Receivables | Current Asset |
| 113 | Inventory | Current Asset |
| 114 | Other Current Assets | Current Asset |
| 121 | Property, Plant & Equipment | Non-Current Asset |
| 122 | Intangible Assets | Non-Current Asset |
| 123-125 | Other Non-Current Assets | Non-Current Asset |

### Liability Accounts (2xxx)

| Code | Name | Type |
|------|------|------|
| 211 | Trade Payables | Current Liability |
| 212 | Accrued Expenses | Current Liability |
| 213 | Other Current Liabilities | Current Liability |
| 213 | VAT Payable | Current Liability |
| 214 | Short-term Loans | Current Liability |
| 216 | Net Salary Payable | Current Liability |
| 2121 | NSSF Payable | Current Liability |
| 2122 | SDL Payable | Current Liability |
| 2123 | WCF Payable | Current Liability |
| 2124 | OSHA Payable | Current Liability |
| 2125 | Health Insurance Payable | Current Liability |
| 2126 | PAYE Payable | Current Liability |
| 2141 | CMSA Fee Payable | Current Liability |
| 2142 | CSD&R Fee Payable | Current Liability |
| 2143 | DSE Fee Payable | Current Liability |
| 2144 | VRF Fee Payable | Current Liability |
| 221 | Long-term Loans | Non-Current Liability |
| 222-224 | Other Non-Current Liabilities | Non-Current Liability |
| 223 | Deferred Tax | Non-Current Liability |

### Equity Accounts (3xxx)

| Code | Name | Type |
|------|------|------|
| 31 | Share Capital | Equity |
| 32 | Additional Paid-in Capital | Equity |
| 33 | Retained Earnings | Equity |
| 34-35 | Other Equity Items | Equity |

### Income Accounts (4xxx)

| Code | Name | Type |
|------|------|------|
| 411 | Brokerage Commission Income | Income |
| 412 | Advisory Fees | Income |
| 413 | Portfolio Management Fees | Income |
| 414 | Custodial Fees | Income |
| 421 | Interest Income | Income |
| 422 | Dividend Income | Income |
| 423 | FX Gain | Income |
| 424 | Gain on Disposal of Assets | Income |

### Expense Accounts (5xxx)

| Code | Name | Type |
|------|------|------|
| 511 | Salaries & Wages | Expense |
| 512 | Staff Benefits | Expense |
| 521 | Rent & Utilities | Expense |
| 522 | Professional Fees | Expense |
| 523 | Compliance & Legal | Expense |
| 524 | Market Data & Systems | Expense |
| 531 | Depreciation | Expense |
| 532 | Amortization | Expense |
| 541 | Interest Expense | Expense |
| 542 | Lease Interest (IFRS 16) | Expense |
| 551 | Current Tax | Expense |
| 552 | Deferred Tax | Expense |
| 561 | CMSA Fees | Expense |
| 562 | DSE Fees | Expense |
| 563 | CSDR Fees | Expense |
| 564 | VRF Fees | Expense |

### Control Accounts (7xxx)

| Code | Name | Type |
|------|------|------|
| 72114 | Custodians Control | Asset |
| 72714 | Brokers Control | Asset |
| 72715 | Employees Control | Asset |
| 73101 | Clients Control | Asset |
| 73111 | Agents Control | Asset |
| 73113 | Nominal Clients Control | Asset |

---

## Appendix B: Receipt Number Formats

| Prefix | Module | Format | Example |
|--------|--------|--------|---------|
| RCP | receipt.php | RCPYYYYMMDDXXXX | RCP202512210001 |
| MTP | upload_mtp.php | MTPYYYYMMDDXXXX | MTP202512210001 |
| PMT | payment.php | PMTYYYYMMDDXXXX | PMT202512210001 |
| BPMT | payment.php (bulk) | BPMTYYYYMMDDXXXX | BPMT202512210001 |
| JRN | journal_entries.php | JRNLYYYYMMDDXXXX | JRN202512210001 |

---

## Appendix C: Currency Support

| Code | Symbol | Name |
|------|--------|------|
| Tsh | TZS | Tanzanian Shilling |
| Ksh | KSh | Kenyan Shilling |
| USD | $ | US Dollar |
| UGsh | UGX | Ugandan Shilling |

---

## Appendix D: Role-Based Access Control

| Role | Level | Access |
|------|-------|--------|
| system_admin | 5 | Full access to all modules |
| ceo | 4 | Full access to all modules |
| finance_manager | 3 | Finance module + reports |
| finance_officer | 2 | Finance module (restricted) |
| accountant | 1 | Read-only access |
| hr_manager | 3 | HR + payroll (no finance write) |
| hr_officer | 2 | HR + payroll (restricted) |
| trader | 1 | Trade module only |

---

## Appendix E: Testing Checklist

### Receipt Processing
- [ ] Create receipt with valid data
- [ ] Verify GL entry creation
- [ ] Verify bank balance update
- [ ] Test with record_in_financial = 'no'
- [ ] Test with money_distribution = 'yes'
- [ ] Test amount validation (zero, negative, >999M)
- [ ] Test invalid currency rejection
- [ ] Test duplicate receipt number prevention

### Payment Processing
- [ ] Create single payment
- [ ] Create bulk payment
- [ ] Verify statutory account grouping
- [ ] Verify trade settlement update
- [ ] Test duplicate prevention

### MTP Upload
- [ ] Upload valid CSV
- [ ] Verify client creation
- [ ] Verify duplicate detection
- [ ] Test with invalid CSV format
- [ ] Test with missing required fields

### Financial Reports
- [ ] Generate balance sheet
- [ ] Verify balance check (assets = liabilities + equity)
- [ ] Generate income statement
- [ ] Verify financial ratios
- [ ] Generate cash flow statement
- [ ] Verify three-activity classification
- [ ] Generate equity statement
- [ ] Verify beginning/ending balance calculation
- [ ] Export to PDF/Excel/CSV

### Bank Reconciliation
- [ ] Select bank account
- [ ] Verify transaction display
- [ ] Test reconciliation formula
- [ ] Verify running balance calculation

### Aging Analysis
- [ ] Verify aging bucket calculation
- [ ] Test with various date ranges
- [ ] Verify entity type filtering
- [ ] Test PDF export

### Security
- [ ] Verify CSRF token validation
- [ ] Verify role-based access
- [ ] Verify SQL injection prevention
- [ ] Verify XSS prevention
- [ ] Verify security headers
