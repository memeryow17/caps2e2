<?php
// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Prevent PHP warnings/notices from breaking JSON responses
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// You can change the log file path if needed
ini_set('error_log', __DIR__ . '/php_errors.log');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'conn.php';

// Get action from query parameter
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create_purchase_order':
            createPurchaseOrder($conn);
            break;
        case 'purchase_orders':
            getPurchaseOrders($conn);
            break;
        case 'purchase_order_details':
            getPurchaseOrderDetails($conn);
            break;
        case 'receive_items':
            receiveItems($conn);
            break;
        case 'suppliers':
            getSuppliers($conn);
            break;
        case 'products':
            getProducts($conn);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function createPurchaseOrder($conn) {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid input data']);
        return;
    }
    
    // Validate required fields
    if (!isset($input['supplier_id']) || !isset($input['items']) || empty($input['items'])) {
        echo json_encode(['success' => false, 'error' => 'Supplier ID and items are required']);
        return;
    }
    
    // Calculate total amount (since we removed pricing, this will be 0)
    $totalAmount = floatval($input['total_amount'] ?? 0);
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Insert purchase order header
        $headerQuery = "INSERT INTO tbl_purchase_order_header (supplier_id, payment_method, total_amount, expected_delivery_date, created_by, status, date, time, notes) 
                       VALUES (?, ?, ?, ?, ?, 'pending', CURDATE(), CURTIME(), ?)";
        $stmt = $conn->prepare($headerQuery);
        $stmt->execute([
            $input['supplier_id'], 
            $input['payment_method'] ?? 'cod', // Add payment method
            $totalAmount, 
            $input['expected_delivery'] ?? null, 
            $input['created_by'] ?? 21, // Use provided employee ID or default
            $input['notes'] ?? ''
        ]);
        
        $purchaseHeaderId = $conn->lastInsertId();
        
        // Insert purchase order details
        $detailQuery = "INSERT INTO tbl_purchase_order_dtl (purchase_header_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
        $detailStmt = $conn->prepare($detailQuery);
        
        foreach ($input['items'] as $item) {
            $productId = $item['product_id'];
            $quantity = intval($item['quantity'] ?? 1);
            $price = floatval($item['price'] ?? 0); // Changed from unit_price to price
            
            $detailStmt->execute([$purchaseHeaderId, $productId, $quantity, $price]);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Purchase order created successfully',
            'purchase_order_id' => $purchaseHeaderId,
            'total_amount' => $totalAmount
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => 'Error creating purchase order: ' . $e->getMessage()]);
    }
}

