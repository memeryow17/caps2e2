<?php
// Dashboard Return Data API
// This file provides real return data for the dashboard

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

// Use centralized database connection
require_once __DIR__ . '/conn.php';

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $action = $data['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_total_return':
            getTotalReturn($conn);
            break;
        case 'get_return_summary':
            getReturnSummary($conn, $data);
            break;
        case 'get_top_returned_products':
            getTopReturnedProducts($conn, $data);
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
 * Get total return amount for dashboard
 */
function getTotalReturn($conn) {
    try {
        // Get total return amount from last 30 days
        $sql = "
            SELECT 
                COALESCE(SUM(pr.total_refund), 0) as total_return_amount,
                COUNT(DISTINCT pr.return_id) as total_returns,
                COUNT(pri.id) as total_items_returned
            FROM tbl_pos_returns pr
            LEFT JOIN tbl_pos_return_items pri ON pr.return_id = pri.return_id
            WHERE DATE(pr.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND pr.status IN ('pending', 'approved', 'completed')
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get today's returns
        $sqlToday = "
            SELECT 
                COALESCE(SUM(pr.total_refund), 0) as today_return_amount,
                COUNT(DISTINCT pr.return_id) as today_returns
            FROM tbl_pos_returns pr
            WHERE DATE(pr.created_at) = CURDATE()
            AND pr.status IN ('pending', 'approved', 'completed')
        ";
        
        $stmtToday = $conn->prepare($sqlToday);
        $stmtToday->execute();
        $todayResult = $stmtToday->fetch(PDO::FETCH_ASSOC);
        
        // Get yesterday's returns for comparison
        $sqlYesterday = "
            SELECT 
                COALESCE(SUM(pr.total_refund), 0) as yesterday_return_amount
            FROM tbl_pos_returns pr
            WHERE DATE(pr.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            AND pr.status IN ('pending', 'approved', 'completed')
        ";
        
        $stmtYesterday = $conn->prepare($sqlYesterday);
        $stmtYesterday->execute();
        $yesterdayResult = $stmtYesterday->fetch(PDO::FETCH_ASSOC);
        
        // Calculate percentage change
        $yesterdayAmount = (float)$yesterdayResult['yesterday_return_amount'];
        $todayAmount = (float)$todayResult['today_return_amount'];
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
                'total_return' => number_format($result['total_return_amount'], 2),
                'total_returns' => (int)$result['total_returns'],
                'total_items_returned' => (int)$result['total_items_returned'],
                'today_return' => number_format($todayResult['today_return_amount'], 2),
                'today_returns' => (int)$todayResult['today_returns'],
                'percentage_change' => round($percentageChange, 1),
                'trend' => $trendText . round($percentageChange, 1) . '%',
                'trend_direction' => $trendDirection
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting return data: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get return summary for charts
 */
function getReturnSummary($conn, $data = []) {
    try {
        $days = $data['days'] ?? $_POST['days'] ?? 7; // Default to 7 days
        $days = max(1, min(30, (int)$days)); // Limit between 1-30 days
        
        $sql = "
            SELECT 
                DATE(pr.created_at) as return_date,
                COALESCE(SUM(pr.total_refund), 0) as daily_return_amount,
                COUNT(DISTINCT pr.return_id) as daily_return_count,
                COUNT(pri.id) as daily_items_returned
            FROM tbl_pos_returns pr
            LEFT JOIN tbl_pos_return_items pri ON pr.return_id = pri.return_id
            WHERE DATE(pr.created_at) >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            AND pr.status IN ('pending', 'approved', 'completed')
            GROUP BY DATE(pr.created_at)
            ORDER BY DATE(pr.created_at) DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for charts
        $chartData = [];
        foreach ($results as $row) {
            $chartData[] = [
                'day' => date('d', strtotime($row['return_date'])),
                'date' => $row['return_date'],
                'totalReturn' => (float)$row['daily_return_amount'],
                'returnCount' => (int)$row['daily_return_count'],
                'itemsReturned' => (int)$row['daily_items_returned']
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
            'message' => 'Error getting return summary: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get top returned products
 */
function getTopReturnedProducts($conn, $data = []) {
    try {
        $days = $data['days'] ?? $_POST['days'] ?? 30; // Default to 30 days
        $days = max(1, min(365, (int)$days)); // Limit between 1-365 days
        $limit = $data['limit'] ?? $_POST['limit'] ?? 10; // Default to top 10
        $limit = max(1, min(50, (int)$limit)); // Limit between 1-50
        
        $sql = "
            SELECT 
                p.product_name,
                p.product_id,
                COUNT(pri.id) as return_count,
                COALESCE(SUM(pri.quantity), 0) as total_quantity_returned,
                COALESCE(SUM(pri.total), 0) as total_return_amount,
                COALESCE(AVG(pri.total), 0) as avg_return_amount
            FROM tbl_pos_return_items pri
            JOIN tbl_pos_returns pr ON pri.return_id = pr.return_id
            JOIN tbl_product p ON pri.product_id = p.product_id
            WHERE DATE(pr.created_at) >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            AND pr.status IN ('pending', 'approved', 'completed')
            GROUP BY p.product_id, p.product_name
            ORDER BY total_return_amount DESC
            LIMIT :limit
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data
        $topReturnedProducts = [];
        foreach ($results as $row) {
            $topReturnedProducts[] = [
                'product_id' => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'return_count' => (int)$row['return_count'],
                'total_quantity_returned' => (int)$row['total_quantity_returned'],
                'total_return_amount' => (float)$row['total_return_amount'],
                'avg_return_amount' => (float)$row['avg_return_amount']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $topReturnedProducts,
            'period_days' => $days,
            'limit' => $limit
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting top returned products: ' . $e->getMessage()
        ]);
    }
}

?>
