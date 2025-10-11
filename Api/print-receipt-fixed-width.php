<?php
// Receipt printer – fixed width 32 chars, automatic printing
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function returnJson($success, $message, $data = []) {
    ob_end_clean();
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnJson(false, 'Method not allowed');
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        returnJson(false, 'Invalid JSON input');
    }

    $receiptWidth = 32;

    function formatPriceLine($label, $amount, $width) {
        $amountStr = number_format((float)$amount, 2);
        $spaces = $width - strlen($label) - strlen($amountStr);
        return $label . str_repeat(' ', max(0, $spaces)) . $amountStr;
    }

    // Wrap an ITEM name into lines of a fixed width without cutting words
    function wrapItemName($text, $width) {
        $text = trim((string)$text);
        if ($text === '') return [''];
        $wrapped = wordwrap($text, $width, "\n", true);
        return explode("\n", $wrapped);
    }

    // Build receipt content
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
        $receipt .= formatPriceLine('Discount Amt:', '-' . number_format((float)$input['discountAmount'], 2), $receiptWidth) . "\n";
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

    // Convert to Windows line endings
    $formatted = str_replace("\n", "\r\n", $receipt);
    
    // Create temporary file
    $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'receipt_' . time() . '.txt';
    file_put_contents($temp, $formatted);

    // ===== PRINTER CONFIGURATION =====
    // Change this to match your actual printer name
    // To find your printer name:
    // 1. Open Control Panel > Devices and Printers
    // 2. Right-click your receipt printer and select "Printer properties"
    // 3. Copy the exact printer name shown at the top
    // 4. Replace 'POS-58' below with your printer name
    
    $printerName = 'POS-58'; // <-- CHANGE THIS TO YOUR PRINTER NAME
    
    // Alternative printer names to try if the main one fails
    $alternativePrinters = [
        'POS-58',
      
    ];
    
    // ===== END PRINTER CONFIGURATION =====

    $printSuccess = false;
    $lastError = '';
    $printMethod = '';
    
    // METHOD 1: Try COPY command with localhost share (works better for POS-58)
    $command = 'copy /b "' . $temp . '" "\\\\localhost\\' . $printerName . '" 2>&1';
    $output = shell_exec($command);
    $lower = strtolower((string)$output);
    
    if ($output === null || (strpos($lower, 'error') === false && strpos($lower, 'denied') === false && strpos($lower, 'not found') === false)) {
        $printSuccess = true;
        $printMethod = 'copy_command';
    } else {
        $lastError = $output;
        
        // METHOD 2: Try Windows PRINT command as fallback
        $command = 'print /D:"' . $printerName . '" "' . $temp . '" 2>&1';
        $output = shell_exec($command);
        $lower = strtolower((string)$output);
        
        if ($output === null || (strpos($lower, 'error') === false && strpos($lower, 'denied') === false && strpos($lower, 'not found') === false)) {
            $printSuccess = true;
            $printMethod = 'print_command';
        } else {
            $lastError .= "\n" . $output;
            
            // METHOD 3: Try alternative printer names
            foreach ($alternativePrinters as $altPrinter) {
                if ($altPrinter === $printerName) continue;
                
                // Try PRINT command
                $command = 'print /D:"' . $altPrinter . '" "' . $temp . '" 2>&1';
                $output = shell_exec($command);
                $lower = strtolower((string)$output);
                
                if ($output === null || (strpos($lower, 'error') === false && strpos($lower, 'denied') === false && strpos($lower, 'not found') === false)) {
                    $printSuccess = true;
                    $printerName = $altPrinter;
                    $printMethod = 'print_command_alt';
                    break;
                }
                
                // Try COPY command
                $command = 'copy /b "' . $temp . '" "\\\\localhost\\' . $altPrinter . '" 2>&1';
                $output = shell_exec($command);
                $lower = strtolower((string)$output);
                
                if ($output === null || (strpos($lower, 'error') === false && strpos($lower, 'denied') === false && strpos($lower, 'not found') === false)) {
                    $printSuccess = true;
                    $printerName = $altPrinter;
                    $printMethod = 'copy_localhost_alt';
                    break;
                }
            }
        }
    }

    // Clean up temp file
    @unlink($temp);

    if ($printSuccess) {
        returnJson(true, 'Receipt printed successfully!', [
            'method' => $printMethod,
            'printer' => $printerName,
            'transactionId' => ($input['transactionId'] ?? null)
        ]);
    } else {
        // Save receipt to file as backup
        $backupDir = __DIR__ . '/../receipts';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0777, true);
        }
        $backupFile = $backupDir . '/receipt_' . ($input['transactionId'] ?? time()) . '.txt';
        file_put_contents($backupFile, $formatted);
        
        returnJson(false, 'Printing failed. Receipt saved to: ' . basename($backupFile) . '. Error: ' . $lastError, [
            'method' => 'copy_raw',
            'backup_file' => $backupFile,
            'error' => $lastError,
            'tried_printers' => array_merge([$printerName], $alternativePrinters)
        ]);
    }

} catch (Exception $e) {
    returnJson(false, 'Error: ' . $e->getMessage());
}
?>
