<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'conn.php';

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = []; }
    
    $action = $_GET['action'] ?? ($data['action'] ?? '');
    
    switch ($action) {
        case 'get_batch_transfer_details':
            getBatchTransferDetails($data);
            break;
            
        case 'create_batch_transfer_detail':
            createBatchTransferDetail($data);
            break;
            
        case 'update_batch_transfer_detail':
            updateBatchTransferDetail($data);
            break;
            
        case 'delete_batch_transfer_detail':
            deleteBatchTransferDetail($data);
            break;
            
        case 'get_batch_by_product_location':
            getBatchByProductLocation($data);
            break;
            
        case 'consume_batch_stock':
            consumeBatchStock($data);
            break;
            
        case 'get_batch_summary':
            getBatchSummary($data);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

/**
 * Get batch transfer details for a specific product and location
 */
function getBatchTransferDetails($data) {
    global $conn;
    
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        if (!$product_id || !$location_id) {
            echo json_encode(['success' => false, 'message' => 'Product ID and Location ID are required']);
            return;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                btd.batch_transfer_id,
                btd.batch_id,
                btd.batch_reference,
                btd.quantity_used,
                btd.srp,
                btd.expiration_date,
                btd.status,
                btd.transfer_date,
                p.product_name,
                p.barcode,
                p.unit_price,
                b.brand,
                s.supplier_name,
                l.location_name
            FROM tbl_batch_transfer_details btd
            LEFT JOIN tbl_product p ON btd.product_id = p.product_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_location l ON btd.location_id = l.location_id
            WHERE btd.product_id = ? AND btd.location_id = ?
            ORDER BY btd.batch_transfer_id ASC
        ");
        
        $stmt->execute([$product_id, $location_id]);
        $batch_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate summary
        $total_stock = array_sum(array_column($batch_details, 'quantity_used'));
        $total_batches = count($batch_details);
        $available_batches = count(array_filter($batch_details, function($item) {
            return $item['status'] === 'Available';
        }));
        $consumed_batches = count(array_filter($batch_details, function($item) {
            return $item['status'] === 'Consumed';
        }));
        
        echo json_encode([
            'success' => true,
            'data' => [
                'batch_details' => $batch_details,
                'summary' => [
                    'total_stock' => $total_stock,
                    'total_batches' => $total_batches,
                    'available_batches' => $available_batches,
                    'consumed_batches' => $consumed_batches
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Create a new batch transfer detail record
 */
function createBatchTransferDetail($data) {
    global $conn;
    
    try {
        $product_id = $data['product_id'] ?? 0;
        $batch_id = $data['batch_id'] ?? 0;
        $batch_reference = $data['batch_reference'] ?? '';
        $quantity_used = $data['quantity_used'] ?? 0;
        $srp = $data['srp'] ?? 0.00;
        $expiration_date = $data['expiration_date'] ?? null;
        $location_id = $data['location_id'] ?? 0;
        $status = $data['status'] ?? 'Available';
        
        if (!$product_id || !$batch_id || !$location_id) {
            echo json_encode(['success' => false, 'message' => 'Product ID, Batch ID, and Location ID are required']);
            return;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO tbl_batch_transfer_details 
            (product_id, batch_id, batch_reference, quantity_used, srp, expiration_date, status, location_id, transfer_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $product_id, $batch_id, $batch_reference, $quantity_used, 
            $srp, $expiration_date, $status, $location_id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Batch transfer detail created successfully',
            'batch_transfer_id' => $conn->lastInsertId()
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Update batch transfer detail record
 */
function updateBatchTransferDetail($data) {
    global $conn;
    
    try {
        $batch_transfer_id = $data['batch_transfer_id'] ?? 0;
        $quantity_used = $data['quantity_used'] ?? null;
        $unit_cost = $data['unit_cost'] ?? null;
        $srp = $data['srp'] ?? null;
        $status = $data['status'] ?? null;
        
        if (!$batch_transfer_id) {
            echo json_encode(['success' => false, 'message' => 'Batch Transfer ID is required']);
            return;
        }
        
        $update_fields = [];
        $params = [];
        
        if ($quantity_used !== null) {
            $update_fields[] = "quantity_used = ?";
            $params[] = $quantity_used;
        }
        
        if ($unit_cost !== null) {
            $update_fields[] = "unit_cost = ?";
            $params[] = $unit_cost;
        }
        
        if ($srp !== null) {
            $update_fields[] = "srp = ?";
            $params[] = $srp;
        }
        
        if ($status !== null) {
            $update_fields[] = "status = ?";
            $params[] = $status;
        }
        
        if (empty($update_fields)) {
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            return;
        }
        
        $update_fields[] = "updated_at = NOW()";
        $params[] = $batch_transfer_id;
        
        $stmt = $conn->prepare("
            UPDATE tbl_batch_transfer_details 
            SET " . implode(', ', $update_fields) . "
            WHERE batch_transfer_id = ?
        ");
        
        $stmt->execute($params);
        
        echo json_encode([
            'success' => true,
            'message' => 'Batch transfer detail updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Delete batch transfer detail record
 */
function deleteBatchTransferDetail($data) {
    global $conn;
    
    try {
        $batch_transfer_id = $data['batch_transfer_id'] ?? 0;
        
        if (!$batch_transfer_id) {
            echo json_encode(['success' => false, 'message' => 'Batch Transfer ID is required']);
            return;
        }
        
        $stmt = $conn->prepare("DELETE FROM tbl_batch_transfer_details WHERE batch_transfer_id = ?");
        $stmt->execute([$batch_transfer_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Batch transfer detail deleted successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Get batch details by product and location
 */
function getBatchByProductLocation($data) {
    global $conn;
    
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        if (!$product_id || !$location_id) {
            echo json_encode(['success' => false, 'message' => 'Product ID and Location ID are required']);
            return;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                btd.*,
                p.product_name,
                p.barcode,
                l.location_name
            FROM tbl_batch_transfer_details btd
            LEFT JOIN tbl_product p ON btd.product_id = p.product_id
            LEFT JOIN tbl_location l ON btd.location_id = l.location_id
            WHERE btd.product_id = ? AND btd.location_id = ?
            ORDER BY btd.batch_transfer_id ASC
        ");
        
        $stmt->execute([$product_id, $location_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $batches
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Consume batch stock (mark as consumed)
 */
function consumeBatchStock($data) {
    global $conn;
    
    try {
        $batch_transfer_id = $data['batch_transfer_id'] ?? 0;
        $quantity_to_consume = $data['quantity_to_consume'] ?? 0;
        
        if (!$batch_transfer_id || !$quantity_to_consume) {
            echo json_encode(['success' => false, 'message' => 'Batch Transfer ID and quantity to consume are required']);
            return;
        }
        
        // Get current batch details
        $stmt = $conn->prepare("
            SELECT quantity_used, status 
            FROM tbl_batch_transfer_details 
            WHERE batch_transfer_id = ?
        ");
        $stmt->execute([$batch_transfer_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            echo json_encode(['success' => false, 'message' => 'Batch not found']);
            return;
        }
        
        $new_quantity = $batch['quantity_used'] - $quantity_to_consume;
        $new_status = $new_quantity <= 0 ? 'Consumed' : 'Available';
        
        // Update batch
        $update_stmt = $conn->prepare("
            UPDATE tbl_batch_transfer_details 
            SET quantity_used = ?, status = ?, updated_at = NOW()
            WHERE batch_transfer_id = ?
        ");
        $update_stmt->execute([$new_quantity, $new_status, $batch_transfer_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Batch stock consumed successfully',
            'remaining_quantity' => $new_quantity,
            'status' => $new_status
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Get batch summary for a product and location
 */
function getBatchSummary($data) {
    global $conn;
    
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        if (!$product_id || !$location_id) {
            echo json_encode(['success' => false, 'message' => 'Product ID and Location ID are required']);
            return;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_batches,
                SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) as available_batches,
                SUM(CASE WHEN status = 'Consumed' THEN 1 ELSE 0 END) as consumed_batches,
                SUM(CASE WHEN status = 'Expired' THEN 1 ELSE 0 END) as expired_batches,
                SUM(quantity_used) as total_quantity,
                SUM(CASE WHEN status = 'Available' THEN quantity_used ELSE 0 END) as available_quantity,
                AVG(unit_cost) as avg_unit_cost,
                AVG(srp) as avg_srp
            FROM tbl_batch_transfer_details
            WHERE product_id = ? AND location_id = ?
        ");
        
        $stmt->execute([$product_id, $location_id]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $summary
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

