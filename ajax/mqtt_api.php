<?php
/**
 * MQTT API Endpoint
 * Provides MQTT information to frontend via AJAX
 * Used by admin panel to display MQTT status and topic data
 */
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/MqttClient.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

function mqttBrokerConfig(): array
{
    return [
        'host' => (string) food_order_env('MQTT_HOST', '127.0.0.1'),
        'port' => (int) food_order_env('MQTT_PORT', 1883),
    ];
}

function ensureMqttHistoryTable(): void
{
    global $conn;

    $conn->query("CREATE TABLE IF NOT EXISTS mqtt_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        topic VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_incoming TINYINT(1) DEFAULT 0,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_timestamp (timestamp)
    )");
}

switch ($action) {
    case 'get_mqtt_status':
        requireAdmin();
        echo json_encode(getMqttStatus());
        break;

    case 'get_topics':
        requireAdmin();
        echo json_encode(getMqttTopics());
        break;

    case 'get_live_events':
        requireAdmin();
        echo json_encode(getRecentMqttEvents());
        break;

    case 'get_history':
        requireAdmin();
        echo json_encode(getMqttHistory());
        break;

    case 'clear_history':
        requireAdmin();
        echo json_encode(clearMqttHistory());
        break;

    case 'subscribe_info':
        // Public endpoint - returns WebSocket connection info
        $mqttConfig = mqttBrokerConfig();
        echo json_encode([
            'success' => true,
            'ws_host' => (string) food_order_env('WS_MQTT_HOST', $_SERVER['HTTP_HOST'] ?? 'localhost'),
            'ws_port' => (int) food_order_env('WS_MQTT_PORT', 8080),
            'mqtt_broker' => $mqttConfig['host'],
            'mqtt_port' => $mqttConfig['port'],
            'topic_prefix' => 'foodorder',
            'supported_topics' => [
                'foodorder/system/orders',
                'foodorder/kitchen/orders',
                'foodorder/kitchen/status',
                'foodorder/customer/{user_id}/orders',
                'foodorder/admin/dashboard',
                'foodorder/orders/{order_code}',
                'foodorder/admin/events'
            ]
        ]);
        break;

    case 'test_connection':
        requireAdmin();
        echo json_encode(testMqttConnection());
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

function getMqttStatus(): array
{
    try {
        $mqttConfig = mqttBrokerConfig();
        $mqtt = new MqttService($mqttConfig['host'], $mqttConfig['port'], 'status-check-' . uniqid());

        if ($mqtt->connect()) {
            $info = $mqtt->getConnectionInfo();
            $mqtt->disconnect();

            return [
                'success' => true,
                'broker' => $mqttConfig['host'] . ':' . $mqttConfig['port'],
                'status' => 'online',
                'client_id' => $info['clientId'],
                'connected' => $info['connected'],
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }

        return [
            'success' => false,
            'broker' => $mqttConfig['host'] . ':' . $mqttConfig['port'],
            'status' => 'offline',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    } catch (\Exception $e) {
        $mqttConfig = mqttBrokerConfig();
        return [
            'success' => false,
            'broker' => $mqttConfig['host'] . ':' . $mqttConfig['port'],
            'status' => 'error',
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

function getMqttTopics(): array
{
    return [
        'system' => [
            'foodorder/system/orders' => 'All system order events (publish)',
        ],
        'kitchen' => [
            'foodorder/kitchen/orders' => 'New orders for kitchen staff (subscribe)',
            'foodorder/kitchen/status' => 'Kitchen status updates (publish)',
        ],
        'customer' => [
            'foodorder/customer/{user_id}/orders' => 'Per-customer order updates (subscribe)',
        ],
        'admin' => [
            'foodorder/admin/dashboard' => 'Dashboard stats updates (publish)',
            'foodorder/admin/orders' => 'Admin order stream (subscribe)',
            'foodorder/admin/events' => 'Admin system events (publish)',
        ],
        'orders' => [
            'foodorder/orders/{order_code}' => 'Per-order status tracking (subscribe)',
        ]
    ];
}

function getRecentMqttEvents(): array
{
    global $conn;

    $events = [];

    // Get recent orders as events
    $result = mysqli_query($conn, "
        SELECT orders.*, users.name AS customer_name,
               GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
        FROM orders
        LEFT JOIN users ON orders.user_id = users.id
        LEFT JOIN order_items ON orders.id = order_items.order_id
        GROUP BY orders.id
        ORDER BY orders.created_at DESC
        LIMIT 20
    ");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $events[] = [
                'event' => 'order_' . $row['status'],
                'order_code' => $row['order_code'],
                'customer' => $row['customer_name'] ?? 'Guest',
                'items' => $row['items'] ?? 'No items',
                'total_amount' => $row['total_amount'],
                'status' => $row['status'],
                'timestamp' => $row['created_at'],
                'mqtt_topic' => 'foodorder/orders/' . $row['order_code']
            ];
        }
    }

    return [
        'success' => true,
        'events' => $events,
        'count' => count($events),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function getMqttHistory(): array
{
    global $conn;

    ensureMqttHistoryTable();

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $direction = isset($_GET['direction']) ? (int)$_GET['direction'] : null; // 0 = outgoing, 1 = incoming, null = both

    $where = '';
    if ($direction !== null) {
        $where = "WHERE is_incoming = $direction";
    }

    $result = mysqli_query($conn, "
        SELECT * FROM mqtt_history 
        $where 
        ORDER BY timestamp DESC, id DESC 
        LIMIT $limit OFFSET $offset
    ");

    $history = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $history[] = $row;
        }
    }

    // Get total count for pagination
    $countResult = mysqli_query($conn, "SELECT COUNT(*) as total FROM mqtt_history $where");
    $total = 0;
    if ($countResult) {
        $countRow = mysqli_fetch_assoc($countResult);
        $total = (int)($countRow['total'] ?? 0);
    }

    return [
        'success' => true,
        'history' => $history,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function clearMqttHistory(): array
{
    global $conn;

    ensureMqttHistoryTable();

    if ($conn->query("TRUNCATE TABLE mqtt_history")) {
        return [
            'success' => true,
            'message' => 'MQTT history cleared successfully'
        ];
    } else {
        return [
            'success' => false,
            'error' => $conn->error,
            'message' => 'Failed to clear MQTT history'
        ];
    }
}

function testMqttConnection(): array
{
    try {
        $mqttConfig = mqttBrokerConfig();
        $mqtt = new MqttService($mqttConfig['host'], $mqttConfig['port'], 'test-connection-' . uniqid());

        $start = microtime(true);
        $connected = $mqtt->connect();
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        if ($connected) {
            $info = $mqtt->getConnectionInfo();
            $mqtt->disconnect();

            return [
                'success' => true,
                'connected' => true,
                'host' => $mqttConfig['host'],
                'port' => $mqttConfig['port'],
                'latency_ms' => $elapsed,
                'client_id' => $info['clientId'],
                'message' => 'MQTT broker is reachable and accepting connections'
            ];
        }

        return [
            'success' => false,
            'connected' => false,
            'message' => 'Could not connect to MQTT broker'
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'connected' => false,
            'error' => $e->getMessage(),
            'message' => 'MQTT connection test failed'
        ];
    }
}
