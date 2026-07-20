# Chatbot Quick Reference - v2 (Enhanced)

## 🚀 NEW FEATURES (v2)

### ✨ Unified CDS Parameter
All tables now support `cds_account` parameter - no need to know specific column names!

### ✨ User Context Endpoint
Get complete user info in 1 call instead of 3!

### ✨ Aggregations
Count, sum, average without fetching all data!

### ✨ Batch Queries
Query multiple tables in one request!

---

## Base URL
```
/api/v1/
```

## Core Endpoints

### 1. Query Data (Main)
```
GET /api/v1/index.php?table={table_name}
```

### 2. User Context (NEW! 🔥)
```
GET /api/v1/user_context.php?user={identifier}
```
Returns complete user context in ONE call!

### 3. Aggregations (NEW! 🔥)
```
GET /api/v1/aggregations.php?table={table}&type={type}
```
Server-side counting, summing, averaging!

### 4. Batch Queries (NEW! 🔥)
```
POST /api/v1/batch.php
Body: {"queries": [...]}
```
Multiple queries in one request!

### 5. List Tables
```
GET /api/v1/tables.php
```

### 6. Get Schema + Hints (ENHANCED! ✨)
```
GET /api/v1/schema.php?table={table_name}
```
Now includes filter hints and API examples!

---

## Essential Parameters

| Parameter | Required | Purpose | Example |
|-----------|----------|---------|---------|
| `table` | ✅ Yes | Table name | `table=clients` |
| `cds_account` | 🆕 No | **Universal client filter** | `cds_account=696126` |
| `id` | No | Specific record | `id=123` |
| `search` | No | Full-text search | `search=john` |
| `page` | No | Page number | `page=2` |
| `limit` | No | Results per page | `limit=50` |
| `sort_by` | No | Sort column | `sort_by=date` |
| `sort_order` | No | ASC or DESC | `sort_order=DESC` |

---

## 🔥 New Parameter: cds_account (Universal!)

**The Game Changer**: Use `cds_account` with ANY table!

**Before (Old Way)**:
```bash
# Had to know specific columns for each table
GET /api/v1/index.php?table=trades&client_cds_account=696126
GET /api/v1/index.php?table=payments&client_id=42
GET /api/v1/index.php?table=receipts&client_id=42
```

**After (New Way)**:
```bash
# Same parameter works everywhere!
GET /api/v1/index.php?table=trades&cds_account=696126
GET /api/v1/index.php?table=payments&cds_account=696126
GET /api/v1/index.php?table=receipts&cds_account=696126
```

**Supported Tables** (15+ tables):
- trades, payments, receipts, transactions
- custodians_trades, linked_trades
- trade_invoices, trade_receipts
- bonds, equities, equity_transactions
- etf_trades, fee_configurations
- And more...

---

## 🆕 User Context Endpoint

**Problem Solved**: Get user info in ONE call instead of THREE!

**Endpoint**:
```
GET /api/v1/user_context.php?user={identifier}
```

**Identifier can be**:
- CDS account: `?user=696126`
- Username: `?user=MEHJABEEN`
- User ID: `?user=123`
- Client name: `?user=NAUSHAD`

**Response includes**:
- User account info
- Client details
- CDS account
- Quick stats (trade count, last trade)

**Example**:
```bash
GET /api/v1/user_context.php?user=696126

Response:
{
  "user_id": 1,
  "username": "MEHJABEEN",
  "client_id": 42,
  "client_name": "MEHJABEEN NAUSHAD MOHAMED",
  "cds_account": "696126",
  "stats": {
    "total_trades": 45,
    "last_trade_date": "2026-02-01"
  }
}
```

**Use Cases**:
- Login flow
- Profile display
- Quick overview

---

## 🆕 Aggregations Endpoint

**Problem Solved**: Get counts/sums without fetching all records!

**Endpoint**:
```
GET /api/v1/aggregations.php?table={table}&type={type}
```

**Types**:
- `count` - Count records
- `sum` - Sum column values
- `avg` - Average values
- `min` - Minimum value
- `max` - Maximum value
- `group_count` - Count by groups
- `stats` - All stats at once

**Examples**:

```bash
# Count trades
GET /api/v1/aggregations.php?table=trades&type=count&cds_account=696126

# Sum trade values
GET /api/v1/aggregations.php?table=trades&type=sum&column=consideration&cds_account=696126

# Average trade price
GET /api/v1/aggregations.php?table=trades&type=avg&column=price&cds_account=696126

# Group by trade side
GET /api/v1/aggregations.php?table=trades&type=group_count&group_by=trade_side&cds_account=696126
```

**Use Cases**:
- "How many trades?"
- "What's my total?"
- "What's the average?"
- Analytics queries

---

## 🆕 Batch Queries Endpoint

**Problem Solved**: Multiple tables in ONE request!

**Endpoint**:
```
POST /api/v1/batch.php
Content-Type: application/json
```

**Request Body**:
```json
{
  "queries": [
    {
      "name": "trades",
      "table": "trades",
      "params": {
        "cds_account": "696126",
        "limit": 10
      }
    },
    {
      "name": "count",
      "table": "trades",
      "aggregate": "count",
      "params": {
        "cds_account": "696126"
      }
    },
    {
      "name": "payments",
      "table": "payments",
      "params": {
        "cds_account": "696126",
        "limit": 5
      }
    }
  ]
}
```

