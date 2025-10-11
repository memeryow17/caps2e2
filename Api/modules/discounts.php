<?php
// Discount Management Functions
// This file contains all discount-related backend functions

function get_discounts($conn, $data) {
    try {
        $sql = "SELECT discount_id as id, discount_type as type, discount_rate as rate FROM tbl_discount ORDER BY discount_type ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no discounts found, return default PWD and Senior Citizen options
        if (empty($discounts)) {
            $discounts = [
                ['id' => 'PWD', 'type' => 'PWD', 'rate' => 0.20],
                ['id' => 'Senior Citizen', 'type' => 'Senior Citizen', 'rate' => 0.20]
            ];
        }
        
        echo json_encode([
            "success" => true,
            "data" => $discounts,
            "count" => count($discounts)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching discounts: " . $e->getMessage()
        ]);
    }
}
?>
