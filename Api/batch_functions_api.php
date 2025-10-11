<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
require_once __DIR__ . '/modules/batch_functions.php';
require_once __DIR__ . '/conn.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;

$conn = getDatabaseConnection();
$response = null;

switch ($action) {
    case 'duplicate_product_batches':
        $response = duplicate_product_batches($conn, $data);
        break;
    case 'create_transfer_batch_details_table':
        $response = create_transfer_batch_details_table($conn, $data);
        break;
    case 'get_batches':
        $response = get_batches($conn, $data);
        break;
    case 'add_batch_entry':
        $response = add_batch_entry($conn, $data);
        break;
    case 'sync_fifo_stock':
        $response = sync_fifo_stock($conn, $data);
        break;
    case 'force_sync_all_products':
        $response = force_sync_all_products($conn, $data);
        break;
    case 'cleanup_duplicate_transfer_products':
        $response = cleanup_duplicate_transfer_products($conn, $data);
        break;
    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
        break;
}

echo json_encode($response);
