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
    case 'get_transaction_for_exchange':
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
                    h.terminal_id,
                    h.location_name
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
                    d.total,
                    p.product_name,
                    p.barcode,
                    p.selling_price
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

    case 'process_exchange':
        try {
            $original_transaction_id = $data['original_transaction_id'] ?? '';
            $exchange_items = $data['exchange_items'] ?? [];
            $new_items = $data['new_items'] ?? [];
            $location_name = $data['location_name'] ?? '';
            $terminal_name = $data['terminal_name'] ?? '';
            $processed_by = $data['processed_by'] ?? '';
            $manager_approval = $data['manager_approval'] ?? false;
            $manager_username = $data['manager_username'] ?? '';
            
            if (empty($original_transaction_id) || empty($exchange_items) || empty($new_items)) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                break;
            }
            
            // Calculate price differences
            $total_original_value = 0;
            $total_new_value = 0;
            $price_difference = 0;
            
            foreach ($exchange_items as $item) {
                $total_original_value += floatval($item['total']);
            }
            
            foreach ($new_items as $item) {
                $total_new_value += floatval($item['total']);
            }
            
            $price_difference = $total_new_value - $total_original_value;
            
            // Check if manager approval is needed for higher price exchange
            if ($price_difference > 0 && !$manager_approval) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Manager approval required for higher price exchange',
                    'requires_manager_approval' => true,
                    'price_difference' => $price_difference
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Generate exchange reference number
            $exchange_number = 'EXC' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Get location ID
            $locationStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name = ? LIMIT 1");
            $locationStmt->execute([$location_name]);
            $locationResult = $locationStmt->fetch(PDO::FETCH_ASSOC);
            $location_id = $locationResult ? $locationResult['location_id'] : 4;
            
            // Get employee details
            $empDetails = getEmployeeDetails($conn, $processed_by);
            
            // Insert exchange header
            $stmt = $conn->prepare("
                INSERT INTO tbl_pos_exchanges 
                (exchange_id, original_transaction_id, location_name, terminal_name, 
                 processed_by, processed_by_username, total_original_value, total_new_value, 
                 price_difference, manager_approval, manager_username, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $exchange_number,
                $original_transaction_id,
                $location_name,
                $terminal_name,
                $empDetails['emp_id'],
                $empDetails['emp_name'],
                $total_original_value,
                $total_new_value,
                $price_difference,
                $manager_approval ? 1 : 0,
                $manager_username,
            ]);
            
            $exchange_id = $conn->lastInsertId();
            
            // Process returned items (mark as exchange return stock)
            foreach ($exchange_items as $item) {
                // Insert exchange return item
                $stmt = $conn->prepare("
                    INSERT INTO tbl_pos_exchange_returns 
                    (exchange_id, product_id, quantity, price, total, item_condition)
                    VALUES (?, ?, ?, ?, ?, 'good')
                ");
                $stmt->execute([
                    $exchange_number,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['total']
                ]);
                
                // Update product stock (add back to inventory)
                $stmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET quantity = quantity + ?
                    WHERE product_id = ? AND location_id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id'], $location_id]);
                
                // Get a batch_id for this product
                $batchStmt = $conn->prepare("
                    SELECT batch_id FROM tbl_batch 
                    WHERE batch_id IN (
                        SELECT DISTINCT batch_id FROM tbl_fifo_stock WHERE product_id = ?
                        UNION
                        SELECT DISTINCT batch_id FROM tbl_stock_summary WHERE product_id = ?
                    )
                    LIMIT 1
                ");
                $batchStmt->execute([$item['product_id'], $item['product_id']]);
                $batch_id_return = $batchStmt->fetchColumn();
                
                if ($batch_id_return) {
                    // Log stock movement for return
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_stock_movements 
                        (product_id, batch_id, movement_type, quantity, reference_no, movement_date, created_by, notes)
                        VALUES (?, ?, 'IN', ?, ?, NOW(), ?, ?)
                    ");
                    $stmt->execute([
                        $item['product_id'],
                        $batch_id_return,
                        $item['quantity'],
                        $exchange_number,
                        $empDetails['emp_id'],
                        "Exchange return - stock restored for exchange {$exchange_number}"
                    ]);
                } else {
                    error_log("Warning: No batch_id found for returned product {$item['product_id']} in exchange {$exchange_number}");
                }
            }
            
            // Process new items (mark as sold)
            foreach ($new_items as $item) {
                // Insert exchange new item
                $stmt = $conn->prepare("
                    INSERT INTO tbl_pos_exchange_new_items 
                    (exchange_id, product_id, quantity, price, total)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $exchange_number,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['total']
                ]);
                
                // Update product stock (subtract from inventory)
                $stmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET quantity = quantity - ?
                    WHERE product_id = ? AND location_id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id'], $location_id]);
                
                // Get a batch_id for this product
                $batchStmt = $conn->prepare("
                    SELECT batch_id FROM tbl_batch 
                    WHERE batch_id IN (
                        SELECT DISTINCT batch_id FROM tbl_fifo_stock WHERE product_id = ?
                        UNION
                        SELECT DISTINCT batch_id FROM tbl_stock_summary WHERE product_id = ?
                    )
                    LIMIT 1
                ");
                $batchStmt->execute([$item['product_id'], $item['product_id']]);
                $batch_id_sale = $batchStmt->fetchColumn();
                
                if ($batch_id_sale) {
                    // Log stock movement for sale
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_stock_movements 
                        (product_id, batch_id, movement_type, quantity, reference_no, movement_date, created_by, notes)
                        VALUES (?, ?, 'OUT', ?, ?, NOW(), ?, ?)
                    ");
                    $stmt->execute([
                        $item['product_id'],
                        $batch_id_sale,
                        $item['quantity'],
                        $exchange_number,
                        $empDetails['emp_id'],
                        "Exchange sale - stock deducted for exchange {$exchange_number}"
                    ]);
                } else {
                    error_log("Warning: No batch_id found for exchanged product {$item['product_id']} in exchange {$exchange_number}");
                }
            }
            
            // Create new transaction record for the exchange
            $newTransactionStmt = $conn->prepare("
                INSERT INTO tbl_pos_transaction 
                (date, time, payment_type, location_name, terminal_name, user_id, username)
                VALUES (CURDATE(), CURTIME(), 'exchange', ?, ?, ?, ?)
            ");
            $newTransactionStmt->execute([
                $location_name,
                $terminal_name,
                $empDetails['emp_id'],
                $empDetails['emp_name']
            ]);
            $new_transaction_id = $conn->lastInsertId();
            
            // Create sales header for exchange
            $newSalesHeaderStmt = $conn->prepare("
                INSERT INTO tbl_pos_sales_header 
                (transaction_id, reference_number, total_amount, location_name, terminal_name, user_id, username)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $newSalesHeaderStmt->execute([
                $new_transaction_id,
                $exchange_number,
                $total_new_value,
                $location_name,
                $terminal_name,
                $empDetails['emp_id'],
                $empDetails['emp_name']
            ]);
            $new_sales_header_id = $conn->lastInsertId();
            
            // Create sales details for new items
            foreach ($new_items as $item) {
                $stmt = $conn->prepare("
                    INSERT INTO tbl_pos_sales_details 
                    (sales_header_id, product_id, quantity, price, total)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $new_sales_header_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['total']
                ]);
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Exchange processed successfully',
                'exchange_number' => $exchange_number,
                'exchange_id' => $exchange_id,
                'new_transaction_id' => $new_transaction_id,
                'price_difference' => $price_difference,
                'total_original_value' => $total_original_value,
                'total_new_value' => $total_new_value
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

    case 'get_exchange_history':
        try {
            $limit = intval($data['limit'] ?? 50);
            $offset = intval($data['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT 
                    pe.id,
                    pe.exchange_id,
                    pe.original_transaction_id,
                    pe.location_name,
                    pe.terminal_name,
                    pe.processed_by_username,
                    pe.total_original_value,
                    pe.total_new_value,
                    pe.price_difference,
                    pe.manager_approval,
                    pe.manager_username,
                    pe.status,
                    pe.created_at
                FROM tbl_pos_exchanges pe
                ORDER BY pe.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindParam(1, $limit, PDO::PARAM_INT);
            $stmt->bindParam(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $exchanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $exchanges
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;

    case 'get_exchange_details':
        try {
            $exchange_id = $data['exchange_id'] ?? '';
            
            if (empty($exchange_id)) {
                echo json_encode(['success' => false, 'message' => 'Exchange ID is required']);
                break;
            }
            
            // Get exchange header
            $stmt = $conn->prepare("
                SELECT * FROM tbl_pos_exchanges WHERE exchange_id = ?
            ");
            $stmt->execute([$exchange_id]);
            $exchange = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$exchange) {
                echo json_encode(['success' => false, 'message' => 'Exchange not found']);
                break;
            }
            
            // Get returned items
            $returnedStmt = $conn->prepare("
                SELECT 
                    per.product_id,
                    p.product_name,
                    per.quantity,
                    per.price,
                    per.total,
                    per.item_condition
                FROM tbl_pos_exchange_returns per
                LEFT JOIN tbl_product p ON per.product_id = p.product_id
                WHERE per.exchange_id = ?
            ");
            $returnedStmt->execute([$exchange_id]);
            $returned_items = $returnedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get new items
            $newStmt = $conn->prepare("
                SELECT 
                    pen.product_id,
                    p.product_name,
                    pen.quantity,
                    pen.price,
                    pen.total
                FROM tbl_pos_exchange_new_items pen
                LEFT JOIN tbl_product p ON pen.product_id = p.product_id
                WHERE pen.exchange_id = ?
            ");
            $newStmt->execute([$exchange_id]);
            $new_items = $newStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'exchange' => $exchange,
                'returned_items' => $returned_items,
                'new_items' => $new_items
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

