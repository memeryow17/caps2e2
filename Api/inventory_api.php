<?php
require_once __DIR__ . '/modules/inventory.php';
require_once __DIR__ . '/conn.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;

$conn = getDatabaseConnection();
$response = null;

switch ($action) {
    case 'update_product_stock':
        $response = update_product_stock($conn, $data);
        break;
    case 'delete_product':
        $response = delete_product($conn, $data);
        break;
    case 'add_supplier':
        $response = add_supplier($conn, $data);
        break;
    case 'update_supplier':
        $response = update_supplier($conn, $data);
        break;
    case 'update_product':
        $response = update_product($conn, $data);
        break;
    case 'delete_supplier':
        $response = delete_supplier($conn, $data);
        break;
    case 'get_suppliers':
        $response = get_suppliers($conn, $data);
        break;
    case 'get_brands':
        $response = get_brands($conn, $data);
        break;
    case 'get_categories':
        $response = get_categories($conn, $data);
        break;
    case 'get_products':
        $response = get_products($conn, $data);
        break;
    case 'get_product_quantities':
        $response = get_product_quantities($conn, $data);
        break;
    case 'get_expiring_products':
        $response = get_expiring_products($conn, $data);
        break;
    case 'get_quantity_history':
        $response = get_quantity_history($conn, $data);
        break;
    case 'add_quantity_to_product':
        $response = add_quantity_to_product($conn, $data);
        break;
    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
        break;
}

echo json_encode($response);
