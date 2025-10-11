<?php
/**
 * Centralized Stock Utility Functions
 * Eliminates duplicate stock status functions across the codebase
 */

class StockUtils {
    
    /**
     * Get stock status based on quantity
     * @param int $quantity Current stock quantity
     * @param int $lowStockThreshold Threshold for low stock (default: 10)
     * @return string Stock status: 'out of stock', 'low stock', or 'in stock'
     */
    public static function getStockStatus($quantity, $lowStockThreshold = 10) {
        $qty = intval($quantity);
        if ($qty <= 0) {
            return 'out of stock';
        } elseif ($qty <= $lowStockThreshold) {
            return 'low stock';
        } else {
            return 'in stock';
        }
    }
    
    /**
     * Get stock status SQL case statement for database queries
     * @param string $quantityField Field name for quantity in SQL
     * @param int $lowStockThreshold Threshold for low stock (default: 10)
     * @return string SQL CASE statement
     */
    public static function getStockStatusSQL($quantityField, $lowStockThreshold = 10) {
        return "CASE
            WHEN {$quantityField} <= 0 THEN 'out of stock'
            WHEN {$quantityField} <= {$lowStockThreshold} THEN 'low stock'
            ELSE 'in stock'
        END";
    }
    
    /**
     * Get employee details for stock movement logging
     * @param PDO $conn Database connection
     * @param mixed $employee_id_or_username Employee ID or username
     * @return array Employee details
     */
    public static function getEmployeeDetails($conn, $employee_id_or_username) {
        try {
            $empStmt = $conn->prepare("SELECT emp_id, username, CONCAT(Fname, ' ', Lname) as full_name, role_id FROM tbl_employee WHERE emp_id = ? OR username = ? LIMIT 1");
            $empStmt->execute([$employee_id_or_username, $employee_id_or_username]);
            $empData = $empStmt->fetch(PDO::FETCH_ASSOC);

            // Map role_id to role name
            $roleMapping = [
                1 => 'Cashier',
                2 => 'Inventory Manager',
                3 => 'Supervisor',
                4 => 'Admin',
                5 => 'Manager'
            ];
            $empRole = $roleMapping[$empData['role_id'] ?? 4] ?? 'Admin';
            $empName = $empData['full_name'] ?? $employee_id_or_username;

            return [
                'emp_id' => $empData['emp_id'] ?? $employee_id_or_username,
                'emp_name' => $empName,
                'emp_role' => $empRole,
                'formatted_name' => "{$empName} ({$empRole})"
            ];
        } catch (Exception $e) {
            return [
                'emp_id' => $employee_id_or_username,
                'emp_name' => $employee_id_or_username,
                'emp_role' => 'Admin',
                'formatted_name' => "{$employee_id_or_username} (Admin)"
            ];
        }
    }
}
?>
