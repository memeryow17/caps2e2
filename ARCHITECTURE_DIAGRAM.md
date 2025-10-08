# 🏗️ Backend Architecture Diagram

## 📊 Visual Overview of Refactored Structure

---

## 🔄 Request Flow

```
┌─────────────────────────────────────────────────────────────┐
│                       FRONTEND                               │
│  (React/Next.js - JavaScript)                               │
│                                                              │
│  API Call: POST /Api/backend_refactored.php                │
│  Body: { "action": "get_products", "location_id": 1 }      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│            backend_refactored.php (185 lines)               │
│                    MAIN ROUTER                               │
│                                                              │
│  1. Setup API Environment (CORS, Headers, Error Handling)  │
│  2. Get Database Connection                                 │
│  3. Parse JSON Input                                        │
│  4. Extract Action                                          │
│  5. Route to Appropriate Module                            │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                   MODULE ROUTER                              │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │ Auth Module  │  │Product Module│  │Inventory Mod │     │
│  │ (20 actions) │  │ (25 actions) │  │ (18 actions) │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │ Sales Module │  │Report Module │  │ Stock Adj.   │     │
│  │ (7 actions)  │  │ (24 actions) │  │ (6 actions)  │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐                        │
│  │Archive Module│  │ Admin Module │                        │
│  │ (4 actions)  │  │ (9 actions)  │                        │
│  └──────────────┘  └──────────────┘                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                  HELPER FUNCTIONS                            │
│                  (core/helpers.php)                          │
│                                                              │
│  • getStockStatus()        • setupApiEnvironment()         │
│  • getStockStatusSQL()     • getJsonInput()                │
│  • getEmployeeDetails()    • sendJsonResponse()            │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              DATABASE CONNECTION                             │
│              (config/database.php)                           │
│                                                              │
│  • Reads .env file                                          │
│  • Creates PDO connection                                   │
│  • Singleton pattern (reuse connection)                     │
│  • Error handling                                           │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    MySQL DATABASE                            │
│                    (enguio2)                                 │
│                                                              │
│  Tables: tbl_product, tbl_batch, tbl_employee,             │
│          tbl_transfer, tbl_stock_movements, etc.           │
└─────────────────────────────────────────────────────────────┘
```

---

## 📁 File Structure

```
caps2e2/
│
├── .env                                  ← Environment variables
│   ├── DB_HOST=localhost
│   ├── DB_DATABASE=enguio2
│   ├── DB_USERNAME=root
│   └── DB_PASSWORD=
│
├── simple_dotenv.php                     ← .env file loader
│
└── Api/
    │
    ├── config/
    │   └── database.php                  ← 🔵 Database connection
    │       ├── Loads .env
    │       ├── Creates PDO connection
    │       └── getDatabaseConnection()
    │
    ├── core/
    │   └── helpers.php                   ← 🔵 Utility functions
    │       ├── getStockStatus()
    │       ├── getEmployeeDetails()
    │       ├── setupApiEnvironment()
    │       └── Response helpers
    │
    ├── modules/                          ← 🟢 Existing modules
    │   ├── auth.php                      (Authentication)
    │   ├── products.php                  (Products)
    │   ├── inventory.php                 (Inventory)
    │   ├── sales.php                     (POS/Sales)
    │   ├── reports.php                   (Reports)
    │   ├── stock_adjustments.php         (Adjustments)
    │   ├── archive.php                   (Archive)
    │   └── admin.php                     (Admin/Debug)
    │
    ├── logs/
    │   └── php_errors.log                ← Error logging
    │
    ├── backend.php                       ← ⚠️  OLD (8900 lines)
    │   └── Keep as backup
    │
    ├── backend_refactored.php            ← ✅ NEW (185 lines)
    │   └── Main router
    │
    └── REFACTORING_GUIDE.md              ← Documentation
```

---

## 🎯 Module Responsibilities

