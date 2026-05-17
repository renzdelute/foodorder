<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

/**
 * MQTT Service Layer for Food Order System
 * Handles both publishing and subscribing to MQTT topics
 *
 * Topic Structure for MQTT Explorer:
 * - foodorder/system/orders          -> All order events (publish)
 * - foodorder/kitchen/orders         -> Kitchen staff view (subscribe)
 * - foodorder/kitchen/status         -> Kitchen status updates (publish)
 * - foodorder/customer/{user_id}     -> Per-customer order updates (subscribe)
 * - foodorder/admin/dashboard        -> Admin dashboard stats (publish)
 * - foodorder/admin/orders           -> Admin order stream (subscribe)
 * - foodorder/orders/{order_code}    -> Per-order status tracking (subscribe)
 */
class MqttService
{
    private string $host;
    private int $port;
    private string $clientId;
    private ?MqttClient $client = null;
    private array $subscriptions = [];
    private bool $connected = false;

    public const TOPIC_PREFIX = 'foodorder';

    // Static flag to ensure table creation only once per request
    private static bool $tableChecked = false;

    public function __construct(string $host = '127.0.0.1', int $port = 1883, string $clientId = 'food-order-php')
    {
        $this->host = (string) food_order_env('MQTT_HOST', $host);
        $this->port = (int) food_order_env('MQTT_PORT', $port);
        $this->clientId = $clientId . '-' . uniqid();

        // Ensure the mqtt_history table exists
        $this->ensureHistoryTableExists();
    }

