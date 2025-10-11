<?php
// Sales Management Functions
// This file contains all sales-related backend functions

function save_pos_sale($conn, $data) {
    try {
        // Expected payload: transactionId (client ref), totalAmount, referenceNumber, terminalName, paymentMethod, location_name, items:[{product_id, quantity, price}]
        $clientTxnId = $data['transactionId'] ?? '';
        $totalAmount = isset($data['totalAmount']) ? (float)$data['totalAmount'] : 0.0;
        $referenceNumber = $data['referenceNumber'] ?? '';
        $terminalName = trim($data['terminalName'] ?? 'Convenience POS');
        $paymentMethodRaw = (string)($data['paymentMethod'] ?? 'cash');
        $locationName = $data['location_name'] ?? 'Unknown Location';
        $items = $data['items'] ?? [];

        if ($totalAmount <= 0 || !is_array($items) || count($items) === 0) {
            echo json_encode([ 'success' => false, 'message' => 'Invalid sale payload' ]);
            return;
        }

        $conn->beginTransaction();

        // Ensure terminal exists or create it
        $stmt = $conn->prepare("SELECT terminal_id FROM tbl_pos_terminal WHERE terminal_name = :name LIMIT 1");
        $stmt->execute([ ':name' => $terminalName ]);
        $terminalId = $stmt->fetchColumn();
        if (!$terminalId) {
            // Default shift_id to 1 if unknown
            $ins = $conn->prepare("INSERT INTO tbl_pos_terminal (terminal_name, shift_id) VALUES (:name, 1)");
            $ins->execute([ ':name' => $terminalName ]);
            $terminalId = (int)$conn->lastInsertId();
        }

        // Determine employee: prefer explicit payload, then session
        $empId = isset($data['emp_id']) ? (int)$data['emp_id'] : 0;
        if ($empId <= 0) {
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
            if (!empty($_SESSION['user_id'])) { $empId = (int)$_SESSION['user_id']; }
        }
        if ($empId <= 0) { $empId = 1; }

        // Normalize payment method to enum values ('cash','card','Gcash')
        $pt = strtolower(trim($paymentMethodRaw));
        if ($pt === 'gcash' || $pt === 'g-cash' || $pt === 'g cash') { $paymentEnum = 'Gcash'; }
        elseif ($pt === 'card') { $paymentEnum = 'card'; }
        else { $paymentEnum = 'cash'; }

        // Create transaction row using AUTO_INCREMENT id
        $txnStmt = $conn->prepare("INSERT INTO tbl_pos_transaction (date, time, emp_id, payment_type) VALUES (CURDATE(), CURTIME(), :emp, :ptype)");
        $txnStmt->execute([
            ':emp' => $empId,
            ':ptype' => $paymentEnum
        ]);
        $transactionId = (int)$conn->lastInsertId();

        // Create sales header
        $hdrStmt = $conn->prepare("INSERT INTO tbl_pos_sales_header (transaction_id, total_amount, reference_number, terminal_id) VALUES (:txn, :total, :ref, :term)");
        $hdrStmt->execute([
            ':txn' => $transactionId,
            ':total' => $totalAmount,
            ':ref' => $referenceNumber,
            ':term' => $terminalId
        ]);
        $salesHeaderId = (int)$conn->lastInsertId();

        // Insert each item into details
        $dtlStmt = $conn->prepare("INSERT INTO tbl_pos_sales_details (sales_header_id, product_id, quantity, price) VALUES (:hdr, :pid, :qty, :price)");
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? $it['id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $price = (float)($it['price'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $dtlStmt->execute([
                    ':hdr' => $salesHeaderId,
                    ':pid' => $pid,
                    ':qty' => $qty,
                    ':price' => $price
                ]);
            }
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Sale saved successfully',
            'data' => [
                'transaction_id' => $transactionId,
                'sales_header_id' => $salesHeaderId,
                'client_txn_id' => $clientTxnId,
                'total_amount' => $totalAmount,
                'payment_method' => $paymentEnum,
                'terminal_id' => $terminalId,
                'items_count' => count($items)
            ]
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Error saving sale: ' . $e->getMessage()
        ]);
    }
}

function get_today_sales($conn, $data) {
    try {
        $cashier_username = $data['cashier_username'] ?? 'Admin';
        $location_name = $data['location_name'] ?? '';
        $terminal_name = $data['terminal_name'] ?? '';
        
        // Get today's date
        $today = date('Y-m-d');
        
        // Build the query to get today's sales data based on actual database schema
        $sql = "
            SELECT 
                COUNT(DISTINCT pt.transaction_id) as total_transactions,
                COALESCE(SUM(psh.total_amount), 0) as total_sales,
                COALESCE(SUM(CASE WHEN pt.payment_type = 'cash' THEN psh.total_amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN pt.payment_type = 'Gcash' THEN psh.total_amount ELSE 0 END), 0) as gcash_sales,
                COALESCE(SUM(CASE WHEN pt.payment_type = 'card' THEN psh.total_amount ELSE 0 END), 0) as card_sales,
                0 as total_discount
            FROM tbl_pos_transaction pt
            LEFT JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            LEFT JOIN tbl_employee e ON pt.emp_id = e.emp_id
            WHERE DATE(pt.date) = :today
        ";
        
        $params = [':today' => $today];
        
        // Add cashier filter if provided
        if (!empty($cashier_username) && $cashier_username !== 'Admin') {
            $sql .= " AND e.username = :username";
            $params[':username'] = $cashier_username;
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $salesData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Since there's no discount_amount column in tbl_pos_sales_header,
        // we'll set total_discount to 0 for now
        $salesData['total_discount'] = 0;
        
        echo json_encode([
            "success" => true,
            "data" => $salesData,
            "date" => $today,
            "cashier" => $cashier_username,
            "location" => $location_name,
            "terminal" => $terminal_name,
            "note" => "Location and terminal filtering not available in current schema"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching today's sales: " . $e->getMessage()
        ]);
    }
}

function get_recent_transactions($conn, $data) {
    try {
        $limit = $data['limit'] ?? 10;
        $location_name = $data['location_name'] ?? '';
        
        $sql = "
            SELECT 
                pt.transaction_id,
                pt.date,
                pt.time,
                pt.payment_type,
                psh.total_amount,
                psh.reference_number,
                pt.location_name,
                pt.terminal_name,
                CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, '')) as cashier_name
            FROM tbl_pos_transaction pt
            LEFT JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            LEFT JOIN tbl_employee e ON pt.emp_id = e.emp_id
            WHERE pt.transaction_id IS NOT NULL
        ";
        
        $params = [];
        
        if (!empty($location_name)) {
            $sql .= " AND pt.location_name = :location";
            $params[':location'] = $location_name;
        }
        
        $sql .= " ORDER BY pt.date DESC, pt.time DESC LIMIT :limit";
        $params[':limit'] = $limit;
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':limit') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "data" => $transactions,
            "count" => count($transactions)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}
?>
