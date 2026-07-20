# StockEx — Stock Exchange Management System

A full-stack stock exchange and brokerage management platform built for **Neovam LTD / Victory Financial Services LTD**. The system handles trade management (equities & bonds), financial accounting (double-entry general ledger, financial statements), human resources (employee lifecycle, payroll, leave, recruitment), CEO approval workflows, client management, fee/commission configuration, document management, an AI-powered chatbot assistant, a comprehensive reporting suite, and a RESTful API for programmatic data access.

---

## Architecture Overview

The application follows a **hybrid architecture**:

- **PHP Backend** — Serves all business logic, database interactions, authentication, and dynamic HTML pages. Procedural and OOP patterns using PDO/MySQL.
- **Next.js Frontend** — Modern React 19 application with Tailwind CSS v4 and shadcn/ui components, providing a contemporary UI layer.
- **REST API** — Read-only JSON API over ~90 database tables with pagination, filtering, search, and sorting.

```
User (Browser)
   │
   ├── PHP Backend (index.php → modules/*.php)
   │   ├── /auth/         Authentication & session management
   │   ├── /trader/       Trade entry, dealing sheets, settlement
   │   ├── /finance/      Accounting, GL, financial statements
   │   ├── /hr/           HR management, payroll, leave, recruitment
   │   ├── /ceo/          CEO dashboard and approval workflows
   │   ├── /admin/        System administration and configuration
   │   ├── /reports/      Reporting and PDF generation
   │   ├── /documents/    Document repository
   │   └── /chatbot/      AI-powered financial assistant
   │
   ├── Next.js App (localhost:3000)
   │   └── shadcn/ui components, Recharts, Tailwind CSS v4
   │
   └── REST API (/api/v1/)
       └── JSON endpoints for tables, schema, stock prices, etc.
```

---

## Features

### Trading Module
- **Equity & Bond Trading** — Entry, editing, and management of trades for both equities and bonds
- **Dealing Sheets** — Comprehensive dealing sheet with profit/loss calculations, settlement tracking
- **Order Intake** — Order sheet management with client order capture
- **Bulk Upload** — CSV/Excel import of trades with validation and duplicate detection
- **Settlement Tracking** — Settlement contract generation, CSD reconciliation, missing trade detection
- **Contract Notes** — Automated generation and email delivery of contract notes (PDF via TCPDF/FPDF)
- **Bond Calculator** — Yield-to-maturity, price, and duration calculations
- **AI Ticket Detection** — Automated flagging of anomalous trades

### Finance & Accounting
- **Chart of Accounts** — Hierarchical GL account structure with configurable mappings
- **Journal Entries** — Double-entry journal posting with audit trail
- **Financial Statements** — Balance Sheet, Income Statement, Cash Flow Statement, Equity Statement
- **Receipts & Payments** — Receipt generation (PDF), payment processing, MTP upload
- **Bank Management** — Multi-bank account tracking and reconciliation
- **Debtors & Creditors** — Inflow/outflow tracking, aging analysis
- **Regulatory Fees** — Automated fee assignment and commission account resolution
- **Trial Balance** — Real-time trial balance with drill-down to individual entries

### Human Resources
- **Employee Management** — Full employee lifecycle (onboarding, changes, termination)
- **Leave Management** — Multi-stage approval workflow (HR → CEO) with leave types, balances, and calendars
- **Payroll** — Salary setup, payroll calculation, salary preview, payment processing with statutory deductions
- **Recruitment** — Job openings, applicant tracking, application management
- **Performance Targets** — KPI/goal setting with progress tracking
- **HR Reports** — Statutory deductions reports, headcount analysis, payroll summaries

### CEO Dashboard
- **Approval Workflows** — Centralized approval for leave, payroll, recruitment, targets, employee changes
- **Oversight** — Cross-module visibility into all operations
- **User Management** — Create, disable, and manage system users

### AI Chatbot
- **Natural Language Queries** — Ask questions about financial data in plain English
- **Intent Matching** — Rule-based intent detection with entity extraction
- **Knowledge Base** — Populatable knowledge base for system documentation
- **Session Management** — Persistent conversation context

### Reporting
- **PDF Generation** — Contract notes, invoices, receipts, financial reports (TCPDF + FPDF)
- **Advanced Reports** — Custom report builder with filtering and aggregation
- **Commissions** — Commission calculation and reporting by broker/trader
- **General Ledger** — GL report with period filtering and account drill-down
- **Statutory Reports** — PAYE, NSSF, NHIF, and other statutory deduction reports

### REST API
- **Table Querying** — Query any of ~90 database tables with pagination, filtering, sorting, and full-text search
- **Schema Discovery** — Retrieve table schemas, column types, and relationships
- **Stock Prices** — Current and historical stock price data
- **Contract Notes** — Programmatic contract note generation
- **Notifications** — System notification retrieval
- **User Context** — Current user information and permissions

