<?php
// qz-tray-receipt.php - For QZ Tray Integration
// This script generates raw receipt data that QZ Tray will print directly.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$receiptWidth = 32; // Standard thermal printer width

// Function to format a price line
function formatPriceLine($label, $amount, $width) {
    $amountStr = number_format((float)$amount, 2);
    $spaces = $width - strlen($label) - strlen($amountStr);
    return $label . str_repeat(' ', max(0, $spaces)) . $amountStr;
}

// Function to wrap item name
function wrapItemName($text, $width) {
    $text = trim((string)$text);
    if ($text === '') return [''];
    $wrapped = wordwrap($text, $width, "\n", true);
    return explode("\n", $wrapped);
}

// Build receipt content (raw text for QZ Tray)
$receipt = '';
$receipt .= str_repeat('=', $receiptWidth) . "\n";
$receipt .= str_pad("ENGUIO'S PHARMACY", $receiptWidth, ' ', STR_PAD_BOTH) . "\n";
$receipt .= str_repeat('=', $receiptWidth) . "\n";
$receipt .= 'Date: ' . ($input['date'] ?? date('Y-m-d')) . "\n";
$receipt .= 'Time: ' . ($input['time'] ?? date('H:i:s')) . "\n";
$receipt .= 'TXN ID: ' . ($input['transactionId'] ?? 'N/A') . "\n";
$receipt .= 'Cashier: ' . ($input['cashier'] ?? 'Admin') . "\n";
$receipt .= 'Terminal: ' . ($input['terminalName'] ?? 'POS') . "\n";
$receipt .= str_repeat('-', $receiptWidth) . "\n";

// Items header
$receipt .= str_pad('QTY', 4)
  . str_pad('ITEM', 14)
  . str_pad('PRICE', 7, ' ', STR_PAD_LEFT)
  . str_pad('TOTAL', 7, ' ', STR_PAD_LEFT) . "\n";
$receipt .= str_repeat('-', $receiptWidth) . "\n";

// Items
if (!empty($input['items'])) {
    foreach ($input['items'] as $item) {
        $name = $item['name'] ?? 'Unknown';
        $qty = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $total = $qty * $price;

        $lines = wrapItemName($name, 14);
        $first = array_shift($lines);

        // First line with qty, price, total
        $receipt .= str_pad($qty, 4)
          . str_pad($first, 14)
          . str_pad(number_format($price, 2), 7, ' ', STR_PAD_LEFT)
          . str_pad(number_format($total, 2), 7, ' ', STR_PAD_LEFT) . "\n";

        // Continuation lines (wrapped item names)
        foreach ($lines as $cont) {
            $receipt .= str_repeat(' ', 4) . str_pad($cont, 14) . "\n";
        }
    }
} else {
    $receipt .= "No items found\n";
}

$receipt .= str_repeat('-', $receiptWidth) . "\n";

// Subtotal
$subtotal = (float)($input['subtotal'] ?? $input['total'] ?? 0);
$receipt .= formatPriceLine('SUBTOTAL:', $subtotal, $receiptWidth) . "\n";

// Discount (if any)
if (!empty($input['discountType']) && isset($input['discountAmount']) && (float)$input['discountAmount'] > 0) {
    $receipt .= 'Discount: ' . $input['discountType'] . "\n";
    $discountAmt = (float)$input['discountAmount'];
    $receipt .= formatPriceLine('Discount Amt:', $discountAmt, $receiptWidth) . "\n";
}

// Grand Total
$totalAmount = (float)($input['grandTotal'] ?? $input['total'] ?? 0);
$receipt .= str_repeat('-', $receiptWidth) . "\n";
$receipt .= formatPriceLine('GRAND TOTAL:', $totalAmount, $receiptWidth) . "\n";
$receipt .= str_repeat('-', $receiptWidth) . "\n";

// Payment details
$receipt .= 'PAYMENT: ' . strtoupper($input['paymentMethod'] ?? 'Unknown') . "\n";

$pmLower = strtolower((string)($input['paymentMethod'] ?? ''));
if ($pmLower === 'cash') {
    $receipt .= formatPriceLine('CASH:', $input['amountPaid'] ?? 0, $receiptWidth) . "\n";
    $receipt .= formatPriceLine('CHANGE:', $input['change'] ?? 0, $receiptWidth) . "\n";
} elseif ($pmLower === 'gcash') {
    if (!empty($input['gcashRef'])) {
        $receipt .= 'GCASH REF: ' . $input['gcashRef'] . "\n";
    }
    $receipt .= formatPriceLine('AMOUNT PAID:', $input['amountPaid'] ?? 0, $receiptWidth) . "\n";
    $receipt .= formatPriceLine('CHANGE:', $input['change'] ?? 0, $receiptWidth) . "\n";
}

$receipt .= str_repeat('=', $receiptWidth) . "\n";
$receipt .= str_pad('Thank you!', $receiptWidth, ' ', STR_PAD_BOTH) . "\n";
$receipt .= str_pad('Please come again', $receiptWidth, ' ', STR_PAD_BOTH) . "\n";
$receipt .= str_pad('This is your official receipt', $receiptWidth, ' ', STR_PAD_BOTH) . "\n";

// Feed lines for paper cut
$receipt .= "\n\n\n\n\n";

// Return raw receipt data for QZ Tray
echo json_encode([
    'success' => true,
    'message' => 'Raw receipt data generated for QZ Tray',
    'data' => [
        'rawReceipt' => $receipt,
        'transactionId' => ($input['transactionId'] ?? null)
    ]
]);
?>

