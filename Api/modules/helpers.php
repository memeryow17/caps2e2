<?php
// Helper functions
// Note: Database connection is in conn.php. Include that file before using these helpers.

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

// Setup common headers and session
function setupApiEnvironment() {
    // Start output buffering to prevent unwanted output
    ob_start();

    session_start();

    // CORS and content-type headers
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Content-Type: application/json");

    // Disable error display to prevent HTML in JSON response
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    // Log errors to a file for debugging
    ini_set('log_errors', 1);
    ini_set('error_log', 'php_errors.log');
}

// Validate and decode JSON input
function getJsonInput() {
    $rawData = file_get_contents("php://input");
    error_log("Raw input: " . $rawData);

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

    return $data;
}

// Validate that action is provided
function validateAction($data) {
    if (!isset($data['action'])) {
        echo json_encode([
            "success" => false,
            "message" => "Missing action"
        ]);
        exit;
    }

    return $data['action'];
}

// Clean output buffer
function cleanOutputBuffer() {
    ob_clean();
}

// Send JSON response and exit
function sendJsonResponse($response) {
    echo json_encode($response);
    exit;
}

// Error response helper
function sendErrorResponse($message, $additionalData = null) {
    $response = [
        "success" => false,
        "message" => $message
    ];

    if ($additionalData) {
        $response = array_merge($response, $additionalData);
    }

    sendJsonResponse($response);
}

// Success response helper
function sendSuccessResponse($message = null, $data = null) {
    $response = ["success" => true];

    if ($message) {
        $response["message"] = $message;
    }

    if ($data) {
        $response["data"] = $data;
    }

    sendJsonResponse($response);
}

// Validate required fields
function validateRequiredFields($data, $requiredFields) {
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendErrorResponse("Missing required field: {$field}");
        }
    }
}

// Sanitize string input
function sanitizeString($input) {
    return isset($input) ? trim($input) : '';
}

// Sanitize integer input
function sanitizeInt($input, $default = 0) {
    return isset($input) ? intval($input) : $default;
}

// Sanitize float input
function sanitizeFloat($input, $default = 0.0) {
    return isset($input) ? floatval($input) : $default;
}

// Log activity to database
function logActivity($conn, $data) {
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
            return false; // Don't log if no activity type
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

        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}
?>