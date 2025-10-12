<?php
// CORS headers must be set first, before any output
// Load environment variables for CORS configuration
require_once __DIR__ . '/../simple_dotenv.php';
$dotenv = new SimpleDotEnv(__DIR__ . '/..');
$dotenv->load();

// Get allowed origins from environment variable (comma-separated)
$corsOriginsEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:3000,http://localhost:3001';
$allowed_origins = array_map('trim', explode(',', $corsOriginsEnv));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Fallback to first allowed origin for development
    header("Access-Control-Allow-Origin: " . $allowed_origins[0]);
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours
header("Content-Type: application/json; charset=utf-8");

// Handle preflight OPTIONS requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start output buffering to prevent unwanted output
ob_start();

// Register shutdown handler to catch fatal errors and always return JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            "success" => false,
            "message" => "Fatal error: " . $error['message'],
            "error" => $error
        ]);
        exit;
    }
});

session_start();

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Log errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Helper function to get stock status based on quantity
function getStockStatus($quantity, $lowStockThreshold = 10) {
    $qty = intval($quantity);
    if ($qty <= 0) {
        return 'out of stock';
    } elseif ($qty <= $lowStockThreshold) {
        return 'low stock';
    } else {
        return 'in stock';
    }
}

// Helper function to get stock status SQL case statement
function getStockStatusSQL($quantityField, $lowStockThreshold = 10) {
    return "CASE 
        WHEN {$quantityField} <= 0 THEN 'out of stock'
        WHEN {$quantityField} <= {$lowStockThreshold} THEN 'low stock'
        ELSE 'in stock'
    END";
}


// Use centralized database connection
require_once __DIR__ . '/conn.php';

// Don't clear output buffer as it contains CORS headers

// Helper function to get employee details for stock movement logging
function getEmployeeDetails($conn, $employee_id_or_username) {
    try {
        $empStmt = $conn->prepare("SELECT emp_id, username, CONCAT(Fname, ' ', Lname) as full_name, role_id FROM tbl_employee WHERE emp_id = ? OR username = ? LIMIT 1");
        $empStmt->execute([$employee_id_or_username, $employee_id_or_username]);
        $empData = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        // Map role_id to role name
        $roleMapping = [
            1 => 'Cashier',
            2 => 'Inventory Manager', 
            3 => 'Supervisor',
            4 => 'Admin',
            5 => 'Manager'
        ];
        $empRole = $roleMapping[$empData['role_id'] ?? 4] ?? 'Admin';
        $empName = $empData['full_name'] ?? $employee_id_or_username;
        
        return [
            'emp_id' => $empData['emp_id'] ?? $employee_id_or_username,
            'emp_name' => $empName,
            'emp_role' => $empRole,
            'formatted_name' => "{$empName} ({$empRole})"
        ];
    } catch (Exception $e) {
        return [
            'emp_id' => $employee_id_or_username,
            'emp_name' => $employee_id_or_username,
            'emp_role' => 'Admin',
            'formatted_name' => "{$employee_id_or_username} (Admin)"
        ];
    }
}

// Read and decode incoming JSON request
$rawData = file_get_contents("php://input");
// error_log("Raw input: " . $rawData);

$data = json_decode($rawData, true);

// Check if JSON is valid
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON input: " . json_last_error_msg(),
        "raw" => $rawData
    ]);
    exit;
}

// Check if 'action' is set
if (!isset($data['action'])) {
    echo json_encode([
        "success" => false,
        "message" => "Missing action"
    ]);
    exit;
}

// Action handler
$action = $data['action'];
error_log("Processing action: " . $action . " from " . $_SERVER['REMOTE_ADDR']);

try {
    /**
     * Direct database queries for dashboard chart data
     */
    function getSalesChartDataDirect($conn, $days = 7) {
        try {
            $days = max(1, min(30, (int)$days)); // Sanitize as integer
            
            $sql = "
                SELECT 
                    DATE(pt.date) as sales_date,
                    COALESCE(SUM(psh.total_amount), 0) as daily_sales_amount
                FROM tbl_pos_transaction pt
                JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
                WHERE DATE(pt.date) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                GROUP BY DATE(pt.date)
                ORDER BY DATE(pt.date) DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $chartData = [];
            foreach ($results as $row) {
                $chartData[] = [
                    'day' => date('d', strtotime($row['sales_date'])),
                    'totalSales' => (float)$row['daily_sales_amount']
                ];
            }
            
            return ['data' => $chartData];
        } catch (Exception $e) {
            return ['data' => []];
        }
    }

    function getTransferChartDataDirect($conn, $days = 7) {
        try {
            $days = max(1, min(30, (int)$days)); // Sanitize as integer
            
            $sql = "
                SELECT 
                    DATE(date) as transfer_date,
                    COUNT(*) as daily_transfer_count
                FROM tbl_transfer_header
                WHERE DATE(date) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                GROUP BY DATE(date)
                ORDER BY DATE(date) DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $chartData = [];
            foreach ($results as $row) {
                $chartData[] = [
                    'day' => date('d', strtotime($row['transfer_date'])),
                    'totalTransfer' => (int)$row['daily_transfer_count']
                ];
            }
            
            return ['data' => $chartData];
        } catch (Exception $e) {
            return ['data' => []];
        }
    }

    function getReturnChartDataDirect($conn, $days = 7) {
        try {
            $days = max(1, min(30, (int)$days)); // Sanitize as integer
            
            $sql = "
                SELECT 
                    DATE(pr.created_at) as return_date,
                    COALESCE(SUM(pr.total_refund), 0) as daily_return_amount
                FROM tbl_pos_returns pr
                WHERE DATE(pr.created_at) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                AND pr.status IN ('pending', 'approved', 'completed')
                GROUP BY DATE(pr.created_at)
                ORDER BY DATE(pr.created_at) DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $chartData = [];
            foreach ($results as $row) {
                $chartData[] = [
                    'day' => date('d', strtotime($row['return_date'])),
                    'totalReturn' => (float)$row['daily_return_amount']
                ];
            }
            
            return ['data' => $chartData];
        } catch (Exception $e) {
            return ['data' => []];
        }
    }

    function getTopSellingProductsDirect($conn, $limit = 5) {
        try {
            $sql = "
                SELECT 
                    p.product_name,
                    SUM(psd.quantity) as total_quantity_sold,
                    SUM(psd.quantity * psd.price) as total_sales_amount,
                    p.status,
                    p.stock_status
                FROM tbl_pos_sales_details psd
                JOIN tbl_pos_sales_header psh ON psd.sales_header_id = psh.sales_header_id
                JOIN tbl_product p ON psd.product_id = p.product_id
                WHERE p.status IS NULL OR p.status <> 'archived'
                GROUP BY p.product_id, p.product_name, p.status, p.stock_status
                ORDER BY total_quantity_sold DESC
                LIMIT :limit
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $topProducts = [];
            foreach ($results as $row) {
                $topProducts[] = [
                    'name' => $row['product_name'],
                    'quantity' => (int)$row['total_quantity_sold'],
                    'sales' => number_format((float)$row['total_sales_amount'], 2),
                    'status' => $row['stock_status'] === 'in stock' ? 'In Stock' : 'Out of Stock'
                ];
            }
            
            return $topProducts;
        } catch (Exception $e) {
            return [];
        }
    }

    function getInventoryAlertsDirect($conn) {
        try {
            $sql = "
                SELECT 
                    p.product_name,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    p.stock_status,
                    l.location_name,
                    CASE 
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) = 0 THEN 'Out of Stock'
                        WHEN p.stock_status = 'out of stock' THEN 'Stock Out'
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 10 THEN 'Low Stock'
                        ELSE 'In Stock'
                    END as alert_type
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND (COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) = 0 OR COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 10 OR p.stock_status = 'out of stock')
                ORDER BY 
                    CASE 
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) = 0 THEN 1
                        WHEN p.stock_status = 'out of stock' THEN 2
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 10 THEN 3
                        ELSE 4
                    END,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) ASC
                LIMIT 10
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $alerts = [];
            foreach ($results as $row) {
                $alerts[] = [
                    'name' => $row['product_name'],
                    'quantity' => (int)$row['quantity'],
                    'location' => $row['location_name'] ?? 'Unknown',
                    'alert_type' => $row['alert_type'],
                    'alerts' => $row['alert_type']
                ];
            }
            
            return $alerts;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Helper function to get data from separate API files
     */
    function getDashboardDataFromAPI($apiFile, $action, $params = []) {
    try {
        // Prepare request data
        $requestData = array_merge(['action' => $action], $params);
        
        // Get API base URL from environment variable
        $apiBaseUrl = $_ENV['API_BASE_URL'] ?? 'http://localhost/caps2e2/Api';
        
        // Make API call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiBaseUrl . '/' . $apiFile);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if ($result && isset($result['success']) && $result['success']) {
                return $result['data'] ?? [];
            }
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log("Error calling API $apiFile: " . $e->getMessage());
        return [];
    }
}

