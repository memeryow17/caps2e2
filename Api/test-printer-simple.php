<?php
// Simple Printer Test - Tagalog/English
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🖨️ Printer Test - Simple</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            margin: 10px 5px;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .btn-secondary {
            background: #2196F3;
        }
        .btn-secondary:hover {
            background: #0b7dda;
        }
        .status {
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            font-weight: bold;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 2px solid #bee5eb;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            margin: 10px 0;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 5px;
            margin: 10px 0;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #4CAF50;
        }
        label {
            display: block;
            font-weight: bold;
            margin-top: 15px;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🖨️ Printer Test (Simple)</h1>
        <p class="subtitle">Test kung gumagana ang printer mo</p>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'list_printers') {
                echo '<div class="status info">';
                echo '<strong>📋 Mga Available na Printer:</strong>';
                echo '</div>';
                
                $command = 'wmic printer get name 2>&1';
                $output = shell_exec($command);
                
                if ($output && strlen(trim($output)) > 0) {
                    echo '<pre>' . htmlspecialchars($output) . '</pre>';
                    echo '<div class="status success">';
                    echo '✅ Nakuha ang list ng printers! Copy yung name ng printer mo sa taas.';
                    echo '</div>';
                } else {
                    echo '<div class="status error">';
                    echo '❌ Hindi makuha ang printer list.<br>';
                    echo 'Siguraduhin na may installed printer ka sa Windows.';
                    echo '</div>';
                }
            }
            
            if ($action === 'test_print') {
                $printerName = trim($_POST['printer_name'] ?? 'POS-58');
                
                echo '<div class="status info">';
                echo '<strong>🖨️ Testing Printer: ' . htmlspecialchars($printerName) . '</strong>';
                echo '</div>';
                
                // Create test receipt
                $receipt = str_repeat('=', 32) . "\r\n";
                $receipt .= str_pad("TEST PRINT", 32, " ", STR_PAD_BOTH) . "\r\n";
                $receipt .= str_repeat('=', 32) . "\r\n";
                $receipt .= "Date: " . date('Y-m-d') . "\r\n";
                $receipt .= "Time: " . date('H:i:s') . "\r\n";
                $receipt .= str_repeat('-', 32) . "\r\n";
                $receipt .= "Kung makita mo to,\r\n";
                $receipt .= "GUMAGANA ANG PRINTER MO!\r\n";
                $receipt .= str_repeat('=', 32) . "\r\n";
                $receipt .= "\r\n\r\n\r\n";
                
                // Save to temp file
                $tempFile = sys_get_temp_dir() . '\\test_' . time() . '.txt';
                file_put_contents($tempFile, $receipt);
                
                // Try to print
                $command = 'copy /b "' . $tempFile . '" "\\\\localhost\\' . $printerName . '" 2>&1';
                $output = shell_exec($command);
                
                @unlink($tempFile);
                
                $lower = strtolower((string)$output);
                $success = ($output === null) || 
                          (strpos($lower, 'error') === false && 
                           strpos($lower, 'denied') === false && 
                           strpos($lower, 'not found') === false);
                
                if ($success) {
                    echo '<div class="status success">';
                    echo '✅ <strong>SUCCESS! Nag-print na!</strong><br><br>';
                    echo 'Check mo ang printer mo - dapat may lumalabas na test receipt.<br>';
                    echo 'Printer: <code>' . htmlspecialchars($printerName) . '</code>';
                    echo '</div>';
                } else {
                    echo '<div class="status error">';
                    echo '❌ <strong>FAILED! Hindi nag-print.</strong><br><br>';
                    echo '<strong>Error:</strong><br>';
                    echo '<pre>' . htmlspecialchars($output) . '</pre>';
                    echo '<br><strong>Mga Solusyon:</strong><br>';
                    echo '1. I-check kung TAMA ang pangalan ng printer (case-sensitive!)<br>';
                    echo '2. Siguraduhin na naka-ON ang printer<br>';
                    echo '3. I-check kung naka-SHARE ang printer sa Windows<br>';
                    echo '4. I-click yung "List Printers" button para makita ang tama na pangalan';
                    echo '</div>';
                }
            }
        }
        ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="list_printers">
            <button type="submit" class="btn">📋 Ipakita ang Lahat ng Printer</button>
        </form>
        
        <hr style="margin: 30px 0; border: none; border-top: 2px solid #eee;">
        
        <form method="POST">
            <input type="hidden" name="action" value="test_print">
            <label>Pangalan ng Printer:</label>
            <input type="text" name="printer_name" value="POS-58" placeholder="Halimbawa: POS-58, XP-58">
            <button type="submit" class="btn btn-secondary">🖨️ I-Test ang Printer</button>
        </form>
        
        <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-radius: 8px; border: 2px solid #ffc107;">
            <strong>💡 Paano Gamitin:</strong><br>
            1️⃣ I-click ang "Ipakita ang Lahat ng Printer"<br>
            2️⃣ Copy yung EXACT name ng printer mo<br>
            3️⃣ I-paste sa "Pangalan ng Printer" field<br>
            4️⃣ I-click ang "I-Test ang Printer"<br>
            5️⃣ Kung success, update mo na sa <code>Api/print-receipt-fixed-width.php</code> line 152
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 8px;">
            <strong>🔧 Common Issues:</strong><br>
            ❌ <strong>"The system cannot find the file"</strong> = Mali ang printer name<br>
            ❌ <strong>"Access is denied"</strong> = Hindi naka-share ang printer<br>
            ❌ <strong>"Printer offline"</strong> = Naka-off ang printer o walang connection
        </div>
    </div>
</body>
</html>

