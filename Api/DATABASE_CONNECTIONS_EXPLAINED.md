# 📚 Database Connection Files Explained

## Overview
You have **4 different database connection files** in your API folder. This happened because the codebase evolved over time with different approaches. Let me explain each one and their relationships.

---

## 🔍 The 4 Connection Files

### 1. **`conn.php`** - Basic PDO Connection (REVERTED)
**Location:** `Api/conn.php`  
**Type:** PDO (PHP Data Objects)  
**Status:** ⚠️ **Currently has hardcoded credentials (reverted from .env)**

```php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "enguio2";

$conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
```

**Used By:**
- ✅ `products_api.php`
- ✅ `inventory_api.php`
- ✅ `barcode_api.php`
- ✅ `batch_functions_api.php`
- ✅ `batch_transfer_api.php`
- ✅ `purchase_order_api.php`
- ✅ `inventory_transfer_api.php`
- ✅ And 8 more files...

**Purpose:** Basic PDO connection for most API endpoints. Simple and straightforward.

---

### 2. **`conn_mysqli.php`** - MySQLi Connection (REVERTED)
**Location:** `Api/conn_mysqli.php`  
**Type:** MySQLi (MySQL Improved)  
**Status:** ⚠️ **Currently has hardcoded credentials (reverted from .env)**

```php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "enguio2";

$conn = new mysqli($servername, $username, $password, $dbname);
```

**Used By:**
- ✅ `login.php`
- ✅ `dashboard_transfer_api.php`
- ✅ Test files

**Purpose:** MySQLi connection (different from PDO). Used by login system because it was built with MySQLi.

**Key Difference from PDO:**
- MySQLi: `$stmt->bind_param("s", $username);`
- PDO: `$stmt->execute([$username]);`

---

### 3. **`conn_simple.php`** - PDO with Simple .env
**Location:** `Api/conn_simple.php`  
**Type:** PDO with SimpleDotEnv
**Status:** ✅ **Uses .env variables (without Composer)**

```php
require_once __DIR__ . '/../simple_dotenv.php';
$dotenv = new SimpleDotEnv(__DIR__ . '/..');
$dotenv->load();

$servername = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_DATABASE'];
// ... creates PDO connection
```

**Used By:**
- ⚠️ **Currently NOT used by any active API files**

**Purpose:** Alternative .env approach without requiring Composer. Uses a custom `simple_dotenv.php` loader.

---

### 4. **`Database.php`** - Database Class (REVERTED)
**Location:** `Api/Database.php`  
**Type:** PDO wrapped in a class
**Status:** ⚠️ **Currently has hardcoded credentials (reverted from .env)**

```php
class Database {
    private $host = 'localhost';
    private $dbname = 'enguio2';
    
    public function select($sql, $params = []) { }
    public function insert($sql, $params = []) { }
    public function update($sql, $params = []) { }
    // ... helper methods
}
```

**Used By:**
- ✅ `get_transferred_batches_api.php`

**Purpose:** Object-oriented approach with helper methods for common database operations.

---

### 5. **`config/database.php`** - NEW Centralized Connection ✨
**Location:** `Api/config/database.php`  
**Type:** PDO with .env (SimpleDotEnv)  
**Status:** ✅ **Modern approach - Uses .env and singleton pattern**

```php
function getDatabaseConnection() {
    static $conn = null; // Singleton pattern
    
    if ($conn !== null) {
        return $conn;
    }
    
    // Load from .env
    $servername = $_ENV['DB_HOST'] ?? 'localhost';
    $dbname = $_ENV['DB_DATABASE'] ?? 'enguio2';
    // ... creates PDO connection once
}
```

**Used By:**
- ✅ `sales_api.php`
- ✅ `convenience_store_api.php`
- ✅ `pharmacy_api.php`
- ✅ `dashboard_sales_api.php`
- ✅ `dashboard_return_api.php`
- ✅ `stock_summary_api.php`
- ✅ `combined_reports_api.php`
- ✅ `batch_stock_adjustment_api.php`
- ✅ `backend.php`

**Purpose:** **NEW standard** - Centralized, uses .env, singleton pattern (only creates one connection).

---

## 📊 Current State Analysis

### What Happened? 🤔

You implemented .env support, but then **reverted** the changes to the original connection files (`conn.php`, `conn_mysqli.php`, `Database.php`).

