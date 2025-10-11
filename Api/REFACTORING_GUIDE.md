# 🚀 Backend API Refactoring Guide

## 📋 Overview

This guide explains the new modular structure of the backend API and how to migrate from the monolithic `backend.php` to the new refactored version.

---

## 🏗️ New Directory Structure

```
Api/
├── config/
│   └── database.php          # Centralized database connection with .env support
├── core/
│   └── helpers.php            # Shared utility functions
├── modules/
│   ├── auth.php               # Authentication & user management (existing)
│   ├── products.php           # Product management (existing)
│   ├── inventory.php          # Inventory & transfers (existing)
│   ├── sales.php              # POS & sales operations (existing)
│   ├── reports.php            # Reports & analytics (existing)
│   ├── stock_adjustments.php # Stock adjustment operations (existing)
│   ├── archive.php            # Archive management (existing)
│   └── admin.php              # Admin/debug functions (existing)
├── logs/
│   └── php_errors.log         # Error logs
├── backend.php                # Original monolithic file (8900 lines) - KEEP FOR NOW
├── backend_refactored.php     # New modular router - USE THIS
└── [other API files]
```

---

## 🎯 What Was Refactored

### ✅ Created New Files:

1. **`config/database.php`**
   - Centralized database connection
   - Uses `.env` for secure credentials
   - Singleton pattern for connection reuse
   - Proper error handling

2. **`core/helpers.php`**
   - Extracted all helper functions from backend.php
   - `getStockStatus()` - Stock status calculation
   - `getStockStatusSQL()` - SQL case statements
   - `getEmployeeDetails()` - Employee lookup
   - `setupApiEnvironment()` - CORS, headers, error handling
   - `getJsonInput()` - JSON validation
   - Response helpers (`sendJsonResponse`, `sendErrorResponse`, `sendSuccessResponse`)

3. **`backend_refactored.php`**
   - New main router (replaces backend.php)
   - Routes 150+ actions to appropriate modules
   - Clean, maintainable code
   - Easy to extend

---

## 📊 Action Categories

### 🔐 Authentication & User Management (auth.php)
- `login`, `logout`, `generate_captcha`
- `add_employee`, `display_employee`, `update_employee_status`
- `get_login_records`, `get_users`, `get_activity_records`
- `register_terminal_route`, `get_login_activity`, `get_login_activity_count`
- `log_activity`, `get_activity_logs`, `get_all_logs`
- `get_current_user`, `reset_password`

### 📦 Product Management (products.php)
- `add_convenience_product`, `add_pharmacy_product`, `add_product`
- `update_product`, `delete_product`
- `addBrand`, `displayBrand`, `deleteBrand`, `add_brand`
- `get_products`, `get_suppliers`, `get_brands`, `get_categories`
- `get_locations`, `get_inventory_staff`
- `get_products_oldest_batch_for_transfer`, `get_products_oldest_batch`
- `get_products_by_location`, `get_products_by_location_name`, `get_location_products`
- `add_supplier`, `update_supplier`, `delete_supplier`
- `deleteSupplier`, `restoreSupplier`, `displayArchivedSuppliers`

### 📊 Inventory & Transfer Management (inventory.php)
- `create_transfer`, `update_transfer_status`, `delete_transfer`
- `get_transfers_with_details`, `get_transferred_products_by_location`
- `get_transfer_logs`, `get_transfer_log`, `get_transfer_log_by_id`
- `create_transfer_batch_details_table`
- `get_batches`, `get_locations_for_filter`
- `get_fifo_stock`, `consume_stock_fifo`, `transfer_fifo_consumption`
- `enhanced_fifo_transfer`, `get_fifo_stock_status`, `check_fifo_availability`
- `get_movement_history`, `get_quantity_history`

### 💰 POS & Sales (sales.php)
- `get_pos_products`, `check_barcode`, `get_product_batches`
- `get_discounts`
- `update_product_stock`, `reduce_product_stock`, `simple_update_product_stock`

### 📈 Reports & Analytics (reports.php)
- `get_inventory_kpis`, `get_supply_by_location`, `get_return_rate_by_product`
- `get_stockout_items`, `get_product_kpis`
- `get_warehouse_kpis`, `get_warehouse_supply_by_product`
- `get_warehouse_supply_by_location`, `get_warehouse_stockout_items`
- `get_warehouse_product_kpis`
- `get_top_products_by_quantity`, `get_stock_distribution_by_category`
- `get_fast_moving_items_trend`, `get_critical_stock_alerts`
- `get_inventory_by_branch_category`
- `get_reports_data`, `get_inventory_summary_report`
- `get_low_stock_report`, `get_expiry_report`, `get_movement_history_report`
- `get_expiring_products`, `get_supply_by_product`

### 🔧 Stock Adjustments (stock_adjustments.php)
- `get_stock_adjustments`, `create_stock_adjustment`
- `update_stock_adjustment`, `delete_stock_adjustment`
- `get_stock_adjustment_stats`, `get_product_quantities`

### 📁 Archive Management (archive.php)
- `get_archived_products`, `get_archived_items`
- `restore_archived_item`, `delete_archived_item`

### 🛠️ Admin/Debug (admin.php)
- `test_connection`, `check_table_structure`
- `debug_brands_suppliers`, `clear_brands`
- `diagnose_warehouse_data`, `emergency_restore_warehouse`
- `duplicate_product_batches`, `test_database_connection`
- `test_action`

