# Stock Exchange Database API

A RESTful API providing read-only access to all 90 tables in the stock exchange database.

## 🆕 Version 2.0 - Major Enhancements!

**New Features**:
- ✅ **Unified CDS Parameter** - Use `cds_account` with ANY table!
- ✅ **User Context Endpoint** - Get complete user info in 1 call
- ✅ **Aggregations** - Count, sum, average without fetching all data
- ✅ **Batch Queries** - Query multiple tables in one request
- ✅ **Enhanced Schema** - Auto-discovery with query hints
- ✅ **Fixed CORS** - Direct API access, no proxy needed

**[See Full v2 Enhancement Documentation →](docs/API_v2_ENHANCEMENTS.md)**

## 📁 Directory Structure

```
api/
├── config.php              # API configuration and helper functions
├── QueryBuilder.php        # Database query builder class
├── v1/
│   ├── index.php          # Main endpoint for querying tables
│   ├── tables.php         # List all available tables
│   └── schema.php         # Get table structure
├── docs/
│   ├── API_DOCUMENTATION.md          # Complete API documentation
│   └── CHATBOT_QUICK_REFERENCE.md    # Quick guide for chatbot integration
└── README.md              # This file
```

## 🚀 Quick Start

### 1. Basic Query
```
GET /api/v1/index.php?table=clients
```

### 2. Get Specific Record
```
GET /api/v1/index.php?table=clients&id=1
```

### 3. Search and Filter
```
GET /api/v1/index.php?table=trades&search=apple&status=completed
```

## 📚 Documentation

- **Full Documentation**: See [API_DOCUMENTATION.md](docs/API_DOCUMENTATION.md)
- **Chatbot Guide**: See [CHATBOT_QUICK_REFERENCE.md](docs/CHATBOT_QUICK_REFERENCE.md)

## 🔑 Key Features

- ✅ Query all 90 database tables
- ✅ Pagination support
- ✅ Full-text search across columns
- ✅ Filter by specific columns
- ✅ Sort by any column (ASC/DESC)
- ✅ Get individual records by ID
- ✅ JSON response format
- ✅ Comprehensive error handling
- ✅ Request logging

## 📋 Available Endpoints

| Endpoint | Purpose | Version |
|----------|---------|---------|
| `/api/v1/tables.php` | List all available tables | v1 |
| `/api/v1/schema.php?table=X` | Get table structure + hints | v1 (Enhanced v2) |
| `/api/v1/index.php?table=X` | Query table data | v1 (Enhanced v2) |
| `/api/v1/user_context.php?user=X` | Get complete user context | 🆕 v2 |
| `/api/v1/aggregations.php` | Server-side aggregations | 🆕 v2 |
| `/api/v1/batch.php` | Batch multiple queries | 🆕 v2 |

## 🔧 Configuration

Database connection is configured in `/config/database.php`:
- Host: localhost
- Database: jrozqhmy_stock_exchange_db
- Uses PDO with prepared statements for security

## 🛡️ Security Features

- ✅ Read-only (GET requests only)
- ✅ Input sanitization
- ✅ SQL injection prevention (PDO prepared statements)
- ✅ Table name validation (whitelist)
- ✅ CORS headers configured
- ✅ Error logging

## 📊 Supported Tables (90 total)

### Categories:
- **Accounting** (18 tables): account_categories, chart_of_accounts, general_ledger, etc.
- **Trading** (24 tables): trades, bonds, equities, brokers, etc.
- **Clients** (4 tables): clients, customers, agents, etc.
- **Employees** (17 tables): employees, payroll, leave_requests, etc.
- **Financial** (10 tables): payments, receipts, transactions, etc.
- **System** (17 tables): audit_trail, approval_workflows, documents, etc.

See full list in documentation.

## 💡 Usage Examples

### Python
```python
import requests

response = requests.get(
    "http://yourdomain.com/api/v1/index.php",
    params={
        "table": "trades",
        "search": "apple",
        "limit": 20,
        "sort_by": "trade_date",
        "sort_order": "DESC"
    }
)

data = response.json()
if data["success"]:
    print(f"Found {data['data']['pagination']['total_records']} records")
    for record in data['data']['records']:
        print(record)
```

### JavaScript/Node.js
```javascript
const axios = require('axios');

const response = await axios.get('http://yourdomain.com/api/v1/index.php', {
  params: {
    table: 'clients',
    limit: 50,
    page: 1
  }
});

if (response.data.success) {
  console.log(`Total records: ${response.data.data.pagination.total_records}`);
  console.log(response.data.data.records);
}
```

### cURL
```bash
curl "http://yourdomain.com/api/v1/index.php?table=trades&search=AAPL&limit=10"
```

## 📝 Response Format

### Success
```json
{
  "success": true,
  "status_code": 200,
  "timestamp": "2026-01-29 10:30:00",
  "message": "Records retrieved successfully",
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

### Error
```json
{
  "success": false,
  "status_code": 400,
  "error": "Error message",
  "timestamp": "2026-01-29 10:30:00"
}
```

## 🤖 Chatbot Integration

This API is designed for easy chatbot integration. Key features for AI/chatbots:

1. **Discover tables**: `GET /api/v1/tables.php`
2. **Understand structure**: `GET /api/v1/schema.php?table=X`
3. **Query data**: Build dynamic queries based on user intent

See [CHATBOT_QUICK_REFERENCE.md](docs/CHATBOT_QUICK_REFERENCE.md) for detailed integration guide.

## 📈 Logging

Logs are stored in:
- `/logs/api_access.log` - All API requests
- `/logs/api_errors.log` - Error logs

## ⚙️ Configuration Options

In `config.php`:
- `DEFAULT_PAGE_SIZE`: Default records per page (50)
- `MAX_PAGE_SIZE`: Maximum records per page (500)
- `API_VERSION`: Current API version (v1)

## 🔒 Security Recommendations

For production deployment:
1. Add API key authentication
2. Implement rate limiting
3. Enable HTTPS only
4. Add user-level permissions
5. Mask sensitive data fields
6. Set up monitoring and alerts

## 🐛 Troubleshooting

### Common Issues

**"Database connection failed"**
- Check database credentials in `/config/database.php`
- Verify database server is running

**"Invalid table name"**
- Use `/api/v1/tables.php` to see available tables
- Table names are case-sensitive

**"Method not allowed"**
- Only GET requests are supported
- Check HTTP method

## 📞 Support

For issues or questions:
1. Review documentation in `/api/docs/`
2. Check error logs in `/logs/`
3. Contact system administrator

## 🔄 Future Enhancements

Planned features:
- POST/PUT/DELETE endpoints
- Authentication system
- Rate limiting
- Caching layer
- GraphQL support
- Webhook notifications

## 📄 License

Internal use only - Stock Exchange Database Management System

## 🎯 Version

**Current Version**: 1.0  
**Release Date**: January 29, 2026  
**Status**: Production Ready
