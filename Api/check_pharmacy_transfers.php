<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/conn.php';

try {
    $conn = getDatabaseConnection();
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = []; }
    $action = $_GET['action'] ?? ($data['action'] ?? 'check_pharmacy_data');

    switch ($action) {
        case 'check_pharmacy_data':
            // Get all locations
            $locationStmt = $conn->prepare("SELECT location_id, location_name FROM tbl_location ORDER BY location_name");
            $locationStmt->execute();
            $locations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $locationMap = [];
            $pharmacyLocationId = null;
            foreach ($locations as $loc) {
                $locationMap[$loc['location_id']] = $loc['location_name'];
                if (strpos(strtolower($loc['location_name']), 'pharmacy') !== false) {
                    $pharmacyLocationId = $loc['location_id'];
                }
            }
            
            // Get all transfer headers
            $transferStmt = $conn->prepare("
                SELECT * FROM tbl_transfer_header 
                WHERE status = 'approved' 
                ORDER BY transfer_header_id DESC
            ");
            $transferStmt->execute();
            $transfers = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get transfer details
            $transferDetailsStmt = $conn->prepare("
                SELECT 
                    td.transfer_header_id,
                    td.product_id,
                    td.qty,
                    th.source_location_id,
                    th.destination_location_id,
                    th.status as transfer_status
                FROM tbl_transfer_dtl td
                JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                WHERE th.status = 'approved'
                ORDER BY td.transfer_header_id DESC
            ");
            $transferDetailsStmt->execute();
            $transferDetails = $transferDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get transfer batch details
            $batchDetailsStmt = $conn->prepare("SELECT * FROM tbl_transfer_batch_details ORDER BY id DESC");
            $batchDetailsStmt->execute();
            $batchDetails = $batchDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get pharmacy batch details specifically
            $pharmacyBatchDetails = [];
            if ($pharmacyLocationId) {
                $pharmacyBatchStmt = $conn->prepare("
                    SELECT * FROM tbl_transfer_batch_details 
                    WHERE location_id = ?
                    ORDER BY id DESC
                ");
                $pharmacyBatchStmt->execute([$pharmacyLocationId]);
                $pharmacyBatchDetails = $pharmacyBatchStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Get pharmacy transfers
            $pharmacyTransfers = [];
            if ($pharmacyLocationId) {
                $pharmacyTransferStmt = $conn->prepare("
                    SELECT 
                        th.transfer_header_id,
                        th.date,
                        th.source_location_id,
                        th.destination_location_id,
                        th.status,
                        td.product_id,
                        td.qty,
                        p.product_name,
                        p.barcode,
                        COALESCE(fs.srp, 0) as price
                    FROM tbl_transfer_header th
                    JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                    LEFT JOIN tbl_product p ON td.product_id = p.product_id
                    LEFT JOIN tbl_fifo_stock fs ON td.product_id = fs.product_id AND fs.available_quantity > 0
                    WHERE th.destination_location_id = ? AND th.status = 'approved'
                    ORDER BY th.transfer_header_id DESC
                ");
                $pharmacyTransferStmt->execute([$pharmacyLocationId]);
                $pharmacyTransfers = $pharmacyTransferStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                "success" => true,
                "data" => [
                    "locations" => $locations,
                    "location_map" => $locationMap,
                    "pharmacy_location_id" => $pharmacyLocationId,
                    "transfers" => $transfers,
                    "transfer_details" => $transferDetails,
                    "batch_details" => $batchDetails,
                    "pharmacy_batch_details" => $pharmacyBatchDetails,
                    "pharmacy_transfers" => $pharmacyTransfers,
                    "summary" => [
                        "total_locations" => count($locations),
                        "total_transfers" => count($transfers),
                        "total_transfer_details" => count($transferDetails),
                        "total_batch_details" => count($batchDetails),
                        "pharmacy_batch_details_count" => count($pharmacyBatchDetails),
                        "pharmacy_transfers_count" => count($pharmacyTransfers)
                    ]
                ]
            ]);
            break;
            
        case 'create_missing_batch_details':
            // Create missing batch details for approved transfers
            $conn->beginTransaction();
            
            try {
                // Get all approved transfers that don't have batch details
                $missingStmt = $conn->prepare("
                    SELECT 
                        td.transfer_header_id,
                        td.product_id,
                        td.qty,
                        th.destination_location_id,
                        p.product_name,
                        p.barcode,
                        COALESCE(fs.srp, 0) as price
                    FROM tbl_transfer_dtl td
                    JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                    LEFT JOIN tbl_product p ON td.product_id = p.product_id
                    LEFT JOIN tbl_fifo_stock fs ON td.product_id = fs.product_id AND fs.available_quantity > 0
                    WHERE th.status = 'approved'
                    AND NOT EXISTS (
                        SELECT 1 FROM tbl_transfer_batch_details tbd 
                        WHERE tbd.product_id = td.product_id 
                        AND tbd.location_id = th.destination_location_id
                    )
                ");
                $missingStmt->execute();
                $missingTransfers = $missingStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $created = 0;
                foreach ($missingTransfers as $transfer) {
                    // Get the first available batch for this product
                    $batchStmt = $conn->prepare("
                        SELECT batch_id, batch_reference, srp, expiration_date
                        FROM tbl_batch 
                        WHERE product_id = ?
                        ORDER BY entry_date ASC, batch_id ASC
                        LIMIT 1
                    ");
                    $batchStmt->execute([$transfer['product_id']]);
                    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($batch) {
                        // Create batch detail record
                        $insertStmt = $conn->prepare("
                            INSERT INTO tbl_transfer_batch_details 
                            (product_id, batch_id, batch_reference, quantity, srp, expiration_date, location_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insertStmt->execute([
                            $transfer['product_id'],
                            $batch['batch_id'],
                            $batch['batch_reference'] ?: 'BATCH-' . $transfer['product_id'] . '-' . $transfer['transfer_header_id'],
                            $transfer['qty'],
                            $transfer['price'] ?: $batch['srp'] ?: 0,
                            $batch['expiration_date'] ?: date('Y-m-d', strtotime('+1 year')),
                            $transfer['destination_location_id']
                        ]);
                        $created++;
                    }
                }
                
                $conn->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Created $created missing batch detail records",
                    "created_count" => $created
                ]);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        default:
            echo json_encode([
                "success" => false,
                "message" => "Invalid action"
            ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
