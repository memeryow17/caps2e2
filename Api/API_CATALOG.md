# ENGUIO API Catalog

## Directory Structure

```
Api/
├── Core Configuration Files
│   ├── config.php              # Configuration loader (loads .env)
│   ├── cors.php                # CORS headers configuration
│   ├── conn.php                # PDO database connection
│   ├── conn_mysqli.php         # MySQLi database connection
│   └── Database.php            # Database class with helper methods
│
├── Authentication & Authorization
│   └── login.php               # User login, logout, captcha
│
├── POS (Point of Sale) APIs
│   ├── sales_api.php           # Sales transactions and operations
│   ├── convenience_store_api.php  # Convenience store specific operations
│   ├── pharmacy_api.php        # Pharmacy specific operations
│   ├── pos_return_api.php      # Product return processing
│   └── pos_exchange_api.php    # Product exchange processing
│
├── Inventory Management APIs
│   ├── inventory_api.php       # Inventory operations
│   ├── inventory_transfer_api.php  # Transfer between locations
│   ├── products_api.php        # Product CRUD operations
│   ├── stock_summary_api.php   # Stock summaries and reports
│   └── barcode_api.php         # Barcode operations
│
├── Batch & FIFO Management
│   ├── batch_tracking.php      # Batch tracking system
│   ├── batch_functions_api.php # Batch-related functions
│   ├── batch_transfer_api.php  # Batch transfer operations
│   ├── batch_stock_adjustment_api.php  # Batch adjustments
│   ├── fifo_transfer_api.php   # FIFO transfer operations
│   └── get_transferred_batches_api.php  # Batch transfer queries
│
├── Purchase Orders
│   ├── purchase_order_api.php  # Full purchase order management
│   ├── purchase_order_api_simple.php  # Simplified purchase orders
│   └── create_purchase_order_api.php  # Purchase order creation
│
├── Transfer Operations
│   └── transfer_api.php        # General transfer operations
│
├── Dashboard APIs
│   ├── dashboard_sales_api.php    # Sales dashboard data
│   ├── dashboard_return_api.php   # Returns dashboard data
│   └── dashboard_transfer_api.php # Transfer dashboard data
│
├── Reports
│   └── combined_reports_api.php  # Combined reporting system
│
├── Utilities
│   ├── merge_duplicate_products.php  # Product deduplication
│   └── backend.php             # Legacy backend handler
│   ├── backend_new.php         # New modular backend router
│   └── backend_modular.php     # Modular backend implementation
│
├── Module Library
│   └── modules/
│       ├── helpers.php         # Helper functions
│       ├── auth.php            # Authentication functions
│       ├── products.php        # Product management functions
│       ├── inventory.php       # Inventory functions
│       ├── batch_functions.php # Batch functions
│       ├── barcode.php         # Barcode functions
│       ├── locations.php       # Location management
│       ├── reports.php         # Reporting functions
│       ├── sales.php           # Sales functions
│       ├── employees.php       # Employee management
│       ├── discounts.php       # Discount functions
│       └── admin.php           # Admin functions
│
├── Testing & Documentation
│   ├── tests/
│   │   ├── verify_setup.php    # Setup verification
│   │   ├── connection_test.php # Database connection test
│   │   ├── test_database.php   # Database tests
│   │   ├── test_cors.php       # CORS configuration test
│   │   ├── test_dashboard_apis.php  # Dashboard API tests
│   │   └── test_backend_direct.php  # Backend API tests
│   │
│   ├── utils/
│   │   ├── ApiHelper.php       # API response helpers
│   │   └── print-receipt-fixed-width.php  # Receipt printing
│   │
│   └── documentation/
│       └── README.md           # API documentation
```

## API Endpoints by Category

### 1. Authentication APIs

#### login.php
**Purpose:** User authentication and session management

**Actions:**
- `login` - User login with captcha verification
- `logout` - User logout and session cleanup
- `generate_captcha` - Generate captcha for login

**Method:** POST

**Authentication:** None (public endpoint)

---

### 2. Point of Sale (POS) APIs

#### sales_api.php
**Purpose:** Handle all POS and sales operations

**Key Actions:**
- `get_discounts` - Get available discount types
- `check_barcode` - Verify product barcode
- `process_sale` - Process a sale transaction
- `void_sale` - Void a sale
- `get_sales_history` - Get sales history

**Method:** POST

**Authentication:** Required

---

#### convenience_store_api.php
**Purpose:** Convenience store specific operations

**Method:** POST

**Authentication:** Required

---

#### pharmacy_api.php
**Purpose:** Pharmacy specific operations including prescription management

