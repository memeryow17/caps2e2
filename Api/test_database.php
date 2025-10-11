<?php
// Test database connection and table existence
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Content-Type: application/json");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/conn.php';

try {
    
    echo json_encode([
        "success" => true,
        "message" => "Database connection successful"
    ]);
    
    // Test if tbl_category table exists
    $stmt = $conn->prepare("SHOW TABLES LIKE 'tbl_category'");
    $stmt->execute();
    $tableExists = $stmt->fetch();
    
    if ($tableExists) {
        // Test if table has data
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_category");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "message" => "Database connection successful",
            "tbl_category_exists" => true,
            "tbl_category_count" => $count['count']
        ]);
    } else {
        echo json_encode([
            "success" => true,
            "message" => "Database connection successful",
            "tbl_category_exists" => false,
            "available_tables" => []
        ]);
        
        // Get list of available tables
        $stmt = $conn->prepare("SHOW TABLES");
        $stmt->execute();
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            "success" => true,
            "message" => "Database connection successful",
            "tbl_category_exists" => false,
            "available_tables" => $tables
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
