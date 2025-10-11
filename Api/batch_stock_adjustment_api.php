<?php
// Batch Stock Adjustment API
// Handles batch-level stock adjustments and tracking

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
error_log("Batch Stock Adjustment API - Raw input: " . $rawData);

$data = json_decode($rawData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON input"
    ]);
    exit;
}

$action = $data['action'] ?? '';

// Function to ensure table structure is correct (without SRP)
function ensureTableStructure($conn) {
    try {
        error_log("🔧 Starting table structure check...");
        
        // Check if entry_date column exists
        $checkEntryDateStmt = $conn->prepare("SHOW COLUMNS FROM tbl_fifo_stock LIKE 'entry_date'");
        $checkEntryDateStmt->execute();
        $entryDateExists = $checkEntryDateStmt->fetch();
        
        error_log("🔍 Entry_date column check result: " . ($entryDateExists ? "EXISTS" : "NOT EXISTS"));
        
        if (!$entryDateExists) {
            error_log("➕ Adding entry_date column to tbl_fifo_stock...");
            // Add entry_date column if it doesn't exist
            $addEntryDateStmt = $conn->prepare("ALTER TABLE tbl_fifo_stock ADD COLUMN entry_date DATE DEFAULT CURDATE()");
            $addEntryDateStmt->execute();
            error_log("✅ Successfully added entry_date column to tbl_fifo_stock table");
        } else {
            error_log("✅ Entry_date column already exists");
        }
        
        error_log("🏁 Table structure check completed");
        
    } catch (Exception $e) {
        error_log("❌ Error ensuring table structure: " . $e->getMessage());
        error_log("❌ Error details: " . print_r($e, true));
    }
}

// Ensure table structure before processing requests
ensureTableStructure($conn);