**Method:** POST

**Authentication:** Required

---

#### pos_return_api.php
**Purpose:** Handle product returns

**Key Actions:**
- `process_return` - Process product return
- `get_return_history` - Get return history
- `approve_return` - Approve a return request

**Method:** POST

**Authentication:** Required

---

#### pos_exchange_api.php
**Purpose:** Handle product exchanges

**Key Actions:**
- `process_exchange` - Process product exchange
- `get_exchange_history` - Get exchange history

**Method:** POST

**Authentication:** Required

---

### 3. Inventory Management APIs

#### inventory_api.php
**Purpose:** Core inventory management operations

**Key Actions:**
- `get_products` - Get products list
- `add_product` - Add new product
- `update_product` - Update product
- `delete_product` - Delete product
- `adjust_stock` - Adjust stock levels

**Method:** POST

**Authentication:** Required

---

#### products_api.php
**Purpose:** Product CRUD operations

**Key Actions:**
- `get_products` - Get products with filters
- `get_suppliers` - Get supplier list
- `get_brands` - Get brand list
- `get_categories` - Get category list
- `get_fifo_stock` - Get FIFO stock status
- `get_products_oldest_batch` - Get products by oldest batch
- `get_warehouse_kpis` - Get warehouse KPIs

**Method:** POST

**Authentication:** Required

---

#### barcode_api.php
**Purpose:** Barcode generation and management

**Key Actions:**
- `generate_barcode` - Generate barcode
- `lookup_barcode` - Look up product by barcode
- `update_barcode` - Update product barcode

**Method:** POST

**Authentication:** Required

---

#### stock_summary_api.php
**Purpose:** Stock summaries and movement tracking

**Key Actions:**
- `get_stock_summary` - Get stock summary
- `get_stock_movements` - Get stock movement history
- `get_low_stock_items` - Get items with low stock

**Method:** POST

**Authentication:** Required

---

### 4. Batch & FIFO Management APIs

#### batch_tracking.php
**Purpose:** Track and manage product batches

**Key Actions:**
- `add_batch` - Add new batch
- `update_batch` - Update batch information
- `get_batch_history` - Get batch history
- `expire_batch` - Mark batch as expired

**Method:** POST

**Authentication:** Required

---

#### batch_functions_api.php
**Purpose:** Batch-related helper functions

**Method:** POST

**Authentication:** Required

---

#### batch_transfer_api.php
**Purpose:** Transfer batches between locations

**Key Actions:**
- `transfer_batch` - Transfer batch to another location
- `get_batch_transfers` - Get batch transfer history

**Method:** POST

**Authentication:** Required

---

#### batch_stock_adjustment_api.php
**Purpose:** Adjust batch stock levels

**Key Actions:**
- `adjust_batch_stock` - Adjust stock for a specific batch
- `get_adjustment_history` - Get adjustment history

**Method:** POST

**Authentication:** Required

---

#### fifo_transfer_api.php
**Purpose:** FIFO-based transfer operations

**Key Actions:**
- `fifo_transfer` - Transfer stock using FIFO method
- `get_fifo_queue` - Get FIFO queue

**Method:** POST

**Authentication:** Required

---

#### get_transferred_batches_api.php
**Purpose:** Query transferred batches

**Key Actions:**
- `get_transferred_batches` - Get list of transferred batches

**Method:** POST

**Authentication:** Required

---

### 5. Purchase Order APIs

#### purchase_order_api.php
**Purpose:** Full purchase order management

**Key Actions:**
- `create_purchase_order` - Create new purchase order
- `update_purchase_order` - Update purchase order
- `get_purchase_orders` - Get purchase order list
- `receive_purchase_order` - Receive/complete purchase order
- `cancel_purchase_order` - Cancel purchase order

**Method:** POST

**Authentication:** Required

---

#### purchase_order_api_simple.php
**Purpose:** Simplified purchase order operations

**Method:** POST

**Authentication:** Required

---

#### create_purchase_order_api.php
**Purpose:** Dedicated purchase order creation

**Method:** POST

**Authentication:** Required

---

### 6. Transfer APIs

#### inventory_transfer_api.php
**Purpose:** Transfer inventory between locations

**Key Actions:**
- `create_transfer` - Create transfer request
- `approve_transfer` - Approve transfer
- `complete_transfer` - Complete transfer
- `get_transfers` - Get transfer list

**Method:** POST

**Authentication:** Required

---

#### transfer_api.php
**Purpose:** General transfer operations

**Method:** POST

**Authentication:** Required

---

### 7. Dashboard APIs

#### dashboard_sales_api.php
**Purpose:** Provide sales data for dashboard

