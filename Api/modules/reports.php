<?php
/**
 * Reports Module
 * Handles all report-related functionality for the admin system
 */

class ReportsModule {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get report data based on report type
     */
    public function getReportData($reportType, $startDate, $endDate, $checkForUpdates = false, $userData = null) {
        $reportData = [];
        $hasNewData = false;
        
        switch ($reportType) {
            case 'all':
                // Return all available reports for the Reports.js component
                return $this->getAllReports($startDate, $endDate);
                
            case 'stock_in':
                $reportData = $this->getStockInReport($startDate, $endDate);
                break;
                
            case 'stock_out':
                $reportData = $this->getStockOutReport($startDate, $endDate);
                break;
                
            case 'sales':
                $reportData = $this->getSalesReport($startDate, $endDate, $checkForUpdates, $userData);
                $hasNewData = $this->checkForNewSalesData($startDate, $endDate);
                break;
                
            case 'inventory_balance':
                $reportData = $this->getInventoryBalanceReport();
                break;
                
            case 'stock_adjustment':
                $reportData = $this->getStockAdjustmentReport($startDate, $endDate);
                break;
                
            case 'supplier':
                $reportData = $this->getSupplierReport();
                break;
                
            case 'cashier_performance':
                $reportData = $this->getCashierPerformanceReport($startDate, $endDate, $checkForUpdates);
                $hasNewData = $this->checkForNewCashierData($startDate, $endDate);
                break;
                
            case 'login_logs':
                $reportData = $this->getLoginLogsReport($startDate, $endDate);
                break;
                
            case 'activity_logs':
                $reportData = $this->getActivityLogsReport($startDate, $endDate);
                break;
                
            default:
                $reportData = [];
        }
        
        return [
            'success' => true,
            'data' => $reportData,
            'has_new_data' => $hasNewData,
            'report_type' => $reportType,
            'date_range' => "$startDate to $endDate",
            'timestamp' => date('Y-m-d H:i:s'),
            'cache_bust' => time()
        ];
    }
    
