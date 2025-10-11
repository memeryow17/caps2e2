<?php
/**
 * Printer Test Script
 * This script helps you test if your printer is configured correctly
 * 
 * Usage: http://localhost/caps2e2/Api/test-printer.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printer Test - POS System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .status {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-weight: bold;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        button {
            background: #4CAF50;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
        }
        button:hover {
            background: #45a049;
        }
        .printer-list {
            background: #f9f9f9;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🖨️ POS Printer Test</h1>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'list_printers') {
                echo '<h2>Available Printers:</h2>';
                
                // Get list of printers
                $command = 'wmic printer get name';
                $output = shell_exec($command);
                
                if ($output) {
                    echo '<div class="printer-list">';
                    echo '<pre>' . htmlspecialchars($output) . '</pre>';
                    echo '</div>';
                    echo '<div class="info">📋 Copy one of these printer names and update it in <code>Api/print-receipt-fixed-width.php</code> line 152</div>';
                } else {
                    echo '<div class="error">❌ Could not retrieve printer list. Make sure you\'re running this on Windows.</div>';
                }
            }
            
            if ($action === 'test_print') {
                $printerName = $_POST['printer_name'] ?? 'POS-58';
                
                echo '<h2>Testing Printer: ' . htmlspecialchars($printerName) . '</h2>';
                
                // Create test receipt
                $testReceipt = str_repeat('=', 32) . "\r\n";
                $testReceipt .= str_pad("TEST RECEIPT", 32, " ", STR_PAD_BOTH) . "\r\n";
                $testReceipt .= str_repeat('=', 32) . "\r\n";
                $testReceipt .= "Date: " . date('Y-m-d') . "\r\n";
                $testReceipt .= "Time: " . date('H:i:s') . "\r\n";
                $testReceipt .= "Test ID: TEST-" . time() . "\r\n";
                $testReceipt .= str_repeat('-', 32) . "\r\n";
                $testReceipt .= "This is a test receipt\r\n";
                $testReceipt .= "from your POS system.\r\n";
                $testReceipt .= str_repeat('=', 32) . "\r\n";
                $testReceipt .= str_pad("If you see this,", 32, " ", STR_PAD_BOTH) . "\r\n";
                $testReceipt .= str_pad("your printer works!", 32, " ", STR_PAD_BOTH) . "\r\n";
                $testReceipt .= "\r\n\r\n\r\n\r\n\r\n";
                
                // Save to temp file
                $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_receipt_' . time() . '.txt';
                file_put_contents($tempFile, $testReceipt);
                
                // Try to print
                $command = 'copy /b ' . escapeshellarg($tempFile) . ' ' . escapeshellarg('\\\\localhost\\' . $printerName);
                $output = shell_exec($command . ' 2>&1');
                
                @unlink($tempFile);
                
                $lower = strtolower((string)$output);
                $success = ($output === null) || (strpos($lower, 'error') === false && strpos($lower, 'denied') === false && strpos($lower, 'not found') === false);
                
                if ($success) {
                    echo '<div class="success">';
                    echo '✅ <strong>SUCCESS!</strong> Test receipt sent to printer.<br>';
                    echo 'Check your printer - it should have printed a test receipt.<br>';
                    echo 'Printer: <code>' . htmlspecialchars($printerName) . '</code>';
                    echo '</div>';
                } else {
                    echo '<div class="error">';
                    echo '❌ <strong>FAILED!</strong> Could not print to: <code>' . htmlspecialchars($printerName) . '</code><br><br>';
                    echo '<strong>Error Output:</strong><br>';
                    echo '<pre>' . htmlspecialchars($output) . '</pre>';
                    echo '<br><strong>Troubleshooting:</strong><br>';
                    echo '1. Make sure the printer name is <strong>exactly correct</strong> (case-sensitive)<br>';
                    echo '2. Check if printer is turned on and ready<br>';
                    echo '3. Verify printer is shared in Windows (Control Panel → Printers)<br>';
                    echo '4. Click "List All Printers" below to see available printers';
                    echo '</div>';
                }
            }
        }
        ?>
        
        <h2>Test Your Printer</h2>
        <p>Use this page to test if your receipt printer is configured correctly.</p>
        
        <form method="POST" style="margin: 20px 0;">
            <input type="hidden" name="action" value="list_printers">
            <button type="submit">📋 List All Printers</button>
        </form>
        
        <form method="POST" style="margin: 20px 0;">
            <input type="hidden" name="action" value="test_print">
            <label for="printer_name"><strong>Printer Name:</strong></label><br>
            <input type="text" id="printer_name" name="printer_name" value="POS-58" 
                   style="padding: 10px; margin: 10px 0; width: 300px; font-size: 14px;">
            <br>
            <button type="submit">🖨️ Send Test Receipt</button>
        </form>
        
        <div class="info">
            <strong>💡 Quick Tips:</strong><br>
            1. First, click "List All Printers" to see your available printers<br>
            2. Copy your printer name from the list<br>
            3. Paste it in the "Printer Name" field above<br>
            4. Click "Send Test Receipt" to test<br>
            5. If successful, update the printer name in <code>Api/print-receipt-fixed-width.php</code>
        </div>
        
        <h2>Manual Command Test</h2>
        <p>You can also test from Command Prompt:</p>
        <pre>echo Test > test.txt
copy /b test.txt \\localhost\POS-58</pre>
        <p><small>Replace <code>POS-58</code> with your actual printer name</small></p>
        
        <hr style="margin: 30px 0;">
        
        <h2>Current Configuration</h2>
        <?php
        $configFile = __DIR__ . '/print-receipt-fixed-width.php';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            if (preg_match('/\$printerName\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $currentPrinter = $matches[1];
                echo '<div class="info">';
                echo '📝 Currently configured printer: <strong><code>' . htmlspecialchars($currentPrinter) . '</code></strong><br>';
                echo 'File: <code>Api/print-receipt-fixed-width.php</code> (line ~152)';
                echo '</div>';
            }
        }
        ?>
        
        <h2>Need Help?</h2>
        <p>See <code>PRINTER_SETUP_GUIDE.md</code> for detailed setup instructions.</p>
    </div>
</body>
</html>