**Current Situation:**
```
Api/
├── conn.php              ❌ REVERTED (hardcoded credentials)
├── conn_mysqli.php       ❌ REVERTED (hardcoded credentials)
├── conn_simple.php       ✅ Uses .env (but NOT USED by any files)
├── Database.php          ❌ REVERTED (hardcoded credentials)
└── config/
    └── database.php      ✅ Uses .env (BEING USED by newer APIs)
```

### The Split Situation:

**Group A - Using OLD connections (hardcoded):**
- 15 files using `conn.php` ❌
- 5 files using `conn_mysqli.php` ❌
- 1 file using `Database.php` ❌

**Group B - Using NEW connection (.env):**
- 9 files using `config/database.php` ✅

---

## 🔗 File Relationships

### Connection Flow:

```
API Files
    ↓
    ├─→ conn.php (PDO, hardcoded)
    │   ├─→ products_api.php
    │   ├─→ inventory_api.php
    │   ├─→ barcode_api.php
    │   └─→ 12 more files...
    │
    ├─→ conn_mysqli.php (MySQLi, hardcoded)
    │   ├─→ login.php
    │   └─→ dashboard_transfer_api.php
    │
    ├─→ Database.php (Class, hardcoded)
    │   └─→ get_transferred_batches_api.php
    │
    ├─→ conn_simple.php (PDO, .env, NOT USED)
    │   └─→ ❌ No files using this
    │
    └─→ config/database.php (PDO, .env, NEW)
        ├─→ sales_api.php
        ├─→ convenience_store_api.php
        ├─→ pharmacy_api.php
        ├─→ dashboard_sales_api.php
        ├─→ dashboard_return_api.php
        ├─→ stock_summary_api.php
        ├─→ combined_reports_api.php
        ├─→ batch_stock_adjustment_api.php
        └─→ backend.php
```

---

## 🎯 Why Multiple Connection Files?

### Historical Reasons:

1. **`conn.php`** - Original connection file (first version)
2. **`conn_mysqli.php`** - Created when login needed MySQLi instead of PDO
3. **`Database.php`** - Someone tried OOP approach for cleaner code
4. **`conn_simple.php`** - Attempted .env without Composer
5. **`config/database.php`** - **New standard** with proper .env support

### Technical Reasons:

| File | Library | Pattern | .env Support | Reusability |
|------|---------|---------|--------------|-------------|
| `conn.php` | PDO | Procedural | ❌ No | Low |
| `conn_mysqli.php` | MySQLi | Procedural | ❌ No | Low |
| `conn_simple.php` | PDO | Procedural | ✅ Yes | Medium |
| `Database.php` | PDO | OOP Class | ❌ No | High |
| `config/database.php` | PDO | Function + Singleton | ✅ Yes | **Highest** |

---

## 🔥 The Problem

### Security Issues:
1. **Hardcoded credentials** in 3 out of 5 files ❌
2. **Exposed in version control** (Git) ❌
3. **Difficult to change** per environment ❌
4. **Inconsistent patterns** across codebase ❌

### Maintainability Issues:
1. **4-5 different connection files** = confusion
2. **Split codebase** - some use .env, some don't
3. **Duplicate code** - same connection logic repeated
4. **No single source of truth**

---

## ✅ Recommended Solution

### Option 1: Keep NEW Standard (Recommended) ⭐

**Keep:** `Api/config/database.php` as the **ONLY** connection file

**Action Plan:**
1. ✅ Keep `config/database.php` (uses .env, singleton pattern)
2. 🔄 Migrate ALL files to use `config/database.php`
3. ❌ Delete `conn.php`, `conn_mysqli.php`, `Database.php`
4. ❌ Delete `conn_simple.php` (not being used)

**Benefits:**
- ✅ Single source of truth
- ✅ Uses .env for security
- ✅ Singleton pattern (efficient)
- ✅ Consistent across all APIs
- ✅ Easy to maintain

---

### Option 2: Update OLD Files to Use .env

**Action Plan:**
1. Re-implement .env support in `conn.php`
2. Re-implement .env support in `conn_mysqli.php`
3. Re-implement .env support in `Database.php`
4. Keep all 4 connection files

**Benefits:**
- ✅ Less code changes needed
- ✅ Backward compatible

**Drawbacks:**
- ❌ Still have 4+ connection files
- ❌ Duplicate code
- ❌ Harder to maintain

---

## 🎯 My Recommendation

