<?php
require_once __DIR__ . '/modules/products.php';
require_once __DIR__ . '/conn.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;

$conn = getDatabaseConnection();
$response = null;

switch ($action) {
    case 'get_products':
        $response = handle_get_products($conn, $data);
        break;
    case 'get_suppliers':
        $response = handle_get_suppliers($conn, $data);
        break;
    case 'get_brands':
        $response = handle_get_brands($conn, $data);
        break;
    case 'get_categories':
        $response = handle_get_categories($conn, $data);
        break;
    case 'get_fifo_stock':
        $response = handle_get_fifo_stock_status($conn, $data);
        break;
    case 'get_products_oldest_batch':
        $response = handle_get_products_oldest_batch($conn, $data);
        break;
    case 'get_warehouse_kpis':
        $response = handle_get_inventory_kpis($conn, $data);
        break;
    // Add more cases as needed for other actions
    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
        break;
}

echo json_encode($response);
