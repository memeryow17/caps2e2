<?php
/**
 * Batch-related functions for Inventory Transfer System
 * This file contains all batch-related database operations
 */

/**
 * Get transfer batch details for a specific transfer
 */
function getTransferBatchDetails($conn, $data) {
    try {
        $transfer_id = $data['transfer_id'] ?? 0;
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        // Check if this is for inventory (transfer_id) or store (product_id + location_id)
        if ($transfer_id) {
            // For InventoryTransfer modal - get batch details for specific transfer
            $stmt = $conn->prepare("
                SELECT DISTINCT
                    tbd.id,
                    tbd.batch_id,
                    tbd.batch_reference,
                    tbd.quantity as batch_quantity,
                    tbd.srp as batch_srp,
                    tbd.expiration_date,
                    fs.srp,
                    fs.available_quantity
                FROM tbl_transfer_batch_details tbd
                LEFT JOIN tbl_fifo_stock fs ON tbd.batch_id = fs.batch_id
                LEFT JOIN tbl_transfer_dtl td ON tbd.product_id = td.product_id AND td.transfer_header_id = ?
                WHERE td.transfer_header_id = ?
                ORDER BY tbd.id ASC
            ");
            $stmt->execute([$transfer_id, $transfer_id]);
            $batch_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ["success" => true, "data" => $batch_details];
        } elseif ($product_id && $location_id) {
            // For ConvenienceStore and PharmacyInventory modals - get individual batches from tbl_transfer_batch_details
            $stmt = $conn->prepare("
                SELECT 
                    tbd.id as batch_transfer_id,
                    tbd.batch_id,
                    tbd.batch_reference,
                    tbd.quantity as batch_quantity,
                    tbd.srp as batch_srp,
                    tbd.expiration_date,
                    'Consumed' as status,
                    th.date as transfer_date,
                    p.product_name,
                    p.barcode,
                    p.srp as unit_price,
                    p.srp,
                    b.brand,
                    s.supplier_name,
                    l.location_name as location_name,
                    sl.location_name as source_location_name,
                    CONCAT(e.Fname, ' ', e.Lname) as employee_name
                FROM tbl_transfer_batch_details tbd
                JOIN tbl_transfer_header th ON tbd.transfer_header_id = th.transfer_header_id
                JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_location l ON th.destination_location_id = l.location_id
                LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
                LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
                WHERE td.product_id = ? AND th.destination_location_id = ?
                ORDER BY tbd.id ASC
            ");
            $stmt->execute([$product_id, $location_id]);
            $batch_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals for store modals
            $total_stock = array_sum(array_column($batch_details, 'batch_quantity'));
            $total_batches = count($batch_details);
            $active_batches = count(array_filter($batch_details, function($item) {
                return $item['status'] === 'Available';
            }));
            $consumed_batches = count(array_filter($batch_details, function($item) {
                return $item['status'] === 'Consumed';
            }));
            
            // Get unique SRP
            $srp = $batch_details[0]['batch_srp'] ?? 0;
            
            $response_data = [
                'batch_details' => $batch_details,
                'summary' => [
                    'total_stock' => $total_stock,
                    'total_transfer_quantity' => $total_stock,
                    'srp' => $srp,
                    'total_batches' => $total_batches,
                    'active_batches' => $active_batches,
                    'consumed_batches' => $consumed_batches
                ]
            ];
            
            return ["success" => true, "data" => $response_data];
        } else {
            return ["success" => false, "message" => "Either Transfer ID or Product ID + Location ID is required"];
        }
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

/**
 * Get individual batch details for a product (using tbl_batch_transfer_details)
 */
function getIndividualBatchDetails($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        if (!$product_id || !$location_id) {
            return ["success" => false, "message" => "Product ID and Location ID are required"];
        }
        
        // Get individual batches from tbl_batch_transfer_details for a specific product and location
        $stmt = $conn->prepare("
            SELECT 
                btd.batch_transfer_id,
                btd.batch_id,
                btd.batch_reference,
                btd.quantity_used as batch_quantity,
                btd.srp,
                btd.srp as batch_srp,
                btd.expiration_date,
                btd.status,
                btd.transfer_date,
                p.product_name,
                p.barcode,
                l.location_name as location_name
            FROM tbl_batch_transfer_details btd
            LEFT JOIN tbl_product p ON btd.product_id = p.product_id
            LEFT JOIN tbl_location l ON btd.location_id = l.location_id
            WHERE btd.product_id = ? AND btd.location_id = ?
            ORDER BY btd.batch_transfer_id ASC
        ");
        $stmt->execute([$product_id, $location_id]);
        $batch_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ["success" => true, "data" => $batch_details];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

/**
 * Get batch transfer details by product (using tbl_batch_transfer_details)
 */
function getBatchTransferDetailsByProduct($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        if (!$product_id || !$location_id) {
            return ['success' => false, 'message' => 'Product ID and Location ID are required'];
        }
        
        // Get individual batches from tbl_batch_transfer_details for modal display
        $stmt = $conn->prepare("
            SELECT 
                btd.batch_transfer_id,
                btd.batch_id,
                btd.batch_reference,
                btd.quantity_used as batch_quantity,
                btd.srp,
                btd.srp as batch_srp,
                btd.expiration_date,
                btd.status,
                btd.transfer_date,
                p.product_name,
                p.barcode,
                p.srp as unit_price,
                p.srp,
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
        
        // Calculate summary for modal
        $total_stock = array_sum(array_column($batch_details, 'batch_quantity'));
        $total_batches = count($batch_details);
        $available_batches = count(array_filter($batch_details, function($item) {
            return $item['status'] === 'Available';
        }));
        $consumed_batches = count(array_filter($batch_details, function($item) {
            return $item['status'] === 'Consumed';
        }));
        
        $response_data = [
            'batch_details' => $batch_details,
            'summary' => [
                'total_stock' => $total_stock,
                'total_batches' => $total_batches,
                'available_batches' => $available_batches,
                'consumed_batches' => $consumed_batches
            ]
        ];
        
        return ["success" => true, "data" => $response_data];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

/**
 * Get batch transfers by location (using tbl_batch_transfer_details)
 */
function getBatchTransfersByLocation($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? 0;
        $location_name = $data['location_name'] ?? '';
        
        if (!$location_id && !$location_name) {
            return ['success' => false, 'message' => 'Location ID or Location Name is required'];
        }
        
        // Get location ID if only name provided
        if ($location_name && !$location_id) {
            $locStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name LIKE ?");
            $locStmt->execute(["%$location_name%"]);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            if ($location) {
                $location_id = $location['location_id'];
            } else {
                return ['success' => false, 'message' => 'Location not found'];
            }
        }
        
        // Get all batch transfers for this location with detailed information
        $stmt = $conn->prepare("
            SELECT 
                btd.batch_transfer_id,
                btd.product_id,
                btd.batch_id,
                btd.batch_reference,
                btd.quantity_used as batch_quantity,
                btd.srp,
                btd.srp as batch_srp,
                btd.expiration_date,
                btd.status,
                btd.transfer_date,
                btd.created_at,
                p.product_name,
                p.barcode,
                c.category_name as category,
                p.srp as unit_price,
                p.srp as product_srp,
                b.brand,
                s.supplier_name,
                l.location_name,
                sl.location_name as source_location_name,
                dl.location_name as destination_location_name,
                th.transfer_header_id,
                th.date as transfer_header_date,
                th.status as transfer_status,
                CONCAT(e.Fname, ' ', e.Lname) as employee_name
            FROM tbl_batch_transfer_details btd
            LEFT JOIN tbl_product p ON btd.product_id = p.product_id
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_location l ON btd.location_id = l.location_id
            LEFT JOIN tbl_transfer_header th ON btd.transfer_id = th.transfer_header_id
            LEFT JOIN tbl_location sl ON th.source_location_id = sl.location_id
            LEFT JOIN tbl_location dl ON th.destination_location_id = dl.location_id
            LEFT JOIN tbl_employee e ON th.employee_id = e.emp_id
            WHERE btd.location_id = ?
            ORDER BY btd.transfer_date DESC
        ");
        $stmt->execute([$location_id]);
        $batch_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'data' => $batch_transfers];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'data' => []];
    }
}

/**
 * Create transfer batch details table
 */
function createTransferBatchDetailsTable($conn) {
    try {
        // First create the transfer_log table if it doesn't exist
        $create_transfer_log_sql = "
            CREATE TABLE IF NOT EXISTS `tbl_transfer_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `transfer_id` int(11) NOT NULL,
                `product_id` int(11) NOT NULL,
                `quantity` int(11) NOT NULL,
                `from_location` varchar(255) NOT NULL,
                `to_location` varchar(255) NOT NULL,
                `transfer_date` datetime NOT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `transfer_id` (`transfer_id`),
                KEY `product_id` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->exec($create_transfer_log_sql);
        
        // Create the transfer batch details table if it doesn't exist
        $create_table_sql = "
            CREATE TABLE IF NOT EXISTS `tbl_transfer_batch_details` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `product_id` int(11) NOT NULL,
                `batch_id` int(11) NOT NULL,
                `batch_reference` varchar(255) NOT NULL,
                `quantity` int(11) NOT NULL,
                `srp` decimal(10,2) NOT NULL,
                `expiration_date` date DEFAULT NULL,
                `transfer_header_id` int(11) NOT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `product_id` (`product_id`),
                KEY `batch_id` (`batch_id`),
                KEY `transfer_header_id` (`transfer_header_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->exec($create_table_sql);
        
        // Add missing product_id column if it doesn't exist
        try {
            $conn->exec("ALTER TABLE tbl_transfer_batch_details ADD COLUMN product_id int(11) NOT NULL AFTER id");
            $conn->exec("ALTER TABLE tbl_transfer_batch_details ADD KEY product_id (product_id)");
            error_log("Added product_id column to tbl_transfer_batch_details table");
        } catch (Exception $e) {
            // Column might already exist, ignore error
            error_log("Product_id column addition: " . $e->getMessage());
        }
        
        // Remove transfer_id column from batch details table (not needed since batch details should be independent)
        try {
            $conn->exec("ALTER TABLE tbl_transfer_batch_details DROP COLUMN transfer_id");
            error_log("Removed transfer_id column from tbl_transfer_batch_details table");
        } catch (Exception $e) {
            // Column might already be dropped, ignore error
            error_log("Transfer_id column removal: " . $e->getMessage());
        }
        
        // Populate sample batch details for existing transfers
        $populate_stmt = $conn->prepare("
            INSERT IGNORE INTO tbl_transfer_batch_details 
            (product_id, batch_id, batch_reference, quantity, srp, expiration_date)
            SELECT 
                tl.product_id,
                COALESCE(fs.batch_id, 1) as batch_id,
                CONCAT('BR-', tl.transfer_id, '-', tl.product_id) as batch_reference,
                tl.quantity,
                COALESCE(fs.srp, p.srp, 0) as srp,
                COALESCE(fs.expiration_date, DATE_ADD(CURDATE(), INTERVAL 30 DAY)) as expiration_date
            FROM tbl_transfer_log tl
            LEFT JOIN tbl_fifo_stock fs ON tl.product_id = fs.product_id
            LEFT JOIN tbl_product p ON tl.product_id = p.product_id
            WHERE NOT EXISTS (
                SELECT 1 FROM tbl_transfer_batch_details tbd 
                WHERE tbd.product_id = tl.product_id AND tbd.batch_id = COALESCE(fs.batch_id, 1)
            )
            LIMIT 1
        ");
        $populate_stmt->execute();
        
        return ["success" => true, "message" => "Transfer batch details table created successfully"];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage()];
    }
}

/**
 * Get all batches
 */
function getAllBatches($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT
                b.batch_id,
                b.batch_reference,
                b.product_id,
                p.product_name,
                b.quantity,
                b.srp,
                b.expiration_date,
                b.entry_date,
                l.location_name
            FROM tbl_fifo_stock b
            LEFT JOIN tbl_product p ON b.product_id = p.product_id
            LEFT JOIN tbl_location l ON b.location_id = l.location_id
            ORDER BY b.entry_date DESC
        ");
        $stmt->execute();
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ["success" => true, "data" => $batches];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

/**
 * Get product batches
 */
function getProductBatches($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT DISTINCT
                fs.batch_id,
                fs.batch_reference,
                fs.available_quantity,
                fs.unit_cost,
                fs.srp,
                fs.expiration_date,
                fs.entry_date,
                l.location_name
            FROM tbl_fifo_stock fs
            LEFT JOIN tbl_location l ON fs.location_id = l.location_id
            WHERE fs.product_id = ?
            ORDER BY fs.entry_date ASC
        ");
        $stmt->execute([$product_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ["success" => true, "data" => $batches];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

/**
 * Duplicate product batches
 */
function duplicateProductBatches($conn, $data) {
    try {
        $product_id = isset($data['product_id']) ? intval($data['product_id']) : 0;
        $batch_ids = isset($data['batch_ids']) ? $data['batch_ids'] : [22, 23]; // Default to your batch IDs
        
        if ($product_id <= 0) {
            return ["success" => false, "message" => "Invalid product ID"];
        }
        
        $duplicated_batches = [];
        
        foreach ($batch_ids as $batch_id) {
            // Get the original batch details
            $stmt = $conn->prepare("
                SELECT batch_reference, available_quantity, srp, expiration_date, entry_date, location_id
                FROM tbl_fifo_stock 
                WHERE batch_id = ?
            ");
            $stmt->execute([$batch_id]);
            $original_batch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($original_batch) {
                // Create a new batch with the same details but for the new product
                $new_batch_reference = $original_batch['batch_reference'] . '-COPY-' . $product_id;
                
                $insert_stmt = $conn->prepare("
                    INSERT INTO tbl_fifo_stock 
                    (product_id, batch_reference, available_quantity, srp, expiration_date, entry_date, location_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $insert_stmt->execute([
                    $product_id,
                    $new_batch_reference,
                    $original_batch['available_quantity'],
                    $original_batch['srp'],
                    $original_batch['expiration_date'],
                    $original_batch['entry_date'],
                    $original_batch['location_id']
                ]);
                
                $new_batch_id = $conn->lastInsertId();
                $duplicated_batches[] = [
                    'original_batch_id' => $batch_id,
                    'new_batch_id' => $new_batch_id,
                    'batch_reference' => $new_batch_reference
                ];
            }
        }
        
        return [
            "success" => true, 
            "message" => "Batches duplicated successfully",
            "duplicated_batches" => $duplicated_batches
        ];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage()];
    }
}

/**
 * Add batch entry
 */
function addBatchEntry($conn, $data) {
    try {
        // Extract batch data
        $batch_reference = $data['batch_reference'] ?? '';
        $product_id = $data['product_id'] ?? 0;
        $quantity = $data['quantity'] ?? 0;
        $srp = $data['srp'] ?? 0;
        $srp = $data['srp'] ?? 0;
        $expiration_date = $data['expiration_date'] ?? null;
        $location_id = $data['location_id'] ?? 0;
        
        // Validation
        if (empty($batch_reference) || $product_id <= 0 || $quantity <= 0) {
            return ["success" => false, "message" => "Missing required fields: batch_reference, product_id, quantity"];
        }
        
        // Insert new batch
        $stmt = $conn->prepare("
            INSERT INTO tbl_fifo_stock 
            (product_id, batch_reference, available_quantity, srp, expiration_date, entry_date, location_id)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        
        $stmt->execute([
            $product_id,
            $batch_reference,
            $quantity,
            $srp,
            $expiration_date,
            $location_id
        ]);
        
        $batch_id = $conn->lastInsertId();
        
        return [
            "success" => true,
            "message" => "Batch entry added successfully",
            "batch_id" => $batch_id,
            "batch_reference" => $batch_reference
        ];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage()];
    }
}

/**
 * Get products oldest batch for transfer
 */
function getProductsOldestBatchForTransfer($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? null;
        
        $sql = "
            SELECT 
                p.product_id,
                p.product_name,
                p.barcode,
                p.srp as unit_price,
                p.srp,
                fs.batch_id,
                fs.batch_reference,
                fs.available_quantity as oldest_batch_quantity,
                fs.expiration_date,
                fs.entry_date,
                SUM(fs.available_quantity) as total_quantity,
                COUNT(fs.batch_id) as batch_count
            FROM tbl_product p
            LEFT JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id
        ";
        
        if ($location_id) {
            $sql .= " WHERE fs.location_id = ? ";
        }
        
        $sql .= "
            GROUP BY p.product_id, fs.batch_id, fs.batch_reference, fs.available_quantity, fs.expiration_date, fs.entry_date
            HAVING fs.entry_date = (
                SELECT MIN(fs2.entry_date) 
                FROM tbl_fifo_stock fs2 
                WHERE fs2.product_id = p.product_id" . ($location_id ? " AND fs2.location_id = ?" : "") . "
            )
            ORDER BY p.product_name ASC
        ";
        
        $stmt = $conn->prepare($sql);
        
        if ($location_id) {
            $stmt->execute([$location_id, $location_id]);
        } else {
            $stmt->execute();
        }
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ["success" => true, "data" => $products];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

/**
 * Get products oldest batch
 */
function getProductsOldestBatch($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? null;
        
        $sql = "
            SELECT 
                p.product_id,
                p.product_name,
                p.barcode,
                p.srp as unit_price,
                p.srp,
                fs.batch_id,
                fs.batch_reference,
                fs.available_quantity,
                fs.expiration_date,
                fs.entry_date,
                SUM(fs.available_quantity) as total_quantity,
                COUNT(fs.batch_id) as batch_count
            FROM tbl_product p
            LEFT JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id
        ";
        
        if ($location_id) {
            $sql .= " WHERE fs.location_id = ? ";
        }
        
        $sql .= "
            GROUP BY p.product_id, fs.batch_id, fs.batch_reference, fs.available_quantity, fs.expiration_date, fs.entry_date
            HAVING fs.entry_date = (
                SELECT MIN(fs2.entry_date) 
                FROM tbl_fifo_stock fs2 
                WHERE fs2.product_id = p.product_id" . ($location_id ? " AND fs2.location_id = ?" : "") . "
            )
            ORDER BY p.product_name ASC
        ";
        
        $stmt = $conn->prepare($sql);
        
        if ($location_id) {
            $stmt->execute([$location_id, $location_id]);
        } else {
            $stmt->execute();
        }
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ["success" => true, "data" => $products];
    } catch (Exception $e) {
        return ["success" => false, "message" => "Database error: " . $e->getMessage(), "data" => []];
    }
}

// Get product batches
function get_product_batches($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        
        if (!$product_id) {
            echo json_encode([
                "success" => false,
                "message" => "Product ID is required"
            ]);
            return;
        }
        
        $sql = "
            SELECT 
                fs.batch_id,
                fs.batch_reference,
                fs.available_quantity as quantity,
                fs.unit_cost,
                fs.srp,
                fs.expiration_date,
                fs.created_at,
                p.product_name
            FROM tbl_fifo_stock fs
            LEFT JOIN tbl_product p ON fs.product_id = p.product_id
            WHERE fs.product_id = ? AND fs.available_quantity > 0
            ORDER BY fs.expiration_date ASC, fs.created_at ASC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$product_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "data" => $batches,
            "count" => count($batches)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching product batches: " . $e->getMessage()
        ]);
    }
}

// Get FIFO stock
function get_fifo_stock($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        
        if (!$product_id) {
            echo json_encode([
                "success" => false,
                "message" => "Product ID is required"
            ]);
            return;
        }
        
        $sql = "
            SELECT 
                fs.batch_id,
                fs.batch_reference,
                fs.available_quantity,
                fs.unit_cost,
                fs.srp,
                fs.expiration_date,
                fs.created_at,
                p.product_name,
                DATEDIFF(fs.expiration_date, CURDATE()) as days_until_expiry
            FROM tbl_fifo_stock fs
            LEFT JOIN tbl_product p ON fs.product_id = p.product_id
            WHERE fs.product_id = ? AND fs.available_quantity > 0
            ORDER BY fs.expiration_date ASC, fs.created_at ASC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$product_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $total_quantity = array_sum(array_column($batches, 'available_quantity'));
        $total_value = 0;
        foreach ($batches as $batch) {
            $total_value += $batch['available_quantity'] * $batch['srp'];
        }
        
        echo json_encode([
            "success" => true,
            "data" => $batches,
            "count" => count($batches),
            "totals" => [
                "total_quantity" => $total_quantity,
                "total_value" => round($total_value, 2)
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching FIFO stock: " . $e->getMessage()
        ]);
    }
}

// Get products with oldest batch
function get_products_oldest_batch($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? 0;

        $sql = "
            SELECT
                p.product_id,
                p.product_name,
                c.category_name as category,
                COALESCE(SUM(fs.available_quantity), 0) as total_quantity,
                p.barcode,
                p.srp,
                p.srp as unit_price,
                p.location_id,
                l.location_name,
                COALESCE(b.brand, '') as brand,
                COALESCE(s.supplier_name, '') as supplier_name,
                MIN(fs.expiration_date) as earliest_expiry,
                MIN(fs.batch_reference) as oldest_batch_ref,
                DATEDIFF(MIN(fs.expiration_date), CURDATE()) as days_until_expiry,
                COALESCE(NULLIF(first_batch.first_batch_srp, 0), p.srp) as first_batch_srp
            FROM tbl_product p
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id AND fs.available_quantity > 0
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN (
                SELECT
                    fs2.product_id,
                    fs2.srp as first_batch_srp,
                    ROW_NUMBER() OVER (PARTITION BY fs2.product_id ORDER BY fs2.entry_date ASC, fs2.fifo_id ASC) as rn
                FROM tbl_fifo_stock fs2
                WHERE fs2.available_quantity > 0 AND fs2.srp > 0
            ) first_batch ON p.product_id = first_batch.product_id AND first_batch.rn = 1
            WHERE p.status = 'active'
        ";

        $params = [];

        if ($location_id > 0) {
            $sql .= " AND p.location_id = ?";
            $params[] = $location_id;
        }

        $sql .= " GROUP BY p.product_id, p.product_name, c.category_name, p.barcode, p.srp, p.location_id, l.location_name, b.brand, s.supplier_name ORDER BY earliest_expiry ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $products,
            "count" => count($products)
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching products with oldest batch: " . $e->getMessage()
        ]);
    }
}

// Get all batches
function get_batches($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        
        $sql = "
            SELECT 
                fs.batch_id,
                fs.product_id,
                fs.batch_reference,
                fs.available_quantity,
                fs.unit_cost,
                fs.srp,
                fs.expiration_date,
                fs.created_at,
                p.product_name,
                c.category_name as category
            FROM tbl_fifo_stock fs
            LEFT JOIN tbl_product p ON fs.product_id = p.product_id
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            WHERE fs.available_quantity > 0
        ";
        
        $params = [];
        
        if ($product_id > 0) {
            $sql .= " AND fs.product_id = ?";
            $params[] = $product_id;
        }
        
        if ($location_id > 0) {
            $sql .= " AND p.location_id = ?";
            $params[] = $location_id;
        }
        
        $sql .= " ORDER BY fs.expiration_date ASC, fs.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "data" => $batches,
            "count" => count($batches)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching batches: " . $e->getMessage()
        ]);
    }
}

// Add batch entry
function add_batch_entry($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $batch_reference = $data['batch_reference'] ?? '';
        $quantity = $data['quantity'] ?? 0;
        $srp = $data['srp'] ?? 0;
        $srp = $data['srp'] ?? 0;
        $expiration_date = $data['expiration_date'] ?? null;
        $entry_by = $data['entry_by'] ?? 'admin';
        
        if (!$product_id || $quantity <= 0) {
            echo json_encode([
                "success" => false,
                "message" => "Product ID and valid quantity are required"
            ]);
            return;
        }
        
        // Check if tbl_fifo_stock table exists, create if not
        $createTableStmt = $conn->prepare("
            CREATE TABLE IF NOT EXISTS tbl_fifo_stock (
                batch_id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                batch_reference VARCHAR(100),
                original_quantity DECIMAL(10,2) NOT NULL,
                available_quantity DECIMAL(10,2) NOT NULL,
                srp DECIMAL(10,2) DEFAULT 0.00,
                expiration_date DATE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_id (product_id),
                INDEX idx_expiration (expiration_date)
            )
        ");
        $createTableStmt->execute();
        
        // Insert batch entry
        $stmt = $conn->prepare("
            INSERT INTO tbl_fifo_stock 
            (product_id, batch_reference, original_quantity, available_quantity, srp, expiration_date) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$product_id, $batch_reference, $quantity, $quantity, $srp, $expiration_date]);
        
        $batch_id = $conn->lastInsertId();
        
        // Update product total quantity
        $updateStmt = $conn->prepare("
            UPDATE tbl_product 
            SET quantity = quantity + ? 
            WHERE product_id = ?
        ");
        $updateStmt->execute([$quantity, $product_id]);
        
        // Log stock movement
        $logStmt = $conn->prepare("
            INSERT INTO tbl_stock_movements 
            (product_id, batch_id, movement_type, quantity, remaining_quantity, srp, 
             expiration_date, reference_no, notes, created_by)
            VALUES (?, ?, 'IN', ?, ?, ?, ?, ?, ?, ?)
        ");
        $logStmt->execute([
            $product_id,
            $batch_id,
            $quantity,
            $quantity,
            $srp,
            $expiration_date,
            $batch_reference,
            "Batch entry added",
            $entry_by
        ]);
        
        echo json_encode([
            "success" => true,
            "message" => "Batch entry added successfully",
            "data" => [
                "batch_id" => $batch_id,
                "batch_reference" => $batch_reference,
                "quantity" => $quantity
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error adding batch entry: " . $e->getMessage()
        ]);
    }
}

// Consume stock FIFO
function consume_stock_fifo($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $quantity = $data['quantity'] ?? 0;
        $reference_no = $data['reference_no'] ?? '';
        $notes = $data['notes'] ?? '';
        $entry_by = $data['entry_by'] ?? 'admin';
        
        if (!$product_id || $quantity <= 0) {
            echo json_encode([
                "success" => false,
                "message" => "Product ID and valid quantity are required"
            ]);
            return;
        }
        
        // Get available batches in FIFO order (oldest first)
        $batchStmt = $conn->prepare("
            SELECT batch_id, batch_reference, available_quantity, unit_cost, expiration_date
            FROM tbl_fifo_stock 
            WHERE product_id = ? AND available_quantity > 0
            ORDER BY expiration_date ASC, created_at ASC
        ");
        $batchStmt->execute([$product_id]);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($batches)) {
            echo json_encode([
                "success" => false,
                "message" => "No available stock batches found"
            ]);
            return;
        }
        
        // Check if total available quantity is sufficient
        $total_available = array_sum(array_column($batches, 'available_quantity'));
        if ($total_available < $quantity) {
            echo json_encode([
                "success" => false,
                "message" => "Insufficient stock. Available: $total_available, Requested: $quantity"
            ]);
            return;
        }
        
        $remaining_to_consume = $quantity;
        $consumed_batches = [];
        
        foreach ($batches as $batch) {
            if ($remaining_to_consume <= 0) break;
            
            $consume_from_batch = min($remaining_to_consume, $batch['available_quantity']);
            $new_available = $batch['available_quantity'] - $consume_from_batch;
            
            // Update batch quantity
            $updateBatchStmt = $conn->prepare("
                UPDATE tbl_fifo_stock 
                SET available_quantity = ? 
                WHERE batch_id = ?
            ");
            $updateBatchStmt->execute([$new_available, $batch['batch_id']]);
            
            // Log stock movement
            $logStmt = $conn->prepare("
                INSERT INTO tbl_stock_movements 
                (product_id, batch_id, movement_type, quantity, remaining_quantity, srp, 
                 expiration_date, reference_no, notes, created_by)
                VALUES (?, ?, 'OUT', ?, ?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $product_id,
                $batch['batch_id'],
                $consume_from_batch,
                $new_available,
                $batch['srp'],
                $batch['expiration_date'],
                $reference_no,
                $notes ?: "FIFO consumption",
                $entry_by
            ]);
            
            $consumed_batches[] = [
                "batch_id" => $batch['batch_id'],
                "batch_reference" => $batch['batch_reference'],
                "consumed_quantity" => $consume_from_batch,
                "remaining_quantity" => $new_available
            ];
            
            $remaining_to_consume -= $consume_from_batch;
        }
        
        // Update product total quantity
        $updateProductStmt = $conn->prepare("
            UPDATE tbl_product 
            SET quantity = quantity - ? 
            WHERE product_id = ?
        ");
        $updateProductStmt->execute([$quantity, $product_id]);
        
        echo json_encode([
            "success" => true,
            "message" => "Stock consumed successfully using FIFO",
            "data" => [
                "total_consumed" => $quantity,
                "batches_affected" => count($consumed_batches),
                "consumed_batches" => $consumed_batches
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error consuming stock: " . $e->getMessage()
        ]);
    }
}

// Duplicate product batches
function duplicate_product_batches($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $batch_ids = $data['batch_ids'] ?? [];
        
        if (!$product_id || empty($batch_ids)) {
            echo json_encode([
                "success" => false,
                "message" => "Product ID and batch IDs are required"
            ]);
            return;
        }
        
        $duplicated_batches = [];
        
        foreach ($batch_ids as $batch_id) {
            // Get original batch details
            $batchStmt = $conn->prepare("
                SELECT batch_reference, original_quantity, available_quantity, srp, expiration_date
                FROM tbl_fifo_stock 
                WHERE batch_id = ? AND product_id = ?
            ");
            $batchStmt->execute([$batch_id, $product_id]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($batch) {
                // Create duplicate batch with new reference
                $new_reference = $batch['batch_reference'] . '_DUP_' . time();
                
                $duplicateStmt = $conn->prepare("
                    INSERT INTO tbl_fifo_stock 
                    (product_id, batch_reference, original_quantity, available_quantity, srp, expiration_date) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $duplicateStmt->execute([
                    $product_id,
                    $new_reference,
                    $batch['original_quantity'],
                    $batch['available_quantity'],
                    $batch['srp'],
                    $batch['expiration_date']
                ]);
                
                $new_batch_id = $conn->lastInsertId();
                
                $duplicated_batches[] = [
                    "original_batch_id" => $batch_id,
                    "new_batch_id" => $new_batch_id,
                    "new_reference" => $new_reference
                ];
            }
        }
        
        echo json_encode([
            "success" => true,
            "message" => "Batches duplicated successfully",
            "data" => [
                "duplicated_count" => count($duplicated_batches),
                "duplicated_batches" => $duplicated_batches
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error duplicating batches: " . $e->getMessage()
        ]);
    }
}

// Sync FIFO stock
function sync_fifo_stock($conn, $data) {
    try {
        // Sync product quantities with FIFO stock totals
        $syncStmt = $conn->prepare("
            UPDATE tbl_product p
            SET quantity = (
                SELECT COALESCE(SUM(fs.available_quantity), 0)
                FROM tbl_fifo_stock fs
                WHERE fs.product_id = p.product_id
            )
            WHERE p.status = 'active'
        ");
        $syncStmt->execute();
        
        $affected_rows = $syncStmt->rowCount();
        
        echo json_encode([
            "success" => true,
            "message" => "FIFO stock synchronized successfully",
            "data" => [
                "products_updated" => $affected_rows
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error syncing FIFO stock: " . $e->getMessage()
        ]);
    }
}

// Force sync all products
function force_sync_all_products($conn, $data) {
    try {
        // Reset all product quantities to 0 first
        $resetStmt = $conn->prepare("UPDATE tbl_product SET quantity = 0 WHERE status = 'active'");
        $resetStmt->execute();
        
        // Sync with FIFO stock
        $syncStmt = $conn->prepare("
            UPDATE tbl_product p
            SET quantity = (
                SELECT COALESCE(SUM(fs.available_quantity), 0)
                FROM tbl_fifo_stock fs
                WHERE fs.product_id = p.product_id
            )
            WHERE p.status = 'active'
        ");
        $syncStmt->execute();
        
        $affected_rows = $syncStmt->rowCount();
        
        echo json_encode([
            "success" => true,
            "message" => "All products force synchronized successfully",
            "data" => [
                "products_updated" => $affected_rows
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error force syncing products: " . $e->getMessage()
        ]);
    }
}

// Cleanup duplicate transfer products
function cleanup_duplicate_transfer_products($conn, $data) {
    try {
        // Remove duplicate entries from transfer batch details
        $cleanupStmt = $conn->prepare("
            DELETE tbd1 FROM tbl_transfer_batch_details tbd1
            INNER JOIN tbl_transfer_batch_details tbd2 
            WHERE tbd1.id > tbd2.id 
            AND tbd1.product_id = tbd2.product_id 
            AND tbd1.batch_reference = tbd2.batch_reference
        ");
        $cleanupStmt->execute();
        
        $deleted_rows = $cleanupStmt->rowCount();
        
        echo json_encode([
            "success" => true,
            "message" => "Duplicate transfer products cleaned up successfully",
            "data" => [
                "duplicates_removed" => $deleted_rows
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error cleaning up duplicates: " . $e->getMessage()
        ]);
    }
}

// Create transfer batch details table
function create_transfer_batch_details_table($conn, $data) {
    try {
        $createTableStmt = $conn->prepare("
            CREATE TABLE IF NOT EXISTS tbl_transfer_batch_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transfer_header_id INT,
                product_id INT NOT NULL,
                batch_id INT,
                batch_reference VARCHAR(100),
                quantity DECIMAL(10,2) NOT NULL,
                srp DECIMAL(10,2) DEFAULT 0.00,
                expiration_date DATE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_transfer_header (transfer_header_id),
                INDEX idx_product_id (product_id),
                INDEX idx_batch_id (batch_id)
            )
        ");
        $createTableStmt->execute();
        
        echo json_encode([
            "success" => true,
            "message" => "Transfer batch details table created successfully"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error creating transfer batch details table: " . $e->getMessage()
        ]);
    }
}
?>