function getPurchaseOrders($conn) {
    try {
        $query = "SELECT 
                    po.purchase_header_id as id,
                    po.date as order_date,
                    po.expected_delivery_date,
                    po.total_amount,
                    po.status,
                    po.notes,
                    po.payment_method,
                    s.supplier_name,
                    s.supplier_contact
                  FROM tbl_purchase_order_header po
                  JOIN tbl_supplier s ON po.supplier_id = s.supplier_id
                  ORDER BY po.date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $purchaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $purchaseOrders]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getPurchaseOrderDetails($conn) {
    $poId = $_GET['po_id'] ?? 0;
    
    if (!$poId) {
        echo json_encode(['success' => false, 'error' => 'Purchase order ID required']);
        return;
    }
    
    try {
        // Get header
        $headerQuery = "SELECT 
                         po.*, s.supplier_name
                       FROM tbl_purchase_order_header po
                       JOIN tbl_supplier s ON po.supplier_id = s.supplier_id
                       WHERE po.purchase_header_id = ?";
        $stmt = $conn->prepare($headerQuery);
        $stmt->execute([$poId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$header) {
            echo json_encode(['success' => false, 'error' => 'Purchase order not found']);
            return;
        }
        
        // Get details
        $detailsQuery = "SELECT 
                          pod.*, p.product_name, c.category_name as category FROM tbl_purchase_order_dtl pod
                        JOIN tbl_product p ON pod.product_id = p.product_id
                        LEFT JOIN tbl_category c ON p.category_id = c.category_id
                        WHERE pod.purchase_header_id = ?";
        $detailStmt = $conn->prepare($detailsQuery);
        $detailStmt->execute([$poId]);
        $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'header' => $header,
            'details' => $details
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function receiveItems($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid input data']);
        return;
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Create receiving header with completed status
        // Truncate fields to prevent truncation errors
        $deliveryReceiptNo = isset($input['delivery_receipt_no']) ? 
            substr(trim($input['delivery_receipt_no']), 0, 100) : '';
        $notes = isset($input['notes']) ? 
            substr(trim($input['notes']), 0, 500) : '';
        
        $receivingQuery = "INSERT INTO tbl_purchase_receiving_header (purchase_header_id, received_by, delivery_receipt_no, notes, receiving_date, receiving_time, status) 
                          VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?)";
        $stmt = $conn->prepare($receivingQuery);
        $stmt->execute([
            $input['purchase_header_id'], 
            21, // Default employee ID
            $deliveryReceiptNo, 
            $notes,
            'completed' // Explicitly pass as parameter to avoid any string issues
        ]);
        
        $receivingId = $conn->lastInsertId();
        
        // Process each received item (keep table compatibility, ignore price/batch/expiry semantically)
        foreach ($input['items'] as $item) {
            $receivedQty = intval($item['received_qty'] ?? 0);
            $productId   = intval($item['product_id'] ?? 0);
            if ($receivedQty <= 0 || $productId <= 0) {
                continue;
            }

            // Prefer ordered qty from payload; otherwise try to infer from PO detail if provided
            $orderedQty = intval($item['ordered_qty'] ?? 0);
            if ($orderedQty <= 0 && isset($item['purchase_dtl_id'])) {
                $purchaseDtlId = intval($item['purchase_dtl_id']);
                $q = $conn->prepare("SELECT quantity FROM tbl_purchase_order_dtl WHERE purchase_dtl_id = ?");
                $q->execute([$purchaseDtlId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row) { $orderedQty = intval($row['quantity']); }
            }

            // Get product name for the detail record and truncate to prevent errors
            $productName = $item['product_name'] ?? 'Unknown Product';
            $productName = substr($productName, 0, 255); // Truncate to 255 chars to prevent truncation errors
            
            // Insert using the original table columns; pass neutral values for removed fields
            $detailQuery = "INSERT INTO tbl_purchase_receiving_dtl (receiving_id, product_id, product_name, ordered_qty, received_qty, unit_price, batch_number, expiration_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $detailStmt = $conn->prepare($detailQuery);
            $detailStmt->execute([
                $receivingId,
                $productId,
                $productName,  // product_name (truncated)
                $orderedQty,
                $receivedQty,
                0,            // unit_price (deprecated)
                '',           // batch_number (deprecated)
                null          // expiration_date (deprecated)
            ]);
        }
        
        // Update purchase order delivery status
        updatePurchaseOrderStatus($conn, $input['purchase_header_id']);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Items received successfully',
            'receiving_id' => $receivingId
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function updateInventory($conn, $productId, $quantity, $unitPrice, $batchNumber, $expirationDate) {
    // Check if inventory table exists and update accordingly
    // This is a simplified version - you may need to adjust based on your actual inventory structure
    
    try {
        // For now, we'll just log the inventory update
        $logQuery = "INSERT INTO inventory_log (product_id, quantity, unit_price, batch_number, expiration_date, action, log_date) 
                     VALUES (?, ?, ?, ?, ?, 'received', NOW())";
        $stmt = $conn->prepare($logQuery);
        $stmt->execute([$productId, $quantity, $unitPrice, $batchNumber, $expirationDate]);
    } catch (Exception $e) {
        // If inventory_log table doesn't exist, just continue
        // You can create this table later for tracking purposes
    }
}

function updatePurchaseOrderStatus($conn, $purchaseOrderId) {
    // Preserve 'approved' status if already approved; otherwise mark as 'to_review'
    try {
        $check = $conn->prepare("SELECT status FROM tbl_purchase_order_header WHERE purchase_header_id = ?");
        $check->execute([$purchaseOrderId]);
        $current = $check->fetchColumn();

        if ($current === 'approved') {
            return; // Do not change status if already approved
        }

        $query = "UPDATE tbl_purchase_order_header SET status = 'to_review' WHERE purchase_header_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$purchaseOrderId]);
    } catch (Exception $e) {
        // Silently ignore status update failure to not block receiving
    }
}

function getSuppliers($conn) {
    try {
        $query = "SELECT supplier_id, supplier_name, supplier_contact FROM tbl_supplier WHERE status = 'active' ORDER BY supplier_name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $suppliers]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getProducts($conn) {
    try {
        $query = "SELECT product_id, product_name, category, srp as unit_price FROM tbl_product WHERE status = 'active' ORDER BY product_name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $products]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

$conn = null; // Close connection
?>
