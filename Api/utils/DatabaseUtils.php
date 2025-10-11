<?php
/**
 * Centralized Database Utility Functions
 * Eliminates duplicate database connection functions across the codebase
 */

class DatabaseUtils {
    
    /**
     * Get database connection using environment variables
     * @return PDO Database connection
     * @throws Exception If connection fails
     */
    public static function getConnection() {
        // Use the unified connection
        require_once __DIR__ . '/../conn.php';
        global $conn;
        return $conn;
    }
    
    /**
     * Setup API environment (headers, CORS, etc.)
     */
    public static function setupApiEnvironment() {
        // Start output buffering to prevent unwanted output
        ob_start();

        session_start();

        // CORS and content-type headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
        header("Content-Type: application/json");

        // Disable error display to prevent HTML in JSON response
        ini_set('display_errors', 0);
        error_reporting(E_ALL);

        // Log errors to a file for debugging
        ini_set('log_errors', 1);
        ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
    }
    
    /**
     * Clean output buffer
     */
    public static function cleanOutputBuffer() {
        ob_clean();
    }
    
    /**
     * Validate and decode JSON input
     * @return array Decoded JSON data
     * @throws Exception If JSON is invalid
     */
    public static function getJsonInput() {
        $rawData = file_get_contents("php://input");
        
        $data = json_decode($rawData, true);

        // Check if JSON is valid
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON input: " . json_last_error_msg());
        }

        return $data;
    }
    
    /**
     * Validate that action is provided in data
     * @param array $data Input data
     * @return string Action name
     * @throws Exception If action is missing
     */
    public static function validateAction($data) {
        if (!isset($data['action'])) {
            throw new Exception("Missing action");
        }
        return $data['action'];
    }
}
?>