### Document Repository
- **Department Folders** — Organized document storage by department
- **Upload/Download** — File upload with access controls, secure download
- **In-Browser Viewing** — Document preview where supported

---

## Technology Stack

### Backend
| Technology | Purpose |
|---|---|
| **PHP 8.0+** | Application logic, page rendering, API |
| **MySQL / MariaDB** | Relational database via PDO |
| **Apache** | Web server with mod_rewrite |
| **PHPMailer** | Email delivery with DKIM signing |
| **TCPDF / FPDF** | PDF generation |

### Frontend
| Technology | Purpose |
|---|---|
| **Next.js 15** (App Router) | React framework |
| **React 19** | UI components |
| **TypeScript 5** | Type safety |
| **Tailwind CSS v4** | Utility-first styling |
| **shadcn/ui** (New York) | Accessible UI component library |
| **Recharts** | Data visualization |
| **Zod + React Hook Form** | Form validation |
| **date-fns** | Date utilities |
| **lucide-react** | Icons |
| **next-themes** | Dark/light mode |

### Tooling
- **pnpm** — Package manager
- **PostCSS** — CSS processing pipeline

---

## Database Schema

The database (`stockex_exchange_new_db`) consists of ~90+ tables across multiple domains:

| Domain | Key Tables |
|---|---|
| **Users & Auth** | `users`, `user_sessions`, `user_roles` |
| **Trading** | `trades`, `equities`, `bonds`, `bond_auctions`, `trade_receipts`, `trade_invoices` |
| **Companies** | `companies`, `company_types`, `sectors` |
| **Clients** | `clients`, `client_accounts`, `client_fees` |
| **Finance** | `chart_of_accounts`, `journal_entries`, `journal_entry_items`, `receipts`, `payments`, `banks` |
| **HR** | `employees`, `departments`, `leave_requests`, `payroll`, `payroll_items`, `job_openings`, `job_applications`, `targets` |
| **Approvals** | `approval_requests`, `approval_stages`, `approval_audit_trail` |
| **Fees** | `fee_configuration`, `commission_rates`, `regulatory_fees` |
| **Notifications** | `notifications`, `notification_recipients` |
| **System** | `system_settings`, `audit_log`, `master_data`, `lookups` |

Migration scripts are located in the `/database/` directory and should be applied in sequence starting with `schema.sql`.

---

## Installation

### Prerequisites

- PHP 8.0+ (with `pdo_mysql`, `mbstring`, `gd`, `openssl` extensions)
- MySQL 8.0+ or MariaDB 10.5+
- Apache with `mod_rewrite` enabled
- Node.js 18+ and pnpm (`npm install -g pnpm`)
- Composer (optional, for dependency updates)

### Step 1: Clone & Configure

```bash
git clone <repository-url> /var/www/stockex
cd /var/www/stockex
```

### Step 2: Database Setup

```sql
CREATE DATABASE stockex_exchange_new_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Apply migration files in order:

```bash
mysql -u root -p stockex_exchange_new_db < database/schema.sql
mysql -u root -p stockex_exchange_new_db < database/hr_schema.sql
mysql -u root -p stockex_exchange_new_db < database/master_data_schema.sql
mysql -u root -p stockex_exchange_new_db < database/fee_configuration_schema.sql
mysql -u root -p stockex_exchange_new_db < database/approval_workflow_final.sql
# Apply remaining migrations as needed
```

### Step 3: Configure PHP

Edit `config/database.php` with your MySQL credentials:

```php
private $host     = 'localhost';
private $db_name  = 'stockex_exchange_new_db';
private $username = 'root';
private $password = '';
```

Update `config/config.php` with the correct `BASE_URL`:

```php
define('BASE_URL', 'http://localhost/stockex');
```

### Step 4: Set Up Frontend

```bash
pnpm install
pnpm dev      # Development server on http://localhost:3000
```

For production:

```bash
pnpm build
pnpm start
```

### Step 5: Web Server

Ensure Apache serves the application and `mod_rewrite` is active. The included `.htaccess` handles clean URL rewriting. If using the built-in PHP server:

```bash
php -S localhost:8080
```

### Step 6: Verification

1. Navigate to `http://localhost/stockex/`
2. Log in with default credentials (created by `schema.sql` or run `php setup/create_admin.php`)
3. Default admin credentials: `admin` / `admin123`
4. The API is available at `http://localhost/stockex/api/`

---

## Configuration Reference

| File | Key Settings |
|---|---|
| `config/config.php` | `BASE_URL`, `UPLOAD_PATH`, `MAX_FILE_SIZE`, `TIMEZONE`, `AVAILABLE_DEPARTMENTS` |
| `config/database.php` | MySQL host, database name, username, password |
| `config/email.php` | SMTP host, port, encryption, credentials, DKIM keys |
| `config/account_mapping.php` | GL account code mappings for trade accounting |
| `config/approval_constants.php` | Workflow status codes for approvals |
| `api/config.php` | API version, pagination defaults, table whitelist |
| `next.config.mjs` | Next.js build settings, ESLint strictness |

