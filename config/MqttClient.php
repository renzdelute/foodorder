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

    public function __construct(string $host = '127.0.0.1', int $port = 1883, string $clientId = 'food-order-php')
    {
        $this->host = (string) food_order_env('MQTT_HOST', $host);
        $this->port = (int) food_order_env('MQTT_PORT', $port);
        $this->clientId = $clientId . '-' . uniqid();
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

        try {
            $this->client->publish(self::TOPIC_PREFIX . '/system/orders', $message, 1);
            $this->client->publish(self::TOPIC_PREFIX . '/orders/' . $orderCode, $message, 1);
            if ($userId > 0) {
                $this->client->publish(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $message, 1);
            }
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Publish Order Error: ' . $e->getMessage());
            return false;
        }
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

        try {
            $this->client->publish(self::TOPIC_PREFIX . '/kitchen/status', $message, 1);
            $this->client->publish(self::TOPIC_PREFIX . '/admin/dashboard', $message, 1);
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Publish Kitchen Status Error: ' . $e->getMessage());
            return false;
        }
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

        try {
            $this->client->publish(self::TOPIC_PREFIX . '/kitchen/orders', $message, 1);
            $this->client->publish(self::TOPIC_PREFIX . '/system/orders', $message, 1);
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Publish New Order Error: ' . $e->getMessage());
            return false;
        }
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

        try {
            $this->client->publish(self::TOPIC_PREFIX . '/system/orders', $message, 1);
            $this->client->publish(self::TOPIC_PREFIX . '/orders/' . $orderCode, $message, 1);
            if ($userId > 0) {
                $this->client->publish(self::TOPIC_PREFIX . '/customer/' . $userId . '/orders', $message, 1);
            }
            $this->client->publish(self::TOPIC_PREFIX . '/kitchen/orders', $message, 1);
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Publish Status Change Error: ' . $e->getMessage());
            return false;
        }
    }

    public function publishAdminEvent(string $event, array $data = []): bool
    {
        if (!$this->connected || !$this->client) return false;

        $message = json_encode(array_merge([
            'event'      => $event,
            'timestamp'  => date('Y-m-d H:i:s')
        ], $data));

        try {
            $this->client->publish(self::TOPIC_PREFIX . '/admin/events', $message, 1);
            return true;
        } catch (\Exception $e) {
            error_log('MQTT Publish Admin Event Error: ' . $e->getMessage());
            return false;
        }
    }

    // ==================== SUBSCRIBE METHODS ====================

    public function subscribe(string $topic, callable $callback, int $qos = 1): void
    {
        if (!$this->client || !$this->connected) return;

        try {
            $this->client->subscribe($topic, function ($topic, $message) use ($callback) {
                $data = json_decode($message, true);
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