### **Consolidate to TWO files only:**

1. **`Api/config/database.php`** (PRIMARY) - For PDO connections
   - Most modern
   - Uses .env
   - Singleton pattern
   - Use this for **ALL new code**

2. **`Api/config/database_mysqli.php`** (LEGACY) - For MySQLi only
   - Only for `login.php` (uses MySQLi-specific functions)
   - Can be migrated to PDO later

**Delete these files:**
- ❌ `conn.php` (replace with `config/database.php`)
- ❌ `conn_simple.php` (not used)
- ❌ `Database.php` (use `config/database.php` instead)

---

## 📝 Function Dependencies

### Helper Functions Across Files:

```php
// In config/database.php
function getDatabaseConnection() {
    // Returns singleton PDO connection
}

// Used by many files:
require_once __DIR__ . '/config/database.php';
$conn = getDatabaseConnection();
```

### Module Dependencies:

```
API Files
    ↓
require_once 'modules/products.php'
    ↓
Function: handle_get_products($conn, $data)
    ↓
Uses $conn passed from API file
```

---

## 🚀 Quick Fix Guide

### To fix the current split situation:

**Step 1:** Check what you prefer:
- **Option A:** Keep the NEW `config/database.php` approach (recommended)
- **Option B:** Go back to OLD approach but add .env support

**Step 2:** I can help you:
1. Migrate all files to use ONE connection file
2. Remove duplicate connection files
3. Update all `require_once` statements
4. Test all endpoints

Would you like me to:
- **A)** Consolidate everything to use `config/database.php`?
- **B)** Re-implement .env in the old files?
- **C)** Explain more about any specific file?

---

## 📋 Summary Table

| File | Type | .env | Singleton | Used By | Status |
|------|------|------|-----------|---------|--------|
| `conn.php` | PDO | ❌ | ❌ | 15 files | ⚠️ Hardcoded |
| `conn_mysqli.php` | MySQLi | ❌ | ❌ | 5 files | ⚠️ Hardcoded |
| `conn_simple.php` | PDO | ✅ | ❌ | 0 files | 🗑️ Unused |
| `Database.php` | PDO Class | ❌ | ❌ | 1 file | ⚠️ Hardcoded |
| `config/database.php` | PDO | ✅ | ✅ | 9 files | ✅ **Best** |

---

## 💡 Why This Matters

### Security Risk:
```php
// ❌ BAD (Current state in 3 files)
$username = "root";
$password = "";  // Exposed in Git!

// ✅ GOOD (config/database.php)
$username = $_ENV['DB_USERNAME']; // From .env file
```

### Efficiency:
```php
// ❌ OLD (Creates new connection every time)
require 'conn.php'; // New connection
require 'conn.php'; // Another new connection!

// ✅ NEW (Singleton - reuses connection)
getDatabaseConnection(); // Creates connection
getDatabaseConnection(); // Returns same connection
```

---

## 🔧 Technical Details

### PDO vs MySQLi:

**PDO (PHP Data Objects):**
```php
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
```

**MySQLi:**
```php
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
```

**Why PDO is Better:**
- ✅ Works with multiple databases (MySQL, PostgreSQL, SQLite)
- ✅ Cleaner syntax
- ✅ Better error handling
- ✅ Named parameters support

---

## 🎬 What Should You Do?

### Immediate Action Required:

**You have a split codebase:**
- 50% of files use hardcoded connections ❌
- 50% of files use .env connections ✅

**This creates:**
- Security vulnerabilities
- Maintenance nightmares
- Confusion for developers

### I Recommend:

**Let me consolidate everything to use `config/database.php`**

This will:
1. ✅ Remove security vulnerabilities
2. ✅ Eliminate duplicate code
3. ✅ Make maintenance easier
4. ✅ Standardize your codebase
5. ✅ Use modern best practices

---

## 📞 Next Steps

Please tell me which option you prefer:

**Option A (Recommended):** 
- Consolidate to `config/database.php` only
- Migrate all 21 files to use this one connection
- Delete the 3 duplicate connection files

**Option B:**
- Keep current structure
- Add .env support back to `conn.php`, `conn_mysqli.php`, `Database.php`
- Update documentation

**Option C:**
- Something else? Let me know your preference!

---

**Created:** October 8, 2025  
**Purpose:** Explain database connection file redundancy  
**Recommendation:** Consolidate to single connection file with .env support