---

## Modules Overview

### Directory Structure

```
├── admin/           System administration (users, companies, fees, master data)
├── api/             REST API v1 endpoints
├── app/             Next.js App Router (React frontend)
├── assets/          Static CSS, JS, images
├── auth/            Login, logout, registration, middleware
├── ceo/             CEO dashboard and approval management
├── chatbot/         AI financial chatbot engine and interface
├── components/      shadcn/ui React components
├── config/          Application configuration files
├── database/        SQL migration scripts
├── documents/       Document repository (upload/download)
├── finance/         Accounting, financial statements, receipts
├── hr/              HR management, payroll, leave, recruitment
├── includes/        Shared PHP helpers (header, footer, approval, bond, dealing sheet)
├── lib/             TypeScript utilities
├── phpmailer/       PHPMailer library
├── public/          Static public assets
├── reports/         Reporting engine, PDF generation (TCPDF/FPDF)
├── setup/           Setup scripts (admin creation)
├── tcpdf/           TCPDF library
├── tests/           Test suite
├── trader/          Trading module (equities, bonds, settlement)
└── uploads/         File uploads directory
```

### Role-Based Access

| Role | Accessible Modules |
|---|---|
| `system_admin` | Admin dashboard, all settings, user management |
| `ceo` | CEO dashboard, approvals, cross-module oversight |
| `trader` | Trading module, dealing sheets, trade entry |
| `finance_officer` | Finance module, accounting, receipts, reports |
| `hr_manager` / `hr_officer` | HR module, payroll, leave, recruitment |

---

## API Usage

The REST API is available at `/api/v1/`. All endpoints return JSON.

**List available tables:**
```bash
GET /api/v1/tables.php
```

**Query a table:**
```bash
GET /api/v1/index.php?table=trades&page=1&per_page=50
GET /api/v1/index.php?table=users&filters={"role":"trader"}&sort=created_at:desc
```

**Search across a table:**
```bash
GET /api/v1/index.php?table=companies&search=neovam
```

**Get table schema:**
```bash
GET /api/v1/schema.php?table=trades
```

**Stock prices:**
```bash
GET /api/v1/stock_prices.php?symbol=XYZ
```

See `/api/docs/API_DOCUMENTATION.md` for the complete API reference.

---

## Running Tests

```bash
php tests/test_trade_accounting.php
```

This runs unit tests for trade upload accounting logic including name normalization, charge alias mapping, and commission account resolution.

---

## Development

### PHP Backend

Coding conventions:
- PSR-12 inspired style (but not strictly enforced)
- PDO prepared statements for all database queries
- Procedural pages co-existing with OOP helper classes
- Error handling via `try/catch` with `PDOException`
- `config/config.php` contains global sanitization and helper functions

### Next.js Frontend

```bash
pnpm dev        # Hot-reload dev server
pnpm build      # Production build
pnpm lint       # Run ESLint
pnpm start      # Production server
```

Component conventions:
- shadcn/ui components in `components/ui/`
- Custom hooks in `hooks/`
- Utility functions in `lib/`
- Tailwind CSS v4 with `@tailwindcss/postcss`

### Adding a New Database Table

1. Create the migration SQL in `database/`
2. Add the table to the whitelist in `api/config.php` (if it should be API-accessible)
3. Add any GL account mappings in `config/account_mapping.php` (if finance-related)

---

## Deployment

### Production Checklist

- [ ] Set `error_reporting(0)` in `config/config.php`
- [ ] Configure real SMTP credentials in `config/email.php`
- [ ] Change default admin password immediately
- [ ] Set strong MySQL credentials (not root with empty password)
- [ ] Enable HTTPS via Let's Encrypt or your CA
- [ ] Configure `.env` file support if migrating from hardcoded values
- [ ] Run `pnpm build && pnpm start` for the Next.js frontend
- [ ] Set up cron jobs for:
  - Payroll processing (`php hr/pay_salary.php`)
  - Email reminders for pending approvals
  - Data backup

---

## Email Configuration

The system uses PHPMailer with SMTP and DKIM signing for transactional emails (contract notes, approval notifications, password resets).

Configure SMTP in `config/email.php`:

```php
$mail->Host       = 'mail.yourdomain.com';
$mail->Port       = 587;
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Username   = 'noreply@yourdomain.com';
$mail->Password   = 'your-smtp-password';
```

For DKIM, generate a key pair and configure the DKIM settings in the same file.

---

## License

Proprietary — All rights reserved. Built for **Neovam LTD / Victory Financial Services LTD**. Internal use only unless otherwise authorized.

---

## Support

For issues, bug reports, or feature requests, please contact the development team or open an issue in the project repository.
