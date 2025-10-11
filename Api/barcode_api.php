<?php
require_once __DIR__ . '/modules/barcode.php';
require_once __DIR__ . '/conn.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;

$conn = getDatabaseConnection();
$response = null;

switch ($action) {
    case 'check_barcode':
        $response = check_barcode($conn, $data);
        break;
    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
        break;
}

echo json_encode($response);
