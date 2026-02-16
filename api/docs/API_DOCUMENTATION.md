# Stock Exchange Database API Documentation

**Version:** 1.0  
**Base URL:** `/api/v1/`  
**Format:** JSON  
**Authentication:** None (currently open - consider adding authentication in production)

---

## Overview

This API provides read-only access to the Stock Exchange Database. It supports querying all 90 tables in the database with advanced features including:

- Pagination
- Searching across all columns
- Filtering by specific columns
- Sorting by any column
- Individual record retrieval

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Available Endpoints](#available-endpoints)
3. [Common Parameters](#common-parameters)
4. [Response Format](#response-format)
5. [Error Handling](#error-handling)
6. [Example Queries](#example-queries)
7. [Available Tables](#available-tables)
8. [Chatbot Integration Guide](#chatbot-integration-guide)

---

## Quick Start

### Basic Query
```
GET /api/v1/index.php?table=clients
```

### Get Specific Record
```
GET /api/v1/index.php?table=clients&id=1
```

### Search and Filter
```
GET /api/v1/index.php?table=trades&search=apple&page=1&limit=20
```

---

## Available Endpoints

### 1. List All Available Tables
**Endpoint:** `/api/v1/tables.php`  
**Method:** GET  
**Description:** Returns a list of all queryable tables organized by category

**Example Request:**
```
GET /api/v1/tables.php
```

**Example Response:**
```json
{
  "success": true,
  "status_code": 200,
  "timestamp": "2026-01-29 10:30:00",
  "message": "Available tables retrieved successfully",
  "data": {
    "total_tables": 90,
    "categories": {
      "accounting": ["account_categories", "balance_sheet_items", "chart_of_accounts", ...],
      "trading": ["trades", "bonds", "equities", "brokers", ...],
      "clients": ["clients", "customers", "agents"],
      "employees": ["employees", "payroll", "leave_requests", ...],
      "system": ["audit_trail", "approval_workflows", "security_logs", ...]
    },
    "all_tables": ["account_categories", "agents", "bonds", ...]
  }
}
```

---

### 2. Get Table Schema
**Endpoint:** `/api/v1/schema.php`  
**Method:** GET  
**Parameters:** 
- `table` (required): Name of the table

**Example Request:**
```
GET /api/v1/schema.php?table=clients
```

**Example Response:**
```json
{
  "success": true,
  "status_code": 200,
  "timestamp": "2026-01-29 10:30:00",
  "message": "Table schema retrieved successfully",
  "data": {
    "table_name": "clients",
    "total_records": 150,
    "column_count": 25,
    "columns": [
      {
        "name": "id",
        "type": "int",
        "null": false,
        "key": "PRI",
        "default": null,
        "extra": "auto_increment"
      },
      {
        "name": "client_name",
        "type": "varchar(255)",
        "null": false,
        "key": "",
        "default": null,
        "extra": ""
      }
    ]
  }
}
```

---

### 3. Query Records (Main Endpoint)
**Endpoint:** `/api/v1/index.php`  
**Method:** GET  
**Parameters:** 
- `table` (required): Name of the table to query
- `id` (optional): Specific record ID to retrieve
- Additional parameters listed below

**Example Requests:**

#### Get All Records from a Table
```
GET /api/v1/index.php?table=trades
```

#### Get Specific Record by ID
```
GET /api/v1/index.php?table=trades&id=100
```

#### With Pagination
```
GET /api/v1/index.php?table=trades&page=2&limit=50
```

#### With Search
```
GET /api/v1/index.php?table=clients&search=john
```

#### With Filters
```
GET /api/v1/index.php?table=trades&status=completed&trade_type=buy
```

#### With Sorting
```
GET /api/v1/index.php?table=trades&sort_by=trade_date&sort_order=DESC
```

#### Combined Query
```
GET /api/v1/index.php?table=trades&search=AAPL&status=completed&page=1&limit=25&sort_by=trade_date&sort_order=DESC
```

---

## Common Parameters

All query endpoints support these parameters:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `table` | string | *required* | Name of the database table |
| `id` | integer | - | Specific record ID to retrieve |
| `page` | integer | 1 | Page number for pagination |
| `limit` | integer | 50 | Number of records per page (max: 500) |
| `search` | string | - | Search term (searches across all columns) |
| `sort_by` | string | id | Column name to sort by |
| `sort_order` | string | ASC | Sort direction (ASC or DESC) |
| `{column_name}` | string | - | Filter by specific column value |

---

## Response Format

### Success Response

```json
{
  "success": true,
  "status_code": 200,
  "timestamp": "2026-01-29 10:30:00",
  "message": "Records retrieved successfully",
  "data": {
    "records": [
      {
        "id": 1,
        "field1": "value1",
        "field2": "value2"
      }
    ],
    "pagination": {
      "total_records": 100,
      "current_page": 1,
      "page_size": 50,
      "total_pages": 2
    }
  }
}
```

### Single Record Response

```json
{
  "success": true,
  "status_code": 200,
  "timestamp": "2026-01-29 10:30:00",
  "message": "Record retrieved successfully",
  "data": {
    "id": 1,
    "field1": "value1",
    "field2": "value2"
  }
}
```

---

## Error Handling

### Error Response Format

```json
{
  "success": false,
  "status_code": 400,
  "error": "Error message here",
  "timestamp": "2026-01-29 10:30:00",
  "details": {
    "additional": "information"
  }
}
```

### Common Error Codes

| Status Code | Description |
|-------------|-------------|
| 400 | Bad Request - Invalid parameters or missing required fields |
| 404 | Not Found - Record or table does not exist |
| 405 | Method Not Allowed - Only GET requests are supported |
| 500 | Internal Server Error - Server-side error occurred |

---

## Example Queries

### 1. Get All Clients
```
GET /api/v1/index.php?table=clients&limit=100
```

### 2. Search for a Specific Trade
```
GET /api/v1/index.php?table=trades&search=AAPL
```

### 3. Get Trades for a Specific Client
```
GET /api/v1/index.php?table=trades&client_id=123
```

### 4. Get Recent Transactions
```
GET /api/v1/index.php?table=transactions&sort_by=created_at&sort_order=DESC&limit=20
```

### 5. Get Employee Payroll Records
```
GET /api/v1/index.php?table=payroll&employee_id=45&sort_by=pay_date&sort_order=DESC
```

### 6. Get Bonds by Type
```
GET /api/v1/index.php?table=bonds&bond_type_id=2
```

### 7. Get Approved Leave Requests
```
GET /api/v1/index.php?table=leave_requests&status=approved
```

### 8. Get Chart of Accounts by Category
```
GET /api/v1/index.php?table=chart_of_accounts&category_id=1&sort_by=account_code
```

---

## Available Tables

### Accounting Tables
- `account_categories` - Account category hierarchy
- `account_opening_balances` - Opening balances for accounts
- `balance_sheet_items` - Balance sheet line items
- `balance_sheet_reporting_formats` - Balance sheet format configurations
- `cashflow_components` - Cash flow statement components
- `cash_flow_formats` - Cash flow statement formats
- `chart_of_accounts` - Chart of accounts master data
- `general_ledger` - General ledger entries
- `gl_account_formats` - GL account format configurations
- `gl_transactions` - General ledger transactions
- `journal_entries` - Journal entry records
- `trial_balance` - Trial balance data
- `income_statement_items` - Income statement line items
- `ledger_types` - Types of ledgers
- `sub_ledger_categories` - Sub-ledger categories
- `sub_ledger_groups` - Sub-ledger groupings
- `sub_ledger_related_parties` - Related party sub-ledgers
- `sub_ledger_status` - Sub-ledger status types

### Trading Tables
- `trades` - Trade transactions
- `bonds` - Bond securities
- `bond_auctions` - Bond auction records
- `bond_issuers` - Bond issuing entities
- `bond_types` - Types of bonds
- `bonds_economic_sectors` - Economic sectors for bonds
- `equities` - Equity securities
- `equities_settings` - Equity trading settings
- `equity_transactions` - Equity transaction records
- `etf_trades` - ETF trading records
- `brokers` - Broker information
- `custodians` - Custodian information
- `custodians_trades` - Custodian trade records
- `linked_trades` - Linked trade relationships
- `trade_invoices` - Trade invoice records
- `trade_receipts` - Trade receipt records
- `daily_trade_sequence` - Daily trade sequencing
- `share_market_segments` - Market segment classifications
- `share_market_trends` - Market trend data
- `share_types` - Types of shares
- `coupon_determiners` - Bond coupon calculation rules
- `investment_asset_classes` - Asset class classifications
- `investments_costing_basis` - Investment costing methods

### Client Tables
- `clients` - Client master data
- `client_merge_log` - Client merge history
- `customers` - Customer records
- `agents` - Agent information
- `merged_cds_accounts` - Merged CDS account records

### Employee & HR Tables
- `employees` - Employee master data
- `employee_benefits` - Employee benefit records
- `employee_targets` - Employee performance targets
- `payroll` - Payroll records
- `payroll_incentives` - Payroll incentive records
- `payroll_items` - Payroll item definitions
- `pending_pay` - Pending payment records
- `salary_history` - Salary change history
- `leave_requests` - Leave request records
- `leave_types` - Types of leave
- `departments` - Department information
- `positions` - Job position definitions
- `job_positions` - Job posting records
- `job_applications` - Job application records
- `performance_reviews` - Performance review records
- `performance_targets` - Performance target definitions
- `target_reviews` - Target review records
- `hr_activities` - HR activity log
- `titles` - Employee title definitions

### Financial Tables
- `payments` - Payment records
- `receipts` - Receipt records
- `payment_methods` - Payment method types
- `payment_frequencies` - Payment frequency definitions
- `banks_accounts` - Bank account information
- `fee_configurations` - Fee configuration settings
- `fee_configuration_audit` - Fee configuration audit trail
- `regulatory_fee_assignments` - Regulatory fee assignments
- `transaction` - General transaction records
- `transaction_types` - Transaction type definitions
- `transaction_comments` - Transaction comment records

### System & Configuration Tables
- `approval_workflows` - Approval workflow definitions
- `approval_settings` - Approval system settings
- `approval_notifications` - Approval notification records
- `audit_trail` - System audit trail
- `security_logs` - Security event logs
- `system_settings` - System configuration settings
- `documents` - Document records
- `document_folders` - Document folder structure
- `companies` - Company information
- `suppliers` - Supplier information
- `vendors` - Vendor information
- `identity_types` - Identity document types
- `report_periods` - Reporting period definitions

### User Management
- `users` - User accounts

---

## Chatbot Integration Guide

### Overview for AI/Chatbot Systems

This API is designed to be easily integrated with AI chatbots. The chatbot can query any table in the database using natural language queries that are translated to API calls.

### Integration Pattern

1. **Identify the Intent**: Understand what data the user is requesting
2. **Map to Table**: Determine which table(s) contain the data
3. **Build Query**: Construct the appropriate API endpoint with parameters
4. **Execute Request**: Make HTTP GET request to the API
5. **Parse Response**: Extract and format the data for the user

### Example Conversation Flows

#### Example 1: Getting Client Information
**User:** "Show me all clients"

**Chatbot Action:**
```
GET /api/v1/index.php?table=clients&limit=100
```

**Chatbot Response:** "I found {total_records} clients. Here are the first {page_size} records..."

---

#### Example 2: Searching for Trades
**User:** "Find trades for Apple stock"

**Chatbot Action:**
```
GET /api/v1/index.php?table=trades&search=apple
```

**Chatbot Response:** "I found {count} trades related to Apple..."

---

#### Example 3: Getting Employee Data
**User:** "Show me recent payroll records"

**Chatbot Action:**
```
GET /api/v1/index.php?table=payroll&sort_by=pay_date&sort_order=DESC&limit=20
```

**Chatbot Response:** "Here are the 20 most recent payroll records..."

---

#### Example 4: Complex Query
**User:** "Show me completed buy trades from last month sorted by date"

**Chatbot Actions:**
1. Identify filters: `trade_type=buy`, `status=completed`
2. Identify sort: `sort_by=trade_date`, `sort_order=DESC`
3. Consider date filtering if needed

```
GET /api/v1/index.php?table=trades&trade_type=buy&status=completed&sort_by=trade_date&sort_order=DESC
```

---

### Table Discovery for Chatbots

Before answering queries, chatbots should be aware of available tables:

```
GET /api/v1/tables.php
```

This returns all available tables organized by category, helping the chatbot understand the data structure.

### Schema Discovery for Chatbots

To understand the structure of a specific table:

```
GET /api/v1/schema.php?table=clients
```

This returns:
- All column names and types
- Which columns can be filtered
- Total record count

### Query Building Guidelines for Chatbots

1. **Always specify the table parameter**
2. **Use pagination for large datasets** (limit parameter)
3. **Use search for keyword queries** across all columns
4. **Use specific column filters** when the user mentions specific fields
5. **Use sorting** when the user wants ordered results
6. **Handle pagination** when results exceed one page

### Error Handling for Chatbots

When the API returns an error (success: false):
- Check the `status_code` to understand the error type
- Read the `error` message for details
- Use the `details` object for additional context
- Provide a user-friendly explanation

Example error handling:
```javascript
if (!response.success) {
  if (response.status_code === 404) {
    return "I couldn't find that record in the database.";
  } else if (response.status_code === 400) {
    return "I need more information. " + response.error;
  }
}
```

### Best Practices for Chatbot Integration

1. **Cache table list**: Store the result from `/api/v1/tables.php` to avoid repeated calls
2. **Validate before querying**: Check if a table exists before making requests
3. **Use appropriate limits**: Don't request more data than needed
4. **Format responses**: Present data in a user-friendly format, not raw JSON
5. **Handle empty results**: Inform users when no records are found
6. **Suggest alternatives**: When a query fails, suggest valid table names or query formats

### Sample Chatbot Implementation Logic

```python
class DatabaseChatbot:
    def __init__(self, api_base_url):
        self.api_base_url = api_base_url
        self.tables = self.load_tables()
    
    def load_tables(self):
        # Cache available tables
        response = requests.get(f"{self.api_base_url}/tables.php")
        return response.json()['data']['all_tables']
    
    def query(self, user_input):
        # 1. Parse user intent
        intent = self.parse_intent(user_input)
        
        # 2. Map to table
        table = self.find_table(intent)
        
        # 3. Build query parameters
        params = self.build_params(intent)
        params['table'] = table
        
        # 4. Execute request
        response = requests.get(
            f"{self.api_base_url}/index.php",
            params=params
        )
        
        # 5. Format and return response
        return self.format_response(response.json())
```

### Quick Reference: Common Query Patterns

| User Intent | API Call Pattern |
|-------------|------------------|
| "Show me all [table]" | `?table={table}&limit=100` |
| "Find [keyword] in [table]" | `?table={table}&search={keyword}` |
| "Get [table] where [field]=[value]" | `?table={table}&{field}={value}` |
| "Show me record ID [number]" | `?table={table}&id={number}` |
| "Recent [table]" | `?table={table}&sort_order=DESC&limit=20` |
| "How many [table]?" | `?table={table}&limit=1` (check total_records in pagination) |

---

## Rate Limiting

Currently, there are no rate limits implemented. In production, consider implementing:
- Rate limiting per IP address
- API key authentication
- Request throttling

---

## Security Considerations

### Current Implementation
- Read-only access (GET only)
- Input sanitization
- SQL injection prevention via PDO prepared statements
- Table name validation

### Recommended Enhancements
1. Add API key authentication
2. Implement rate limiting
3. Add HTTPS requirement
4. Implement request logging and monitoring
5. Add user-level permissions
6. Consider data masking for sensitive fields

---

## Support & Contact

For issues, feature requests, or questions about this API:
- Check the logs in `/logs/api_access.log` and `/logs/api_errors.log`
- Review this documentation
- Contact the system administrator

---

## Changelog

### Version 1.0 (2026-01-29)
- Initial release
- Support for all 90 database tables
- GET requests only
- Pagination, search, filtering, and sorting
- Comprehensive documentation

---

## Appendix: Full cURL Examples

### Get all tables
```bash
curl "http://yourdomain.com/api/v1/tables.php"
```

### Get table schema
```bash
curl "http://yourdomain.com/api/v1/schema.php?table=clients"
```

### Get all records with pagination
```bash
curl "http://yourdomain.com/api/v1/index.php?table=trades&page=1&limit=50"
```

### Search with filters
```bash
curl "http://yourdomain.com/api/v1/index.php?table=trades&search=AAPL&status=completed&sort_by=trade_date&sort_order=DESC"
```

### Get specific record
```bash
curl "http://yourdomain.com/api/v1/index.php?table=clients&id=123"
```

---

**End of Documentation**
