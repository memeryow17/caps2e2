<?php
// Dashboard Sales Data API
// This file provides real sales data for the dashboard

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
        case 'get_total_sales':
            getTotalSales($conn);
            break;
        case 'get_sales_summary':
            getSalesSummary($conn, $data);
            break;
        case 'get_payment_methods':
            getPaymentMethods($conn, $data);
            break;
        case 'get_top_selling_products':
            getTopSellingProducts($conn, $data);
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
 * Get total sales amount for dashboard
 */
function getTotalSales($conn) {
    try {
        // Get total sales amount from last 30 days
        $sql = "
            SELECT 
                COALESCE(SUM(psh.total_amount), 0) as total_sales_amount,
                COUNT(DISTINCT pt.transaction_id) as total_transactions,
                COUNT(DISTINCT psh.sales_header_id) as total_sales_headers
            FROM tbl_pos_transaction pt
            JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            WHERE DATE(pt.date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get today's sales
        $sqlToday = "
            SELECT 
                COALESCE(SUM(psh.total_amount), 0) as today_sales_amount,
                COUNT(DISTINCT pt.transaction_id) as today_transactions
            FROM tbl_pos_transaction pt
            JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            WHERE DATE(pt.date) = CURDATE()
        ";
        
        $stmtToday = $conn->prepare($sqlToday);
        $stmtToday->execute();
        $todayResult = $stmtToday->fetch(PDO::FETCH_ASSOC);
        
        // Get yesterday's sales for comparison
        $sqlYesterday = "
            SELECT 
                COALESCE(SUM(psh.total_amount), 0) as yesterday_sales_amount
            FROM tbl_pos_transaction pt
            JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            WHERE DATE(pt.date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ";
        
        $stmtYesterday = $conn->prepare($sqlYesterday);
        $stmtYesterday->execute();
        $yesterdayResult = $stmtYesterday->fetch(PDO::FETCH_ASSOC);
        
        // Calculate percentage change
        $yesterdayAmount = (float)$yesterdayResult['yesterday_sales_amount'];
        $todayAmount = (float)$todayResult['today_sales_amount'];
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
                'total_sales' => number_format($result['total_sales_amount'], 2),
                'total_transactions' => (int)$result['total_transactions'],
                'total_sales_headers' => (int)$result['total_sales_headers'],
                'today_sales' => number_format($todayResult['today_sales_amount'], 2),
                'today_transactions' => (int)$todayResult['today_transactions'],
                'percentage_change' => round($percentageChange, 1),
                'trend' => $trendText . round($percentageChange, 1) . '%',
                'trend_direction' => $trendDirection
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting sales data: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get sales summary for charts
 */
function getSalesSummary($conn, $data = []) {
    try {
        $days = $data['days'] ?? $_POST['days'] ?? 7; // Default to 7 days
        $days = max(1, min(30, (int)$days)); // Limit between 1-30 days - sanitized as integer
        
        // Note: MySQL doesn't allow parameter binding in INTERVAL clause
        $sql = "
            SELECT 
                DATE(pt.date) as sales_date,
                COALESCE(SUM(psh.total_amount), 0) as daily_sales_amount,
                COUNT(DISTINCT pt.transaction_id) as daily_transaction_count,
                COALESCE(SUM(CASE WHEN pt.payment_type = 'cash' THEN psh.total_amount ELSE 0 END), 0) as daily_cash_sales,
                COALESCE(SUM(CASE WHEN pt.payment_type = 'card' THEN psh.total_amount ELSE 0 END), 0) as daily_card_sales,
                COALESCE(SUM(CASE WHEN pt.payment_type = 'Gcash' THEN psh.total_amount ELSE 0 END), 0) as daily_gcash_sales
            FROM tbl_pos_transaction pt
            JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            WHERE DATE(pt.date) >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
            GROUP BY DATE(pt.date)
            ORDER BY DATE(pt.date) DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for charts
        $chartData = [];
        foreach ($results as $row) {
            $chartData[] = [
                'day' => date('d', strtotime($row['sales_date'])),
                'date' => $row['sales_date'],
                'totalSales' => (float)$row['daily_sales_amount'],
                'transactionCount' => (int)$row['daily_transaction_count'],
                'cashSales' => (float)$row['daily_cash_sales'],
                'cardSales' => (float)$row['daily_card_sales'],
                'gcashSales' => (float)$row['daily_gcash_sales']
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
            'message' => 'Error getting sales summary: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get payment methods breakdown
 */
function getPaymentMethods($conn, $data = []) {
    try {
        $days = $data['days'] ?? $_POST['days'] ?? 30; // Default to 30 days
        $days = max(1, min(365, (int)$days)); // Limit between 1-365 days - sanitized as integer
        
        // Note: MySQL doesn't support parameter binding in INTERVAL, so we use the sanitized integer directly
        $sql = "
            SELECT 
                pt.payment_type,
                COUNT(DISTINCT pt.transaction_id) as transaction_count,
                COALESCE(SUM(psh.total_amount), 0) as total_amount
            FROM tbl_pos_transaction pt
            JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            WHERE DATE(pt.date) >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)
            GROUP BY pt.payment_type
            ORDER BY total_amount DESC
        ";
        
        $stmt = $conn->query($sql); // Use query() instead of prepare() since no parameters
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate total for percentage
        $total = array_sum(array_column($results, 'transaction_count'));
        $total = $total > 0 ? $total : 1; // Avoid division by zero
        
        // Format the data and assign colors
        $colors = [
            'cash' => '#3B82F6',
            'card' => '#10B981', 
            'gcash' => '#F59E0B'
        ];
        
        $paymentMethods = [];
        foreach ($results as $row) {
            $paymentType = ucfirst(strtolower($row['payment_type']));
            $percentage = round(($row['transaction_count'] / $total) * 100, 1);
            
            $paymentMethods[] = [
                'name' => $paymentType,
                'count' => (int)$row['transaction_count'],
                'amount' => (float)$row['total_amount'],
                'percentage' => $percentage,
                'color' => $colors[strtolower($row['payment_type'])] ?? '#6B7280'
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $paymentMethods,
            'period_days' => $days
        ]);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getPaymentMethods: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        echo json_encode([
            'success' => false,
            'message' => 'Error getting payment methods: ' . $e->getMessage(),
            'error_code' => $e->getCode(),
            'trace' => $e->getFile() . ':' . $e->getLine()
        ]);
    } catch (Exception $e) {
        error_log("General Error in getPaymentMethods: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error getting payment methods: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get top selling products by quantity
 */
function getTopSellingProducts($conn, $data = []) {
    try {
        $limit = $data['limit'] ?? $_POST['limit'] ?? 5; // Default to 5 products
        $limit = max(1, min(20, (int)$limit)); // Limit between 1-20 products
        
        $sql = "
            SELECT 
                p.product_name,
                SUM(psd.quantity) as total_quantity_sold,
                SUM(psd.quantity * psd.price) as total_sales_amount,
                p.status,
                p.stock_status
            FROM tbl_pos_sales_details psd
            JOIN tbl_pos_sales_header psh ON psd.sales_header_id = psh.sales_header_id
            JOIN tbl_product p ON psd.product_id = p.product_id
            WHERE p.status IS NULL OR p.status <> 'archived'
            GROUP BY p.product_id, p.product_name, p.status, p.stock_status
            ORDER BY total_quantity_sold DESC
            LIMIT :limit
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $topProducts = [];
        foreach ($results as $row) {
            $topProducts[] = [
                'name' => $row['product_name'],
                'quantity' => (int)$row['total_quantity_sold'],
                'sales' => number_format((float)$row['total_sales_amount'], 2),
                'status' => $row['stock_status'] === 'in stock' ? 'In Stock' : 'Out of Stock'
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $topProducts,
            'limit' => $limit
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error getting top selling products: ' . $e->getMessage()
        ]);
    }
}

?>
