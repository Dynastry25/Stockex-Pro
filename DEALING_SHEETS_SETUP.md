# Dealing Sheet & Order Sheet Implementation - SETUP GUIDE

## Overview
Successfully implemented **Dealing Sheet** and **Order Sheet** features with first-login-of-day popup functionality for brokers and traders.

---

## 🎯 Features Implemented

### 1. **Dealing Sheet**
- ✅ Displays all matched trades from the `trades` table
- ✅ Shows: Trade Reference, Type, Security ID/Name, Quantity, Price, Value, Buy/Sell indicator
- ✅ Filterable by date and asset type (bonds/equities)
- ✅ Printable format with totals and averages
- ✅ Full-page view at `trader/dealing_sheet.php`

### 2. **Order Sheet (Placeholder)**
- ✅ UI structure ready for future API integration
- ✅ Sample dummy data displayed
- ✅ Filter by status (matched/unmatched)
- ✅ Full-page view at `trader/order_sheet.php`
- ⏳ Real order data pending external API connection

### 3. **First-Login-Daily Popup**
- ✅ Auto-shows modal on **first login of each day**
- ✅ Tracks popup display in `user_sessions` table
- ✅ Works for all user roles (primarily brokers/traders)
- ✅ Includes both sheets in tabbed Bootstrap modal
- ✅ Badge showing trade count in navbar

### 4. **Navigation Integration**
- ✅ Sidebar menu link: "Dealing Sheets" with badge count
- ✅ Top navbar button with badge (desktop view)
- ✅ Modal can be reopened anytime via navbar links

---

## 📦 Files Created/Modified

### **New Files Created:**
1. `database/create_user_sessions_table.sql` - Migration for tracking popup display
2. `includes/sheets_modal.php` - Tabbed modal (Dealing + Order sheets)
3. `trader/dealing_sheet.php` - Full-page Dealing Sheet view
4. `trader/order_sheet.php` - Full-page Order Sheet view (placeholder)
5. `api/v1/sheets.php` - API endpoint for sheet data and popup tracking

### **Modified Files:**
1. `database/schema.sql` - Added `user_sessions` table
2. `includes/financial_helpers.php` - Added helper functions:
   - `shouldShowFirstDailyPopup()`
   - `markFirstDailyPopupShown()`
   - `updateUserSessionLoginDate()`
   - `getDealingSheetTrades()`
   - `getTradesSummary()`
   - `getOrdersPlaceholder()`

3. `auth/login.php` - Sets `$_SESSION['show_popup']` on successful login
4. `includes/header.php` - Added navbar badge, popup data attributes, trade count
5. `includes/footer.php` - Includes sheets_modal.php globally
6. `assets/js/script.js` - Auto-open modal on first daily login
7. `assets/css/style.css` - CSS variable aliases and sheet styling

---

## 🔧 Database Setup

### **Step 1: Run the Migration**
Execute the following SQL file in your database:

```bash
mysql -u your_user -p your_database < database/create_user_sessions_table.sql
```

Or manually run in your database client:

```sql
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    last_login_date DATE,
    first_daily_popup_shown BOOLEAN DEFAULT FALSE,
    popup_display_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_login (last_login_date)
);

-- Populate existing users
INSERT INTO user_sessions (user_id, last_login_date, first_daily_popup_shown)
SELECT id, NULL, FALSE FROM users
ON DUPLICATE KEY UPDATE user_id = user_id;
```

### **Step 2: Verify Trades Table**
Ensure your `trades` table has these columns for the Dealing Sheet to work:
- `trade_reference` (VARCHAR)
- `trade_type` (ENUM: 'bond', 'equity')
- `instrument_id` (INT - references bonds.id or equities.id)
- `trade_side` (ENUM: 'buy', 'sell')
- `quantity` (BIGINT)
- `price` (DECIMAL)
- `total_value` (DECIMAL)
- `buyer_name` (VARCHAR)
- `seller_name` (VARCHAR)
- `trade_date` (DATE)
- `status` (ENUM: 'active', 'settled', 'cancelled')

If any column is missing, create them via migration.

---

## 🧪 Testing Checklist

### **Manual Testing:**
1. ☑ **First Login Today**
   - Log in as any user (broker/trader)
   - Modal should auto-open showing Dealing Sheet tab
   - Verify badge shows correct trade count

