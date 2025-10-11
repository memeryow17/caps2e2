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
                    p.quantity,
                    p.stock_status,
                    l.location_name,
                    CASE 
                        WHEN p.quantity = 0 THEN 'Out of Stock'
                        WHEN p.stock_status = 'out of stock' THEN 'Stock Out'
                        WHEN p.quantity <= 10 THEN 'Low Stock'
                        ELSE 'In Stock'
                    END as alert_type
                FROM tbl_product p
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                AND (p.quantity = 0 OR p.quantity <= 10 OR p.stock_status = 'out of stock')
                ORDER BY 
                    CASE 
                        WHEN p.quantity = 0 THEN 1
                        WHEN p.stock_status = 'out of stock' THEN 2
                        WHEN p.quantity <= 10 THEN 3
                        ELSE 4
                    END,
                    p.quantity ASC
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

// Include all handler files
require_once __DIR__ . '/handlers/activity_handlers.php';
require_once __DIR__ . '/handlers/archive_handlers.php';
require_once __DIR__ . '/handlers/auth_handlers.php';
require_once __DIR__ . '/handlers/dashboard_handlers.php';
require_once __DIR__ . '/handlers/discount_handlers.php';
require_once __DIR__ . '/handlers/inventory_handlers.php';
require_once __DIR__ . '/handlers/location_handlers.php';
require_once __DIR__ . '/handlers/misc_handlers.php';
require_once __DIR__ . '/handlers/product_handlers.php';
require_once __DIR__ . '/handlers/report_handlers.php';
require_once __DIR__ . '/handlers/settings_handlers.php';
require_once __DIR__ . '/handlers/supplier_handlers.php';
require_once __DIR__ . '/handlers/system_handlers.php';
require_once __DIR__ . '/handlers/warehouse_handlers.php';


