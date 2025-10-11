<?php
// Products Module - Handles product CRUD operations, brands, categories, and inventory queries

function handle_add_convenience_product($conn, $data) {
    try{
         $product_name = isset($data['product_name'])&& !empty($data['product_name']) ? trim($data['product_name']) : '';
        $category = isset($data['category']) && !empty($data['category'])? trim($data['category']) : '';
        $barcode = isset($data['barcode']) && !empty($data['barcode'])? trim($data['barcode']) : '';
        $description = isset($data['description']) && !empty($data['description']) ? trim($data['description']) : '';
        $expiration = isset($data['expiration']) && !empty($data['expiration']) ? trim($data['expiration']) : '';

        $quantity = isset($data['quantity']) && !empty($data['quantity']) ? trim($data['quantity']) : '';
        $unit_price = isset($data['unit_price']) && !empty($data['unit_price']) ? trim($data['unit_price']) : '';
        $brand = isset($data['brand_id']) && !empty($data['brand_id']) ? trim($data['brand_id']) : '';


        // Prepare the SQL statement
        $stmt = $conn->prepare("
            INSERT INTO tbl_product (
                product_name, category, barcode, description, expiration, quantity, unit_price,
                brand_id
            ) VALUES (
                :product_name, :category, :barcode, :description, :expiration, :quantity, :unit_price,
                :brand_id
            )
        ");

        // Bind parameters
        $stmt->bindParam(":product_name", $product_name, PDO::PARAM_STR);
        $stmt->bindParam(":category", $category, PDO::PARAM_STR);
        $stmt->bindParam(":barcode", $barcode, PDO::PARAM_STR);
        $stmt->bindParam(":description", $description, PDO::PARAM_STR);
        $stmt->bindParam(":expiration", $expiration, PDO::PARAM_STR);
        $stmt->bindParam(":quantity", $quantity, PDO::PARAM_INT);
        $stmt->bindParam(":unit_price", $unit_price, PDO::PARAM_INT);
        $stmt->bindParam(":brand_id", $brand, PDO::PARAM_STR);

        // Execute the statement
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Product added successfully"]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to add product"]);
        }

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
    }
}

function handle_add_pharmacy_product($conn, $data) {
    try{
        $product_name = isset($data['product_name'])&& !empty($data['product_name']) ? trim($data['product_name']) : '';
        $category = isset($data['category']) && !empty($data['category'])? trim($data['category']) : '';
        $barcode = isset($data['barcode']) && !empty($data['barcode'])? trim($data['barcode']) : '';
        $description = isset($data['description']) && !empty($data['description']) ? trim($data['description']) : '';
        $prescription = isset($data['prescription']) && !empty($data['prescription']) ? trim($data['prescription']) : '';
        $expiration = isset($data['expiration']) && !empty($data['expiration']) ? trim($data['expiration']) : '';
        $quantity = isset($data['quantity']) && !empty($data['quantity']) ? trim($data['quantity']) : '';
        $unit_price = isset($data['unit_price']) && !empty($data['unit_price']) ? trim($data['unit_price']) : '';
        $brand = isset($data['brand_id']) && !empty($data['brand_id']) ? trim($data['brand_id']) : '';


        // Prepare the SQL statement
        $stmt = $conn->prepare("
            INSERT INTO tbl_product (
                product_name, category, barcode, description, prescription, expiration, quantity, unit_price,
                brand_id
            ) VALUES (
                :product_name, :category, :barcode, :description, :prescription, :expiration, :quantity, :unit_price,
                :brand_id
            )
        ");

        // Bind parameters
        $stmt->bindParam(":product_name", $product_name, PDO::PARAM_STR);
        $stmt->bindParam(":category", $category, PDO::PARAM_STR);
        $stmt->bindParam(":barcode", $barcode, PDO::PARAM_STR);
        $stmt->bindParam(":description", $description, PDO::PARAM_STR);
        $stmt->bindParam(":prescription", $prescription, PDO::PARAM_STR);
        $stmt->bindParam(":expiration", $expiration, PDO::PARAM_STR);
        $stmt->bindParam(":quantity", $quantity, PDO::PARAM_INT);
        $stmt->bindParam(":unit_price", $unit_price, PDO::PARAM_INT);
        $stmt->bindParam(":brand_id", $brand, PDO::PARAM_STR);

        // Execute the statement
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Product added successfully"]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to add product"]);
        }

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
    }
}

