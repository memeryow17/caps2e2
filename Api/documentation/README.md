# ENGUIO API - Best Practices Guide

## Overview
This API follows PHP best practices for security, maintainability, and scalability.

## Best Practices Implemented

### 1. Environment Variables (.env)
All sensitive configuration data (database credentials, API URLs) are stored in a `.env` file, which is:
- **NOT** committed to version control
- Loaded dynamically via `config.php`
- Easy to change per environment (development, staging, production)

#### Setup Instructions:
1. Copy the example environment file:
   ```bash
   cp ../env.example.txt ../.env
   ```

2. Edit the `.env` file with your actual credentials:
   ```env
   DB_HOST=localhost
   DB_USERNAME=root
   DB_PASSWORD=your_password
   DB_NAME=enguio2
   DB_CHARSET=utf8mb4
   
   CORS_ORIGIN=http://localhost:3000
   ```

### 2. Database Connection Files

#### conn.php (PDO Connection)
- Uses `require_once __DIR__ . '/config.php'` to load configuration
- PDO with prepared statements for security
- Proper error handling with environment-aware messages

**Usage:**
```php
require_once __DIR__ . '/conn.php';
// $conn is now available as a PDO instance
```

#### conn_mysqli.php (MySQLi Connection)
- Uses `require_once __DIR__ . '/config.php'` to load configuration
- MySQLi with proper charset configuration
- Error handling with JSON responses

**Usage:**
```php
require_once __DIR__ . '/conn_mysqli.php';
// $conn is now available as a MySQLi instance
```

#### Database.php (PDO Class)
- Object-oriented approach with helper methods
- Methods: `select()`, `selectOne()`, `insert()`, `update()`, `delete()`
- Transaction support: `beginTransaction()`, `commit()`, `rollback()`

**Usage:**
```php
require_once __DIR__ . '/Database.php';
$db = new Database();
$users = $db->select("SELECT * FROM users WHERE status = ?", ['active']);
```

### 3. Configuration Management

#### config.php
Central configuration file that:
- Loads environment variables from `.env` file
- Provides `Config::get()` method for accessing configuration
- Includes helper methods: `Config::isDebug()`, `Config::isProduction()`

**Usage:**
```php
require_once __DIR__ . '/config.php';
$dbHost = Config::get('DB_HOST');
$isDebug = Config::isDebug();
```

### 4. CORS Configuration

#### cors.php
Centralized CORS header management:
- CORS origin loaded from environment variables
- Handles preflight OPTIONS requests
- Consistent across all API endpoints

**Usage:**
```php
// Must be first line in API file, before any output
require_once __DIR__ . '/cors.php';
```

### 5. Helper Functions

#### modules/helpers.php
Reusable helper functions:
- `getDatabaseConnection()` - Get PDO connection with environment variables
- `getStockStatus()` - Get stock status based on quantity
- `logActivity()` - Log user activity to database
- `setupApiEnvironment()` - Setup common API environment
- Response helpers: `sendJsonResponse()`, `sendErrorResponse()`, `sendSuccessResponse()`

**Usage:**
```php
require_once __DIR__ . '/modules/helpers.php';
$conn = getDatabaseConnection();
sendJsonResponse(['success' => true, 'data' => $results]);
```

## File Structure
```
Api/
├── config.php              # Configuration loader (loads .env)
├── cors.php                # CORS headers configuration
├── conn.php                # PDO database connection
├── conn_mysqli.php         # MySQLi database connection
├── Database.php            # Database class with helper methods
├── modules/
│   ├── helpers.php         # Helper functions
│   ├── auth.php            # Authentication functions
│   ├── products.php        # Product management
│   ├── inventory.php       # Inventory management
│   └── ...
├── sales_api.php           # Sales API endpoint
├── inventory_api.php       # Inventory API endpoint
└── ...
```

## Creating New API Files

### Template for New API File:
```php
<?php
/**
 * Your API Name
 * Description of what this API does
 * 
 * Best Practice:
 * - CORS configuration loaded from environment variables
 * - Database credentials from .env file
 */

// Load CORS configuration (must be first, before any output)
require_once __DIR__ . '/cors.php';

// Start output buffering
ob_start();

// Disable error display in production
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Load database connection
require_once __DIR__ . '/conn.php';

// Load helper functions
require_once __DIR__ . '/modules/helpers.php';

// Clear any output that might have been generated
ob_clean();

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

$action = $data['action'] ?? '';

try {
    switch ($action) {
        case 'your_action':
            // Your code here
            echo json_encode(['success' => true, 'data' => $result]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => Config::isDebug() ? $e->getMessage() : 'An error occurred'
    ]);
}
?>
```

## Security Best Practices

### 1. Always Use Prepared Statements
```php
// Good ✓
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);

// Bad ✗
$result = $conn->query("SELECT * FROM users WHERE id = $userId");
```

### 2. Never Expose Sensitive Information in Production
```php
// Use Config::isDebug() to control error messages
$message = Config::isDebug() 
    ? "Database error: " . $e->getMessage()
    : "An error occurred";
```

### 3. Always Validate Input
```php
// Validate required fields
if (empty($data['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

// Sanitize input
$userId = intval($data['user_id']);
$username = trim($data['username']);
```

### 4. Use require_once for Includes
```php
// Good ✓
require_once __DIR__ . '/conn.php';

// Bad ✗
include 'conn.php';  // May include multiple times
require 'conn.php';  // Relative path issues
```

### 5. Always Return JSON Responses
```php
header('Content-Type: application/json');
echo json_encode(['success' => true, 'data' => $result]);
exit();
```

## Testing

### Connection Test:
```bash
php Api/connection_test.php
```

### Test Individual APIs:
```bash
curl -X POST http://localhost/Api/sales_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"test"}'
```

## Troubleshooting

### Issue: Database Connection Failed
1. Check `.env` file exists and has correct credentials
2. Verify database server is running
3. Check database user has proper permissions
4. Check `php_errors.log` for detailed error messages

### Issue: CORS Errors
1. Verify `CORS_ORIGIN` in `.env` matches your frontend URL
2. Check that `cors.php` is loaded first in API file
3. Clear browser cache and try again

### Issue: "Config class not found"
1. Ensure `config.php` is loaded before using Config class
2. Check file path in `require_once` statement
3. Verify `config.php` exists in Api directory

## Migration from Old Code

### Old Pattern:
```php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "enguio2";
$conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
```

### New Pattern:
```php
require_once __DIR__ . '/conn.php';
// $conn is now available with credentials from .env
```

## Environment-Specific Configuration

### Development (.env):
```env
APP_ENV=development
APP_DEBUG=true
DB_HOST=localhost
CORS_ORIGIN=http://localhost:3000
```

### Production (.env):
```env
APP_ENV=production
APP_DEBUG=false
DB_HOST=production-db-server
CORS_ORIGIN=https://your-production-domain.com
```

## Additional Resources

- [PHP PDO Documentation](https://www.php.net/manual/en/book.pdo.php)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [OWASP PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)

## Support

For issues or questions, please refer to:
- Check `php_errors.log` for detailed error messages
- Review this README for best practices
- Contact the development team

---
**Last Updated:** October 2025
**Version:** 2.0
