<?php
// Modular Backend API
// This file uses separate module files for better organization

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

// Use centralized database connection
require_once __DIR__ . '/conn.php';

// Clear any output that might have been generated
ob_clean();

// Read and decode incoming JSON request
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
error_log("Processing action: " . $action);

try {
    switch ($action) {
        // Test connection
        case 'test_connection':
            echo json_encode([
                "success" => true,
                "message" => "Modular API connection successful",
                "timestamp" => date('Y-m-d H:i:s'),
                "database" => "Connected to enguio2 database",
                "modules" => ["inventory", "sales", "barcode", "locations", "discounts"]
            ]);
            break;
            
        // Inventory Management
        case 'get_pos_inventory':
            require_once 'modules/inventory.php';
            get_pos_inventory($conn, $data);
            break;
            
        case 'reduce_product_stock':
            require_once 'modules/inventory.php';
            reduce_product_stock($conn, $data);
            break;
            
        case 'update_product_stock':
            require_once 'modules/inventory.php';
            update_product_stock($conn, $data);
            break;
            
        // Sales Management
        case 'save_pos_sale':
            require_once 'modules/sales.php';
            save_pos_sale($conn, $data);
            break;
            
        case 'get_today_sales':
            require_once 'modules/sales.php';
            get_today_sales($conn, $data);
            break;
            
        case 'get_recent_transactions':
            require_once 'modules/sales.php';
            get_recent_transactions($conn, $data);
            break;
            
        // Barcode Management
        case 'check_barcode':
            require_once 'modules/barcode.php';
            check_barcode($conn, $data);
            break;
            
        // Location Management
        case 'get_locations':
            require_once 'modules/locations.php';
            get_locations($conn, $data);
            break;
            
        // Discount Management
        case 'get_discounts':
            require_once 'modules/discounts.php';
            get_discounts($conn, $data);
            break;
            
        // Warehouse Management - Product Operations
        case 'get_products':
            require_once 'modules/inventory.php';
            get_products($conn, $data);
            break;
        
        case 'get_products_by_location_name':
            require_once 'modules/products.php';
            handle_get_products_by_location_name($conn, $data);
            break;
        
        case 'get_location_products':
            require_once 'modules/products.php';
            handle_get_location_products($conn, $data);
            break;
        
        case 'get_product_batches':
            require_once 'modules/batch_functions.php';
            get_product_batches($conn, $data);
            break;
        
        case 'get_fifo_stock':
            require_once 'modules/batch_functions.php';
            get_fifo_stock($conn, $data);
            break;
        
        case 'get_products_oldest_batch':
            require_once 'modules/batch_functions.php';
            get_products_oldest_batch($conn, $data);
            break;
        
        case 'get_product_quantities':
            require_once 'modules/inventory.php';
            get_product_quantities($conn, $data);
            break;
            
        case 'get_expiring_products':
            require_once 'modules/inventory.php';
            get_expiring_products($conn, $data);
            break;
            
        case 'consume_stock_fifo':
            require_once 'modules/batch_functions.php';
            consume_stock_fifo($conn, $data);
            break;
            
        case 'get_quantity_history':
            require_once 'modules/inventory.php';
            get_quantity_history($conn, $data);
            break;
            
        case 'add_quantity_to_product':
            require_once 'modules/inventory.php';
            add_quantity_to_product($conn, $data);
            break;
            
        // Warehouse Management - Supplier Operations
        case 'get_suppliers':
            require_once 'modules/inventory.php';
            get_suppliers($conn, $data);
            break;
            
        case 'add_supplier':
            require_once 'modules/inventory.php';
            add_supplier($conn, $data);
            break;
            
        case 'update_supplier':
            require_once 'modules/inventory.php';
            update_supplier($conn, $data);
            break;
            
        case 'delete_supplier':
            require_once 'modules/inventory.php';
            delete_supplier($conn, $data);
            break;
            
        // Warehouse Management - Brand Operations
        case 'get_brands':
            require_once 'modules/inventory.php';
            get_brands($conn, $data);
            break;
            
        case 'add_brand':
            require_once 'modules/inventory.php';
            add_brand($conn, $data);
            break;
            
        // Warehouse Management - Category Operations
        case 'get_categories':
            require_once 'modules/inventory.php';
            get_categories($conn, $data);
            break;
            
        // Warehouse Management - Batch Operations
        case 'get_batches':
            require_once 'modules/batch_functions.php';
            get_batches($conn, $data);
            break;
            
        case 'add_batch_entry':
            require_once 'modules/batch_functions.php';
            add_batch_entry($conn, $data);
            break;
            
        case 'duplicate_product_batches':
            require_once 'modules/batch_functions.php';
            duplicate_product_batches($conn, $data);
            break;
            
        // Warehouse Management - Product CRUD Operations
        case 'add_product':
            require_once 'modules/inventory.php';
            add_product($conn, $data);
            break;
            
        case 'update_product':
            require_once 'modules/inventory.php';
            update_product($conn, $data);
            break;
            
        case 'delete_product':
            require_once 'modules/inventory.php';
            delete_product($conn, $data);
            break;
            
        // Warehouse Management - System Operations
        case 'sync_fifo_stock':
            require_once 'modules/batch_functions.php';
            sync_fifo_stock($conn, $data);
            break;
            
        case 'force_sync_all_products':
            require_once 'modules/batch_functions.php';
            force_sync_all_products($conn, $data);
            break;
            
        case 'cleanup_duplicate_transfer_products':
            require_once 'modules/batch_functions.php';
            cleanup_duplicate_transfer_products($conn, $data);
            break;
            
        case 'create_transfer_batch_details_table':
            require_once 'modules/batch_functions.php';
            create_transfer_batch_details_table($conn, $data);
            break;
            
        // Warehouse Management - KPI Operations
        case 'get_warehouse_kpis':
            require_once 'modules/reports.php';
            get_warehouse_kpis($conn, $data);
            break;
        
        // Employee Management
        case 'add_employee':
            require_once 'modules/employees.php';
            handle_add_employee($conn, $data);
            break;
        case 'display_employee':
            require_once 'modules/employees.php';
            handle_display_employee($conn, $data);
            break;
        case 'update_employee_status':
            require_once 'modules/employees.php';
            handle_update_employee_status($conn, $data);
            break;
        
        // Activity Logging
        case 'log_activity':
            require_once 'modules/inventory.php';
            log_activity($conn, $data);
            break;
        
        // Default case for unknown actions
        default:
            echo json_encode([
                "success" => false,
                "message" => "Unknown action: $action",
                "available_actions" => [
                    "test_connection",
                    "get_pos_inventory",
                    "reduce_product_stock", 
                    "update_product_stock",
                    "save_pos_sale",
                    "get_today_sales",
                    "get_recent_transactions",
                    "check_barcode",
                    "get_locations",
                    "get_discounts"
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}

// Flush the output buffer to ensure clean JSON response
ob_end_flush();
?>
