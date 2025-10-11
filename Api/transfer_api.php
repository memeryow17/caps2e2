<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Proxy-only router to preserve existing logic in Api/backend.php for transfer/FIFO
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = []; }
$action = $_GET['action'] ?? ($data['action'] ?? '');

$allowed = [
    'get_transfers_with_details',
    'get_transfer_log',
    'get_transfer_log_by_id',
    'get_transfer_batch_details',
    'get_products_oldest_batch_for_transfer',
    'get_locations',
    'get_current_user',
    'get_inventory_staff',
    'get_fifo_stock_status',
    'check_fifo_availability',
    'create_transfer',
    'delete_transfer',
    'get_transfer_logs',
    'get_fifo_stock',
    'create_transfer_batch_details_table'
];

if (!in_array($action, $allowed, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/Api/transfer_api.php'), '/\\');
$backendUrl = $scheme . '://' . $host . $basePath . '/backend.php';

$ch = curl_init($backendUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}
curl_close($ch);
echo $response;
?>