**Key Actions:**
- `get_total_sales` - Get total sales
- `get_sales_summary` - Get sales summary by period
- `get_top_products` - Get top selling products

**Method:** POST/GET

**Authentication:** Required

---

#### dashboard_return_api.php
**Purpose:** Provide return data for dashboard

**Key Actions:**
- `get_total_returns` - Get total returns
- `get_return_summary` - Get return summary

**Method:** POST/GET

**Authentication:** Required

---

#### dashboard_transfer_api.php
**Purpose:** Provide transfer data for dashboard

**Key Actions:**
- `get_total_transfer` - Get total transfers
- `get_transfer_summary` - Get transfer summary

**Method:** POST/GET

**Authentication:** Required

---

### 8. Reports APIs

#### combined_reports_api.php
**Purpose:** Combined reporting system

**Key Actions:**
- `get_sales_report` - Sales report
- `get_inventory_report` - Inventory report
- `get_transfer_report` - Transfer report
- `get_return_report` - Return report

**Method:** POST

**Authentication:** Required

---

### 9. Utility APIs

#### merge_duplicate_products.php
**Purpose:** Identify and merge duplicate products

**Method:** POST

**Authentication:** Admin only

---

### 10. Backend Handlers

#### backend.php
**Purpose:** Legacy main backend handler

**Status:** ⚠️ Legacy - consider using backend_new.php

**Method:** POST

**Authentication:** Required

---

#### backend_new.php
**Purpose:** New modular backend router

**Status:** ✅ Recommended

**Method:** POST

**Authentication:** Required

---

#### backend_modular.php
**Purpose:** Modular backend implementation

**Method:** POST

**Authentication:** Required

---

## Module Library

### modules/helpers.php
**Purpose:** Shared helper functions
- `getDatabaseConnection()` - Get database connection
- `getStockStatus()` - Calculate stock status
- `logActivity()` - Log user activity
- `setupApiEnvironment()` - Setup API environment
- Response helpers: `sendJsonResponse()`, `sendErrorResponse()`, `sendSuccessResponse()`

### modules/auth.php
**Purpose:** Authentication functions
- User authentication
- Session management
- Permission checking

### modules/products.php
**Purpose:** Product management functions
- Product CRUD operations
- Product search and filtering

### modules/inventory.php
**Purpose:** Inventory management functions
- Stock management
- Stock adjustments
- Stock movement tracking

### modules/batch_functions.php
**Purpose:** Batch management functions
- Batch operations
- FIFO queue management

### modules/barcode.php
**Purpose:** Barcode operations
- Barcode generation
- Barcode lookup

### modules/locations.php
**Purpose:** Location management
- Location CRUD
- Location-based operations

### modules/reports.php
**Purpose:** Reporting functions
- Report generation
- Data aggregation

### modules/sales.php
**Purpose:** Sales functions
- Sale processing
- Sales calculations

### modules/employees.php
**Purpose:** Employee management
- Employee CRUD
- Employee queries

### modules/discounts.php
**Purpose:** Discount management
- Discount rules
- Discount application

### modules/admin.php
**Purpose:** Admin functions
- System administration
- Configuration management

---

## Best Practices

### All API Files Follow:
1. ✅ Environment variables for configuration
2. ✅ `require_once __DIR__ . '/file.php'` for includes
3. ✅ Proper error handling
4. ✅ JSON responses
5. ✅ CORS headers
6. ✅ Prepared statements for database queries
7. ✅ Activity logging
8. ✅ Input validation

### Standard Response Format:
```json
{
    "success": true|false,
    "message": "Message string",
    "data": { ... } // optional
}
```

### Error Response:
```json
{
    "success": false,
    "message": "Error description",
    "error": { ... } // optional debug info
}
```

---

## Testing

### Setup Verification:
```bash
php Api/tests/verify_setup.php
```

### Database Connection Test:
```bash
php Api/tests/connection_test.php
```

### Individual API Test:
```bash
curl -X POST http://localhost/Api/sales_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"test_action", "data":"test"}'
```

---

## Quick Reference

### Core Files:
- **config.php** - Load environment variables
- **cors.php** - CORS configuration
- **conn.php** - PDO connection
- **conn_mysqli.php** - MySQLi connection

### Most Used APIs:
1. `login.php` - Authentication
2. `sales_api.php` - Sales processing
3. `inventory_api.php` - Inventory management
4. `products_api.php` - Product operations
5. `dashboard_sales_api.php` - Dashboard data

---

**Last Updated:** October 2025
**Version:** 2.0
**Total Endpoints:** 40+