switch ($action) {
    case 'test_connection':
        echo json_encode([
            "success" => true,
            "message" => "API connection successful",
            "timestamp" => date('Y-m-d H:i:s'),
            "database" => "Connected to enguio2 database"
        ]);
        break;
    
    case 'check_table_structure':
        try {
            $stmt = $conn->prepare("DESCRIBE tbl_product");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "columns" => $columns
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'debug_brands_suppliers':
        try {
            // Get all brands
            $brandStmt = $conn->prepare("SELECT * FROM tbl_brand ORDER BY brand_id");
            $brandStmt->execute();
            $brands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get all suppliers
            $supplierStmt = $conn->prepare("SELECT * FROM tbl_supplier ORDER BY supplier_id");
            $supplierStmt->execute();
            $suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get existing products
            $productStmt = $conn->prepare("SELECT product_id, product_name, brand_id, supplier_id, product_type FROM tbl_product ORDER BY product_id");
            $productStmt->execute();
            $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "brands" => $brands,
                "suppliers" => $suppliers,
                "products" => $products,
                "brand_count" => count($brands),
                "supplier_count" => count($suppliers),
                "will_use_brand_id" => count($brands) > 0 ? $brands[0]['brand_id'] : null,
                "will_use_supplier_id" => count($suppliers) > 0 ? $suppliers[0]['supplier_id'] : null
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'clear_brands':
        try {
            // Update existing products to have NULL brand_id
            $updateStmt = $conn->prepare("UPDATE tbl_product SET brand_id = NULL WHERE brand_id IS NOT NULL");
            $updateStmt->execute();
            $updatedProducts = $updateStmt->rowCount();
            
            // Clear the brand table
            $deleteStmt = $conn->prepare("DELETE FROM tbl_brand");
            $deleteStmt->execute();
            $deletedBrands = $deleteStmt->rowCount();
            
            // Reset auto-increment
            $conn->exec("ALTER TABLE tbl_brand AUTO_INCREMENT = 1");
            
            echo json_encode([
                "success" => true,
                "message" => "Brands table cleared successfully",
                "updated_products" => $updatedProducts,
                "deleted_brands" => $deletedBrands
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    case 'add_employee':
        try {
            // Extract and sanitize input data
            $fname = isset($data['fname'])&& !empty($data['fname']) ? trim($data['fname']) : '';
            $mname = isset($data['mname']) && !empty($data['mname'])? trim($data['mname']) : '';
            $lname = isset($data['lname']) && !empty($data['lname'])? trim($data['lname']) : '';
            $email = isset($data['email']) ? trim($data['email']) : '';
            $contact = isset($data['contact_num']) ? trim($data['contact_num']) : '';
            $role_id = isset($data['role_id']) ? trim($data['role_id']) : '';
            $shift_id = isset($data['shift_id']) && $data['shift_id'] !== null && $data['shift_id'] !== '' ? (int)$data['shift_id'] : null;
            $username = isset($data['username']) ? trim($data['username']) : '';
            $password = isset($data['password']) ? trim($data['password']) : '';
            $age = isset($data['age']) ? trim($data['age']) : '';
            $address = isset($data['address']) ? trim($data['address']) : '';
            $status = isset($data['status']) ? trim($data['status']) : 'Active';
            $gender = isset($data['gender']) ? trim($data['gender']) : '';
            $birthdate = isset($data['birthdate']) ? trim($data['birthdate']) : '';

            // Only require shift_id for cashier (3) and pharmacist (2)
            if (($role_id == 2 || $role_id == 3) && empty($shift_id)) {
                echo json_encode(["success" => false, "message" => "Shift is required."]);
                exit;
            }

            // Hash the password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Prepare the SQL statement
            $stmt = $conn->prepare("
                INSERT INTO tbl_employee (
                    Fname, Mname, Lname, email, contact_num, role_id, shift_id,
                    username, password, age, address, status,gender,birthdate
                ) VALUES (
                    :fname, :mname, :lname, :email, :contact_num, :role_id, :shift_id,
                    :username, :password, :age, :address, :status, :gender, :birthdate
                )
            ");

            // Bind parameters
            $stmt->bindParam(":fname", $fname, PDO::PARAM_STR);
            $stmt->bindParam(":mname", $mname, PDO::PARAM_STR);
            $stmt->bindParam(":lname", $lname, PDO::PARAM_STR);
            $stmt->bindParam(":email", $email, PDO::PARAM_STR);
            $stmt->bindParam(":contact_num", $contact, PDO::PARAM_STR);
            $stmt->bindParam(":role_id", $role_id, PDO::PARAM_INT);
            if ($shift_id !== null) {
                $stmt->bindValue(":shift_id", $shift_id, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":shift_id", null, PDO::PARAM_NULL);
            }
            $stmt->bindParam(":username", $username, PDO::PARAM_STR);
            $stmt->bindParam(":password", $hashedPassword, PDO::PARAM_STR);
            $stmt->bindParam(":age", $age, PDO::PARAM_INT);
            $stmt->bindParam(":address", $address, PDO::PARAM_STR);
            $stmt->bindParam(":status", $status, PDO::PARAM_STR);
            $stmt->bindParam(":gender", $gender, PDO::PARAM_STR);
            $stmt->bindParam(":birthdate", $birthdate, PDO::PARAM_STR);

            // Execute the statement
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Employee added successfully"]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to add employee"]);
            }

        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
        }
        break;

            case 'login':
                try {
                    $username = isset($data['username']) ? trim($data['username']) : '';
                    $password = isset($data['password']) ? trim($data['password']) : '';
                    $captcha = isset($data['captcha']) ? trim($data['captcha']) : '';
                    $captchaAnswer = isset($data['captchaAnswer']) ? trim($data['captchaAnswer']) : '';
        
                    // Validate inputs
                    if (empty($username) || empty($password)) {
                        echo json_encode(["success" => false, "message" => "Username and password are required"]);
                        exit;
                    }
        
                    // Verify captcha
                    // if (empty($captcha) || empty($captchaAnswer) || $captcha !== $captchaAnswer) {
                    //     echo json_encode(["success" => false, "message" => "Invalid captcha"]);
                    //     exit;
                    // }    
                // Check if user exists (regardless of status)
                $stmt = $conn->prepare("
                    SELECT e.emp_id, e.username, e.password, e.status, e.Fname, e.Lname, e.role_id, e.shift_id, r.role 
                    FROM tbl_employee e 
                    JOIN tbl_role r ON e.role_id = r.role_id 
                    WHERE e.username = :username
                ");
                $stmt->bindParam(":username", $username, PDO::PARAM_STR);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
                // If user exists but is inactive, return a specific message
                if ($user && strcasecmp($user['status'] ?? '', 'Active') !== 0) {
                    echo json_encode(["success" => false, "message" => "User is inactive. Please contact the administrator."]);
                    break;
                }
    
                // Check password - handle both hashed and plain text passwords
                $passwordValid = false;
                if ($user) {
                    // First try to verify as hashed password
                    if (password_verify($password, $user['password'])) {
                        $passwordValid = true;
                    } 
                    // If that fails, check if it's a plain text password (for backward compatibility)
                    elseif ($password === $user['password']) {
                        $passwordValid = true;
                    }
                }
    
                if ($user && $passwordValid) {
                    // Start session and store user data
                    session_start();
                    $_SESSION['user_id'] = $user['emp_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['Fname'] . ' ' . $user['Lname'];
    
                    // Log login activity to tbl_login
                    try {
                        $loginStmt = $conn->prepare("
                            INSERT INTO tbl_login (emp_id, role_id, username, login_time, login_date, ip_address, location, terminal_id, shift_id) 
                            VALUES (:emp_id, :role_id, :username, CURTIME(), CURDATE(), :ip_address, :location, :terminal_id, :shift_id)
                        ");
                        
                        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        
                        $loginStmt->bindParam(':emp_id', $user['emp_id'], PDO::PARAM_INT);
                        $loginStmt->bindParam(':role_id', $user['role_id'], PDO::PARAM_INT);
                        $loginStmt->bindParam(':username', $user['username'], PDO::PARAM_STR);
                        $loginStmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
                        $loginStmt->bindParam(':location', $location_label, PDO::PARAM_STR);
                        $loginStmt->bindParam(':terminal_id', $terminal_id, PDO::PARAM_INT);
                        $loginStmt->bindParam(':shift_id', $user['shift_id'], PDO::PARAM_INT);
                        
                        $loginStmt->execute();
                        
                        // Store login_id in session for logout tracking
                        $_SESSION['login_id'] = $conn->lastInsertId();
                        $login_id_inserted = $_SESSION['login_id'];
                        
                        // Log login activity to activity log
                        try {
                            $activityStmt = $conn->prepare("
                                INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) 
                                VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW())
                            ");
                            
                            $activityStmt->execute([
                                ':user_id' => $user['emp_id'],
                                ':username' => $user['username'],
                                ':role' => $user['role'],
                                ':activity_type' => 'LOGIN',
                                ':activity_description' => 'User logged in successfully',
                                ':table_name' => 'tbl_login',
                                ':record_id' => $login_id_inserted
                            ]);
                        } catch (Exception $activityError) {
                            error_log("Activity logging error: " . $activityError->getMessage());
                        }
                        
                    } catch (Exception $loginLogError) {
                        error_log("Login logging error: " . $loginLogError->getMessage());
                        // Continue with login even if logging fails
                    }
    
                    // Terminal/location handling: prefer explicit route, else infer from role
                    $route = strtolower(trim($data['route'] ?? ''));
                    $location_label = null;
                    $terminal_name = null;
                    if ($route !== '') {
                        if (strpos($route, 'pos_convenience') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                        elseif (strpos($route, 'pos_pharmacy') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                        elseif (strpos($route, 'inventory_con') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                        elseif (strpos($route, 'admin') !== false) { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
                    }
                    if (!$terminal_name) {
                        $roleLower = strtolower((string)($user['role'] ?? ''));
                        if (strpos($roleLower, 'cashier') !== false || strpos($roleLower, 'pos') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                        elseif (strpos($roleLower, 'pharmacist') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                        elseif (strpos($roleLower, 'inventory') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                        else { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
                    }
    
                    $terminal_id = null;
                    if ($terminal_name) {
                        try {
                            // Ensure terminal exists and update shift
                            $termSel = $conn->prepare("SELECT terminal_id, shift_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
                            $termSel->execute([':name' => $terminal_name]);
                            $term = $termSel->fetch(PDO::FETCH_ASSOC);
                            $user_shift_id = $user['shift_id'] ?? null;
                            if ($term) {
                                $terminal_id = (int)$term['terminal_id'];
                                if ($user_shift_id && (int)$term['shift_id'] !== (int)$user_shift_id) {
                                    $upd = $conn->prepare("UPDATE tbl_pos_terminal SET shift_id = :shift WHERE terminal_id = :tid");
                                    $upd->execute([':shift' => $user_shift_id, ':tid' => $terminal_id]);
                                }
                            } else {
                                $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, :shift)");
                                $ins->execute([':name' => $terminal_name, ':shift' => $user_shift_id]);
                                $terminal_id = (int)$conn->lastInsertId();
                            }
    
                            // Optionally annotate login row with location/terminal if columns exist
                            if (!empty($login_id_inserted)) {
                                try {
                                    $tryUpd = $conn->prepare("UPDATE tbl_login SET location = :loc WHERE login_id = :lid");
                                    $tryUpd->execute([':loc' => $location_label, ':lid' => $login_id_inserted]);
                                } catch (Exception $ignore) {}
                                try {
                                    $tryUpd2 = $conn->prepare("UPDATE tbl_login SET terminal_id = :tid WHERE login_id = :lid");
                                    $tryUpd2->execute([':tid' => $terminal_id, ':lid' => $login_id_inserted]);
                                } catch (Exception $ignore) {}
                                try {
                                    $tryUpd3 = $conn->prepare("UPDATE tbl_login SET shift_id = :sid WHERE login_id = :lid");
                                    $tryUpd3->execute([':sid' => $user_shift_id, ':lid' => $login_id_inserted]);
                                } catch (Exception $ignore) {}
                            }
                        } catch (Exception $terminalError) {
                            error_log('Terminal handling error: ' . $terminalError->getMessage());
                        }
                    }
    
                    // Log login activity to system activity logs
                    try {
                        $logStmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW()), CURTIME())");
                        $logStmt->execute([
                            ':user_id' => $user['emp_id'],
                            ':username' => $user['username'],
                            ':employee_name' => $user['Fname'] . ' ' . $user['Lname'],
                            ':role' => $user['role'],
                            ':activity_type' => 'LOGIN',
                            ':activity_description' => "User logged in successfully from {$terminal_name}",
                            ':module' => 'Authentication',
                            ':action' => 'LOGIN',
                            ':location' => $terminal_name,
                            ':terminal_id' => $terminal_id
                        ]);
                    } catch (Exception $activityLogError) {
                        error_log("Activity logging error: " . $activityLogError->getMessage());
                    }

                    echo json_encode([
                        "success" => true,
                        "message" => "Login successful",
                        "role" => $user['role'],
                        "user_id" => $user['emp_id'],
                        "full_name" => $user['Fname'] . ' ' . $user['Lname'],
                        "terminal_id" => $terminal_id,
                        "terminal_name" => $terminal_name,
                        "location" => $location_label,
                        "shift_id" => $user['shift_id'] ?? null
                    ]);
                } else {
                    echo json_encode(["success" => false, "message" => "Invalid username or password"]);
                }
    
            } catch (Exception $e) {
                echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
            }
            break;
        try {
            $username = isset($data['username']) ? trim($data['username']) : '';
            $password = isset($data['password']) ? trim($data['password']) : '';
            $captcha = isset($data['captcha']) ? trim($data['captcha']) : '';
            $captchaAnswer = isset($data['captchaAnswer']) ? trim($data['captchaAnswer']) : '';

            // Validate inputs
            if (empty($username) || empty($password)) {
                echo json_encode(["success" => false, "message" => "Username and password are required"]);
                exit;
            }

            // Verify captcha
            if (empty($captcha) || empty($captchaAnswer) || $captcha !== $captchaAnswer) {
                echo json_encode(["success" => false, "message" => "Invalid captcha"]);
                exit;
            }

            // Check if user exists (regardless of status)
            $stmt = $conn->prepare("
                SELECT e.emp_id, e.username, e.password, e.status, e.Fname, e.Lname, e.role_id, e.shift_id, r.role 
                FROM tbl_employee e 
                JOIN tbl_role r ON e.role_id = r.role_id 
                WHERE e.username = :username
            ");
            $stmt->bindParam(":username", $username, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // If user exists but is inactive, return a specific message
            if ($user && strcasecmp($user['status'] ?? '', 'Active') !== 0) {
                echo json_encode(["success" => false, "message" => "User is inactive. Please contact the administrator."]);
                break;
            }

            // Check password - handle both hashed and plain text passwords
            $passwordValid = false;
            if ($user) {
                // First try to verify as hashed password
                if (password_verify($password, $user['password'])) {
                    $passwordValid = true;
                } 
                // If that fails, check if it's a plain text password (for backward compatibility)
                elseif ($password === $user['password']) {
                    $passwordValid = true;
                }
            }

            if ($user && $passwordValid) {
                // Start session and store user data
                session_start();
                $_SESSION['user_id'] = $user['emp_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['Fname'] . ' ' . $user['Lname'];

                // Log login activity to tbl_login
                try {
                    $loginStmt = $conn->prepare("
                        INSERT INTO tbl_login (emp_id, role_id, username, login_time, login_date, ip_address) 
                        VALUES (:emp_id, :role_id, :username, NOW(), CURDATE(), :ip_address)
                    ");
                    
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    
                    $loginStmt->bindParam(':emp_id', $user['emp_id'], PDO::PARAM_INT);
                    $loginStmt->bindParam(':role_id', $user['role_id'], PDO::PARAM_INT);
                    $loginStmt->bindParam(':username', $user['username'], PDO::PARAM_STR);
                    $loginStmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
                    
                    $loginStmt->execute();
                    
                    // Store login_id in session for logout tracking
                    $_SESSION['login_id'] = $conn->lastInsertId();
                    $login_id_inserted = $_SESSION['login_id'];
                    
                } catch (Exception $loginLogError) {
                    error_log("Login logging error: " . $loginLogError->getMessage());
                    // Continue with login even if logging fails
                }

                // Terminal/location handling: prefer explicit route, else infer from role
                $route = strtolower(trim($data['route'] ?? ''));
                $location_label = null;
                $terminal_name = null;
                if ($route !== '') {
                    if (strpos($route, 'pos_convenience') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                    elseif (strpos($route, 'pos_pharmacy') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                    elseif (strpos($route, 'inventory_con') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                    elseif (strpos($route, 'admin') !== false) { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
                }
                if (!$terminal_name) {
                    $roleLower = strtolower((string)($user['role'] ?? ''));
                    if (strpos($roleLower, 'cashier') !== false || strpos($roleLower, 'pos') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
                    elseif (strpos($roleLower, 'pharmacist') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
                    elseif (strpos($roleLower, 'inventory') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
                    else { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }
                }

                $terminal_id = null;
                if ($terminal_name) {
                    try {
                        // Ensure terminal exists and update shift
                        $termSel = $conn->prepare("SELECT terminal_id, shift_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
                        $termSel->execute([':name' => $terminal_name]);
                        $term = $termSel->fetch(PDO::FETCH_ASSOC);
                        $user_shift_id = $user['shift_id'] ?? null;
                        if ($term) {
                            $terminal_id = (int)$term['terminal_id'];
                            if ($user_shift_id && (int)$term['shift_id'] !== (int)$user_shift_id) {
                                $upd = $conn->prepare("UPDATE tbl_pos_terminal SET shift_id = :shift WHERE terminal_id = :tid");
                                $upd->execute([':shift' => $user_shift_id, ':tid' => $terminal_id]);
                            }
                        } else {
                            $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, :shift)");
                            $ins->execute([':name' => $terminal_name, ':shift' => $user_shift_id]);
                            $terminal_id = (int)$conn->lastInsertId();
                        }

                        // Optionally annotate login row with location/terminal if columns exist
                        if (!empty($login_id_inserted)) {
                            try {
                                $tryUpd = $conn->prepare("UPDATE tbl_login SET location = :loc WHERE login_id = :lid");
                                $tryUpd->execute([':loc' => $location_label, ':lid' => $login_id_inserted]);
                            } catch (Exception $ignore) {}
                            try {
                                $tryUpd2 = $conn->prepare("UPDATE tbl_login SET terminal_id = :tid WHERE login_id = :lid");
                                $tryUpd2->execute([':tid' => $terminal_id, ':lid' => $login_id_inserted]);
                            } catch (Exception $ignore) {}
                            try {
                                $tryUpd3 = $conn->prepare("UPDATE tbl_login SET shift_id = :sid WHERE login_id = :lid");
                                $tryUpd3->execute([':sid' => $user_shift_id, ':lid' => $login_id_inserted]);
                            } catch (Exception $ignore) {}
                        }
                    } catch (Exception $terminalError) {
                        error_log('Terminal handling error: ' . $terminalError->getMessage());
                    }
                }

                echo json_encode([
                    "success" => true,
                    "message" => "Login successful",
                    "role" => $user['role'],
                    "user_id" => $user['emp_id'],
                    "full_name" => $user['Fname'] . ' ' . $user['Lname'],
                    "terminal_id" => $terminal_id,
                    "terminal_name" => $terminal_name,
                    "location" => $location_label,
                    "shift_id" => $user['shift_id'] ?? null
                ]);
            } else {
                echo json_encode(["success" => false, "message" => "Invalid username or password"]);
            }

        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
        }
        break;

    case 'logout':
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
            }

            $empId = $_SESSION['user_id'] ?? null;
            $loginId = $_SESSION['login_id'] ?? null;
            // Fallback to client-provided emp_id when session cookies aren't present (CORS, different port, etc.)
            if (!$empId && isset($data['emp_id'])) {
                $empId = intval($data['emp_id']);
            }

            try {
                $updated = 0;
                if ($loginId && $empId) {
                    // Update the known session login row
                    $logoutStmt = $conn->prepare("UPDATE tbl_login SET logout_time = CURTIME(), logout_date = CURDATE() WHERE login_id = :login_id AND emp_id = :emp_id");
                    $logoutStmt->bindParam(':login_id', $loginId, PDO::PARAM_INT);
                    $logoutStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                    $logoutStmt->execute();
                    $updated = $logoutStmt->rowCount();
                    error_log('[logout] update by session login_id='.$loginId.' emp_id='.$empId.' affected='.$updated);
                }
                if ($updated === 0 && $empId) {
                    // Fallback: find the most recent OPEN login record for this employee
                    $findStmt = $conn->prepare("SELECT login_id FROM tbl_login WHERE emp_id = :emp_id AND (logout_time IS NULL OR logout_time = '00:00:00') ORDER BY login_id DESC LIMIT 1");
                    $findStmt->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                    $findStmt->execute();
                    $row = $findStmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && isset($row['login_id'])) {
                        $fallbackLogout = $conn->prepare("UPDATE tbl_login SET logout_time = CURTIME(), logout_date = CURDATE() WHERE login_id = :login_id");
                        $fallbackLogout->bindParam(':login_id', $row['login_id'], PDO::PARAM_INT);
                        $fallbackLogout->execute();
                        $updated = $fallbackLogout->rowCount();
                        error_log('[logout] update by open row login_id='.$row['login_id'].' affected='.$updated);
                    }
                }
                if ($updated === 0 && $empId) {
                    // Final fallback: update the most recent row for this employee
                    $findAny = $conn->prepare("SELECT login_id FROM tbl_login WHERE emp_id = :emp_id ORDER BY login_id DESC LIMIT 1");
                    $findAny->bindParam(':emp_id', $empId, PDO::PARAM_INT);
                    $findAny->execute();
                    $last = $findAny->fetch(PDO::FETCH_ASSOC);
                    if ($last && isset($last['login_id'])) {
                        $updAny = $conn->prepare("UPDATE tbl_login SET logout_time = CURTIME(), logout_date = CURDATE() WHERE login_id = :login_id");
                        $updAny->bindParam(':login_id', $last['login_id'], PDO::PARAM_INT);
                        $updAny->execute();
                        error_log('[logout] forced update latest login_id='.$last['login_id'].' affected='.$updAny->rowCount());
                    }
                }
                } catch (Exception $logoutLogError) {
                    error_log("Logout logging error: " . $logoutLogError->getMessage());
            }

            // Log logout activity to system activity logs
            if ($empId) {
                try {
                    // Get employee details for logging
                    $empStmt = $conn->prepare("SELECT username, Fname, Lname, role FROM tbl_employee WHERE emp_id = ?");
                    $empStmt->execute([$empId]);
                    $empData = $empStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($empData) {
                        $logStmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at) VALUES (:user_id, :username, :role, :activity_type, :activity_description, :table_name, :record_id, CURDATE(), CURTIME(), NOW()), CURTIME())");
                        $logStmt->execute([
                            ':user_id' => $empId,
                            ':username' => $empData['username'],
                            ':employee_name' => $empData['Fname'] . ' ' . $empData['Lname'],
                            ':role' => $empData['role'],
                            ':activity_type' => 'LOGOUT',
                            ':activity_description' => 'User logged out from system',
                            ':module' => 'Authentication',
                            ':action' => 'LOGOUT'
                        ]);
                    }
                } catch (Exception $activityLogError) {
                    error_log("Activity logging error: " . $activityLogError->getMessage());
                }
            }

            // Clear session only after writing logout record
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            
            echo json_encode([
                'success' => true,
                'message' => 'Logout successful'
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'An error occurred during logout: ' . $e->getMessage()]);
        }
        break;

    case 'get_login_records':
        try {
            $stmt = $conn->prepare("
                SELECT l.*, e.username, r.role 
                FROM tbl_login l 
                JOIN tbl_employee e ON l.emp_id = e.emp_id 
                LEFT JOIN tbl_role r ON l.role_id = r.role_id 
                ORDER BY l.login_id DESC 
                LIMIT 10
            ");
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'records' => $records
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_users':
        try {
            $stmt = $conn->prepare("
                SELECT e.emp_id, e.username, e.status, r.role 
                FROM tbl_employee e 
                LEFT JOIN tbl_role r ON e.role_id = r.role_id 
                ORDER BY e.emp_id
            ");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'users' => $users
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_activity_records':
        try {
            $stmt = $conn->prepare("
                SELECT * FROM tbl_activity_log 
                WHERE activity_type IN ('LOGIN', 'LOGOUT') 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'records' => $records
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'register_terminal_route':
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
            $empId = $_SESSION['user_id'] ?? ($data['emp_id'] ?? null);
            $route = strtolower(trim($data['route'] ?? ''));
            if (!$empId || $route === '') {
                echo json_encode(['success' => false, 'message' => 'Missing emp_id or route']);
                break;
            }

            // Get employee shift
            $emp = null;
            try {
                $st = $conn->prepare("SELECT shift_id, role_id FROM tbl_employee WHERE emp_id = :id LIMIT 1");
                $st->execute([':id' => $empId]);
                $emp = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            $user_shift_id = $emp['shift_id'] ?? null;

            // Map route → terminal/location
            $location_label = 'admin';
            $terminal_name = 'Admin Terminal';
            if (strpos($route, 'pos_convenience') !== false) { $location_label = 'convenience'; $terminal_name = 'Convenience POS'; }
            elseif (strpos($route, 'pos_pharmacy') !== false) { $location_label = 'pharmacy'; $terminal_name = 'Pharmacy POS'; }
            elseif (strpos($route, 'inventory_con') !== false) { $location_label = 'inventory'; $terminal_name = 'Inventory Terminal'; }
            elseif (strpos($route, 'admin') !== false) { $location_label = 'admin'; $terminal_name = 'Admin Terminal'; }

            // Ensure terminal exists and update shift
            $termSel = $conn->prepare("SELECT terminal_id, shift_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
            $termSel->execute([':name' => $terminal_name]);
            $term = $termSel->fetch(PDO::FETCH_ASSOC);
            if ($term) {
                $terminal_id = (int)$term['terminal_id'];
                if ($user_shift_id && (int)$term['shift_id'] !== (int)$user_shift_id) {
                    $upd = $conn->prepare("UPDATE tbl_pos_terminal SET shift_id = :shift WHERE terminal_id = :tid");
                    $upd->execute([':shift' => $user_shift_id, ':tid' => $terminal_id]);
                }
            } else {
                $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, :shift)");
                $ins->execute([':name' => $terminal_name, ':shift' => $user_shift_id]);
                $terminal_id = (int)$conn->lastInsertId();
            }

            // Annotate most recent open login row
            try {
                $findStmt = $conn->prepare("SELECT login_id FROM tbl_login WHERE emp_id = :emp AND (logout_time IS NULL OR logout_time = '00:00:00') ORDER BY login_id DESC LIMIT 1");
                $findStmt->execute([':emp' => $empId]);
                $row = $findStmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['login_id'])) {
                    try { $upd1 = $conn->prepare("UPDATE tbl_login SET terminal_id = :tid WHERE login_id = :lid"); $upd1->execute([':tid' => $terminal_id, ':lid' => $row['login_id']]); } catch (Exception $e) {}
                    try { $upd2 = $conn->prepare("UPDATE tbl_login SET location = :loc WHERE login_id = :lid"); $upd2->execute([':loc' => $location_label, ':lid' => $row['login_id']]); } catch (Exception $e) {}
                    try { if ($user_shift_id) { $upd3 = $conn->prepare("UPDATE tbl_login SET shift_id = :sid WHERE login_id = :lid"); $upd3->execute([':sid' => $user_shift_id, ':lid' => $row['login_id']]); } } catch (Exception $e) {}
                }
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'data' => [
                'terminal_id' => $terminal_id,
                'terminal_name' => $terminal_name,
                'location' => $location_label,
                'shift_id' => $user_shift_id
            ]]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
    case 'get_login_activity':
        try {
            $limit = isset($data['limit']) ? intval($data['limit']) : 200;
            $search = isset($data['search']) ? trim($data['search']) : '';
            $date_from = isset($data['date_from']) ? trim($data['date_from']) : '';
            $date_to = isset($data['date_to']) ? trim($data['date_to']) : '';

            $clauses = [];
            $params = [];

            if ($search !== '') {
                $clauses[] = '(l.username LIKE ? OR e.Fname LIKE ? OR e.Lname LIKE ?)';
                $term = "%$search%";
                $params[] = $term; $params[] = $term; $params[] = $term;
            }
            if ($date_from !== '') { $clauses[] = 'l.login_date >= ?'; $params[] = $date_from; }
            if ($date_to !== '') { $clauses[] = 'l.login_date <= ?'; $params[] = $date_to; }

            $whereSql = count($clauses) ? ('WHERE ' . implode(' AND ', $clauses)) : '';

            $sql = "
                SELECT 
                    l.login_id, l.emp_id, l.role_id, l.username,
                    l.login_time, l.login_date, l.logout_time, l.logout_date,
                    l.ip_address,
                    e.Fname, e.Lname, r.role,
                    -- Compute terminal/location label without requiring extra columns
                    CASE 
                        WHEN LOWER(r.role) LIKE '%admin%' THEN 'Admin Terminal'
                        WHEN LOWER(r.role) LIKE '%cashier%' OR LOWER(r.role) LIKE '%pos%' THEN 'Convenience POS'
                        WHEN LOWER(r.role) LIKE '%pharmacist%' THEN 'Pharmacy POS'
                        WHEN LOWER(r.role) LIKE '%inventory%' THEN 'Inventory Terminal'
                        ELSE 'Admin Terminal'
                    END AS terminal_name
                FROM tbl_login l
                LEFT JOIN tbl_employee e ON l.emp_id = e.emp_id
                LEFT JOIN tbl_role r ON l.role_id = r.role_id
                $whereSql
                ORDER BY l.login_id DESC
                LIMIT $limit
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rowCount = is_array($rows) ? count($rows) : 0;
            
            // If no rows, try a fallback simple query (helps diagnose join/data issues)
            $fallback = [];
            if ($rowCount === 0) {
                $fb = $conn->prepare("SELECT * FROM tbl_login ORDER BY login_id DESC LIMIT 5");
                $fb->execute();
                $fallback = $fb->fetchAll(PDO::FETCH_ASSOC);
            }

            // Debug: log how many rows were found
            error_log('[get_login_activity] rows=' . $rowCount . ', fallback=' . count($fallback));

            echo json_encode(['success' => true, 'data' => $rows, 'fallback' => $fallback]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'data' => []]);
        }
        break;

    case 'get_login_activity_count':
        try {
            // Count today's logins and logouts (each recorded row counts once; rows with today's logout but older login also counted)
            $stmt = $conn->prepare("SELECT 
                    SUM(CASE WHEN login_date = CURDATE() THEN 1 ELSE 0 END) AS logins_today,
                    SUM(CASE WHEN logout_date = CURDATE() THEN 1 ELSE 0 END) AS logouts_today
                FROM tbl_login");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['logins_today' => 0, 'logouts_today' => 0];
            $total = (int)$row['logins_today'] + (int)$row['logouts_today'];
            echo json_encode(['success' => true, 'data' => ['logins_today' => (int)$row['logins_today'], 'logouts_today' => (int)$row['logouts_today'], 'total' => $total]]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'data' => ['logins_today' => 0, 'logouts_today' => 0, 'total' => 0]]);
        }
        break;
    // --- Activity Logs: write and read comprehensive system activities ---
    case 'log_activity':
        try {
            // Ensure comprehensive activity logs table exists
            $conn->exec("CREATE TABLE IF NOT EXISTS `tbl_activity_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) DEFAULT NULL,
                `username` varchar(255) DEFAULT NULL,
                `employee_name` varchar(255) DEFAULT NULL,
                `role` varchar(100) DEFAULT NULL,
                `activity_type` varchar(100) NOT NULL,
                `activity_description` text DEFAULT NULL,
                `module` varchar(100) DEFAULT NULL COMMENT 'Module where activity occurred (POS, Inventory, Admin, etc.)',
                `action` varchar(100) DEFAULT NULL COMMENT 'Specific action performed',
                `table_name` varchar(255) DEFAULT NULL COMMENT 'Database table affected',
                `record_id` int(11) DEFAULT NULL COMMENT 'ID of the affected record',
                `old_values` json DEFAULT NULL COMMENT 'Previous values (for updates)',
                `new_values` json DEFAULT NULL COMMENT 'New values (for updates)',
                `ip_address` varchar(45) DEFAULT NULL,
                `user_agent` text DEFAULT NULL,
                `location` varchar(255) DEFAULT NULL COMMENT 'Physical location or terminal',
                `terminal_id` varchar(100) DEFAULT NULL,
                `session_id` varchar(255) DEFAULT NULL,
                `date_created` date NOT NULL,
                `time_created` time NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_username` (`username`),
                KEY `idx_activity_type` (`activity_type`),
                KEY `idx_module` (`module`),
                KEY `idx_date_created` (`date_created`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_table_record` (`table_name`, `record_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Comprehensive system activity logs';");

            // Get user data from session or provided data
            $user_id = isset($data['user_id']) ? $data['user_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
            $username = isset($data['username']) ? trim($data['username']) : (isset($_SESSION['username']) ? $_SESSION['username'] : null);
            $role = isset($data['role']) ? trim($data['role']) : (isset($_SESSION['role']) ? $_SESSION['role'] : null);
            $employee_name = isset($data['employee_name']) ? trim($data['employee_name']) : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : null);
            
            $activity_type = isset($data['activity_type']) ? trim($data['activity_type']) : '';
            $activity_description = isset($data['description']) ? trim($data['description']) : null;
            $module = isset($data['module']) ? trim($data['module']) : 'System';
            $action = isset($data['action']) ? trim($data['action']) : $activity_type;
            $table_name = isset($data['table_name']) ? trim($data['table_name']) : null;
            $record_id = isset($data['record_id']) ? $data['record_id'] : null;
            $location = isset($data['location']) ? trim($data['location']) : 'System';
            $terminal_id = isset($data['terminal_id']) ? trim($data['terminal_id']) : null;
            $ip_address = isset($data['ip_address']) ? $data['ip_address'] : ($_SERVER['REMOTE_ADDR'] ?? null);
            $user_agent = isset($data['user_agent']) ? $data['user_agent'] : ($_SERVER['HTTP_USER_AGENT'] ?? null);
            $session_id = isset($data['session_id']) ? $data['session_id'] : (session_id() ?: null);

            if ($activity_type === '') {
                echo json_encode(["success" => false, "message" => "activity_type is required"]);
                break;
            }

            $date_created = date('Y-m-d');
            $time_created = date('H:i:s');

            $stmt = $conn->prepare("INSERT INTO tbl_activity_log (user_id, username, employee_name, role, activity_type, activity_description, module, action, table_name, record_id, location, terminal_id, ip_address, user_agent, session_id, date_created, time_created) VALUES (:user_id, :username, :employee_name, :role, :activity_type, :activity_description, :module, :action, :table_name, :record_id, :location, :terminal_id, :ip_address, :user_agent, :session_id, :date_created, :time_created)");
            
            $stmt->bindValue(':user_id', $user_id !== null && $user_id !== '' ? $user_id : null, $user_id !== null && $user_id !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':username', $username !== null && $username !== '' ? $username : null, $username !== null && $username !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':employee_name', $employee_name !== null && $employee_name !== '' ? $employee_name : null, $employee_name !== null && $employee_name !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':role', $role !== null && $role !== '' ? $role : null, $role !== null && $role !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindParam(':activity_type', $activity_type, PDO::PARAM_STR);
            $stmt->bindValue(':activity_description', $activity_description !== null && $activity_description !== '' ? $activity_description : null, $activity_description !== null && $activity_description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':module', $module !== null && $module !== '' ? $module : null, $module !== null && $module !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':action', $action !== null && $action !== '' ? $action : null, $action !== null && $action !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':table_name', $table_name !== null && $table_name !== '' ? $table_name : null, $table_name !== null && $table_name !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':record_id', $record_id !== null && $record_id !== '' ? $record_id : null, $record_id !== null && $record_id !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':location', $location !== null && $location !== '' ? $location : null, $location !== null && $location !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':terminal_id', $terminal_id !== null && $terminal_id !== '' ? $terminal_id : null, $terminal_id !== null && $terminal_id !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':ip_address', $ip_address !== null && $ip_address !== '' ? $ip_address : null, $ip_address !== null && $ip_address !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':user_agent', $user_agent !== null && $user_agent !== '' ? $user_agent : null, $user_agent !== null && $user_agent !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':session_id', $session_id !== null && $session_id !== '' ? $session_id : null, $session_id !== null && $session_id !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindParam(':date_created', $date_created, PDO::PARAM_STR);
            $stmt->bindParam(':time_created', $time_created, PDO::PARAM_STR);
            $stmt->execute();

            echo json_encode(["success" => true, "message" => "Activity logged successfully"]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error logging activity: " . $e->getMessage()]);
        }
        break;

    case 'get_activity_logs':
        try {
            // Ensure table exists for safe reads
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(255) NULL,
                role VARCHAR(100) NULL,
                activity_type VARCHAR(100) NOT NULL,
                activity_description TEXT NULL,
                table_name VARCHAR(255) NULL,
                record_id INT NULL,
                date_created DATE NOT NULL,
                time_created TIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $limit = isset($data['limit']) ? max(1, intval($data['limit'])) : 200;
            $search = isset($data['search']) ? trim($data['search']) : '';
            $date_from = isset($data['date_from']) ? trim($data['date_from']) : '';
            $date_to = isset($data['date_to']) ? trim($data['date_to']) : '';

            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = "(username LIKE :s OR role LIKE :s OR activity_type LIKE :s OR activity_description LIKE :s)";
                $params[':s'] = '%' . $search . '%';
            }
            if ($date_from !== '') {
                $where[] = "date_created >= :df";
                $params[':df'] = $date_from;
            }
            if ($date_to !== '') {
                $where[] = "date_created <= :dt";
                $params[':dt'] = $date_to;
            }
            $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

            $sql = "SELECT id, user_id, username, role, activity_type, activity_description, table_name, record_id, date_created, time_created, created_at FROM tbl_activity_log $whereSql ORDER BY created_at DESC, id DESC LIMIT :lim";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "data" => $rows]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error getting activity logs: " . $e->getMessage(), "data" => []]);
        }
        break;

    case 'get_all_logs':
        try {
            $limit = isset($data['limit']) ? max(1, intval($data['limit'])) : 500;
            $search = isset($data['search']) ? trim($data['search']) : '';
            $date_from = isset($data['date_from']) ? trim($data['date_from']) : '';
            $date_to = isset($data['date_to']) ? trim($data['date_to']) : '';

            // Ensure table exists and add sample data if empty
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(255) NULL,
                role VARCHAR(100) NULL,
                activity_type VARCHAR(100) NOT NULL,
                activity_description TEXT NULL,
                table_name VARCHAR(255) NULL,
                record_id INT NULL,
                date_created DATE NOT NULL,
                time_created TIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            // Check if table is empty and add sample data
            $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_activity_log");
            $countStmt->execute();
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count == 0) {
                // Add comprehensive sample data for testing
                $sampleData = [
                    ['LOGIN', 'User logged in successfully from Main Office terminal', 'admin', 'Admin'],
                    ['POS_SALE_SAVED', 'POS Sale completed: Transaction TXN-2024001 - ₱250.00 (CASH, 3 items) at Convenience POS Terminal 1', 'cashier1', 'Cashier'],
                    ['STOCK_ADJUSTMENT_CREATED', 'Stock Addition: 100 units of Medicine ABC (Reason: New delivery from supplier)', 'inventory', 'Inventory'],
                    ['INVENTORY_TRANSFER_CREATED', 'Transfer created from Warehouse to Convenience Store: 5 products (Medicine, Vitamins)', 'inventory', 'Inventory'],
                    ['USER_CREATE', 'Created new employee: John Doe (john.doe) with role Cashier', 'admin', 'Admin'],
                    ['STOCK_IN', 'Stock In: 50 units of Product XYZ received from Supplier ABC', 'warehouse', 'Warehouse'],
                    ['STOCK_OUT', 'Stock Out: 25 units of Product ABC sold via POS', 'pos_system', 'POS'],
                    ['LOGOUT', 'User logged out from Main Office terminal', 'cashier1', 'Cashier'],
                    ['NAVIGATION', 'User navigated to Reports section', 'manager1', 'Manager'],
                    ['USER_UPDATE', 'Updated employee profile: Jane Smith (jane.smith)', 'admin', 'Admin'],
                    ['WAREHOUSE_STOCK_UPDATED', 'Warehouse stock updated: Product ID 45, Quantity: 50, Batch: BTH-001', 'inventory', 'Inventory'],
                    ['POS_SALE_SAVED', 'POS Sale completed: Transaction TXN-2024002 - ₱180.50 (GCASH, 2 items) at Pharmacy POS', 'cashier2', 'Cashier'],
                    ['STOCK_ADJUSTMENT_CREATED', 'Stock Correction: Adjusted Product ID 123 quantity from 10 to 15 (Reason: Found additional stock)', 'inventory', 'Inventory'],
                    ['INVENTORY_TRANSFER_CREATED', 'Transfer completed: 3 products moved from Convenience Store to Pharmacy', 'inventory', 'Inventory'],
                    ['LOGIN', 'User logged in from Pharmacy terminal', 'pharmacist1', 'Pharmacist']
                ];
                
                $insertSample = $conn->prepare("INSERT INTO tbl_activity_log (activity_type, activity_description, username, role, date_created, time_created) VALUES (?, ?, ?, ?, CURDATE(), CURTIME())");
                foreach ($sampleData as $sample) {
                    $insertSample->execute($sample);
                }
            }

            // Fetch activity logs
            $paramsAct = [];
            $whereAct = [];
            if ($search !== '') {
                $whereAct[] = "(username LIKE :s OR role LIKE :s OR activity_type LIKE :s OR activity_description LIKE :s)";
                $paramsAct[':s'] = '%' . $search . '%';
            }
            if ($date_from !== '') { $whereAct[] = "date_created >= :df"; $paramsAct[':df'] = $date_from; }
            if ($date_to !== '') { $whereAct[] = "date_created <= :dt"; $paramsAct[':dt'] = $date_to; }
            $whereActSql = count($whereAct) ? ('WHERE ' . implode(' AND ', $whereAct)) : '';
            $stmtAct = $conn->prepare("SELECT date_created, time_created, username, role, activity_type AS action, activity_description AS description FROM tbl_activity_log $whereActSql ORDER BY created_at DESC, id DESC LIMIT :limAct");
            foreach ($paramsAct as $k => $v) { $stmtAct->bindValue($k, $v); }
            $limAct = min($limit, 300);
            $stmtAct->bindValue(':limAct', $limAct, PDO::PARAM_INT);
            $stmtAct->execute();
            $activity = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

            // Fetch inventory movement history
            $movementData = [];
            try {
                $paramsMovement = [];
                $whereMovement = [];
                if ($search !== '') {
                    $whereMovement[] = "(p.product_name LIKE :s OR p.barcode LIKE :s OR sm.created_by LIKE :s)";
                    $paramsMovement[':s'] = '%' . $search . '%';
                }
                if ($date_from !== '') { $whereMovement[] = "DATE(sm.movement_date) >= :df"; $paramsMovement[':df'] = $date_from; }
                if ($date_to !== '') { $whereMovement[] = "DATE(sm.movement_date) <= :dt"; $paramsMovement[':dt'] = $date_to; }
                $whereMovementSql = count($whereMovement) ? ('WHERE ' . implode(' AND ', $whereMovement)) : '';

                $stmtMovement = $conn->prepare("
                    SELECT 
                        DATE(sm.movement_date) as date_created,
                        TIME(sm.movement_date) as time_created,
                        sm.created_by as username,
                        'Inventory' as role,
                        CONCAT('STOCK_', UPPER(sm.movement_type)) as action,
                        CONCAT(
                            CASE sm.movement_type
                                WHEN 'IN' THEN '📦 Stock Added: '
                                WHEN 'OUT' THEN '📤 Stock Removed: '
                                ELSE '📊 Stock Movement: '
                            END,
                            sm.quantity, ' units of ', p.product_name,
                            CASE WHEN sm.notes IS NOT NULL THEN CONCAT(' (', sm.notes, ')') ELSE '' END
                        ) as description
                    FROM tbl_stock_movements sm
                    LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                    $whereMovementSql
                    ORDER BY sm.movement_date DESC
                    LIMIT :limMovement
                ");
                foreach ($paramsMovement as $k => $v) { $stmtMovement->bindValue($k, $v); }
                $limMovement = min($limit, 100);
                $stmtMovement->bindValue(':limMovement', $limMovement, PDO::PARAM_INT);
                $stmtMovement->execute();
                $movementData = $stmtMovement->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Continue even if movement data fails
                error_log("Movement data fetch failed: " . $e->getMessage());
            }

            // Fetch inventory transfer history
            $transferData = [];
            try {
                $paramsTransfer = [];
                $whereTransfer = [];
                if ($search !== '') {
                    $whereTransfer[] = "(th.transferred_by LIKE :s OR sl.location_name LIKE :s OR dl.location_name LIKE :s)";
                    $paramsTransfer[':s'] = '%' . $search . '%';
                }
                if ($date_from !== '') { $whereTransfer[] = "DATE(th.date) >= :df"; $paramsTransfer[':df'] = $date_from; }
                if ($date_to !== '') { $whereTransfer[] = "DATE(th.date) <= :dt"; $paramsTransfer[':dt'] = $date_to; }
                $whereTransferSql = count($whereTransfer) ? ('WHERE ' . implode(' AND ', $whereTransfer)) : '';

                $stmtTransfer = $conn->prepare("
                    SELECT 
                        DATE(th.date) as date_created,
                        TIME(th.date) as time_created,
                        th.transferred_by as username,
                        'Inventory' as role,
                        'INVENTORY_TRANSFER' as action,
                        CONCAT(
                            '🚛 Transfer #', th.transfer_header_id, ': ',
                            sl.location_name, ' → ', dl.location_name,
                            ' (Status: ', UPPER(th.status), ')'
                        ) as description
                    FROM tbl_transfer_header th
                    LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                    LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                    $whereTransferSql
                    ORDER BY th.date DESC
                    LIMIT :limTransfer
                ");
                foreach ($paramsTransfer as $k => $v) { $stmtTransfer->bindValue($k, $v); }
                $limTransfer = min($limit, 100);
                $stmtTransfer->bindValue(':limTransfer', $limTransfer, PDO::PARAM_INT);
                $stmtTransfer->execute();
                $transferData = $stmtTransfer->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Continue even if transfer data fails
                error_log("Transfer data fetch failed: " . $e->getMessage());
            }

            // Fetch login activity; materialize both login and logout as separate entries
            $paramsLogin = [];
            $whereLogin = [];
            if ($search !== '') {
                $whereLogin[] = "(l.username LIKE :s OR r.role_name LIKE :s OR e.Fname LIKE :s OR e.Lname LIKE :s)";
                $paramsLogin[':s'] = '%' . $search . '%';
            }
            if ($date_from !== '') { $whereLogin[] = "l.login_date >= :df"; $paramsLogin[':df'] = $date_from; }
            if ($date_to !== '') { $whereLogin[] = "l.login_date <= :dt"; $paramsLogin[':dt'] = $date_to; }
            $whereLoginSql = count($whereLogin) ? ('WHERE ' . implode(' AND ', $whereLogin)) : '';
            $stmtLogin = $conn->prepare("SELECT l.login_date, l.login_time, l.logout_date, l.logout_time, l.username, r.role_name AS role FROM tbl_login l LEFT JOIN tbl_role r ON l.role_id = r.role_id LEFT JOIN tbl_employee e ON l.emp_id = e.emp_id $whereLoginSql ORDER BY l.login_id DESC LIMIT :limLogin");
            foreach ($paramsLogin as $k => $v) { $stmtLogin->bindValue($k, $v); }
            $limLogin = min($limit, 500);
            $stmtLogin->bindValue(':limLogin', $limLogin, PDO::PARAM_INT);
            $stmtLogin->execute();
            $loginRows = $stmtLogin->fetchAll(PDO::FETCH_ASSOC);

            $logs = [];
            foreach ($loginRows as $lr) {
                // login record
                $logs[] = [
                    'login_date' => $lr['login_date'],
                    'login_time' => $lr['login_time'],
                    'username' => $lr['username'],
                    'role' => $lr['role'],
                    'log_activity' => 'login',
                    'description' => 'login'
                ];
                // logout record if present
                if (!empty($lr['logout_date']) && !empty($lr['logout_time'])) {
                    $logs[] = [
                        'login_date' => $lr['logout_date'],
                        'login_time' => $lr['logout_time'],
                        'username' => $lr['username'],
                        'role' => $lr['role'],
                        'log_activity' => 'logout',
                        'description' => 'logout'
                    ];
                }
            }

            // Normalize login logs to match the format of other logs
            foreach ($logs as &$log) {
                if (isset($log['login_date']) && !isset($log['date_created'])) {
                    $log['date_created'] = $log['login_date'];
                    $log['time_created'] = $log['login_time'];
                    $log['action'] = $log['log_activity'];
                    unset($log['login_date'], $log['login_time'], $log['log_activity']);
                }
            }

            // Merge all data sources: activity logs, movement data, transfer data, and login logs
            $allLogs = array_merge($activity, $movementData, $transferData, $logs);

            // Sort all logs by date/time descending
            usort($allLogs, function ($x, $y) {
                $dx = $x['date_created'] ?? '';
                $tx = $x['time_created'] ?? '';
                $dy = $y['date_created'] ?? '';
                $ty = $y['time_created'] ?? '';
                $sx = strtotime($dx . ' ' . $tx);
                $sy = strtotime($dy . ' ' . $ty);
                return $sy <=> $sx;
            });

            // Apply overall limit
            if (count($allLogs) > $limit) {
                $allLogs = array_slice($allLogs, 0, $limit);
            }

            echo json_encode(["success" => true, "data" => $allLogs]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error getting all logs: " . $e->getMessage(), "data" => []]);
        }
        break;

    case 'generate_captcha':
        try {
            // Generate simple addition captcha only
            $num1 = rand(1, 15);
            $num2 = rand(1, 15);
            $answer = $num1 + $num2;
            
            $question = "What is $num1 + $num2?";
            
            // Ensure answer is always a number
            $answer = (int)$answer;
            
            // Log for debugging
            error_log("Captcha generated - Question: $question, Answer: $answer, Type: " . gettype($answer));
            
            echo json_encode([
                "success" => true,
                "question" => $question,
                "answer" => $answer
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error generating captcha: " . $e->getMessage()
            ]);
        }
        break;
    case 'display_employee':
        try {
            $stmt = $conn->prepare("SELECT emp_id,Fname,Mname,Lname,email,contact_num,role_id,shift_id,username,age,address,status,gender,birthdate FROM tbl_employee");
            $stmt->execute();
            $employee = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($employee) {
                echo json_encode([
                    "success" => true,
                    "employees" => $employee
                ]);
            } else {
                echo json_encode([
                    "success" => true,
                    "employees" => [],
                    "message" => "No employees found"
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "employees" => []
            ]);
        }
        break;

    case 'update_employee_status':
        try {
            $emp_id = isset($data['id']) ? trim($data['id']) : '';
            $newStatus = isset($data['status']) ? trim($data['status']) : '';

            $stmt = $conn->prepare("UPDATE tbl_employee SET status = :status WHERE emp_id = :id");
            $stmt->bindParam(":status", $newStatus, PDO::PARAM_STR);
            $stmt->bindParam(":id", $emp_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Status updated successfully"]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to update status"]);
            }
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'get_employee_profile':
        require_once 'modules/employees.php';
        handle_get_employee_profile($conn, $data);
        break;

    case 'update_employee_profile':
        require_once 'modules/employees.php';
        handle_update_employee_profile($conn, $data);
        break;
        //convenience
    case 'add_convenience_product':
        require_once __DIR__ . '/modules/products.php';
        handle_add_convenience_product($conn, $data);
        break;
    //pharmacy
    case 'add_pharmacy_product':
        require_once __DIR__ . '/modules/products.php';
        handle_add_pharmacy_product($conn, $data);
        break;
    //brand section
    case 'addBrand':
        require_once __DIR__ . '/modules/products.php';
        handle_addBrand($conn, $data);
        break;
    case 'displayBrand':
        require_once __DIR__ . '/modules/products.php';
        handle_displayBrand($conn, $data);
        break;
        
    case 'deleteBrand':
        require_once __DIR__ . '/modules/products.php';
        handle_deleteBrand($conn, $data);
        break;
    case 'add_brand':
        require_once __DIR__ . '/modules/products.php';
        handle_add_brand($conn, $data);
        break;

    case 'add_product':
        require_once __DIR__ . '/modules/products.php';
        handle_add_product($conn, $data);
        break;
    case 'update_product':
        require_once __DIR__ . '/modules/products.php';
        handle_update_product($conn, $data);
        break;


    case 'enhanced_fifo_transfer':
        require_once '../enhanced_fifo_transfer_system.php';
        
        try {
            $fifoSystem = new EnhancedFifoTransferSystem($conn);
            $result = $fifoSystem->performEnhancedFifoTransfer($data);
            
            echo json_encode($result);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Enhanced FIFO Transfer Error: ' . $e->getMessage()
            ]);
        }
        break;

case 'get_fifo_stock_status':
    require_once __DIR__ . '/modules/products.php';
    handle_get_fifo_stock_status($conn, $data);
    break;

case 'check_fifo_availability':
    require_once __DIR__ . '/modules/products.php';
    handle_check_fifo_availability($conn, $data);
    break;
case 'get_products_oldest_batch_for_transfer':
    require_once __DIR__ . '/modules/products.php';
    handle_get_products_oldest_batch_for_transfer($conn, $data);
    break;

    case 'get_products_oldest_batch':
        require_once __DIR__ . '/modules/products.php';
        handle_get_products_oldest_batch($conn, $data);
        break;

    case 'diagnose_warehouse_data':
        try {
            $location_id = $data['location_id'] ?? 2; // Default to warehouse
            
            // Check basic product count
            $productStmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_product WHERE location_id = ? AND status = 'active'");
            $productStmt->execute([$location_id]);
            $productCount = $productStmt->fetchColumn();
            
            // Check FIFO stock count
            $fifoStmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_fifo_stock WHERE available_quantity > 0");
            $fifoStmt->execute();
            $fifoCount = $fifoStmt->fetchColumn();
            
            // Check stock summary count
            $summaryStmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_stock_summary WHERE available_quantity > 0");
            $summaryStmt->execute();
            $summaryCount = $summaryStmt->fetchColumn();
            
            // Get sample products
            $sampleStmt = $conn->prepare("
                SELECT 
                    p.product_id, 
                    p.product_name, 
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity, 
                    p.status 
                FROM tbl_product p 
                WHERE p.location_id = ? 
                LIMIT 5
            ");
            $sampleStmt->execute([$location_id]);
            $sampleProducts = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "diagnosis" => [
                    "location_id" => $location_id,
                    "product_count" => $productCount,
                    "fifo_stock_count" => $fifoCount,
                    "stock_summary_count" => $summaryCount,
                    "sample_products" => $sampleProducts
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Diagnostic error: " . $e->getMessage()
            ]);
        }
        break;

    case 'emergency_restore_warehouse':
        try {
            $location_id = $data['location_id'] ?? 2; // Default to warehouse
            
            // Start transaction
            $conn->beginTransaction();
            
            // 1. Get all products from tbl_product for warehouse
            $productStmt = $conn->prepare("
                SELECT p.*, b.batch_reference, b.entry_date, b.entry_by
                FROM tbl_product p
                LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
                WHERE p.location_id = ? AND p.status = 'active'
            ");
            $productStmt->execute([$location_id]);
            $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $restored_count = 0;
            $fifo_count = 0;
            $summary_count = 0;
            
            foreach ($products as $product) {
                // Handle NULL batch_id by creating a default batch
                $batch_id = $product['batch_id'] ?? null;
                if (!$batch_id) {
                    // Create a default batch for products without batch_id
                    $defaultBatchStmt = $conn->prepare("
                        INSERT INTO tbl_batch (
                            date, batch, batch_reference, supplier_id, location_id, 
                            entry_date, entry_time, entry_by, order_no
                        ) VALUES (?, ?, ?, ?, ?, ?, CURTIME(), ?, ?)
                    ");
                    $defaultBatchStmt->execute([
                        date('Y-m-d'),
                        'RESTORED-' . $product['product_id'],
                        'RESTORED-' . $product['product_id'],
                        1, // Default supplier
                        $location_id,
                        date('Y-m-d'),
                        'SYSTEM_RESTORE',
                        ''
                    ]);
                    $batch_id = $conn->lastInsertId();
                    
                    // Update the product with the new batch_id
                    $updateProductStmt = $conn->prepare("UPDATE tbl_product SET batch_id = ? WHERE product_id = ?");
                    $updateProductStmt->execute([$batch_id, $product['product_id']]);
                }
                
                // 2. Insert into tbl_fifo_stock if not exists
                $checkFifoStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_fifo_stock WHERE product_id = ? AND batch_id = ?");
                $checkFifoStmt->execute([$product['product_id'], $batch_id]);
                
                if ($checkFifoStmt->fetchColumn() == 0) {
                    $fifoStmt = $conn->prepare("
                        INSERT INTO tbl_fifo_stock (
                            product_id, batch_id, batch_reference, quantity, available_quantity, 
                            srp, expiration_date, entry_date, entry_by, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $fifoStmt->execute([
                        $product['product_id'],
                        $batch_id,
                        $product['batch_reference'] ?? 'RESTORED',
                        $product['quantity'],
                        $product['quantity'],
                        $product['srp'],
                        $product['expiration'],
                        $product['entry_date'] ?? date('Y-m-d'),
                        $product['entry_by'] ?? 'SYSTEM_RESTORE'
                    ]);
                    $fifo_count++;
                }
                
                // 3. Insert into tbl_stock_summary if not exists
                $checkSummaryStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_stock_summary WHERE product_id = ? AND batch_id = ?");
                $checkSummaryStmt->execute([$product['product_id'], $batch_id]);
                
                if ($checkSummaryStmt->fetchColumn() == 0) {
                    $summaryStmt = $conn->prepare("
                        INSERT INTO tbl_stock_summary (
                            product_id, batch_id, batch_reference, available_quantity, 
                            srp, expiration_date, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $summaryStmt->execute([
                        $product['product_id'],
                        $batch_id,
                        $product['batch_reference'] ?? 'RESTORED',
                        $product['quantity'],
                        $product['srp'],
                        $product['expiration']
                    ]);
                    $summary_count++;
                }
                
                $restored_count++;
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                "success" => true,
                "message" => "Warehouse products restored successfully!",
                "restored" => [
                    "products_processed" => $restored_count,
                    "fifo_entries_created" => $fifo_count,
                    "summary_entries_created" => $summary_count
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                "success" => false,
                "message" => "Restore failed: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_inventory_kpis':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as physicalAvailable,
                    0 as softReserved,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as onhandInventory,
                    0 as newOrderLineQty,
                    0 as returned,
                    0 as returnRate,
                    100 as sellRate,
                    0 as outOfStock
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
            ");
            $stmt->execute($params);
            $kpis = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode($kpis);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_supply_by_location':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    l.location_name as location,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as onhand,
                    0 as softReserved,
                    0 as returned
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY l.location_name
                ORDER BY onhand DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $supplyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($supplyData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_return_rate_by_product':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    ROUND(COUNT(CASE WHEN p.stock_status = 'out of stock' THEN 1 END) * 100.0 / COUNT(*), 1) as returnRate
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY p.product_name
                HAVING returnRate > 0
                ORDER BY returnRate DESC
                LIMIT 12
            ");
            $stmt->execute($params);
            $returnData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($returnData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_stockout_items':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    -COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as stockout
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                AND p.stock_status = 'out of stock'
                ORDER BY stockout ASC
                LIMIT 15
            ");
            $stmt->execute($params);
            $stockoutData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($stockoutData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_products':
    try {
        $location_id = $data['location_id'] ?? null;
        $for_transfer = $data['for_transfer'] ?? false;
        
        $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived')";
        $params = [];
        
        if ($location_id) {
            $whereClause .= " AND p.location_id = ?";
            $params[] = $location_id;
        }
        
        // If for transfer, show OLD and NEW quantities separately for FIFO management
        if ($for_transfer) {
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name,
                    p.barcode,
                    p.description,
                
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.stock_status,
                    s.supplier_name,
                    b.brand,
                    l.location_name,
                    ss.batch_id,
                    ss.batch_reference,
                    b.entry_date,
                    b.entry_time,
                    b.entry_by,
                    COALESCE(p.date_added, CURDATE()) as date_added,
                    -- Show OLD quantity (oldest batch)
                    (SELECT ss2.available_quantity 
                     FROM tbl_stock_summary ss2 
                     INNER JOIN tbl_batch b2 ON ss2.batch_id = b2.batch_id 
                     WHERE ss2.product_id = p.product_id 
                     AND ss2.available_quantity > 0
                     AND b2.entry_date = (
                         SELECT MIN(b3.entry_date) 
                         FROM tbl_batch b3 
                         INNER JOIN tbl_stock_summary ss3 ON b3.batch_id = ss3.batch_id 
                         WHERE ss3.product_id = p.product_id AND ss3.available_quantity > 0
                     )
                     LIMIT 1) as old_quantity,
                    -- Show NEW quantity (newest batch)
                    (SELECT ss2.available_quantity 
                     FROM tbl_stock_summary ss2 
                     INNER JOIN tbl_batch b2 ON ss2.batch_id = b2.batch_id 
                     WHERE ss2.product_id = p.product_id 
                     AND ss2.available_quantity > 0
                     AND b2.entry_date = (
                         SELECT MAX(b3.entry_date) 
                         FROM tbl_batch b3 
                         INNER JOIN tbl_stock_summary ss3 ON b3.batch_id = ss3.batch_id 
                         WHERE ss3.product_id = p.product_id AND ss3.available_quantity > 0
                     )
                     LIMIT 1) as new_quantity,
                    -- Show total quantity
                    ss.available_quantity as total_quantity
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id 
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                INNER JOIN tbl_stock_summary ss ON p.product_id = ss.product_id
                INNER JOIN tbl_batch b ON ss.batch_id = b.batch_id
                WHERE ss.available_quantity > 0
                $whereClause
                GROUP BY p.product_id, p.product_name, c.category_name, p.barcode, p.description,
                         p.brand_id, p.supplier_id, p.location_id, p.stock_status, 
                         s.supplier_name, b.brand, l.location_name, ss.batch_id, ss.batch_reference, 
                         b.entry_date, b.entry_by, ss.available_quantity
                ORDER BY p.product_name ASC
            ");
        } else {
            // Modified query to show each batch as a separate row
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name,
                    p.barcode,
                    p.description,
                    p.prescription,
                    p.bulk,
                    p.product_type,
                    p.expiration,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.batch_id,
                    p.stock_status,
                    p.date_added,
                    p.created_at,
                    s.supplier_name,
                    br.brand,
                    l.location_name,
                    b.batch as batch_reference,
                    b.entry_date as batch_entry_date,
                    b.entry_time as batch_entry_time,
                    b.entry_by as batch_entry_by,
                    b.order_no as batch_order_no,
                    COALESCE(p.date_added, CURDATE()) as date_added_formatted
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id 
                LEFT JOIN tbl_brand br ON p.brand_id = br.brand_id 
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
                $whereClause
                ORDER BY p.product_name ASC, p.batch_id ASC, p.product_id ASC
            ");
        }
        
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $products
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "data" => []
        ]);
    }
    break;

    case 'get_suppliers':
        try {
            $stmt = $conn->prepare("
                SELECT * FROM tbl_supplier 
                WHERE status != 'archived' OR status IS NULL
                ORDER BY supplier_id DESC
            ");
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $suppliers
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    case 'get_brands':
        try {
            $stmt = $conn->prepare("SELECT * FROM tbl_brand ORDER BY brand_id DESC");
            $stmt->execute();
            $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $brands
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_categories':
        try {
            $stmt = $conn->prepare("SELECT * FROM tbl_category ORDER BY category_id");
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $categories
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_locations':
        try {
            $stmt = $conn->prepare("SELECT * FROM tbl_location ORDER BY location_id");
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $locations
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_inventory_staff':
        try {
            $stmt = $conn->prepare("
                SELECT emp_id, CONCAT(Fname, ' ', Lname) as name 
                FROM tbl_employee 
                WHERE status = 'Active'
                ORDER BY Fname, Lname
            ");
            $stmt->execute();
            $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $staff
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_transfers_with_details':
        try {
            $stmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id,
                    th.date,
                    th.status,
                    sl.location_name as source_location_name,
                    dl.location_name as destination_location_name,
                    e.Fname as employee_name,
                    COUNT(td.product_id) as total_products,
                    SUM(td.qty * COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0)) as total_value
                FROM tbl_transfer_header th
                LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                LEFT JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                LEFT JOIN tbl_product p ON td.product_id = p.product_id
                GROUP BY th.transfer_header_id
                ORDER BY th.transfer_header_id DESC
            ");
            $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get products for each transfer
            foreach ($transfers as &$transfer) {
                $stmt2 = $conn->prepare("
                    SELECT 
                        p.product_name, c.category_name, p.barcode,
                         p.description, p.brand_id,
                        b.brand,
                        td.qty as qty
                    FROM tbl_transfer_dtl td
                    JOIN tbl_product p ON td.product_id = p.product_id
                    LEFT JOIN tbl_category c ON p.category_id = c.category_id
                    LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                    WHERE td.transfer_header_id = ?
                ");
                $stmt2->execute([$transfer['transfer_header_id']]);
                $transfer['products'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                "success" => true,
                "data" => $transfers
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    case 'get_transferred_products_by_location':
        try {
            $location_id = $data['location_id'] ?? 0;
            $product_id = $data['product_id'] ?? 0;
            
            if (!$location_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Location ID is required"
                ]);
                break;
            }
            
            // Get transferred products for a specific location
            $stmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id,
                    th.delivery_date,
                    th.status,
                    td.product_id,
                    td.qty as transferred_qty,
                    p.product_name,
                    p.barcode,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    sl.location_name as source_location,
                    dl.location_name as destination_location
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                JOIN tbl_location sl ON th.source_location_id = sl.location_id
                JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                WHERE th.destination_location_id = ?
                " . ($product_id ? "AND td.product_id = ?" : "") . "
                ORDER BY th.transfer_header_id DESC
            ");
            
            if ($product_id) {
                $stmt->execute([$location_id, $product_id]);
            } else {
                $stmt->execute([$location_id]);
            }
            
            $transferred_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $transferred_products
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error getting transferred products: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_transfer_logs':
        try {
            $product_id = $data['product_id'] ?? 0;
            $location_id = $data['location_id'] ?? 0;
            $limit = $data['limit'] ?? 50;
            
            $where_conditions = [];
            $params = [];
            
            if ($product_id) {
                $where_conditions[] = "tl.product_id = ?";
                $params[] = $product_id;
            }
            
            if ($location_id) {
                $where_conditions[] = "(tl.from_location_id = ? OR tl.to_location_id = ?)";
                $params[] = $location_id;
                $params[] = $location_id;
            }
            
            $where_clause = "";
            if (!empty($where_conditions)) {
                $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            }
            
            // First, get the transfer logs
            $stmt = $conn->prepare("
                SELECT 
                    tl.transfer_id,
                    tl.product_id,
                    tl.product_name,
                    tl.from_location,
                    tl.to_location,
                    tl.quantity,
                    tl.transfer_date,
                    tl.created_at
                FROM tbl_transfer_log tl
                $where_clause
                ORDER BY tl.created_at DESC
                LIMIT $limit
            ");
            
            $stmt->execute($params);
            $transfer_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // For each transfer, get the individual batch details
            foreach ($transfer_logs as &$transfer) {
                $batch_stmt = $conn->prepare("
                    SELECT 
                        tbd.batch_id,
                        tbd.batch_reference,
                        tbd.quantity as batch_quantity,
                        COALESCE(tbd.srp, fs.srp) as batch_srp,
                        tbd.expiration_date,
                        fs.batch_id as fifo_batch_id
                    FROM tbl_transfer_batch_details tbd
                    LEFT JOIN tbl_fifo_stock fs ON tbd.batch_id = fs.batch_id
                    LEFT JOIN tbl_transfer_dtl td ON tbd.product_id = td.product_id
                    LEFT JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                    WHERE th.transfer_header_id = ?
                    ORDER BY tbd.batch_id
                ");
                
                $batch_stmt->execute([$transfer['transfer_id']]);
                $transfer['batch_details'] = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                "success" => true,
                "data" => $transfer_logs
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error getting transfer logs: " . $e->getMessage()
            ]);
        }
        break;
    case 'create_transfer_batch_details_table':
        try {
            // First create the transfer_log table if it doesn't exist
            $create_transfer_log_sql = "
                CREATE TABLE IF NOT EXISTS `tbl_transfer_log` (
                    `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
                    `product_id` int(11) NOT NULL,
                    `product_name` varchar(255) NOT NULL,
                    `from_location` varchar(255) NOT NULL,
                    `to_location` varchar(255) NOT NULL,
                    `quantity` int(11) NOT NULL,
                    `transfer_date` date NOT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`transfer_id`),
                    KEY `product_id` (`product_id`),
                    KEY `transfer_date` (`transfer_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ";
            
            $conn->exec($create_transfer_log_sql);
            
            // Ensure transfer_id column is properly set up as auto-increment
            try {
                $conn->exec("ALTER TABLE tbl_transfer_log MODIFY COLUMN transfer_id int(11) NOT NULL AUTO_INCREMENT");
            } catch (Exception $e) {
                // Column might already be properly configured, ignore error
                error_log("Transfer log table modification: " . $e->getMessage());
            }
            
            // Create the transfer batch details table if it doesn't exist
            $create_table_sql = "
                CREATE TABLE IF NOT EXISTS `tbl_transfer_batch_details` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `product_id` int(11) NOT NULL,
                    `batch_id` int(11) NOT NULL,
                    `batch_reference` varchar(255) NOT NULL,
                    `quantity` int(11) NOT NULL,
                    `srp` decimal(10,2) DEFAULT NULL,
                    `expiration_date` date DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `product_id` (`product_id`),
                    KEY `batch_id` (`batch_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ";
            
            $conn->exec($create_table_sql);
            
            // Note: tbl_transfer_batch_details doesn't use transfer_id column
            // The table structure uses product_id instead
            
            // Populate sample batch details for existing transfers
            $populate_stmt = $conn->prepare("
                INSERT IGNORE INTO tbl_transfer_batch_details 
                (product_id, batch_id, fifo_id, batch_reference, quantity, srp, expiration_date)
                SELECT 
                    tl.product_id,
                    COALESCE(fs.batch_id, 1) as batch_id,
                    CASE 
                        WHEN fs.fifo_id IS NOT NULL THEN fs.fifo_id
                        ELSE NULL
                    END as fifo_id,
                    COALESCE(fs.batch_reference, CONCAT('BR-', tl.transfer_id, '-', tl.product_id)) as batch_reference,
                    tl.quantity,
                    COALESCE(fs.srp, 10.00) as srp,
                    COALESCE(fs.expiration_date, DATE_ADD(CURDATE(), INTERVAL 1 YEAR)) as expiration_date
                FROM tbl_transfer_log tl
                LEFT JOIN tbl_fifo_stock fs ON tl.product_id = fs.product_id
                WHERE NOT EXISTS (
                    SELECT 1 FROM tbl_transfer_batch_details tbd 
                    WHERE tbd.product_id = tl.product_id
                )
                LIMIT 1
            ");
            
            $populate_stmt->execute();
            
            echo json_encode([
                "success" => true,
                "message" => "Transfer tables created and populated successfully"
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error creating transfer tables: " . $e->getMessage()
            ]);
        }
        break;
    case 'create_transfer':
        try {
            $source_location_id = $data['source_location_id'] ?? 0;
            $destination_location_id = $data['destination_location_id'] ?? 0;
            $employee_id = $data['employee_id'] ?? 0;
            $status = $data['status'] ?? 'approved'; // Use 'approved' to match database enum
            $products = $data['products'] ?? [];
            
            // Strict validation for locations
            if ($source_location_id == $destination_location_id) {
                error_log("[TRANSFER ERROR] Source and destination locations are the same! Source: $source_location_id, Destination: $destination_location_id");
                echo json_encode(["success" => false, "message" => "Source and destination locations cannot be the same!"]);
                break;
            }
            if ($destination_location_id == 0) {
                error_log("[TRANSFER ERROR] Invalid destination location! Destination: $destination_location_id");
                echo json_encode(["success" => false, "message" => "Invalid destination location!"]);
                break;
            }
            // Check if destination exists
            $locCheck = $conn->prepare("SELECT location_id, location_name FROM tbl_location WHERE location_id = ?");
            $locCheck->execute([$destination_location_id]);
            $destLoc = $locCheck->fetch(PDO::FETCH_ASSOC);
            if (!$destLoc) {
                error_log("[TRANSFER ERROR] Destination location does not exist! ID: $destination_location_id");
                echo json_encode(["success" => false, "message" => "Destination location does not exist!"]);
                break;
            }
            error_log("[TRANSFER] Source: $source_location_id, Destination: $destination_location_id ({$destLoc['location_name']})");
            
            if (empty($products)) {
                echo json_encode(["success" => false, "message" => "No products to transfer"]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Validate product quantities before transfer using FIFO total stock
            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $transfer_qty = $product['quantity'];
                
                // Get product name first
                $nameStmt = $conn->prepare("
                    SELECT product_name 
                    FROM tbl_product 
                    WHERE product_id = ? AND location_id = ?
                    LIMIT 1
                ");
                $nameStmt->execute([$product_id, $source_location_id]);
                $productInfo = $nameStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$productInfo) {
                    error_log("[TRANSFER ERROR] Product not found in source location - Product ID: $product_id");
                    throw new Exception("Product not found in source location - Product ID: " . $product_id);
                }
                
                // Check TOTAL available quantity across ALL FIFO batches (for multi-batch support)
                $fifoCheckStmt = $conn->prepare("
                    SELECT 
                        COALESCE(SUM(fs.available_quantity), 0) as total_available
                    FROM tbl_fifo_stock fs
                    JOIN tbl_product p ON fs.product_id = p.product_id
                    WHERE fs.product_id = ? 
                    AND p.location_id = ?
                    AND fs.available_quantity > 0
                    AND p.status = 'active'
                ");
                $fifoCheckStmt->execute([$product_id, $source_location_id]);
                $fifoAvailable = $fifoCheckStmt->fetch(PDO::FETCH_ASSOC);
                $total_available = $fifoAvailable['total_available'] ?? 0;
                
                if ($total_available < $transfer_qty) {
                    error_log("[TRANSFER ERROR] Insufficient FIFO stock for product: {$productInfo['product_name']} (Total Available across all batches: $total_available, Requested: $transfer_qty)");
                    throw new Exception("Insufficient quantity for product: " . $productInfo['product_name'] . 
                                     ". Total Available across all batches: " . $total_available . ", Requested: " . $transfer_qty);
                }
                
                // Log for debugging - show multi-batch support
                $batchCountStmt = $conn->prepare("
                    SELECT COUNT(*) as batch_count 
                    FROM tbl_fifo_stock fs
                    JOIN tbl_product p ON fs.product_id = p.product_id
                    WHERE fs.product_id = ? 
                    AND p.location_id = ?
                    AND fs.available_quantity > 0
                    AND p.status = 'active'
                ");
                $batchCountStmt->execute([$product_id, $source_location_id]);
                $batchCount = $batchCountStmt->fetch(PDO::FETCH_ASSOC)['batch_count'] ?? 0;
                
                error_log("[TRANSFER VALIDATION] Product ID: $product_id, Name: " . $productInfo['product_name'] . 
                         ", Total Available (FIFO): $total_available across $batchCount batch(es), Requested: $transfer_qty");
                         
                if ($transfer_qty > $total_available / 2) {
                    error_log("[TRANSFER INFO] Large transfer detected - will consume from multiple FIFO batches in oldest-first order");
                }
            }
            
            // Insert transfer header
            $stmt = $conn->prepare("
                INSERT INTO tbl_transfer_header (
                    source_location_id, destination_location_id, employee_id, 
                    status, date
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$source_location_id, $destination_location_id, $employee_id, $status]);
            $transfer_header_id = $conn->lastInsertId();
            
            // Insert transfer details and process the transfer
            $stmt2 = $conn->prepare("
                INSERT INTO tbl_transfer_dtl (
                    transfer_header_id, product_id, qty
                ) VALUES (?, ?, ?)
            ");
            
            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $transfer_qty = $product['quantity'];
                
                // Insert transfer detail
                $stmt2->execute([
                    $transfer_header_id,
                    $product_id,
                    $transfer_qty
                ]);
                
                // Get the original product details from source location
                $productStmt = $conn->prepare("
                    SELECT product_name, category_id, barcode, description, prescription, bulk,
                           expiration, brand_id, supplier_id, batch_id
                    FROM tbl_product 
                    WHERE product_id = ? AND location_id = ?
                    LIMIT 1
                ");
                $productStmt->execute([$product_id, $source_location_id]);
                $productDetails = $productStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($productDetails) {
                    // IMPLEMENT FIFO BATCH CONSUMPTION FOR TRANSFER
                    $remaining_transfer_qty = $transfer_qty;
                    $consumed_batches = [];
                    
                    // Get FIFO stock data for the product in source location (only if product is active)
                    $fifoStmt = $conn->prepare("
                        SELECT 
                            fs.fifo_id,
                            fs.batch_id,
                            fs.batch_reference,
                            fs.available_quantity,
                            fs.srp
                        FROM tbl_fifo_stock fs
                        JOIN tbl_batch b ON fs.batch_id = b.batch_id
                        JOIN tbl_product p ON fs.product_id = p.product_id
                        WHERE fs.product_id = ? 
                        AND fs.available_quantity > 0
                        AND p.status = 'active'
                        ORDER BY 
                            CASE 
                                WHEN fs.expiration_date IS NULL THEN 1 
                                ELSE 0 
                            END,
                            fs.expiration_date ASC, 
                            b.entry_date ASC, 
                            fs.fifo_id ASC
                    ");
                    $fifoStmt->execute([$product_id]);
                    $fifoStock = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($fifoStock)) {
                        throw new Exception("No FIFO stock available for product ID: $product_id in source location");
                    }
                    
                    // Debug: Log the FIFO stock data
                    error_log("FIFO Stock Data for transfer: " . json_encode($fifoStock));
                    error_log("Transfer quantity: $transfer_qty, Remaining: $remaining_transfer_qty");
                    
                    // Consume stock from FIFO order (oldest first)
                    foreach ($fifoStock as $batch) {
                        if ($remaining_transfer_qty <= 0) break;
                        
                        $batch_quantity = min($remaining_transfer_qty, $batch['available_quantity']);
                        
                        error_log("Processing batch: " . $batch['batch_reference'] . " - Available: " . $batch['available_quantity'] . ", Consuming: $batch_quantity");
                        
                        // Check if this batch will become empty after consumption
                        $willBeEmpty = ($batch['available_quantity'] - $batch_quantity) <= 0;
                        
                        error_log("Batch " . $batch['batch_reference'] . " will be empty: " . ($willBeEmpty ? 'YES' : 'NO') . " (Available: " . $batch['available_quantity'] . " - Consuming: $batch_quantity = " . ($batch['available_quantity'] - $batch_quantity) . ")");
                        
                        // If batch will be empty, set it to exactly 0
                        if ($willBeEmpty) {
                            error_log("Setting batch " . $batch['batch_reference'] . " to 0 (consumed completely)");
                            
                            $markConsumedStmt = $conn->prepare("
                                UPDATE tbl_fifo_stock 
                                SET available_quantity = 0, updated_at = NOW()
                                WHERE fifo_id = ?
                            ");
                            $markConsumedStmt->execute([$batch['fifo_id']]);
                            
                            // Also mark as consumed in stock_summary
                            $markSummaryConsumedStmt = $conn->prepare("
                                UPDATE tbl_stock_summary 
                                SET available_quantity = 0,
                                    last_updated = NOW()
                                WHERE product_id = ? AND batch_id = ?
                            ");
                            $markSummaryConsumedStmt->execute([$product_id, $batch['batch_id']]);
                            
                            error_log("Marked FIFO batch as consumed - FIFO ID: " . $batch['fifo_id'] . ", Batch: " . $batch['batch_reference'] . " (consumed completely)");
                        } else {
                            error_log("Updating batch " . $batch['batch_reference'] . " normally - reducing by $batch_quantity");
                            
                            // Update FIFO stock normally if batch won't be empty
                            $updateFifoStmt = $conn->prepare("
                                UPDATE tbl_fifo_stock 
                                SET available_quantity = available_quantity - ?
                                WHERE fifo_id = ?
                            ");
                            $updateFifoStmt->execute([$batch_quantity, $batch['fifo_id']]);
                            
                            // Also update stock_summary to keep tables in sync
                            $updateSummaryStmt = $conn->prepare("
                                UPDATE tbl_stock_summary 
                                SET available_quantity = available_quantity - ?,
                                    total_quantity = total_quantity - ?,
                                    last_updated = NOW()
                                WHERE product_id = ? AND batch_id = ?
                            ");
                            $updateSummaryStmt->execute([$batch_quantity, $batch_quantity, $product_id, $batch['batch_id']]);
                        }
                        
                        $consumed_batches[] = [
                            'batch_reference' => $batch['batch_reference'],
                            'quantity' => $batch_quantity,
                            'srp' => $batch['srp']
                        ];
                        
                        $remaining_transfer_qty -= $batch_quantity;
                        error_log("Consumed from batch " . $batch['batch_reference'] . ": $batch_quantity units for transfer. Remaining transfer qty: $remaining_transfer_qty");
                    }
                    
                    if ($remaining_transfer_qty > 0) {
                        throw new Exception("Insufficient stock available for transfer. Only " . ($transfer_qty - $remaining_transfer_qty) . " units available in FIFO stock.");
                    }
                    
                    // ✅ CORRECT APPROACH: NO tbl_product updates!
                    // Stock is managed purely through tbl_fifo_stock and tbl_transfer_batch_details
                    // tbl_product should remain as master data only (name, barcode, category, etc.)
                    // Products are location-independent; stock tracking is via FK relationships
                    
                    error_log("Transfer FIFO consumption completed - Product ID: $product_id, Qty: $transfer_qty, From: $source_location_id, To: $destination_location_id");
                    
                    error_log("Transfer completed - Product ID: $product_id, Qty: $transfer_qty, From: $source_location_id, To: $destination_location_id");
                    
                    // Log the transfer with FIFO batch details
                    error_log("Transfer completed with FIFO batch consumption - Product ID: $product_id, Quantity: $transfer_qty, From: $source_location_id, To: $destination_location_id, Batches: " . json_encode($consumed_batches));
                    
                    // Insert individual batch details into transfer_batch_details table
                    if (!empty($consumed_batches)) {
                        $batchDetailsStmt = $conn->prepare("
                            INSERT INTO tbl_transfer_batch_details 
                            (product_id, batch_id, fifo_id, batch_reference, quantity, srp, expiration_date, location_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        foreach ($consumed_batches as $batch) {
                            // Get batch details from FIFO stock including fifo_id
                            $batchInfoStmt = $conn->prepare("
                                SELECT fs.fifo_id, fs.batch_id, fs.expiration_date, fs.srp 
                                FROM tbl_fifo_stock fs 
                                WHERE fs.batch_reference = ? AND fs.product_id = ? 
                                LIMIT 1
                            ");
                            $batchInfoStmt->execute([$batch['batch_reference'], $product_id]);
                            $batchInfo = $batchInfoStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($batchInfo && $batchInfo['fifo_id'] !== null) {
                                // Only insert if fifo_id exists in tbl_fifo_stock
                                $batchDetailsStmt->execute([
                                    $product_id,
                                    $batchInfo['batch_id'],
                                    $batchInfo['fifo_id'],  // Include fifo_id
                                    $batch['batch_reference'],
                                    $batch['quantity'],
                                    $batchInfo['srp'],
                                    $batchInfo['expiration_date'],
                                    $destination_location_id  // Add destination location ID
                                ]);
                                error_log("Inserted transfer batch detail with fifo_id: " . $batchInfo['fifo_id']);
                            } else {
                                // Log warning if fifo_id not found
                                error_log("Warning: No valid fifo_id found for batch_reference: " . $batch['batch_reference'] . ", product_id: " . $product_id);
                            }
                        }
                    }
                }
            }
            
            $conn->commit();
            
            // ✅ NO AUTO-SYNC NEEDED!
            // tbl_product should NOT store quantities - that's what tbl_fifo_stock is for!
            // Products are location-independent; stock is managed through FK relationships
            
            // Log final transfer summary
            error_log("Transfer completed successfully - Transfer ID: $transfer_header_id, Products: " . count($products));
        // Insert into transfer log for tracking
        try {
            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $transfer_qty = $product['quantity'];
                
                // Get product details for logging
                $productStmt = $conn->prepare("
                    SELECT product_name, srp 
                    FROM tbl_product 
                    WHERE product_id = ?
                ");
                $productStmt->execute([$product_id]);
                $productDetails = $productStmt->fetch(PDO::FETCH_ASSOC);
                
                // Get location names for logging
                $sourceLocStmt = $conn->prepare("SELECT location_name FROM tbl_location WHERE location_id = ?");
                $sourceLocStmt->execute([$source_location_id]);
                $sourceLocation = $sourceLocStmt->fetch(PDO::FETCH_ASSOC);
                
                $destLocStmt = $conn->prepare("SELECT location_name FROM tbl_location WHERE location_id = ?");
                $destLocStmt->execute([$destination_location_id]);
                $destLocation = $destLocStmt->fetch(PDO::FETCH_ASSOC);
                
                // Insert into transfer log
                $logStmt = $conn->prepare("
                    INSERT INTO tbl_transfer_log (
                        product_id, product_name, from_location, to_location, 
                        quantity, transfer_date, created_at
                    ) VALUES (?, ?, ?, ?, ?, CURDATE(), NOW())
                ");
                $logStmt->execute([
                    $product_id,
                    $productDetails['product_name'] ?? 'Unknown Product',
                    $sourceLocation['location_name'] ?? 'Unknown Source',
                    $destLocation['location_name'] ?? 'Unknown Destination',
                    $transfer_qty
                ]);
                
                error_log("Transfer logged: Product ID $product_id, Qty: $transfer_qty, From: " . 
                    ($sourceLocation['location_name'] ?? 'Unknown') . ", To: " . 
                    ($destLocation['location_name'] ?? 'Unknown'));
            }
        } catch (Exception $logError) {
            error_log("Warning: Could not log transfer to tbl_transfer_log: " . $logError->getMessage());
            // Don't fail the transfer if logging fails
        }
        
        echo json_encode([
            "success" => true,
            "message" => "Transfer completed successfully. Products moved to destination location.",
            "transfer_id" => $transfer_header_id,
            "products_transferred" => count($products),
            "source_location" => $source_location_id,
            "destination_location" => $destination_location_id
        ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'add_supplier':
        try {
            $supplier_name = $data['supplier_name'] ?? '';
            $supplier_address = $data['supplier_address'] ?? '';
            $supplier_contact = $data['supplier_contact'] ?? '';
            $supplier_email = $data['supplier_email'] ?? '';
            $primary_phone = $data['primary_phone'] ?? '';
            $primary_email = $data['primary_email'] ?? '';
            $contact_person = $data['contact_person'] ?? '';
            $contact_title = $data['contact_title'] ?? '';
            $notes = $data['notes'] ?? '';
            
            $stmt = $conn->prepare("
                INSERT INTO tbl_supplier (
                    supplier_name, supplier_address, supplier_contact, supplier_email,
                    primary_phone, primary_email, contact_person, contact_title, notes, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            
            $stmt->execute([
                $supplier_name, $supplier_address, $supplier_contact, $supplier_email,
                $primary_phone, $primary_email, $contact_person, $contact_title, $notes
            ]);
            
            echo json_encode(["success" => true, "message" => "Supplier added successfully"]);
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'update_supplier':
        try {
            $supplier_id = $data['supplier_id'] ?? 0;
            $supplier_name = $data['supplier_name'] ?? '';
            $supplier_address = $data['supplier_address'] ?? '';
            $supplier_contact = $data['supplier_contact'] ?? '';
            $supplier_email = $data['supplier_email'] ?? '';
            $contact_person = $data['contact_person'] ?? '';
            $payment_terms = $data['payment_terms'] ?? '';
            $lead_time_days = $data['lead_time_days'] ?? '';
            $notes = $data['notes'] ?? '';
            
            $stmt = $conn->prepare("
                UPDATE tbl_supplier SET 
                    supplier_name = ?, supplier_address = ?, supplier_contact = ?,
                    supplier_email = ?, contact_person = ?, payment_terms = ?,
                    lead_time_days = ?, notes = ?
                WHERE supplier_id = ?
            ");
            
            $stmt->execute([
                $supplier_name, $supplier_address, $supplier_contact,
                $supplier_email, $contact_person, $payment_terms,
                $lead_time_days, $notes, $supplier_id
            ]);
            
            echo json_encode(["success" => true, "message" => "Supplier updated successfully"]);
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'delete_supplier':
        try {
            $supplier_id = $data['supplier_id'] ?? 0;
            $reason = $data['reason'] ?? 'Archived by user';
            $archived_by = $data['archived_by'] ?? 'admin';
            
            // Get supplier details before archiving
            $stmt = $conn->prepare("SELECT * FROM tbl_supplier WHERE supplier_id = ?");
            $stmt->execute([$supplier_id]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$supplier) {
                echo json_encode(["success" => false, "message" => "Supplier not found"]);
                break;
            }

            $conn->beginTransaction();

            try {
                // Update supplier status to archived
                $stmt = $conn->prepare("UPDATE tbl_supplier SET status = 'archived' WHERE supplier_id = ?");
                $stmt->execute([$supplier_id]);
                
                // Add to archive table
                $stmt = $conn->prepare("
                    INSERT INTO tbl_archive (
                        item_id, item_type, item_name, item_description, category, 
                        archived_by, archived_date, archived_time, reason, status, original_data
                    ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, 'Archived', ?)
                ");
                $stmt->execute([
                    $supplier_id,
                    'Supplier',
                    $supplier['supplier_name'],
                    $supplier['supplier_address'] ?? '',
                    'Suppliers',
                    $archived_by,
                    $reason,
                    json_encode($supplier)
                ]);

                $conn->commit();
                echo json_encode(["success" => true, "message" => "Supplier archived successfully"]);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'delete_product':
        try {
            $product_id = $data['product_id'] ?? 0;
            $reason = $data['reason'] ?? 'Archived by user';
            $archived_by = $data['archived_by'] ?? 'admin';
            
            // Get product details before archiving
            $stmt = $conn->prepare("SELECT * FROM tbl_product WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                echo json_encode(["success" => false, "message" => "Product not found"]);
                break;
            }

            $conn->beginTransaction();

            try {
                // Update product status to archived
                $stmt = $conn->prepare("UPDATE tbl_product SET status = 'archived' WHERE product_id = ?");
                $stmt->execute([$product_id]);
                
                // Add to archive table
                $stmt = $conn->prepare("
                    INSERT INTO tbl_archive (
                        item_id, item_type, item_name, item_description, category, 
                        archived_by, archived_date, archived_time, reason, status, original_data
                    ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, 'Archived', ?)
                ");
                $stmt->execute([
                    $product_id,
                    'Product',
                    $product['product_name'],
                    $product['description'] ?? '',
                    $product['category'] ?? '',
                    $archived_by,
                    $reason,
                    json_encode($product)
                ]);

                $conn->commit();
                echo json_encode(["success" => true, "message" => "Product archived successfully"]);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'update_transfer_status':
        try {
            $transfer_header_id = $data['transfer_header_id'] ?? 0;
            $new_status = $data['status'] ?? '';
            $employee_id = $data['employee_id'] ?? 0;
            $notes = $data['notes'] ?? '';
            
            if (!$transfer_header_id || !$new_status) {
                echo json_encode(["success" => false, "message" => "Transfer ID and status are required"]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Update transfer status
            $stmt = $conn->prepare("
                UPDATE tbl_transfer_header 
                SET status = ? 
                WHERE transfer_header_id = ?
            ");
            $stmt->execute([$new_status, $transfer_header_id]);
            
            // If status is "Completed", add products to destination location
            if ($new_status === 'Completed') {
                // Get transfer details
                $transferStmt = $conn->prepare("
                    SELECT th.source_location_id, th.destination_location_id, td.product_id, td.qty
                    FROM tbl_transfer_header th
                    JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                    WHERE th.transfer_header_id = ?
                ");
                $transferStmt->execute([$transfer_header_id]);
                $transferDetails = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($transferDetails as $detail) {
                    $product_id = $detail['product_id'];
                    $qty = $detail['qty'];
                    $destination_location_id = $detail['destination_location_id'];
                    
                    // Get the original product details
                    $productStmt = $conn->prepare("
                        SELECT product_name, category_id, barcode, description, prescription, bulk,
                               expiration, brand_id, supplier_id, batch_id, status,
                        FROM tbl_product 
                        WHERE product_id = ?
                        LIMIT 1
                    ");
                    $productStmt->execute([$product_id]);
                    $productDetails = $productStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($productDetails) {
                        // Check if product exists in destination location
                        $checkStmt = $conn->prepare("
                            SELECT product_id, quantity 
                            FROM tbl_product 
                            WHERE product_id = ? AND location_id = ?
                        ");
                        $checkStmt->execute([$product_id, $destination_location_id]);
                        $existingProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existingProduct) {
                            // Update existing product quantity
                            $updateStmt = $conn->prepare("
                                UPDATE tbl_product 
                                SET quantity = quantity + ?,
                                    stock_status = CASE 
                                        WHEN quantity + ? <= 0 THEN 'out of stock'
                                        WHEN quantity + ? <= 10 THEN 'low stock'
                                        ELSE 'in stock'
                                    END
                                WHERE product_id = ? AND location_id = ?
                            ");
                            $updateStmt->execute([$qty, $qty, $qty, $product_id, $destination_location_id]);
                        } else {
                            // Create new product entry in destination location
                            $insertStmt = $conn->prepare("
                                INSERT INTO tbl_product (
                                    product_name, category_id, barcode, description, prescription, bulk,
                                    expiration, quantity, brand_id, supplier_id,
                                    location_id, batch_id, status, stock_status
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insertStmt->execute([
                                $productDetails['product_name'],
                                $productDetails['category_id'],
                                $productDetails['barcode'],
                                $productDetails['description'],
                                $productDetails['prescription'],
                                $productDetails['bulk'],
                                $productDetails['expiration'],
                                $qty,
                                $productDetails['srp'],
                                $productDetails['brand_id'],
                                $productDetails['supplier_id'],
                                $destination_location_id,
                                $productDetails['batch_id'],
                                $qty <= 0 ? 'out of stock' : ($qty <= 10 ? 'low stock' : 'in stock')
                            ]);
                        }
                    }
                }
            }
            
            // Log the status change
            $logStmt = $conn->prepare("
                INSERT INTO tbl_transfer_log (
                    transfer_header_id, status, employee_id, notes, log_date
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([$transfer_header_id, $new_status, $employee_id, $notes]);
            
            $conn->commit();
            echo json_encode([
                "success" => true, 
                "message" => "Transfer status updated to " . $new_status . 
                            ($new_status === 'Completed' ? ". Products added to destination location." : "")
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;
    case 'delete_transfer':
        try {
            $transfer_header_id = $data['transfer_header_id'] ?? 0;
            
            if (!$transfer_header_id) {
                echo json_encode(["success" => false, "message" => "Transfer ID is required"]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get transfer details to restore quantities
            $transferStmt = $conn->prepare("
                SELECT th.source_location_id, td.product_id, td.qty
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                WHERE th.transfer_header_id = ?
            ");
            $transferStmt->execute([$transfer_header_id]);
            $transferDetails = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Restore quantities to source location
            foreach ($transferDetails as $detail) {
                $updateStmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET quantity = quantity + ?,
                        stock_status = CASE 
                            WHEN quantity + ? <= 0 THEN 'out of stock'
                            WHEN quantity + ? <= 10 THEN 'low stock'
                            ELSE 'in stock'
                        END
                    WHERE product_id = ?
                ");
                $updateStmt->execute([$detail['qty'], $detail['qty'], $detail['qty'], $detail['product_id']]);
            }
            
            // Delete transfer details
            $deleteDetailsStmt = $conn->prepare("DELETE FROM tbl_transfer_dtl WHERE transfer_header_id = ?");
            $deleteDetailsStmt->execute([$transfer_header_id]);
            
            // Delete transfer header
            $deleteHeaderStmt = $conn->prepare("DELETE FROM tbl_transfer_header WHERE transfer_header_id = ?");
            $deleteHeaderStmt->execute([$transfer_header_id]);
            
            $conn->commit();
            echo json_encode(["success" => true, "message" => "Transfer deleted successfully. Quantities restored to source location."]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;

    case 'get_batches':
        try {
            $stmt = $conn->prepare("
                SELECT 
                    b.batch_id,
                    b.batch,
                    b.entry_date,
                    b.entry_time,
                    b.entry_by,
                    b.order_no,
                    s.supplier_name,
                    l.location_name,
                    COUNT(p.product_id) as product_count,
                    SUM(COALESCE((SELECT SUM(fs.available_quantity * fs.srp) FROM tbl_fifo_stock fs WHERE fs.batch_id = b.batch_id), 0)) as total_value
                FROM tbl_batch b
                LEFT JOIN tbl_supplier s ON b.supplier_id = s.supplier_id
                LEFT JOIN tbl_location l ON b.location_id = l.location_id
                LEFT JOIN tbl_product p ON b.batch_id = p.batch_id
                WHERE b.batch IS NOT NULL AND b.batch != ''
                GROUP BY b.batch_id, b.batch, b.entry_date, b.entry_time, b.entry_by, b.order_no, s.supplier_name, l.location_name
                ORDER BY b.batch_id DESC
            ");
            $stmt->execute();
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $batches
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    
    case 'get_locations_for_filter':
        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT location_name 
                FROM tbl_location 
                ORDER BY location_name
            ");
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $locations
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    case 'get_products_by_location':
        try {
            $location_name = $data['location_name'] ?? '';
            
            if (empty($location_name)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Location name is required"
                ]);
                break;
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    p.*,
                    s.supplier_name,
                    b.brand,
                    l.location_name,
                    batch.batch as batch_reference,
                    batch.entry_date,
                    batch.entry_by
                FROM tbl_product p 
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id 
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id 
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_batch batch ON p.batch_id = batch.batch_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND l.location_name = ?
                ORDER BY p.product_name ASC
            ");
            $stmt->execute([$location_name]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $products
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    // POS: initial product list for POS UI
    case 'get_pos_products':
        try {
            $location_name = $data['location_name'] ?? '';
            $where = "(p.status IS NULL OR p.status = 'active')";
            $params = [];
            if ($location_name !== '') {
                $where .= " AND l.location_name = ?";
                $params[] = $location_name;
            }

            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    l.location_name
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE $where
                ORDER BY p.product_name ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $rows
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    case 'check_product_name':
        try {
            $product_name = $data['product_name'] ?? '';
            $location_name = $data['location_name'] ?? null;
            $location_id = $data['location_id'] ?? null;
            
            if (empty($product_name)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product name is required"
                ]);
                break;
            }
            
            // Find product by name first
            $stmt = $conn->prepare(
                "SELECT p.*, l.location_name AS base_location_name,
                        c.category_name, b.brand
                 FROM tbl_product p
                 LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                 LEFT JOIN tbl_category c ON p.category_id = c.category_id
                 LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                 WHERE p.product_name = ?
                 AND (p.status IS NULL OR p.status <> 'archived')
                 LIMIT 1"
            );
            $stmt->execute([$product_name]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                $product_id = (int)$product['product_id'];
                
                // Resolve effective location from the latest approved transfer (destination)
                $tx = $conn->prepare(
                    "SELECT th.destination_location_id AS dest_id, dl.location_name AS dest_name
                     FROM tbl_transfer_dtl td
                     JOIN tbl_transfer_header th ON th.transfer_header_id = td.transfer_header_id AND th.status = 'approved'
                     LEFT JOIN tbl_location dl ON dl.location_id = th.destination_location_id
                     WHERE td.product_id = ?
                     ORDER BY th.transfer_header_id DESC
                     LIMIT 1"
                );
                $tx->execute([$product_id]);
                $latestTransfer = $tx->fetch(PDO::FETCH_ASSOC);
                
                $effective_location_id = $product['location_id'];
                $effective_location_name = $product['base_location_name'];
                if ($latestTransfer && !empty($latestTransfer['dest_id'])) {
                    $effective_location_id = (int)$latestTransfer['dest_id'];
                    $effective_location_name = $latestTransfer['dest_name'];
                }
                
                // Validate against requested location, if any
                $matchesTarget = true;
                if (!empty($location_id)) {
                    $matchesTarget = ((int)$location_id === (int)$effective_location_id);
                } else if (!empty($location_name)) {
                    $matchesTarget = (mb_strtolower(trim($location_name)) === mb_strtolower(trim((string)$effective_location_name)));
                }
                
                if ($matchesTarget) {
                    $product['location_id'] = $effective_location_id;
                    $product['location_name'] = $effective_location_name;
                    $product['category'] = $product['category_name'];
                    $product['brand'] = $product['brand'] ?: 'N/A';

                    // Compute an approximate on-hand for resolved location using transfers in/out
                    // Transfers IN to this location
                    $inStmt = $conn->prepare(
                        "SELECT COALESCE(SUM(td.qty), 0) as total_in
                         FROM tbl_transfer_dtl td
                         JOIN tbl_transfer_header th ON th.transfer_header_id = td.transfer_header_id 
                         WHERE td.product_id = ? AND th.destination_location_id = ? AND th.status = 'approved'"
                    );
                    $inStmt->execute([$product_id, $effective_location_id]);
                    $totalIn = $inStmt->fetch(PDO::FETCH_ASSOC)['total_in'];

                    // Transfers OUT from this location
                    $outStmt = $conn->prepare(
                        "SELECT COALESCE(SUM(td.qty), 0) as total_out
                         FROM tbl_transfer_dtl td
                         JOIN tbl_transfer_header th ON th.transfer_header_id = td.transfer_header_id 
                         WHERE td.product_id = ? AND th.source_location_id = ? AND th.status = 'approved'"
                    );
                    $outStmt->execute([$product_id, $effective_location_id]);
                    $totalOut = $outStmt->fetch(PDO::FETCH_ASSOC)['total_out'];

                    // Approximate quantity = base quantity + transfers in - transfers out
                    $approximateQuantity = max(0, $product['quantity'] + $totalIn - $totalOut);
                    $product['quantity'] = $approximateQuantity;

                    echo json_encode([
                        "success" => true,
                        "message" => "Product found by name",
                        "product" => $product
                    ]);
                } else {
                    echo json_encode([
                        "success" => false,
                        "message" => "Product found but not in requested location. Product is in: " . $effective_location_name
                    ]);
                }
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Product not found with name: " . $product_name
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    // check_barcode - REMOVED from backend.php
    // This action is now handled by Api/modules/barcode.php via backend_modular.php
    // To use: route 'check_barcode' action to 'backend_modular.php' in apiHandler.js

    case 'get_product_batches':
        try {
            $product_id = $data['product_id'] ?? 0;
            
            if (empty($product_id)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product ID is required",
                    "data" => []
                ]);
                break;
            }
            
            // Get all batches for the specific product
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.batch_id,
                    p.expiration,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    p.date_added,
                    p.status,
                    b.batch,
                    s.supplier_name,
                    l.location_name
                FROM tbl_product p
                LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
                LEFT JOIN tbl_supplier s ON b.supplier_id = s.supplier_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE p.product_id = ?
                AND (p.status IS NULL OR p.status <> 'archived')
                ORDER BY p.expiration ASC, p.date_added DESC
            ");
            $stmt->execute([$product_id]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $batches
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    // POS: fetch discount options from tbl_discount
    case 'get_discounts':
        try {
            $stmt = $conn->prepare("SELECT discount_id, discount_rate, discount_type FROM tbl_discount");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $rows
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;


    case 'test_database_connection':
        try {
            // Test basic connection
            $testStmt = $conn->prepare("SELECT 1 as test");
            $testStmt->execute();
            $result = $testStmt->fetch(PDO::FETCH_ASSOC);
            
            // Test table existence
            $tables = ['tbl_product', 'tbl_batch', 'tbl_fifo_stock', 'tbl_stock_movements'];
            $tableStatus = [];
            
            foreach ($tables as $table) {
                try {
                    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM $table LIMIT 1");
                    $checkStmt->execute();
                    $count = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    $tableStatus[$table] = "EXISTS - {$count['count']} records";
                } catch (Exception $e) {
                    $tableStatus[$table] = "ERROR: " . $e->getMessage();
                }
            }
            
            echo json_encode([
                "success" => true,
                "message" => "Database connection test",
                "connection" => "OK",
                "test_query" => $result,
                "tables" => $tableStatus
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database connection failed: " . $e->getMessage()
            ]);
        }
        break;

    case 'simple_update_product_stock':
        try {
            $product_id = $data['product_id'] ?? 0;
            $new_quantity = $data['new_quantity'] ?? 0;
            
            error_log("🔍 Simple Update - Product ID: $product_id, Quantity: $new_quantity");
            
            if ($product_id <= 0 || $new_quantity <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID or quantity"
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Just update the product quantity - simplest possible operation
            $updateStmt = $conn->prepare("
                UPDATE tbl_product 
                SET quantity = quantity + ?
                WHERE product_id = ?
            ");
            $updateStmt->execute([$new_quantity, $product_id]);
            
            $conn->commit();
            
            error_log("✅ Simple update successful - Product ID: $product_id, Added: $new_quantity");
            
            echo json_encode([
                "success" => true,
                "message" => "Simple stock update successful"
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            
            error_log("❌ Simple update failed: " . $e->getMessage());
            
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'update_product_stock':
        try {
            $product_id = $data['product_id'] ?? 0;
            $new_quantity = $data['new_quantity'] ?? 0;
            $batch_reference = $data['batch_reference'] ?? '';
            $expiration_date = $data['expiration_date'] ?? null;
            $unit_cost = $data['unit_cost'] ?? 0;
            $new_srp = $data['new_srp'] ?? null;
            $entry_by = $data['entry_by'] ?? 'admin';
            
            error_log("🔍 Update Product Stock - Received data: " . json_encode($data));
            error_log("🔍 Product ID: $product_id, Quantity: $new_quantity, Batch Ref: $batch_reference");
            
            // Use new_srp if provided, otherwise use 0
            $srp = $new_srp ?? 0;
            
            if ($product_id <= 0 || $new_quantity <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID or quantity"
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get current product details (NO quantity - it's in tbl_fifo_stock only)
            $productStmt = $conn->prepare("
                SELECT product_name, category_id, barcode, description, prescription, bulk,
                       expiration, brand_id, supplier_id, location_id, status
                FROM tbl_product 
                WHERE product_id = ?
                LIMIT 1
            ");
            $productStmt->execute([$product_id]);
            $productDetails = $productStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$productDetails) {
                throw new Exception("Product not found");
            }
            
            // Get old quantity from tbl_fifo_stock instead
            $oldQtyStmt = $conn->prepare("
                SELECT COALESCE(SUM(available_quantity), 0) as total_quantity
                FROM tbl_fifo_stock
                WHERE product_id = ?
            ");
            $oldQtyStmt->execute([$product_id]);
            $old_quantity = $oldQtyStmt->fetchColumn();
            $quantity_change = $new_quantity; // This is the amount being added
            
            error_log("🔍 Product details: " . json_encode($productDetails));
            error_log("🔍 Old quantity: $old_quantity, New quantity: $new_quantity, Quantity change: $quantity_change");
            
            // Create batch record - always create one for stock updates
            $batch_id = null;
            $final_batch_reference = $batch_reference ?: 'STOCK-UPDATE-' . date('YmdHis') . '-' . $product_id;
            
            error_log("🔍 About to create batch with reference: $final_batch_reference");
            
            $batchStmt = $conn->prepare("
                INSERT INTO tbl_batch (
                    date, batch, batch_reference, supplier_id, location_id, 
                    entry_date, entry_time, entry_by
                ) VALUES (?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?)
            ");
            $batchStmt->execute([
                date('Y-m-d'), 
                $final_batch_reference, 
                $final_batch_reference, 
                $productDetails['supplier_id'], 
                $productDetails['location_id'], 
                $entry_by
            ]);
            $batch_id = $conn->lastInsertId();
            
            error_log("✅ Batch created successfully - Batch ID: $batch_id, Reference: $final_batch_reference");
            
            error_log("🔍 Skipping product quantity update - quantity is ONLY in tbl_fifo_stock now");
            
            // NO LONGER UPDATE tbl_product.quantity - it doesn't exist anymore!
            // Only update batch_id and expiration if provided
            if ($batch_id || $expiration_date) {
                $updateStmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET batch_id = COALESCE(?, batch_id),
                        expiration = COALESCE(?, expiration)
                    WHERE product_id = ?
                ");
                $updateStmt->execute([$batch_id, $expiration_date, $product_id]);
                error_log("✅ Product metadata updated successfully");
            }
            
            error_log("🔍 About to create FIFO stock entry");
            
            // Create FIFO stock entry
            $fifoStmt = $conn->prepare("
                INSERT INTO tbl_fifo_stock (
                    product_id, batch_id, batch_reference, quantity, available_quantity,
                    srp, expiration_date, entry_date, entry_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
            ");
            $fifoStmt->execute([
                $product_id, $batch_id, $final_batch_reference, $new_quantity, $new_quantity,
                $srp, $expiration_date, $entry_by
            ]);
            
            error_log("✅ FIFO stock entry created successfully");
            
            error_log("🔍 About to create stock movement record");
            
            // Record the stock movement for tracking quantity changes
            $movementStmt = $conn->prepare("
                INSERT INTO tbl_stock_movements (
                    product_id, batch_id, movement_type, quantity, remaining_quantity,
                    expiration_date, reference_no, notes, created_by
                ) VALUES (?, ?, 'IN', ?, ?, ?, ?, ?, ?)
            ");
            $movementStmt->execute([
                $product_id,
                $batch_id,
                $quantity_change,
                $old_quantity + $new_quantity,
                $expiration_date,
                $final_batch_reference,
                "Stock added: +{$quantity_change} units. Old: {$old_quantity}, New: " . ($old_quantity + $new_quantity),
                $entry_by
            ]);
            
            error_log("✅ Stock movement record created successfully");
            
            $conn->commit();
            
            error_log("✅ Stock update successful - Product ID: $product_id, Added: $new_quantity, Batch: $batch_id");
            
            echo json_encode([
                "success" => true,
                "message" => "Stock updated successfully with FIFO tracking"
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            
            error_log("❌ Stock update failed: " . $e->getMessage());
            error_log("❌ Product ID: $product_id, Quantity: $new_quantity, Batch ID: $batch_id");
            
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'reduce_product_stock':
        try {
            $product_id = $data['product_id'] ?? 0;
            $quantity_to_reduce = $data['quantity'] ?? 0;
            $transaction_id = $data['transaction_id'] ?? '';
            $location_name = $data['location_name'] ?? '';
            $entry_by = $data['entry_by'] ?? 'POS System';
            
            if ($product_id <= 0 || $quantity_to_reduce <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID or quantity"
                ]);
                break;
            }
            
            if (empty($location_name)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Location name is required for stock updates"
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get location ID for the specified location
            $locationStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name = ?");
            $locationStmt->execute([$location_name]);
            $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$location) {
                throw new Exception("Location '{$location_name}' not found");
            }
            $location_id = $location['location_id'];
            
            // First, check if this is a transferred product in the current location
            $transferStmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id, 
                    td.transfer_dtl_id, 
                    td.qty as available_transfer_qty,
                    p.product_name,
                    p.batch_id
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                WHERE td.product_id = ? 
                AND th.destination_location_id = ?
                AND th.status = 'approved'
                AND td.qty > 0
                ORDER BY th.date DESC
                LIMIT 1
            ");
            $transferStmt->execute([$product_id, $location_id]);
            $transferDetails = $transferStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($transferDetails) {
                // This is a transferred product - update transfer quantities
                $current_quantity = $transferDetails['available_transfer_qty'];
                
                // Check if we have enough stock in the transfer
                if ($current_quantity < $quantity_to_reduce) {
                    throw new Exception("Insufficient stock in {$location_name}. Available: {$current_quantity}, Requested: {$quantity_to_reduce}");
                }
                
                $new_quantity = $current_quantity - $quantity_to_reduce;
                
                // Update transfer detail quantity
                $updateTransferStmt = $conn->prepare("
                    UPDATE tbl_transfer_dtl 
                    SET qty = ?
                    WHERE transfer_dtl_id = ?
                ");
                $updateTransferStmt->execute([$new_quantity, $transferDetails['transfer_dtl_id']]);
                
                error_log("Updated transfer quantity for product $product_id in {$location_name}: {$current_quantity} -> {$new_quantity}");
                
                // Record the stock movement for the transfer
                $movementStmt = $conn->prepare("
                    INSERT INTO tbl_stock_movements (
                        product_id, batch_id, movement_type, quantity, remaining_quantity,
                        reference_no, notes, created_by
                    ) VALUES (?, ?, 'OUT', ?, ?, ?, ?, ?)
                ");
                $movementStmt->execute([
                    $product_id,
                    $transferDetails['batch_id'],
                    $quantity_to_reduce,
                    $new_quantity,
                    $transaction_id,
                    "POS Sale from {$location_name}: -{$quantity_to_reduce} units sold from transfer. Transfer qty: {$current_quantity} -> {$new_quantity}",
                    $entry_by
                ]);
                
                $conn->commit();
                echo json_encode([
                    "success" => true,
                    "message" => "Transfer stock reduced successfully for POS sale in {$location_name}",
                    "data" => [
                        "product_id" => $product_id,
                        "product_name" => $transferDetails['product_name'],
                        "location" => $location_name,
                        "old_quantity" => $current_quantity,
                        "new_quantity" => $new_quantity,
                        "quantity_reduced" => $quantity_to_reduce,
                        "transaction_id" => $transaction_id,
                        "stock_type" => "transferred"
                    ]
                ]);
                break;
            }
            
            // If not transferred, check if it's a regular product in this location
            $productStmt = $conn->prepare("
                SELECT product_name, category_id, barcode, description, prescription, bulk,
                       expiration, srp, brand_id, supplier_id, location_id, status, quantity,
                       batch_id
                FROM tbl_product 
                WHERE product_id = ? AND location_id = ?
                LIMIT 1
            ");
            $productStmt->execute([$product_id, $location_id]);
            $productDetails = $productStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$productDetails) {
                throw new Exception("Product not found in {$location_name}. Product may be in a different location or not exist.");
            }
            
            $current_quantity = $productDetails['quantity'];
            
            // Check if we have enough stock
            if ($current_quantity < $quantity_to_reduce) {
                throw new Exception("Insufficient stock in {$location_name}. Available: {$current_quantity}, Requested: {$quantity_to_reduce}");
            }
            
            $new_quantity = $current_quantity - $quantity_to_reduce;
            
            // Update product quantity (only for the specific location)
            $updateStmt = $conn->prepare("
                UPDATE tbl_product 
                SET quantity = ?,
                    stock_status = CASE 
                        WHEN ? <= 0 THEN 'out of stock'
                        WHEN ? <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END
                WHERE product_id = ? AND location_id = ?
            ");
            $updateStmt->execute([$new_quantity, $new_quantity, $new_quantity, $product_id, $location_id]);
            
            // Update FIFO stock - reduce from oldest batch first
            $fifoStmt = $conn->prepare("
                SELECT fifo_id, available_quantity, batch_reference
                FROM tbl_fifo_stock 
                WHERE product_id = ? AND available_quantity > 0
                ORDER BY 
                    CASE 
                        WHEN expiration_date IS NULL THEN 1 
                        ELSE 0 
                    END,
                    expiration_date ASC, 
                    entry_date ASC, 
                    fifo_id ASC
                FOR UPDATE
            ");
            $fifoStmt->execute([$product_id]);
            $fifoBatches = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $remaining_to_reduce = $quantity_to_reduce;
            
            foreach ($fifoBatches as $batch) {
                if ($remaining_to_reduce <= 0) break;
                
                $batch_quantity = min($batch['available_quantity'], $remaining_to_reduce);
                
                // Update FIFO stock
                $updateFifoStmt = $conn->prepare("
                    UPDATE tbl_fifo_stock 
                    SET available_quantity = available_quantity - ?
                    WHERE fifo_id = ?
                ");
                $updateFifoStmt->execute([$batch_quantity, $batch['fifo_id']]);
                
                $remaining_to_reduce -= $batch_quantity;
            }
            
            // This section is now handled above in the transferred product logic
            
            // Record the stock movement for tracking quantity changes
            $movementStmt = $conn->prepare("
                INSERT INTO tbl_stock_movements (
                    product_id, batch_id, movement_type, quantity, remaining_quantity,
                    expiration_date, reference_no, notes, created_by
                ) VALUES (?, ?, 'OUT', ?, ?, ?, ?, ?, ?)
            ");
            $movementStmt->execute([
                $product_id,
                $productDetails['batch_id'],
                $quantity_to_reduce,
                $new_quantity,
                $productDetails['expiration'],
                $transaction_id,
                "POS Sale from {$location_name}: -{$quantity_to_reduce} units sold. Regular product qty: {$current_quantity} -> {$new_quantity}",
                $entry_by
            ]);
            
            $conn->commit();
            echo json_encode([
                "success" => true,
                "message" => "Regular stock reduced successfully for POS sale in {$location_name}",
                "data" => [
                    "product_id" => $product_id,
                    "product_name" => $productDetails['product_name'],
                    "location" => $location_name,
                    "old_quantity" => $current_quantity,
                    "new_quantity" => $new_quantity,
                    "quantity_reduced" => $quantity_to_reduce,
                    "transaction_id" => $transaction_id,
                    "stock_type" => "regular",
                    "stock_status" => $new_quantity <= 0 ? 'out of stock' : ($new_quantity <= 10 ? 'low stock' : 'in stock')
                ]
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    // Removed duplicate get_pos_inventory case - using sales_api.php instead
    /*
        try {
            $location_id = $data['location_id'] ?? 0;
            $search = $data['search'] ?? '';
            
            if (!$location_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Location ID is required"
                ]);
                break;
            }
            
            $whereConditions = ["p.location_id = ?"];
            $params = [$location_id];
            
            if (!empty($search)) {
                $whereConditions[] = "(p.product_name LIKE ? OR p.barcode LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            // Get products with real-time stock levels from transfer batch details
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name,
                    p.barcode,
                    p.description,
                    COALESCE(SUM(tbd.quantity), 0) as quantity,
                    -- Prioritize SRP from earliest expiring batch
                    COALESCE(
                        (SELECT tbd2.srp 
                         FROM tbl_transfer_batch_details tbd2
                         WHERE tbd2.product_id = p.product_id 
                         AND tbd2.location_id = ?
                         AND tbd2.quantity > 0
                         ORDER BY 
                            CASE WHEN tbd2.expiration_date IS NULL THEN 1 ELSE 0 END,
                            tbd2.expiration_date ASC,
                            tbd2.id ASC
                         LIMIT 1),
                        (SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0
                    ) as srp,
                    CASE 
                        WHEN COALESCE(SUM(tbd.quantity), 0) <= 0 THEN 'out of stock'
                        WHEN COALESCE(SUM(tbd.quantity), 0) <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END as stock_status,
                    p.status,
                    COALESCE(b.brand, '') as brand,
                    COALESCE(s.supplier_name, '') as supplier_name,
                    'Regular' as product_type
                FROM tbl_product p
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_transfer_batch_details tbd ON p.product_id = tbd.product_id
                WHERE p.status = 'active' AND (tbd.location_id = ? OR tbd.location_id IS NULL)
                GROUP BY p.product_id, p.product_name, c.category_name, p.barcode, p.description, p.status, b.brand, s.supplier_name
                HAVING COALESCE(SUM(tbd.quantity), 0) > 0
                ORDER BY p.product_name ASC
            ");
            
            $stmt->execute(array_merge([$location_id, $location_id], $params));
            $regularProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get transferred products with current available quantities
            $transferStmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name,
                    p.barcode,
                    p.description,
                    td.qty as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    CASE 
                        WHEN td.qty <= 0 THEN 'out of stock'
                        WHEN td.qty <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END as stock_status,
                    'active' as status,
                    COALESCE(b.brand, '') as brand,
                    COALESCE(s.supplier_name, '') as supplier_name,
                    'Transferred' as product_type,
                    th.transfer_header_id
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                WHERE th.destination_location_id = ?
                AND th.status = 'approved'
                AND td.qty > 0
                " . (!empty($search) ? "AND (p.product_name LIKE ? OR p.barcode LIKE ?)" : "") . "
                ORDER BY p.product_name ASC
            ");
            
            $transferParams = [$location_id];
            if (!empty($search)) {
                $transferParams[] = "%$search%";
                $transferParams[] = "%$search%";
            }
            
            $transferStmt->execute($transferParams);
            $transferredProducts = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine and deduplicate products, summing quantities for same product
            $allProducts = array_merge($regularProducts, $transferredProducts);
            $uniqueProducts = [];
            $seenProducts = [];
            
            foreach ($allProducts as $product) {
                $key = $product['product_name'] . '|' . $product['category'] . '|' . $product['barcode'];
                
                if (!isset($seenProducts[$key])) {
                    $seenProducts[$key] = count($uniqueProducts);
                    $uniqueProducts[] = $product;
                } else {
                    // If duplicate found, SUM only REGULAR quantities (ignore transferred)
                    $existingIndex = $seenProducts[$key];
                    
                    // Only sum quantities if both are Regular products
                    if ($product['product_type'] === 'Regular' && $uniqueProducts[$existingIndex]['product_type'] === 'Regular') {
                        $uniqueProducts[$existingIndex]['quantity'] += $product['quantity'];
                    } else if ($product['product_type'] === 'Regular' && $uniqueProducts[$existingIndex]['product_type'] !== 'Regular') {
                        // Replace transferred with regular quantity
                        $uniqueProducts[$existingIndex]['quantity'] = $product['quantity'];
                        $uniqueProducts[$existingIndex]['product_type'] = 'Regular';
                    }
                    // If both are transferred or current is transferred, keep existing
                    
                    // Update stock status based on new total quantity
                    $totalQty = $uniqueProducts[$existingIndex]['quantity'];
                    $uniqueProducts[$existingIndex]['stock_status'] = 
                        $totalQty <= 0 ? 'out of stock' : 
                        ($totalQty <= 10 ? 'low stock' : 'in stock');
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => $uniqueProducts,
                "summary" => [
                    "total_products" => count($uniqueProducts),
                    "regular_products" => count($regularProducts),
                    "transferred_products" => count($transferredProducts)
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    */
    case 'get_quantity_history':
        try {
            $product_id = $data['product_id'] ?? 0;
            
            if ($product_id <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID"
                ]);
                break;
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    sm.movement_id,
                    sm.movement_type,
                    sm.quantity as quantity_change,
                    sm.remaining_quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    sm.movement_date,
                    sm.reference_no,
                    sm.notes,
                    sm.created_by,
                    b.batch_reference,
                    b.entry_date as batch_date,
                    -- Get expiration date from tbl_fifo_stock (priority 1)
                    COALESCE(fs.expiration_date, sm.expiration_date) as expiration_date,
                    -- Get expiration date from tbl_product as fallback
                    p.expiration as product_expiration
                FROM tbl_stock_movements sm
                LEFT JOIN tbl_batch b ON sm.batch_id = b.batch_id
                LEFT JOIN tbl_fifo_stock fs ON sm.product_id = fs.product_id AND sm.batch_id = fs.batch_id
                LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                WHERE sm.product_id = ? 
                AND sm.movement_type != 'OUT'
                ORDER BY sm.movement_date DESC
                LIMIT 20
            ");
            $stmt->execute([$product_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $history
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_movement_history':
        try {
            $search = $data['search'] ?? '';
            $movement_type = $data['movement_type'] ?? 'all';
            $location = $data['location'] ?? 'all';
            $date_range = $data['date_range'] ?? 'all';
            
            // Build WHERE clause for filtering
            $whereConditions = [];
            $params = [];
            
            if ($search) {
                $whereConditions[] = "(p.product_name LIKE ? OR p.barcode LIKE ? OR e.Fname LIKE ? OR e.Lname LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if ($location !== 'all') {
                $whereConditions[] = "(sl.location_name = ? OR dl.location_name = ?)";
                $params[] = $location;
                $params[] = $location;
            }
            
            if ($date_range !== 'all') {
                switch ($date_range) {
                    case 'today':
                        $whereConditions[] = "DATE(th.date) = CURDATE()";
                        break;
                    case 'week':
                        $whereConditions[] = "th.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                        break;
                    case 'month':
                        $whereConditions[] = "th.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                        break;
                }
            }
            
            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
            
            $stmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id as id,
                    p.product_name,
                    p.barcode as productId,
                    'Transfer' as movementType,
                    td.qty as quantity,
                    sl.location_name as fromLocation,
                    dl.location_name as toLocation,
                    CONCAT(e.Fname, ' ', e.Lname) as movedBy,
                    th.date,
                    TIME(th.date) as time,
                    CASE 
                        WHEN th.status = '' OR th.status IS NULL THEN 'Completed'
                        WHEN th.status = 'pending' THEN 'Pending'
                        WHEN th.status = 'approved' THEN 'Completed'
                        WHEN th.status = 'rejected' THEN 'Cancelled'
                        ELSE th.status
                    END as status,
                    NULL as notes,
                    CONCAT('TR-', th.transfer_header_id) as reference,
                    c.category_name,
                    p.description,
                    b.brand
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                $whereClause
                ORDER BY th.date DESC, th.transfer_header_id DESC
            ");
            $stmt->execute($params);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $movements
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    case 'get_fifo_stock':
        try {
            $product_id = isset($data['product_id']) ? intval($data['product_id']) : 0;
            
            if ($product_id <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID"
                ]);
                break;
            }
            
            // Query to get FIFO stock data for the product with batch dates
            $stmt = $conn->prepare("
                SELECT 
                    fs.fifo_id as summary_id,
                    fs.batch_id,
                    fs.batch_id as batch_number,
                    fs.batch_reference,
                    fs.available_quantity,
                    fs.srp,
                    fs.srp as fifo_srp,
                    COALESCE(fs.srp, 0) AS srp,
                    fs.expiration_date,
                    fs.quantity as total_quantity,
                    fs.entry_date as fifo_entry_date,
                    b.entry_date as batch_date,
                    b.entry_time as batch_time,
                    ROW_NUMBER() OVER (ORDER BY fs.expiration_date ASC, b.entry_date ASC, fs.fifo_id ASC) as fifo_order,
                    CASE 
                        WHEN fs.expiration_date IS NULL THEN NULL
                        ELSE DATEDIFF(fs.expiration_date, CURDATE())
                    END as days_until_expiry
                FROM tbl_fifo_stock fs
                JOIN tbl_batch b ON fs.batch_id = b.batch_id
                JOIN tbl_product p ON fs.product_id = p.product_id
                WHERE fs.product_id = ? AND fs.available_quantity > 0
                ORDER BY 
                    CASE 
                        WHEN fs.expiration_date IS NULL THEN 1 
                        ELSE 0 
                    END,
                    fs.expiration_date ASC, 
                    b.entry_date ASC, 
                    fs.fifo_id ASC
            ");
            
            $stmt->execute([$product_id]);
            $fifoData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $fifoData
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_all_batches':
        try {
            $location_id = $data['location_id'] ?? 2;
            
            // Query to get all FIFO stock data across all products with batch dates
            $stmt = $conn->prepare("
                SELECT 
                    fs.fifo_id as summary_id,
                    fs.product_id,
                    p.product_name,
                    p.barcode,
                    fs.batch_id,
                    fs.batch_id as batch_number,
                    fs.batch_reference,
                    fs.available_quantity,
                    fs.srp,
                    fs.srp as fifo_srp,
                    COALESCE(fs.srp, 0) AS srp,
                    fs.expiration_date,
                    fs.quantity as total_quantity,
                    fs.entry_date as fifo_entry_date,
                    b.entry_date as batch_date,
                    b.entry_time as batch_time,
                    b.entry_by,
                    CASE 
                        WHEN fs.expiration_date IS NULL THEN NULL
                        ELSE DATEDIFF(fs.expiration_date, CURDATE())
                    END as days_until_expiry,
                    c.category_name,
                    s.supplier_name
                FROM tbl_fifo_stock fs
                JOIN tbl_batch b ON fs.batch_id = b.batch_id
                JOIN tbl_product p ON fs.product_id = p.product_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_supplier s ON b.supplier_id = s.supplier_id
                WHERE fs.available_quantity > 0
                AND p.location_id = ?
                ORDER BY 
                    p.product_name ASC,
                    CASE 
                        WHEN fs.expiration_date IS NULL THEN 1 
                        ELSE 0 
                    END,
                    fs.expiration_date ASC, 
                    b.entry_date ASC, 
                    fs.fifo_id ASC
            ");
            
            $stmt->execute([$location_id]);
            $allBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $allBatches
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'consume_stock_fifo':
        try {
            $product_id = $data['product_id'] ?? 0;
            $quantity = $data['quantity'] ?? 0;
            $reference_no = $data['reference_no'] ?? '';
            $notes = $data['notes'] ?? '';
            $created_by = $data['created_by'] ?? 'admin';
            
            if ($product_id <= 0 || $quantity <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID or quantity"
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get FIFO stock data for the product
            $fifoStmt = $conn->prepare("
                SELECT 
                    fs.batch_id,
                    fs.batch_reference,
                    fs.available_quantity,
                    fs.srp
                FROM tbl_fifo_stock fs
                JOIN tbl_batch b ON fs.batch_id = b.batch_id
                WHERE fs.product_id = ? AND fs.available_quantity > 0
                ORDER BY 
                    CASE 
                        WHEN fs.expiration_date IS NULL THEN 1 
                        ELSE 0 
                    END,
                    fs.expiration_date ASC, 
                    b.entry_date ASC, 
                    fs.fifo_id ASC
            ");
            $fifoStmt->execute([$product_id]);
            $fifoStock = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($fifoStock)) {
                throw new Exception("No FIFO stock available for this product");
            }
            
            $remaining_quantity = $quantity;
            $consumed_batches = [];
            
            // Consume stock from FIFO order
            foreach ($fifoStock as $batch) {
                if ($remaining_quantity <= 0) break;
                
                $batch_quantity = min($remaining_quantity, $batch['available_quantity']);
                
                // Update FIFO stock
                $updateStmt = $conn->prepare("
                    UPDATE tbl_fifo_stock 
                    SET available_quantity = available_quantity - ?
                    WHERE batch_id = ? AND product_id = ?
                ");
                $updateStmt->execute([$batch_quantity, $batch['batch_id'], $product_id]);
                
                // Update main product quantity
                $productStmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET quantity = quantity - ?,
                        stock_status = CASE 
                            WHEN quantity - ? <= 0 THEN 'out of stock'
                            WHEN quantity - ? <= 10 THEN 'low stock'
                            ELSE 'in stock'
                        END
                    WHERE product_id = ?
                ");
                $productStmt->execute([$batch_quantity, $batch_quantity, $batch_quantity, $product_id]);
                
                $consumed_batches[] = [
                    'batch_reference' => $batch['batch_reference'],
                    'quantity' => $batch_quantity,
                    'srp' => $batch['srp']
                ];
                
                $remaining_quantity -= $batch_quantity;
            }
            
            if ($remaining_quantity > 0) {
                throw new Exception("Insufficient stock available. Only " . ($quantity - $remaining_quantity) . " units consumed.");
            }
            
            // Log the consumption
            $logStmt = $conn->prepare("
                INSERT INTO tbl_stock_consumption (
                    product_id, quantity, reference_no, notes, created_by, consumed_date
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([$product_id, $quantity, $reference_no, $notes, $created_by]);
            
            $conn->commit();
            echo json_encode([
                "success" => true,
                "message" => "Stock consumed successfully using FIFO method",
                "consumed_batches" => $consumed_batches
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'transfer_fifo_consumption':
        try {
            $product_id = $data['product_id'] ?? 0;
            $quantity = $data['quantity'] ?? 0;
            $transfer_id = $data['transfer_id'] ?? '';
            $source_location_id = $data['source_location_id'] ?? 0;
            
            if ($product_id <= 0 || $quantity <= 0 || $source_location_id <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid product ID, quantity, or source location"
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get FIFO stock data for the product in source location
            $fifoStmt = $conn->prepare("
                SELECT 
                    fs.fifo_id,
                    fs.batch_id,
                    fs.batch_reference,
                    fs.available_quantity,
                    fs.srp
                FROM tbl_fifo_stock fs
                JOIN tbl_batch b ON fs.batch_id = b.batch_id
                WHERE fs.product_id = ? AND fs.available_quantity > 0
                ORDER BY 
                    CASE 
                        WHEN fs.expiration_date IS NULL THEN 1 
                        ELSE 0 
                    END,
                    fs.expiration_date ASC, 
                    b.entry_date ASC, 
                    fs.fifo_id ASC
            ");
            $fifoStmt->execute([$product_id]);
            $fifoStock = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($fifoStock)) {
                throw new Exception("No FIFO stock available for product ID: $product_id in source location");
            }
            
            $remaining_quantity = $quantity;
            $consumed_batches = [];
            
            // Consume stock from FIFO order (oldest first)
            foreach ($fifoStock as $batch) {
                if ($remaining_quantity <= 0) break;
                
                $batch_quantity = min($remaining_quantity, $batch['available_quantity']);
                
                // Update FIFO stock
                $updateFifoStmt = $conn->prepare("
                    UPDATE tbl_fifo_stock 
                    SET available_quantity = available_quantity - ?
                    WHERE fifo_id = ?
                ");
                $updateFifoStmt->execute([$batch_quantity, $batch['fifo_id']]);
                
                // Check if this batch is now empty
                $checkBatchStmt = $conn->prepare("
                    SELECT available_quantity FROM tbl_fifo_stock WHERE fifo_id = ?
                ");
                $checkBatchStmt->execute([$batch['fifo_id']]);
                $currentBatchQty = $checkBatchStmt->fetch(PDO::FETCH_ASSOC);
                
                // If batch is empty, mark it as consumed (don't delete to maintain history)
                if ($currentBatchQty && $currentBatchQty['available_quantity'] <= 0) {
                    $markConsumedStmt = $conn->prepare("
                        UPDATE tbl_fifo_stock 
                        SET status = 'consumed', consumed_date = NOW()
                        WHERE fifo_id = ?
                    ");
                    $markConsumedStmt->execute([$batch['fifo_id']]);
                    error_log("Marked FIFO batch as consumed during transfer - FIFO ID: " . $batch['fifo_id'] . ", Batch: " . $batch['batch_reference']);
                }
                
                $consumed_batches[] = [
                    'batch_reference' => $batch['batch_reference'],
                    'quantity' => $batch_quantity,
                    'srp' => $batch['srp']
                ];
                
                $remaining_quantity -= $batch_quantity;
                error_log("Consumed from batch " . $batch['batch_reference'] . ": $batch_quantity units for transfer $transfer_id");
            }
            
            if ($remaining_quantity > 0) {
                throw new Exception("Insufficient stock available for transfer. Only " . ($quantity - $remaining_quantity) . " units available in FIFO stock.");
            }
            
            // Log the transfer consumption
            $logStmt = $conn->prepare("
                INSERT INTO tbl_stock_consumption (
                    product_id, quantity, reference_no, notes, created_by, consumed_date, consumption_type
                ) VALUES (?, ?, ?, ?, 'system', NOW(), 'transfer')
            ");
            $logStmt->execute([$product_id, $quantity, $transfer_id, "Transfer consumption from location $source_location_id"]);
            
            $conn->commit();
            echo json_encode([
                "success" => true,
                "message" => "Transfer FIFO consumption completed successfully",
                "consumed_batches" => $consumed_batches,
                "total_consumed" => $quantity
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_expiring_products':
        try {
            $days_threshold = $data['days_threshold'] ?? 30;
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    b.brand,
                    s.supplier_name,
                    p.expiration,
                    DATEDIFF(p.expiration, CURDATE()) as days_until_expiry
                FROM tbl_product p
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                WHERE p.expiration IS NOT NULL 
                AND p.expiration >= CURDATE()
                AND DATEDIFF(p.expiration, CURDATE()) <= ?
                AND (p.status IS NULL OR p.status <> 'archived')
                ORDER BY p.expiration ASC
            ");
            
            $stmt->execute([$days_threshold]);
            $expiringProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $expiringProducts
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    // Inventory Dashboard Actions
    case 'get_inventory_kpis':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            // Get main KPIs
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as physicalAvailable,
                    0 as softReserved,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as onhandInventory,
                    0 as newOrderLineQty,
                    0 as returned,
                    0 as returnRate,
                    100 as sellRate,
                    0 as outOfStock
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
            ");
            $stmt->execute($params);
            $kpis = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode($kpis);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_supply_by_product':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as onhand,
                    0 as softReserved,
                    0 as returned
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY p.product_name
                ORDER BY onhand DESC
                LIMIT 11
            ");
            $stmt->execute($params);
            $supplyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($supplyData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_supply_by_location':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    l.location_name as location,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as onhand,
                    0 as softReserved,
                    0 as returned
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY l.location_name
                ORDER BY onhand DESC
            ");
            $stmt->execute($params);
            $supplyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($supplyData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_return_rate_by_product':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    ROUND(COUNT(CASE WHEN p.stock_status = 'out of stock' THEN 1 END) * 100.0 / COUNT(*), 1) as returnRate
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY p.product_name
                HAVING returnRate > 0
                ORDER BY returnRate DESC
                LIMIT 12
            ");
            $stmt->execute($params);
            $returnData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($returnData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    case 'get_stockout_items':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    -COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as stockout
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                AND p.stock_status = 'out of stock'
                ORDER BY stockout ASC
                LIMIT 15
            ");
            $stmt->execute($params);
            $stockoutData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($stockoutData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_product_kpis':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as physicalAvailable,
                    0 as softReserved,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as onhandInventory,
                    0 as newOrderLineQty,
                    0 as returned,
                    0 as returnRate,
                    100 as sellRate,
                    0 as outOfStock
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY p.product_name
                ORDER BY physicalAvailable DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $productKPIs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($productKPIs);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    // Warehouse-specific API endpoints
    case 'get_warehouse_kpis':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            // Build WHERE conditions
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($location_filter && $location_filter !== 'Warehouse') {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            } else if ($location_filter === 'Warehouse') {
                // Only filter by warehouse if specifically requested
                $whereConditions[] = "p.location_id = 2";
            }
            // If no location filter or 'All' is selected, don't filter by location
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            // Get warehouse-specific KPIs using PDO
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT p.product_id) as totalProducts,
                    COUNT(DISTINCT s.supplier_id) as totalSuppliers,
                    ROUND(COUNT(DISTINCT p.product_id) * 100.0 / 1000, 1) as storageCapacity,
                    COALESCE((SELECT SUM(fs.available_quantity * fs.srp) FROM tbl_fifo_stock fs), 0) as warehouseValue,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs), 0) as totalQuantity,
                    COALESCE((SELECT COUNT(DISTINCT fs.product_id) FROM tbl_fifo_stock fs WHERE fs.available_quantity <= 10 AND fs.available_quantity > 0), 0) as lowStockItems,
                    COUNT(CASE WHEN p.expiration IS NOT NULL AND p.expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiringSoon,
                    COUNT(DISTINCT b.batch_id) as totalBatches,
                    COUNT(CASE WHEN t.status = 'pending' THEN 1 END) as activeTransfers
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
                LEFT JOIN tbl_transfer_dtl td ON p.product_id = td.product_id
                LEFT JOIN tbl_transfer_header t ON td.transfer_header_id = t.transfer_header_id
                $whereClause
            ");
            $stmt->execute($params);
            $warehouseKPIs = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $warehouseKPIs
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_warehouse_supply_by_product':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            // Build WHERE conditions
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($location_filter && $location_filter !== 'Warehouse') {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            } else {
                // Default to warehouse products only
                $whereConditions[] = "p.location_id = 2";
            }
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as onhand,
                    0 as softReserved,
                    0 as returned
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY p.product_name
                ORDER BY onhand DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $supplyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($supplyData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_warehouse_supply_by_location':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            // Always filter for warehouse products (location_id = 2) unless specific location is requested
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($location_filter && $location_filter !== 'Warehouse') {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            } else {
                // Default to warehouse products only
                $whereConditions[] = "p.location_id = 2";
            }
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    l.location_name as location,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as onhand,
                    0 as softReserved,
                    0 as returned
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY l.location_name
                ORDER BY onhand DESC
                LIMIT 8
            ");
            $stmt->execute($params);
            $supplyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($supplyData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_warehouse_stockout_items':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            // Always filter for warehouse products (location_id = 2) unless specific location is requested
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($location_filter && $location_filter !== 'Warehouse') {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            } else {
                // Default to warehouse products only
                $whereConditions[] = "p.location_id = 2";
            }
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    -COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as stockout
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                AND p.stock_status = 'out of stock'
                ORDER BY stockout ASC
                LIMIT 12
            ");
            $stmt->execute($params);
            $stockoutData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($stockoutData);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    case 'get_warehouse_product_kpis':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            // Always filter for warehouse products (location_id = 2) unless specific location is requested
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($location_filter && $location_filter !== 'Warehouse') {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            } else {
                // Default to warehouse products only
                $whereConditions[] = "p.location_id = 2";
            }
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    s.supplier_name as supplier,
                    b.batch as batch,
                    p.status,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as onhandInventory
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
                $whereClause
                ORDER BY quantity DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $productKPIs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($productKPIs);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    // Chart-specific API endpoints
    case 'get_top_products_by_quantity':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    c.category_name as category,
                    l.location_name as location
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                HAVING quantity > 0
                ORDER BY quantity DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $topProducts,
                "count" => count($topProducts)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_stock_distribution_by_category':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    c.category_name as category,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                AND c.category_name IS NOT NULL
                GROUP BY c.category_name
                HAVING quantity > 0
                ORDER BY quantity DESC
                LIMIT 8
            ");
            $stmt->execute($params);
            $categoryDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $categoryDistribution,
                "count" => count($categoryDistribution)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_fast_moving_items_trend':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            $time_period = isset($data['timePeriod']) ? $data['timePeriod'] : 'monthly';
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            // Get the last 6 months of data
            $months = [];
            $trendData = [];
            
            // Determine date range based on time period
            if ($time_period === 'weekly') {
                // Last 6 weeks
                for ($i = 5; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i weeks"));
                    $weeks[] = date('M d', strtotime("-$i weeks"));
                }
                $dateFormat = 'Y-m-d';
                $groupBy = "DATE(created_date)";
            } else {
                // Last 6 months (default)
                for ($i = 5; $i >= 0; $i--) {
                    $date = date('Y-m', strtotime("-$i months"));
                    $months[] = date('M', strtotime("-$i months"));
                }
                $dateFormat = 'Y-m';
                $groupBy = "DATE_FORMAT(created_date, '%Y-%m')";
            }
            
            // Get fast-moving items based on stock movements (OUT transactions)
            // Products with most frequent stock movements in the last 6 months
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COUNT(sm.movement_id) as movement_count,
                    SUM(CASE WHEN sm.movement_type = 'OUT' THEN sm.quantity ELSE 0 END) as total_moved_out,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as current_stock
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_stock_movements sm ON p.product_id = sm.product_id 
                    AND sm.movement_type = 'OUT' 
                    AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                $whereClause
                GROUP BY p.product_id, p.product_name
                HAVING movement_count > 0
                ORDER BY movement_count DESC, total_moved_out DESC
                LIMIT 5
            ");
            $stmt->execute($params);
            $fastMovingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no movement data, get top products by current stock levels
            if (empty($fastMovingProducts)) {
                $stmt = $conn->prepare("
                    SELECT 
                        p.product_name as product,
                        0 as movement_count,
                        0 as total_moved_out,
                        COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as current_stock
                    FROM tbl_product p
                    LEFT JOIN tbl_category c ON p.category_id = c.category_id
                    LEFT JOIN tbl_location l ON p.location_id = l.location_id
                    $whereClause
                    ORDER BY current_stock DESC
                    LIMIT 5
                ");
                $stmt->execute($params);
                $fastMovingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Generate trend data for each fast-moving product
            foreach ($fastMovingProducts as $product) {
                if ($time_period === 'weekly') {
                    foreach ($weeks as $week) {
                        // Get actual movement data for this week if available
                        $weekStmt = $conn->prepare("
                            SELECT COALESCE(SUM(sm.quantity), 0) as weekly_movement
                            FROM tbl_stock_movements sm
                            WHERE sm.product_id = (SELECT product_id FROM tbl_product WHERE product_name = ? LIMIT 1)
                            AND sm.movement_type = 'OUT'
                            AND DATE(sm.movement_date) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                            AND DATE(sm.movement_date) < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                        ");
                        $weekIndex = array_search($week, $weeks);
                        $startDays = ($weekIndex + 1) * 7;
                        $endDays = $weekIndex * 7;
                        $weekStmt->execute([$product['product'], $startDays, $endDays]);
                        $weekData = $weekStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $weeklyMovement = $weekData['weekly_movement'] ?: 0;
                        
                        // Only add to trend data if there's actual movement (no zero values)
                        if ($weeklyMovement > 0) {
                            $trendData[] = [
                                'product' => $product['product'],
                                'month' => $week,
                                'quantity' => $weeklyMovement
                            ];
                        }
                    }
                } else {
                    foreach ($months as $month) {
                        // Get actual movement data for this month if available
                        $monthStmt = $conn->prepare("
                            SELECT COALESCE(SUM(sm.quantity), 0) as monthly_movement
                            FROM tbl_stock_movements sm
                            WHERE sm.product_id = (SELECT product_id FROM tbl_product WHERE product_name = ? LIMIT 1)
                            AND sm.movement_type = 'OUT'
                            AND DATE_FORMAT(sm.movement_date, '%Y-%m') = ?
                        ");
                        $monthDate = date('Y-m', strtotime($month . ' ' . date('Y')));
                        $monthStmt->execute([$product['product'], $monthDate]);
                        $monthData = $monthStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $monthlyMovement = $monthData['monthly_movement'] ?: 0;
                        
                        // Only add to trend data if there's actual movement (no zero values)
                        if ($monthlyMovement > 0) {
                            $trendData[] = [
                                'product' => $product['product'],
                                'month' => $month,
                                'quantity' => $monthlyMovement
                            ];
                        }
                    }
                }
            }
            
            // If still no data, generate sample data for demonstration
            if (empty($trendData)) {
                $sampleProducts = ['Sample Product 1', 'Sample Product 2', 'Sample Product 3'];
                $timeLabels = $time_period === 'weekly' ? $weeks : $months;
                
                foreach ($sampleProducts as $product) {
                    foreach ($timeLabels as $timeLabel) {
                        $trendData[] = [
                            'product' => $product,
                            'month' => $timeLabel,
                            'quantity' => rand(15, 45)
                        ];
                    }
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => $trendData,
                "metadata" => [
                    "time_period" => $time_period,
                    "products_analyzed" => count($fastMovingProducts),
                    "data_source" => "stock_movements"
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_critical_stock_alerts':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name as product,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    c.category_name as category,
                    l.location_name as location,
                    CASE 
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) = 0 THEN 'Out of Stock'
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 5 THEN 'Critical'
                        ELSE 'Low Stock'
                    END as alert_level
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                AND COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 10
                ORDER BY quantity ASC, p.product_name ASC
                LIMIT 10
            ");
            $stmt->execute($params);
            $criticalAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $criticalAlerts,
                "count" => count($criticalAlerts)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_inventory_by_branch_category':
        try {
            $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
            $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;
            
            $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
            $params = [];
            
            if ($product_filter) {
                $whereConditions[] = "c.category_name = ?";
                $params[] = $product_filter;
            }
            
            if ($location_filter) {
                $whereConditions[] = "l.location_name = ?";
                $params[] = $location_filter;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT 
                    l.location_name as location,
                    c.category_name,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                GROUP BY l.location_name, c.category_name
                ORDER BY l.location_name, quantity DESC
                LIMIT 20
            ");
            $stmt->execute($params);
            $branchCategoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $branchCategoryData
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_products_by_location_name':
        try {
            $location_name = $data['location_name'] ?? '';
            
            if (empty($location_name)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Location name is required"
                ]);
                break;
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    p.description,
                    p.prescription,
                    p.bulk,
                    p.expiration,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.batch_id,
                    p.status,
                    p.stock_status,
                    p.date_added,
                    s.supplier_name,
                    b.brand,
                    l.location_name,
                    batch.batch as batch_reference,
                    batch.entry_date,
                    batch.entry_by,
                    COALESCE(p.date_added, CURDATE()) as date_added
                FROM tbl_product p 
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id 
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id 
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_batch batch ON p.batch_id = batch.batch_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND l.location_name = ?
                GROUP BY p.product_id, p.product_name, p.barcode, c.category_name, p.description, p.prescription, p.bulk, p.expiration, p.brand_id, p.supplier_id, p.location_id, p.batch_id, p.status, p.stock_status, p.date_added, s.supplier_name, b.brand, l.location_name, batch.batch, batch.entry_date, batch.entry_by
                ORDER BY p.product_name ASC
            ");
            $stmt->execute([$location_name]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $products
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;
    case 'get_location_products':
        try {
            $location_id = $data['location_id'] ?? 0;
            $search = $data['search'] ?? '';
            $category = $data['category'] ?? 'all';
            
            if (!$location_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Location ID is required"
                ]);
                break;
            }
            
            // Build the WHERE clause for regular products
            $where_conditions = ["p.location_id = ?"];
            $params = [$location_id];
            
            if ($search) {
                $where_conditions[] = "(p.product_name LIKE ? OR p.barcode LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            if ($category && $category !== 'all') {
                $where_conditions[] = "c.category_name = ?";
                $params[] = $category;
            }
            
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
            
            // Get regular products for specific location - GROUP BY product name to consolidate duplicates
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    p.description,
                    p.prescription,
                    p.bulk,
                    p.expiration,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.batch_id,
                    p.status,
                    p.stock_status,
                    p.date_added,
                    b.brand,
                    s.supplier_name,
                    l.location_name,
                    COALESCE(p.batch_id, 0) as batch_id,
                    COALESCE(batch.batch_reference, 'N/A') as batch_reference,
                    COALESCE(batch.entry_date, 'N/A') as batch_date_time,
                    CASE 
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 0 THEN 'out of stock'
                        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END as stock_status,
                    'Regular' as product_type
                FROM tbl_product p
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_batch batch ON p.batch_id = batch.batch_id
                $where_clause
                AND p.status = 'active'
                GROUP BY p.product_id, p.product_name, p.barcode, c.category_name, p.description, p.prescription, p.bulk, p.expiration, p.brand_id, p.supplier_id, p.location_id, p.batch_id, p.status, p.stock_status, p.date_added, b.brand, s.supplier_name, l.location_name, batch.batch_reference, batch.entry_date
                ORDER BY p.product_name ASC
            ");
            
            $stmt->execute($params);
            $regularProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get transferred products to this location
            $transferWhereConditions = ["th.destination_location_id = ?"];
            $transferParams = [$location_id];
            
            if ($search) {
                $transferWhereConditions[] = "(p.product_name LIKE ? OR p.barcode LIKE ?)";
                $transferParams[] = "%$search%";
                $transferParams[] = "%$search%";
            }
            
            if ($category && $category !== 'all') {
                $transferWhereConditions[] = "c.category_name = ?";
                $transferParams[] = $category;
            }
            
            // Filter by product type if specified
            if (isset($data['product_type']) && $data['product_type'] !== 'all') {
                if ($data['product_type'] === 'Transferred') {
                    // Only show transferred products
                    $regularProducts = [];
                } elseif ($data['product_type'] === 'Regular') {
                    // Only show regular products
                    $transferWhereConditions[] = "1 = 0"; // This will make no transferred products match
                }
            }
            
            $transferWhereClause = "WHERE " . implode(" AND ", $transferWhereConditions);
            
            $transferStmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name,
                    p.barcode,
                    p.description,
                    p.prescription,
                    p.bulk,
                    p.expiration,
                    SUM(td.qty) as quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.batch_id,
                    p.status,
                    p.stock_status,
                    p.date_added,
                    b.brand,
                    s.supplier_name,
                    l.location_name,
                    COALESCE(batch.batch_reference, 'N/A') as batch_reference,
                    COALESCE(batch.entry_date, 'N/A') as batch_date_time,
                    CASE 
                        WHEN SUM(td.qty) <= 0 THEN 'out of stock'
                        WHEN SUM(td.qty) <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END as stock_status,
                    'Transferred' as product_type,
                    GROUP_CONCAT(DISTINCT th.transfer_header_id) as transfer_header_ids,
                    MAX(th.date) as transfer_date,
                    GROUP_CONCAT(DISTINCT sl.location_name) as source_locations,
                    GROUP_CONCAT(DISTINCT CONCAT(e.Fname, ' ', e.Lname)) as transferred_by
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_batch batch ON p.batch_id = batch.batch_id
                LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                $transferWhereClause
                AND th.status = 'approved'
                GROUP BY p.product_id, p.product_name, c.category_name, p.barcode, p.description, p.prescription, p.bulk, p.expiration, p.brand_id, p.supplier_id, p.location_id, p.batch_id, p.status, p.stock_status, p.date_added, b.brand, s.supplier_name, l.location_name, batch.batch_reference, batch.entry_date
                ORDER BY p.product_name ASC
            ");
            
            $transferStmt->execute($transferParams);
            $transferredProducts = $transferStmt->fetchAll(PDO::FETCH_ASSOC);

            // Since we're already grouping in the query, we can use the results directly
            // Combine both regular and transferred products
            $allProducts = array_merge($regularProducts, $transferredProducts);

            // Deduplicate by summing quantities if the same product exists in both Regular and Transferred
            $uniqueProducts = [];
            $seenIndexByKey = [];
            foreach ($allProducts as $product) {
                $key = $product['product_name'] . '|' . $product['category'] . '|' . $product['barcode'];
                if (!isset($seenIndexByKey[$key])) {
                    $seenIndexByKey[$key] = count($uniqueProducts);
                    $uniqueProducts[] = $product;
                } else {
                    $idx = $seenIndexByKey[$key];
                    $uniqueProducts[$idx]['quantity'] += (int)$product['quantity'];
                    $totalQty = (int)$uniqueProducts[$idx]['quantity'];
                    $uniqueProducts[$idx]['stock_status'] = $totalQty <= 0 ? 'out of stock' : ($totalQty <= 10 ? 'low stock' : 'in stock');
                    // Prefer labeling as Transferred if any side is transferred
                    if (($product['product_type'] ?? '') === 'Transferred') {
                        $uniqueProducts[$idx]['product_type'] = 'Transferred';
                    }
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => $uniqueProducts,
                "summary" => [
                    "total_products" => count($uniqueProducts),
                    "regular_products" => count($regularProducts),
                    "transferred_products" => count($transferredProducts)
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error getting location products: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_archived_products':
        try {
            $stmt = $conn->prepare("SELECT * FROM tbl_product WHERE status = 'inactive'");
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                "success" => true,
                "data" => $products
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_reports_data':
        try {
            // Get inventory analytics data
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT p.product_id) as totalProducts,
                    (SELECT COUNT(DISTINCT p2.product_id) FROM tbl_product p2 WHERE (p2.status IS NULL OR p2.status <> 'archived') AND (SELECT COALESCE(SUM(fs.available_quantity), 0) FROM tbl_fifo_stock fs WHERE fs.product_id = p2.product_id) BETWEEN 1 AND 10) as lowStockItems,
                    (SELECT COUNT(DISTINCT p2.product_id) FROM tbl_product p2 WHERE (p2.status IS NULL OR p2.status <> 'archived') AND p2.product_id NOT IN (SELECT DISTINCT product_id FROM tbl_fifo_stock WHERE available_quantity > 0)) as outOfStockItems,
                    (SELECT COALESCE(SUM(fs.available_quantity * fs.srp), 0) FROM tbl_fifo_stock fs JOIN tbl_product p2 ON fs.product_id = p2.product_id WHERE (p2.status IS NULL OR p2.status <> 'archived')) as totalValue
                FROM tbl_product p
                WHERE (p.status IS NULL OR p.status <> 'archived')
            ");
            $stmt->execute();
            $analytics = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get top categories distribution
            $stmt = $conn->prepare("
                SELECT 
                    c.category_name,
                    COUNT(p.product_id) as product_count,
                    ROUND(COUNT(p.product_id) * 100.0 / (SELECT COUNT(*) FROM tbl_product WHERE (status IS NULL OR status <> 'archived')), 1) as percentage
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND c.category_name IS NOT NULL
                GROUP BY c.category_name
                ORDER BY product_count DESC
                LIMIT 5
            ");
            $stmt->execute();
            $topCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get recent stock movements for reports
            $stmt = $conn->prepare("
                SELECT 
                    sm.movement_id,
                    p.product_name as title,
                    CASE 
                        WHEN sm.movement_type = 'IN' THEN 'Stock In Report'
                        WHEN sm.movement_type = 'OUT' THEN 'Stock Out Report'
                        ELSE 'Stock Adjustment Report'
                    END as type,
                    sm.created_by as generatedBy,
                    DATE(sm.movement_date) as date,
                    TIME(sm.movement_date) as time,
                    'Completed' as status,
                    CONCAT(ROUND(RAND() * 5 + 0.5, 1), ' MB') as fileSize,
                    CASE WHEN RAND() > 0.5 THEN 'PDF' ELSE 'Excel' END as format,
                    CONCAT(
                        CASE 
                            WHEN sm.movement_type = 'IN' THEN 'Stock received'
                            WHEN sm.movement_type = 'OUT' THEN 'Stock consumed'
                            ELSE 'Stock adjusted'
                        END,
                        ' - ', p.product_name, ' (', sm.quantity, ' units)'
                    ) as description
                FROM tbl_stock_movements sm
                JOIN tbl_product p ON sm.product_id = p.product_id
                ORDER BY sm.movement_date DESC
                LIMIT 20
            ");
            $stmt->execute();
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get transfer reports
            $stmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id as movement_id,
                    CONCAT('Transfer Report #', th.transfer_header_id) as title,
                    'Transfer Report' as type,
                    'System' as generatedBy,
                    th.date,
                    '12:00 PM' as time,
                    CASE 
                        WHEN th.status = 'approved' THEN 'Completed'
                        WHEN th.status = 'pending' THEN 'In Progress'
                        ELSE 'Failed'
                    END as status,
                    CONCAT(ROUND(RAND() * 3 + 0.5, 1), ' MB') as fileSize,
                    'PDF' as format,
                    CONCAT(
                        'Transfer from ', 
                        (SELECT location_name FROM tbl_location WHERE location_id = th.source_location_id),
                        ' to ',
                        (SELECT location_name FROM tbl_location WHERE location_id = th.destination_location_id),
                        ' - ', COUNT(td.product_id), ' products'
                    ) as description
                FROM tbl_transfer_header th
                LEFT JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                GROUP BY th.transfer_header_id
                ORDER BY th.date DESC
                LIMIT 10
            ");
            $stmt->execute();
            $transferReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Combine all reports
            $allReports = array_merge($reports, $transferReports);
            
            // Sort by date (newest first)
            usort($allReports, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });

            echo json_encode([
                "success" => true,
                "analytics" => $analytics,
                "topCategories" => $topCategories,
                "reports" => $allReports
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_inventory_summary_report':
        try {
            $location_id = $data['location_id'] ?? null;
            
            $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived')";
            $params = [];
            
            if ($location_id) {
                $whereClause .= " AND p.location_id = ?";
                $params[] = $location_id;
            }

            $stmt = $conn->prepare("
                SELECT 
                    p.product_name,
                    p.barcode,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    p.stock_status,
                    c.category_name,
                    b.brand,
                    l.location_name,
                    s.supplier_name,
                    p.expiration,
                    COALESCE((SELECT SUM(fs.available_quantity * fs.srp) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as total_value
                FROM tbl_product p
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                $whereClause
                ORDER BY p.product_name
            ");
            $stmt->execute($params);
            $inventoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $inventoryData
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_low_stock_report':
        try {
            $threshold = $data['threshold'] ?? 10;
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name,
                    p.barcode,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    c.category_name,
                    b.brand,
                    l.location_name,
                    s.supplier_name,
                    s.supplier_contact,
                    s.supplier_email,
                    COALESCE((SELECT SUM(fs.available_quantity * fs.srp) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as total_value
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND (SELECT COALESCE(SUM(fs.available_quantity), 0) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id) BETWEEN 1 AND ?
                ORDER BY quantity ASC
            ");
            $stmt->execute([$threshold]);
            $lowStockData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $lowStockData
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_expiry_report':
        try {
            $days_threshold = $data['days_threshold'] ?? 30;
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name,
                    p.barcode,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
                    p.expiration,
                    DATEDIFF(p.expiration, CURDATE()) as days_until_expiry,
                    c.category_name,
                    b.brand,
                    l.location_name,
                    COALESCE((SELECT SUM(fs.available_quantity * fs.srp) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as total_value
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND p.expiration IS NOT NULL
                AND p.expiration <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND (SELECT COALESCE(SUM(fs.available_quantity), 0) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id) > 0
                ORDER BY p.expiration ASC
            ");
            $stmt->execute([$days_threshold]);
            $expiryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $expiryData
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    case 'get_movement_history_report':
        try {
            $start_date = $data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $data['end_date'] ?? date('Y-m-d');
            $movement_type = $data['movement_type'] ?? null;
            
            $whereConditions = ["sm.movement_date BETWEEN ? AND ?"];
            $params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
            
            if ($movement_type) {
                $whereConditions[] = "sm.movement_type = ?";
                $params[] = $movement_type;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);

            $stmt = $conn->prepare("
                SELECT 
                    sm.movement_id,
                    p.product_name,
                    p.barcode,
                    sm.movement_type,
                    sm.quantity,
                    sm.expiration_date,
                    sm.movement_date,
                    sm.reference_no,
                    sm.notes,
                    sm.created_by,
                    l.location_name,
                    (sm.quantity * COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0)) as total_cost
                FROM tbl_stock_movements sm
                JOIN tbl_product p ON sm.product_id = p.product_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                ORDER BY sm.movement_date DESC
            ");
            $stmt->execute($params);
            $movementData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "data" => $movementData
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    case 'duplicate_product_batches':
        try {
            $product_id = isset($data['product_id']) ? intval($data['product_id']) : 0;
            $batch_ids = isset($data['batch_ids']) ? $data['batch_ids'] : [22, 23]; // Default to your batch IDs
            
            if ($product_id <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Valid product ID is required"
                ]);
                break;
            }
            
            // Debug: Check what brands exist
            $debugBrandStmt = $conn->prepare("SELECT brand_id, brand FROM tbl_brand ORDER BY brand_id");
            $debugBrandStmt->execute();
            $existingBrands = $debugBrandStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Debug: Check what suppliers exist  
            $debugSupplierStmt = $conn->prepare("SELECT supplier_id, supplier_name FROM tbl_supplier ORDER BY supplier_id");
            $debugSupplierStmt->execute();
            $existingSuppliers = $debugSupplierStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get the original product details
            $productStmt = $conn->prepare("
                SELECT product_name, category_id, barcode, description, prescription, bulk,
                       srp, brand_id, supplier_id, status, stock_status
                FROM tbl_product 
                WHERE product_id = ?
                LIMIT 1
            ");
            $productStmt->execute([$product_id]);
            $productDetails = $productStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$productDetails) {
                $conn->rollback();
                echo json_encode([
                    "success" => false,
                    "message" => "Product not found"
                ]);
                break;
            }
            
            // Handle brand_id - allow NULL if no brands exist
            $brand_id = null;
            if (count($existingBrands) > 0) {
                $brand_id = $existingBrands[0]['brand_id'];
            }
            
            // Handle supplier_id - allow NULL if no suppliers exist
            $supplier_id = null;
            if (count($existingSuppliers) > 0) {
                $supplier_id = $existingSuppliers[0]['supplier_id'];
            }
            
            $duplicated_count = 0;
            
            // Create duplicate products for each batch
            foreach ($batch_ids as $batch_id) {
                // Check if product already exists with this batch_id
                $checkStmt = $conn->prepare("
                    SELECT product_id FROM tbl_product 
                    WHERE batch_id = ? AND barcode = ?
                ");
                $checkStmt->execute([$batch_id, $productDetails['barcode']]);
                
                if ($checkStmt->fetch()) {
                    continue; // Skip if already exists
                }
                
                // Get batch details
                $batchStmt = $conn->prepare("
                    SELECT batch, supplier_id, location_id, entry_date 
                    FROM tbl_batch 
                    WHERE batch_id = ?
                ");
                $batchStmt->execute([$batch_id]);
                $batchDetails = $batchStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$batchDetails) {
                    continue; // Skip if batch not found
                }
                
                // Get quantity from FIFO stock if available
                $fifoStmt = $conn->prepare("
                    SELECT quantity, available_quantity, srp, expiration_date
                    FROM tbl_fifo_stock 
                    WHERE product_id = ? AND batch_id = ?
                ");
                $fifoStmt->execute([$product_id, $batch_id]);
                $fifoStock = $fifoStmt->fetch(PDO::FETCH_ASSOC);
                
                // Use FIFO data if available, otherwise use defaults
                $quantity = $fifoStock ? $fifoStock['available_quantity'] : 100; // Default quantity
                $srp_value = $fifoStock ? $fifoStock['srp'] : $productDetails['srp'];
                $srp = $fifoStock ? $fifoStock['srp'] : $productDetails['srp'];
                $expiration = $fifoStock ? $fifoStock['expiration_date'] : $batchDetails['entry_date'];
                
                // Insert new product entry
                $insertStmt = $conn->prepare("
                    INSERT INTO tbl_product (
                        product_name, category_id, barcode, description, prescription, bulk,
                        expiration, quantity, srp, brand_id, supplier_id,
                        location_id, batch_id, status, stock_status, date_added
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stock_status = $quantity <= 0 ? 'out of stock' : ($quantity <= 10 ? 'low stock' : 'in stock');
                
                $insertStmt->execute([
                    $productDetails['product_name'],
                    $productDetails['category_id'],
                    $productDetails['barcode'],
                    $productDetails['description'],
                    $productDetails['prescription'],
                    $productDetails['bulk'],
                    $expiration,
                    $quantity,
                    $srp_value,
                    $srp,
                    $brand_id, // Use validated brand_id
                    $batchDetails['supplier_id'] ?: $supplier_id, // Use validated supplier_id
                    $batchDetails['location_id'],
                    $batch_id,
                    'active',
                    $stock_status,
                    $batchDetails['entry_date']
                ]);
                
                $duplicated_count++;
            }
            
            $conn->commit();
            
            echo json_encode([
                "success" => true,
                "message" => "Successfully duplicated product for {$duplicated_count} batch(es)",
                "duplicated_count" => $duplicated_count,
                "debug" => [
                    "existing_brands" => $existingBrands,
                    "existing_suppliers" => $existingSuppliers,
                    "used_brand_id" => $brand_id,
                    "used_supplier_id" => $supplier_id,
                    "original_product" => $productDetails
                ]
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "debug" => [
                    "existing_brands" => isset($existingBrands) ? $existingBrands : "not_set",
                    "existing_suppliers" => isset($existingSuppliers) ? $existingSuppliers : "not_set",
                    "used_brand_id" => isset($brand_id) ? $brand_id : "not_set",
                    "used_supplier_id" => isset($supplier_id) ? $supplier_id : "not_set",
                    "original_product" => isset($productDetails) ? $productDetails : "not_set"
                ]
            ]);
        }
        break;

    case 'reset_password':
        try {
            $emp_id = isset($data['emp_id']) ? (int)$data['emp_id'] : 0;
            $new_password = isset($data['new_password']) ? trim($data['new_password']) : '';

            // Validation
            if (empty($emp_id) || $emp_id <= 0) {
                echo json_encode(["success" => false, "message" => "Invalid employee ID."]);
                exit;
            }

            if (empty($new_password) || strlen($new_password) < 3) {
                echo json_encode(["success" => false, "message" => "Password must be at least 3 characters long."]);
                exit;
            }

            // Check if employee exists
            $checkStmt = $conn->prepare("SELECT emp_id FROM tbl_employee WHERE emp_id = :emp_id");
            $checkStmt->bindParam(":emp_id", $emp_id, PDO::PARAM_INT);
            $checkStmt->execute();

            if ($checkStmt->rowCount() === 0) {
                echo json_encode(["success" => false, "message" => "Employee not found."]);
                exit;
            }

            // Hash the new password
            $hashedPassword = password_hash($new_password, PASSWORD_BCRYPT);

            // Update the password
            $updateStmt = $conn->prepare("UPDATE tbl_employee SET password = :password WHERE emp_id = :emp_id");
            $updateStmt->bindParam(":password", $hashedPassword, PDO::PARAM_STR);
            $updateStmt->bindParam(":emp_id", $emp_id, PDO::PARAM_INT);

            if ($updateStmt->execute()) {
                echo json_encode([
                    "success" => true, 
                    "message" => "Password reset successfully.",
                    "emp_id" => $emp_id
                ]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to update password."]);
            }

        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
        }
        break;

    case 'deleteSupplier':
        // Log raw input
        $rawInput = file_get_contents('php://input');
        error_log("Raw Input: " . $rawInput);
    
        // Decode JSON
        $input = json_decode($rawInput, true);
    
        // Log decoded input
        error_log("Decoded Input: " . print_r($input, true));
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid JSON received",
                "error" => json_last_error_msg()
            ]);
            exit;
        }
    
        if (!isset($input['action'])) {
            echo json_encode(["success" => false, "message" => "Missing action"]);
            exit;
        }
    
        if (!isset($input['supplier_id'])) {
            echo json_encode(["success" => false, "message" => "Missing supplier_id"]);
            exit;
        }
    
        $supplier_id = intval($input['supplier_id']);
        if ($supplier_id <= 0) {
            echo json_encode(["success" => false, "message" => "Invalid supplier ID"]);
            exit;
        }
    
        $stmt = $conn->prepare("UPDATE tbl_supplier SET deleted_at = NOW() WHERE supplier_id = ?");
        
        try {
            if ($stmt->execute([$supplier_id])) {
                echo json_encode(["success" => true, "message" => "Supplier archived"]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to archive supplier"
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "An error occurred: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_discounts':
        try {
            $stmt = $conn->prepare("SELECT discount_id, discount_rate, discount_type FROM tbl_discount ORDER BY discount_id ASC");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Normalize numeric rate
            foreach ($rows as &$r) {
                $r['discount_rate'] = (float)$r['discount_rate'];
            }
            echo json_encode([ 'success' => true, 'data' => $rows ]);
        } catch (Exception $e) {
            echo json_encode([ 'success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'data' => [] ]);
        }
        break;

    // POS functions moved to sales_api.php
    
    case 'restoreSupplier':
            $data = json_decode(file_get_contents('php://input'), true);
        
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid JSON input"
                ]);
                exit;
            }
        
            $supplier_id = intval($data['supplier_id'] ?? 0);
            if ($supplier_id <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Missing or invalid supplier ID"
                ]);
                exit;
            }
        
            try {
                $stmt = $conn->prepare("UPDATE tbl_supplier SET deleted_at = NULL WHERE supplier_id = ?");
                if ($stmt->execute([$supplier_id])) {
                    echo json_encode([
                        "success" => true,
                        "message" => "Supplier restored"
                    ]);
                } else {
                    echo json_encode([
                        "success" => false,
                        "message" => "Error restoring supplier"
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Error restoring supplier",
                    "error" => $e->getMessage()
                ]);
            }
            break;
        
    case 'displayArchivedSuppliers':
        try {
            $stmt = $conn->query("SELECT * FROM tbl_supplier WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "suppliers" => $suppliers]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error fetching archived suppliers: " . $e->getMessage()]);
        }
        break;

    case 'get_archived_items':
        try {
            $stmt = $conn->prepare("
                SELECT 
                    archive_id as id,
                    item_name as name,
                    item_description as description,
                    item_type as type,
                    category,
                    archived_by as archivedBy,
                    DATE(archived_date) as archivedDate,
                    TIME(archived_time) as archivedTime,
                    reason,
                    status,
                    original_data
                FROM tbl_archive 
                ORDER BY archived_date DESC, archived_time DESC
            ");
            $stmt->execute();
            $archivedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                "success" => true,
                "data" => $archivedItems
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'restore_archived_item':
            try {
                $archive_id = $data['id'] ?? 0;
                
                if (!$archive_id) {
                    echo json_encode(["success" => false, "message" => "Archive ID is required"]);
                    break;
                }

                // Get archived item details
                $stmt = $conn->prepare("SELECT * FROM tbl_archive WHERE archive_id = ?");
                $stmt->execute([$archive_id]);
                $archivedItem = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$archivedItem) {
                    echo json_encode(["success" => false, "message" => "Archived item not found"]);
                    break;
                }

                $conn->beginTransaction();

                try {
                    // Restore based on item type
                    switch ($archivedItem['item_type']) {
                        case 'Product':
                            // Restore product
                            $stmt = $conn->prepare("UPDATE tbl_product SET status = 'active' WHERE product_id = ?");
                            $stmt->execute([$archivedItem['item_id']]);
                            break;
                        case 'Supplier':
                            // Restore supplier
                            $stmt = $conn->prepare("UPDATE tbl_supplier SET status = 'active' WHERE supplier_id = ?");
                            $stmt->execute([$archivedItem['item_id']]);
                            break;
                        case 'Category':
                            // Restore category
                            $stmt = $conn->prepare("UPDATE tbl_category SET status = 'active' WHERE category_id = ?");
                            $stmt->execute([$archivedItem['item_id']]);
                            break;
                    }

                    // Update archive status
                    $stmt = $conn->prepare("UPDATE tbl_archive SET status = 'Restored' WHERE archive_id = ?");
                    $stmt->execute([$archive_id]);

                    $conn->commit();
                    echo json_encode(["success" => true, "message" => "Item restored successfully"]);
                } catch (Exception $e) {
                    $conn->rollback();
                    throw $e;
                }
            } catch (Exception $e) {
                echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
            }
            break;

    case 'delete_archived_item':
        try {
            $archive_id = $data['id'] ?? 0;
            
            if (!$archive_id) {
                echo json_encode(["success" => false, "message" => "Archive ID is required"]);
                break;
            }

            // Update archive status to deleted
            $stmt = $conn->prepare("UPDATE tbl_archive SET status = 'Deleted' WHERE archive_id = ?");
            $stmt->execute([$archive_id]);
            
            echo json_encode(["success" => true, "message" => "Item permanently deleted"]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
        break;
    case 'get_transfer_log':
            try {
                // Get transfer data from the correct tables: tbl_transfer_header and tbl_transfer_dtl
                $stmt = $conn->prepare("
                    SELECT 
                        th.transfer_header_id as transfer_id,
                        td.product_id,
                        p.product_name,
                        sl.location_name as from_location,
                        dl.location_name as to_location,
                        td.qty as quantity,
                        th.date as transfer_date,
                        th.date as created_at,
                        th.status,
                        CONCAT(e.Fname, ' ', e.Lname) as transferred_by
                    FROM tbl_transfer_header th
                    JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                    JOIN tbl_product p ON td.product_id = p.product_id
                    JOIN tbl_location sl ON th.source_location_id = sl.location_id
                    JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                    LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                    ORDER BY th.date DESC, th.transfer_header_id DESC
                ");
                $stmt->execute();
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Add batch details for each transfer log
                foreach ($logs as &$log) {
                    // Get batch details for this transfer
                    $batchDetailsStmt = $conn->prepare("
                        SELECT 
                            tbd.batch_id,
                            tbd.batch_reference,
                            tbd.quantity as batch_quantity,
                            COALESCE(tbd.srp, fs.srp) as batch_srp,
                            tbd.expiration_date
                        FROM tbl_transfer_batch_details tbd
                        LEFT JOIN tbl_fifo_stock fs ON fs.batch_id = tbd.batch_id
                        WHERE tbd.product_id = ?
                        ORDER BY tbd.id ASC
                    ");
                    $batchDetailsStmt->execute([$log['product_id']]);
                    $details = $batchDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
                    $log['batch_details'] = $details ?: [];
                }
                
                echo json_encode([
                    "success" => true,
                    "data" => $logs
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage(),
                    "data" => []
                ]);
            }
            break;

    case 'get_transfer_batch_details':
        try {
            $transfer_id = $data['transfer_id'] ?? 0;
            $product_id = $data['product_id'] ?? 0;
            
            if (!$transfer_id && !$product_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Transfer ID or Product ID is required",
                    "data" => []
                ]);
                break;
            }
            
            if ($transfer_id) {
                // Enhanced query to get comprehensive batch details for transfers
                $stmt = $conn->prepare("
                    SELECT 
                        tbd.id,
                        tbd.product_id,
                        p.product_name,
                        p.barcode,
                        p.description,
                        c.category_name,
                        b.brand,
                        s.supplier_name,
                        tbd.batch_id,
                        tbd.batch_reference,
                        tbd.quantity as batch_quantity,
                        tbd.srp as batch_srp,
                        tbd.expiration_date,
                        tbd.transfer_header_id,
                        tbd.created_at,
                        th.date as transfer_date,
                        sl.location_name as source_location,
                        dl.location_name as destination_location,
                        CONCAT(e.Fname, ' ', e.Lname) as employee_name,
                        td.qty as transfer_quantity,
                        td.unit_price,
                        -- Add status based on expiration
                        CASE 
                            WHEN tbd.expiration_date IS NULL THEN 'No Expiry'
                            WHEN tbd.expiration_date < CURDATE() THEN 'Expired'
                            WHEN tbd.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'
                            ELSE 'Good'
                        END as batch_status,
                        -- Calculate days until expiry
                        CASE 
                            WHEN tbd.expiration_date IS NULL THEN NULL
                            ELSE DATEDIFF(tbd.expiration_date, CURDATE())
                        END as days_until_expiry
                    FROM tbl_transfer_batch_details tbd
                    LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
                    LEFT JOIN tbl_category c ON p.category_id = c.category_id
                    LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                    LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                    LEFT JOIN tbl_transfer_dtl td ON tbd.product_id = td.product_id AND tbd.transfer_header_id = td.transfer_header_id
                    LEFT JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                    LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                    LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                    LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                    WHERE tbd.transfer_header_id = ? AND tbd.quantity > 0
                    ORDER BY tbd.expiration_date ASC, tbd.id ASC
                ");
                $stmt->execute([$transfer_id]);
                $batch_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // If no batch details found in transfer_batch_details, try to get from FIFO stock
                if (empty($batch_details)) {
                    $fifo_stmt = $conn->prepare("
                        SELECT 
                            fs.fifo_id as id,
                            fs.product_id,
                            p.product_name,
                            p.barcode,
                            p.description,
                            c.category_name,
                            b.brand,
                            s.supplier_name,
                            fs.batch_id,
                            fs.batch_reference,
                            td.qty as batch_quantity,
                            fs.srp as batch_srp,
                            fs.expiration_date,
                            fs.transfer_header_id,
                            fs.created_at,
                            th.date as transfer_date,
                            sl.location_name as source_location,
                            dl.location_name as destination_location,
                            CONCAT(e.Fname, ' ', e.Lname) as employee_name,
                            td.qty as transfer_quantity,
                            td.unit_price,
                            -- Add status based on expiration
                            CASE 
                                WHEN fs.expiration_date IS NULL THEN 'No Expiry'
                                WHEN fs.expiration_date < CURDATE() THEN 'Expired'
                                WHEN fs.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'
                                ELSE 'Good'
                            END as batch_status,
                            -- Calculate days until expiry
                            CASE 
                                WHEN fs.expiration_date IS NULL THEN NULL
                                ELSE DATEDIFF(fs.expiration_date, CURDATE())
                            END as days_until_expiry
                        FROM tbl_fifo_stock fs
                        LEFT JOIN tbl_product p ON fs.product_id = p.product_id
                        LEFT JOIN tbl_category c ON p.category_id = c.category_id
                        LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                        LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                        LEFT JOIN tbl_transfer_dtl td ON fs.product_id = td.product_id
                        LEFT JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                        LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                        LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                        LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                        WHERE th.transfer_header_id = ? AND fs.available_quantity > 0
                        ORDER BY fs.expiration_date ASC, fs.fifo_id ASC
                        LIMIT 20
                    ");
                    $fifo_stmt->execute([$transfer_id]);
                    $batch_details = $fifo_stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                // If still no batch details, create comprehensive entries based on transfer details
                if (empty($batch_details)) {
                    $transfer_stmt = $conn->prepare("
                        SELECT 
                            td.product_id,
                            p.product_name,
                            p.barcode,
                            p.description,
                            c.category_name,
                            b.brand,
                            s.supplier_name,
                            td.qty as transfer_quantity,
                            td.unit_price,
                            p.expiration,
                            th.date as transfer_date,
                            sl.location_name as source_location,
                            dl.location_name as destination_location,
                            CONCAT(e.Fname, ' ', e.Lname) as employee_name
                        FROM tbl_transfer_dtl td
                        LEFT JOIN tbl_product p ON td.product_id = p.product_id
                        LEFT JOIN tbl_category c ON p.category_id = c.category_id
                        LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                        LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                        LEFT JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                        LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                        LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                        LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                        WHERE th.transfer_header_id = ?
                    ");
                    $transfer_stmt->execute([$transfer_id]);
                    $transfer_details = $transfer_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if ($transfer_details) {
                        $batch_details = [];
                        foreach ($transfer_details as $index => $transfer_detail) {
                            $batch_details[] = [
                                'id' => $index + 1,
                                'product_id' => $transfer_detail['product_id'],
                                'product_name' => $transfer_detail['product_name'],
                                'barcode' => $transfer_detail['barcode'],
                                'description' => $transfer_detail['description'],
                                'category_name' => $transfer_detail['category_name'],
                                'brand' => $transfer_detail['brand'],
                                'supplier_name' => $transfer_detail['supplier_name'],
                                'batch_id' => $index + 1,
                                'batch_reference' => 'BR-' . $transfer_id . '-' . $transfer_detail['product_id'] . '-' . ($index + 1),
                                'batch_quantity' => $transfer_detail['transfer_quantity'],
                                'batch_srp' => $transfer_detail['unit_price'] || 0,
                                'expiration_date' => $transfer_detail['expiration'],
                                'transfer_header_id' => $transfer_id,
                                'created_at' => $transfer_detail['transfer_date'],
                                'transfer_date' => $transfer_detail['transfer_date'],
                                'source_location' => $transfer_detail['source_location'],
                                'destination_location' => $transfer_detail['destination_location'],
                                'employee_name' => $transfer_detail['employee_name'],
                                'transfer_quantity' => $transfer_detail['transfer_quantity'],
                                'unit_price' => $transfer_detail['unit_price'],
                                'batch_status' => $transfer_detail['expiration'] ? 
                                    (strtotime($transfer_detail['expiration']) < time() ? 'Expired' : 
                                     (strtotime($transfer_detail['expiration']) <= strtotime('+7 days') ? 'Expiring Soon' : 'Good')) : 'No Expiry',
                                'days_until_expiry' => $transfer_detail['expiration'] ? 
                                    floor((strtotime($transfer_detail['expiration']) - time()) / (60 * 60 * 24)) : null
                            ];
                        }
                    }
                }
            } else {
                // Get batch details for a specific product
                $stmt = $conn->prepare("
                    SELECT 
                        tbd.id,
                        tbd.product_id,
                        p.product_name,
                        tbd.batch_id,
                        tbd.batch_reference,
                        tbd.quantity as batch_quantity,
                        tbd.srp as batch_srp,
                        tbd.expiration_date,
                        tbd.location_id,
                        l.location_name
                    FROM tbl_transfer_batch_details tbd
                    LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
                    LEFT JOIN tbl_location l ON tbd.location_id = l.location_id
                    WHERE tbd.product_id = ?
                    ORDER BY tbd.id ASC
                ");
                $stmt->execute([$product_id]);
            }
            
            $batch_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate summary
            $total_quantity = array_sum(array_column($batch_details, 'batch_quantity'));
            $total_batches = count($batch_details);
            $total_value = array_sum(array_map(function($item) {
                return $item['batch_quantity'] * $item['batch_srp'];
            }, $batch_details));
            
            echo json_encode([
                "success" => true,
                "data" => $batch_details,
                "summary" => [
                    "total_quantity" => $total_quantity,
                    "total_batches" => $total_batches,
                    "total_value" => $total_value
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_product_details':
        try {
            $product_id = $data['product_id'] ?? 0;
            $transfer_id = $data['transfer_id'] ?? 0;
            
            if (!$product_id && !$transfer_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product ID or Transfer ID is required",
                    "data" => []
                ]);
                break;
            }
            
            if ($transfer_id) {
                // Get product details from transfer
                // Updated query: Get ALL products for this transfer (multiple products possible)
                $stmt = $conn->prepare("
                    SELECT 
                        p.product_id,
                        p.product_name,
                        p.barcode,
                        p.description,
                        p.expiration,
                        p.status,
                        c.category_name,
                        b.brand,
                        s.supplier_name,
                        l.location_name,
                        th.transfer_header_id,
                        th.date as transfer_date,
                        td.qty as transfer_quantity,
                        (SELECT AVG(tbd.srp) FROM tbl_transfer_batch_details tbd WHERE tbd.product_id = p.product_id AND tbd.srp > 0 LIMIT 1) as transfer_srp,
                        sl.location_name as source_location,
                        dl.location_name as destination_location,
                        CONCAT(e.Fname, ' ', e.Lname) as employee_name,
                        e.emp_id
                    FROM tbl_transfer_header th
                    LEFT JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                    LEFT JOIN tbl_product p ON td.product_id = p.product_id
                    LEFT JOIN tbl_category c ON p.category_id = c.category_id
                    LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                    LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                    LEFT JOIN tbl_location l ON p.location_id = l.location_id
                    LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                    LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                    LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                    WHERE th.transfer_header_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$transfer_id]);
            } else {
                // Get product details by product ID
                $stmt = $conn->prepare("
                    SELECT 
                        p.product_id,
                        p.product_name,
                        p.barcode,
                        p.description,
                        p.expiration,
                        p.status,
                        c.category_name,
                        b.brand,
                        s.supplier_name,
                        l.location_name
                    FROM tbl_product p
                    LEFT JOIN tbl_category c ON p.category_id = c.category_id
                    LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                    LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                    LEFT JOIN tbl_location l ON p.location_id = l.location_id
                    WHERE p.product_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$product_id]);
            }
            
            $product_details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product_details) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product not found",
                    "data" => []
                ]);
                break;
            }
            
            // Get current stock levels from FIFO
            $stock_stmt = $conn->prepare("
                SELECT 
                    SUM(fs.available_quantity) as total_stock,
                    COUNT(fs.fifo_id) as total_batches,
                    MIN(fs.expiration_date) as earliest_expiry,
                    MAX(fs.expiration_date) as latest_expiry,
                    AVG(fs.srp) as average_srp,
                    MIN(fs.srp) as min_srp,
                    MAX(fs.srp) as max_srp
                FROM tbl_fifo_stock fs
                WHERE fs.product_id = ? AND fs.available_quantity > 0
            ");
            $stock_stmt->execute([$product_details['product_id']]);
            $stock_info = $stock_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get batch details if transfer_id provided
            $batch_details = [];
            if ($transfer_id) {
                // First try to get batch details from tbl_transfer_batch_details table
                // Note: tbl_transfer_batch_details doesn't have transfer_header_id column directly
                // We need to join with tbl_transfer_dtl to get the transfer_header_id relationship
                $batch_stmt = $conn->prepare("
                    SELECT DISTINCT
                        tbd.batch_id,
                        tbd.batch_reference,
                        tbd.quantity as batch_quantity,
                        tbd.srp as batch_srp,
                        tbd.expiration_date,
                        tbd.created_at
                    FROM tbl_transfer_batch_details tbd
                    LEFT JOIN tbl_transfer_dtl td ON tbd.product_id = td.product_id
                    WHERE td.transfer_header_id = ? AND tbd.product_id = ? AND tbd.quantity > 0
                    ORDER BY tbd.expiration_date ASC, tbd.id ASC
                ");
                $batch_stmt->execute([$transfer_id, $product_details['product_id']]);
                $batch_details = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // If no batch details found in transfer_batch_details, try to get from FIFO stock
                if (empty($batch_details)) {
                    $fifo_stmt = $conn->prepare("
                        SELECT 
                            fs.batch_id,
                            fs.batch_reference,
                            td.qty as batch_quantity,
                            fs.srp as batch_srp,
                            fs.expiration_date,
                            th.date as created_at
                        FROM tbl_fifo_stock fs
                        LEFT JOIN tbl_transfer_dtl td ON fs.product_id = td.product_id
                        LEFT JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                        WHERE th.transfer_header_id = ? AND fs.product_id = ? AND fs.available_quantity > 0
                        ORDER BY fs.fifo_id ASC
                        LIMIT 5
                    ");
                    $fifo_stmt->execute([$transfer_id, $product_details['product_id']]);
                    $batch_details = $fifo_stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                // If still no batch details, create a generic batch entry based on transfer
                if (empty($batch_details)) {
                    $batch_details = [
                        [
                            'batch_id' => 1,
                            'batch_reference' => 'BR-' . $transfer_id . '-' . $product_details['product_id'],
                            'batch_quantity' => $product_details['transfer_quantity'] ?? 0,
                            'batch_srp' => $product_details['transfer_srp'] ?? 0,
                            'expiration_date' => $product_details['expiration'] ?? null,
                            'created_at' => $product_details['transfer_date'] ?? date('Y-m-d H:i:s')
                        ]
                    ];
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => [
                    "product" => $product_details,
                    "stock_info" => $stock_info,
                    "batch_details" => $batch_details
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    case 'get_transfer_log_by_id':
        try {
            $transfer_id = isset($data['transfer_id']) ? (int)$data['transfer_id'] : 0;
            if ($transfer_id <= 0) {
                echo json_encode(["success" => false, "message" => "transfer_id is required"]);
                break;
            }
            
            // Get transfer data from the correct tables: tbl_transfer_header and tbl_transfer_dtl
            $stmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id as transfer_id,
                    td.product_id,
                    p.product_name,
                    c.category_name as category,
                    b.brand,
                    p.barcode,
                    COALESCE(fs.srp, 0) as srp,
                    sl.location_name as from_location,
                    dl.location_name as to_location,
                    td.qty as quantity,
                    th.date as transfer_date,
                    th.date as created_at,
                    th.status,
                    CONCAT(e.Fname, ' ', e.Lname) as transferred_by
                FROM tbl_transfer_header th
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                JOIN tbl_location sl ON th.source_location_id = sl.location_id
                JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id AND fs.available_quantity > 0
                WHERE th.transfer_header_id = ?
                LIMIT 1
            ");
            $stmt->execute([$transfer_id]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$log) {
                echo json_encode(["success" => false, "message" => "Transfer log not found for transfer_id: " . $transfer_id]);
                break;
            }
            
            // Try to find the transfer_header_id to get batch details
            // Method 1: Direct lookup using transfer log data
            $mapStmt = $conn->prepare("
                SELECT 
                    th.transfer_header_id,
                    th.date as header_date,
                    sl.location_name as source_name,
                    dl.location_name as dest_name
                FROM tbl_transfer_header th
                JOIN tbl_location sl ON th.source_location_id = sl.location_id
                JOIN tbl_location dl ON th.destination_location_id = dl.location_id
                JOIN tbl_transfer_dtl td ON td.transfer_header_id = th.transfer_header_id
                WHERE DATE(th.date) = DATE(?)
                  AND sl.location_name = ?
                  AND dl.location_name = ?
                  AND td.product_id = ?
                  AND td.qty = ?
                ORDER BY th.transfer_header_id DESC
                LIMIT 1
            ");
            $mapStmt->execute([
                $log['transfer_date'],
                $log['from_location'],
                $log['to_location'],
                $log['product_id'],
                $log['quantity']
            ]);
            $header = $mapStmt->fetch(PDO::FETCH_ASSOC);
            
            $batchDetails = [];
            
            if ($header && isset($header['transfer_header_id'])) {
                $transfer_header_id = $header['transfer_header_id'];
                
                // Get batch details for this transfer
                $batchStmt = $conn->prepare("
                    SELECT 
                        tbd.id as detail_id,
                        tbd.batch_id,
                        tbd.batch_reference,
                        tbd.quantity as batch_quantity,
                        tbd.quantity,
                        COALESCE(tbd.srp, fs.srp) as batch_srp,
                        COALESCE(tbd.srp, fs.srp) as srp,
                        tbd.expiration_date,
                        fs.entry_date
                    FROM tbl_transfer_batch_details tbd
                    LEFT JOIN tbl_fifo_stock fs ON fs.batch_id = tbd.batch_id
                    LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
                    WHERE tbd.product_id = ?
                    ORDER BY tbd.id ASC
                ");
                $batchStmt->execute([$log['product_id']]);
                $batchDetails = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // If no batch details found, create a default entry from the transfer log
            if (empty($batchDetails)) {
                $batchDetails = [[
                    'batch_id' => null,
                    'batch_number' => 1,
                    'batch_reference' => 'BR-' . $log['transfer_id'] . '-' . $log['product_id'],
                    'batch_quantity' => $log['quantity'],
                    'quantity' => $log['quantity'],
                    'batch_srp' => $log['srp'],
                    'srp' => $log['srp'],
                    'expiration_date' => null,
                    'unit_cost' => null,
                    'entry_date' => $log['transfer_date']
                ]];
            }
            
            $log['batch_details'] = $batchDetails;
            
            // Add debug info
            $log['debug_info'] = [
                'transfer_id' => $transfer_id,
                'found_header' => isset($header['transfer_header_id']) ? $header['transfer_header_id'] : 'not found',
                'batch_count' => count($batchDetails)
            ];
            
            echo json_encode([
                "success" => true, 
                "data" => $log,
                "message" => "Transfer log retrieved successfully with " . count($batchDetails) . " batch detail(s)"
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false, 
                "message" => "Database error: " . $e->getMessage(),
                "trace" => $e->getTraceAsString()
            ]);
        }
        break;

    case 'get_current_user':
        try {
            // Start session if not already started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Debug: Log session state
            error_log("get_current_user: Session ID = " . session_id());
            error_log("get_current_user: Session data = " . json_encode($_SESSION));
            
            // Check if session has user data
            $hasUserId = isset($_SESSION['user_id']) || isset($_SESSION['emp_id']);
            $hasUsername = isset($_SESSION['username']);
            
            if ($hasUserId && $hasUsername) {
                // Get user_id from either user_id or emp_id session variable
                $userId = $_SESSION['user_id'] ?? $_SESSION['emp_id'];
                
                // Get current user's full data from database
                $userStmt = $conn->prepare("
                    SELECT 
                        emp_id,
                        Fname,
                        Lname,
                        CONCAT(Fname, ' ', Lname) as full_name,
                        email,
                        username,
                        role_id,
                        status
                    FROM tbl_employee 
                    WHERE emp_id = :user_id AND status = 'active'
                    LIMIT 1
                ");
                $userStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                $userStmt->execute();
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Get role name
                    $roleName = 'User';
                    if (isset($_SESSION['role'])) {
                        $roleName = $_SESSION['role'];
                    } else if ($user['role_id']) {
                        $roleStmt = $conn->prepare("SELECT role_name FROM tbl_role WHERE role_id = :role_id LIMIT 1");
                        $roleStmt->bindParam(':role_id', $user['role_id'], PDO::PARAM_INT);
                        $roleStmt->execute();
                        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                        if ($roleData) {
                            $roleName = $roleData['role_name'];
                        }
                    }
                    
                    echo json_encode([
                        "success" => true,
                        "data" => [
                            "user_id" => (int)$user['emp_id'],
                            "emp_id" => (int)$user['emp_id'],
                            "username" => $user['username'],
                            "full_name" => $user['full_name'],
                            "fullName" => $user['full_name'], // Keep for backward compatibility
                            "email" => $user['email'],
                            "role" => $roleName,
                            "role_id" => (int)$user['role_id'],
                            "status" => $user['status'],
                            "session_id" => session_id()
                        ],
                        "message" => "Current user retrieved successfully"
                    ]);
                } else {
                    echo json_encode([
                        "success" => false,
                        "message" => "User not found or inactive (ID: " . $userId . ")",
                        "debug" => [
                            "user_id" => $userId,
                            "session_exists" => true,
                            "user_found_in_db" => false
                        ]
                    ]);
                }
            } else {
                // No session found - provide detailed debug info
                echo json_encode([
                    "success" => false,
                    "message" => "No active session found - please login",
                    "debug" => [
                        "session_id" => session_id(),
                        "has_user_id" => isset($_SESSION['user_id']),
                        "has_emp_id" => isset($_SESSION['emp_id']),
                        "has_username" => isset($_SESSION['username']),
                        "session_keys" => array_keys($_SESSION),
                        "recommendation" => "Please login through the login page to establish a session"
                    ]
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Session error: " . $e->getMessage(),
                "trace" => $e->getTraceAsString()
            ]);
        }
        break;
    // Stock Adjustment API Functions
    case 'get_stock_adjustments':
            try {
                $search = $data['search'] ?? '';
                $type = $data['type'] ?? 'all';
                $status = $data['status'] ?? 'all';
                $page = $data['page'] ?? 1;
                $limit = $data['limit'] ?? 10;
                $offset = ($page - 1) * $limit;
                
                $whereConditions = [];
                $params = [];
                
                if ($search) {
                    $whereConditions[] = "(p.product_name LIKE ? OR p.barcode LIKE ? OR sm.notes LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                if ($type !== 'all') {
                    $whereConditions[] = "sm.movement_type = ?";
                    $params[] = $type;
                }
                
                if ($status !== 'all') {
                    $whereConditions[] = "sm.status = ?";
                    $params[] = $status;
                }
                
                $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
                
                // Get total count (using same logic as main query to prevent inconsistencies)
                $countStmt = $conn->prepare("
                    SELECT COUNT(DISTINCT sm.movement_id) as total
                    FROM tbl_stock_movements sm
                    LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                    LEFT JOIN tbl_employee e ON sm.created_by = e.emp_id
                    LEFT JOIN (
                        SELECT DISTINCT
                            l1.emp_id,
                            l1.login_date,
                            l1.login_time,
                            l1.logout_time,
                            l1.location,
                            l1.shift_id,
                            l1.terminal_id,
                            l1.location_id,
                            -- Prioritize POS terminal users for stock movements
                            ROW_NUMBER() OVER (
                                PARTITION BY DATE(l1.login_date), TIME(l1.login_time)
                                ORDER BY 
                                    CASE WHEN l1.location LIKE '%POS%' OR l1.location LIKE '%Cashier%' THEN 1 ELSE 2 END,
                                    l1.login_time DESC
                            ) as rn
                        FROM tbl_login l1
                    ) login_info ON (
                        DATE(login_info.login_date) = DATE(sm.movement_date)
                        AND login_info.login_time <= TIME(sm.movement_date)
                        AND (login_info.logout_time IS NULL OR login_info.logout_time >= TIME(sm.movement_date))
                        AND login_info.rn = 1
                    )
                    LEFT JOIN tbl_employee login_emp ON login_info.emp_id = login_emp.emp_id
                    LEFT JOIN tbl_shift s ON login_info.shift_id = s.shift_id
                    LEFT JOIN tbl_pos_terminal pt ON login_info.terminal_id = pt.terminal_id
                    LEFT JOIN tbl_location loc ON login_emp.location_id = loc.location_id
                    LEFT JOIN tbl_location login_loc ON login_info.location_id = login_loc.location_id
                    LEFT JOIN tbl_role r ON e.role_id = r.role_id
                    LEFT JOIN tbl_role login_role ON login_emp.role_id = login_role.role_id
                    LEFT JOIN tbl_employee inv_mgr ON (
                        inv_mgr.role_id = (SELECT role_id FROM tbl_role WHERE LOWER(role) LIKE '%inventory%' OR LOWER(role) LIKE '%manager%' LIMIT 1)
                        AND inv_mgr.status = 'Active'
                    )
                    $whereClause
                    -- CRITICAL: Filter out admin/inventory entries for POS sales (same as main query)
                    AND NOT (
                        (sm.notes LIKE '%POS Sale%' OR sm.notes LIKE '%sold%') 
                        AND (
                            sm.created_by = 'admin' 
                            OR sm.created_by = 'inventory' 
                            OR LOWER(COALESCE(login_role.role, r.role)) LIKE '%admin%'
                            OR LOWER(COALESCE(login_role.role, r.role)) LIKE '%inventory%'
                            OR LOWER(COALESCE(login_role.role, r.role)) LIKE '%manager%'
                        )
                    )
                ");
                $countStmt->execute($params);
                $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Get adjustments with pagination and login information
                // Fixed query to prevent duplicates by filtering out admin/inventory entries for POS sales
                $stmt = $conn->prepare("
                    SELECT DISTINCT
                        sm.movement_id as id,
                        p.product_name,
                        p.barcode as product_id,
                        CASE 
                            WHEN sm.movement_type = 'IN' THEN 'Addition'
                            WHEN sm.movement_type = 'OUT' THEN 'Subtraction'
                            ELSE 'Adjustment'
                        END as adjustment_type,
                        sm.quantity,
                        sm.notes as reason,
                        -- For return approvals, use the employee who created the movement
                        -- For other movements, use login information
                        CASE 
                            WHEN sm.notes LIKE '%Return approved%' OR sm.notes LIKE '%stock restored%' THEN
                                CONCAT(e.fname, ' ', e.lname, ' (', r.role, ' @ ', loc.location_name, ')')
                            ELSE
                                COALESCE(
                                    CONCAT(login_emp.Fname, ' ', login_emp.Lname, ' - ', s.shifts, ' (', COALESCE(pt.terminal_name, login_info.location), ' @ ', COALESCE(login_loc.location_name, loc.location_name), ')'),
                                    CONCAT(e.fname, ' ', e.lname, ' (', r.role, ' @ ', loc.location_name, ')')
                                )
                        END as adjusted_by,
                        e.emp_id as employee_id,
                        e.username as employee_username,
                        DATE(sm.movement_date) as date,
                        TIME(sm.movement_date) as time,
                        'Approved' as status,
                        sm.notes,
                        sm.expiration_date,
                        sm.reference_no,
                        sm.created_by,
                        -- Get login information for the time of adjustment (prioritize POS terminal users)
                        login_emp.Fname as login_fname,
                        login_emp.Lname as login_lname,
                        login_emp.username as login_username,
                        CONCAT(login_emp.Fname, ' ', login_emp.Lname) as logged_in_user,
                        login_info.login_time,
                        login_info.login_date,
                        login_info.location as terminal_name,
                        -- Get shift and terminal information
                        s.shifts as shift_name,
                        s.time as shift_start,
                        s.end_time as shift_end,
                        pt.terminal_name as pos_terminal_name,
                        -- Get location information
                        loc.location_name as assigned_location,
                        login_loc.location_name as login_location,
                        -- Get role information (prioritize login role over employee role)
                        r.role as user_role,
                        login_role.role as login_role,
                        COALESCE(login_role.role, r.role) as display_role,
                        -- Get inventory manager information
                        inv_mgr.Fname as inv_mgr_fname,
                        inv_mgr.Lname as inv_mgr_lname,
                        CONCAT(inv_mgr.Fname, ' ', inv_mgr.Lname) as inventory_manager
                    FROM tbl_stock_movements sm
                    LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                    LEFT JOIN tbl_employee e ON sm.created_by = e.emp_id
                    LEFT JOIN tbl_role r ON e.role_id = r.role_id
                    LEFT JOIN tbl_location loc ON e.location_id = loc.location_id
                    -- Only join with login table for non-return movements to prevent duplicates
                    LEFT JOIN (
                        SELECT DISTINCT
                            l1.emp_id,
                            l1.login_date,
                            l1.login_time,
                            l1.logout_time,
                            l1.location,
                            l1.shift_id,
                            l1.terminal_id,
                            l1.location_id,
                            -- Prioritize POS terminal users for stock movements
                            ROW_NUMBER() OVER (
                                PARTITION BY DATE(l1.login_date), TIME(l1.login_time)
                                ORDER BY 
                                    CASE WHEN l1.location LIKE '%POS%' OR l1.location LIKE '%Cashier%' THEN 1 ELSE 2 END,
                                    l1.login_time DESC
                            ) as rn
                        FROM tbl_login l1
                    ) login_info ON (
                        -- Only join for non-return movements to prevent duplicates
                        NOT (sm.notes LIKE '%Return approved%' OR sm.notes LIKE '%stock restored%')
                        AND DATE(login_info.login_date) = DATE(sm.movement_date)
                        AND login_info.login_time <= TIME(sm.movement_date)
                        AND (login_info.logout_time IS NULL OR login_info.logout_time >= TIME(sm.movement_date))
                        AND login_info.rn = 1
                    )
                    LEFT JOIN tbl_employee login_emp ON login_info.emp_id = login_emp.emp_id
                    -- Join with shift and terminal tables
                    LEFT JOIN tbl_shift s ON login_info.shift_id = s.shift_id
                    LEFT JOIN tbl_pos_terminal pt ON login_info.terminal_id = pt.terminal_id
                    -- Join with location table
                    LEFT JOIN tbl_location login_loc ON login_info.location_id = login_loc.location_id
                    -- Join with role tables to get role information
                    LEFT JOIN tbl_role login_role ON login_emp.role_id = login_role.role_id
                    -- Join with inventory manager role
                    LEFT JOIN tbl_employee inv_mgr ON (
                        inv_mgr.role_id = (SELECT role_id FROM tbl_role WHERE LOWER(role) LIKE '%inventory%' OR LOWER(role) LIKE '%manager%' LIMIT 1)
                        AND inv_mgr.status = 'Active'
                    )
                    $whereClause
                    -- CRITICAL: Filter out admin/inventory entries for POS sales
                    AND NOT (
                        (sm.notes LIKE '%POS Sale%' OR sm.notes LIKE '%sold%') 
                        AND (
                            sm.created_by = 'admin' 
                            OR sm.created_by = 'inventory' 
                            OR LOWER(COALESCE(login_role.role, r.role)) LIKE '%admin%'
                            OR LOWER(COALESCE(login_role.role, r.role)) LIKE '%inventory%'
                            OR LOWER(COALESCE(login_role.role, r.role)) LIKE '%manager%'
                        )
                    )
                    ORDER BY sm.movement_date DESC
                    LIMIT " . (int)$limit . " OFFSET " . (int)$offset
                );
                
                $stmt->execute($params);
                $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    "success" => true,
                    "data" => $adjustments,
                    "total" => $totalCount,
                    "page" => $page,
                    "limit" => $limit,
                    "pages" => ceil($totalCount / $limit)
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage(),
                    "data" => []
                ]);
            }
            break;

    case 'create_stock_adjustment':
        try {
            $product_id = $data['product_id'] ?? 0;
            $adjustment_type = $data['adjustment_type'] ?? 'Addition';
            $quantity = $data['quantity'] ?? 0;
            $reason = $data['reason'] ?? '';
            $notes = $data['notes'] ?? '';
            $srp = $data['srp'] ?? 0;
            $expiration_date = $data['expiration_date'] ?? null;
            $created_by = $data['created_by'] ?? 'admin';
                
                if (!$product_id || !$quantity || !$reason) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Product ID, quantity, and reason are required"
                    ]);
                    break;
                }
                
                // Start transaction
                $conn->beginTransaction();
                
                // Get employee details for proper logging
                $empDetails = getEmployeeDetails($conn, $created_by);
                
                // Get product details (NO quantity or srp - they're in tbl_fifo_stock)
                $productStmt = $conn->prepare("
                    SELECT product_name, location_id
                    FROM tbl_product 
                    WHERE product_id = ?
                ");
                $productStmt->execute([$product_id]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception("Product not found");
                }
                
                // Get old quantity from tbl_fifo_stock
                $oldQtyStmt = $conn->prepare("
                    SELECT COALESCE(SUM(available_quantity), 0) as total_quantity
                    FROM tbl_fifo_stock
                    WHERE product_id = ?
                ");
                $oldQtyStmt->execute([$product_id]);
                $old_quantity = $oldQtyStmt->fetchColumn();
                
                // Determine movement type
                $movement_type = ($adjustment_type === 'Addition') ? 'IN' : 'OUT';
                
                // Calculate new quantity
                $new_quantity = ($movement_type === 'IN') ? 
                    $old_quantity + $quantity : 
                    max(0, $old_quantity - $quantity);
                
                // Create batch record for the adjustment
                $batchStmt = $conn->prepare("
                    INSERT INTO tbl_batch (
                        batch, supplier_id, location_id, entry_date, entry_time, 
                        entry_by, order_no
                    ) VALUES (?, ?, ?, CURDATE(), CURTIME(), ?, ?)
                ");
                $batch_reference = 'ADJ-' . date('Ymd-His');
                $batchStmt->execute([$batch_reference, null, $product['location_id'], $created_by, '']);
                $batch_id = $conn->lastInsertId();
                
                // NO LONGER UPDATE tbl_product.quantity - it doesn't exist anymore!
                // Create FIFO stock entry if adding quantity
                if ($movement_type === 'IN') {
                    $fifoStmt = $conn->prepare("
                        INSERT INTO tbl_fifo_stock (
                            product_id, batch_id, batch_reference, quantity, available_quantity,
                            srp, expiration_date, entry_date, entry_by, location_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
                    ");
                    $fifoStmt->execute([
                        $product_id, $batch_id, $batch_reference, $quantity, $quantity,
                        $srp, $expiration_date, $created_by, $product['location_id']
                    ]);
                }
                
                // Create stock movement record
                $movementStmt = $conn->prepare("
                    INSERT INTO tbl_stock_movements (
                        product_id, batch_id, movement_type, quantity, remaining_quantity,
                        srp, expiration_date, reference_no, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $movementStmt->execute([
                    $product_id, $batch_id, $movement_type, $quantity, $new_quantity,
                    $srp, $expiration_date, $batch_reference, $notes, $empDetails['formatted_name']
                ]);
                
                // Create stock summary record
                $summaryStmt = $conn->prepare("
                    INSERT INTO tbl_stock_summary (
                        product_id, batch_id, available_quantity, srp,
                        expiration_date, batch_reference, total_quantity
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        available_quantity = available_quantity + VALUES(available_quantity),
                        total_quantity = total_quantity + VALUES(total_quantity),
                        srp = VALUES(srp),
                        last_updated = CURRENT_TIMESTAMP
                ");
                $summaryStmt->execute([
                    $product_id, $batch_id, $quantity, $srp,
                    $expiration_date, $batch_reference, $quantity
                ]);
                
                $conn->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Stock adjustment created successfully",
                    "data" => [
                        "adjustment_id" => $conn->lastInsertId(),
                        "old_quantity" => $old_quantity,
                        "new_quantity" => $new_quantity,
                        "batch_reference" => $batch_reference
                    ]
                ]);
                
            } catch (Exception $e) {
                if (isset($conn)) {
                    $conn->rollback();
                }
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage()
                ]);
            }
            break;
    case 'update_stock_adjustment':
        try {
            $movement_id = $data['movement_id'] ?? 0;
            $quantity = $data['quantity'] ?? 0;
            $reason = $data['reason'] ?? '';
            $notes = $data['notes'] ?? '';
            $expiration_date = $data['expiration_date'] ?? null;
                
                if (!$movement_id) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Movement ID is required"
                    ]);
                    break;
                }
                
                // Start transaction
                $conn->beginTransaction();
                
                // Get current movement details
                $movementStmt = $conn->prepare("
                    SELECT sm.*, p.product_name, 
                           COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as current_product_quantity
                    FROM tbl_stock_movements sm
                    LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                    WHERE sm.movement_id = ?
                ");
                $movementStmt->execute([$movement_id]);
                $movement = $movementStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$movement) {
                    throw new Exception("Movement not found");
                }
                
                // Calculate quantity difference
                $quantity_diff = $quantity - $movement['quantity'];
                
                // Update product quantity
                $new_product_quantity = $movement['current_product_quantity'] + $quantity_diff;
                $new_product_quantity = max(0, $new_product_quantity);
                
                $updateProductStmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET quantity = ?,
                        stock_status = CASE 
                            WHEN ? <= 0 THEN 'out of stock'
                            WHEN ? <= 10 THEN 'low stock'
                            ELSE 'in stock'
                        END
                    WHERE product_id = ?
                ");
                $updateProductStmt->execute([$new_product_quantity, $new_product_quantity, $new_product_quantity, $movement['product_id']]);
                
                // Update movement record
                $updateMovementStmt = $conn->prepare("
                    UPDATE tbl_stock_movements 
                    SET quantity = ?,
                        remaining_quantity = ?,
                        expiration_date = ?,
                        notes = ?
                    WHERE movement_id = ?
                ");
                $updateMovementStmt->execute([
                    $quantity, $new_product_quantity, $expiration_date, $notes, $movement_id
                ]);
                
                // Update stock summary
                $updateSummaryStmt = $conn->prepare("
                    UPDATE tbl_stock_summary 
                    SET available_quantity = available_quantity + ?,
                        expiration_date = ?,
                        last_updated = CURRENT_TIMESTAMP
                    WHERE batch_id = ?
                ");
                $updateSummaryStmt->execute([
                    $quantity_diff, $expiration_date, $movement['batch_id']
                ]);
                
                $conn->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Stock adjustment updated successfully"
                ]);
                
            } catch (Exception $e) {
                if (isset($conn)) {
                    $conn->rollback();
                }
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage()
                ]);
            }
            break;

    case 'delete_stock_adjustment':
        try {
            $movement_id = $data['movement_id'] ?? 0;
            
            if (!$movement_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Movement ID is required"
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get movement details
            $movementStmt = $conn->prepare("
                SELECT sm.*, 
                       COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as current_product_quantity
                FROM tbl_stock_movements sm
                LEFT JOIN tbl_product p ON sm.product_id = p.product_id
                WHERE sm.movement_id = ?
            ");
            $movementStmt->execute([$movement_id]);
            $movement = $movementStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$movement) {
                throw new Exception("Movement not found");
            }
            
            // Reverse the adjustment effect on product quantity
            $quantity_to_reverse = ($movement['movement_type'] === 'IN') ? -$movement['quantity'] : $movement['quantity'];
            $new_product_quantity = $movement['current_product_quantity'] + $quantity_to_reverse;
            $new_product_quantity = max(0, $new_product_quantity);
            
            // Update product quantity
            $updateProductStmt = $conn->prepare("
                UPDATE tbl_product 
                SET quantity = ?,
                    stock_status = CASE 
                        WHEN ? <= 0 THEN 'out of stock'
                        WHEN ? <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END
                WHERE product_id = ?
            ");
            $updateProductStmt->execute([$new_product_quantity, $new_product_quantity, $new_product_quantity, $movement['product_id']]);
            
            // Delete movement record
            $deleteMovementStmt = $conn->prepare("DELETE FROM tbl_stock_movements WHERE movement_id = ?");
            $deleteMovementStmt->execute([$movement_id]);
            
            // Update stock summary
            $updateSummaryStmt = $conn->prepare("
                UPDATE tbl_stock_summary 
                SET available_quantity = available_quantity - ?,
                    last_updated = CURRENT_TIMESTAMP
                WHERE batch_id = ?
            ");
            $updateSummaryStmt->execute([$movement['quantity'], $movement['batch_id']]);
            
            $conn->commit();
            
            echo json_encode([
                "success" => true,
                "message" => "Stock adjustment deleted successfully"
            ]);
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
            }
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'get_stock_adjustment_stats':
            try {
                $stmt = $conn->prepare("
                    SELECT 
                        COUNT(*) as total_adjustments,
                        COUNT(CASE WHEN movement_type = 'IN' THEN 1 END) as additions,
                        COUNT(CASE WHEN movement_type = 'OUT' THEN 1 END) as subtractions,
                        SUM(CASE WHEN movement_type = 'IN' THEN quantity ELSE -quantity END) as net_quantity
                    FROM tbl_stock_movements
                    WHERE movement_type IN ('IN', 'OUT')
                ");
                $stmt->execute();
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    "success" => true,
                    "data" => $stats
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage(),
                    "data" => []
                ]);
            }
            break;
    case 'get_product_quantities':
        try {
            $location_id = $data['location_id'] ?? null;
            
            $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived')";
            $params = [];
            
            if ($location_id) {
                $whereClause .= " AND p.location_id = ?";
                $params[] = $location_id;
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as product_quantity,
                    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.status,
                    p.stock_status,
                    p.date_added,
                    s.supplier_name,
                    b.brand,
                    l.location_name,
                    -- Get total quantity from stock summary or FIFO stock
                    COALESCE(
                        (SELECT SUM(ss.available_quantity) 
                         FROM tbl_stock_summary ss 
                         WHERE ss.product_id = p.product_id), 
                        (SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id),
                        0
                    ) as total_available_quantity
                FROM tbl_product p 
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id 
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id 
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                $whereClause
                ORDER BY p.product_name ASC
            ");
            
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $products
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "data" => []
            ]);
        }
        break;

    // POS functions moved to sales_api.php

    // Additional functions from mysqli backend files
    case 'test_action':
        echo json_encode([
            "success" => true,
            "message" => "Test action successful",
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        break;
        
    case 'test_database_connection':
        try {
            echo json_encode([
                "success" => true,
                "message" => "Database connection successful",
                "database_info" => "Connected to enguio2 database via PDO",
                "timestamp" => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database connection failed: " . $e->getMessage()
            ]);
        }
        break;


    case 'check_fifo_stock':
            try {
                $product_id = $data['product_id'] ?? null;
                $batch_id = $data['batch_id'] ?? null;
                
                $sql = "SELECT fs.*, p.product_name, b.batch 
                        FROM tbl_fifo_stock fs 
                        JOIN tbl_product p ON fs.product_id = p.product_id 
                        JOIN tbl_batch b ON fs.batch_id = b.batch_id";
                
                $params = [];
                $conditions = [];
                
                if ($product_id) {
                    $conditions[] = "fs.product_id = ?";
                    $params[] = $product_id;
                }
                
                if ($batch_id) {
                    $conditions[] = "fs.batch_id = ?";
                    $params[] = $batch_id;
                }
                
                if (!empty($conditions)) {
                    $sql .= " WHERE " . implode(" AND ", $conditions);
                }
                
                $sql .= " ORDER BY fs.entry_date DESC, fs.product_id";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $fifo_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    "success" => true,
                    "fifo_entries" => $fifo_entries,
                    "count" => count($fifo_entries)
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage()
                ]);
            }
            break;

    case 'add_product_enhanced':
        try {
            // Extract data
            $product_name = $data['product_name'] ?? '';
            $description = $data['description'] ?? '';
            $category = $data['category'] ?? 'General';
            $brand_id = $data['brand_id'] ?? 1;
            $supplier_id = $data['supplier_id'] ?? 1;
            $location_id = $data['location_id'] ?? 1;
            $srp = $data['srp'] ?? 0;
            $quantity = $data['quantity'] ?? 0;
            $expiration = $data['expiration'] ?? null;
            $order_no = $data['order_no'] ?? '';
            $entry_by = $data['entry_by'] ?? 'admin';
                
                if (empty($product_name)) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Product name is required"
                    ]);
                    break;
                }
                
                // Start transaction
                $conn->beginTransaction();
                
                try {
                    // Insert product
                    $productStmt = $conn->prepare("
                        INSERT INTO tbl_product (
                            product_name, description, category, brand_id, supplier_id, 
                            location_id, srp, quantity, status, date_added
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURDATE())
                    ");
                    
                    $productStmt->execute([
                        $product_name, $description, $category, $brand_id, 
                        $supplier_id, $location_id, $srp, $quantity
                    ]);
                    
                    $product_id = $conn->lastInsertId();
                    
                    // Create batch record
                    $batchStmt = $conn->prepare("
                        INSERT INTO tbl_batch (
                            batch, supplier_id, location_id, date, entry_date, entry_time, 
                            entry_by, order_no
                        ) VALUES (?, ?, ?, CURDATE(), CURDATE(), CURTIME(), ?, ?)
                    ");
                    
                    $reference = "BATCH-" . date('Ymd') . "-" . $product_id;
                    $batchStmt->execute([$reference, $supplier_id, $location_id, $entry_by, $order_no]);
                    
                    $batch_id = $conn->lastInsertId();
                    
                    // Create stock summary
                    $summaryStmt = $conn->prepare("
                        INSERT INTO tbl_stock_summary (
                            product_id, batch_id, available_quantity, srp,
                            expiration_date, batch_reference, total_quantity
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            available_quantity = available_quantity + VALUES(available_quantity),
                            total_quantity = total_quantity + VALUES(total_quantity),
                            srp = VALUES(srp),
                            last_updated = CURRENT_TIMESTAMP
                    ");
                    
                    $summaryStmt->execute([
                        $product_id, $batch_id, $quantity, $srp,
                        $expiration, $reference, $quantity
                    ]);
                    
                    // Create FIFO stock entry if table exists
                    try {
                        $fifoStmt = $conn->prepare("
                            INSERT INTO tbl_fifo_stock (
                                product_id, batch_id, available_quantity, srp, expiration_date, batch_reference, entry_date, entry_by
                            ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)
                        ");
                        
                        $fifoStmt->execute([
                            $product_id, $batch_id, $quantity, $srp, $expiration, $reference, $entry_by
                        ]);
                        
                        $fifo_created = true;
                    } catch (Exception $e) {
                        // FIFO table might not exist, continue without it
                        $fifo_created = false;
                    }
                    
                    $conn->commit();
                    
                    echo json_encode([
                        "success" => true,
                        "message" => "Product added successfully with enhanced tracking",
                        "product_id" => $product_id,
                        "batch_id" => $batch_id,
                        "fifo_stock_created" => $fifo_created
                    ]);
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    throw $e;
                }
                
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage()
                ]);
            }
            break;

    case 'check_duplicates':
        try {
            // Find products with duplicate name+category combinations
            $stmt = $conn->prepare("
                SELECT product_name, category_id, location_id, COUNT(*) as count
                FROM tbl_product 
                GROUP BY product_name, category_id, location_id 
                HAVING COUNT(*) > 1
                ORDER BY product_name, category_id
            ");
            $stmt->execute();
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "duplicates" => $duplicates,
                "message" => "Found " . count($duplicates) . " duplicate combinations"
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
    case 'check_constraints':
        try {
            // Check for unique constraints on tbl_product
            $stmt = $conn->prepare("
                SELECT 
                    CONSTRAINT_NAME as constraint_name,
                    CONSTRAINT_TYPE as constraint_type,
                    COLUMN_NAME as columns
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tbl_product'
                AND CONSTRAINT_NAME IS NOT NULL
                ORDER BY CONSTRAINT_NAME
            ");
            $stmt->execute([$dbname]);
            $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "constraints" => $constraints,
                "message" => "Found " . count($constraints) . " constraints"
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'view_all_products':
        try {
            $stmt = $conn->prepare("
                SELECT product_id, product_name, category_id, location_id, quantity, barcode, status
                FROM tbl_product 
                ORDER BY product_name, category_id, location_id
                LIMIT 100
            ");
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "products" => $products,
                "message" => "Found " . count($products) . " products"
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'test_transfer_logic':
            try {
                $product_name = $data['product_name'] ?? '';
                $category = $data['category'] ?? '';
                $location_id = $data['location_id'] ?? 0;
                
                // Test the logic that checks for existing products
                $checkStmt = $conn->prepare("
                    SELECT product_id, quantity, product_name, category_id, location_id
                    FROM tbl_product 
                    WHERE product_name = ? AND category_id = ? AND location_id = ?
                ");
                $checkStmt->execute([$product_name, $category, $location_id]);
                $existingProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingProduct) {
                    echo json_encode([
                        "success" => true,
                        "message" => "Product found! ID: {$existingProduct['product_id']}, Current Qty: {$existingProduct['quantity']}. This would be UPDATED during transfer.",
                        "product" => $existingProduct
                    ]);
                } else {
                    echo json_encode([
                        "success" => true,
                        "message" => "No product found with name '$product_name' in category '$category' at location $location_id. This would be CREATED during transfer.",
                        "search_criteria" => [
                            "product_name" => $product_name,
                            "category" => $category,
                            "location_id" => $location_id
                        ]
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage()
                ]);
            }
            break;
    case 'add_batch_entry':
        try {
            // Extract batch data
            $batch_reference = $data['batch_reference'] ?? '';
            $batch_date = $data['batch_date'] ?? date('Y-m-d');
            $total_products = $data['total_products'] ?? 0;
            $total_quantity = $data['total_quantity'] ?? 0;
            $total_value = $data['total_value'] ?? 0;
            $location = $data['location'] ?? 'Warehouse';
            $entry_by = $data['entry_by'] ?? 'System';
            $status = $data['status'] ?? 'active';
            $products = $data['products'] ?? [];
            
            if (empty($batch_reference)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Batch reference is required"
                ]);
                break;
            }
            
            // Check if batch reference already exists
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_batch WHERE batch_reference = ?");
            $checkStmt->execute([$batch_reference]);
            $existingCount = $checkStmt->fetchColumn();
            
            if ($existingCount > 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "Batch reference '$batch_reference' already exists. Please use a unique reference."
                ]);
                break;
            }
            
            if (empty($products)) {
                echo json_encode([
                    "success" => false,
                    "message" => "No products to add"
                ]);
                break;
            }
            
            // Validate each product in the batch
            foreach ($products as $index => $product) {
                if (empty($product['product_name'])) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Product name is required for product at index $index"
                    ]);
                    break 2; // Break out of both loops
                }
                
                if (empty($product['category_id'])) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Category ID is required for product '{$product['product_name']}'"
                    ]);
                    break 2;
                }
                
                if (empty($product['barcode'])) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Barcode is required for product '{$product['product_name']}'"
                    ]);
                    break 2;
                }
                
                if (!isset($product['quantity']) || $product['quantity'] <= 0) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Valid quantity is required for product '{$product['product_name']}'"
                    ]);
                    break 2;
                }
                
                if (!isset($product['srp']) || $product['srp'] < 0) {
                    echo json_encode([
                        "success" => false,
                        "message" => "Valid SRP is required for product '{$product['product_name']}'"
                    ]);
                    break 2;
                }
            }
            
            // Calculate totals from products to verify against provided totals
            $calculated_total_products = count($products);
            $calculated_total_quantity = array_sum(array_column($products, 'quantity'));
            $calculated_total_value = 0;
            
            foreach ($products as $product) {
                $calculated_total_value += ($product['quantity'] * $product['srp']);
            }
            
            // Log the calculated vs provided totals for debugging
            error_log("Batch totals - Calculated: Products=$calculated_total_products, Quantity=$calculated_total_quantity, Value=$calculated_total_value");
            error_log("Batch totals - Provided: Products=$total_products, Quantity=$total_quantity, Value=$total_value");
            
            // Start transaction
            $conn->beginTransaction();
            
            try {
                // 1. Insert batch entry
                $batchStmt = $conn->prepare("
                    INSERT INTO tbl_batch (
                        date, batch, batch_reference, supplier_id, location_id, 
                        entry_date, entry_time, entry_by, order_no
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, CURTIME(), ?, ?
                    )
                ");
                
                $supplier_id = 1; // Default supplier
                $location_id = 2; // Warehouse location
                $order_no = ''; // Can be updated later
                
                // Debug: Log batch parameters
                $batchParams = [
                    $batch_date,
                    $batch_reference,
                    $batch_reference,
                    $supplier_id,
                    $location_id,
                    $batch_date,
                    $entry_by,
                    $order_no
                ];
                
                error_log("Batch parameters count: " . count($batchParams));
                error_log("Batch parameters: " . json_encode($batchParams));
                
                $batchStmt->execute($batchParams);
                
                $batch_id = $conn->lastInsertId();
                
                // 2. Insert all products with the same batch_id - NO quantity/srp in tbl_product!
                $productStmt = $conn->prepare("
                    INSERT INTO tbl_product (
                        product_name, category_id, barcode, description, 
                        brand_id, supplier_id, location_id, batch_id, status, date_added,
                        product_type, bulk, prescription
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");
                
                $successCount = 0;
                foreach ($products as $product) {
                    try {
                        // Validate required fields
                        if (empty($product['product_name']) || empty($product['category_id']) || empty($product['barcode'])) {
                            throw new Exception("Missing required product fields: product_name, category_id, or barcode");
                        }
                        
                        if (!isset($product['quantity']) || $product['quantity'] <= 0) {
                            throw new Exception("Invalid quantity for product: " . $product['product_name']);
                        }
                        
                        if (!isset($product['srp']) || $product['srp'] < 0) {
                            throw new Exception("Invalid SRP for product: " . $product['product_name']);
                        }
                        
                        // Handle brand creation if brand_id is not provided but brand_name is
                        $brand_id = $product['brand_id'] ?? null;
                        if (!$brand_id && !empty($product['brand_name'])) {
                            // Check if brand already exists
                            $brandCheckStmt = $conn->prepare("SELECT brand_id FROM tbl_brand WHERE brand = ?");
                            $brandCheckStmt->execute([$product['brand_name']]);
                            $existingBrandId = $brandCheckStmt->fetchColumn();
                            
                            if ($existingBrandId) {
                                $brand_id = $existingBrandId;
                            } else {
                                // Create new brand
                                $brandInsertStmt = $conn->prepare("INSERT INTO tbl_brand (brand) VALUES (?)");
                                $brandInsertStmt->execute([$product['brand_name']]);
                                $brand_id = $conn->lastInsertId();
                                error_log("Created new brand: {$product['brand_name']} with ID: $brand_id");
                            }
                        }
                        
                        // Default to brand_id 1 if no brand specified
                        if (!$brand_id) {
                            $brand_id = 1;
                        }
                        
                        // Get product_type and bulk configuration
                        $product_type = $product['product_type'] ?? 'Non-Medicine';
                        $bulk = isset($product['bulk']) ? (int)$product['bulk'] : 0;
                        $prescription = isset($product['prescription']) ? (int)$product['prescription'] : 0;
                        
                        // NO quantity/srp in tbl_product - they go ONLY to tbl_fifo_stock!
                        $productParams = [
                            $product['product_name'],
                            $product['category_id'],
                            $product['barcode'],
                            $product['description'] ?? '',
                            $brand_id,
                            $product['supplier_id'] ?? 1,
                            $location_id,
                            $batch_id,
                            'active',
                            date('Y-m-d'),
                            $product_type,
                            $bulk,
                            $prescription
                        ];
                        
                        error_log("Product parameters count: " . count($productParams));
                        error_log("Product parameters: " . json_encode($productParams));
                        error_log("Product type being saved: " . $product_type);
                        
                        $productStmt->execute($productParams);
                        
                        $product_id = $conn->lastInsertId();
                        
                        // 3. Create FIFO stock entry for each product with quantity and srp
                        $fifoStmt = $conn->prepare("
                            INSERT INTO tbl_fifo_stock (
                                product_id, batch_id, batch_reference, quantity, available_quantity, srp,
                                expiration_date, entry_date, entry_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
                        ");
                        
                        $fifoParams = [
                            $product_id,
                            $batch_id,
                            $batch_reference,
                            $product['quantity'],
                            $product['quantity'], // available_quantity starts equal to quantity
                            $product['srp'] ?? 0,
                            $product['expiration'] ?? null,
                            $entry_by
                        ];
                        
                        error_log("FIFO parameters count: " . count($fifoParams));
                        error_log("FIFO parameters: " . json_encode($fifoParams));
                        
                        $fifoStmt->execute($fifoParams);
                        
                        // 4. Insert unit configuration into tbl_product_units for Medicine products with bulk mode
                        if ($product_type === 'Medicine' && isset($product['configMode']) && $product['configMode'] === 'bulk') {
                            // Check if we have the necessary bulk configuration data
                            if (isset($product['boxes']) && isset($product['strips_per_box']) && isset($product['tablets_per_strip'])) {
                                $boxes = (int)$product['boxes'];
                                $strips_per_box = (int)$product['strips_per_box'];
                                $tablets_per_strip = (int)$product['tablets_per_strip'];
                                
                                // Calculate unit prices based on SRP (which is the price per tablet)
                                $srp = (float)($product['srp'] ?? 0);
                                $strip_price = $srp * $tablets_per_strip;
                                $box_price = $strip_price * $strips_per_box;
                                
                                error_log("Inserting medicine units - Boxes: $boxes, Strips/Box: $strips_per_box, Tablets/Strip: $tablets_per_strip");
                                error_log("Unit prices - Tablet: $srp, Strip: $strip_price, Box: $box_price");
                                
                                // Insert base unit (tablet)
                                $unitStmt = $conn->prepare("
                                    INSERT INTO tbl_product_units (
                                        product_id, unit_name, unit_quantity, unit_price, is_base_unit, status
                                    ) VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                
                                // Tablet (base unit)
                                $unitStmt->execute([$product_id, 'tablet', 1, $srp, 1, 'active']);
                                error_log("Inserted tablet unit for product_id: $product_id");
                                
                                // Strip
                                $unitStmt->execute([$product_id, 'strip', $tablets_per_strip, $strip_price, 0, 'active']);
                                error_log("Inserted strip unit for product_id: $product_id");
                                
                                // Box
                                $total_tablets_per_box = $strips_per_box * $tablets_per_strip;
                                $unitStmt->execute([$product_id, 'box', $total_tablets_per_box, $box_price, 0, 'active']);
                                error_log("Inserted box unit for product_id: $product_id");
                                
                                // Update product to enable multi-unit and set default unit
                                $updateProductStmt = $conn->prepare("
                                    UPDATE tbl_product 
                                    SET allow_multi_unit = 1, default_unit = 'tablet'
                                    WHERE product_id = ?
                                ");
                                $updateProductStmt->execute([$product_id]);
                                error_log("Updated product $product_id to enable multi-unit");
                            } else {
                                error_log("Warning: Medicine product '{$product['product_name']}' has bulk mode but missing unit configuration data");
                            }
                        }
                        // Handle Non-Medicine products with bulk configuration
                        else if ($product_type === 'Non-Medicine' && isset($product['configMode']) && $product['configMode'] === 'bulk') {
                            if (isset($product['boxes']) && isset($product['pieces_per_pack'])) {
                                $boxes = (int)$product['boxes'];
                                $pieces_per_pack = (int)$product['pieces_per_pack'];
                                
                                // Calculate unit prices
                                $srp = (float)($product['srp'] ?? 0);
                                $box_price = $srp * $pieces_per_pack;
                                
                                error_log("Inserting non-medicine units - Boxes: $boxes, Pieces/Box: $pieces_per_pack");
                                error_log("Unit prices - Piece: $srp, Box: $box_price");
                                
                                $unitStmt = $conn->prepare("
                                    INSERT INTO tbl_product_units (
                                        product_id, unit_name, unit_quantity, unit_price, is_base_unit, status
                                    ) VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                
                                // Piece (base unit)
                                $unitStmt->execute([$product_id, 'piece', 1, $srp, 1, 'active']);
                                error_log("Inserted piece unit for product_id: $product_id");
                                
                                // Box
                                $unitStmt->execute([$product_id, 'box', $pieces_per_pack, $box_price, 0, 'active']);
                                error_log("Inserted box unit for product_id: $product_id");
                                
                                // Update product to enable multi-unit
                                $updateProductStmt = $conn->prepare("
                                    UPDATE tbl_product 
                                    SET allow_multi_unit = 1, default_unit = 'piece'
                                    WHERE product_id = ?
                                ");
                                $updateProductStmt->execute([$product_id]);
                                error_log("Updated product $product_id to enable multi-unit");
                            } else {
                                error_log("Warning: Non-Medicine product '{$product['product_name']}' has bulk mode but missing unit configuration data");
                            }
                        }
                        
                        $successCount++;
                    } catch (Exception $e) {
                        error_log("Error inserting product {$product['product_name']}: " . $e->getMessage());
                        error_log("Product data: " . json_encode($product));
                        throw $e;
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Batch created successfully with $successCount products",
                    "batch_id" => $batch_id,
                    "batch_reference" => $batch_reference,
                    "total_products" => $successCount,
                    "total_quantity" => $calculated_total_quantity,
                    "total_value" => $calculated_total_value
                ]);
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                error_log("Batch creation failed: " . $e->getMessage());
                error_log("Batch data: " . json_encode($data));
                throw $e;
            }
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

        case 'sync_fifo_stock':
        try {
            // This function syncs FIFO stock available_quantity with product quantity
            $product_id = $data['product_id'] ?? 0;
            
            if ($product_id > 0) {
                // NO LONGER NEEDED - tbl_product.quantity column was removed
                // Product quantities are now managed purely through tbl_fifo_stock
                // Only update stock_status based on FIFO stock
                $syncStmt = $conn->prepare("
                    UPDATE tbl_product p
                    SET p.stock_status = CASE 
                        WHEN (
                            SELECT COALESCE(SUM(fs.available_quantity), 0)
                            FROM tbl_fifo_stock fs
                            WHERE fs.product_id = p.product_id
                        ) <= 0 THEN 'out of stock'
                        WHEN (
                            SELECT COALESCE(SUM(fs.available_quantity), 0)
                            FROM tbl_fifo_stock fs
                            WHERE fs.product_id = p.product_id
                        ) <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END
                    WHERE p.product_id = ?
                ");
                $syncStmt->execute([$product_id]);
                
                $affectedRows = $syncStmt->rowCount();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Product quantity synced with FIFO stock for product ID $product_id. $affectedRows rows updated.",
                    "product_id" => $product_id
                ]);
            } else {
                // Sync all products - ONLY update stock_status (NO quantity column!)
                $syncAllStmt = $conn->prepare("
                    UPDATE tbl_product p
                    SET p.stock_status = CASE 
                        WHEN (
                            SELECT COALESCE(SUM(fs.available_quantity), 0)
                            FROM tbl_fifo_stock fs
                            WHERE fs.product_id = p.product_id
                        ) <= 0 THEN 'out of stock'
                        WHEN (
                            SELECT COALESCE(SUM(fs.available_quantity), 0)
                            FROM tbl_fifo_stock fs
                            WHERE fs.product_id = p.product_id
                        ) <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END
                ");
                $syncAllStmt->execute();
                
                $affectedRows = $syncAllStmt->rowCount();
                
                echo json_encode([
                    "success" => true,
                    "message" => "All product quantities synced with FIFO stock. $affectedRows rows updated.",
                    "affected_rows" => $affectedRows
                ]);
            }
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'force_sync_all_products':
        try {
            // Force sync all products with FIFO stock - ONLY update stock_status (NO quantity!)
            $syncAllStmt = $conn->prepare("
                UPDATE tbl_product p
                SET p.stock_status = CASE 
                    WHEN (
                        SELECT COALESCE(SUM(fs.available_quantity), 0)
                        FROM tbl_fifo_stock fs
                        WHERE fs.product_id = p.product_id
                    ) <= 0 THEN 'out of stock'
                    WHEN (
                        SELECT COALESCE(SUM(fs.available_quantity), 0)
                        FROM tbl_fifo_stock fs
                        WHERE fs.product_id = p.product_id
                    ) <= 10 THEN 'low stock'
                    ELSE 'in stock'
                END
                WHERE p.product_id IN (
                    SELECT DISTINCT fs.product_id 
                    FROM tbl_fifo_stock fs
                )
            ");
            $syncAllStmt->execute();
            
            $affectedRows = $syncAllStmt->rowCount();
            
            echo json_encode([
                "success" => true,
                "message" => "Force synced all products with FIFO stock. $affectedRows products updated.",
                "affected_rows" => $affectedRows
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'cleanup_duplicate_transfer_products':
        try {
            // Remove duplicate products that were incorrectly created during transfers
            // Keep only the original products in their original locations
            $cleanupStmt = $conn->prepare("
                DELETE p FROM tbl_product p
                WHERE p.product_id NOT IN (
                    SELECT original_product_id FROM (
                        SELECT MIN(p2.product_id) as original_product_id
                        FROM tbl_product p2
                        GROUP BY p2.product_name, p2.category, p2.barcode, p2.location_id
                    ) as original_products
                )
                AND p.product_id IN (
                    SELECT p3.product_id 
                    FROM tbl_product p3
                    WHERE p3.product_id NOT IN (
                        SELECT DISTINCT fs.product_id 
                        FROM tbl_fifo_stock fs
                    )
                )
            ");
            $cleanupStmt->execute();
            
            $affectedRows = $cleanupStmt->rowCount();
            
            echo json_encode([
                "success" => true,
                "message" => "Cleaned up $affectedRows duplicate transfer products. Only original products remain.",
                "affected_rows" => $affectedRows
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'test_logging':
        try {
            // Add test log entry for debugging
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(255) NULL,
                role VARCHAR(100) NULL,
                activity_type VARCHAR(100) NOT NULL,
                activity_description TEXT NULL,
                table_name VARCHAR(255) NULL,
                record_id INT NULL,
                date_created DATE NOT NULL,
                time_created TIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $stmt = $conn->prepare("INSERT INTO tbl_activity_log (activity_type, activity_description, username, role, date_created, time_created) VALUES (?, ?, ?, ?, CURDATE(), CURTIME())");
            $stmt->execute([
                'TEST_LOG_ENTRY',
                'Manual test log entry created at ' . date('Y-m-d H:i:s'),
                'admin',
                'Admin'
            ]);
            
            echo json_encode(["success" => true, "message" => "Test log entry created successfully"]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error creating test log: " . $e->getMessage()]);
        }
        break;
        
    // Report Management
    case 'get_report_data':
        try {
            require_once 'modules/reports.php';
            
            $reportType = $data['report_type'] ?? 'sales';
            $startDate = $data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $data['end_date'] ?? date('Y-m-d');
            $checkForUpdates = $data['check_for_updates'] ?? false;
            
            // Get user session data for role-based filtering
            $userData = null;
            if (isset($_SESSION['user_id'])) {
                $userData = [
                    'user_id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'] ?? '',
                    'role' => $_SESSION['role'] ?? '',
                    'full_name' => $_SESSION['full_name'] ?? ''
                ];
            }
            
            $reportsModule = new ReportsModule($conn);
            $result = $reportsModule->getReportData($reportType, $startDate, $endDate, $checkForUpdates, $userData);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error fetching report data: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'generate_report':
        try {
            require_once 'modules/reports.php';
            
            $reportType = $data['report_type'] ?? 'sales';
            $generatedBy = $data['generated_by'] ?? 'Admin';
            $parameters = $data['parameters'] ?? [];
            
            $reportsModule = new ReportsModule($conn);
            $result = $reportsModule->generateReport($reportType, $generatedBy, $parameters);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error generating report: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_report_details':
        try {
            require_once 'modules/reports.php';
            
            $reportId = $data['report_id'] ?? null;
            
            if (!$reportId) {
                echo json_encode([
                    "success" => false,
                    "message" => "Report ID is required"
                ]);
                break;
            }
            
            $reportsModule = new ReportsModule($conn);
            $result = $reportsModule->getReportDetails($reportId);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error fetching report details: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_cashier_details':
        try {
            require_once 'modules/reports.php';
            
            $cashierId = $data['cashier_id'] ?? null;
            $startDate = $data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $data['end_date'] ?? date('Y-m-d');
            
            if (!$cashierId) {
                echo json_encode([
                    "success" => false,
                    "message" => "Cashier ID is required"
                ]);
                break;
            }
            
            $reportsModule = new ReportsModule($conn);
            $result = $reportsModule->getCashierDetails($cashierId, $startDate, $endDate);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error fetching cashier details: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'check_new_sales':
        try {
            require_once 'modules/reports.php';
            
            $since = $data['since'] ?? date('Y-m-d H:i:s', strtotime('-5 minutes'));
            
            $reportsModule = new ReportsModule($conn);
            $result = $reportsModule->checkNewSales($since);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error checking new sales: " . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_latest_sales_activity':
        try {
            require_once 'modules/reports.php';
            
            $minutes = $data['minutes'] ?? 5;
            
            $reportsModule = new ReportsModule($conn);
            $result = $reportsModule->getLatestSalesActivity($minutes);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error fetching latest sales activity: " . $e->getMessage()
            ]);
        }
        break;
        
    // Notification Services
    case 'get_warehouse_notifications':
        try {
            $lowStockThreshold = $data['low_stock_threshold'] ?? 10;
            $expiryWarningDays = $data['expiry_warning_days'] ?? 30;
            
            // Get low stock items
            $lowStockStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_product 
                WHERE quantity <= ? AND quantity > 0 AND (status IS NULL OR status <> 'archived')
            ");
            $lowStockStmt->execute([$lowStockThreshold]);
            $lowStock = $lowStockStmt->fetch()['count'];
            
            // Get out of stock items
            $outOfStockStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_product 
                WHERE quantity = 0 AND (status IS NULL OR status <> 'archived')
            ");
            $outOfStockStmt->execute();
            $outOfStock = $outOfStockStmt->fetch()['count'];
            
            // Get expiring items
            $expiringStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_product 
                WHERE expiration IS NOT NULL 
                AND DATEDIFF(expiration, CURDATE()) <= ? 
                AND DATEDIFF(expiration, CURDATE()) >= 0
                AND (status IS NULL OR status <> 'archived')
            ");
            $expiringStmt->execute([$expiryWarningDays]);
            $expiring = $expiringStmt->fetch()['count'];
            
            // Get expired items
            $expiredStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_product 
                WHERE expiration IS NOT NULL 
                AND DATEDIFF(expiration, CURDATE()) < 0
                AND (status IS NULL OR status <> 'archived')
            ");
            $expiredStmt->execute();
            $expired = $expiredStmt->fetch()['count'];
            
            // Get warehouse-specific data
            $warehousesStmt = $conn->prepare("
                SELECT 
                    l.location_id,
                    l.location_name,
                    COUNT(p.product_id) as total_products,
                    SUM(CASE WHEN (SELECT COALESCE(SUM(fs.available_quantity), 0) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id) BETWEEN 1 AND ? THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN p.product_id NOT IN (SELECT DISTINCT product_id FROM tbl_fifo_stock WHERE available_quantity > 0) THEN 1 ELSE 0 END) as out_of_stock,
                    SUM(CASE WHEN p.expiration IS NOT NULL AND DATEDIFF(p.expiration, CURDATE()) <= ? AND DATEDIFF(p.expiration, CURDATE()) >= 0 THEN 1 ELSE 0 END) as expiring,
                    SUM(CASE WHEN p.expiration IS NOT NULL AND DATEDIFF(p.expiration, CURDATE()) < 0 THEN 1 ELSE 0 END) as expired
                FROM tbl_location l
                LEFT JOIN tbl_product p ON l.location_id = p.location_id AND (p.status IS NULL OR p.status <> 'archived')
                GROUP BY l.location_id, l.location_name
            ");
            $warehousesStmt->execute([$lowStockThreshold, $expiryWarningDays]);
            $warehouses = $warehousesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $warehousesData = [];
            foreach ($warehouses as $warehouse) {
                $warehousesData[$warehouse['location_id']] = [
                    'name' => $warehouse['location_name'],
                    'lowStock' => (int)$warehouse['low_stock'],
                    'outOfStock' => (int)$warehouse['out_of_stock'],
                    'expiring' => (int)$warehouse['expiring'],
                    'expired' => (int)$warehouse['expired'],
                    'totalProducts' => (int)$warehouse['total_products']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'totals' => [
                        'lowStock' => (int)$lowStock,
                        'outOfStock' => (int)$outOfStock,
                        'expiring' => (int)$expiring,
                        'expired' => (int)$expired
                    ],
                    'warehouses' => $warehousesData
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching warehouse notifications: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'check_system_updates':
        try {
            $hours = $data['hours'] ?? 1;
            
            // Check for recent stock movements
            $stockMovementsStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_stock_movements 
                WHERE movement_date >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stockMovementsStmt->execute([$hours]);
            $stockMovements = $stockMovementsStmt->fetch()['count'];
            
            // Check for recent sales
            $salesStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_pos_sales_header psh
                LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
                WHERE pt.date >= DATE_SUB(CURDATE(), INTERVAL ? HOUR)
            ");
            $salesStmt->execute([$hours]);
            $sales = $salesStmt->fetch()['count'];
            
            // Check for recent activity logs
            $activityStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_activity_log 
                WHERE date_created >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $activityStmt->execute([$hours]);
            $activities = $activityStmt->fetch()['count'];
            
            $totalUpdates = $stockMovements + $sales + $activities;
            $hasUpdates = $totalUpdates > 0;
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'hasUpdates' => $hasUpdates,
                    'updateCount' => (int)$totalUpdates,
                    'reportUpdates' => [
                        'stockMovements' => (int)$stockMovements,
                        'sales' => (int)$sales,
                        'activities' => (int)$activities
                    ]
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error checking system updates: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_activity_summary':
        try {
            $hours = $data['hours'] ?? 1;
            
            // Get stock in activities
            $stockInStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_stock_movements 
                WHERE movement_type = 'IN' 
                AND movement_date >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stockInStmt->execute([$hours]);
            $stockIn = $stockInStmt->fetch()['count'];
            
            // Get stock out activities
            $stockOutStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_stock_movements 
                WHERE movement_type = 'OUT' 
                AND movement_date >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stockOutStmt->execute([$hours]);
            $stockOut = $stockOutStmt->fetch()['count'];
            
            // Get sales activities
            $salesStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tbl_pos_sales_header psh
                LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
                WHERE pt.date >= DATE_SUB(CURDATE(), INTERVAL ? HOUR)
            ");
            $salesStmt->execute([$hours]);
            $sales = $salesStmt->fetch()['count'];
            
            $total = $stockIn + $stockOut + $sales;
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'stock_in' => (int)$stockIn,
                    'stock_out' => (int)$stockOut,
                    'sales' => (int)$sales,
                    'total' => (int)$total
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching activity summary: ' . $e->getMessage()
            ]);
        }
        break;
        
    // Activity Logging
    case 'record_activity':
        try {
            $activityType = $data['activity_type'] ?? 'UNKNOWN';
            $description = $data['description'] ?? '';
            $tableName = $data['table_name'] ?? null;
            $recordId = $data['record_id'] ?? null;
            
            // Get current user info (you might need to adjust this based on your session handling)
            $username = 'Admin'; // Default for now
            $role = 'Administrator'; // Default for now
            
            $stmt = $conn->prepare("
                INSERT INTO tbl_activity_log 
                (username, role, activity_type, activity_description, table_name, record_id, date_created, time_created) 
                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME())
            ");
            
            $stmt->execute([$username, $role, $activityType, $description, $tableName, $recordId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Activity logged successfully',
                'activity_id' => $conn->lastInsertId()
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error logging activity: ' . $e->getMessage()
            ]);
        }
        break;

    // Store Settings Management
    case 'get_store_settings':
        try {
            // Check if store_settings table exists, if not create it
            $checkTable = $conn->prepare("SHOW TABLES LIKE 'store_settings'");
            $checkTable->execute();
            
            if ($checkTable->rowCount() == 0) {
                // Create store_settings table
                $createTable = $conn->prepare("
                    CREATE TABLE store_settings (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        setting_key VARCHAR(100) UNIQUE NOT NULL,
                        setting_value TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    )
                ");
                $createTable->execute();
                
                // Insert default settings
                $defaultSettings = [
                    'store_name' => 'Enguio Pharmacy',
                    'store_address' => '',
                    'store_phone' => '',
                    'store_email' => '',
                    'tax_rate' => '12',
                    'currency' => 'PHP',
                    'timezone' => 'Asia/Manila',
                    'notifications' => json_encode([
                        'low_stock' => true,
                        'expiry_alerts' => true,
                        'sales_reports' => true,
                        'system_updates' => true
                    ])
                ];
                
                foreach ($defaultSettings as $key => $value) {
                    $insertStmt = $conn->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?)");
                    $insertStmt->execute([$key, $value]);
                }
            }
            
            // Get all settings
            $stmt = $conn->prepare("SELECT setting_key, setting_value FROM store_settings");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Parse JSON settings
            $parsedSettings = [];
            foreach ($settings as $key => $value) {
                if (in_array($key, ['notifications'])) {
                    $parsedSettings[$key] = json_decode($value, true);
                } else {
                    $parsedSettings[$key] = $value;
                }
            }
            
            echo json_encode([
                'success' => true,
                'settings' => $parsedSettings
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching store settings: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'update_store_settings':
        try {
            // Check if store_settings table exists
            $checkTable = $conn->prepare("SHOW TABLES LIKE 'store_settings'");
            $checkTable->execute();
            
            if ($checkTable->rowCount() == 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Store settings table does not exist'
                ]);
                break;
            }
            
            // Update settings
            $settingsToUpdate = [
                'store_name', 'store_address', 'store_phone', 'store_email', 
                'tax_rate', 'currency', 'timezone', 'notifications'
            ];
            
            foreach ($settingsToUpdate as $setting) {
                if (isset($data[$setting])) {
                    $value = $data[$setting];
                    
                    // Convert arrays to JSON
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO store_settings (setting_key, setting_value) 
                        VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    $stmt->execute([$setting, $value]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Store settings updated successfully'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error updating store settings: ' . $e->getMessage()
            ]);
        }
        break;

 
    case 'get_dashboard_data':
        try {
            // Get real data from separate API files
            $transferData = getDashboardDataFromAPI('dashboard_transfer_api.php', 'get_total_transfer');
            $salesData = getDashboardDataFromAPI('dashboard_sales_api.php', 'get_total_sales');
            $returnData = getDashboardDataFromAPI('dashboard_return_api.php', 'get_total_return');
            $paymentMethodsData = getDashboardDataFromAPI('dashboard_sales_api.php', 'get_payment_methods');
            
            // If helper APIs returned empty, compute simple fallbacks directly from DB
            if (empty($transferData)) {
                try {
                    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_transfer_header");
                    $stmt->execute();
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
                    $transferData = [
                        'total_transfer' => (string)($row['total'] ?? 0),
                        'trend' => '0.0%',
                        'trend_direction' => 'up',
                    ];

                    // summary over last 10 days
                    $sumStmt = $conn->prepare("SELECT DATE_FORMAT(date, '%d') AS day, COUNT(*) AS totalTransfer FROM tbl_transfer_header WHERE date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY) GROUP BY DATE(date) ORDER BY DATE(date)");
                    $sumStmt->execute();
                    $transferChartRows = $sumStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $transferChartData = [ 'data' => array_map(function($r){ return [ 'day' => $r['day'], 'totalTransfer' => (float)$r['totalTransfer'] ]; }, $transferChartRows) ];
                } catch (Exception $e) {
                    error_log('Transfer fallback failed: ' . $e->getMessage());
                }
            }

            if (empty($salesData)) { $salesData = ['total_sales' => '0.00', 'trend' => '0.0%', 'trend_direction' => 'up']; }
            if (empty($returnData)) { $returnData = ['total_return' => '0.00', 'trend' => '0.0%', 'trend_direction' => 'up']; }
            if (empty($paymentMethodsData)) { $paymentMethodsData = ['data' => []]; }

            // Build summary cards from real data or fallbacks
            $summaryCards = [
                [
                    'title' => 'Total Transfer',
                    'value' => $transferData['total_transfer'] ?? '0.00',
                    'trend' => $transferData['trend'] ?? '0.0%',
                    'trendDirection' => $transferData['trend_direction'] ?? 'up'
                ],
                [
                    'title' => 'Total Sales', 
                    'value' => $salesData['total_sales'] ?? '0.00',
                    'trend' => $salesData['trend'] ?? '0.0%',
                    'trendDirection' => $salesData['trend_direction'] ?? 'up'
                ],
                [
                    'title' => 'Total Return',
                    'value' => $returnData['total_return'] ?? '0.00',
                    'trend' => $returnData['trend'] ?? '0.0%',
                    'trendDirection' => $returnData['trend_direction'] ?? 'up'
                ]
            ];

            // Get chart data - use direct database queries instead of API calls
            $salesChartData = getSalesChartDataDirect($conn, 7);
            $transferChartData = isset($transferChartData) ? $transferChartData : getTransferChartDataDirect($conn, 7);
            $returnChartData = getReturnChartDataDirect($conn, 7);
            
            // Get top selling products - call function directly
            $topProductsData = getTopSellingProductsDirect($conn, 5);
            
            // Get inventory alerts
            $inventoryAlertsData = getInventoryAlertsDirect($conn);
            
            // Combine chart data
            $combinedChartData = [];
            $chartDays = ['02', '03', '04', '05', '06', '07', '08', '09', '10', '11'];
            
            foreach ($chartDays as $day) {
                $salesAmount = 0;
                $transferAmount = 0;
                $returnAmount = 0;
                
                // Find matching data from API responses
                if (isset($salesChartData['data'])) {
                    foreach ($salesChartData['data'] as $item) {
                        if ($item['day'] === $day) {
                            $salesAmount = $item['totalSales'];
                            break;
                        }
                    }
                }
                
                if (isset($transferChartData['data'])) {
                    foreach ($transferChartData['data'] as $item) {
                        if ($item['day'] === $day) {
                            $transferAmount = $item['totalTransfer'];
                            break;
                        }
                    }
                }
                
                if (isset($returnChartData['data'])) {
                    foreach ($returnChartData['data'] as $item) {
                        if ($item['day'] === $day) {
                            $returnAmount = $item['totalReturn'];
                            break;
                        }
                    }
                }
                
                $combinedChartData[] = [
                    'day' => $day,
                    'totalTransfer' => $transferAmount,
                    'totalSales' => $salesAmount,
                    'totalReturn' => $returnAmount
                ];
            }

            // Use the inventory alerts data we already fetched
            $inventoryAlerts = $inventoryAlertsData;

            echo json_encode([
                'success' => true,
                'data' => [
                    'summaryCards' => $summaryCards,
                    'salesData' => $combinedChartData,
                    'paymentMethods' => $paymentMethodsData['data'] ?? [],
                    'topProducts' => $topProductsData,
                    'inventoryAlerts' => $inventoryAlerts,
                    'employeePerformance' => [] // Will be populated from actual employee data
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching dashboard data: ' . $e->getMessage()
            ]);
        }
        break;

    case 'get_admin_employee_info':
        require_once __DIR__ . '/modules/admin.php';
        handle_get_admin_employee_info($conn, $data);
        break;

    case 'update_admin_name':
        require_once __DIR__ . '/modules/admin.php';
        handle_update_admin_name($conn, $data);
        break;

    case 'update_admin_employee_info':
        require_once __DIR__ . '/modules/admin.php';
        handle_update_admin_employee_info($conn, $data);
        break;

    case 'change_admin_password':
        require_once __DIR__ . '/modules/admin.php';
        handle_change_admin_password($conn, $data);
        break;

    case 'update_current_user_info':
        require_once __DIR__ . '/modules/admin.php';
        handle_update_current_user_info($conn, $data);
        break;

    case 'change_current_user_password':
        require_once __DIR__ . '/modules/admin.php';
        handle_change_current_user_password($conn, $data);
        break;

    case 'get_product_units':
        try {
            $product_id = $data['product_id'] ?? 0;
            
            if (!$product_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product ID is required"
                ]);
                break;
            }
            
            // Get product details first to check product_type
            $productStmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.product_type,
                    p.allow_multi_unit,
                    p.default_unit
                FROM tbl_product p
                WHERE p.product_id = ?
            ");
            $productStmt->execute([$product_id]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product not found"
                ]);
                break;
            }
            
            // Get units from tbl_product_units
            $unitsStmt = $conn->prepare("
                SELECT 
                    unit_id,
                    product_id,
                    unit_name,
                    unit_quantity,
                    unit_price,
                    is_base_unit,
                    barcode,
                    status
                FROM tbl_product_units
                WHERE product_id = ? AND status = 'active'
                ORDER BY is_base_unit DESC, unit_quantity ASC
            ");
            $unitsStmt->execute([$product_id]);
            $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no units found, return default units based on product type
            if (empty($units)) {
                if ($product['product_type'] === 'Medicine') {
                    $units = [
                        [
                            'unit_id' => null,
                            'unit_name' => 'tablet',
                            'unit_quantity' => 1,
                            'unit_price' => 0,
                            'is_base_unit' => 1,
                            'status' => 'active'
                        ]
                    ];
                } else {
                    $units = [
                        [
                            'unit_id' => null,
                            'unit_name' => 'piece',
                            'unit_quantity' => 1,
                            'unit_price' => 0,
                            'is_base_unit' => 1,
                            'status' => 'active'
                        ]
                    ];
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => [
                    "product" => $product,
                    "units" => $units
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

} // End of switch statement

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}

// Flush the output buffer to ensure clean JSON response
ob_end_flush();
?>  