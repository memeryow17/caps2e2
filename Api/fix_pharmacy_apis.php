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
    $action = $_GET['action'] ?? ($data['action'] ?? 'get_pharmacy_products_fixed');

    switch ($action) {
        case 'get_pharmacy_products_fixed':
            // Fixed pharmacy API that works with transfer-based system
            $search = $data['search'] ?? '';
            $category = $data['category'] ?? 'all';
            
            // Get pharmacy location ID
            $locStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name LIKE '%pharmacy%' LIMIT 1");
            $locStmt->execute();
            $pharmacy_location_id = $locStmt->fetchColumn();
            
            if (!$pharmacy_location_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Pharmacy location not found",
                    "data" => []
                ]);
                break;
            }
            
            // Method 1: Get products from transfer batch details (if they exist)
            $batchDetailsStmt = $conn->prepare("
                SELECT DISTINCT
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    b.brand,
                    s.supplier_name,
                    SUM(tbd.quantity) as total_quantity,
                    COALESCE(tbd.srp, 0) as srp,
                    COALESCE(tbd.srp, 0) as unit_price,
                    MIN(tbd.expiration_date) as expiration_date,
                    'transferred' as source_type
                FROM tbl_transfer_batch_details tbd
                JOIN tbl_product p ON tbd.product_id = p.product_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                WHERE tbd.location_id = ?
                GROUP BY p.product_id, p.product_name, p.barcode, c.category_name, b.brand, s.supplier_name
                ORDER BY p.product_name ASC
            ");
            $batchDetailsStmt->execute([$pharmacy_location_id]);
            $batchProducts = $batchDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Method 2: Get products from approved transfers (fallback)
            $transferStmt = $conn->prepare("
                SELECT DISTINCT
                    p.product_id,
                    p.product_name,
                    p.barcode,
                    c.category_name,
                    b.brand,
                    s.supplier_name,
                    SUM(td.qty) as total_quantity,
                    COALESCE(fs.srp, 0) as srp,
                    COALESCE(fs.srp, 0) as unit_price,
                    fs.expiration_date,
                    'transfer' as source_type
                FROM tbl_transfer_dtl td
                JOIN tbl_transfer_header th ON td.transfer_header_id = th.transfer_header_id
                JOIN tbl_product p ON td.product_id = p.product_id
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id AND fs.available_quantity > 0
                WHERE th.destination_location_id = ? AND th.status = 'approved'
                GROUP BY p.product_id, p.product_name, p.barcode, c.category_name, b.brand, s.supplier_name
                ORDER BY p.product_name ASC
            ");
            $transferStmt->execute([$pharmacy_location_id]);
            $transferProducts = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine results, prioritizing batch details
            $allProducts = [];
            $productIds = [];
            
            // Add batch details products first
            foreach ($batchProducts as $product) {
                $allProducts[] = $product;
                $productIds[] = $product['product_id'];
            }
            
            // Add transfer products that aren't already in batch details
            foreach ($transferProducts as $product) {
                if (!in_array($product['product_id'], $productIds)) {
                    $allProducts[] = $product;
                    $productIds[] = $product['product_id'];
                }
            }
            
            // Apply search filter
            if (!empty($search)) {
                $searchLower = strtolower($search);
                $allProducts = array_filter($allProducts, function($product) use ($searchLower) {
                    return strpos(strtolower($product['product_name']), $searchLower) !== false ||
                           strpos(strtolower($product['barcode']), $searchLower) !== false ||
                           strpos(strtolower($product['category_name']), $searchLower) !== false;
                });
            }
            
            // Apply category filter
            if ($category !== 'all') {
                $allProducts = array_filter($allProducts, function($product) use ($category) {
                    return $product['category_name'] === $category;
                });
            }
            
            // Re-index array
            $allProducts = array_values($allProducts);
            
            echo json_encode([
                "success" => true,
                "data" => $allProducts,
                "count" => count($allProducts),
                "source_breakdown" => [
                    "batch_details" => count($batchProducts),
                    "transfers" => count($transferProducts),
                    "combined" => count($allProducts)
                ]
            ]);
            break;
            
        case 'get_pharmacy_kpis_fixed':
            // Fixed pharmacy KPIs that work with transfer-based system
            $locStmt = $conn->prepare("SELECT location_id FROM tbl_location WHERE location_name LIKE '%pharmacy%' LIMIT 1");
            $locStmt->execute();
            $pharmacy_location_id = $locStmt->fetchColumn();
            
            if (!$pharmacy_location_id) {
                echo json_encode([
                    "success" => false,
                    "message" => "Pharmacy location not found",
                    "data" => []
                ]);
                break;
            }
            
            // Get pharmacy products using the fixed method
            $productsResult = file_get_contents('http://localhost/caps2e2/Api/fix_pharmacy_apis.php?action=get_pharmacy_products_fixed');
            $productsData = json_decode($productsResult, true);
            
            if (!$productsData['success']) {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to get pharmacy products: " . $productsData['message']
                ]);
                break;
            }
            
            $products = $productsData['data'];
            $totalProducts = count($products);
            
            // Calculate KPIs
            $lowStock = 0;
            $expiringSoon = 0;
            $totalValue = 0;
            
            foreach ($products as $product) {
                $qty = intval($product['total_quantity']);
                $srp = floatval($product['srp']);
                
                // Low stock (quantity <= 10)
                if ($qty > 0 && $qty <= 10) {
                    $lowStock++;
                }
                
                // Expiring soon (within 30 days)
                if (!empty($product['expiration_date'])) {
                    $expDate = new DateTime($product['expiration_date']);
                    $thirtyDaysFromNow = new DateTime('+30 days');
                    $now = new DateTime();
                    
                    if ($expDate <= $thirtyDaysFromNow && $expDate >= $now) {
                        $expiringSoon++;
                    }
                }
                
                // Total value
                $totalValue += $srp * $qty;
            }
            
            echo json_encode([
                "success" => true,
                "data" => [
                    "totalProducts" => $totalProducts,
                    "lowStock" => $lowStock,
                    "expiringSoon" => $expiringSoon,
                    "totalValue" => $totalValue,
                    "sourceBreakdown" => $productsData['source_breakdown']
                ]
            ]);
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