---

## 🔄 Migration Path

### Option 1: Gradual Migration (RECOMMENDED)

**Keep both files running side-by-side:**

1. **Keep `backend.php` as-is** - Production traffic continues
2. **Test `backend_refactored.php`** - Verify all endpoints work
3. **Switch frontend gradually** - Update API URLs one module at a time
4. **Monitor for issues** - Check logs, test thoroughly
5. **Complete migration** - Once stable, rename files:
   ```bash
   mv backend.php backend_old.php
   mv backend_refactored.php backend.php
   ```

### Option 2: Immediate Switch (RISKY)

**Only if you have comprehensive tests:**

```bash
# Backup original
cp Api/backend.php Api/backend_backup_$(date +%Y%m%d).php

# Switch to refactored version
mv Api/backend.php Api/backend_old.php
mv Api/backend_refactored.php Api/backend.php
```

---

## 🧪 Testing Checklist

Before switching to the refactored version, test these critical flows:

### Authentication
- [ ] User login (all roles)
- [ ] User logout
- [ ] Session management
- [ ] Activity logging

### Products
- [ ] Add product (convenience & pharmacy)
- [ ] Update product
- [ ] Delete product
- [ ] Get products by location
- [ ] Brand management

### Inventory
- [ ] Create transfer
- [ ] Update transfer status
- [ ] FIFO stock consumption
- [ ] Movement history
- [ ] Batch tracking

### POS
- [ ] Get POS products
- [ ] Barcode scanning
- [ ] Stock updates after sale
- [ ] Discount application

### Reports
- [ ] Inventory KPIs
- [ ] Low stock report
- [ ] Expiry report
- [ ] Movement history report

### Stock Adjustments
- [ ] Create adjustment
- [ ] View adjustments
- [ ] Update adjustment
- [ ] Delete adjustment

---

## 🔧 Configuration

### Environment Variables (.env)

Make sure your `.env` file exists in the project root:

```env
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=enguio2
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4

# Application
APP_ENV=development
APP_TIMEZONE=UTC
```

### Frontend API URL Update

If switching to `backend_refactored.php`, update your frontend API calls:

**Before:**
```javascript
const API_URL = 'http://localhost/caps2e2/Api/backend.php';
```

**After (testing):**
```javascript
const API_URL = 'http://localhost/caps2e2/Api/backend_refactored.php';
```

**After (production migration):**
```javascript
const API_URL = 'http://localhost/caps2e2/Api/backend.php'; // Same URL, different file
```

---

## 📈 Benefits of Refactored Structure

### ✅ Maintainability
- **Before:** 8900 lines in one file
- **After:** Organized into logical modules
- **Result:** Easy to find and fix bugs

### ✅ Scalability
- **Before:** Hard to add new features
- **After:** Just add to appropriate module
- **Result:** Faster development

### ✅ Security
- **Before:** Hardcoded credentials
- **After:** `.env` based configuration
- **Result:** Secure credential management

### ✅ Performance
- **Before:** All code loaded every request
- **After:** Only needed modules loaded
- **Result:** Slightly faster response times

### ✅ Team Collaboration
- **Before:** Merge conflicts on single file
- **After:** Multiple developers, different modules
- **Result:** Easier teamwork

---

## 🚨 Important Notes

### ⚠️ DO NOT Delete backend.php Yet!

The original `backend.php` should be kept until you've:
1. ✅ Fully tested `backend_refactored.php`
2. ✅ Updated all frontend API calls
3. ✅ Verified production stability
4. ✅ Have backups

### ⚠️ Module Functions Already Exist

The refactored router uses **existing module files**:
- `modules/auth.php` - Already exists
- `modules/products.php` - Already exists
- `modules/inventory.php` - Already exists
- etc.

**No need to rewrite these!** The router just organizes how they're called.

### ⚠️ Backward Compatibility

The refactored version maintains **100% backward compatibility**:
- Same action names
- Same request format
- Same response format
- Same database schema

**Your frontend code doesn't need changes** (except the API URL during testing).

---

## 🐛 Troubleshooting

### Issue: "Database connection failed"
**Solution:** Check your `.env` file exists and has correct credentials

### Issue: "Handler not found for action"
**Solution:** The action might be in a different module. Check the router mapping in `backend_refactored.php`

### Issue: "Invalid JSON input"
**Solution:** Ensure you're sending proper JSON in the request body

### Issue: "CORS error"
**Solution:** Check `core/helpers.php` - update `Access-Control-Allow-Origin` to match your frontend URL

---

## 📞 Support

If you encounter issues during migration:

1. **Check logs:** `Api/logs/php_errors.log`
2. **Compare responses:** Test same action on both backends
3. **Verify modules:** Ensure all module files exist
4. **Test database:** Use `test_connection` action

---

## 🎉 Success Metrics

After migration, you should have:

- ✅ **Cleaner codebase** - Organized, modular structure
- ✅ **Secure credentials** - Using `.env` file
- ✅ **Better performance** - Optimized loading
- ✅ **Easier maintenance** - Find code quickly
- ✅ **Scalable architecture** - Ready for growth

---

## 📚 Next Steps

1. **Test the refactored backend** with your frontend
2. **Monitor performance** and error logs
3. **Gradually migrate** API calls
4. **Document any custom changes** you make
5. **Consider adding** unit tests for critical functions

---

**Happy Coding! 🚀**
