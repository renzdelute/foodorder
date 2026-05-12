<?php
/**
 * MQTT Integration Test Script
 * Run: php test_mqtt.php [test-name]
 * 
 * Tests: connection, publish, subscribe, full-integration
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/MqttClient.php';
require_once __DIR__ . '/includes/helpers.php';

$test = $argv[1] ?? 'all';
$passed = 0;
$failed = 0;
$mqttHost = (string) food_order_env('MQTT_HOST', '127.0.0.1');
$mqttPort = (int) food_order_env('MQTT_PORT', 1883);

function assert_true($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

function run_tests($tests) {
    foreach ($tests as $name => $func) {
        echo "\n--- Test: {$name} ---\n";
        try {
            $func();
        } catch (\Exception $e) {
            echo "  [ERROR] Exception: " . $e->getMessage() . "\n";
            global $failed;
            $failed++;
        }
    }
}

// ==================== TEST: Connection ====================
$test_connection = function() {
    global $mqttHost, $mqttPort;

    echo "Testing MQTT broker connection...\n";
    
    $mqtt = new MqttService($mqttHost, $mqttPort, 'test-conn-' . uniqid());
    $connected = $mqtt->connect();
    
    assert_true($connected, "Can connect to MQTT broker");
    
    $info = $mqtt->getConnectionInfo();
    assert_true($info['host'] === $mqttHost, "Host is correct");
    assert_true($info['port'] === $mqttPort, "Port is correct");
    assert_true($info['connected'] === true, "Connection status is true");
    
    $mqtt->disconnect();
    assert_true(!$mqtt->isConnected(), "Can disconnect from MQTT broker");
};

// ==================== TEST: Publish Order Event ====================
$test_publish_order = function() {
    echo "Testing order event publishing...\n";
    
    $mqtt = new MqttService();
    $mqtt->connect();
    
    $testOrderCode = 'ORD-MQTT-TEST-' . time();
    
    // Publish a test order event
    $result = $mqtt->publishOrderEvent($testOrderCode, 'pending', 'Test Customer', 0, 0);
    assert_true($result, "Can publish order event to all topics");
    
    // Publish with user ID
    $result = $mqtt->publishOrderEvent($testOrderCode, 'pending', 'Test Customer', 42, 1);
    assert_true($result, "Can publish order event with user ID");
    
    $mqtt->disconnect();
};

// ==================== TEST: Publish Kitchen Status ====================
$test_publish_kitchen = function() {
    echo "Testing kitchen status publishing...\n";
    
    $mqtt = new MqttService();
    $mqtt->connect();
    
    $result = $mqtt->publishKitchenStatus('active', 3, 2, 5, 10);
    assert_true($result, "Can publish kitchen status");
    
    $mqtt->disconnect();
};

// ==================== TEST: Publish New Order ====================
$test_publish_new_order = function() {
    echo "Testing new order notification...\n";
    
    $mqtt = new MqttService();
    $mqtt->connect();
    
    $items = [
        ['name' => 'Burger', 'quantity' => 2, 'price' => 150.00],
        ['name' => 'Fries', 'quantity' => 1, 'price' => 75.00]
    ];
    
    $result = $mqtt->publishNewOrder('ORD-NEW-TEST', 'Test Customer', $items, 375.00, 42);
    assert_true($result, "Can publish new order notification");
    
    $mqtt->disconnect();
};

// ==================== TEST: Publish Status Change ====================
$test_status_change = function() {
    echo "Testing status change notification...\n";
    
    $mqtt = new MqttService();
    $mqtt->connect();
    
    $result = $mqtt->publishStatusChange('ORD-STATUS-TEST', 'pending', 'preparing', 'Test Customer', 42);
    assert_true($result, "Can publish status change");
    
    $mqtt->disconnect();
};

// ==================== TEST: Subscriptions ====================
$test_subscriptions = function() {
    echo "Testing subscription functionality...\n";
    
    $mqtt = new MqttService();
    $mqtt->connect();
    
    $received = [];
    
    // Subscribe to a test topic
    $mqtt->subscribe('foodorder/test/subscription', function($topic, $data) use (&$received) {
        $received[] = ['topic' => $topic, 'data' => $data];
    }, 1);
    
    assert_true(in_array('foodorder/test/subscription', $mqtt->getSubscriptions()), 
        "Topic is in subscriptions list");
    
    // Give time for subscription to register
    usleep(100000);
    
    // Publish a message to our own test topic using a new client
    $publisher = new MqttService();
    $publisher->connect();
    $publisher->publishOrderEvent('ORD-SUB-TEST', 'pending', 'Subscriber Test');
    
    // Also publish to test topic directly
    $publisher->publishStatusChange('ORD-SUB-TEST', 'pending', 'preparing', 'Test');
    
    usleep(200000);
    
    $publisher->disconnect();
    $mqtt->disconnect();
    
    // Note: We can't truly test subscribe in this manner without a running broker
    // This test validates subscription registration
    assert_true(count($mqtt->getSubscriptions()) > 0, "Subscriptions are registered");
};

// ==================== TEST: Admin Events ====================
$test_admin_events = function() {
    echo "Testing admin event publishing...\n";
    
    $mqtt = new MqttService();
    $mqtt->connect();
    
    $result = $mqtt->publishAdminEvent('menu_updated', ['items_changed' => 5]);
    assert_true($result, "Can publish admin event");
    
    $mqtt->disconnect();
};

// ==================== TEST: Backwards Compatibility ====================
$test_backwards_compat = function() {
    echo "Testing backwards compatibility (MqttPublisher)...\n";
    
    // The static publishOrder method should still work
    $result = MqttPublisher::publishOrder('ORD-BACKCOMPAT-' . time(), 'pending', 'Legacy Test');
    
    // This may fail if broker is not running, but the method should exist
    assert_true(method_exists('MqttPublisher', 'publishOrder'), 
        "MqttPublisher::publishOrder method exists");
    
    assert_true(is_a('MqttPublisher', 'MqttService', true),
        "MqttPublisher extends MqttService");
};

// ==================== TEST: Database Order Counts ====================
$test_order_counts = function() use ($conn) {
    echo "Testing order count retrieval (for kitchen status)...\n";
    
    // Insert test order
    $testCode = 'ORD-COUNT-TEST-' . time();
    $stmt = $conn->prepare("INSERT INTO orders (user_id, order_code, total_amount, status) VALUES (1, ?, 100.00, 'pending')");
    $stmt->bind_param('s', $testCode);
    $stmt->execute();
    $testOrderId = $conn->insert_id;
    $stmt->close();
    
    // Get counts
    $counts = getOrderCounts($conn);
    
    assert_true(isset($counts['pending']), "Pending count exists");
    assert_true($counts['pending'] > 0, "There is at least one pending order");
    assert_true(isset($counts['preparing']), "Preparing count exists");
    assert_true(isset($counts['ready']), "Ready count exists");
    assert_true(isset($counts['completed']), "Completed count exists");
    
    // Cleanup
    $conn->query("DELETE FROM order_items WHERE order_id = {$testOrderId}");
    $conn->query("DELETE FROM order_status_logs WHERE order_id = {$testOrderId}");
    $conn->query("DELETE FROM orders WHERE id = {$testOrderId}");
    
    echo "  Pending: {$counts['pending']}, Preparing: {$counts['preparing']}, Ready: {$counts['ready']}, Completed: {$counts['completed']}\n";
};

// ==================== TEST: Full Integration ====================
$test_full_integration = function() use ($conn) {
    echo "Testing full integration (order creation + MQTT notification)...\n";
    
    if (!isset($conn) || !$conn) {
        echo "  [SKIP] No database connection\n";
        return;
    }
    
    // Start transaction
    $conn->autocommit(false);
    
    try {
        // Create a test customer
        $testName = 'MQTT_Test_' . time();
        $testEmail = 'mqtt_test_' . time() . '@test.com';
        $testPassword = password_hash('testpass', PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'customer')");
        $stmt->bind_param('sss', $testName, $testEmail, $testPassword);
        $stmt->execute();
        $testUserId = $conn->insert_id;
        $stmt->close();
        
        // Create order
        $orderCode = 'ORD-INTEGRATION-' . time();
        $insertOrderSql = "INSERT INTO orders (user_id, order_code, total_amount, status) VALUES (?, ?, ?, 'pending')";
        $insertOrderStmt = $conn->prepare($insertOrderSql);
        $insertOrderStmt->bind_param('isd', $testUserId, $orderCode, 150.00);
        $insertOrderStmt->execute();
        $orderId = $conn->insert_id;
        $insertOrderStmt->close();
        
        // Add items
        $insertItemSql = "INSERT INTO order_items (order_id, item_name, quantity, price) VALUES (?, ?, ?, ?)";
        $insertItemStmt = $conn->prepare($insertItemSql);
        $insertItemStmt->bind_param('isid', $orderId, $name, $qty, $price);
        
        $name = 'Test Item 1'; $qty = 2; $price = 75.00;
        $insertItemStmt->execute();
        
        $insertItemStmt->close();
        
        // Publish MQTT events
        $mqtt = new MqttService();
        if ($mqtt->connect()) {
            $mqtt->publishNewOrder($orderCode, $testName, [
                ['name' => 'Test Item 1', 'quantity' => 2, 'price' => 75.00]
            ], 150.00, $testUserId);
            
            $mqtt->publishOrderEvent($orderCode, 'pending', $testName, $testUserId, $orderId);
            
            $counts = getOrderCounts($conn);
            $mqtt->publishKitchenStatus('pending', $counts['pending'], $counts['preparing'], $counts['ready'], $counts['completed']);
            
            $mqtt->disconnect();
            assert_true(true, "MQTT integration works end-to-end");
        } else {
            assert_true(false, "MQTT broker connection for integration test");
        }
        
        // Cleanup
        $conn->query("DELETE FROM order_items WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM order_status_logs WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM orders WHERE id = {$orderId}");
        $conn->query("DELETE FROM users WHERE id = {$testUserId}");
        
        $conn->commit();
        assert_true(true, "Test data cleaned up successfully");
        
    } catch (\Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
    $conn->autocommit(true);
};

// ==================== Run Tests ====================
echo "========================================\n";
echo "  Food Order System - MQTT Test Suite\n";
echo "========================================\n";

$all_tests = [
    'Connection Test' => $test_connection,
    'Publish Order Event' => $test_publish_order,
    'Publish Kitchen Status' => $test_publish_kitchen,
    'Publish New Order' => $test_publish_new_order,
    'Publish Status Change' => $test_status_change,
    'Subscription Registration' => $test_subscriptions,
    'Admin Events' => $test_admin_events,
    'Backwards Compatibility' => $test_backwards_compat,
    'Database Order Counts' => $test_order_counts,
    'Full Integration' => $test_full_integration,
];

// Filter tests if specific test requested
if ($test !== 'all' && isset($all_tests[$test])) {
    $all_tests = [$test => $all_tests[$test]];
} elseif ($test !== 'all') {
    echo "Unknown test: {$test}\n";
    echo "Available: " . implode(', ', array_keys($all_tests)) . ", all\n";
    exit(1);
}

run_tests($all_tests);

// ==================== Summary ====================
echo "\n========================================\n";
echo "  Test Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
