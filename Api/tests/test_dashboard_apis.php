<?php
// Test script for dashboard APIs
// This file tests the new dashboard API endpoints

// Include database connection
require_once 'conn_mysqli.php';

echo "<h2>Dashboard API Test Results</h2>";

// Test Transfer API
echo "<h3>1. Testing Transfer API</h3>";
$_POST = ['action' => 'get_total_transfer'];
ob_start();
include 'dashboard_transfer_api.php';
$transferResult = ob_get_clean();
echo "<pre>" . htmlspecialchars($transferResult) . "</pre>";

// Test Sales API
echo "<h3>2. Testing Sales API</h3>";
$_POST = ['action' => 'get_total_sales'];
ob_start();
include 'dashboard_sales_api.php';
$salesResult = ob_get_clean();
echo "<pre>" . htmlspecialchars($salesResult) . "</pre>";

// Test Return API
echo "<h3>3. Testing Return API</h3>";
$_POST = ['action' => 'get_total_return'];
ob_start();
include 'dashboard_return_api.php';
$returnResult = ob_get_clean();
echo "<pre>" . htmlspecialchars($returnResult) . "</pre>";

// Test Payment Methods
echo "<h3>4. Testing Payment Methods</h3>";
$_POST = ['action' => 'get_payment_methods'];
ob_start();
include 'dashboard_sales_api.php';
$paymentResult = ob_get_clean();
echo "<pre>" . htmlspecialchars($paymentResult) . "</pre>";

echo "<h3>Test Complete!</h3>";
echo "<p>If you see JSON responses above, the APIs are working correctly.</p>";
?>
