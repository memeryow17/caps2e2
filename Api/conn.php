<?php
/**
 * Unified Database Connection
 * Single source of truth for all database connections
 * Uses environment variables from .env file
 */

// Load environment variables
require_once __DIR__ . '/../simple_dotenv.php';
$dotenv = new SimpleDotEnv(__DIR__ . '/..');
$dotenv->load();

// Get database credentials from environment variables
$servername = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '3306';
$dbname = $_ENV['DB_DATABASE'] ?? 'enguio2';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// Create PDO connection (primary connection type)
try {
    $dsn = "mysql:host=$servername;port=$port;dbname=$dbname;charset=$charset";
    
    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset"
    ]);
    
} catch(PDOException $e) {
    // Log error securely without exposing credentials
    error_log("Database connection failed: " . $e->getMessage());
    
    header("Content-Type: application/json");
    
    // Show detailed error only in development
    $isDevelopment = ($_ENV['APP_ENV'] ?? 'production') === 'development';
    
    echo json_encode([
        "success" => false,
        "message" => $isDevelopment 
            ? "Connection failed: " . $e->getMessage()
            : "Database connection failed. Please contact support."
    ]);
    exit;
}

/**
 * Helper function to get PDO database connection
 * Provides singleton pattern for efficiency
 * 
 * @return PDO Database connection object
 */
function getDatabaseConnection() {
    global $conn;
    return $conn;
}

/**
 * Helper function to get MySQLi connection (for legacy files like login.php)
 * Creates MySQLi connection with same credentials from .env
 * 
 * @return mysqli MySQLi connection object
 */
function getMySQLiConnection() {
    global $servername, $username, $password, $dbname, $port, $charset;
    
    try {
        $mysqli_conn = new mysqli($servername, $username, $password, $dbname, $port);
        
        if ($mysqli_conn->connect_error) {
            throw new Exception("Connection failed: " . $mysqli_conn->connect_error);
        }
        
        $mysqli_conn->set_charset($charset);
        return $mysqli_conn;
        
    } catch(Exception $e) {
        error_log("MySQLi connection failed: " . $e->getMessage());
        
        header("Content-Type: application/json");
        
        $isDevelopment = ($_ENV['APP_ENV'] ?? 'production') === 'development';
        
        echo json_encode([
            "success" => false,
            "message" => $isDevelopment 
                ? "Connection failed: " . $e->getMessage()
                : "Database connection failed. Please contact support."
        ]);
        exit;
    }
}
?>