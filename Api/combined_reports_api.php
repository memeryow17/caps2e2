<?php
/**
 * Combined Reports API - Handles merging multiple reports into a single PDF
 * Supports date filtering and quick-select options
 */

// Start output buffering to prevent unwanted output
ob_start();

// Set content type to JSON for error responses
header('Content-Type: application/json');

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Use centralized database connection
require_once __DIR__ . '/conn.php';

// Clear any output that might have been generated
ob_clean();

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit();
}

$action = $data['action'] ?? '';

try {
    switch ($action) {
        case 'combine_reports':
            handleCombineReports($conn, $data);
            break;
            
        case 'get_reports_data':
            handleGetReportsData($conn, $data);
            break;
            
        case 'get_available_dates':
            handleGetAvailableDates($conn, $data);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Handle combining reports based on date range
 */
function handleCombineReports($conn, $data) {
    $startDate = $data['start_date'] ?? null;
    $endDate = $data['end_date'] ?? null;
    $quickSelect = $data['quick_select'] ?? null;
    $reportTypes = $data['report_types'] ?? ['all']; // Array of report types to include
    
    // Handle quick select options
    if ($quickSelect) {
        $dateRange = getDateRange($quickSelect);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];
    }
    
    // Validate dates
    if (!$startDate || !$endDate) {
        echo json_encode(['success' => false, 'message' => 'Start date and end date are required']);
        return;
    }
    
    // Validate date format
    $dateFormat = 'Y-m-d';
    $dStart = DateTime::createFromFormat($dateFormat, $startDate);
    $dEnd = DateTime::createFromFormat($dateFormat, $endDate);
    
    if (!$dStart || !$dEnd) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
        return;
    }
    
    // Ensure start date is before or equal to end date
    if ($dStart > $dEnd) {
        echo json_encode(['success' => false, 'message' => 'Start date must be before or equal to end date']);
        return;
    }
    
    // Get reports within the date range
    $reports = getReportsInDateRange($conn, $startDate, $endDate, $reportTypes);
    
    if (empty($reports)) {
        echo json_encode([
            'success' => false, 
            'message' => 'No reports found for the selected date range (' . $startDate . ' to ' . $endDate . ')',
            'date_range' => $startDate . ' to ' . $endDate
        ]);
        return;
    }
    
        // Generate combined PDF and force download
        try {
            $pdfContent = generatePdfContent($reports, $startDate, $endDate);
            
            // Set headers for PDF download
            ob_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="combined_reports_' . $startDate . '_to_' . $endDate . '.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
            
            echo $pdfContent;
            exit();
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error generating combined PDF: ' . $e->getMessage()
            ]);
        }
}

/**
 * Handle getting reports data for frontend PDF generation
 */
function handleGetReportsData($conn, $data) {
    $startDate = $data['start_date'] ?? null;
    $endDate = $data['end_date'] ?? null;
    $reportTypes = $data['report_types'] ?? ['all'];
    
    // Validate dates
    if (!$startDate || !$endDate) {
        echo json_encode(['success' => false, 'message' => 'Start date and end date are required']);
        return;
    }
    
    // Validate date format
    $dateFormat = 'Y-m-d';
    $dStart = DateTime::createFromFormat($dateFormat, $startDate);
    $dEnd = DateTime::createFromFormat($dateFormat, $endDate);
    
    if (!$dStart || !$dEnd) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
        return;
    }
    
    // Ensure start date is before or equal to end date
    if ($dStart > $dEnd) {
        echo json_encode(['success' => false, 'message' => 'Start date must be before or equal to end date']);
        return;
    }
    
    // Get reports within the date range
    $reports = getReportsInDateRange($conn, $startDate, $endDate, $reportTypes);
    
    if (empty($reports)) {
        echo json_encode([
            'success' => false, 
            'message' => 'No reports found for the selected date range (' . $startDate . ' to ' . $endDate . ')',
            'date_range' => $startDate . ' to ' . $endDate
        ]);
        return;
    }
    
    // Return reports data for frontend PDF generation
    echo json_encode([
        'success' => true,
        'reports' => $reports,
        'date_range' => $startDate . ' to ' . $endDate,
        'total_reports' => count($reports)
    ]);
}

/**
 * Handle getting available dates for reports
 */
