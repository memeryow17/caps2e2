# API Directory Structure & Standards

## ✅ Implementation Status

All API endpoints have been refactored to follow best practices:

### 1. ✅ Environment Variables (.env)
- **Status:** ✅ Implemented
- All database credentials are now stored in `.env` file
- No hardcoded credentials in any API files
- Using `vlucas/phpdotenv` for environment variable management

### 2. ✅ Centralized Database Connection
- **Status:** ✅ Implemented
- **Primary Connection File:** `Api/conn.php`
- **Alternative (MySQLi):** `Api/conn_mysqli.php`
- **Helper Function:** `getDatabaseConnection()`
- All API endpoints use `require_once __DIR__ . '/conn.php'`

### 3. ⚠️ Directory Organization
- **Status:** ⚠️ Recommended (Not Implemented)
- **Reason:** Moving files would break existing frontend API calls
- **Current Structure:** Flat directory (all APIs in `/Api`)
- **Recommended Future Structure:**
  ```
  Api/
  ├── config/          # Connection files
  │   ├── conn.php
  │   ├── conn_mysqli.php
  │   └── Database.php
  ├── endpoints/       # All API endpoint files
  │   ├── auth/
  │   │   └── login.php
  │   ├── pos/
  │   │   ├── sales_api.php
  │   │   ├── pos_return_api.php
  │   │   └── pos_exchange_api.php
  │   ├── inventory/
  │   │   ├── inventory_api.php
  │   │   ├── transfer_api.php
  │   │   └── stock_summary_api.php
  │   ├── warehouse/
  │   │   ├── convenience_store_api.php
  │   │   └── pharmacy_api.php
  │   ├── products/
  │   │   ├── products_api.php
  │   │   └── barcode_api.php
  │   ├── reports/
  │   │   ├── dashboard_sales_api.php
  │   │   ├── dashboard_return_api.php
  │   │   └── combined_reports_api.php
  │   └── batch/
  │       ├── batch_functions_api.php
  │       ├── batch_transfer_api.php
  │       └── batch_stock_adjustment_api.php
  ├── modules/         # Business logic modules
  │   ├── admin.php
  │   ├── auth.php
  │   ├── inventory.php
  │   ├── products.php
  │   ├── sales.php
  │   └── helpers.php
  └── utils/           # Utility classes
      └── DatabaseUtils.php
  ```

## 📋 API Files Updated

### ✅ Refactored to use .env + conn.php:
1. `backend.php`
2. `sales_api.php`
3. `convenience_store_api.php`
4. `pharmacy_api.php`
5. `dashboard_sales_api.php`
6. `dashboard_return_api.php`
7. `stock_summary_api.php`
8. `combined_reports_api.php`
9. `batch_stock_adjustment_api.php`
10. `Database.php`
11. `conn_mysqli.php`
12. `utils/DatabaseUtils.php`

### ✅ Already using best practices:
1. `products_api.php`
2. `inventory_api.php`
3. `barcode_api.php`
4. `purchase_order_api.php`
5. All module files in `modules/`

### ✅ Updated to use __DIR__:
1. `login.php` (now uses `__DIR__ . '/conn_mysqli.php'`)

## 🔒 Security Best Practices Implemented

### ✅ Environment Variables
- Database credentials stored in `.env` file
- `.env` file is in `.gitignore` (never committed to version control)
- Environment variables loaded via `Dotenv\Dotenv::createImmutable()`

### ✅ Error Handling
- Secure error logging (no credential exposure)
- Development vs Production error messages
- Errors logged to `php_errors.log` file

### ✅ Connection Management
- Single source of truth for database connections
- PDO with prepared statements (prevents SQL injection)
- Proper error handling and connection validation

## 📝 Code Standards

### Connection Pattern:
```php
// At the top of each API file (after headers)
require_once __DIR__ . '/conn.php';
$conn = getDatabaseConnection();
```

### Environment Variables Required:
```env
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=enguio2
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
APP_ENV=development
APP_DEBUG=true
APP_TIMEZONE=UTC
```

### CORS Headers Standard:
```php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=utf-8");
```

## 🚀 Next Steps (Future Improvements)

### 1. API Versioning
- Create versioned API endpoints (e.g., `/Api/v1/`, `/Api/v2/`)
- Maintain backward compatibility

### 2. API Documentation
- Generate OpenAPI/Swagger documentation
- Document all endpoints, request/response formats

### 3. Rate Limiting
- Implement API rate limiting to prevent abuse
- Add authentication tokens for API access

### 4. Caching
- Implement Redis/Memcached for frequently accessed data
- Reduce database load for read-heavy operations

### 5. API Gateway
- Consider implementing an API gateway for centralized routing
- Simplify frontend API calls

## 📚 Related Documentation

- `ENV_README.md` - Environment variables setup guide
- `AGENTS.md` - Code style guidelines
- `PROJECT_STRUCTURE.md` - Overall project structure

## 🛠️ Maintenance Notes

- **Last Updated:** October 8, 2025
- **PHP Version:** 8.x
- **Framework:** Custom (Next.js frontend + PHP backend)
- **Database:** MySQL (via PDO)

## ⚠️ Important Warnings

1. **Do NOT move API files** without updating frontend calls
2. **Do NOT commit** `.env` file to version control
3. **Always test** API changes in development before production
4. **Keep backup** of working `.env` configuration

---

*This document is automatically generated and should be updated whenever API structure changes.*