    public function connect(): bool
    {
        try {
            $this->client = new MqttClient($this->host, $this->port, $this->clientId);

            $mqttUser = trim((string) food_order_env('MQTT_USERNAME', ''));
            $mqttPass = trim((string) food_order_env('MQTT_PASSWORD', ''));

            $settings = (new ConnectionSettings())
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(10)
                ->setLastWillTopic(self::TOPIC_PREFIX . '/system/status')
                ->setLastWillMessage(json_encode([
                    'status' => 'offline',
                    'client' => $this->clientId,
                    'timestamp' => date('Y-m-d H:i:s')
                ]))
                ->setLastWillQualityOfService(1)
                ->setRetainLastWill(true);

            if ($mqttUser !== '') {
                $settings->setUsername($mqttUser);

                if ($mqttPass !== '') {
                    $settings->setPassword($mqttPass);
                }
            }

            $this->client->connect($settings, true);
            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Connection Error: ' . $e->getMessage());
            $this->connected = false;
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->client && $this->connected) {
            try {
                $this->client->disconnect();
            } catch (\Exception $e) {
                error_log('MQTT Disconnect Error: ' . $e->getMessage());
            }
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ==================== PUBLISH METHODS ====================

    public function publishOrderEvent(string $orderCode, string $status, string $customerName, int $userId = 0, int $orderId = 0): bool
    {
        if (!$this->connected || !$this->client) return false;

        $message = json_encode([
            'order_code'   => $orderCode,
            'order_id'     => $orderId,
            'status'       => $status,
            'customer'     => $customerName,
            'user_id'      => $userId,
            'timestamp'    => date('Y-m-d H:i:s'),
            'datetime'     => date('c')
        ]);

        $topic = self::TOPIC_PREFIX . '/system/orders';
        $success = $this->publishWithLogging($topic, $message);

        if ($success) {
            $this->client->publish(self::TOPIC_PREFIX . '/orders/' . $orderCode, $message, 1);
            $this->publishWithLogging(self::TOPIC_PREFIX . '/orders/' . $orderCode, $message);

            if ($userId > 0) {
                $this->client->publish(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $message, 1);
                $this->publishWithLogging(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $message);
            }
        }

        return $success;
    }

    public function publishKitchenStatus(string $status, int $pendingCount, int $preparingCount, int $readyCount, int $completedCount): bool
    {
        if (!$this->connected || !$this->client) return false;

        $message = json_encode([
            'status'           => $status,
            'counts'           => [
                'pending'    => $pendingCount,
                'preparing'  => $preparingCount,
                'ready'      => $readyCount,
                'completed'  => $completedCount
            ],
            'timestamp'        => date('Y-m-d H:i:s')
        ]);

        $topic = self::TOPIC_PREFIX . '/kitchen/status';
        $success = $this->publishWithLogging($topic, $message);

        if ($success) {
            $this->client->publish(self::TOPIC_PREFIX . '/admin/dashboard', $message, 1);
            $this->publishWithLogging(self::TOPIC_PREFIX . '/admin/dashboard', $message);
        }

        return $success;
    }

    public function publishNewOrder(string $orderCode, string $customerName, array $items, float $totalAmount, int $userId = 0): bool
    {
        if (!$this->connected || !$this->client) return false;

        $message = json_encode([
            'event'          => 'new_order',
            'order_code'     => $orderCode,
            'customer'       => $customerName,
            'items'          => $items,
            'total_amount'   => $totalAmount,
            'user_id'        => $userId,
            'timestamp'      => date('Y-m-d H:i:s')
        ]);

        $topic = self::TOPIC_PREFIX . '/kitchen/orders';
        $success = $this->publishWithLogging($topic, $message);

        if ($success) {
            $this->client->publish(self::TOPIC_PREFIX . '/system/orders', $message, 1);
            $this->publishWithLogging(self::TOPIC_PREFIX . '/system/orders', $message);
        }

        return $success;
    }

    public function publishStatusChange(string $orderCode, string $oldStatus, string $newStatus, string $customerName, int $userId = 0): bool
    {
        if (!$this->connected || !$this->client) return false;

        $message = json_encode([
            'event'          => 'status_change',
            'order_code'     => $orderCode,
            'old_status'     => $oldStatus,
            'new_status'     => $newStatus,
            'customer'       => $customerName,
            'user_id'        => $userId,
            'timestamp'      => date('Y-m-d H:i:s')
        ]);

        $topic = self::TOPIC_PREFIX . '/system/orders';
        $success = $this->publishWithLogging($topic, $message);

        if ($success) {
            $this->client->publish(self::TOPIC_PREFIX . '/orders/' . $orderCode, $message, 1);
            $this->publishWithLogging(self::TOPIC_PREFIX . '/orders/' . $orderCode, $message);

            if ($userId > 0) {
                $this->client->publish(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $message, 1);
                $this->publishWithLogging(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $message);
            }

            $this->client->publish(self::TOPIC_PREFIX . '/kitchen/orders', $message, 1);
            $this->publishWithLogging(self::TOPIC_PREFIX . '/kitchen/orders', $message);
        }

        return $success;
    }

    public function publishAdminEvent(string $event, array $data = []): bool
    {
        if (!$this->connected || !$this->client) return false;

        $message = json_encode(array_merge([
            'event'      => $event,
            'timestamp'  => date('Y-m-d H:i:s')
        ], $data));

        $topic = self::TOPIC_PREFIX . '/admin/events';
        return $this->publishWithLogging($topic, $message);
    }

    // ==================== SUBSCRIBE METHODS ====================

    public function subscribe(string $topic, callable $callback, int $qos = 1): void
    {
        if (!$this->client || !$this->connected) return;

        try {
            $this->client->subscribe($topic, function ($topic, $message) use ($callback) {
                $data = json_decode($message, true);
                // Log incoming message
                $this->logMessage($topic, $data, true);
                // Call user's callback
                $callback($topic, $data);
            }, $qos);
            $this->subscriptions[$topic] = $callback;
        } catch (\Exception $e) {
            error_log('MQTT Subscribe Error: ' . $e->getMessage());
        }
    }

    public function subscribeKitchenOrders(callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/kitchen/orders', $callback);
    }

    public function subscribeCustomerOrders(int $userId, callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $callback);
    }

    public function subscribeOrder(string $orderCode, callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/orders/' . $orderCode, $callback);
    }

    public function subscribeSystemOrders(callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/system/orders', $callback);
    }

    public function subscribeAdminDashboard(callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/admin/dashboard', $callback);
    }

    public function subscribeAdminEvents(callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/admin/events', $callback);
    }

    public function subscribeKitchenStatus(callable $callback): void
    {
        $this->subscribe(self::TOPIC_PREFIX . '/kitchen/status', $callback);
    }

    public function getSubscriptions(): array
    {
        return array_keys($this->subscriptions);
    }

    public function loop(bool $allowInterrupt = true): void
    {
        if (!$this->client || !$this->connected) return;
        try {
            $this->client->loop($allowInterrupt);
        } catch (\Exception $e) {
            error_log('MQTT Loop Error: ' . $e->getMessage());
        }
    }

    public function getClient(): ?MqttClient
    {
        return $this->client;
    }

    public function getConnectionInfo(): array
    {
        return [
            'host'      => $this->host,
            'port'      => $this->port,
            'clientId'  => $this->clientId,
            'connected' => $this->connected,
            'topics'    => $this->getSubscriptions()
        ];
    }

    // ==================== PRIVATE METHODS ====================

    private function publishWithLogging(string $topic, string $message): bool
    {
        try {
            $this->client->publish($topic, $message, 1);
            $this->logMessage($topic, $message, false);
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Publish Error: ' . $e->getMessage());
            return false;
        }
    }

    private function logMessage(string $topic, $message, bool $isIncoming): void
    {
        global $conn;

        if (!$conn) {
            error_log('MQTT Log Error: Database connection not available');
            return;
        }

        // Ensure table exists (static flag prevents multiple checks)
        if (self::$tableChecked === false) {
            $this->ensureHistoryTableExists();
        }

        $topicEscaped = $conn->real_escape_string($topic);
        $messageEscaped = $conn->real_escape_string(is_array($message) ? json_encode($message) : $message);
        $isIncomingInt = $isIncoming ? 1 : 0;

        $sql = "INSERT INTO mqtt_history (topic, message, is_incoming, timestamp) 
                VALUES ('$topicEscaped', '$messageEscaped', $isIncomingInt, NOW())";

        if (!$conn->query($sql)) {
            error_log('MQTT Log Error: ' . $conn->error);
        }
    }

    private function ensureHistoryTableExists(): void
    {
        if (self::$tableChecked) {
            return;
        }

        global $conn;

        if (!$conn) {
            error_log('MQTT History Table Creation Error: Database connection not available');
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS mqtt_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            topic VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_incoming TINYINT(1) DEFAULT 0,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_timestamp (timestamp)
        )";

        if ($conn->query($sql) === false) {
            error_log('MQTT History Table Creation Error: ' . $conn->error);
        }

        self::$tableChecked = true;
    }
}

// Legacy alias for backwards compatibility with existing code
class MqttPublisher extends MqttService
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 1883, 'food-order-publisher');
    }

    public static function publishOrder(string $orderCode, string $status, string $customerName): bool
    {
        $mqtt = new self();
        if (!$mqtt->connect()) return false;
        $result = $mqtt->publishOrderEvent($orderCode, $status, $customerName);
        $mqtt->disconnect();
        return $result;
    }
}