    /**
     * Get all reports for the Reports.js component
     */
    private function getAllReports($startDate, $endDate) {
        try {
            // Get stock movements as reports
            $stmt = $this->conn->prepare("
                SELECT 
                    sm.movement_id,
                    CONCAT('Stock ', 
                        CASE sm.movement_type 
                            WHEN 'IN' THEN 'In Report'
                            WHEN 'OUT' THEN 'Out Report'
                            WHEN 'ADJUSTMENT' THEN 'Adjustment Report'
                            WHEN 'TRANSFER' THEN 'Transfer Report'
                            ELSE CONCAT(sm.movement_type, ' Report')
                        END
                    ) as title,
                    CASE sm.movement_type 
                        WHEN 'IN' THEN 'Stock In Report'
                        WHEN 'OUT' THEN 'Stock Out Report'
                        WHEN 'ADJUSTMENT' THEN 'Stock Adjustment Report'
                        WHEN 'TRANSFER' THEN 'Transfer Report'
                        ELSE CONCAT(sm.movement_type, ' Report')
                    END as type,
                    CONCAT('Report for ', p.product_name, ' - ', sm.movement_type, ' movement') as description,
                    COALESCE(sm.created_by, 'System') as generatedBy,
                    DATE(sm.movement_date) as date,
                    TIME(sm.movement_date) as time,
                    'Completed' as status,
                    'PDF' as format,
                    '2.5 MB' as fileSize
                FROM tbl_stock_movements sm
                JOIN tbl_product p ON sm.product_id = p.product_id
                WHERE DATE(sm.movement_date) BETWEEN ? AND ?
                ORDER BY sm.movement_date DESC
                LIMIT 50
            ");
            $stmt->execute([$startDate, $endDate]);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get analytics data
            $analytics = $this->getAnalyticsData();
            
            // Get top categories
            $topCategories = $this->getTopCategories();
            
            return [
                'success' => true,
                'reports' => $reports,
                'analytics' => $analytics,
                'topCategories' => $topCategories,
                'has_new_data' => false,
                'report_type' => 'all',
                'date_range' => "$startDate to $endDate"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching reports: ' . $e->getMessage(),
                'reports' => [],
                'analytics' => [
                    'totalProducts' => 0,
                    'lowStockItems' => 0,
                    'outOfStockItems' => 0,
                    'totalValue' => 0
                ],
                'topCategories' => []
            ];
        }
    }
    
    /**
     * Get analytics data for the dashboard
     */
    private function getAnalyticsData() {
        try {
            // Total products
            $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_product WHERE status IS NULL OR status <> 'archived'");
            $stmt->execute();
            $totalProducts = $stmt->fetch()['total'];
            
            // Low stock items (quantity <= 10)
            $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_product WHERE quantity <= 10 AND (status IS NULL OR status <> 'archived')");
            $stmt->execute();
            $lowStockItems = $stmt->fetch()['total'];
            
            // Out of stock items
            $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_product WHERE quantity = 0 AND (status IS NULL OR status <> 'archived')");
            $stmt->execute();
            $outOfStockItems = $stmt->fetch()['total'];
            
            // Total inventory value
            $stmt = $this->conn->prepare("SELECT COALESCE(SUM(quantity * srp), 0) as total FROM tbl_product WHERE status IS NULL OR status <> 'archived'");
            $stmt->execute();
            $totalValue = $stmt->fetch()['total'];
            
            return [
                'totalProducts' => (int)$totalProducts,
                'lowStockItems' => (int)$lowStockItems,
                'outOfStockItems' => (int)$outOfStockItems,
                'totalValue' => (float)$totalValue
            ];
            
        } catch (Exception $e) {
            return [
                'totalProducts' => 0,
                'lowStockItems' => 0,
                'outOfStockItems' => 0,
                'totalValue' => 0
            ];
        }
    }
    
    /**
     * Get top categories for the dashboard
     */
    private function getTopCategories() {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    c.category_name as category_name,
                    COUNT(*) as product_count,
                    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_product WHERE status IS NULL OR status <> 'archived')), 1) as percentage
                FROM tbl_product p
                LEFT JOIN tbl_category c ON p.category_id = c.category_id
                WHERE p.status IS NULL OR p.status <> 'archived'
                AND c.category_name IS NOT NULL AND c.category_name <> ''
                GROUP BY c.category_name
                ORDER BY product_count DESC
                LIMIT 6
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get Stock In Report Data
     */
    private function getStockInReport($startDate, $endDate) {
        $stmt = $this->conn->prepare("
            SELECT 
                DATE(sm.movement_date) as date,
                TIME(sm.movement_date) as time,
                p.product_name,
                p.barcode,
                sm.quantity,
                sm.srp as unit_price,
                (sm.quantity * sm.srp) as total_value,
                s.supplier_name,
                sm.reference_no,
                sm.notes,
                sm.created_by as received_by
            FROM tbl_stock_movements sm
            JOIN tbl_product p ON sm.product_id = p.product_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            WHERE sm.movement_type = 'IN'
            AND DATE(sm.movement_date) BETWEEN ? AND ?
            ORDER BY sm.movement_date DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Stock Out Report Data
     */
    private function getStockOutReport($startDate, $endDate) {
        $stmt = $this->conn->prepare("
            SELECT 
                DATE(sm.movement_date) as date,
                TIME(sm.movement_date) as time,
                p.product_name,
                p.barcode,
                sm.quantity,
                sm.srp as unit_price,
                (sm.quantity * sm.srp) as total_value,
                sm.reference_no,
                sm.notes as customer_info,
                sm.movement_id,
                -- Determine the actual cashier/employee who performed the action
                CASE 
                    WHEN sm.created_by = 'POS System' THEN 
                        CASE 
                            WHEN pt.emp_id IS NOT NULL THEN CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, ''))
                            ELSE 'POS System'
                        END
                    WHEN sm.created_by = 'Pharmacy Cashier' THEN 'Pharmacy Cashier'
                    WHEN sm.created_by = 'Inventory Manager' THEN 'Inventory Manager'
                    WHEN sm.created_by = 'Admin' THEN 'Admin'
                    ELSE COALESCE(sm.created_by, 'System')
                END as adjusted_by,
                -- Additional context for better identification
                CASE 
                    WHEN sm.created_by = 'POS System' AND pt.emp_id IS NOT NULL THEN 
                        CONCAT('Cashier ', COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, ''), ' (', COALESCE(e.username, 'Unknown'), ')')
                    WHEN sm.created_by = 'POS System' THEN 'POS System'
                    WHEN sm.created_by = 'Pharmacy Cashier' THEN 'Pharmacy Cashier'
                    WHEN sm.created_by = 'Inventory Manager' THEN 'Inventory Manager'
                    WHEN sm.created_by = 'Admin' THEN 'Admin'
                    ELSE COALESCE(sm.created_by, 'System')
                END as adjusted_by_detailed,
                -- Movement type for display
                CASE sm.movement_type
                    WHEN 'OUT' THEN 'Stock Out'
                    WHEN 'IN' THEN 'Stock In'
                    WHEN 'ADJUSTMENT' THEN 'Stock Adjustment'
                    ELSE sm.movement_type
                END as adjustment_type,
                -- Reason for the movement
                CASE 
                    WHEN sm.notes LIKE '%POS Sale%' THEN 'POS Sale'
                    WHEN sm.notes LIKE '%Manual%' THEN 'Manual Adjustment'
                    WHEN sm.notes LIKE '%Transfer%' THEN 'Stock Transfer'
                    ELSE COALESCE(sm.notes, 'System Adjustment')
                END as reason
            FROM tbl_stock_movements sm
            JOIN tbl_product p ON sm.product_id = p.product_id
            -- Try to get the actual employee who made the POS transaction
            LEFT JOIN tbl_pos_sales_header psh ON sm.reference_no = psh.reference_number
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            LEFT JOIN tbl_employee e ON pt.emp_id = e.emp_id
            WHERE sm.movement_type = 'OUT'
            AND DATE(sm.movement_date) BETWEEN ? AND ?
            ORDER BY sm.movement_date DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Sales Report Data with Role-based Filtering
     */
    private function getSalesReport($startDate, $endDate, $checkForUpdates = false, $userData = null) {
        // Check for new data if requested
        if ($checkForUpdates) {
            $this->checkForNewSalesData($startDate, $endDate);
        }
        
        // Determine filtering based on user role
        $whereClause = "WHERE pt.date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($userData && isset($userData['role'])) {
            $userRole = strtolower($userData['role']);
            $userId = $userData['user_id'] ?? null;
            
            // Apply role-based filtering
            if ($userRole === 'cashier' && $userId) {
                // Cashiers can only see their own sales
                $whereClause .= " AND pt.emp_id = ?";
                $params[] = $userId;
            } elseif ($userRole === 'supervisor') {
                // Supervisors can see sales from their assigned location/terminal
                // For now, show all sales (can be enhanced with location-based filtering)
                $whereClause .= "";
            } elseif ($userRole === 'manager') {
                // Managers can see all sales
                $whereClause .= "";
            } elseif ($userRole === 'admin') {
                // Admins can see all sales
                $whereClause .= "";
            } else {
                // Default: show only own sales for unknown roles
                if ($userId) {
                    $whereClause .= " AND pt.emp_id = ?";
                    $params[] = $userId;
                }
            }
        }
        
        // First, let's check if there's any data at all
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_pos_sales_header");
        $countStmt->execute();
        $result = $countStmt->fetch();
        $totalSales = $result['total'];
        
        if ($totalSales == 0) {
            return [];
        }
        
        $stmt = $this->conn->prepare("
            SELECT 
                pt.date,
                pt.time,
                psh.transaction_id,
                psh.total_amount,
                psh.reference_number as reference_no,
                COUNT(psd.product_id) as items_sold,
                GROUP_CONCAT(CONCAT(COALESCE(p.product_name, CONCAT('Product ID: ', psd.product_id)), ' (', psd.quantity, 'x ₱', psd.price, ')') SEPARATOR ', ') as products,
                COALESCE(t.terminal_name, CONCAT('Terminal ', psh.terminal_id)) as terminal,
                pt.payment_type,
                CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, '')) as cashier_name,
                COALESCE(e.username, 'System') as cashier_username,
                e.emp_id,
                psh.sales_header_id,
                CONCAT(pt.date, ' ', pt.time) as transaction_timestamp
            FROM tbl_pos_sales_header psh
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
            LEFT JOIN tbl_product p ON psd.product_id = p.product_id
            LEFT JOIN tbl_employee e ON pt.emp_id = e.emp_id
            LEFT JOIN tbl_pos_terminal t ON psh.terminal_id = t.terminal_id
            " . $whereClause . "
            GROUP BY psh.sales_header_id, pt.date, pt.time, psh.transaction_id, psh.total_amount, psh.reference_number, psh.terminal_id, pt.payment_type, e.emp_id, t.terminal_name
            ORDER BY pt.date DESC, pt.time DESC
        ");
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no data for the specific date range, try to get recent data with same filtering
        if (empty($reportData)) {
            $recentWhereClause = "WHERE pt.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            $recentParams = [];
            
            if ($userData && isset($userData['role'])) {
                $userRole = strtolower($userData['role']);
                $userId = $userData['user_id'] ?? null;
                
                if ($userRole === 'cashier' && $userId) {
                    $recentWhereClause .= " AND pt.emp_id = ?";
                    $recentParams[] = $userId;
                } elseif (!in_array($userRole, ['admin', 'manager', 'supervisor'])) {
                    if ($userId) {
                        $recentWhereClause .= " AND pt.emp_id = ?";
                        $recentParams[] = $userId;
                    }
                }
            }
            
            $recentStmt = $this->conn->prepare("
                SELECT 
                    pt.date,
                    pt.time,
                    psh.transaction_id,
                    psh.total_amount,
                    psh.reference_number as reference_no,
                    COUNT(psd.product_id) as items_sold,
                    GROUP_CONCAT(CONCAT(COALESCE(p.product_name, CONCAT('Product ID: ', psd.product_id)), ' (', psd.quantity, 'x ₱', psd.price, ')') SEPARATOR ', ') as products,
                    COALESCE(t.terminal_name, CONCAT('Terminal ', psh.terminal_id)) as terminal,
                    pt.payment_type,
                    CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, '')) as cashier_name,
                    COALESCE(e.username, 'System') as cashier_username,
                    e.emp_id,
                    psh.sales_header_id,
                    CONCAT(pt.date, ' ', pt.time) as transaction_timestamp
                FROM tbl_pos_sales_header psh
                LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
                LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
                LEFT JOIN tbl_product p ON psd.product_id = p.product_id
                LEFT JOIN tbl_employee e ON pt.emp_id = e.emp_id
                LEFT JOIN tbl_pos_terminal t ON psh.terminal_id = t.terminal_id
                " . $recentWhereClause . "
                GROUP BY psh.sales_header_id, pt.date, pt.time, psh.transaction_id, psh.total_amount, psh.reference_number, psh.terminal_id, pt.payment_type, e.emp_id, t.terminal_name
                ORDER BY pt.date DESC, pt.time DESC
                LIMIT 10
            ");
            $recentStmt->execute($recentParams);
            $reportData = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $reportData;
    }
    
    /**
     * Get Inventory Balance Report Data
     */
    private function getInventoryBalanceReport() {
        $stmt = $this->conn->prepare("
            SELECT 
                p.product_name,
                p.barcode,
                c.category_name as category,
                p.quantity as current_stock,
                p.srp as unit_price,
                (p.quantity * p.srp) as total_value,
                COALESCE(l.location_name, 'Warehouse') as location,
                COALESCE(s.supplier_name, 'Unknown Supplier') as supplier,
                COALESCE(b.brand, 'Generic') as brand,
                p.expiration,
                CASE 
                    WHEN p.quantity <= 10 THEN 'Low Stock'
                    WHEN p.quantity = 0 THEN 'Out of Stock'
                    ELSE 'In Stock'
                END as status
            FROM tbl_product p
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
            LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
            LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
            WHERE (p.status IS NULL OR p.status <> 'archived')
            ORDER BY p.product_name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Stock Adjustment Report Data
     */
    private function getStockAdjustmentReport($startDate, $endDate) {
        $stmt = $this->conn->prepare("
            SELECT 
                DATE(sm.movement_date) as date,
                TIME(sm.movement_date) as time,
                p.product_name,
                p.barcode,
                sm.quantity,
                sm.movement_type,
                sm.notes as reason,
                sm.created_by as adjusted_by,
                sm.reference_no
            FROM tbl_stock_movements sm
            JOIN tbl_product p ON sm.product_id = p.product_id
            WHERE sm.movement_type IN ('ADJUSTMENT', 'CORRECTION')
            AND DATE(sm.movement_date) BETWEEN ? AND ?
            ORDER BY sm.movement_date DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Supplier Report Data
     */
    private function getSupplierReport() {
        $stmt = $this->conn->prepare("
            SELECT 
                s.supplier_name,
                s.contact_person,
                COALESCE(s.supplier_email, s.primary_email) as email,
                COUNT(p.product_id) as products_supplied,
                COALESCE(SUM(p.quantity), 0) as total_stock,
                COALESCE(SUM(p.quantity * p.srp), 0) as total_value,
                COUNT(DISTINCT sm.movement_id) as deliveries_count
            FROM tbl_supplier s
            LEFT JOIN tbl_product p ON s.supplier_id = p.supplier_id
            LEFT JOIN tbl_stock_movements sm ON p.product_id = sm.product_id AND sm.movement_type = 'IN'
            WHERE s.status = 'active'
            GROUP BY s.supplier_id, s.supplier_name, s.contact_person, s.supplier_email, s.primary_email
            ORDER BY s.supplier_name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Cashier Performance Report Data
     */
    private function getCashierPerformanceReport($startDate, $endDate, $checkForUpdates = false) {
        // Check for new data if requested
        if ($checkForUpdates) {
            $this->checkForNewCashierData($startDate, $endDate);
        }
        
        // Show all cashiers regardless of whether they have made sales
        
        $stmt = $this->conn->prepare("
            SELECT 
                e.emp_id,
                CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, '')) as cashier_name,
                COALESCE(e.username, 'Unknown') as cashier_username,
                e.email,
                e.contact_num,
                r.role as employee_role,
                COUNT(DISTINCT CASE WHEN pt.date BETWEEN ? AND ? THEN psh.sales_header_id END) as transactions_count,
                COALESCE(SUM(CASE WHEN pt.date BETWEEN ? AND ? THEN psh.total_amount ELSE 0 END), 0) as total_sales,
                COALESCE(AVG(CASE WHEN pt.date BETWEEN ? AND ? THEN psh.total_amount END), 0) as average_transaction,
                COUNT(DISTINCT CASE WHEN pt.date BETWEEN ? AND ? THEN psd.product_id END) as unique_products_sold,
                MIN(CASE WHEN pt.date BETWEEN ? AND ? THEN pt.date END) as first_sale_date,
                MAX(CASE WHEN pt.date BETWEEN ? AND ? THEN pt.date END) as last_sale_date
            FROM tbl_employee e
            LEFT JOIN tbl_role r ON e.role_id = r.role_id
            LEFT JOIN tbl_pos_transaction pt ON e.emp_id = pt.emp_id
            LEFT JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
            WHERE (e.role_id = 2 OR e.role_id = 3) AND e.status = 'Active'
            GROUP BY e.emp_id, e.Fname, e.Lname, e.username, e.email, e.contact_num, r.role
            HAVING transactions_count > 0
            ORDER BY total_sales DESC
        ");
        $stmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no data for the specific date range, try to get recent data
        if (empty($reportData)) {
            $recentStmt = $this->conn->prepare("
                SELECT 
                    e.emp_id,
                    CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, '')) as cashier_name,
                    COALESCE(e.username, 'Unknown') as cashier_username,
                    e.email,
                    e.contact_num,
                    r.role as employee_role,
                    COUNT(DISTINCT psh.sales_header_id) as transactions_count,
                    COALESCE(SUM(psh.total_amount), 0) as total_sales,
                    COALESCE(AVG(psh.total_amount), 0) as average_transaction,
                    COUNT(DISTINCT psd.product_id) as unique_products_sold,
                    MIN(pt.date) as first_sale_date,
                    MAX(pt.date) as last_sale_date
                FROM tbl_employee e
                LEFT JOIN tbl_role r ON e.role_id = r.role_id
                LEFT JOIN tbl_pos_transaction pt ON e.emp_id = pt.emp_id
                LEFT JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
                LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
                WHERE (e.role_id = 2 OR e.role_id = 3) AND e.status = 'Active'
                GROUP BY e.emp_id, e.Fname, e.Lname, e.username, e.email, e.contact_num, r.role
                HAVING transactions_count > 0
                ORDER BY total_sales DESC
                LIMIT 10
            ");
            $recentStmt->execute();
            $reportData = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $reportData;
    }
    
    /**
     * Get Login Logs Report Data - Show Only Online Users in Cards
     */
    private function getLoginLogsReport($startDate, $endDate) {
        // Get only ONLINE users for TODAY (status = 'online' or NULL, no logout_time, and login_date = TODAY)
        // LIMIT TO ONLY 3 EMPLOYEES - BASED ON LATEST RECORD PER EMPLOYEE
        $today = date('Y-m-d');
        $onlineStmt = $this->conn->prepare("
            SELECT 
                l.login_date as date,
                l.login_time as time,
                l.username,
                CONCAT(e.Fname, ' ', COALESCE(e.Mname, ''), ' ', e.Lname) as employee_name,
                r.role,
                'ONLINE' as login_status,
                'LOGIN' as action,
                l.location,
                COALESCE(t.terminal_name, CONCAT('Terminal ', l.terminal_id)) as terminal,
                l.logout_time,
                l.logout_date,
                l.ip_address,
                l.created_at,
                '🔓 Currently logged in today' as description,
                TIMESTAMPDIFF(MINUTE, CONCAT(l.login_date, ' ', l.login_time), NOW()) as session_duration_minutes
            FROM tbl_login l
            LEFT JOIN tbl_employee e ON l.emp_id = e.emp_id
            LEFT JOIN tbl_role r ON l.role_id = r.role_id
            LEFT JOIN tbl_pos_terminal t ON l.terminal_id = t.terminal_id
            WHERE l.login_date = ?
            AND (l.logout_time IS NULL OR l.logout_time = '00:00:00')
            AND (l.status = 'online' OR l.status IS NULL OR l.status = '')
            AND l.login_id IN (
                SELECT MAX(login_id) 
                FROM tbl_login l2 
                WHERE l2.emp_id = l.emp_id 
                AND l2.login_date = ?
            )
            ORDER BY l.login_time DESC, l.created_at DESC
            LIMIT 3
        ");
        $onlineStmt->execute([$today, $today]);
        $onlineUsers = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get comprehensive login logs for detailed report - TODAY ONLY, MAX 3 EMPLOYEES
        // BASED ON LATEST RECORD PER EMPLOYEE
        $allLogsStmt = $this->conn->prepare("
            SELECT 
                l.login_date as date,
                l.login_time as time,
                l.username,
                CONCAT(e.Fname, ' ', COALESCE(e.Mname, ''), ' ', e.Lname) as employee_name,
                r.role,
                CASE 
                    WHEN l.status = 'offline' THEN 'OFFLINE'
                    WHEN l.login_date = ? AND (l.logout_time IS NULL OR l.logout_time = '00:00:00') AND (l.status = 'online' OR l.status IS NULL OR l.status = '') THEN 'ONLINE'
                    ELSE 'OFFLINE'
                END as login_status,
                CASE 
                    WHEN l.status = 'offline' THEN 'LOGOUT'
                    WHEN l.login_date = ? AND (l.logout_time IS NULL OR l.logout_time = '00:00:00') AND (l.status = 'online' OR l.status IS NULL OR l.status = '') THEN 'LOGIN'
                    ELSE 'LOGOUT'
                END as action,
                l.location,
                COALESCE(t.terminal_name, CONCAT('Terminal ', l.terminal_id)) as terminal,
                l.logout_time,
                l.logout_date,
                l.ip_address,
                l.created_at,
                CASE 
                    WHEN l.status = 'offline' 
                    THEN CONCAT('🔒 Logged out at ', TIME_FORMAT(l.logout_time, '%h:%i %p'))
                    WHEN l.login_date = ? AND (l.logout_time IS NULL OR l.logout_time = '00:00:00') AND (l.status = 'online' OR l.status IS NULL OR l.status = '')
                    THEN '🔓 Currently logged in today'
                    ELSE '🔒 Logged out'
                END as description,
                CASE 
                    WHEN l.status = 'offline' AND l.logout_time IS NOT NULL AND l.logout_time != '00:00:00'
                    THEN TIMESTAMPDIFF(MINUTE, CONCAT(l.login_date, ' ', l.login_time), CONCAT(l.logout_date, ' ', l.logout_time))
                    WHEN l.login_date = ? AND (l.logout_time IS NULL OR l.logout_time = '00:00:00') AND (l.status = 'online' OR l.status IS NULL OR l.status = '')
                    THEN TIMESTAMPDIFF(MINUTE, CONCAT(l.login_date, ' ', l.login_time), NOW())
                    ELSE 0
                END as session_duration_minutes
            FROM tbl_login l
            LEFT JOIN tbl_employee e ON l.emp_id = e.emp_id
            LEFT JOIN tbl_role r ON l.role_id = r.role_id
            LEFT JOIN tbl_pos_terminal t ON l.terminal_id = t.terminal_id
            WHERE l.login_date = ?
            AND l.login_id IN (
                SELECT MAX(login_id) 
                FROM tbl_login l2 
                WHERE l2.emp_id = l.emp_id 
                AND l2.login_date = ?
            )
            ORDER BY l.login_time DESC, l.created_at DESC
            LIMIT 3
        ");
        $allLogsStmt->execute([$today, $today, $today, $today, $today, $today]);
        $allLogs = $allLogsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Sort all logs by date and time
        usort($allLogs, function($a, $b) {
            $dateA = $a['date'] . ' ' . $a['time'];
            $dateB = $b['date'] . ' ' . $b['time'];
            return strtotime($dateB) - strtotime($dateA);
        });
        
        // Return both online users (for cards) and all logs (for detailed report)
        return [
            'online_users' => $onlineUsers,  // Only online users for cards display
            'all_logs' => $allLogs,         // All login/logout activities for detailed report
            'online_count' => count($onlineUsers),
            'total_logs' => count($allLogs)
        ];
    }
    
    /**
     * Get Activity Logs Report Data - From tbl_activity_log table (TODAY ONLY)
     */
    private function getActivityLogsReport($startDate, $endDate) {
        try {
            // Get activity logs from the existing tbl_activity_log table - TODAY ONLY
            $today = date('Y-m-d');
            
            // Debug logging
            error_log("Activity Logs Report - Fetching data for today: $today");
            
            $stmt = $this->conn->prepare("
                SELECT 
                    al.id,
                    al.user_id,
                    CASE 
                        WHEN al.username IS NOT NULL AND al.username != '' THEN al.username
                        ELSE 'System'
                    END as username,
                    COALESCE(CONCAT(e.Fname, ' ', COALESCE(e.Mname, ''), ' ', e.Lname), 
                             CASE 
                                 WHEN al.username IS NOT NULL AND al.username != '' THEN al.username
                                 ELSE 'System User'
                             END) as employee_name,
                    COALESCE(al.role, r.role, 'Unknown') as role,
                    al.activity_type as action,
                    al.activity_description as description,
                    al.table_name,
                    al.record_id,
                    al.date_created as date,
                    al.time_created as time,
                    al.created_at,
                    CASE 
                        WHEN al.activity_type IN ('LOGIN', 'POS_SALE_CREATED', 'STOCK_MOVEMENT_CREATED') THEN 'ONLINE'
                        WHEN al.activity_type = 'LOGOUT' THEN 'OFFLINE'
                        ELSE 'ACTIVE'
                    END as login_status,
                    CASE 
                        WHEN al.table_name = 'tbl_login' THEN 'Authentication'
                        WHEN al.table_name = 'tbl_pos_sales_header' THEN 'POS'
                        WHEN al.table_name = 'tbl_stock_movements' THEN 'Inventory'
                        WHEN al.table_name = 'tbl_employee' THEN 'User Management'
                        ELSE 'System'
                    END as module,
                    CASE 
                        WHEN al.activity_type = 'LOGIN' THEN 'Main Office'
                        WHEN al.activity_type = 'LOGOUT' THEN 'Main Office'
                        WHEN al.activity_type = 'POS_SALE_CREATED' THEN 'Convenience Store'
                        WHEN al.activity_type = 'STOCK_MOVEMENT_CREATED' THEN 'Warehouse'
                        WHEN al.activity_type = 'USER_CREATE' THEN 'Main Office'
                        WHEN al.activity_type = 'USER_UPDATE' THEN 'Main Office'
                        ELSE 'System'
                    END as location,
                    CASE 
                        WHEN al.activity_type = 'LOGIN' THEN 'Admin Terminal'
                        WHEN al.activity_type = 'LOGOUT' THEN 'Admin Terminal'
                        WHEN al.activity_type = 'POS_SALE_CREATED' THEN 'POS Terminal 1'
                        WHEN al.activity_type = 'STOCK_MOVEMENT_CREATED' THEN 'Warehouse Terminal'
                        WHEN al.activity_type = 'USER_CREATE' THEN 'Admin Terminal'
                        WHEN al.activity_type = 'USER_UPDATE' THEN 'Admin Terminal'
                        ELSE 'System Terminal'
                    END as terminal,
                    'ACTIVITY_LOG' as source
                FROM tbl_activity_log al
                LEFT JOIN tbl_employee e ON al.user_id = e.emp_id
                LEFT JOIN tbl_role r ON e.role_id = r.role_id
                WHERE al.date_created = ?
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT 1000
            ");
            $stmt->execute([$today]);
            $activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Debug logging
            error_log("Activity Logs Report - Found " . count($activityLogs) . " records for today");
            if (count($activityLogs) > 0) {
                error_log("Activity Logs Report - First record: " . json_encode($activityLogs[0]));
            }
            
            return $activityLogs;
            
        } catch (Exception $e) {
            error_log("Error in getActivityLogsReport: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check for new sales data
     */
    private function checkForNewSalesData($startDate, $endDate) {
        // Check for sales created in the last 5 minutes for better real-time detection
        $checkStmt = $this->conn->prepare("
            SELECT COUNT(*) as new_count 
            FROM tbl_pos_sales_header psh
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            WHERE pt.date BETWEEN ? AND ?
            AND pt.date >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $checkStmt->execute([$startDate, $endDate]);
        $result = $checkStmt->fetch();
        return $result['new_count'] > 0;
    }
    
    /**
     * Check for new cashier data
     */
    private function checkForNewCashierData($startDate, $endDate) {
        // Check for cashier activity in the last 5 minutes for better real-time detection
        $checkStmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT pt.emp_id) as new_count 
            FROM tbl_pos_sales_header psh
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            WHERE pt.date BETWEEN ? AND ?
            AND pt.emp_id IS NOT NULL
            AND pt.date >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $checkStmt->execute([$startDate, $endDate]);
        $result = $checkStmt->fetch();
        return $result['new_count'] > 0;
    }
    
    /**
     * Check for new sales (for real-time notifications)
     */
    public function checkNewSales($since) {
        // Check for new sales since the given time
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as new_sales_count
            FROM tbl_pos_sales_header psh
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            WHERE pt.date >= ?
        ");
        $stmt->execute([$since]);
        $result = $stmt->fetch();
        $newSales = $result['new_sales_count'];
        
        // Check for new cashier activity
        $stmt2 = $this->conn->prepare("
            SELECT COUNT(DISTINCT pt.emp_id) as new_cashier_activity
            FROM tbl_pos_sales_header psh
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            WHERE pt.date >= ?
            AND pt.emp_id IS NOT NULL
        ");
        $stmt2->execute([$since]);
        $result2 = $stmt2->fetch();
        $newCashierActivity = $result2['new_cashier_activity'];
        
        return [
            "success" => true,
            "data" => [
                "newSales" => (int)$newSales,
                "newCashierActivity" => (int)$newCashierActivity,
                "checkTime" => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    /**
     * Get latest sales activity for real-time updates
     */
    public function getLatestSalesActivity($minutes = 5) {
        $stmt = $this->conn->prepare("
            SELECT 
                psh.sales_header_id,
                psh.reference_number,
                psh.total_amount,
                pt.date,
                pt.time,
                pt.payment_type,
                CONCAT(COALESCE(e.Fname, ''), ' ', COALESCE(e.Lname, '')) as cashier_name,
                COALESCE(t.terminal_name, CONCAT('Terminal ', psh.terminal_id)) as terminal_name,
                COUNT(psd.product_id) as items_count
            FROM tbl_pos_sales_header psh
            LEFT JOIN tbl_pos_transaction pt ON psh.transaction_id = pt.transaction_id
            LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
            LEFT JOIN tbl_employee e ON pt.emp_id = e.emp_id
            LEFT JOIN tbl_pos_terminal t ON psh.terminal_id = t.terminal_id
            WHERE pt.date >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY psh.sales_header_id, pt.date, pt.time, psh.reference_number, psh.total_amount, pt.payment_type, e.emp_id, t.terminal_name
            ORDER BY pt.date DESC, pt.time DESC
            LIMIT 20
        ");
        $stmt->execute([$minutes]);
        $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            "success" => true,
            "data" => [
                "recentSales" => $recentSales,
                "count" => count($recentSales),
                "checkTime" => date('Y-m-d H:i:s'),
                "minutesChecked" => $minutes
            ]
        ];
    }
    
    /**
     * Get report details by report ID
     */
    public function getReportDetails($reportId) {
        try {
            // Get report details from stock movements
            $stmt = $this->conn->prepare("
                SELECT 
                    sm.movement_id,
                    p.product_name,
                    p.barcode,
                    c.category_name as category,
                    sm.quantity,
                    sm.unit_cost as unit_price,
                    (sm.quantity * sm.unit_cost) as total_value,
                    sm.movement_type,
                    sm.reference_no,
                    DATE(sm.movement_date) as date,
                    TIME(sm.movement_date) as time,
                    COALESCE(l.location_name, 'Warehouse') as location_name,
                    COALESCE(s.supplier_name, 'Unknown') as supplier_name,
                    COALESCE(b.brand, 'Generic') as brand,
                    p.expiration as expiration_date,
                    sm.notes
                FROM tbl_stock_movements sm
                JOIN tbl_product p ON sm.product_id = p.product_id
                LEFT JOIN tbl_location l ON p.location_id = l.location_id
                LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                WHERE sm.movement_id = ?
            ");
            $stmt->execute([$reportId]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($details)) {
                // Try to get from transfer reports
                $transferStmt = $this->conn->prepare("
                    SELECT 
                        th.transfer_header_id as movement_id,
                        p.product_name,
                        p.barcode,
                        c.category_name as category,
                        td.qty as quantity,
                        p.srp as unit_price,
                        (td.qty * p.srp) as total_value,
                        'TRANSFER' as movement_type,
                        th.reference_number as reference_no,
                        DATE(th.date) as date,
                        TIME(th.date) as time,
                        COALESCE(l.location_name, 'Warehouse') as location_name,
                        COALESCE(s.supplier_name, 'Unknown') as supplier_name,
                        COALESCE(b.brand, 'Generic') as brand,
                        p.expiration as expiration_date,
                        th.notes
                    FROM tbl_transfer_header th
                    JOIN tbl_transfer_dtl td ON th.transfer_header_id = td.transfer_header_id
                    JOIN tbl_product p ON td.product_id = p.product_id
                    LEFT JOIN tbl_location l ON p.location_id = l.location_id
                    LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
                    LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
                    WHERE th.transfer_header_id = ?
                ");
                $transferStmt->execute([$reportId]);
                $details = $transferStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return [
                'success' => true,
                'details' => $details,
                'report_id' => $reportId
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching report details: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    /**
     * Generate report (placeholder for future functionality)
     */
    public function generateReport($reportType, $generatedBy, $parameters) {
        // This can be extended to create PDF reports, Excel exports, etc.
        return [
            'success' => true,
            'message' => 'Report generated successfully',
            'report_type' => $reportType,
            'generated_by' => $generatedBy,
            'parameters' => $parameters
        ];
    }
    
    /**
     * Get cashier details for performance report
     */
    public function getCashierDetails($cashierId, $startDate, $endDate) {
        // Get cashier info
        $cashierStmt = $this->conn->prepare("
            SELECT 
                emp_id,
                CONCAT(COALESCE(Fname, ''), ' ', COALESCE(Lname, '')) as cashier_name,
                username as cashier_username,
                email
            FROM tbl_employee 
            WHERE emp_id = ?
        ");
        $cashierStmt->execute([$cashierId]);
        $cashierInfo = $cashierStmt->fetch();
        
        // Get summary data
        $summaryStmt = $this->conn->prepare("
            SELECT 
                COUNT(DISTINCT psh.sales_header_id) as total_transactions,
                COALESCE(SUM(psh.total_amount), 0) as total_sales,
                COUNT(DISTINCT psd.product_id) as total_items
            FROM tbl_pos_transaction pt
            LEFT JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
            WHERE pt.emp_id = ? AND pt.date BETWEEN ? AND ?
        ");
        $summaryStmt->execute([$cashierId, $startDate, $endDate]);
        $summary = $summaryStmt->fetch();
        
        // Get detailed sales data
        $salesStmt = $this->conn->prepare("
            SELECT 
                pt.date,
                pt.time,
                psh.reference_number as reference_no,
                psh.total_amount,
                COUNT(psd.product_id) as items_sold,
                pt.payment_type,
                GROUP_CONCAT(CONCAT(COALESCE(p.product_name, CONCAT('Product ID: ', psd.product_id)), ' (', psd.quantity, 'x ₱', psd.price, ')') SEPARATOR ', ') as products
            FROM tbl_pos_transaction pt
            LEFT JOIN tbl_pos_sales_header psh ON pt.transaction_id = psh.transaction_id
            LEFT JOIN tbl_pos_sales_details psd ON psh.sales_header_id = psd.sales_header_id
            LEFT JOIN tbl_product p ON psd.product_id = p.product_id
            WHERE pt.emp_id = ? AND pt.date BETWEEN ? AND ?
            GROUP BY psh.sales_header_id, pt.date, pt.time, psh.reference_number, psh.total_amount, pt.payment_type
            ORDER BY pt.date DESC, pt.time DESC
        ");
        $salesStmt->execute([$cashierId, $startDate, $endDate]);
        $salesData = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'cashier_info' => $cashierInfo,
            'summary' => $summary,
            'sales_data' => $salesData
        ];
    }
}

// Get warehouse KPIs
function get_warehouse_kpis($conn, $data) {
    try {
        $location = $data['location'] ?? 'warehouse';
        $location_id = $data['location_id'] ?? 2; // Default warehouse location
        
        // Get total products count
        $productCountStmt = $conn->prepare("
            SELECT COUNT(*) as total_products 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ?
        ");
        $productCountStmt->execute([$location_id]);
        $productCount = $productCountStmt->fetchColumn();
        
        // Get total stock quantity
        $stockQtyStmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as total_stock 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ?
        ");
        $stockQtyStmt->execute([$location_id]);
        $totalStock = $stockQtyStmt->fetchColumn();
        
        // Get total stock value
        $stockValueStmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity * srp), 0) as total_value 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ?
        ");
        $stockValueStmt->execute([$location_id]);
        $totalValue = $stockValueStmt->fetchColumn();
        
        // Get low stock items (quantity <= 10)
        $lowStockStmt = $conn->prepare("
            SELECT COUNT(*) as low_stock_count 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ? AND quantity <= 10
        ");
        $lowStockStmt->execute([$location_id]);
        $lowStockCount = $lowStockStmt->fetchColumn();
        
        // Get out of stock items (quantity = 0)
        $outOfStockStmt = $conn->prepare("
            SELECT COUNT(*) as out_of_stock_count 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ? AND quantity = 0
        ");
        $outOfStockStmt->execute([$location_id]);
        $outOfStockCount = $outOfStockStmt->fetchColumn();
        
        // Get expiring products (within 30 days)
        $expiringStmt = $conn->prepare("
            SELECT COUNT(*) as expiring_count 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ? 
            AND expiration IS NOT NULL 
            AND DATEDIFF(expiration, CURDATE()) <= 30 
            AND DATEDIFF(expiration, CURDATE()) >= 0
        ");
        $expiringStmt->execute([$location_id]);
        $expiringCount = $expiringStmt->fetchColumn();
        
        // Get categories count
        $categoriesStmt = $conn->prepare("
            SELECT COUNT(DISTINCT category) as categories_count 
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ? 
            AND category IS NOT NULL AND category != ''
        ");
        $categoriesStmt->execute([$location_id]);
        $categoriesCount = $categoriesStmt->fetchColumn();
        
        // Get suppliers count
        $suppliersStmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.supplier_id) as suppliers_count 
            FROM tbl_product p 
            LEFT JOIN tbl_category c ON p.category_id = c.category_id
                WHERE p.status = 'active' AND p.location_id = ? 
            AND p.supplier_id IS NOT NULL
        ");
        $suppliersStmt->execute([$location_id]);
        $suppliersCount = $suppliersStmt->fetchColumn();
        
        // Get recent stock movements (last 7 days)
        $recentMovementsStmt = $conn->prepare("
            SELECT COUNT(*) as recent_movements 
            FROM tbl_stock_movements sm
            LEFT JOIN tbl_product p ON sm.product_id = p.product_id
            WHERE p.location_id = ? 
            AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $recentMovementsStmt->execute([$location_id]);
        $recentMovements = $recentMovementsStmt->fetchColumn();
        
        // Get top categories by product count
        $topCategoriesStmt = $conn->prepare("
            SELECT category, COUNT(*) as product_count
            FROM tbl_product 
            WHERE status = 'active' AND location_id = ? 
            AND category IS NOT NULL AND category != ''
            GROUP BY category 
            ORDER BY product_count DESC 
            LIMIT 5
        ");
        $topCategoriesStmt->execute([$location_id]);
        $topCategories = $topCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate stock health percentage
        $stockHealthPercentage = $productCount > 0 ? 
            round((($productCount - $lowStockCount - $outOfStockCount) / $productCount) * 100, 2) : 0;
        
        echo json_encode([
            "success" => true,
            "data" => [
                "overview" => [
                    "total_products" => (int)$productCount,
                    "total_stock_quantity" => (int)$totalStock,
                    "total_stock_value" => round($totalValue, 2),
                    "categories_count" => (int)$categoriesCount,
                    "suppliers_count" => (int)$suppliersCount,
                    "recent_movements" => (int)$recentMovements
                ],
                "stock_status" => [
                    "in_stock" => (int)($productCount - $lowStockCount - $outOfStockCount),
                    "low_stock" => (int)$lowStockCount,
                    "out_of_stock" => (int)$outOfStockCount,
                    "expiring_soon" => (int)$expiringCount,
                    "stock_health_percentage" => $stockHealthPercentage
                ],
                "top_categories" => $topCategories,
                "location" => $location,
                "location_id" => $location_id,
                "last_updated" => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching warehouse KPIs: " . $e->getMessage()
        ]);
    }
}
?>
