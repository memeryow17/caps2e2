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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = []; }
$action = $_GET['action'] ?? ($data['action'] ?? '');

switch ($action) {
    case 'find_duplicates':
        try {
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name,
                    l.location_name,
                    COUNT(*) as duplicate_count,
                    GROUP_CONCAT(p.product_id ORDER BY p.product_id) as product_ids,
                    GROUP_CONCAT(p.quantity ORDER BY p.product_id) as quantities,
                    GROUP_CONCAT(p.unit_price ORDER BY p.product_id) as prices,
                    SUM(p.quantity) as total_quantity,
                    AVG(p.unit_price) as avg_price
                FROM tbl_product p
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                GROUP BY p.product_name, l.location_name
                HAVING COUNT(*) > 1
                ORDER BY duplicate_count DESC, p.product_name
            ");
            $stmt->execute();
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $duplicates,
                'count' => count($duplicates)
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'merge_duplicates':
        try {
            $product_name = $data['product_name'] ?? '';
            $location_name = $data['location_name'] ?? '';
            
            if (empty($product_name) || empty($location_name)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Product name and location name are required'
                ]);
                break;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Find duplicates
            $stmt = $conn->prepare("
                SELECT p.product_id, p.quantity, p.unit_price
                FROM tbl_product p
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE p.product_name = ? 
                AND l.location_name = ?
                AND (p.status IS NULL OR p.status <> 'archived')
                ORDER BY p.product_id DESC
            ");
            $stmt->execute([$product_name, $location_name]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($products) <= 1) {
                $conn->rollback();
                echo json_encode([
                    'success' => false,
                    'message' => 'No duplicates found for this product'
                ]);
                break;
            }
            
            // Use the first product (highest ID) as primary
            $primary_product = $products[0];
            $primary_id = $primary_product['product_id'];
            
            // Calculate total quantity
            $total_quantity = array_sum(array_column($products, 'quantity'));
            
            // Update primary product with combined quantity
            $stmt = $conn->prepare("
                UPDATE tbl_product 
                SET quantity = ?
                WHERE product_id = ?
            ");
            $stmt->execute([$total_quantity, $primary_id]);
            
            // Archive duplicate products
            $duplicate_ids = array_column(array_slice($products, 1), 'product_id');
            if (!empty($duplicate_ids)) {
                $placeholders = str_repeat('?,', count($duplicate_ids) - 1) . '?';
                $stmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET status = 'archived'
                    WHERE product_id IN ($placeholders)
                ");
                $stmt->execute($duplicate_ids);
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Successfully merged " . count($products) . " duplicate products",
                'primary_id' => $primary_id,
                'total_quantity' => $total_quantity,
                'archived_count' => count($duplicate_ids)
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'merge_all_duplicates':
        try {
            // Start transaction
            $conn->beginTransaction();
            
            // Find all duplicates
            $stmt = $conn->prepare("
                SELECT 
                    p.product_name,
                    l.location_name,
                    GROUP_CONCAT(p.product_id ORDER BY p.product_id) as product_ids,
                    GROUP_CONCAT(p.quantity ORDER BY p.product_id) as quantities
                FROM tbl_product p
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                WHERE (p.status IS NULL OR p.status <> 'archived')
                GROUP BY p.product_name, l.location_name
                HAVING COUNT(*) > 1
            ");
            $stmt->execute();
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $merged_count = 0;
            $archived_count = 0;
            
            foreach ($duplicates as $duplicate) {
                $product_name = $duplicate['product_name'];
                $location_name = $duplicate['location_name'];
                $product_ids = explode(',', $duplicate['product_ids']);
                $quantities = explode(',', $duplicate['quantities']);
                
                // Use the first product (highest ID) as primary
                $primary_id = $product_ids[0];
                $total_quantity = array_sum($quantities);
                
                // Update primary product with combined quantity
                $stmt = $conn->prepare("
                    UPDATE tbl_product 
                    SET quantity = ?
                    WHERE product_id = ?
                ");
                $stmt->execute([$total_quantity, $primary_id]);
                
                // Archive duplicate products
                $duplicate_ids = array_slice($product_ids, 1);
                if (!empty($duplicate_ids)) {
                    $placeholders = str_repeat('?,', count($duplicate_ids) - 1) . '?';
                    $stmt = $conn->prepare("
                        UPDATE tbl_product 
                        SET status = 'archived'
                        WHERE product_id IN ($placeholders)
                    ");
                    $stmt->execute($duplicate_ids);
                    $archived_count += count($duplicate_ids);
                }
                
                $merged_count++;
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Successfully merged $merged_count duplicate product groups",
                'merged_groups' => $merged_count,
                'archived_products' => $archived_count
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
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