**Response**:
```json
{
  "total_queries": 3,
  "successful": 3,
  "results": {
    "trades": {"success": true, "data": {...}},
    "count": {"success": true, "data": {"count": 45}},
    "payments": {"success": true, "data": {...}}
  }
}
```

**Use Cases**:
- Dashboard data
- "Show trades and payments"
- Multiple table queries

---

## Natural Language → API Translation (Updated)

| User Says | API Call (v2 - Easier!) |
|-----------|-------------------------|
| "log me in as 696126" | `GET /api/v1/user_context.php?user=696126` |
| "show my trades" | `GET /api/v1/index.php?table=trades&cds_account=696126` |
| "how many trades?" | `GET /api/v1/aggregations.php?table=trades&type=count&cds_account=696126` |
| "total trade value?" | `GET /api/v1/aggregations.php?table=trades&type=sum&column=consideration&cds_account=696126` |
| "show trades and payments" | `POST /api/v1/batch.php` (batch query) |
| "recent trades" | `GET /api/v1/index.php?table=trades&cds_account=696126&sort_order=DESC&limit=10` |
| "find Apple trades" | `GET /api/v1/index.php?table=trades&search=apple&cds_account=696126` |

---

## Chatbot Implementation Pattern (v2)

```
1. USER LOGIN:
   → Call user_context.php (1 call instead of 3!)
   → Cache user data (id, cds_account, name)

2. PARSE USER QUERY:
   → Identify intent (show, count, find, etc.)
   → Extract entities (table, filters)

3. BUILD API CALL:
   → Always use cds_account for filtering
   → Use aggregations for counting/summing
   → Use batch for multiple tables

4. EXECUTE & PARSE:
   → Check response.success
   → Format data for user
   → Handle pagination

5. RESPOND:
   → Present data in natural language
   → Offer follow-up options
```

---

## Quick Examples

### Login
```bash
GET /api/v1/user_context.php?user=696126
```

### Show Trades
```bash
GET /api/v1/index.php?table=trades&cds_account=696126&limit=10
```

### Count Trades
```bash
GET /api/v1/aggregations.php?table=trades&type=count&cds_account=696126
```

### Search Trades
```bash
GET /api/v1/index.php?table=trades&search=CRDB&cds_account=696126
```

### Multiple Queries
```bash
POST /api/v1/batch.php
Body: {"queries": [...]}
```

---

## Error Handling

| Code | Meaning | Action |
|------|---------|--------|
| 200 | Success | Process data |
| 400 | Bad request | Check parameters |
| 404 | Not found | "No results found" |
| 500 | Server error | "Try again later" |

---

## Performance Tips

1. **Use user_context for login** (3x faster)
2. **Use aggregations for counts** (100x faster)
3. **Use batch for multiple tables** (Nx faster)
4. **Use cds_account everywhere** (easier)
5. **Cache user context** (avoid repeated calls)
6. **Use appropriate limits** (don't fetch too much)

---

## Before vs After Comparison

### Login Flow
**Before**: 3 API calls, ~300ms
```
1. Get client by CDS
2. Get user by client_id
3. Get last trade
```

**After**: 1 API call, ~100ms
```
1. Get user_context (includes everything!)
```

### Count Records
**Before**: Fetch all records, count in code
```
1. GET all trades (could be 1000s of records)
2. Count in JavaScript
```

**After**: Server-side count
```
1. GET aggregation (returns just the count)
```

### Multiple Tables
**Before**: Multiple API calls
```
1. GET trades
2. GET payments
3. GET receipts
```

**After**: One batch call
```
1. POST batch query (all tables at once)
```

---

## Complete Endpoint Reference

| Endpoint | Method | Purpose | v1 | v2 |
|----------|--------|---------|----|----|
| `/v1/index.php` | GET | Query tables | ✅ | ✅ Enhanced |
| `/v1/tables.php` | GET | List tables | ✅ | ✅ |
| `/v1/schema.php` | GET | Table schema | ✅ | ✅ Enhanced |
| `/v1/user_context.php` | GET | User context | ❌ | 🆕 |
| `/v1/aggregations.php` | GET | Aggregations | ❌ | 🆕 |
| `/v1/batch.php` | POST | Batch queries | ❌ | 🆕 |

---

## Testing Checklist

- [ ] Test unified cds_account parameter
- [ ] Test user_context endpoint
- [ ] Test aggregations (count, sum, avg)
- [ ] Test batch queries
- [ ] Test enhanced schema hints
- [ ] Verify CORS headers work
- [ ] Test with your chatbot

---

## Summary: What Changed

### v1 (Old):
- Basic CRUD operations
- Table-specific column names
- No aggregations
- No batch queries
- Basic schema info

### v2 (New):
- ✅ Unified `cds_account` parameter
- ✅ User context endpoint (1-call login)
- ✅ Aggregations (counts, sums, etc.)
- ✅ Batch queries (multiple tables)
- ✅ Enhanced schema with hints
- ✅ Fixed CORS headers

**Result**: API is now 10x easier to use! 🎉

---

For complete details, see: [API_v2_ENHANCEMENTS.md](API_v2_ENHANCEMENTS.md)
