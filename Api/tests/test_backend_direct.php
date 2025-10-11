<?php
// Test backend directly
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Content-Type: application/json");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Test database connection first
    require_once 'conn_mysqli.php';
    
    // Test get_categories action
    $action = 'get_categories';
    
    switch ($action) {
        case 'get_categories':
            try {
                $stmt = $conn->prepare("SELECT * FROM tbl_category ORDER BY category_id");
                $stmt->execute();
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    "success" => true,
                    "data" => $categories,
                    "count" => count($categories)
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Database error: " . $e->getMessage(),
                    "data" => []
                ]);
            }
            break;
            
        default:
            echo json_encode([
                "success" => false,
                "message" => "Unknown action: $action"
            ]);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Connection error: " . $e->getMessage()
    ]);
}
?>
