<?php
/**
 * API to get transferred batches for specific stores
 * This will populate and display batch transfer details
 */

// Enable CORS for all requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cache-Control");
header("Access-Control-Max-Age: 86400");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

require_once __DIR__ . '/conn.php';

// Get the data from the request
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];

// Get the action from the request
$action = $data['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

try {
    // Use global $conn from conn.php (PDO connection)
    
    switch ($action) {
        case 'get_transferred_batches':
            getTransferredBatches($conn, $data);
            break;
            
        case 'populate_missing_batch_details':
            populateMissingBatchDetails($conn, $data);
            break;
            
        case 'get_batch_transfer_summary':
            getBatchTransferSummary($conn, $data);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

/**
 * Get transferred batches for a specific store/location
 */
function getTransferredBatches($db, $data) {
    try {
        $location_id = $data['location_id'] ?? 0;
        $product_id = $data['product_id'] ?? 0;
        $location_name = $data['location_name'] ?? '';
        
        if (!$location_id && !$location_name) {
            echo json_encode([
                'success' => false,
                'message' => 'Location ID or Location Name is required'
            ]);
            return;
        }
        
        // If location_name is provided, get location_id
        if ($location_name && !$location_id) {
            $locationStmt = $db->getConnection()->prepare("SELECT location_id FROM tbl_location WHERE location_name LIKE ?");
            $locationStmt->execute(["%$location_name%"]);
            $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($location) {
                $location_id = $location['location_id'];
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Location not found'
                ]);
                return;
            }
        }
        
        // First, try to get data from tbl_transfer_batch_details
        $batchDetails = [];
        if ($product_id) {
            // Get specific product batch details
            $sql = "
                SELECT 
                    tbd.id as batch_transfer_id,
                    tbd.product_id,
                    tbd.batch_id,
                    tbd.batch_reference,
                    tbd.quantity as batch_quantity,
                    tbd.srp as batch_srp,
                    tbd.expiration_date,
                    tbd.created_at as transfer_date,
                    p.product_name,
                    p.barcode,
                    p.location_id,
                    l.location_name,
                    b.entry_date as batch_entry_date,
                    b.entry_time as batch_entry_time
                FROM tbl_transfer_batch_details tbd
                LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
                LEFT JOIN tbl_batch b ON tbd.batch_id = b.batch_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE p.location_id = ? AND tbd.product_id = ?
                ORDER BY tbd.created_at DESC
            ";
            $batchDetails = $db->select($sql, [$location_id, $product_id]);
        } else {
            // Get all batch details for the location
            $sql = "
                SELECT 
                    tbd.id as batch_transfer_id,
                    tbd.product_id,
                    tbd.batch_id,
                    tbd.batch_reference,
                    tbd.quantity as batch_quantity,
                    tbd.srp as batch_srp,
                    tbd.expiration_date,
                    tbd.created_at as transfer_date,
                    p.product_name,
                    p.barcode,
                    p.location_id,
                    l.location_name,
                    b.entry_date as batch_entry_date,
                    b.entry_time as batch_entry_time
                FROM tbl_transfer_batch_details tbd
                LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
                LEFT JOIN tbl_batch b ON tbd.batch_id = b.batch_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE p.location_id = ?
                ORDER BY tbd.created_at DESC
            ";
            $batchDetails = $db->select($sql, [$location_id]);
        }
        
        // If no batch details found, try to populate from stock movements
        if (empty($batchDetails)) {
            $batchDetails = populateFromStockMovements($db, $location_id, $product_id);
        }
        
        // Group by product for summary
        $summary = [];
        $groupedBatches = [];
        
        foreach ($batchDetails as $batch) {
            $product_id_key = $batch['product_id'];
            
            if (!isset($groupedBatches[$product_id_key])) {
                $groupedBatches[$product_id_key] = [
                    'product_id' => $batch['product_id'],
                    'product_name' => $batch['product_name'],
                    'barcode' => $batch['barcode'],
                    'total_quantity' => 0,
                    'batches' => []
                ];
            }
            
            $quantity = $batch['batch_quantity'] ?? $batch['quantity_used'] ?? 0;
            $groupedBatches[$product_id_key]['total_quantity'] += $quantity;
            $groupedBatches[$product_id_key]['batches'][] = $batch;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $batchDetails,
            'grouped_data' => $groupedBatches,
            'summary' => [
                'total_products' => count($groupedBatches),
                'total_batches' => count($batchDetails),
                'location_id' => $location_id
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting transferred batches: ' . $e->getMessage()
        ]);
    }
}

/**
 * Populate missing batch details from stock movements
 */
function populateFromStockMovements($db, $location_id, $product_id = 0) {
    try {
        $sql = "
            SELECT 
                sm.product_id,
                sm.batch_id,
                sm.movement_type,
                sm.quantity,
                sm.movement_date,
                sm.reference_no,
                sm.srp,
                sm.expiration_date,
                p.product_name,
                p.barcode,
                b.batch_reference,
                b.entry_date as batch_entry_date,
                b.entry_time as batch_entry_time,
                l.location_name
            FROM tbl_stock_movements sm
            LEFT JOIN tbl_product p ON sm.product_id = p.product_id
            LEFT JOIN tbl_batch b ON sm.batch_id = b.batch_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            WHERE sm.movement_type = 'IN' 
            AND p.location_id = ?
        ";
        
        $params = [$location_id];
        
        if ($product_id) {
            $sql .= " AND sm.product_id = ?";
            $params[] = $product_id;
        }
        
        $sql .= " ORDER BY sm.movement_date DESC";
        
        $movements = $db->select($sql, $params);
        
        // Convert movements to batch details format
        $batchDetails = [];
        foreach ($movements as $movement) {
            $batchDetails[] = [
                'batch_transfer_id' => $movement['product_id'] . '_' . $movement['batch_id'] . '_' . strtotime($movement['movement_date']),
                'product_id' => $movement['product_id'],
                'batch_id' => $movement['batch_id'],
                'batch_reference' => $movement['batch_reference'],
                'batch_quantity' => $movement['quantity'],
                'unit_cost' => 0,
                'batch_srp' => $movement['srp'],
                'expiration_date' => $movement['expiration_date'],
                'location_id' => $location_id,
                'transfer_date' => $movement['movement_date'],
                'transfer_time' => date('H:i:s', strtotime($movement['movement_date'])),
                'status' => 'transferred',
                'product_name' => $movement['product_name'],
                'barcode' => $movement['barcode'],
                'srp' => $movement['srp'],
                'batch_entry_date' => $movement['batch_entry_date'],
                'batch_entry_time' => $movement['batch_entry_time'],
                'location_name' => $movement['location_name']
            ];
        }
        
        return $batchDetails;
        
    } catch (Exception $e) {
        error_log("Error populating from stock movements: " . $e->getMessage());
        return [];
    }
}

/**
 * Populate missing batch transfer details in the database
 */
function populateMissingBatchDetails($db, $data) {
    try {
        $location_id = $data['location_id'] ?? 0;
        $location_name = $data['location_name'] ?? '';
        
        if (!$location_id && !$location_name) {
            echo json_encode([
                'success' => false,
                'message' => 'Location ID or Location Name is required'
            ]);
            return;
        }
        
        // If location_name is provided, get location_id
        if ($location_name && !$location_id) {
            $locationStmt = $db->getConnection()->prepare("SELECT location_id FROM tbl_location WHERE location_name LIKE ?");
            $locationStmt->execute(["%$location_name%"]);
            $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($location) {
                $location_id = $location['location_id'];
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Location not found'
                ]);
                return;
            }
        }
        
        $db->beginTransaction();
        
        // Get stock movements for this location
        $sql = "
            SELECT 
                sm.product_id,
                sm.batch_id,
                sm.movement_type,
                sm.quantity,
                sm.movement_date,
                sm.reference_no,
                sm.srp,
                sm.expiration_date,
                b.batch_reference
            FROM tbl_stock_movements sm
            LEFT JOIN tbl_batch b ON sm.batch_id = b.batch_id
            LEFT JOIN tbl_product p ON sm.product_id = p.product_id
            WHERE sm.movement_type = 'IN' 
            AND p.location_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM tbl_transfer_batch_details tbd 
                WHERE tbd.product_id = sm.product_id 
                AND tbd.batch_id = sm.batch_id
            )
            ORDER BY sm.movement_date DESC
        ";
        
        $movements = $db->select($sql, [$location_id]);
        
        $inserted_count = 0;
        
        foreach ($movements as $movement) {
            // Insert into tbl_transfer_batch_details
            $insertSql = "
                INSERT INTO tbl_transfer_batch_details (
                    product_id, batch_id, batch_reference, quantity, 
                    srp, expiration_date, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            $transferDate = date('Y-m-d', strtotime($movement['movement_date']));
            $transferTime = date('H:i:s', strtotime($movement['movement_date']));
            
            $db->insert($insertSql, [
                $movement['product_id'],
                $movement['batch_id'],
                $movement['batch_reference'],
                $movement['quantity'],
                $movement['srp'],
                $movement['expiration_date'],
                $movement['movement_date']
            ]);
            
            $inserted_count++;
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully populated $inserted_count batch transfer details",
            'inserted_count' => $inserted_count
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Error populating batch details: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get batch transfer summary for a location
 */
function getBatchTransferSummary($conn, $data) {
    try {
        // Note: $conn is now PDO connection, not Database class
        $location_id = $data['location_id'] ?? 0;
        $location_name = $data['location_name'] ?? '';
        
        if (!$location_id && !$location_name) {
            echo json_encode([
                'success' => false,
                'message' => 'Location ID or Location Name is required'
            ]);
            return;
        }
        
        // If location_name is provided, get location_id
        if ($location_name && !$location_id) {
            $locationStmt = $db->getConnection()->prepare("SELECT location_id FROM tbl_location WHERE location_name LIKE ?");
            $locationStmt->execute(["%$location_name%"]);
            $location = $locationStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($location) {
                $location_id = $location['location_id'];
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Location not found'
                ]);
                return;
            }
        }
        
        // Get summary data
        $sql = "
            SELECT 
                COUNT(DISTINCT tbd.product_id) as total_products,
                COUNT(tbd.id) as total_batches,
                SUM(tbd.quantity) as total_quantity,
                AVG(tbd.srp) as average_srp,
                MIN(tbd.created_at) as earliest_transfer,
                MAX(tbd.created_at) as latest_transfer,
                l.location_name
            FROM tbl_transfer_batch_details tbd
            LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            WHERE p.location_id = ?
        ";
        
        $summary = $db->selectOne($sql, [$location_id]);
        
        // Get product breakdown
        $productSql = "
            SELECT 
                p.product_id,
                p.product_name,
                p.barcode,
                COUNT(tbd.id) as batch_count,
                SUM(tbd.quantity) as total_quantity,
                AVG(tbd.srp) as average_srp
            FROM tbl_transfer_batch_details tbd
            LEFT JOIN tbl_product p ON tbd.product_id = p.product_id
            WHERE p.location_id = ?
            GROUP BY p.product_id, p.product_name, p.barcode
            ORDER BY total_quantity DESC
        ";
        
        $products = $db->select($productSql, [$location_id]);
        
        echo json_encode([
            'success' => true,
            'summary' => $summary,
            'products' => $products
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting batch transfer summary: ' . $e->getMessage()
        ]);
    }
}

?>