function handleGetAvailableDates($conn, $data) {
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT DATE(movement_date) as report_date, COUNT(*) as report_count
            FROM tbl_stock_movements 
            WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(movement_date)
            ORDER BY report_date DESC
        ");
        $stmt->execute();
        $dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'available_dates' => $dates,
            'total_days' => count($dates)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching available dates: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get date range based on quick select option
 */
function getDateRange($option) {
    $today = new DateTime();
    
    switch ($option) {
        case 'today':
            return [
                'start' => $today->format('Y-m-d'),
                'end' => $today->format('Y-m-d')
            ];
            
        case 'yesterday':
            $yesterday = clone $today;
            $yesterday->modify('-1 day');
            return [
                'start' => $yesterday->format('Y-m-d'),
                'end' => $yesterday->format('Y-m-d')
            ];
            
        case 'this_week':
            $startOfWeek = clone $today;
            $startOfWeek->modify('Monday this week');
            $endOfWeek = clone $today;
            $endOfWeek->modify('Sunday this week');
            return [
                'start' => $startOfWeek->format('Y-m-d'),
                'end' => $endOfWeek->format('Y-m-d')
            ];
            
        case 'last_week':
            $lastWeekStart = clone $today;
            $lastWeekStart->modify('Monday last week');
            $lastWeekEnd = clone $today;
            $lastWeekEnd->modify('Sunday last week');
            return [
                'start' => $lastWeekStart->format('Y-m-d'),
                'end' => $lastWeekEnd->format('Y-m-d')
            ];
            
        case 'this_month':
            $startOfMonth = clone $today;
            $startOfMonth->modify('first day of this month');
            $endOfMonth = clone $today;
            $endOfMonth->modify('last day of this month');
            return [
                'start' => $startOfMonth->format('Y-m-d'),
                'end' => $endOfMonth->format('Y-m-d')
            ];
            
        case 'last_month':
            $lastMonthStart = clone $today;
            $lastMonthStart->modify('first day of last month');
            $lastMonthEnd = clone $today;
            $lastMonthEnd->modify('last day of last month');
            return [
                'start' => $lastMonthStart->format('Y-m-d'),
                'end' => $lastMonthEnd->format('Y-m-d')
            ];
            
        default:
            return [
                'start' => null,
                'end' => null
            ];
    }
}

/**
 * Get reports within the specified date range
 */
function getReportsInDateRange($conn, $startDate, $endDate, $reportTypes) {
    try {
        $whereClause = "WHERE DATE(sm.movement_date) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        // Filter by report types if not 'all'
        if (!in_array('all', $reportTypes)) {
            $typeConditions = [];
            foreach ($reportTypes as $type) {
                switch ($type) {
                    case 'stock_in':
                        $typeConditions[] = "sm.movement_type = 'IN'";
                        break;
                    case 'stock_out':
                        $typeConditions[] = "sm.movement_type = 'OUT'";
                        break;
                    case 'stock_adjustment':
                        $typeConditions[] = "sm.movement_type = 'ADJUSTMENT'";
                        break;
                    case 'transfer':
                        $typeConditions[] = "sm.movement_type = 'TRANSFER'";
                        break;
                }
            }
            
            if (!empty($typeConditions)) {
                $whereClause .= " AND (" . implode(' OR ', $typeConditions) . ")";
            }
        }
        
        $stmt = $conn->prepare("
            SELECT 
                sm.movement_id,
                sm.movement_type,
                sm.movement_date,
                sm.quantity,
                sm.reference_no,
                sm.notes,
                sm.created_by,
                p.product_name,
                p.barcode,
                c.category_name as category,
                p.srp,
                COALESCE(l.location_name, 'Warehouse') as location_name,
                COALESCE(s.supplier_name, 'Unknown') as supplier_name,
                COALESCE(b.brand, 'Generic') as brand,
                p.expiration
            FROM tbl_stock_movements sm
            JOIN tbl_product p ON sm.product_id = p.product_id
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
            LEFT JOIN tbl_location l ON p.location_id = l.location_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            {$whereClause}
            ORDER BY sm.movement_date DESC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching reports: ' . $e->getMessage());
    }
}

/**
 * Generate PDF content from reports data
 */
function generatePdfContent($reports, $startDate, $endDate) {
    // Create a simple PDF using basic PDF structure
    $pdfContent = createSimplePdf($reports, $startDate, $endDate);
    
    return $pdfContent;
}

/**
 * Create simple PDF content using basic formatting
 */
function createSimplePdf($reports, $startDate, $endDate) {
    // Create a simple PDF structure
    $pdf = "%PDF-1.4\n";
    $pdf .= "1 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Catalog\n";
    $pdf .= "/Pages 2 0 R\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n\n";
    
    $pdf .= "2 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Pages\n";
    $pdf .= "/Kids [3 0 R]\n";
    $pdf .= "/Count 1\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n\n";
    
    // Create page content
    $pageContent = "BT\n";
    $pageContent .= "/F1 16 Tf\n";
    $pageContent .= "100 750 Td\n";
    $pageContent .= "(ENGUIO PHARMACY SYSTEM) Tj\n";
    $pageContent .= "0 -30 Td\n";
    $pageContent .= "/F1 14 Tf\n";
    $pageContent .= "(Combined Reports) Tj\n";
    $pageContent .= "0 -20 Td\n";
    $pageContent .= "/F1 12 Tf\n";
    $pageContent .= "(Date Range: " . $startDate . " to " . $endDate . ") Tj\n";
    $pageContent .= "0 -20 Td\n";
    $pageContent .= "(Generated on: " . date('Y-m-d H:i:s') . ") Tj\n";
    $pageContent .= "0 -40 Td\n";
    
    // Add report data
    $y = 650;
    foreach ($reports as $report) {
        if ($y < 100) {
            $pageContent .= "0 -" . (750 - $y) . " Td\n";
            $y = 750;
        }
        
        $date = date('Y-m-d', strtotime($report['movement_date']));
        $time = date('H:i:s', strtotime($report['movement_date']));
        
        $pageContent .= "/F1 10 Tf\n";
        $pageContent .= "(" . $date . " " . $time . " - " . $report['product_name'] . " - " . $report['movement_type'] . " - Qty: " . $report['quantity'] . ") Tj\n";
        $pageContent .= "0 -15 Td\n";
        $y -= 15;
    }
    
    $pageContent .= "ET\n";
    
    $pdf .= "3 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Page\n";
    $pdf .= "/Parent 2 0 R\n";
    $pdf .= "/MediaBox [0 0 612 792]\n";
    $pdf .= "/Contents 4 0 R\n";
    $pdf .= "/Resources <<\n";
    $pdf .= "/Font <<\n";
    $pdf .= "/F1 <<\n";
    $pdf .= "/Type /Font\n";
    $pdf .= "/Subtype /Type1\n";
    $pdf .= "/BaseFont /Helvetica\n";
    $pdf .= ">>\n";
    $pdf .= ">>\n";
    $pdf .= ">>\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n\n";
    
    $pdf .= "4 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Length " . strlen($pageContent) . "\n";
    $pdf .= ">>\n";
    $pdf .= "stream\n";
    $pdf .= $pageContent;
    $pdf .= "endstream\n";
    $pdf .= "endobj\n\n";
    
    $pdf .= "xref\n";
    $pdf .= "0 5\n";
    $pdf .= "0000000000 65535 f \n";
    $pdf .= "0000000009 00000 n \n";
    $pdf .= "0000000058 00000 n \n";
    $pdf .= "0000000115 00000 n \n";
    $pdf .= "0000000274 00000 n \n";
    $pdf .= "trailer\n";
    $pdf .= "<<\n";
    $pdf .= "/Size 5\n";
    $pdf .= "/Root 1 0 R\n";
    $pdf .= ">>\n";
    $pdf .= "startxref\n";
    $pdf .= "0\n";
    $pdf .= "%%EOF\n";
    
    return $pdf;
}

/**
 * Generate HTML content for the PDF
 */
function generatePdfHtml($reports, $startDate, $endDate) {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Combined Reports - ' . $startDate . ' to ' . $endDate . '</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
        }
        
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.4;
            color: #000;
        }
        
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 3px solid #000; 
            padding-bottom: 20px; 
        }
        
        .header h1 {
            font-size: 28px;
            margin: 0;
            color: #000;
        }
        
        .header h2 {
            font-size: 20px;
            margin: 10px 0 0 0;
            color: #000;
        }
        
        .header p {
            margin: 5px 0;
            font-size: 14px;
            color: #000;
        }
        
        .report-info { 
            margin-bottom: 20px; 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 5px; 
            border: 1px solid #ddd;
        }
        
        .report-info h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #000;
        }
        
        .report-info p {
            margin: 5px 0;
            font-size: 12px;
            color: #000;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px; 
            font-size: 10px;
        }
        
        th, td { 
            border: 1px solid #000; 
            padding: 6px; 
            text-align: left; 
            vertical-align: top;
        }
        
        th { 
            background-color: #f0f0f0; 
            font-weight: bold; 
            font-size: 10px;
            color: #000;
        }
        
        td {
            font-size: 9px;
            color: #000;
        }
        
        .summary { 
            background: #e8f4fd; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            border: 1px solid #b3d9ff;
        }
        
        .summary h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #000;
        }
        
        .summary p {
            margin: 5px 0;
            font-size: 12px;
            color: #000;
        }
        
        .summary ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        
        .summary li {
            font-size: 12px;
            color: #000;
        }
        
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #0056b3;
        }
        
        .page-break { 
            page-break-before: always; 
        }
        
        /* Ensure proper spacing for PDF */
        @page {
            margin: 1cm;
            size: A4;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">🖨️ Print PDF</button>
    
    <div class="header">
        <h1>ENGUIO PHARMACY SYSTEM</h1>
        <h2>Combined Reports</h2>
        <p><strong>Date Range:</strong> ' . $startDate . ' to ' . $endDate . '</p>
        <p><strong>Generated on:</strong> ' . date('Y-m-d H:i:s') . '</p>
        <p><strong>Generated by:</strong> System Administrator</p>
    </div>
    
    <div class="summary">
        <h3>Report Summary</h3>
        <p><strong>Total Reports:</strong> ' . count($reports) . '</p>
        <p><strong>Date Range:</strong> ' . $startDate . ' to ' . $endDate . '</p>
        <p><strong>Generated By:</strong> System Administrator</p>
    </div>';
    
    $totalValue = 0;
    $movementTypes = [];
    
    foreach ($reports as $report) {
        $totalValue += ($report['quantity'] * $report['srp']);
        
        if (!isset($movementTypes[$report['movement_type']])) {
            $movementTypes[$report['movement_type']] = 0;
        }
        $movementTypes[$report['movement_type']]++;
    }
    
    $html .= '<table>
        <thead>
            <tr>
                <th style="width: 15%;">Date</th>
                <th style="width: 8%;">Time</th>
                <th style="width: 20%;">Product Name</th>
                <th style="width: 12%;">Barcode</th>
                <th style="width: 10%;">Category</th>
                <th style="width: 8%;">Type</th>
                <th style="width: 6%;">Qty</th>
                <th style="width: 8%;">Unit Price</th>
                <th style="width: 8%;">Total Value</th>
                <th style="width: 10%;">Reference</th>
                <th style="width: 8%;">Location</th>
                <th style="width: 10%;">Supplier</th>
                <th style="width: 8%;">Brand</th>
                <th style="width: 15%;">Notes</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($reports as $report) {
        $date = date('Y-m-d', strtotime($report['movement_date']));
        $time = date('H:i:s', strtotime($report['movement_date']));
        $totalValueItem = $report['quantity'] * $report['srp'];
        
        $html .= '<tr>
            <td>' . htmlspecialchars($date) . '</td>
            <td>' . htmlspecialchars($time) . '</td>
            <td><strong>' . htmlspecialchars($report['product_name']) . '</strong></td>
            <td>' . htmlspecialchars($report['barcode']) . '</td>
            <td>' . htmlspecialchars($report['category']) . '</td>
            <td>' . htmlspecialchars($report['movement_type']) . '</td>
            <td style="text-align: right;">' . number_format($report['quantity']) . '</td>
            <td style="text-align: right;">₱' . number_format($report['srp'], 2) . '</td>
            <td style="text-align: right;">₱' . number_format($totalValueItem, 2) . '</td>
            <td>' . htmlspecialchars($report['reference_no']) . '</td>
            <td>' . htmlspecialchars($report['location_name']) . '</td>
            <td>' . htmlspecialchars($report['supplier_name']) . '</td>
            <td>' . htmlspecialchars($report['brand']) . '</td>
            <td>' . htmlspecialchars($report['notes']) . '</td>
        </tr>';
    }
    
    $html .= '</tbody>
    </table>
    
    <div class="summary">
        <h3>Summary Statistics</h3>
        <p><strong>Total Value:</strong> ₱' . number_format($totalValue, 2) . '</p>
        <p><strong>Total Records:</strong> ' . count($reports) . '</p>
        <p><strong>Movement Types:</strong></p>
        <ul>';
    
    foreach ($movementTypes as $type => $count) {
        $html .= '<li><strong>' . $type . ':</strong> ' . $count . ' records</li>';
    }
    
    $html .= '</ul>
    </div>
    
    <div class="footer">
        <p><strong>This report was generated by Enguio Pharmacy System</strong></p>
        <p>For questions or support, please contact your system administrator</p>
        <p>Report ID: ' . time() . ' | Generated on: ' . date('Y-m-d H:i:s') . '</p>
    </div>
    
    <script>
        // Auto-print when page loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        };
        
        // Handle print dialog
        window.onafterprint = function() {
            window.close();
        };
    </script>
</body>
</html>';
    
    return $html;
}

?>
