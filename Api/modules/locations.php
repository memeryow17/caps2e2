<?php
// Location Management Functions
// This file contains all location-related backend functions

function get_locations($conn, $data) {
    try {
        $sql = "SELECT location_id, location_name, status FROM tbl_location WHERE status = 'active' ORDER BY location_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "data" => $locations,
            "count" => count($locations)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching locations: " . $e->getMessage()
        ]);
    }
}
?>
