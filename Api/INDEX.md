# ENGUIO API - INDEX

## 📁 Directory Structure

```
Api/
│
├── 🔧 Core Configuration
│   ├── config.php              # Loads environment variables
│   ├── cors.php                # CORS configuration
│   ├── conn.php                # PDO database connection
│   ├── conn_mysqli.php         # MySQLi database connection
│   └── Database.php            # Database helper class
│
├── 🔐 Authentication
│   └── login.php               # Login, logout, captcha
│
├── 🏪 POS APIs (5 endpoints)
│   ├── sales_api.php
│   ├── convenience_store_api.php
│   ├── pharmacy_api.php
│   ├── pos_return_api.php
│   └── pos_exchange_api.php
│
├── 📦 Inventory APIs (5 endpoints)
│   ├── inventory_api.php
│   ├── inventory_transfer_api.php
│   ├── products_api.php
│   ├── stock_summary_api.php
│   └── barcode_api.php
│
├── 📊 Batch Management (6 endpoints)
│   ├── batch_tracking.php
│   ├── batch_functions_api.php
│   ├── batch_transfer_api.php
│   ├── batch_stock_adjustment_api.php
│   ├── fifo_transfer_api.php
│   └── get_transferred_batches_api.php
│
├── 🛒 Purchase Orders (3 endpoints)
│   ├── purchase_order_api.php
│   ├── purchase_order_api_simple.php
│   └── create_purchase_order_api.php
│
├── 🔄 Transfer
│   └── transfer_api.php
│
├── 📈 Dashboard APIs (3 endpoints)
│   ├── dashboard_sales_api.php
│   ├── dashboard_return_api.php
│   └── dashboard_transfer_api.php
│
├── 📋 Reports
│   └── combined_reports_api.php
│
├── 🔌 Backend Handlers (3)
│   ├── backend.php             # ⚠️  Legacy
│   ├── backend_new.php         # ✅ Recommended
│   └── backend_modular.php     # ✅ Active
│
├── 🛠️ Utilities
│   └── merge_duplicate_products.php
│
├── 📚 modules/                 # 12 module files
│   ├── helpers.php
│   ├── auth.php
│   ├── products.php
│   ├── inventory.php
│   ├── batch_functions.php
│   ├── barcode.php
│   ├── locations.php
│   ├── reports.php
│   ├── sales.php
│   ├── employees.php
│   ├── discounts.php
│   └── admin.php
│
├── 🧪 tests/                   # 6 test files
│   ├── verify_setup.php
│   ├── connection_test.php
│   ├── test_database.php
│   ├── test_cors.php
│   ├── test_dashboard_apis.php
│   └── test_backend_direct.php
│
├── 🔨 utils/                   # 2 utility files
│   ├── ApiHelper.php
│   └── print-receipt-fixed-width.php
│
├── 📖 documentation/
│   └── README.md               # Full API documentation
│
├── API_CATALOG.md              # Complete API catalog
└── INDEX.md                    # This file
```

---

## 🚀 Quick Start

### 1. Setup Environment
```bash
cd /home/quasar/Capstone
cp env.example.txt .env
nano .env  # Update credentials
```

### 2. Verify Setup
```bash
php Api/tests/verify_setup.php
```

### 3. Test Connection
```bash
php Api/tests/connection_test.php
```

---

## 📊 API Statistics

- **Total Endpoints:** 34
- **Module Files:** 12
- **Test Files:** 6
- **Utility Files:** 2
- **Core Files:** 5

---

## 🎯 Most Used APIs

1. `login.php` - Authentication
2. `sales_api.php` - POS sales
3. `inventory_api.php` - Inventory management
4. `products_api.php` - Product operations
5. `dashboard_sales_api.php` - Dashboard data

---

## 📚 Documentation

| File | Description |
|------|-------------|
| **API_CATALOG.md** | Complete catalog of all endpoints |
| **documentation/README.md** | Full API documentation |
| **API_CLEANUP_COMPLETE.md** | Cleanup summary |
| **SETUP_INSTRUCTIONS.md** | Setup guide |
| **QUICK_START.txt** | Quick reference |

---

## ✅ Best Practices Applied

- ✅ Environment variables for configuration
- ✅ `require_once __DIR__ . '/file.php'` for includes
- ✅ Proper error handling
- ✅ JSON responses
- ✅ CORS headers
- ✅ Prepared statements
- ✅ Activity logging
- ✅ Input validation

---

## 🔧 Testing

```bash
# Verify setup
php Api/tests/verify_setup.php

# Test database
php Api/tests/connection_test.php

# Test CORS
php Api/tests/test_cors.php

# Test APIs
php Api/tests/test_database.php
```

---

## 🆘 Troubleshooting

### Database Connection Failed?
1. Check `.env` file exists
2. Verify credentials are correct
3. Ensure MySQL is running

### CORS Errors?
1. Update `CORS_ORIGIN` in `.env`
2. Clear browser cache

### File Not Found?
1. Ensure using `__DIR__` in includes
2. Check file paths

---

## 📞 Support

- Check `documentation/README.md` for detailed docs
- Run `php Api/tests/verify_setup.php` to diagnose issues
- Review `API_CATALOG.md` for endpoint details

---

**Version:** 2.0  
**Last Updated:** October 2025  
**Status:** ✅ Production Ready