function handle_add_product($conn, $data) {
    try {
        // Extract and sanitize data
        $product_name = isset($data['product_name']) ? trim($data['product_name']) : '';
        $category = isset($data['category']) ? trim($data['category']) : '';
        $barcode = isset($data['barcode']) ? trim($data['barcode']) : '';
        $description = isset($data['description']) ? trim($data['description']) : '';
        $prescription = isset($data['prescription']) ? intval($data['prescription']) : 0;
        $bulk = isset($data['bulk']) ? intval($data['bulk']) : 0;
        $quantity = isset($data['quantity']) ? intval($data['quantity']) : 0;
        $unit_price = isset($data['unit_price']) ? floatval($data['unit_price']) : 0;
        $srp = isset($data['srp']) && $data['srp'] > 0 ? floatval($data['srp']) : 0; // SRP should be separate from unit_price
        $supplier_id = isset($data['supplier_id']) ? intval($data['supplier_id']) : 0;
        // Handle brand_id - allow NULL if not provided or empty
        $brand_id = null;
        if (isset($data['brand_id']) && !empty($data['brand_id'])) {
            $brand_id = intval($data['brand_id']);
            // Validate brand_id exists if provided
            $brandCheckStmt = $conn->prepare("SELECT brand_id FROM tbl_brand WHERE brand_id = ?");
            $brandCheckStmt->execute([$brand_id]);
            if (!$brandCheckStmt->fetch()) {
                $brand_id = null;
            }
        }

        $expiration = isset($data['expiration']) ? trim($data['expiration']) : null;
        $date_added = isset($data['date_added']) ? trim($data['date_added']) : date('Y-m-d');
        $status = isset($data['status']) ? trim($data['status']) : 'active';
        $stock_status = isset($data['stock_status']) ? trim($data['stock_status']) : 'in stock';
        $reference = isset($data['reference']) ? trim($data['reference']) : '';
        $entry_by = isset($data['entry_by']) ? trim($data['entry_by']) : 'admin';
        $order_no = isset($data['order_no']) ? trim($data['order_no']) : '';

        // Handle location_id - convert location name to ID if needed
        $location_id = null;
        if (isset($data['location_id'])) {
            $location_id = intval($data['location_id']);
        } elseif (isset($data['location'])) {
            $locStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name = ?");
            $locStmt->execute([trim($data['location'])]);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            $location_id = $location ? $location['location_id'] : 2; // Default to warehouse (ID 2)
        } else {
            $location_id = 2; // Default to warehouse
        }

        // Start transaction
        $conn->beginTransaction();

        // Create batch record first
        $batch_id = null;
        if ($reference) {
            $batchStmt = $conn->prepare("
                INSERT INTO tbl_batch (
                    batch, supplier_id, location_id, entry_date, entry_time, 
                    entry_by, order_no
                ) VALUES (?, ?, ?, CURDATE(), CURTIME(), ?, ?)
            ");
            $batchStmt->execute([$reference, $supplier_id, $location_id, $entry_by, $order_no]);
            $batch_id = $conn->lastInsertId();
        }

        // Prepare insert statement for product
        $stmt = $conn->prepare("
            INSERT INTO tbl_product (
                product_name, category, barcode, description, prescription, bulk,
                expiration, date_added, quantity, unit_price, srp, brand_id, supplier_id,
                location_id, batch_id, status, stock_status
            ) VALUES (
                :product_name, :category, :barcode, :description, :prescription, :bulk,
                :expiration, :date_added, :quantity, :unit_price, :srp, :brand_id, :supplier_id,
                :location_id, :batch_id, :status,  :stock_status
            )
        ");

        // Bind parameters
        $stmt->bindParam(':product_name', $product_name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':prescription', $prescription);
        $stmt->bindParam(':bulk', $bulk);
        $stmt->bindParam(':expiration', $expiration);
        $stmt->bindParam(':date_added', $date_added);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':unit_price', $unit_price);
        $stmt->bindParam(':srp', $srp);
        $stmt->bindParam(':brand_id', $brand_id);
        $stmt->bindParam(':supplier_id', $supplier_id);
        $stmt->bindParam(':location_id', $location_id);
        $stmt->bindParam(':batch_id', $batch_id);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':stock_status', $stock_status);

        if ($stmt->execute()) {
            $product_id = $conn->lastInsertId();

            // FIFO: Create stock movement record for new stock
            if ($batch_id && $quantity > 0) {
                $movementStmt = $conn->prepare("
                    INSERT INTO tbl_stock_movements (
                        product_id, batch_id, movement_type, quantity, remaining_quantity,
                        srp, expiration_date, reference_no, created_by
                    ) VALUES (?, ?, 'IN', ?, ?, ?, ?, ?, ?)
                ");
                $movementStmt->execute([
                    $product_id, $batch_id, $quantity, $quantity, 
                    $unit_price, $expiration, $reference, $entry_by
                ]);

                // Create stock summary record with the new srp
                $summaryStmt = $conn->prepare("
                    INSERT INTO tbl_stock_summary (
                        product_id, batch_id, available_quantity, srp,
                        expiration_date, batch_reference, total_quantity
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        available_quantity = available_quantity + VALUES(available_quantity),
                        total_quantity = total_quantity + VALUES(total_quantity),
                        srp = VALUES(srp),
                        last_updated = CURRENT_TIMESTAMP
                ");
                $summaryStmt->execute([
                    $product_id, $batch_id, $quantity, $srp,
                    $expiration, $reference, $quantity
                ]);

                // Create FIFO stock entry if table exists
                try {
                    $fifoStmt = $conn->prepare("
                        INSERT INTO tbl_fifo_stock (
                            product_id, batch_id, available_quantity, srp, expiration_date, batch_reference, entry_date, entry_by
                        ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)
                    ");

                    $fifoStmt->execute([
                        $product_id, $batch_id, $quantity, $srp, $expiration, $reference, $entry_by
                    ]);

                    $fifo_created = true;
                } catch (Exception $e) {
                    $fifo_created = false;
                }
            }

            $conn->commit();
            echo json_encode([
                "success" => true, 
                "message" => "Product added successfully with FIFO tracking",
                "fifo_stock_created" => $fifo_created ?? false
            ]);
        } else {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Failed to add product"]);
        }

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_update_product($conn, $data) {
    try {
        // Extract and sanitize data
        $product_id = isset($data['product_id']) ? intval($data['product_id']) : 0;
        $product_name = isset($data['product_name']) ? trim($data['product_name']) : '';
        $category = isset($data['category']) ? trim($data['category']) : '';
        $barcode = isset($data['barcode']) ? trim($data['barcode']) : '';
        $description = isset($data['description']) ? trim($data['description']) : '';
        $prescription = isset($data['prescription']) ? intval($data['prescription']) : 0;
        $bulk = isset($data['bulk']) ? intval($data['bulk']) : 0;
        $quantity = isset($data['quantity']) ? intval($data['quantity']) : 0;
        $unit_price = isset($data['unit_price']) ? floatval($data['unit_price']) : 0;
        $srp = isset($data['srp']) && $data['srp'] > 0 ? floatval($data['srp']) : 0; // SRP should be separate from unit_price
        $supplier_id = isset($data['supplier_id']) ? intval($data['supplier_id']) : 0;
        // Handle brand_id - allow NULL if not provided or empty
        $brand_id = null;
        // error_log removed for production: received brand_id
        if (isset($data['brand_id']) && !empty($data['brand_id'])) {
            $brand_id = intval($data['brand_id']);
            // error_log removed for production: parsed brand_id
            // Validate brand_id exists if provided
            $brandCheckStmt = $conn->prepare("SELECT brand_id FROM tbl_brand WHERE brand_id = ?");
            $brandCheckStmt->execute([$brand_id]);
            if (!$brandCheckStmt->fetch()) {
                // If brand_id doesn't exist, set to NULL
                // error_log removed for production: brand_id not found
                $brand_id = null;
            } else {
                // error_log removed for production: brand_id validated
            }
        } else {
            // error_log removed for production: no brand_id provided
        }
        $expiration = isset($data['expiration']) ? trim($data['expiration']) : null;

        if ($product_id <= 0) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid product ID"
            ]);
            return;
        }

        // Start transaction
        $conn->beginTransaction();

        // Update product
        $stmt = $conn->prepare("
            UPDATE tbl_product SET
                product_name = ?,
                category = ?,
                barcode = ?,
                description = ?,
                prescription = ?,
                bulk = ?,
                quantity = ?,
                unit_price = ?,
                srp = ?,
                supplier_id = ?,
                brand_id = ?,
                expiration = ?,
                stock_status = CASE
                    WHEN ? <= 0 THEN 'out of stock'
                    WHEN ? <= 10 THEN 'low stock'
                    ELSE 'in stock'
                END
            WHERE product_id = ?
        ");

        $stmt->execute([
            $product_name,
            $category,
            $barcode,
            $description,
            $prescription,
            $bulk,
            $quantity,
            $unit_price,
            $srp,
            $supplier_id,
            $brand_id,
            $expiration,
            $quantity,
            $quantity,
            $product_id
        ]);

        $conn->commit();
        echo json_encode([
            "success" => true,
            "message" => "Product updated successfully"
        ]);

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_addBrand($conn, $data) {
    try {
        $brand_name = isset($data['brand']) && !empty($data['brand']) ? trim($data['brand']) : '';

        // Validate input
        if (!$brand_name) {
            echo json_encode(["success" => false, "message" => "Brand name is required"]);
            exit;
        }

        // Check for duplicates
        $checkStmt = $conn->prepare("SELECT * FROM tbl_brand WHERE brand = :brand");
        $checkStmt->bindParam(":brand", $brand_name, PDO::PARAM_STR);
        $checkStmt->execute();
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(["success" => false, "message" => "Brand already exists"]);
            exit;
        }

        // Insert new brand
        $stmt = $conn->prepare("INSERT INTO tbl_brand (brand) VALUES (:brand)");
        $stmt->bindParam(":brand", $brand_name, PDO::PARAM_STR);

        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Brand added successfully"]);
        } else {
            // Return specific database error
            echo json_encode([
                "success" => false,
                "message" => "Database error: " . implode(", ", $stmt->errorInfo())
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
    }
}

function handle_displayBrand($conn, $data) {
    try {
        // Get all brands with their product count (without is_archived)
        $stmt = $conn->prepare("
            SELECT
                b.brand_id,
                b.brand,
                COUNT(p.product_id) AS product_count
            FROM tbl_brand b
            LEFT JOIN tbl_product p ON b.brand_id = p.brand_id
            GROUP BY b.brand_id, b.brand
            ORDER BY b.brand_id
        ");
        $stmt->execute();
        $brand = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($brand) {
            echo json_encode([
                "success" => true,
                "brand" => $brand
            ]);
        } else {
            echo json_encode([
                "success" => true,
                "brand" => [],
                "message" => "No brands found"
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "brand" => []
        ]);
    }
}

function handle_deleteBrand($conn, $data) {
    try {
        $brand_id = isset($data['brand_id']) ? intval($data['brand_id']) : 0;

        // Validate input
        if ($brand_id <= 0) {
            echo json_encode(["success" => false, "message" => "Invalid brand ID"]);
            return;
        }

        // Use prepared statement with proper DELETE syntax
        $stmt = $conn->prepare("DELETE FROM tbl_brand WHERE brand_id = :brand_id");
        $stmt->bindParam(":brand_id", $brand_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Brand deleted successfully"
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Failed to delete brand"
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_add_brand($conn, $data) {
    try {
        $brand_name = isset($data['brand_name']) ? trim($data['brand_name']) : '';

        if (empty($brand_name)) {
            echo json_encode(["success" => false, "message" => "Brand name is required"]);
            return;
        }

        // Check if brand already exists
        $checkStmt = $conn->prepare("SELECT brand_id FROM tbl_brand WHERE brand = ?");
        $checkStmt->execute([$brand_name]);
        $existingBrand = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingBrand) {
            echo json_encode([
                "success" => true,
                "brand_id" => $existingBrand['brand_id'],
                "message" => "Brand already exists"
            ]);
            return;
        }

        // Insert new brand
        $stmt = $conn->prepare("INSERT INTO tbl_brand (brand) VALUES (?)");
        $stmt->execute([$brand_name]);
        $brand_id = $conn->lastInsertId();

        echo json_encode([
            "success" => true,
            "brand_id" => $brand_id,
            "message" => "Brand added successfully"
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_get_products($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? null;
        $for_transfer = $data['for_transfer'] ?? false;

        $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived')";
        $params = [];

        if ($location_id) {
            $whereClause .= " AND p.location_id = ?";
            $params[] = $location_id;
        }

        // If for transfer, show OLD and NEW quantities separately for FIFO management
        if ($for_transfer) {
            $stmt = $conn->prepare("
                SELECT
                    p.product_id,
                    p.product_name,
                    p.category_id,
                    c.category_name,
                    p.barcode,
                    p.description,

                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.srp,
                    p.stock_status,
                    s.supplier_name,
                    b.brand,
                    l.location_name,
                    ss.batch_id,
                    ss.batch_reference,
                    b.entry_date,
                    b.entry_by,
                    COALESCE(p.date_added, CURDATE()) as date_added,
                    -- Show OLD quantity (oldest batch)
                    (SELECT ss2.available_quantity
                     FROM tbl_stock_summary ss2
                     INNER JOIN tbl_batch b2 ON ss2.batch_id = b2.batch_id
                     WHERE ss2.product_id = p.product_id
                     AND ss2.available_quantity > 0
                     AND b2.entry_date = (
                         SELECT MIN(b3.entry_date)
                         FROM tbl_batch b3
                         INNER JOIN tbl_stock_summary ss3 ON b3.batch_id = ss3.batch_id
                         WHERE ss3.product_id = p.product_id AND ss3.available_quantity > 0
                     )
                     LIMIT 1) as old_quantity,
                    -- Show NEW quantity (newest batch)
                    (SELECT ss2.available_quantity
                     FROM tbl_stock_summary ss2
                     INNER JOIN tbl_batch b2 ON ss2.batch_id = b2.batch_id
                     WHERE ss2.product_id = p.product_id
                     AND ss2.available_quantity > 0
                     AND b2.entry_date = (
                         SELECT MAX(b3.entry_date)
                         FROM tbl_batch b3
                         INNER JOIN tbl_stock_summary ss3 ON b3.batch_id = ss3.batch_id
                         WHERE ss3.product_id = p.product_id AND ss3.available_quantity > 0
                     )
                     LIMIT 1) as new_quantity,
                    -- Show total quantity
                    ss.available_quantity as total_quantity
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                INNER JOIN tbl_stock_summary ss ON p.product_id = ss.product_id
                INNER JOIN tbl_batch b ON ss.batch_id = b.batch_id
                WHERE ss.available_quantity > 0
                $whereClause
                GROUP BY p.product_id, p.product_name, p.category_id, c.category_name, p.barcode, p.description,
                         p.brand_id, p.supplier_id, p.location_id, p.srp, p.stock_status,
                         s.supplier_name, b.brand, l.location_name, ss.batch_id, ss.batch_reference,
                         b.entry_date, b.entry_by, ss.available_quantity
                ORDER BY p.product_name ASC
            ");
        } else {
            // Modified query to show each batch as a separate row
            $stmt = $conn->prepare("
                SELECT
                    p.product_id,
                    p.product_name,
                    p.category_id,
                    c.category_name,
                    p.barcode,
                    p.description,
                    p.prescription,
                    p.bulk,
                    p.expiration,
                    p.quantity,
                    p.srp,
                    p.srp,
                    p.brand_id,
                    p.supplier_id,
                    p.location_id,
                    p.batch_id,
                    p.stock_status,
                    p.date_added,
                    p.created_at,
                    s.supplier_name,
                    br.brand,
                    l.location_name,
                    b.batch as batch_reference,
                    b.entry_date as batch_entry_date,
                    b.entry_time as batch_entry_time,
                    b.entry_by as batch_entry_by,
                    b.order_no as batch_order_no,
                    COALESCE(p.date_added, CURDATE()) as date_added_formatted
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_brand br ON p.brand_id = br.brand_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
                $whereClause
                ORDER BY p.product_name ASC, p.batch_id ASC, p.product_id ASC
            ");
        }

        $stmt->execute($params);
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
}

function handle_get_suppliers($conn, $data) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM tbl_supplier
            WHERE status != 'archived' OR status IS NULL
            ORDER BY supplier_id DESC
        ");
        $stmt->execute();
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $suppliers
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "data" => []
        ]);
    }
}

function handle_get_brands($conn, $data) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tbl_brand ORDER BY brand_id DESC");
        $stmt->execute();
        $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $brands
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "data" => []
        ]);
    }
}

function handle_get_categories($conn, $data) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tbl_category ORDER BY category_id");
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $categories
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "data" => []
        ]);
    }
}

function handle_get_locations($conn, $data) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tbl_location ORDER BY location_id");
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $locations
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "data" => []
        ]);
    }
}

function handle_get_inventory_staff($conn, $data) {
    try {
        $stmt = $conn->prepare("
            SELECT emp_id, CONCAT(Fname, ' ', Lname) as name
            FROM tbl_employee
            WHERE status = 'Active'
            ORDER BY Fname, Lname
        ");
        $stmt->execute();
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $staff
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "data" => []
        ]);
    }
}