2. ☑ **Refresh Page**
   - Refresh browser without logging out
   - Modal should NOT re-open (popup flag cleared after first display)

3. ☑ **Same Day Re-Login**
   - Log out and log in again the same day
   - Modal should NOT show (already shown today per `user_sessions` table)

4. ☑ **Next Day Login**
   - Change system date to tomorrow OR wait for actual next day
   - Log in again
   - Modal should auto-show (new day = new popup)

5. ☑ **Navbar Access**
   - Click "Dealing Sheets" link in sidebar
   - Modal should open on demand
   - Click button in top navbar (desktop view)
   - Same modal opens

6. ☑ **Dealing Sheet Tab**
   - Click "Dealing Sheet" tab in modal
   - Should show table of all active trades
   - Verify: Reference, Type, Security, Quantity, Price, Value, Buy/Sell columns
   - If no trades, shows "No trades today" message

7. ☑ **Order Sheet Tab**
   - Click "Order Sheet" tab in modal
   - Should show dummy sample orders with "API Integration Pending" warning
   - Table shows: Order ID, Client, Security, Quantity, Price, Status

8. ☑ **Full Page Views**
   - Navigate to `trader/dealing_sheet.php`
   - Should show full-page dealing sheet with filters
   - Try date filter and asset type filter
   - Navigate to `trader/order_sheet.php`
   - Should show full-page order sheet (placeholder)

9. ☑ **Print Functionality**
   - Open modal or full page
   - Click "Print" button
   - Print preview should hide navbar, tabs, alerts (only table visible)

10. ☑ **Badge Count**
    - Verify navbar badge matches actual trade count from database
    - Badge updates on page refresh

---

## 🔌 API Endpoints Available

### **GET** `/api/v1/sheets.php?action=trades[&date=YYYY-MM-DD]`
Returns matched trades for dealing sheet.

**Response:**
```json
{
  "success": true,
  "data": {
    "trades": [...],
    "summary": {"count": 5, "total_value": 150000.00},
    "date": "2026-03-06"
  }
}
```

### **GET** `/api/v1/sheets.php?action=orders`
Returns placeholder orders (dummy data).

**Response:**
```json
{
  "success": true,
  "data": {
    "orders": [...],
    "note": "Placeholder data: API integration pending"
  }
}
```

### **POST** `/api/v1/sheets.php` (action=mark_popup_shown)
Marks popup as shown for the current user today.

**Request Body:**
```json
{
  "action": "mark_popup_shown"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Popup state updated"
}
```

---

## 🚀 Next Steps (Optional Enhancements)

### **Phase 2: Order Sheet Real Data**
1. Connect external order API endpoint
2. Replace `getOrdersPlaceholder()` in `financial_helpers.php` with real API call
3. Add WebSocket for real-time order updates
4. Implement order action handlers (cancel, modify)

### **Phase 3: Advanced Features**
1. **Export to PDF** - Add TCPDF integration for dealing sheet export
2. **Email Dealing Sheet** - Auto-email to brokers at end of day
3. **Historical Archive** - Create `dealing_sheets_archive` table for daily snapshots
4. **Role Filtering** - Show only company-specific trades based on user's broker firm
5. **Pagination** - Add pagination for large trade volumes
6. **Search** - Add real-time client/security search in modal
7. **CSV Export** - Export dealing sheet to CSV download

---

## 🎨 Customization Options

### **Change Popup Frequency**
Edit `shouldShowFirstDailyPopup()` in `financial_helpers.php`:
- **Every login**: Always return `true`
- **First login ever**: Check if `$session` is null only
- **Weekly**: Compare week number instead of date

### **Change Badge Color**
Edit sidebar badge in `includes/header.php`:
```php
<span class="badge bg-danger ms-auto"><?php echo $sheets_trade_count; ?></span>
```
Change `bg-danger` to `bg-primary`, `bg-success`, etc.

### **Customize Trade Filters**
Edit trade query in `getDealingSheetTrades()` in `financial_helpers.php`:
```php
WHERE t.status = 'active' AND t.uploaded_by = ?  // Filter by user
```

### **Change Modal Delay**
Edit auto-open delay in `assets/js/script.js`:
```javascript
setTimeout(() => {
  const modal = bootstrap.Modal.getOrCreateInstance(sheetsModalElement)
  modal.show()
  markSheetsPopupShown()
}, 400)  // Change 400ms to your desired delay
```

