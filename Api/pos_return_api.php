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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = []; }
$action = $_GET['action'] ?? ($data['action'] ?? '');

switch ($action) {
    case 'process_customer_return':
        error_log('POS Return API: Processing customer return with data: ' . json_encode($data));
        try {
            $return_number = $data['return_number'] ?? '';
            $original_transaction_id = $data['original_transaction_id'] ?? '';
            $return_reason = $data['return_reason'] ?? '';
            $location_name = $data['location_name'] ?? '';
            $terminal_name = $data['terminal_name'] ?? '';
            $processed_by = $data['processed_by'] ?? '';
            $items = $data['items'] ?? [];
            
            if (empty($return_number) || empty($original_transaction_id) || empty($return_reason) || empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Calculate total return amount
            $total_return_amount = 0;
            foreach ($items as $item) {
                $total_return_amount += floatval($item['total_amount']);
            }
            
            // For now, use the provided location_name to find the location_id
            // This ensures we're updating stock in the correct location
            $locationStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name = ? LIMIT 1");
            $locationStmt->execute([$location_name]);
            $locationResult = $locationStmt->fetch(PDO::FETCH_ASSOC);
            
            $returnLocationId = $locationResult ? $locationResult['location_id'] : 4; // Default to Convenience Store
            $returnLocationName = $location_name;
            $returnTerminalName = $terminal_name;
            
            error_log("Using location: ID={$returnLocationId}, Name={$returnLocationName}, Terminal={$returnTerminalName}");

            // Insert return header using existing tbl_pos_returns table
            $stmt = $conn->prepare("
                INSERT INTO tbl_pos_returns 
                (return_id, original_transaction_id, reason, method, item_condition, location_name, terminal_name, 
                 user_id, username, total_refund, status)
                VALUES (?, ?, ?, 'refund', 'good', ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $return_number, 
                $original_transaction_id, 
                $return_reason, 
                $returnLocationName, // Use original location
                $returnTerminalName, // Use original terminal
                $processed_by,
                $processed_by, // username (assuming processed_by is username)
                $total_return_amount
            ]);
            
            $return_id = $conn->lastInsertId();

            // Get original sales data to modify
            $originalSalesStmt = $conn->prepare("
                SELECT sh.sales_header_id, sh.total_amount, sh.reference_number, sh.transaction_id
                FROM tbl_pos_sales_header sh
                WHERE sh.reference_number = ?
            ");
            $originalSalesStmt->execute([$original_transaction_id]);
            $originalSales = $originalSalesStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$originalSales) {
                throw new Exception("Original transaction not found: {$original_transaction_id}");
            }
            
            $originalSalesHeaderId = $originalSales['sales_header_id'];
            $originalTotalAmount = $originalSales['total_amount'];
            $originalReferenceNumber = $originalSales['reference_number'];
            
            // Calculate new total after returns
            $newTotalAmount = $originalTotalAmount - $total_return_amount;
            
            // Insert return details and update stock
            foreach ($items as $item) {
                // Insert return item using existing tbl_pos_return_items table
                $stmt = $conn->prepare("
                    INSERT INTO tbl_pos_return_items 
                    (return_id, product_id, quantity, price, total, item_condition)
                    VALUES (?, ?, ?, ?, ?, 'good')
                ");
                $stmt->execute([
                    $return_number, // Use return_id (string) instead of numeric ID
                    $item['product_id'],
                    $item['return_quantity'],
                    $item['unit_price'],
                    $item['total_amount']
                ]);
                
                // NOTE: Stock update is now handled in approve_return action after admin approval
                // Stock will be updated when admin approves the return in Return Management
                
                // Log that return item was created (stock update will happen after approval)
                error_log("Return item created for product {$item['product_id']}: {$item['return_quantity']} units (pending approval)");
                
                // Update or remove sales details based on return quantity
                $stmt = $conn->prepare("
                    SELECT quantity FROM tbl_pos_sales_details 
                    WHERE sales_header_id = ? AND product_id = ?
                ");
                $stmt->execute([$originalSalesHeaderId, $item['product_id']]);
                $salesDetail = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($salesDetail) {
                    $remainingQuantity = $salesDetail['quantity'] - $item['return_quantity'];
                    
                    if ($remainingQuantity <= 0) {
                        // Remove the sales detail record completely
                        $stmt = $conn->prepare("
                            DELETE FROM tbl_pos_sales_details 
                            WHERE sales_header_id = ? AND product_id = ?
                        ");
                        $stmt->execute([$originalSalesHeaderId, $item['product_id']]);
                        error_log("Removed sales detail for product {$item['product_id']} - fully returned");
                    } else {
                        // Update the quantity in sales details
                        $stmt = $conn->prepare("
                            UPDATE tbl_pos_sales_details 
                            SET quantity = ?
                            WHERE sales_header_id = ? AND product_id = ?
                        ");
                        $stmt->execute([$remainingQuantity, $originalSalesHeaderId, $item['product_id']]);
                        error_log("Updated sales detail for product {$item['product_id']}: remaining quantity = {$remainingQuantity}");
                    }
                }
            }
            
            // Update sales header total amount
            if ($newTotalAmount <= 0) {
                // If all items are returned, mark the transaction as fully returned
                $stmt = $conn->prepare("
                    UPDATE tbl_pos_sales_header 
                    SET total_amount = 0, reference_number = CONCAT(reference_number, '_RETURNED')
                    WHERE sales_header_id = ?
                ");
                $stmt->execute([$originalSalesHeaderId]);
                error_log("Marked transaction {$original_transaction_id} as fully returned");
            } else {
                // Update the total amount
                $stmt = $conn->prepare("
                    UPDATE tbl_pos_sales_header 
                    SET total_amount = ?
                    WHERE sales_header_id = ?
                ");
                $stmt->execute([$newTotalAmount, $originalSalesHeaderId]);
                error_log("Updated transaction {$original_transaction_id} total: {$originalTotalAmount} -> {$newTotalAmount}");
            }
            
            // Commit transaction
            $conn->commit();
            
            // Trigger notification event for new return
            // This will be picked up by the notification system
            error_log("New return created: {$return_number} from {$location_name} - Amount: {$total_return_amount}");
            
            echo json_encode([
                "success" => true,
                "message" => "Return processed successfully",
                "return_number" => $return_number,
                "return_id" => $return_id,
                "total_amount" => $total_return_amount,
                "notification_triggered" => true
            ]);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_pending_returns':
        try {
            $limit = intval($data['limit'] ?? 50);
            $offset = intval($data['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT 
                    pr.id,
                    pr.return_id,
                    pr.original_transaction_id,
                    pr.reason,
                    pr.method,
                    pr.item_condition,
                    pr.location_name,
                    pr.terminal_name,
                    pr.username,
                    pr.total_refund,
                    pr.status,
                    pr.created_at
                FROM tbl_pos_returns pr
                WHERE pr.status = 'pending'
                ORDER BY pr.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindParam(1, $limit, PDO::PARAM_INT);
            $stmt->bindParam(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $returns
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_all_returns':
        try {
            $limit = intval($data['limit'] ?? 50);
            $offset = intval($data['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT 
                    pr.id,
                    pr.return_id,
                    pr.original_transaction_id,
                    pr.reason,
                    pr.method,
                    pr.item_condition,
                    pr.location_name,
                    pr.terminal_name,
                    pr.username,
                    pr.total_refund,
                    pr.status,
                    pr.created_at,
                    pr.approved_by,
                    pr.approved_by_username,
                    pr.approved_at
                FROM tbl_pos_returns pr
                ORDER BY pr.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindParam(1, $limit, PDO::PARAM_INT);
            $stmt->bindParam(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $returns
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_return_history':
        try {
            $limit = intval($data['limit'] ?? 50);
            $offset = intval($data['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT 
                    pr.id,
                    pr.return_id,
                    pr.original_transaction_id,
                    pr.reason,
                    pr.method,
                    pr.item_condition,
                    pr.location_name,
                    pr.terminal_name,
                    pr.username,
                    pr.total_refund,
                    pr.status,
                    pr.created_at
                FROM tbl_pos_returns pr
                ORDER BY pr.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindParam(1, $limit, PDO::PARAM_INT);
            $stmt->bindParam(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $returns
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_return_details':
        try {
            $return_id = $data['return_id'] ?? '';
            
            if (empty($return_id)) {
                echo json_encode(['success' => false, 'message' => 'Return ID is required']);
                break;
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    pri.product_id,
                    p.product_name,
                    pri.quantity,
                    pri.price,
                    pri.total,
                    pri.item_condition
                FROM tbl_pos_return_items pri
                LEFT JOIN tbl_product p ON pri.product_id = p.product_id
                WHERE pri.return_id = ?
            ");
            $stmt->execute([$return_id]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $details
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'approve_return':
        try {
            $return_id = $data['return_id'] ?? '';
            $approved_by = $data['approved_by'] ?? 1;
            $approved_by_username = $data['approved_by_username'] ?? 'Admin';
            $notes = $data['notes'] ?? '';
            
            if (empty($return_id)) {
                echo json_encode(['success' => false, 'message' => 'Return ID is required']);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Get employee details for proper logging
            $empDetails = getEmployeeDetails($conn, $approved_by);
            
            // Get return details
            $stmt = $conn->prepare("
                SELECT pr.*, pri.product_id, pri.quantity, pri.price, pri.total
                FROM tbl_pos_returns pr
                LEFT JOIN tbl_pos_return_items pri ON pr.return_id = pri.return_id
                WHERE pr.return_id = ? AND pr.status = 'pending'
            ");
            $stmt->execute([$return_id]);
            $returnData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($returnData)) {
                throw new Exception("Return not found or already processed");
            }
            
            $return = $returnData[0];
            $location_name = $return['location_name'];
            
            // Update return status to approved
            $stmt = $conn->prepare("
                UPDATE tbl_pos_returns 
                SET status = 'approved', 
                    approved_by = ?, 
                    approved_by_username = ?, 
                    approved_at = NOW(),
                    notes = ?
                WHERE return_id = ?
            ");
            $stmt->execute([$approved_by, $approved_by_username, $notes, $return_id]);
            
            // Restore stock for each returned item
            $restored_items = [];
            $total_quantity_restored = 0;
            
            foreach ($returnData as $item) {
                if ($item['product_id']) {
                    // Get location ID
                    $locationStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name = ? LIMIT 1");
                    $locationStmt->execute([$location_name]);
                    $locationResult = $locationStmt->fetch(PDO::FETCH_ASSOC);
                    $location_id = $locationResult ? $locationResult['location_id'] : 4;
                    
                    // Directly restore to tbl_transfer_batch_details - no changes to tbl_product
                    $quantity_to_restore = $item['quantity'];
                    $restored_batches = [];
                    
                    // Find the original consumed batch and restore it directly
                    $stmt = $conn->prepare("
                        SELECT tbd.id, tbd.batch_reference, tbd.quantity, tbd.product_id, tbd.location_id
                        FROM tbl_transfer_batch_details tbd
                        WHERE tbd.product_id = ? AND tbd.location_id = ?
                        ORDER BY tbd.created_at ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$item['product_id'], $location_id]);
                    $originalBatch = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($originalBatch) {
                        // Restore quantity to the original batch - keep the same batch_reference
                        $stmt = $conn->prepare("
                            UPDATE tbl_transfer_batch_details 
                            SET quantity = quantity + ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$quantity_to_restore, $originalBatch['id']]);
                        
                        $restored_batches[] = [
                            'batch_reference' => $originalBatch['batch_reference'],
                            'restored_quantity' => $quantity_to_restore,
                            'system' => 'Transfer Batch Details'
                        ];
                        
                        error_log("Restored original batch {$originalBatch['batch_reference']} for product {$item['product_id']}: {$quantity_to_restore} units (batch ID: {$originalBatch['id']})");
                    } else {
                        // No batch found - this shouldn't happen, but log it
                        error_log("Warning: No batch found to restore for product {$item['product_id']} at location {$location_id}");
                    }
                    
                    $restored_items[] = $item['product_id'];
                    $total_quantity_restored += $item['quantity'];
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Return approved successfully',
                'location_name' => $location_name,
                'restored_items' => implode(', ', $restored_items),
                'total_quantity_restored' => $total_quantity_restored,
                'transfer_details_updated' => true,
                'restored_batches' => $restored_batches,
                'note' => 'Returned quantities have been restored to original batches in tbl_transfer_batch_details'
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'reject_return':
        try {
            $return_id = $data['return_id'] ?? '';
            $rejected_by = $data['rejected_by'] ?? 1;
            $rejected_by_username = $data['rejected_by_username'] ?? 'Admin';
            $rejection_reason = $data['rejection_reason'] ?? '';
            
            if (empty($return_id)) {
                echo json_encode(['success' => false, 'message' => 'Return ID is required']);
                break;
            }
            
            if (empty($rejection_reason)) {
                echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
                break;
            }
            
            // Update return status to rejected
            $stmt = $conn->prepare("
                UPDATE tbl_pos_returns 
                SET status = 'rejected', 
                    rejected_by = ?, 
                    rejected_by_username = ?, 
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE return_id = ? AND status = 'pending'
            ");
            $result = $stmt->execute([$rejected_by, $rejected_by_username, $rejection_reason, $return_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception("Return not found or already processed");
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Return rejected successfully'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get_transaction_details':
        try {
            $transaction_id = $data['transaction_id'] ?? '';
            if (empty($transaction_id)) {
                echo json_encode(['success' => false, 'message' => 'Transaction ID is required']);
                break;
            }

            // Find transaction by reference number (TXN format)
            $stmt = $conn->prepare("
                SELECT 
                    t.transaction_id,
                    t.date,
                    t.time,
                    t.payment_type,
                    h.total_amount,
                    h.reference_number,
                    h.terminal_id
                FROM tbl_pos_transaction t
                JOIN tbl_pos_sales_header h ON t.transaction_id = h.transaction_id
                WHERE h.reference_number = ?
            ");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                break;
            }

            // Get transaction items
            $itemsStmt = $conn->prepare("
                SELECT 
                    d.product_id,
                    d.quantity,
                    d.price,
                    p.product_name,
                    p.barcode
                FROM tbl_pos_sales_details d
                JOIN tbl_product p ON d.product_id = p.product_id
                WHERE d.sales_header_id = (
                    SELECT sales_header_id FROM tbl_pos_sales_header 
                    WHERE transaction_id = ?
                )
                ORDER BY d.sales_details_id
            ");
            $itemsStmt->execute([$transaction['transaction_id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'transaction' => [
                    ...$transaction,
                    'items' => $items
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;

    case 'get_recent_transactions':
        try {
            $limit = (int)($data['limit'] ?? 20);
            $location_name = $data['location_name'] ?? '';

            $whereClause = "";
            $params = [];
            
            if (!empty($location_name)) {
                $whereClause = "WHERE h.reference_number IS NOT NULL";
            }

            $stmt = $conn->prepare("
                SELECT 
                    t.transaction_id,
                    t.date,
                    t.time,
                    t.payment_type,
                    h.total_amount,
                    h.reference_number,
                    h.terminal_id,
                    (SELECT COUNT(*) FROM tbl_pos_sales_details d WHERE d.sales_header_id = h.sales_header_id) as item_count
                FROM tbl_pos_transaction t
                JOIN tbl_pos_sales_header h ON t.transaction_id = h.transaction_id
                $whereClause
                ORDER BY t.transaction_id DESC
                LIMIT ?
            ");
            
            $params[] = $limit;
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'transactions' => $transactions
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>
