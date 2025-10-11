<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../fifo_transfer_system.php';

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'transfer':
            handleTransfer();
            break;
            
        case 'check_stock':
            handleCheckStock();
            break;
            
        case 'transfer_history':
            handleTransferHistory();
            break;
            
        case 'available_products':
            handleAvailableProducts();
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle FIFO transfer request
 */
function handleTransfer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Transfer action requires POST method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $product_barcode = $input['product_barcode'] ?? null;
    $source_location_id = $input['source_location_id'] ?? null;
    $destination_location_id = $input['destination_location_id'] ?? null;
    $requested_quantity = $input['requested_quantity'] ?? null;
    $employee_id = $input['employee_id'] ?? null;
    
    // Validate required fields
    if (!$product_barcode || !$source_location_id || !$destination_location_id || !$requested_quantity || !$employee_id) {
        throw new Exception('Missing required fields: product_barcode, source_location_id, destination_location_id, requested_quantity, employee_id');
    }
    
    // Validate data types
    if (!is_numeric($product_barcode) || !is_numeric($source_location_id) || 
        !is_numeric($destination_location_id) || !is_numeric($requested_quantity) || 
        !is_numeric($employee_id)) {
        throw new Exception('All IDs and quantities must be numeric');
    }
    
    $result = performFifoTransfer(
        intval($product_barcode),
        intval($source_location_id),
        intval($destination_location_id),
        intval($requested_quantity),
        intval($employee_id)
    );
    
    echo json_encode($result);
}

/**
 * Handle stock check request
 */
function handleCheckStock() {
    $product_barcode = $_GET['product_barcode'] ?? null;
    $location_id = $_GET['location_id'] ?? null;
    
    if (!$product_barcode || !$location_id) {
        throw new Exception('Missing required parameters: product_barcode, location_id');
    }
    
    if (!is_numeric($product_barcode) || !is_numeric($location_id)) {
        throw new Exception('Product barcode and location ID must be numeric');
    }
    
    $result = getAvailableStock(intval($product_barcode), intval($location_id));
    echo json_encode($result);
}

/**
 * Handle transfer history request
 */
function handleTransferHistory() {
    global $conn;
    
    $limit = intval($_GET['limit'] ?? 20);
    $offset = intval($_GET['offset'] ?? 0);
    $product_barcode = $_GET['product_barcode'] ?? null;
    $location_id = $_GET['location_id'] ?? null;
    
    $where_conditions = [];
    $params = [];
    
    if ($product_barcode) {
        $where_conditions[] = "p.barcode = :product_barcode";
        $params[':product_barcode'] = $product_barcode;
    }
    
    if ($location_id) {
        $where_conditions[] = "(th.source_location_id = :location_id OR th.destination_location_id = :location_id)";
        $params[':location_id'] = $location_id;
    }
    
    $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT DISTINCT
            th.transfer_header_id,
            th.date,
            sl.location_name as source_location,
            dl.location_name as destination_location,
            CONCAT(e.Fname, ' ', e.Lname) as employee_name,
            th.status,
            COUNT(DISTINCT td.transfer_dtl_id) as items_count,
            SUM(td.qty) as total_quantity
        FROM tbl_transfer_header th
        INNER JOIN tbl_location sl ON th.source_location_id = sl.location_id
        INNER JOIN tbl_location dl ON th.destination_location_id = dl.location_id
        INNER JOIN tbl_employee e ON th.employee_id = e.emp_id
        LEFT JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
        LEFT JOIN tbl_product p ON td.product_id = p.product_id
        {$where_clause}
        GROUP BY th.transfer_header_id
        ORDER BY th.transfer_header_id DESC
        LIMIT :limit OFFSET :offset
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $transfers,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($transfers)
        ]
    ]);
}

/**
 * Handle available products request
 */
function handleAvailableProducts() {
    global $conn;
    
    $location_id = $_GET['location_id'] ?? null;
    $search = $_GET['search'] ?? '';
    
    if (!$location_id) {
        throw new Exception('Missing required parameter: location_id');
    }
    
    $search_condition = '';
    $params = [':location_id' => $location_id];
    
    if (!empty($search)) {
        $search_condition = "AND (p.product_name LIKE :search OR p.barcode LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    $stmt = $conn->prepare("
        SELECT 
            p.barcode,
            p.product_name,
            c.category_name as category,
            SUM(p.quantity) as total_quantity,
            COUNT(DISTINCT p.batch_id) as batch_count,
            MIN(b.entry_date) as oldest_batch_date,
            MAX(b.entry_date) as newest_batch_date,
            p.unit_price
        FROM tbl_product p
        LEFT JOIN tbl_category c ON p.category_id = c.category_id
                INNER JOIN tbl_batch b ON p.batch_id = b.batch_id
        WHERE p.location_id = :location_id 
            AND p.status = 'active'
            AND p.quantity > 0
            {$search_condition}
        GROUP BY p.barcode, p.product_name, c.category_name as category, p.unit_price
        HAVING total_quantity > 0
        ORDER BY p.product_name ASC
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $products
    ]);
}

/**
 * Get locations list
 */
function getLocations() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT location_id, location_name 
        FROM tbl_location 
        WHERE status = 'active' 
        ORDER BY location_name
    ");
    
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $locations
    ]);
}
?> 