```
┌─────────────────────────────────────────────────────────────┐
│                    MODULE BREAKDOWN                          │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 🔐 AUTH MODULE (modules/auth.php)                           │
├─────────────────────────────────────────────────────────────┤
│ • User login/logout                                         │
│ • Session management                                        │
│ • Employee management                                       │
│ • Activity logging                                          │
│ • Terminal registration                                     │
│ • Password reset                                            │
│                                                              │
│ Actions: login, logout, add_employee, get_users,           │
│          log_activity, get_activity_logs, etc.             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 📦 PRODUCTS MODULE (modules/products.php)                   │
├─────────────────────────────────────────────────────────────┤
│ • Product CRUD operations                                   │
│ • Brand management                                          │
│ • Supplier management                                       │
│ • Category management                                       │
│ • Location management                                       │
│                                                              │
│ Actions: add_product, update_product, delete_product,      │
│          addBrand, get_suppliers, get_categories, etc.     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 📊 INVENTORY MODULE (modules/inventory.php)                 │
├─────────────────────────────────────────────────────────────┤
│ • Inventory transfers                                       │
│ • FIFO stock management                                     │
│ • Batch tracking                                            │
│ • Movement history                                          │
│ • Stock consumption                                         │
│                                                              │
│ Actions: create_transfer, get_fifo_stock,                  │
│          consume_stock_fifo, get_movement_history, etc.    │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 💰 SALES MODULE (modules/sales.php)                         │
├─────────────────────────────────────────────────────────────┤
│ • POS product lookup                                        │
│ • Barcode scanning                                          │
│ • Stock updates                                             │
│ • Discount management                                       │
│                                                              │
│ Actions: get_pos_products, check_barcode,                  │
│          update_product_stock, get_discounts, etc.         │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 📈 REPORTS MODULE (modules/reports.php)                     │
├─────────────────────────────────────────────────────────────┤
│ • Inventory KPIs                                            │
│ • Warehouse analytics                                       │
│ • Stock reports                                             │
│ • Charts and graphs                                         │
│ • Expiry tracking                                           │
│                                                              │
│ Actions: get_inventory_kpis, get_warehouse_kpis,           │
│          get_low_stock_report, get_expiry_report, etc.     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 🔧 STOCK ADJUSTMENTS (modules/stock_adjustments.php)        │
├─────────────────────────────────────────────────────────────┤
│ • Create adjustments                                        │
│ • Update adjustments                                        │
│ • Delete adjustments                                        │
│ • Adjustment statistics                                     │
│                                                              │
│ Actions: get_stock_adjustments, create_stock_adjustment,   │
│          update_stock_adjustment, etc.                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 📁 ARCHIVE MODULE (modules/archive.php)                     │
├─────────────────────────────────────────────────────────────┤
│ • View archived items                                       │
│ • Restore items                                             │
│ • Delete archived items                                     │
│                                                              │
│ Actions: get_archived_products, restore_archived_item,     │
│          delete_archived_item, etc.                        │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 🛠️  ADMIN MODULE (modules/admin.php)                        │
├─────────────────────────────────────────────────────────────┤
│ • Test connections                                          │
│ • Debug tools                                               │
│ • Emergency functions                                       │
│ • Diagnostics                                               │
│                                                              │
│ Actions: test_connection, diagnose_warehouse_data,         │
│          emergency_restore_warehouse, etc.                 │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔄 Data Flow Example

### Example: Adding a Product

```
1. Frontend sends request:
   ┌─────────────────────────────────────┐
   │ POST /Api/backend_refactored.php   │
   │ {                                   │
   │   "action": "add_product",         │
   │   "product_name": "Paracetamol",   │
   │   "price": 5.50,                   │
   │   "location_id": 1                 │
   │ }                                   │
   └─────────────────────────────────────┘
                    ↓
2. Router receives and validates:
   ┌─────────────────────────────────────┐
   │ backend_refactored.php              │
   │ • Setup environment                 │
   │ • Get database connection           │
   │ • Parse JSON                        │
   │ • Extract action: "add_product"    │
   └─────────────────────────────────────┘
                    ↓
3. Router identifies module:
   ┌─────────────────────────────────────┐
   │ Action "add_product" belongs to:    │
   │ → modules/products.php              │
   └─────────────────────────────────────┘
                    ↓
4. Module processes request:
   ┌─────────────────────────────────────┐
   │ handle_add_product($conn, $data)    │
   │ • Validate input                    │
   │ • Check duplicates                  │
   │ • Insert into database              │
   │ • Log activity                      │
   └─────────────────────────────────────┘
                    ↓
5. Database operation:
   ┌─────────────────────────────────────┐
   │ INSERT INTO tbl_product             │
   │ (product_name, price, location_id)  │
   │ VALUES (?, ?, ?)                    │
   └─────────────────────────────────────┘
                    ↓
6. Response sent back:
   ┌─────────────────────────────────────┐
   │ {                                   │
   │   "success": true,                 │
   │   "message": "Product added",      │
   │   "product_id": 123                │
   │ }                                   │
   └─────────────────────────────────────┘
```

---

## 📊 Before vs After Comparison

### BEFORE: Monolithic

```
┌───────────────────────────────────────┐
│                                       │
│        backend.php (8900 lines)       │
│                                       │
│  ┌─────────────────────────────────┐ │
│  │ CORS Headers                    │ │
│  │ Error Handling                  │ │
│  │ Database Connection (hardcoded) │ │
│  │ Helper Functions                │ │
│  │ 150+ Action Handlers            │ │
│  │ All mixed together!             │ │
│  └─────────────────────────────────┘ │
│                                       │
│  Problems:                            │
│  ❌ Hard to navigate                  │
│  ❌ Hard to maintain                  │
│  ❌ Hardcoded credentials             │
│  ❌ Merge conflicts                   │
│  ❌ Difficult to test                 │
│                                       │
└───────────────────────────────────────┘
```

### AFTER: Modular

```
┌───────────────────────────────────────┐
│  backend_refactored.php (185 lines)   │
│  ┌─────────────────────────────────┐  │
│  │ Main Router                     │  │
│  │ • Setup environment             │  │
│  │ • Get connection                │  │
│  │ • Route to modules              │  │
│  └─────────────────────────────────┘  │
└───────────┬───────────────────────────┘
            │
            ├─→ config/database.php (Secure connection)
            ├─→ core/helpers.php (Utilities)
            │
            └─→ modules/
                ├─→ auth.php
                ├─→ products.php
                ├─→ inventory.php
                ├─→ sales.php
                ├─→ reports.php
                ├─→ stock_adjustments.php
                ├─→ archive.php
                └─→ admin.php

Benefits:
✅ Easy to navigate
✅ Easy to maintain
✅ Secure credentials (.env)
✅ No merge conflicts
✅ Easy to test
```

---

## 🎯 Summary

**Main Router:** `backend_refactored.php` (185 lines)
- Receives all requests
- Routes to appropriate module
- Handles errors

**Database:** `config/database.php`
- Centralized connection
- .env based credentials
- Singleton pattern

**Helpers:** `core/helpers.php`
- Shared utilities
- Response helpers
- Stock calculations

**Modules:** `modules/*.php`
- Organized by feature
- Clear responsibilities
- Easy to extend

**Result:** Clean, maintainable, professional architecture! 🚀

---

**Your backend is now production-ready! 🎉**
