<?php
// Main API Router - Routes actions to appropriate modules
// This replaces the monolithic backend.php file

require_once 'modules/helpers.php';
require_once 'modules/auth.php';
require_once 'modules/products.php';
require_once 'modules/employees.php';

// Setup API environment
setupApiEnvironment();

// Get database connection
$conn = getDatabaseConnection();

// Clean output buffer
cleanOutputBuffer();

// Get and validate JSON input
$data = getJsonInput();

// Validate action
$action = validateAction($data);

// Log the action being processed
error_log("Processing action: " . $action);

// Route actions to appropriate modules
try {
    switch ($action) {
        // Test/Debug actions
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
                $productStmt = $conn->prepare("SELECT product_id, product_name, brand_id, supplier_id FROM tbl_product ORDER BY product_id");
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

        // Authentication module actions
        case 'login':
            handle_login($conn, $data);
            break;

        case 'logout':
            handle_logout($conn, $data);
            break;

        case 'generate_captcha':
            handle_generate_captcha($conn, $data);
            break;

        case 'add_employee':
            handle_add_employee($conn, $data);
            break;

        case 'display_employee':
            handle_display_employee($conn, $data);
            break;

        case 'update_employee_status':
            handle_update_employee_status($conn, $data);
            break;

        case 'get_login_records':
            handle_get_login_records($conn, $data);
            break;

        case 'get_users':
            handle_get_users($conn, $data);
            break;

        case 'get_activity_records':
            handle_get_activity_records($conn, $data);
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

        case 'log_activity':
            handle_log_activity($conn, $data);
            break;

        case 'get_activity_logs':
            handle_get_activity_logs($conn, $data);
            break;

        case 'get_all_logs':
            handle_get_all_logs($conn, $data);
            break;

        // Products module actions
        case 'add_convenience_product':
            handle_add_convenience_product($conn, $data);
            break;

        case 'add_pharmacy_product':
            handle_add_pharmacy_product($conn, $data);
            break;

        case 'add_product':
            handle_add_product($conn, $data);
            break;

        case 'update_product':
            handle_update_product($conn, $data);
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

        case 'get_products':
            handle_get_products($conn, $data);
            break;

        case 'get_suppliers':
            handle_get_suppliers($conn, $data);
            break;

        case 'get_brands':
            handle_get_brands($conn, $data);
            break;

        case 'get_categories':
            handle_get_categories($conn, $data);
            break;

        case 'get_locations':
            handle_get_locations($conn, $data);
            break;

        case 'get_inventory_staff':
            handle_get_inventory_staff($conn, $data);
            break;

        case 'get_products_oldest_batch_for_transfer':
            handle_get_products_oldest_batch_for_transfer($conn, $data);
            break;

        case 'get_products_oldest_batch':
            handle_get_products_oldest_batch($conn, $data);
            break;

        case 'get_inventory_kpis':
            handle_get_inventory_kpis($conn, $data);
            break;

        case 'get_supply_by_location':
            handle_get_supply_by_location($conn, $data);
            break;

        case 'get_return_rate_by_product':
            handle_get_return_rate_by_product($conn, $data);
            break;

        case 'get_stockout_items':
            handle_get_stockout_items($conn, $data);
            break;

        case 'enhanced_fifo_transfer':
            handle_enhanced_fifo_transfer($conn, $data);
            break;

        case 'get_fifo_stock_status':
            handle_get_fifo_stock_status($conn, $data);
            break;

        case 'check_fifo_availability':
            handle_check_fifo_availability($conn, $data);
            break;

        case 'get_discounts':
            handle_get_discounts($conn, $data);
            break;
        case 'get_supply_by_product':
            handle_get_supply_by_product($conn, $data);
            break;
        case 'get_product_kpis':
            handle_get_product_kpis($conn, $data);
            break;
        case 'get_warehouse_kpis':
            handle_get_warehouse_kpis($conn, $data);
            break;
        case 'get_warehouse_supply_by_product':
            handle_get_warehouse_supply_by_product($conn, $data);
            break;
        case 'get_warehouse_supply_by_location':
            handle_get_warehouse_supply_by_location($conn, $data);
            break;
        case 'get_warehouse_stockout_items':
            handle_get_warehouse_stockout_items($conn, $data);
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
        case 'get_fast_moving_items_trend':
            handle_get_fast_moving_items_trend($conn, $data);
            break;
        case 'get_critical_stock_alerts':
            handle_get_critical_stock_alerts($conn, $data);
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
        // Default case for unknown actions
        default:
            echo json_encode([
                "success" => false,
                "message" => "Unknown action: {$action}"
            ]);
            break;
    }

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "An error occurred: " . $e->getMessage()
    ]);
}
?>