switch ($action) {

    // ACTIVITY Actions
    case 'log_activity':
        handle_log_activity($conn, $data);
        break;
    case 'get_latest_sales_activity':
        handle_get_latest_sales_activity($conn, $data);
        break;
    case 'record_activity':
        handle_record_activity($conn, $data);
        break;

    // ARCHIVE Actions
    case 'get_archived_items':
        handle_get_archived_items($conn, $data);
        break;
    case 'restore_archived_item':
        handle_restore_archived_item($conn, $data);
        break;
    case 'delete_archived_item':
        handle_delete_archived_item($conn, $data);
        break;

    // AUTH Actions
    case 'add_employee':
        handle_add_employee($conn, $data);
        break;
    case 'login':
        handle_login($conn, $data);
        break;
    case 'logout':
        handle_logout($conn, $data);
        break;
    case 'get_login_records':
        handle_get_login_records($conn, $data);
        break;
    case 'get_users':
        handle_get_users($conn, $data);
        break;
    case 'register_terminal_route':
        handle_register_terminal_route($conn, $data);
        break;
    case 'get_login_activity':
        handle_get_login_activity($conn, $data);
        break;
    case 'get_login_activity_count':
        handle_get_login_activity_count($conn, $data);
        break;
    case 'display_employee':
        handle_display_employee($conn, $data);
        break;
    case 'update_employee_status':
        handle_update_employee_status($conn, $data);
        break;
    case 'get_employee_profile':
        handle_get_employee_profile($conn, $data);
        break;
    case 'update_employee_profile':
        handle_update_employee_profile($conn, $data);
        break;
    case 'reset_password':
        handle_reset_password($conn, $data);
        break;
    case 'get_current_user':
        handle_get_current_user($conn, $data);
        break;
    case 'get_admin_employee_info':
        handle_get_admin_employee_info($conn, $data);
        break;
    case 'update_admin_employee_info':
        handle_update_admin_employee_info($conn, $data);
        break;
    case 'change_admin_password':
        handle_change_admin_password($conn, $data);
        break;
    case 'update_current_user_info':
        handle_update_current_user_info($conn, $data);
        break;
    case 'change_current_user_password':
        handle_change_current_user_password($conn, $data);
        break;

    // DASHBOARD Actions
    case 'get_inventory_kpis':
        handle_get_inventory_kpis($conn, $data);
        break;
    case 'get_warehouse_kpis':
        handle_get_warehouse_kpis($conn, $data);
        break;
    case 'get_fast_moving_items_trend':
        handle_get_fast_moving_items_trend($conn, $data);
        break;
    case 'get_dashboard_data':
        handle_get_dashboard_data($conn, $data);
        break;

    // DISCOUNT Actions
    case 'get_discounts':
        handle_get_discounts($conn, $data);
        break;

    // INVENTORY Actions
    case 'enhanced_fifo_transfer':
        handle_enhanced_fifo_transfer($conn, $data);
        break;
    case 'get_fifo_stock_status':
        handle_get_fifo_stock_status($conn, $data);
        break;
    case 'check_fifo_availability':
        handle_check_fifo_availability($conn, $data);
        break;
    case 'get_stockout_items':
        handle_get_stockout_items($conn, $data);
        break;
    case 'get_transfers_with_details':
        handle_get_transfers_with_details($conn, $data);
        break;
    case 'get_transfer_logs':
        handle_get_transfer_logs($conn, $data);
        break;
    case 'create_transfer_batch_details_table':
        handle_create_transfer_batch_details_table($conn, $data);
        break;
    case 'create_transfer':
        handle_create_transfer($conn, $data);
        break;
    case 'update_transfer_status':
        handle_update_transfer_status($conn, $data);
        break;
    case 'delete_transfer':
        handle_delete_transfer($conn, $data);
        break;
    case 'get_batches':
        handle_get_batches($conn, $data);
        break;
    case 'get_quantity_history':
        handle_get_quantity_history($conn, $data);
        break;
    case 'get_movement_history':
        handle_get_movement_history($conn, $data);
        break;
    case 'get_fifo_stock':
        handle_get_fifo_stock($conn, $data);
        break;
    case 'consume_stock_fifo':
        handle_consume_stock_fifo($conn, $data);
        break;
    case 'transfer_fifo_consumption':
        handle_transfer_fifo_consumption($conn, $data);
        break;
    case 'get_warehouse_stockout_items':
        handle_get_warehouse_stockout_items($conn, $data);
        break;
    case 'get_critical_stock_alerts':
        handle_get_critical_stock_alerts($conn, $data);
        break;
    case 'get_low_stock_report':
        handle_get_low_stock_report($conn, $data);
        break;
    case 'get_movement_history_report':
        handle_get_movement_history_report($conn, $data);
        break;
    case 'get_transfer_log':
        handle_get_transfer_log($conn, $data);
        break;
    case 'get_transfer_log_by_id':
        handle_get_transfer_log_by_id($conn, $data);
        break;
    case 'get_stock_adjustments':
        handle_get_stock_adjustments($conn, $data);
        break;
    case 'create_stock_adjustment':
        handle_create_stock_adjustment($conn, $data);
        break;
    case 'update_stock_adjustment':
        handle_update_stock_adjustment($conn, $data);
        break;
    case 'delete_stock_adjustment':
        handle_delete_stock_adjustment($conn, $data);
        break;
    case 'get_stock_adjustment_stats':
        handle_get_stock_adjustment_stats($conn, $data);
        break;
    case 'check_fifo_stock':
        handle_check_fifo_stock($conn, $data);
        break;
    case 'test_transfer_logic':
        handle_test_transfer_logic($conn, $data);
        break;
    case 'add_batch_entry':
        handle_add_batch_entry($conn, $data);
        break;
    case 'sync_fifo_stock':
        handle_sync_fifo_stock($conn, $data);
        break;

    // LOCATION Actions
    case 'get_supply_by_location':
        handle_get_supply_by_location($conn, $data);
        break;
    case 'get_locations':
        handle_get_locations($conn, $data);
        break;
    case 'get_locations_for_filter':
        handle_get_locations_for_filter($conn, $data);
        break;

    // MISC Actions
    case 'get_all_logs':
        handle_get_all_logs($conn, $data);
        break;
    case 'get_categories':
        handle_get_categories($conn, $data);
        break;
    case 'get_inventory_staff':
        handle_get_inventory_staff($conn, $data);
        break;
    case 'today':
        handle_today($conn, $data);
        break;
    case 'week':
        handle_week($conn, $data);
        break;
    case 'month':
        handle_month($conn, $data);
        break;
    case 'get_cashier_details':
        handle_get_cashier_details($conn, $data);
        break;
    case 'update_admin_name':
        handle_update_admin_name($conn, $data);
        break;

    // PRODUCT Actions
    case 'debug_brands_suppliers':
        handle_debug_brands_suppliers($conn, $data);
        break;
    case 'clear_brands':
        handle_clear_brands($conn, $data);
        break;
    case 'add_convenience_product':
        handle_add_convenience_product($conn, $data);
        break;
    case 'add_pharmacy_product':
        handle_add_pharmacy_product($conn, $data);
        break;
    case 'addBrand':
        handle_addBrand($conn, $data);
        break;
    case 'displayBrand':
        handle_displayBrand($conn, $data);
        break;
    case 'deleteBrand':
        handle_deleteBrand($conn, $data);
        break;
    case 'add_brand':
        handle_add_brand($conn, $data);
        break;
    case 'add_product':
        handle_add_product($conn, $data);
        break;
    case 'update_product':
        handle_update_product($conn, $data);
        break;
    case 'get_products_oldest_batch_for_transfer':
        handle_get_products_oldest_batch_for_transfer($conn, $data);
        break;
    case 'get_products_oldest_batch':
        handle_get_products_oldest_batch($conn, $data);
        break;
    case 'get_return_rate_by_product':
        handle_get_return_rate_by_product($conn, $data);
        break;
    case 'get_products':
        handle_get_products($conn, $data);
        break;
    case 'get_brands':
        handle_get_brands($conn, $data);
        break;
    case 'get_transferred_products_by_location':
        handle_get_transferred_products_by_location($conn, $data);
        break;
    case 'delete_product':
        handle_delete_product($conn, $data);
        break;
    case 'get_products_by_location':
        handle_get_products_by_location($conn, $data);
        break;
    case 'get_pos_products':
        handle_get_pos_products($conn, $data);
        break;
    case 'check_product_name':
        handle_check_product_name($conn, $data);
        break;
    case 'check_barcode':
        handle_check_barcode($conn, $data);
        break;
    case 'get_product_batches':
        handle_get_product_batches($conn, $data);
        break;
    case 'simple_update_product_stock':
        handle_simple_update_product_stock($conn, $data);
        break;
    case 'update_product_stock':
        handle_update_product_stock($conn, $data);
        break;
    case 'reduce_product_stock':
        handle_reduce_product_stock($conn, $data);
        break;
    case 'get_expiring_products':
        handle_get_expiring_products($conn, $data);
        break;
    case 'get_supply_by_product':
        handle_get_supply_by_product($conn, $data);
        break;
    case 'get_product_kpis':
        handle_get_product_kpis($conn, $data);
        break;
    case 'get_warehouse_supply_by_product':
        handle_get_warehouse_supply_by_product($conn, $data);
        break;
    case 'get_warehouse_product_kpis':
        handle_get_warehouse_product_kpis($conn, $data);
        break;
    case 'get_top_products_by_quantity':
        handle_get_top_products_by_quantity($conn, $data);
        break;
    case 'get_stock_distribution_by_category':
        handle_get_stock_distribution_by_category($conn, $data);
        break;
    case 'get_inventory_by_branch_category':
        handle_get_inventory_by_branch_category($conn, $data);
        break;
    case 'get_products_by_location_name':
        handle_get_products_by_location_name($conn, $data);
        break;
    case 'get_location_products':
        handle_get_location_products($conn, $data);
        break;
    case 'get_archived_products':
        handle_get_archived_products($conn, $data);
        break;
    case 'duplicate_product_batches':
        handle_duplicate_product_batches($conn, $data);
        break;
    case 'Product':
        handle_Product($conn, $data);
        break;
    case 'Category':
        handle_Category($conn, $data);
        break;
    case 'get_product_quantities':
        handle_get_product_quantities($conn, $data);
        break;
    case 'add_product_enhanced':
        handle_add_product_enhanced($conn, $data);
        break;
    case 'view_all_products':
        handle_view_all_products($conn, $data);
        break;
    case 'force_sync_all_products':
        handle_force_sync_all_products($conn, $data);
        break;
    case 'cleanup_duplicate_transfer_products':
        handle_cleanup_duplicate_transfer_products($conn, $data);
        break;

    // REPORT Actions
    case 'get_activity_records':
        handle_get_activity_records($conn, $data);
        break;
    case 'get_activity_logs':
        handle_get_activity_logs($conn, $data);
        break;
    case 'get_reports_data':
        handle_get_reports_data($conn, $data);
        break;
    case 'get_inventory_summary_report':
        handle_get_inventory_summary_report($conn, $data);
        break;
    case 'get_expiry_report':
        handle_get_expiry_report($conn, $data);
        break;
    case 'get_report_data':
        handle_get_report_data($conn, $data);
        break;
    case 'generate_report':
        handle_generate_report($conn, $data);
        break;
    case 'get_report_details':
        handle_get_report_details($conn, $data);
        break;
    case 'get_activity_summary':
        handle_get_activity_summary($conn, $data);
        break;

    // SETTINGS Actions
    case 'get_store_settings':
        handle_get_store_settings($conn, $data);
        break;
    case 'update_store_settings':
        handle_update_store_settings($conn, $data);
        break;

    // SUPPLIER Actions
    case 'get_suppliers':
        handle_get_suppliers($conn, $data);
        break;
    case 'add_supplier':
        handle_add_supplier($conn, $data);
        break;
    case 'update_supplier':
        handle_update_supplier($conn, $data);
        break;
    case 'delete_supplier':
        handle_delete_supplier($conn, $data);
        break;
    case 'deleteSupplier':
        handle_deleteSupplier($conn, $data);
        break;
    case 'restoreSupplier':
        handle_restoreSupplier($conn, $data);
        break;
    case 'displayArchivedSuppliers':
        handle_displayArchivedSuppliers($conn, $data);
        break;
    case 'Supplier':
        handle_Supplier($conn, $data);
        break;

    // SYSTEM Actions
    case 'test_connection':
        handle_test_connection($conn, $data);
        break;
    case 'check_table_structure':
        handle_check_table_structure($conn, $data);
        break;
    case 'generate_captcha':
        handle_generate_captcha($conn, $data);
        break;
    case 'test_database_connection':
        handle_test_database_connection($conn, $data);
        break;
    case 'test_action':
        handle_test_action($conn, $data);
        break;
    case 'check_duplicates':
        handle_check_duplicates($conn, $data);
        break;
    case 'check_constraints':
        handle_check_constraints($conn, $data);
        break;
    case 'test_logging':
        handle_test_logging($conn, $data);
        break;
    case 'check_new_sales':
        handle_check_new_sales($conn, $data);
        break;
    case 'check_system_updates':
        handle_check_system_updates($conn, $data);
        break;

    // WAREHOUSE Actions
    case 'diagnose_warehouse_data':
        handle_diagnose_warehouse_data($conn, $data);
        break;
    case 'emergency_restore_warehouse':
        handle_emergency_restore_warehouse($conn, $data);
        break;
    case 'get_warehouse_supply_by_location':
        handle_get_warehouse_supply_by_location($conn, $data);
        break;
    case 'get_warehouse_notifications':
        handle_get_warehouse_notifications($conn, $data);
        break;

    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action: ' . $action
        ]);
        break;
}

 // End of switch statement

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}

// Flush the output buffer to ensure clean JSON response
ob_end_flush();
?>  