---

## 🐛 Troubleshooting

### **Popup Not Showing**
1. Check `$_SESSION['show_popup']` is set in `auth/login.php`
2. Verify `user_sessions` table exists and is populated
3. Check browser console for JavaScript errors
4. Ensure `<body>` has `data-show-popup="true"` attribute after login

### **Badge Count Wrong**
1. Verify `trades` table has records with `status = 'active'`
2. Check `getTradesSummary()` function in `financial_helpers.php`
3. Ensure query joins bonds/equities tables correctly

### **Modal Blank/Empty**
1. Check `getDealingSheetTrades()` returns data
2. Verify database connection in `includes/footer.php`
3. Check PHP error logs for database query errors
4. Ensure `financial_helpers.php` is required before modal include

### **Popup Shows Every Page Load**
1. Verify session flag is unset in `includes/footer.php`
2. Check `markSheetsPopupShown()` is called after modal opens
3. Ensure API endpoint `/api/v1/sheets.php` is accessible

### **CSS Variables Not Working**
1. Add compatibility aliases in `assets/css/style.css` (already added):
```css
:root {
  --primary: var(--primary-color);
  --light-bg: var(--background-secondary);
}
```

---

## 📊 Database Schema Reference

### **user_sessions Table**
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | Foreign key to users.id (UNIQUE) |
| last_login_date | DATE | Date of last login |
| first_daily_popup_shown | BOOLEAN | TRUE if popup shown today |
| popup_display_count | INT | Total times popup has been shown |
| created_at | TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | Last update time |

---

## ✅ Deployment Checklist

Before deploying to production:

- [ ] Run database migration: `create_user_sessions_table.sql`
- [ ] Verify trades table has required columns
- [ ] Test popup on dev/staging environment
- [ ] Test with multiple user roles (broker, trader, admin)
- [ ] Test across browsers (Chrome, Firefox, Safari)
- [ ] Test on mobile devices (responsive modal)
- [ ] Verify print styles work correctly
- [ ] Check API endpoints return valid JSON
- [ ] Review error logs for PHP warnings
- [ ] Clear browser cache after deployment
- [ ] Update user documentation/training materials

---

## 📝 Technical Notes

### **Session Management**
- Popup flag stored in `$_SESSION['show_popup']` during login
- Flag cleared after first modal render in `footer.php`
- Persistent tracking in `user_sessions` table

### **Daily Reset Logic**
- `updateUserSessionLoginDate()` resets `first_daily_popup_shown = 0` if new day
- `shouldShowFirstDailyPopup()` compares `last_login_date` with current date
- Popup shows if date doesn't match OR flag is 0

### **Modal Loading**
- Modal HTML included globally in `footer.php` for all logged-in users
- JavaScript checks `data-show-popup` attribute on `<body>` tag
- Bootstrap Modal API used: `bootstrap.Modal.getOrCreateInstance()`

### **Trade Data Flow**
1. Login → Set session flag
2. Page loads → Fetch trade count for badge
3. Footer renders → Include modal with trade data
4. JS checks flag → Auto-open modal if first daily login
5. AJAX call → Mark popup as shown in database
6. Session flag cleared → Prevent re-display on refresh

---

## 🎓 Support & Maintenance

**For issues or questions:**
1. Check PHP error logs: `error_log` files in project root
2. Check browser console for JavaScript errors
3. Verify database connection and table existence
4. Test API endpoints directly: `api/v1/sheets.php?action=trades`

**Maintenance tasks:**
- Monitor `user_sessions` table growth (one row per user)
- Archive old trades periodically for performance
- Update dummy order data in `getOrdersPlaceholder()` as needed
- Review popup display analytics via `popup_display_count` column

---

## 🙏 Implementation Complete

All requirements successfully implemented:
✅ Dealing Sheet displays matched trades
✅ Order Sheet UI ready for API integration
✅ First-login-daily popup for brokers/traders
✅ Navbar section with badge count
✅ Full-page views for both sheets
✅ Print functionality
✅ Bootstrap modal with tabs
✅ Session tracking and popup management
✅ API endpoints for data retrieval

**Ready for testing and deployment!**