function handle_get_products_oldest_batch_for_transfer($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? null;

        $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived')";
        $params = [];

        if ($location_id) {
            $whereClause .= " AND p.location_id = ?";
            $params[] = $location_id;
        }

        // Enhanced query to get products with proper SRP from FIFO stock if available
        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                c.category_name,
                p.barcode,
                p.description,
                COALESCE(b.brand, '') as brand,
                COALESCE(s.supplier_name, '') as supplier_name,
                -- Use FIFO stock SRP if available, otherwise fallback to product SRP
                COALESCE(
                    (SELECT fs.srp FROM tbl_fifo_stock fs 
                     WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 
                     ORDER BY fs.expiration_date ASC, fs.entry_date ASC LIMIT 1),
                    p.srp,
                    0
                ) as srp,
                p.location_id,
                l.location_name,
                p.quantity as total_quantity,
                p.quantity as oldest_batch_quantity,
                -- Get oldest batch reference from FIFO stock if available
                COALESCE(
                    (SELECT fs.batch_reference FROM tbl_fifo_stock fs 
                     WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 
                     ORDER BY fs.expiration_date ASC, fs.entry_date ASC LIMIT 1),
                    'N/A'
                ) as batch_reference,
                -- Get entry date from FIFO stock if available
                COALESCE(
                    (SELECT fs.entry_date FROM tbl_fifo_stock fs 
                     WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 
                     ORDER BY fs.expiration_date ASC, fs.entry_date ASC LIMIT 1),
                    'N/A'
                ) as entry_date,
                -- Get expiration date from FIFO stock if available
                COALESCE(
                    (SELECT fs.expiration_date FROM tbl_fifo_stock fs 
                     WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 
                     ORDER BY fs.expiration_date ASC, fs.entry_date ASC LIMIT 1),
                    'N/A'
                ) as expiration_date,
                1 as total_batches
            FROM tbl_product p
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            $whereClause
            AND p.quantity > 0
            ORDER BY p.product_name ASC
        ");

        $stmt->execute($params);
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
}

