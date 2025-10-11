<?php
/**
 * Setup Verification Script
 * Checks if the API is properly configured with best practices
 */

header('Content-Type: application/json');

$results = [
    'success' => true,
    'checks' => []
];

// Check 1: Config file exists
$results['checks']['config_file'] = [
    'name' => 'Config file exists',
    'status' => file_exists(__DIR__ . '/../config.php'),
    'message' => file_exists(__DIR__ . '/../config.php') 
        ? '✓ config.php found' 
        : '✗ config.php not found'
];

// Check 2: CORS file exists
$results['checks']['cors_file'] = [
    'name' => 'CORS file exists',
    'status' => file_exists(__DIR__ . '/../cors.php'),
    'message' => file_exists(__DIR__ . '/../cors.php') 
        ? '✓ cors.php found' 
        : '✗ cors.php not found'
];

// Check 3: .env file exists
$envFile = __DIR__ . '/../../.env';
$results['checks']['env_file'] = [
    'name' => '.env file exists',
    'status' => file_exists($envFile),
    'message' => file_exists($envFile) 
        ? '✓ .env file found' 
        : '✗ .env file not found - copy env.example.txt to .env'
];

// Check 4: Load config
try {
    require_once __DIR__ . '/../config.php';
    $results['checks']['config_loaded'] = [
        'name' => 'Config loads successfully',
        'status' => true,
        'message' => '✓ Config class loaded'
    ];
} catch (Exception $e) {
    $results['checks']['config_loaded'] = [
        'name' => 'Config loads successfully',
        'status' => false,
        'message' => '✗ Error loading config: ' . $e->getMessage()
    ];
    $results['success'] = false;
}

// Check 5: Database credentials configured
if (isset($results['checks']['config_loaded']['status']) && $results['checks']['config_loaded']['status']) {
    $dbHost = Config::get('DB_HOST');
    $dbName = Config::get('DB_NAME');
    $dbUser = Config::get('DB_USERNAME');
    
    $results['checks']['db_config'] = [
        'name' => 'Database configuration',
        'status' => !empty($dbHost) && !empty($dbName),
        'message' => !empty($dbHost) && !empty($dbName)
            ? "✓ DB configured: {$dbUser}@{$dbHost}/{$dbName}"
            : '✗ Database credentials missing in .env'
    ];
}

// Check 6: Test database connection
if (isset($results['checks']['db_config']['status']) && $results['checks']['db_config']['status']) {
    try {
        require_once __DIR__ . '/../conn.php';
        $results['checks']['db_connection'] = [
            'name' => 'Database connection',
            'status' => true,
            'message' => '✓ Database connection successful'
        ];
    } catch (Exception $e) {
        $results['checks']['db_connection'] = [
            'name' => 'Database connection',
            'status' => false,
            'message' => '✗ Database connection failed: ' . $e->getMessage()
        ];
        $results['success'] = false;
    }
}

// Check 7: Connection files exist
$connectionFiles = [
    'conn.php' => 'PDO connection file',
    'conn_mysqli.php' => 'MySQLi connection file',
    'Database.php' => 'Database class file'
];

foreach ($connectionFiles as $file => $description) {
    $exists = file_exists(__DIR__ . '/../' . $file);
    $results['checks']['file_' . $file] = [
        'name' => $description,
        'status' => $exists,
        'message' => $exists ? "✓ {$file} exists" : "✗ {$file} missing"
    ];
}

// Overall status
$failedChecks = 0;
$totalChecks = count($results['checks']);
foreach ($results['checks'] as $check) {
    if (!$check['status']) {
        $failedChecks++;
    }
}

$results['summary'] = [
    'total_checks' => $totalChecks,
    'passed' => $totalChecks - $failedChecks,
    'failed' => $failedChecks,
    'message' => $failedChecks === 0 
        ? "✓ All checks passed! API is configured correctly."
        : "⚠ {$failedChecks} check(s) failed. Please review the issues above."
];

$results['success'] = ($failedChecks === 0);

// Pretty print JSON
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