switch ($action) {
    case 'get_batch_adjustment_history':
        try {
            $page = $data['page'] ?? 1;
            $limit = $data['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            
            // Get batch adjustment history from tbl_batch_adjustment_log
            $stmt = $conn->prepare("
                SELECT 
                    bal.log_id,
                    bal.product_id,
                    bal.batch_id,
                    bal.batch_reference,
                    bal.old_qty,
                    bal.new_qty,
                    bal.adjustment_qty,
                    bal.movement_type,
                    bal.reason,
                    bal.notes,
                    bal.adjusted_by,
                    bal.created_at,
                    p.product_name,
                    p.barcode,
                    fs.expiration_date
                FROM tbl_batch_adjustment_log bal
                LEFT JOIN tbl_product p ON bal.product_id = p.product_id
                LEFT JOIN tbl_fifo_stock fs ON bal.batch_id = fs.batch_id
                ORDER BY bal.created_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset
            );
            
            $stmt->execute();
            $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_batch_adjustment_log");
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
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

    case 'get_batch_adjustment_stats':
        try {
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_adjustments,
                    COUNT(CASE WHEN movement_type = 'IN' THEN 1 END) as additions,
                    COUNT(CASE WHEN movement_type = 'OUT' THEN 1 END) as subtractions,
                    SUM(CASE WHEN movement_type = 'IN' THEN adjustment_qty ELSE -adjustment_qty END) as net_quantity
                FROM tbl_batch_adjustment_log
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

    case 'get_product_batches_for_adjustment':
        try {
            $product_id = $data['product_id'] ?? 0;
            $location_id = $data['location_id'] ?? null;
            
            if (!$product_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Product ID is required"
                ]);
                break;
            }
            
            // Get product details
            $productWhereClause = "WHERE p.product_id = ?";
            $productParams = [$product_id];
            
            // Add location filter if specified
            if ($location_id) {
                $productWhereClause .= " AND p.location_id = ?";
                $productParams[] = $location_id;
            }
            
            $productStmt = $conn->prepare("
                SELECT p.product_id, p.product_name, p.barcode, c.category_name as category, p.quantity,
                       b.brand, s.supplier_name, l.location_name
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                {$productWhereClause}
            ");
            $productStmt->execute($productParams);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get available batches for this product
            $batchesWhereClause = "WHERE fs.product_id = ? AND fs.quantity > 0";
            $batchesParams = [$product_id];
            
            // Add location filter if specified
            if ($location_id) {
                $batchesWhereClause .= " AND p.location_id = ?";
                $batchesParams[] = $location_id;
            }
            
            // Get batches without SRP column
            $batchesStmt = $conn->prepare("
                SELECT 
                    fs.batch_id,
                    fs.batch_reference,
                    fs.available_quantity as current_qty,
                    fs.expiration_date,
                    fs.entry_date as batch_created_at,
                    COALESCE(l.location_name, 'Unknown') as location_name
                FROM tbl_fifo_stock fs
                LEFT JOIN tbl_product p ON fs.product_id = p.product_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                {$batchesWhereClause}
                ORDER BY fs.expiration_date ASC, fs.entry_date ASC
            ");
            $batchesStmt->execute($batchesParams);
            $batches = $batchesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => [
                    "product" => $product,
                    "batches" => $batches
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'create_batch_stock_adjustment':
        try {
            error_log("🔄 Starting create_batch_stock_adjustment...");
            error_log("📊 Received data: " . print_r($data, true));
            
            $product_id = $data['product_id'] ?? 0;
            $batch_id = $data['batch_id'] ?? 0;
            $old_qty = $data['old_qty'] ?? 0;
            $new_qty = $data['new_qty'] ?? 0;
            $adjustment_qty = $data['adjustment_qty'] ?? 0;
            $reason = $data['reason'] ?? '';
            $notes = $data['notes'] ?? '';
            $adjusted_by = $data['adjusted_by'] ?? 'admin';
            
            error_log("📋 Parsed values: product_id=$product_id, batch_id=$batch_id, old_qty=$old_qty, new_qty=$new_qty, adjustment_qty=$adjustment_qty, reason='$reason'");
            
            if (!$product_id || !$batch_id || !$reason) {
                $errorMsg = "Product ID, Batch ID, and reason are required";
                error_log("❌ Validation failed: $errorMsg");
                echo json_encode([
                    "success" => false,
                    "message" => $errorMsg
                ]);
                break;
            }
            
            if ($adjustment_qty == 0) {
                $errorMsg = "Adjustment quantity cannot be zero";
                error_log("❌ Validation failed: $errorMsg");
                echo json_encode([
                    "success" => false,
                    "message" => $errorMsg
                ]);
                break;
            }
            
            // Start transaction
            error_log("🔄 Starting database transaction...");
            $conn->beginTransaction();
            
            // Determine movement type based on adjustment quantity
            $movement_type = $adjustment_qty > 0 ? 'IN' : 'OUT';
            error_log("📊 Movement type: $movement_type");
            
            // Get batch reference
            error_log("🔍 Getting batch reference for batch_id: $batch_id");
            $batchRefStmt = $conn->prepare("SELECT batch_reference FROM tbl_fifo_stock WHERE batch_id = ?");
            $batchRefStmt->execute([$batch_id]);
            $batch_reference = $batchRefStmt->fetchColumn();
            error_log("📋 Batch reference: $batch_reference");
            
            // Update FIFO stock quantity
            error_log("🔄 Updating FIFO stock quantity...");
            $updateStmt = $conn->prepare("
                UPDATE tbl_fifo_stock 
                SET available_quantity = available_quantity + ? 
                WHERE batch_id = ?
            ");
            $updateStmt->execute([$adjustment_qty, $batch_id]);
            error_log("✅ FIFO stock updated");
            
            // Update product total quantity
            error_log("🔄 Updating product total quantity...");
            $updateProductStmt = $conn->prepare("
                UPDATE tbl_product 
                SET quantity = quantity + ?,
                    stock_status = CASE 
                        WHEN (quantity + ?) <= 0 THEN 'out of stock'
                        WHEN (quantity + ?) <= 10 THEN 'low stock'
                        ELSE 'in stock'
                    END
                WHERE product_id = ?
            ");
            $updateProductStmt->execute([$adjustment_qty, $adjustment_qty, $adjustment_qty, $product_id]);
            error_log("✅ Product quantity updated");
            
            // Create batch adjustment log entry
            error_log("🔄 Creating batch adjustment log entry...");
            $logStmt = $conn->prepare("
                INSERT INTO tbl_batch_adjustment_log (
                    product_id, batch_id, batch_reference, old_qty, new_qty, 
                    adjustment_qty, movement_type, reason, notes, adjusted_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $product_id, $batch_id, $batch_reference, $old_qty, $new_qty,
                $adjustment_qty, $movement_type, $reason, $notes, $adjusted_by
            ]);
            error_log("✅ Batch adjustment log created");
            
            // Create stock movement record (without SRP)
            error_log("🔄 Creating stock movement record...");
            $movementStmt = $conn->prepare("
                INSERT INTO tbl_stock_movements (
                    product_id, batch_id, movement_type, quantity, remaining_quantity,
                    expiration_date, reference_no, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Get expiration date from batch
            $expiration_date = null;
            try {
                error_log("🔍 Getting expiration date for batch_id: $batch_id");
                $batchDetailsStmt = $conn->prepare("SELECT expiration_date FROM tbl_fifo_stock WHERE batch_id = ?");
                $batchDetailsStmt->execute([$batch_id]);
                $batchDetails = $batchDetailsStmt->fetch(PDO::FETCH_ASSOC);
                $expiration_date = $batchDetails['expiration_date'] ?? null;
                error_log("📅 Expiration date: $expiration_date");
            } catch (Exception $e) {
                error_log("❌ Error getting batch expiration date: " . $e->getMessage());
            }
            
            $movementStmt->execute([
                $product_id, $batch_id, $movement_type, abs($adjustment_qty), $new_qty,
                $expiration_date, 
                $batch_reference, "Batch adjustment: " . $reason, $adjusted_by
            ]);
            error_log("✅ Stock movement record created");
            
            $conn->commit();
            error_log("✅ Transaction committed successfully");
            
            echo json_encode([
                "success" => true,
                "message" => "Batch adjustment created successfully",
                "data" => [
                    "log_id" => $conn->lastInsertId(),
                    "old_qty" => $old_qty,
                    "new_qty" => $new_qty,
                    "adjustment_qty" => $adjustment_qty,
                    "movement_type" => $movement_type
                ]
            ]);
            error_log("✅ Response sent successfully");
            
        } catch (Exception $e) {
            error_log("❌ Exception in create_batch_stock_adjustment: " . $e->getMessage());
            error_log("❌ Exception trace: " . $e->getTraceAsString());
            
            if (isset($conn)) {
                try {
                    $conn->rollback();
                    error_log("✅ Transaction rolled back");
                } catch (Exception $rollbackError) {
                    error_log("❌ Error during rollback: " . $rollbackError->getMessage());
                }
            }
            
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage(),
                "error_details" => [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "trace" => $e->getTraceAsString()
                ]
            ]);
        }
        break;

    case 'get_products_oldest_batch':
        try {
            $status = $data['status'] ?? 'active';
            $limit = $data['limit'] ?? 1000;
            
            $stmt = $conn->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    c.category_name as category,
                    p.quantity,
                    p.barcode,
                    p.unit_price,
                    p.location_id,
                    l.location_name,
                    COALESCE(b.brand, '') as brand,
                    COALESCE(s.supplier_name, '') as supplier_name,
                    COUNT(fs.batch_id) as batch_count,
                    SUM(fs.available_quantity) as total_available
                FROM tbl_product p
                LEFT JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id AND fs.available_quantity > 0
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                WHERE p.status = ?
                GROUP BY p.product_id
                HAVING batch_count > 0
                ORDER BY p.product_name ASC
                LIMIT " . (int)$limit
            );
            
            $stmt->execute([$status]);
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

    case 'create_batch_adjustment_log_table':
        try {
            // Create the batch adjustment log table if it doesn't exist
            $createTableStmt = $conn->prepare("
                CREATE TABLE IF NOT EXISTS tbl_batch_adjustment_log (
                    log_id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    batch_id INT NOT NULL,
                    batch_reference VARCHAR(100),
                    old_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
                    new_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
                    adjustment_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
                    movement_type ENUM('IN', 'OUT') NOT NULL,
                    reason TEXT NOT NULL,
                    notes TEXT,
                    adjusted_by VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_product_id (product_id),
                    INDEX idx_batch_id (batch_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $createTableStmt->execute();
            
            echo json_encode([
                "success" => true,
                "message" => "Batch adjustment log table created successfully"
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;


    case 'test_table_structure':
        try {
            // Test table structure and show current columns
            $showColumnsStmt = $conn->prepare("SHOW COLUMNS FROM tbl_fifo_stock");
            $showColumnsStmt->execute();
            $columns = $showColumnsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "message" => "Table structure retrieved",
                "columns" => $columns
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
        break;

    case 'test_api':
        try {
            echo json_encode([
                "success" => true,
                "message" => "API is working",
                "timestamp" => date('Y-m-d H:i:s'),
                "received_data" => $data
            ]);
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Test error: " . $e->getMessage()
            ]);
        }
        break;

    default:
        echo json_encode([
            "success" => false,
            "message" => "Unknown action: $action"
        ]);
        break;
}

ob_end_flush();
?>
