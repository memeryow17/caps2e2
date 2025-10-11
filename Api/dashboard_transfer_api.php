<?php
// Dashboard Transfer Data API
// This file provides real transfer data for the dashboard

// CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once __DIR__ . '/conn.php';

// dashboard_transfer_api uses MySQLi syntax, so we need MySQLi connection
$conn = getMySQLiConnection();

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_total_transfer':
            getTotalTransfer($conn);
            break;
        case 'get_transfer_summary':
            getTransferSummary($conn);
            break;
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

/**
 * Get total transfer amount for dashboard
 */
function getTotalTransfer($conn) {
    try {
        // Get total transfer amount from approved transfers
        $sql = "
            SELECT 
                COALESCE(SUM(td.quantity * td.price), 0) as total_transfer_amount,
                COUNT(DISTINCT th.transfer_header_id) as total_transfers,
                COUNT(td.product_id) as total_items_transferred
            FROM tbl_transfer_header th
            JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
            WHERE th.status = 'approved'
            AND DATE(th.transfer_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get today's transfers
        $sqlToday = "
            SELECT 
                COALESCE(SUM(td.quantity * td.price), 0) as today_transfer_amount,
                COUNT(DISTINCT th.transfer_header_id) as today_transfers
            FROM tbl_transfer_header th
            JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
            WHERE th.status = 'approved'
            AND DATE(th.transfer_date) = CURDATE()
        ";
        
        $stmtToday = $conn->prepare($sqlToday);
        $stmtToday->execute();
        $todayResult = $stmtToday->fetch(PDO::FETCH_ASSOC);
        
        // Get yesterday's transfers for comparison
        $sqlYesterday = "
            SELECT 
                COALESCE(SUM(td.quantity * td.price), 0) as yesterday_transfer_amount
            FROM tbl_transfer_header th
            JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
            WHERE th.status = 'approved'
            AND DATE(th.transfer_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ";
        
        $stmtYesterday = $conn->prepare($sqlYesterday);
        $stmtYesterday->execute();
        $yesterdayResult = $stmtYesterday->fetch(PDO::FETCH_ASSOC);
        
        // Calculate percentage change
        $yesterdayAmount = (float)$yesterdayResult['yesterday_transfer_amount'];
        $todayAmount = (float)$todayResult['today_transfer_amount'];
        $percentageChange = 0;
        
        if ($yesterdayAmount > 0) {
            $percentageChange = (($todayAmount - $yesterdayAmount) / $yesterdayAmount) * 100;
        } elseif ($todayAmount > 0) {
            $percentageChange = 100; // 100% increase if no yesterday data but today has data
        }
        
        $trendDirection = $percentageChange >= 0 ? 'up' : 'down';
        $trendText = $percentageChange >= 0 ? '+' : '';
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_transfer' => number_format($result['total_transfer_amount'], 2),
                'total_transfers' => (int)$result['total_transfers'],
                'total_items_transferred' => (int)$result['total_items_transferred'],
                'today_transfer' => number_format($todayResult['today_transfer_amount'], 2),
                'today_transfers' => (int)$todayResult['today_transfers'],
                'percentage_change' => round($percentageChange, 1),
                'trend' => $trendText . round($percentageChange, 1) . '%',
                'trend_direction' => $trendDirection
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting transfer data: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get transfer summary for charts
 */
function getTransferSummary($conn) {
    try {
        $days = $_POST['days'] ?? 7; // Default to 7 days
        $days = max(1, min(30, (int)$days)); // Limit between 1-30 days
        
        $sql = "
            SELECT 
                DATE(th.transfer_date) as transfer_date,
                COALESCE(SUM(td.quantity * td.price), 0) as daily_transfer_amount,
                COUNT(DISTINCT th.transfer_header_id) as daily_transfer_count,
                COUNT(td.product_id) as daily_items_count
            FROM tbl_transfer_header th
            JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
            WHERE th.status = 'approved'
            AND DATE(th.transfer_date) >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(th.transfer_date)
            ORDER BY DATE(th.transfer_date) DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for charts
        $chartData = [];
        foreach ($results as $row) {
            $chartData[] = [
                'day' => date('d', strtotime($row['transfer_date'])),
                'date' => $row['transfer_date'],
                'totalTransfer' => (float)$row['daily_transfer_amount'],
                'transferCount' => (int)$row['daily_transfer_count'],
                'itemsCount' => (int)$row['daily_items_count']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $chartData,
            'period_days' => $days
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting transfer summary: ' . $e->getMessage()
        ]);
    }
}

?>
