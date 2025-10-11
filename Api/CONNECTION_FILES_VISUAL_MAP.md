# 🗺️ Database Connection Files - Visual Map

## 📁 Directory Structure

```
Api/
├── config/
│   └── database.php          ✅ NEW: Uses .env, Singleton pattern
│
├── conn.php                   ⚠️ OLD: Basic PDO, Hardcoded
├── conn_mysqli.php            ⚠️ OLD: MySQLi, Hardcoded  
├── conn_simple.php            🗑️ UNUSED: .env but not used
└── Database.php               ⚠️ OLD: OOP Class, Hardcoded
```

---

## 🔄 Connection Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                     API ENDPOINTS                            │
│                   (22 PHP files)                             │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ├── GROUP A (15 files) ────→ conn.php (PDO, hardcoded) ❌
                       │                              ↓
                       │                          $conn = new PDO(...)
                       │                              ↓
                       │                          Direct connection
                       │
                       ├── GROUP B (5 files) ─────→ conn_mysqli.php (MySQLi, hardcoded) ❌
                       │                              ↓
                       │                          $conn = new mysqli(...)
                       │                              ↓
                       │                          Direct connection
                       │
                       ├── GROUP C (1 file) ──────→ Database.php (Class, hardcoded) ❌
                       │                              ↓
                       │                          new Database()
                       │                              ↓
                       │                          OOP methods
                       │
                       ├── GROUP D (0 files) ─────→ conn_simple.php (.env) 🗑️
                       │                              ↓
                       │                          NOT USED ANYWHERE
                       │
                       └── GROUP E (9 files) ─────→ config/database.php (.env, singleton) ✅
                                                      ↓
                                                  getDatabaseConnection()
                                                      ↓
                                                  SimpleDotEnv → .env file
                                                      ↓
                                                  Singleton PDO connection
```

---

## 📋 File Usage Matrix

### Who Uses What?

| API File | Connection Used | Type | .env | Status |
|----------|----------------|------|------|--------|
| **GROUP E - MODERN (9 files)** |
| `sales_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `convenience_store_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `pharmacy_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `dashboard_sales_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `dashboard_return_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `stock_summary_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `combined_reports_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `batch_stock_adjustment_api.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| `backend.php` | `config/database.php` | PDO | ✅ | ✅ Good |
| **GROUP A - OLD PDO (15 files)** |
| `products_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `inventory_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `barcode_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `batch_functions_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `batch_transfer_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `purchase_order_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `inventory_transfer_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `pos_return_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `pos_exchange_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `create_purchase_order_api.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| `merge_duplicate_products.php` | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| ...and 4 more | `conn.php` | PDO | ❌ | ⚠️ Needs update |
| **GROUP B - OLD MySQLi (5 files)** |
| `login.php` | `conn_mysqli.php` | MySQLi | ❌ | ⚠️ Needs update |
| `dashboard_transfer_api.php` | `conn_mysqli.php` | MySQLi | ❌ | ⚠️ Needs update |
| ...and 3 test files | `conn_mysqli.php` | MySQLi | ❌ | ⚠️ Needs update |
| **GROUP C - OOP CLASS (1 file)** |
| `get_transferred_batches_api.php` | `Database.php` | PDO | ❌ | ⚠️ Needs update |
| **GROUP D - UNUSED (0 files)** |
| *(none)* | `conn_simple.php` | PDO | ✅ | 🗑️ Can delete |

**Total Files:** 30 API files  
**Using Modern Connection:** 9 files (30%) ✅  
**Using Old Connections:** 21 files (70%) ⚠️

---

## 🔄 Function/Class Relationships

### 1. Module Functions

Many API files use module functions that need `$conn`:

```php
// In products_api.php:
require_once __DIR__ . '/modules/products.php';
require_once __DIR__ . '/conn.php';  // Get connection

$conn = getDatabaseConnection();  // Wrong! This function doesn't exist in conn.php
$response = handle_get_products($conn, $data);  // Pass connection to module
```

**Module files don't create connections**, they receive them:

```php
// In modules/products.php:
function handle_get_products($conn, $data) {
    // Use $conn that was passed in
    $stmt = $conn->prepare("SELECT * FROM products");
    // ...
}
```

### 2. Helper Functions

```php
// modules/helpers.php
function getStockStatus($quantity) {
    // Doesn't need connection
    return $quantity > 0 ? 'in stock' : 'out of stock';
}
```

### 3. Class-Based (Database.php)

```php
// Database.php provides a class
$db = new Database();
$products = $db->select("SELECT * FROM products");
$db->insert("INSERT INTO products ...", $params);
```

---

## 🎨 Code Examples

### Current Code (Multiple Connections):

```php
// File 1: products_api.php
require_once 'conn.php';
$conn = ???; // No getDatabaseConnection() function!

// File 2: sales_api.php  
require_once 'config/database.php';
$conn = getDatabaseConnection(); // Works!

// File 3: login.php
require_once 'conn_mysqli.php';
// Uses $conn directly (MySQLi)
```

**Problem:** Inconsistent! Some files can't get connection properly.

### Recommended Code (One Connection):

```php
// ALL FILES use the same pattern:
require_once __DIR__ . '/config/database.php';
$conn = getDatabaseConnection();

// Now all files work the same way!
```

---

## ⚠️ Critical Issue Discovered

### Looking at your recent changes:

You **reverted** the .env implementation I did, which means:

**Before my changes:**
- `conn.php` had .env support ✅
- `conn_mysqli.php` had .env support ✅
- `Database.php` had .env support ✅

**After you reverted:**
- `conn.php` now has hardcoded credentials ❌
- `conn_mysqli.php` now has hardcoded credentials ❌
- `Database.php` now has hardcoded credentials ❌

**BUT you also created:**
- `config/database.php` with .env support ✅

**Result:**
- **Split codebase:** Half secure (.env), half insecure (hardcoded)
- **Confusion:** Which connection file should developers use?

---

## 🎯 What I Notice from Your Changes

### You're trying to organize into folders:

```php
// New pattern you're using:
require_once __DIR__ . '/config/database.php';  // Organized!
require_once __DIR__ . '/core/helpers.php';     // Organized!

$conn = getDatabaseConnection();
```

**This is GOOD!** ✅ But you need to complete the migration:

1. ✅ You created `config/database.php` (good organization)
2. ✅ You moved 9 files to use it (good start)
3. ❌ You reverted .env from old files (now they're insecure)
4. ⚠️ 21 files still use old connections (incomplete migration)

---

## 🚀 My Recommendation

### Complete the Migration:

Let me help you:

1. **Update the 21 remaining files** to use `config/database.php`
2. **Add `getDatabaseConnection()` helper** to old conn.php (for backward compatibility)
3. **Eventually deprecate old files** (after migration complete)
4. **Create `config/database_mysqli.php`** for login.php (MySQLi-specific)

This way:
- ✅ All files work
- ✅ All files use .env
- ✅ Backward compatible
- ✅ Organized structure

**Should I proceed with this fix?**

---

## 📚 Related Files

- `.env` - Environment variables
- `simple_dotenv.php` - Custom .env loader
- `core/helpers.php` - Helper functions
- `modules/*.php` - Business logic modules

---

**Last Updated:** October 8, 2025  
**Status:** ⚠️ **NEEDS ATTENTION**  
**Issue:** Split codebase with inconsistent connection patterns  
**Recommendation:** Complete migration to `config/database.php`
