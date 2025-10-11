<?php
/**
 * Configuration File
 * Loads environment variables and provides configuration access
 * 
 * Best Practice: This file loads from .env file or uses defaults
 */

class Config {
    private static $config = null;
    
    /**
     * Load configuration from .env file
     */
    private static function loadEnv() {
        $envFile = __DIR__ . '/../.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Parse key=value pairs
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Set environment variable if not already set
                    if (!getenv($key)) {
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                    }
                }
            }
        }
    }
    
    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public static function get($key, $default = null) {
        if (self::$config === null) {
            self::loadEnv();
            self::$config = [
                // Database Configuration
                'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
                'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
                'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '',
                'DB_NAME' => getenv('DB_NAME') ?: 'enguio2',
                'DB_CHARSET' => getenv('DB_CHARSET') ?: 'utf8mb4',
                
                // API Configuration
                'API_URL' => getenv('API_URL') ?: 'http://localhost:3000',
                'CORS_ORIGIN' => getenv('CORS_ORIGIN') ?: 'http://localhost:3000',
                
                // Environment
                'APP_ENV' => getenv('APP_ENV') ?: 'development',
                'APP_DEBUG' => getenv('APP_DEBUG') ?: 'true',
                
                // Session Configuration
                'SESSION_LIFETIME' => getenv('SESSION_LIFETIME') ?: 3600,
                'SESSION_NAME' => getenv('SESSION_NAME') ?: 'ENGUIO_SESSION',
                
                // Logging
                'LOG_ERRORS' => getenv('LOG_ERRORS') ?: 'true',
                'ERROR_LOG_PATH' => getenv('ERROR_LOG_PATH') ?: 'php_errors.log',
            ];
        }
        
        return isset(self::$config[$key]) ? self::$config[$key] : $default;
    }
    
    /**
     * Get all configuration
     * 
     * @return array All configuration values
     */
    public static function all() {
        if (self::$config === null) {
            self::get('DB_HOST'); // Trigger load
        }
        return self::$config;
    }
    
    /**
     * Check if running in debug mode
     * 
     * @return bool
     */
    public static function isDebug() {
        return self::get('APP_DEBUG') === 'true';
    }
    
    /**
     * Check if running in production
     * 
     * @return bool
     */
    public static function isProduction() {
        return self::get('APP_ENV') === 'production';
    }
}
?>