function handle_get_products_oldest_batch($conn, $data) {
    try {
        $location_id = $data['location_id'] ?? null;

        $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived')";
        $params = [];

        if ($location_id) {
            $whereClause .= " AND p.location_id = ?";
            $params[] = $location_id;
        }

        // Use complex query with FIFO data to ensure correct oldest batch values
        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                c.category_name,
                p.barcode,
                p.description,
                COALESCE(b.brand, '') as brand,
                COALESCE(s.supplier_name, '') as supplier_name,
                COALESCE(oldest_available_batch.srp, p.srp) as unit_price,
                COALESCE(oldest_available_batch.srp, p.srp) as srp,
                COALESCE(oldest_available_batch.srp, p.srp) as first_batch_srp,
                p.location_id,
                l.location_name,
                p.stock_status,
                p.date_added,
                p.status,
                -- Oldest available batch information
                oldest_available_batch.batch_id as oldest_batch_id,
                oldest_available_batch.batch_reference as oldest_batch_reference,
                oldest_available_batch.entry_date as oldest_batch_entry_date,
                oldest_available_batch.expiration_date as oldest_batch_expiration,
                oldest_available_batch.quantity as oldest_batch_quantity,
                oldest_available_batch.srp as oldest_batch_srp,
                oldest_available_batch.entry_time,
                oldest_available_batch.entry_by,
                -- Total quantity across all batches
                total_qty.total_quantity,
                -- Count of total batches
                total_qty.total_batches,
                -- Fallback to product quantity if no stock summary
                COALESCE(total_qty.total_quantity, p.quantity) as quantity
            FROM tbl_product p
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            -- Get oldest AVAILABLE batch for each product (from tbl_fifo_stock)
            LEFT JOIN (
                SELECT
                    fs.product_id,
                    fs.batch_id,
                    fs.batch_reference,
                    fs.entry_date,
                    fs.entry_date as entry_time,
                    fs.entry_by,
                    fs.expiration_date,
                    fs.available_quantity as quantity,
                    fs.srp,
                    ROW_NUMBER() OVER (
                        PARTITION BY fs.product_id
                        ORDER BY 
                            CASE 
                                WHEN fs.expiration_date IS NULL THEN 1 
                                ELSE 0 
                            END,
                            fs.expiration_date ASC, 
                            fs.entry_date ASC, 
                            fs.batch_id ASC
                    ) as batch_rank
                FROM tbl_fifo_stock fs
                WHERE fs.available_quantity > 0  -- Only get batches with available stock
            ) oldest_available_batch ON p.product_id = oldest_available_batch.product_id AND oldest_available_batch.batch_rank = 1
            -- Get total quantities
            LEFT JOIN (
                SELECT
                    product_id,
                    SUM(available_quantity) as total_quantity,
                    COUNT(*) as total_batches
                FROM tbl_stock_summary
                WHERE available_quantity > 0
                GROUP BY product_id
            ) total_qty ON p.product_id = total_qty.product_id
            $whereClause
            ORDER BY p.product_name ASC
        ");
        
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log first product to check FIFO values
        if (!empty($products)) {
            error_log("🔍 First product FIFO data: " . json_encode([
                'product_name' => $products[0]['product_name'],
                'oldest_batch_quantity' => $products[0]['oldest_batch_quantity'],
                'oldest_batch_srp' => $products[0]['oldest_batch_srp'],
                'quantity' => $products[0]['quantity'],
                'srp' => $products[0]['srp']
            ]));
        }
        
        error_log("Warehouse query returned " . count($products) . " products");

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
}

function handle_get_inventory_kpis($conn, $data) {
    try {
        $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
        $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;

        $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
        $params = [];

        if ($product_filter) {
            $whereConditions[] = "c.category_name = ?";
            $params[] = $product_filter;
        }

        if ($location_filter) {
            $whereConditions[] = "l.location_name = ?";
            $params[] = $location_filter;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $stmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN p.stock_status = 'in stock' THEN p.quantity ELSE 0 END) as physicalAvailable,
                SUM(CASE WHEN p.stock_status = 'low stock' THEN p.quantity ELSE 0 END) as softReserved,
                SUM(CASE WHEN p.stock_status = 'in stock' THEN p.quantity ELSE 0 END) as onhandInventory,
                COUNT(CASE WHEN p.quantity <= 10 THEN 1 END) as newOrderLineQty,
                SUM(CASE WHEN p.stock_status = 'out of stock' THEN p.quantity ELSE 0 END) as returned,
                ROUND(COUNT(CASE WHEN p.stock_status = 'out of stock' THEN 1 END) * 100.0 / COUNT(*), 1) as returnRate,
                ROUND(COUNT(CASE WHEN p.stock_status = 'in stock' THEN 1 END) * 100.0 / COUNT(*), 1) as sellRate,
                SUM(CASE WHEN p.stock_status = 'out of stock' THEN p.quantity ELSE 0 END) as outOfStock
            FROM tbl_product p
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            $whereClause
        ");
        $stmt->execute($params);
        $kpis = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($kpis);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_get_supply_by_location($conn, $data) {
    try {
        $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
        $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;

        $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
        $params = [];

        if ($product_filter) {
            $whereConditions[] = "c.category_name = ?";
            $params[] = $product_filter;
        }

        if ($location_filter) {
            $whereConditions[] = "l.location_name = ?";
            $params[] = $location_filter;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $stmt = $conn->prepare("
            SELECT
                l.location_name as location,
                SUM(CASE WHEN p.stock_status = 'in stock' THEN p.quantity ELSE 0 END) as onhand,
                SUM(CASE WHEN p.stock_status = 'low stock' THEN p.quantity ELSE 0 END) as softReserved,
                SUM(CASE WHEN p.stock_status = 'out of stock' THEN p.quantity ELSE 0 END) as returned
            FROM tbl_product p
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            $whereClause
            GROUP BY l.location_name
            ORDER BY onhand DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $supplyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($supplyData);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_get_return_rate_by_product($conn, $data) {
    try {
        $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
        $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;

        $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
        $params = [];

        if ($product_filter) {
            $whereConditions[] = "c.category_name = ?";
            $params[] = $product_filter;
        }

        if ($location_filter) {
            $whereConditions[] = "l.location_name = ?";
            $params[] = $location_filter;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $stmt = $conn->prepare("
            SELECT
                p.product_name as product,
                ROUND(COUNT(CASE WHEN p.stock_status = 'out of stock' THEN 1 END) * 100.0 / COUNT(*), 1) as returnRate
            FROM tbl_product p
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            $whereClause
            GROUP BY p.product_name
            HAVING returnRate > 0
            ORDER BY returnRate DESC
            LIMIT 12
        ");
        $stmt->execute($params);
        $returnData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($returnData);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_get_stockout_items($conn, $data) {
    try {
        $product_filter = isset($data['product']) && $data['product'] !== 'All' ? $data['product'] : null;
        $location_filter = isset($data['location']) && $data['location'] !== 'All' ? $data['location'] : null;

        $whereConditions = ["(p.status IS NULL OR p.status <> 'archived')"];
        $params = [];

        if ($product_filter) {
            $whereConditions[] = "c.category_name = ?";
            $params[] = $product_filter;
        }

        if ($location_filter) {
            $whereConditions[] = "l.location_name = ?";
            $params[] = $location_filter;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $stmt = $conn->prepare("
            SELECT
                p.product_name as product,
                -p.quantity as stockout
            FROM tbl_product p
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            $whereClause
            AND p.stock_status = 'out of stock'
            ORDER BY stockout ASC
            LIMIT 15
        ");
        $stmt->execute($params);
        $stockoutData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($stockoutData);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

function handle_enhanced_fifo_transfer($conn, $data) {
    require_once '../enhanced_fifo_transfer_system.php';

    try {
        $fifoSystem = new EnhancedFifoTransferSystem($conn);
        $result = $fifoSystem->performEnhancedFifoTransfer($data);

        echo json_encode($result);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Enhanced FIFO Transfer Error: ' . $e->getMessage()
        ]);
    }
}

function handle_get_fifo_stock_status($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? null;

        // Get FIFO stock data from tbl_fifo_stock
        $sql = "
            SELECT
                fs.product_id,
                fs.batch_id,
                fs.batch_reference,
                fs.quantity,
                fs.available_quantity,
                fs.srp,
                fs.expiration_date,
                fs.entry_date,
                p.product_name,
                p.location_id
            FROM tbl_fifo_stock fs
            JOIN tbl_product p ON fs.product_id = p.product_id
            WHERE fs.product_id = ?
            AND fs.available_quantity > 0
        ";

        $params = [$product_id];

        if ($location_id) {
            $sql .= " AND p.location_id = ?";
            $params[] = $location_id;
        }

        $sql .= " ORDER BY fs.entry_date ASC, fs.fifo_id ASC"; // FIFO order

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $fifo_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($fifo_entries)) {
            echo json_encode([
                'success' => true,
                'total_available' => 0,
                'batches_count' => 0,
                'fifo_batches' => [],
                'message' => 'Product not found or no FIFO stock available'
            ]);
            return;
        }

        // Calculate total available from FIFO entries
        $total_available = 0;
        $fifo_batches = [];
        $fifo_rank = 1;

        foreach ($fifo_entries as $entry) {
            $total_available += $entry['available_quantity'];

            $fifo_batches[] = [
                'available_quantity' => $entry['available_quantity'],
                'batch_reference' => $entry['batch_reference'],
                'entry_date' => $entry['entry_date'],
                'fifo_rank' => $fifo_rank,
                'batch_id' => $entry['batch_id'],
                'srp' => $entry['srp'],
                'expiration_date' => $entry['expiration_date']
            ];

            $fifo_rank++;
        }

        echo json_encode([
            'success' => true,
            'total_available' => $total_available,
            'batches_count' => count($fifo_batches),
            'fifo_batches' => $fifo_batches,
            'product_name' => $fifo_entries[0]['product_name']
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting FIFO stock status: ' . $e->getMessage()
        ]);
    }
}

function handle_check_fifo_availability($conn, $data) {
    try {
        $product_id = $data['product_id'] ?? 0;
        $location_id = $data['location_id'] ?? 0;
        $requested_quantity = $data['requested_quantity'] ?? 0;

        // Get FIFO stock data from tbl_fifo_stock
        $stmt = $conn->prepare("
            SELECT
                fs.product_id,
                fs.batch_id,
                fs.batch_reference,
                fs.quantity,
                fs.available_quantity,
                fs.srp,
                fs.expiration_date,
                fs.entry_date,
                p.product_name,
                p.location_id
            FROM tbl_fifo_stock fs
            JOIN tbl_product p ON fs.product_id = p.product_id
            WHERE fs.product_id = ?
            AND p.location_id = ?
            AND fs.available_quantity > 0
            ORDER BY fs.entry_date ASC, fs.fifo_id ASC
        ");

        $stmt->execute([$product_id, $location_id]);
        $fifo_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($fifo_entries)) {
            echo json_encode([
                "success" => true,
                "is_available" => false,
                "total_available" => 0,
                "requested_quantity" => $requested_quantity,
                "batches_count" => 0,
                "next_batches" => [],
                "message" => "Product not found or no FIFO stock available"
            ]);
            return;
        }

        // Calculate total available from FIFO entries
        $total_available = 0;
        $next_batches = [];
        $fifo_rank = 1;

        foreach ($fifo_entries as $entry) {
            $total_available += $entry['available_quantity'];

            $next_batches[] = [
                'available_quantity' => $entry['available_quantity'],
                'batch_reference' => $entry['batch_reference'],
                'entry_date' => $entry['entry_date'],
                'fifo_rank' => $fifo_rank,
                'batch_id' => $entry['batch_id'],
                'srp' => $entry['srp'],
                'expiration_date' => $entry['expiration_date']
            ];

            $fifo_rank++;
        }

        $is_available = $total_available >= $requested_quantity;

        echo json_encode([
            "success" => true,
            "is_available" => $is_available,
            "total_available" => $total_available,
            "requested_quantity" => $requested_quantity,
            "batches_count" => count($next_batches),
            "next_batches" => $next_batches,
            "product_name" => $fifo_entries[0]['product_name']
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error checking FIFO availability: " . $e->getMessage()
        ]);
    }
}
// Get products by location name
function handle_get_products_by_location_name($conn, $data) {
    try {
        $location_name = isset($data['location_name']) ? trim($data['location_name']) : '';
        if ($location_name === '') {
            echo json_encode([
                "success" => false,
                "message" => "Missing location_name parameter",
                "data" => []
            ]);
            return;
        }
        // Resolve location name to location_id
        $locStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name = ? LIMIT 1");
        $locStmt->execute([$location_name]);
        $location = $locStmt->fetch(PDO::FETCH_ASSOC);
        if (!$location) {
            echo json_encode([
                "success" => false,
                "message" => "Location not found",
                "data" => []
            ]);
            return;
        }
        $location_id = $location['location_id'];
        // Use the same query as handle_get_products
        $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived') AND p.location_id = ?";
        $params = [$location_id];
        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                c.category_name,
                p.barcode,
                p.description,
                p.prescription,
                p.bulk,
                p.expiration,
                p.quantity,
                p.unit_price,
                p.srp,
                p.brand_id,
                p.supplier_id,
                p.location_id,
                p.batch_id,
                p.stock_status,
                p.date_added,
                p.created_at,
                s.supplier_name,
                br.brand,
                l.location_name,
                b.batch as batch_reference,
                b.entry_date as batch_entry_date,
                b.entry_time as batch_entry_time,
                b.entry_by as batch_entry_by,
                b.order_no as batch_order_no,
                COALESCE(p.date_added, CURDATE()) as date_added_formatted
            FROM tbl_product p
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_brand br ON p.brand_id = br.brand_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
            $whereClause
            ORDER BY p.product_name ASC, p.batch_id ASC, p.product_id ASC
        ");
        $stmt->execute($params);
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
}

// Get products by location ID (alias for handle_get_products)
function handle_get_location_products($conn, $data) {
    try {
        $location_id = isset($data['location_id']) ? intval($data['location_id']) : null;
        if (!$location_id) {
            echo json_encode([
                "success" => false,
                "message" => "Missing location_id parameter",
                "data" => []
            ]);
            return;
        }
        // Use the same query as handle_get_products
        $whereClause = "WHERE (p.status IS NULL OR p.status <> 'archived') AND p.location_id = ?";
        $params = [$location_id];
        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.category_id,
                c.category_name,
                p.barcode,
                p.description,
                p.prescription,
                p.bulk,
                p.expiration,
                p.quantity,
                p.unit_price,
                p.srp,
                p.brand_id,
                p.supplier_id,
                p.location_id,
                p.batch_id,
                p.stock_status,
                p.date_added,
                p.created_at,
                s.supplier_name,
                br.brand,
                l.location_name,
                b.batch as batch_reference,
                b.entry_date as batch_entry_date,
                b.entry_time as batch_entry_time,
                b.entry_by as batch_entry_by,
                b.order_no as batch_order_no,
                COALESCE(p.date_added, CURDATE()) as date_added_formatted
            FROM tbl_product p
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_brand br ON p.brand_id = br.brand_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            LEFT JOIN tbl_batch b ON p.batch_id = b.batch_id
            $whereClause
            ORDER BY p.product_name ASC, p.batch_id ASC, p.product_id ASC
        ");
        $stmt->execute($params);
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
}

?>