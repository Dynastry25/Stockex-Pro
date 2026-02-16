# Chatbot API Access Instructions

## API Endpoint Format
```
Base URL: /api/v1/
Main Endpoint: /api/v1/index.php
Required Parameter: table={table_name}
```

## Available Tables (90 Total)

### Core Business Tables
- **clients** - Client information
- **trades** - Trade transactions  
- **employees** - Employee records
- **bonds** - Bond securities
- **equities** - Equity/stock securities
- **payments** - Payment records
- **receipts** - Receipt records
- **payroll** - Payroll data
- **leave_requests** - Employee leave requests
- **accounts** - Account information
- **transactions** - Transaction records
- **users** - User accounts

### Financial/Accounting
- chart_of_accounts
- general_ledger
- journal_entries
- trial_balance
- balance_sheet_items
- income_statement_items
- account_categories

### Trading Related
- brokers
- custodians
- bond_auctions
- bond_issuers
- etf_trades
- equity_transactions
- trade_invoices

### HR/Employee
- departments
- positions
- performance_reviews
- salary_history
- employee_benefits
- employee_targets

### System
- audit_trail
- approval_workflows
- documents
- system_settings

## Query Syntax

### Basic Query
```
GET /api/v1/index.php?table={table_name}
```

### Get by ID
```
GET /api/v1/index.php?table={table_name}&id={number}
```

### Search
```
GET /api/v1/index.php?table={table_name}&search={keyword}
```

### Filter by Column
```
GET /api/v1/index.php?table={table_name}&{column}={value}
```

### Pagination
```
GET /api/v1/index.php?table={table_name}&page={number}&limit={number}
```

### Sorting
```
GET /api/v1/index.php?table={table_name}&sort_by={column}&sort_order=DESC
```

## Natural Language → API Translation

| User Says | API Call |
|-----------|----------|
| "show clients" | `?table=clients` |
| "list all trades" | `?table=trades` |
| "find client 5" | `?table=clients&id=5` |
| "search for apple" in trades | `?table=trades&search=apple` |
| "completed trades" | `?table=trades&status=completed` |
| "recent transactions" | `?table=transactions&sort_order=DESC` |
| "employee payroll" | `?table=payroll` |
| "bond information" | `?table=bonds` |

## Response Structure

Every response includes:
- `success` (true/false)
- `status_code` (200, 400, 404, 500)
- `message` (description)
- `data` (records + pagination)

Success response:
```json
{
  "success": true,
  "data": {
    "records": [...],
    "pagination": {
      "total_records": 100,
      "current_page": 1,
      "page_size": 50,
      "total_pages": 2
    }
  }
}
```

## Error Handling

- 200 = Success → Process data
- 400 = Bad request → Ask user for clarification
- 404 = Not found → Inform user "no records found"
- 500 = Server error → "Unable to process request"

## Chatbot Implementation Pattern

```
1. Parse user intent
2. Identify table from intent
3. Extract filters/search terms
4. Build API URL
5. Make GET request
6. Check response.success
7. Format data for user
8. Handle pagination if needed
```

## Common Use Cases

### "Show me clients"
```
GET /api/v1/index.php?table=clients&limit=50
```

### "Find trades for Apple"
```
GET /api/v1/index.php?table=trades&search=apple
```

### "Get employee with ID 10"
```
GET /api/v1/index.php?table=employees&id=10
```

### "Recent payroll records"
```
GET /api/v1/index.php?table=payroll&sort_by=pay_date&sort_order=DESC&limit=20
```

### "Completed trades sorted by date"
```
GET /api/v1/index.php?table=trades&status=completed&sort_by=trade_date&sort_order=DESC
```

### "Bond information"
```
GET /api/v1/index.php?table=bonds&limit=30
```

## Tips for AI/Chatbot

1. **Table Discovery**: Call `/api/v1/tables.php` once to cache available tables
2. **Fuzzy Matching**: Map user terms to table names (e.g., "staff" → "employees")
3. **Default Limits**: Use `limit=20` for reasonable response sizes
4. **Pagination**: Check `total_pages` and offer "show more" if > 1
5. **Validation**: Verify table exists before making query
6. **Error Messages**: Provide helpful feedback based on error codes
7. **Format Output**: Present data in readable format, not raw JSON

## Quick Reference

| Action | Parameter | Example |
|--------|-----------|---------|
| Specify table | `table=` | `table=clients` |
| Get record | `id=` | `id=123` |
| Search | `search=` | `search=john` |
| Paginate | `page=` & `limit=` | `page=2&limit=50` |
| Sort | `sort_by=` & `sort_order=` | `sort_by=date&sort_order=DESC` |
| Filter | `{column}=` | `status=active` |

## Discovery Endpoints

Get table list:
```
GET /api/v1/tables.php
```

Get table structure:
```
GET /api/v1/schema.php?table={table_name}
```

## Security Notes

- Only GET requests allowed
- All inputs sanitized
- SQL injection protected
- No authentication required (read-only)

---

**Remember**: Start with `/api/v1/tables.php` to discover available data sources!
