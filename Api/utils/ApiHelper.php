<?php
/**
 * Simple API Response Helper
 * Clean functions for consistent API responses
 */

class ApiResponse {
    
    /**
     * Send success response with data
     */
    public static function success($data = null, $message = 'Success') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
    
    /**
     * Send error response
     */
    public static function error($message = 'An error occurred', $code = 400) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
    
    /**
     * Send validation error response
     */
    public static function validationError($errors) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors
        ]);
        exit;
    }
    
    /**
     * Send not found response
     */
    public static function notFound($message = 'Resource not found') {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
    
    /**
     * Send unauthorized response
     */
    public static function unauthorized($message = 'Unauthorized access') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
}

/**
 * Simple Input Helper
 */
class InputHelper {
    
    /**
     * Get input data from POST/GET
     */
    public static function getData() {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Get action from URL or data
     */
    public static function getAction() {
        return $_GET['action'] ?? ($_POST['action'] ?? '');
    }
    
    /**
     * Validate required fields
     */
    public static function validateRequired($data, $requiredFields) {
        $errors = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }
        return $errors;
    }
    
    /**
     * Sanitize string input
     */
    public static function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Get integer value safely
     */
    public static function getInt($value, $default = 0) {
        return is_numeric($value) ? (int)$value : $default;
    }
    
    /**
     * Get float value safely
     */
    public static function getFloat($value, $default = 0.0) {
        return is_numeric($value) ? (float)$value : $default;
    }
}
?>
