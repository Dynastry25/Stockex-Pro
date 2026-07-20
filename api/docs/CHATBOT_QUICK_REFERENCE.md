# Quick Reference Guide for Chatbot Integration

## Base URL
```
/api/v1/
```

## Available Endpoints

### 1. List All Tables
```
GET /api/v1/tables.php
```
Returns all 90 queryable tables organized by category.

### 2. Get Table Schema
```
GET /api/v1/schema.php?table={table_name}
```
Returns column information for a specific table.

### 3. Query Data (Main Endpoint)
```
GET /api/v1/index.php?table={table_name}[&parameters]
```

## Essential Parameters

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `table` | **Required** - Table to query | `table=clients` |
| `id` | Get specific record | `id=123` |
| `search` | Search across all columns | `search=john` |
| `{column}` | Filter by column value | `status=active` |
| `page` | Page number | `page=2` |
| `limit` | Records per page (max 500) | `limit=50` |
| `sort_by` | Column to sort by | `sort_by=date` |
| `sort_order` | ASC or DESC | `sort_order=DESC` |

## Response Structure

### Success Response
```json
{
  "success": true,
  "status_code": 200,
  "message": "...",
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

### Error Response
```json
{
  "success": false,
  "status_code": 400,
  "error": "Error message",
  "timestamp": "..."
}
```

## Common Query Patterns

### Get All Records
```
GET /api/v1/index.php?table=clients&limit=100
```

### Get By ID
```
GET /api/v1/index.php?table=clients&id=5
```

### Search
```
GET /api/v1/index.php?table=trades&search=apple
```

### Filter
```
GET /api/v1/index.php?table=trades&status=completed&trade_type=buy
```

### Sort
```
GET /api/v1/index.php?table=trades&sort_by=trade_date&sort_order=DESC
```

### Complex Query
```
GET /api/v1/index.php?table=trades&search=AAPL&status=completed&sort_by=trade_date&sort_order=DESC&page=1&limit=20
```

## Table Categories

### Accounting
`account_categories`, `balance_sheet_items`, `chart_of_accounts`, `general_ledger`, `journal_entries`, `trial_balance`, etc.

### Trading
`trades`, `bonds`, `equities`, `brokers`, `custodians`, `bond_auctions`, `etf_trades`, etc.

### Clients
`clients`, `customers`, `agents`, `client_merge_log`

### Employees & HR
`employees`, `payroll`, `leave_requests`, `departments`, `positions`, `employee_benefits`, `salary_history`, etc.

### Financial
`payments`, `receipts`, `banks_accounts`, `fee_configurations`, `transaction`, `transaction_types`

### System
`approval_workflows`, `audit_trail`, `security_logs`, `system_settings`, `documents`, `users`

## Chatbot Translation Examples

### User: "Show me all clients"
```
GET /api/v1/index.php?table=clients&limit=100
```

### User: "Find Apple trades"
```
GET /api/v1/index.php?table=trades&search=apple
```

### User: "Get client with ID 42"
```
GET /api/v1/index.php?table=clients&id=42
```

### User: "Show recent payroll records"
```
GET /api/v1/index.php?table=payroll&sort_by=pay_date&sort_order=DESC&limit=20
```

### User: "Find completed buy trades"
```
GET /api/v1/index.php?table=trades&status=completed&trade_type=buy
```

### User: "Search for employee named John"
```
GET /api/v1/index.php?table=employees&search=john
```

### User: "Show me bonds sorted by interest rate"
```
GET /api/v1/index.php?table=bonds&sort_by=interest_rate&sort_order=DESC
```

## Error Handling

| Status Code | Meaning | Chatbot Response |
|-------------|---------|------------------|
| 200 | Success | Return formatted data |
| 400 | Bad request | "I need more information. Please specify..." |
| 404 | Not found | "I couldn't find that record" |
| 405 | Method not allowed | "Only read operations are supported" |
| 500 | Server error | "Something went wrong. Please try again" |

## Best Practices

1. **Cache table list** - Call `/tables.php` once and store results
2. **Validate tables** - Check if table exists before querying
3. **Use appropriate limits** - Don't fetch more than needed
4. **Handle pagination** - Check `total_pages` for large results
5. **Format nicely** - Present data in readable format, not raw JSON
6. **Provide context** - Tell users what data you're showing

## Implementation Checklist

- [ ] Fetch and cache available tables
- [ ] Parse user intent (what table, what filters)
- [ ] Build query URL with appropriate parameters
- [ ] Make HTTP GET request
- [ ] Check `success` field in response
- [ ] Handle errors gracefully
- [ ] Format data for presentation
- [ ] Handle pagination if needed

## Sample Code Snippet

```python
import requests

def query_database(table, **kwargs):
    base_url = "http://yourdomain.com/api/v1/index.php"
    params = {"table": table, **kwargs}
    
    response = requests.get(base_url, params=params)
    data = response.json()
    
    if data["success"]:
        return data["data"]
    else:
        raise Exception(data["error"])

# Usage examples
clients = query_database("clients", limit=50)
trade = query_database("trades", id=100)
search_results = query_database("employees", search="john")
filtered = query_database("trades", status="completed", limit=20)
```

## Security Notes

- Only GET requests are allowed
- All inputs are sanitized
- Table names are validated against whitelist
- SQL injection prevented via PDO prepared statements
- Consider adding API keys for production use

---

**Quick Tip:** Start every chatbot interaction by calling `/api/v1/tables.php` once to understand available data sources.
