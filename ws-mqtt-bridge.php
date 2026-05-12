<?php
/**
 * WebSocket-to-MQTT Bridge Server
 * 
 * This server bridges MQTT messages to WebSocket connections,
 * allowing browsers to receive real-time MQTT updates.
 * 
 * Run with: php ws-mqtt-bridge.php start
 * 
 * Requires: php-mqtt/client (already installed via composer)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/bootstrap.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class WsMqttBridge
{
    private string $wsHost;
    private int $wsPort;
    private string $mqttHost;
    private int $mqttPort;
    private ?MqttClient $mqttClient = null;
    private array $webSocketClients = [];
    private $masterSocket;
    private bool $running = false;
    private string $mqttUsername;
    private string $mqttPassword;

    // MQTT topics the bridge subscribes to
    private array $mqttTopics = [
        'foodorder/#'               => 'wildcard',
    ];

    public function __construct(
        string $wsHost = '0.0.0.0',
        int $wsPort = 8080,
        string $mqttHost = '127.0.0.1',
        int $mqttPort = 1883,
        string $mqttUsername = '',
        string $mqttPassword = ''
    ) {
        $this->wsHost = $wsHost;
        $this->wsPort = $wsPort;
        $this->mqttHost = $mqttHost;
        $this->mqttPort = $mqttPort;
        $this->mqttUsername = $mqttUsername;
        $this->mqttPassword = $mqttPassword;
    }

    /**
     * Start the bridge server
     */
    public function start(): void
    {
        echo "[Bridge] Starting WebSocket-to-MQTT Bridge...\n";
        echo "[Bridge] WebSocket: {$this->wsHost}:{$this->wsPort}\n";
        echo "[Bridge] MQTT Broker: {$this->mqttHost}:{$this->mqttPort}\n\n";

        // Create master WebSocket socket
        $this->masterSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->masterSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->masterSocket, $this->wsHost, $this->wsPort);
        socket_listen($this->masterSocket);

        // Connect to MQTT broker
        if (!$this->connectMqtt()) {
            echo "[Bridge] Failed to connect to MQTT broker. Exiting.\n";
            exit(1);
        }

        echo "[Bridge] Bridge started successfully!\n";
        echo "[Bridge] Waiting for connections...\n\n";

        $this->running = true;

        // Main event loop
        while ($this->running) {
            $readSockets = $this->getAllSockets();
            $write = null;
            $except = null;

            if (@socket_select($readSockets, $write, $except, 0, 200000) === false) {
                break;
            }

            foreach ($readSockets as $socket) {
                if ($socket === $this->masterSocket) {
                    // New WebSocket connection
                    $this->handleNewConnection();
                } else {
                    // Data from existing WebSocket client
                    $this->handleClientData($socket);
                }
            }

            // Process MQTT messages
            $this->processMqttMessages();
        }

        $this->cleanup();
    }

    /**
     * Connect to MQTT broker and subscribe to topics
     */
    private function connectMqtt(): bool
    {
        try {
            $this->mqttClient = new MqttClient($this->mqttHost, $this->mqttPort, 'ws-bridge-' . uniqid());

            $settings = (new ConnectionSettings())
                ->setUsername($this->mqttUsername)
                ->setPassword($this->mqttPassword)
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(10);

            $this->mqttClient->connect($settings, true);

            // Subscribe to all foodorder topics
            foreach ($this->mqttTopics as $topic => $label) {
                $this->mqttClient->subscribe($topic, function ($topic, $message) {
                    $this->onMqttMessage($topic, $message);
                }, 1);
                echo "[Bridge] Subscribed to MQTT topic: {$topic}\n";
            }

            // Process initial subscription
            $this->mqttClient->loop(false);

            return true;
        } catch (\Exception $e) {
            echo "[Bridge] MQTT Connection Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Handle MQTT messages and forward to WebSocket clients
     */
    private function onMqttMessage(string $topic, string $message): void
    {
        $data = json_decode($message, true);
        $data['_mqtt_topic'] = $topic;
        $data['_received_at'] = date('Y-m-d H:i:s');

        $payload = json_encode($data);

        // Forward to all connected WebSocket clients
        foreach ($this->webSocketClients as $clientId => $client) {
            if (is_resource($client['socket'])) {
                $this->sendWebSocketMessage($client['socket'], $payload);
            }
        }
    }

    /**
     * Process MQTT loop (non-blocking)
     */
    private function processMqttMessages(): void
    {
        if ($this->mqttClient) {
            try {
                $this->mqttClient->loop(false);
            } catch (\Exception $e) {
                // Silently ignore loop errors
            }
        }
    }

    /**
     * Handle new WebSocket connection
     */
    private function handleNewConnection(): void
    {
        $clientSocket = socket_accept($this->masterSocket);
        if ($clientSocket === false) return;

        // Perform WebSocket handshake
        $headers = socket_read($clientSocket, 4096);
        if ($headers && $this->performHandshake($clientSocket, $headers)) {
            $clientId = (int)$clientSocket;
            $this->webSocketClients[$clientId] = [
                'socket' => $clientSocket,
                'connected_at' => time(),
                'subscriptions' => []
            ];

            // Send connection confirmation
            $welcome = json_encode([
                'type' => 'system',
                'event' => 'connected',
                'message' => 'WebSocket connection established',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->sendWebSocketMessage($clientSocket, $welcome);

            echo "[Bridge] New WebSocket client connected (ID: {$clientId})\n";
        } else {
            socket_close($clientSocket);
        }
    }

    /**
     * Handle data from WebSocket client
     */
    private function handleClientData($socket): void
    {
        $data = @socket_read($socket, 4096, PHP_NORMAL_READ);

        if ($data === false || $data === '') {
            // Client disconnected
            $clientId = (int)$socket;
            socket_close($socket);
            unset($this->webSocketClients[$clientId]);
            echo "[Bridge] Client {$clientId} disconnected\n";
            return;
        }

        // Decode WebSocket frame
        $message = $this->decodeWebSocketFrame($data);

        if ($message) {
            try {
                $json = json_decode($message, true);
                if ($json && isset($json['action'])) {
                    $this->handleClientAction($socket, $json);
                }
            } catch (\Exception $e) {
                // Invalid JSON, ignore
            }
        }
    }

    /**
     * Handle client actions (subscribe, unsubscribe, etc.)
     */
    private function handleClientAction($socket, array $data): void
    {
        $clientId = (int)$socket;

        switch ($data['action']) {
            case 'subscribe':
                $topic = $data['topic'] ?? '';
                if ($topic) {
                    $this->webSocketClients[$clientId]['subscriptions'][] = $topic;

                    // Subscribe to MQTT topic if not already
                    if ($this->mqttClient && !in_array($topic, $this->mqttClient->getSubscriptions() ?? [])) {
                        try {
                            $this->mqttClient->subscribe($topic, function ($mqttTopic, $message) use ($topic) {
                                // Filter: only forward if it matches a client subscription pattern
                                $this->forwardToSubscribers($mqttTopic, $message);
                            }, 1);
                        } catch (\Exception $e) {
                            // Ignore
                        }
                    }

                    $response = json_encode([
                        'type' => 'system',
                        'event' => 'subscribed',
                        'topic' => $topic
                    ]);
                    $this->sendWebSocketMessage($socket, $response);
                }
                break;

            case 'unsubscribe':
                $topic = $data['topic'] ?? '';
                if ($topic) {
                    $this->webSocketClients[$clientId]['subscriptions'] = array_filter(
                        $this->webSocketClients[$clientId]['subscriptions'],
                        fn($t) => $t !== $topic
                    );

                    $response = json_encode([
                        'type' => 'system',
                        'event' => 'unsubscribed',
                        'topic' => $topic
                    ]);
                    $this->sendWebSocketMessage($socket, $response);
                }
                break;

            case 'ping':
                $response = json_encode([
                    'type' => 'system',
                    'event' => 'pong',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->sendWebSocketMessage($socket, $response);
                break;

            default:
                $response = json_encode([
                    'type' => 'error',
                    'message' => 'Unknown action: ' . ($data['action'] ?? 'none')
                ]);
                $this->sendWebSocketMessage($socket, $response);
                break;
        }
    }

    /**
     * Forward MQTT message to WebSocket clients subscribed to matching topics
     */
    private function forwardToSubscribers(string $mqttTopic, string $message): void
    {
        $data = json_decode($message, true);
        $data['_mqtt_topic'] = $mqttTopic;
        $data['_received_at'] = date('Y-m-d H:i:s');
        $payload = json_encode($data);

        foreach ($this->webSocketClients as $clientId => $client) {
            if (is_resource($client['socket'])) {
                foreach ($client['subscriptions'] as $subscribedTopic) {
                    if ($this->topicMatches($mqttTopic, $subscribedTopic)) {
                        $this->sendWebSocketMessage($client['socket'], $payload);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Check if MQTT topic matches subscription pattern (supports # and + wildcards)
     */
    private function topicMatches(string $topic, string $pattern): bool
    {
        // Exact match
        if ($topic === $pattern) return true;

        // Wildcard: # matches everything
        if ($pattern === 'foodorder/#') return strpos($topic, 'foodorder/') === 0;

        // Wildcard: + matches single level
        if (strpos($pattern, '+') !== false) {
            $patternParts = explode('/', $pattern);
            $topicParts = explode('/', $topic);

            if (count($patternParts) !== count($topicParts)) return false;

            foreach ($patternParts as $i => $part) {
                if ($part !== '+' && $part !== $topicParts[$i]) return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Perform WebSocket handshake
     */
    private function performHandshake($socket, string $headers): bool
    {
        if (preg_match("/Sec-WebSocket-Key:\s(.*)\r\n/", $headers, $matches)) {
            $key = trim($matches[1]);
            $acceptKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

            $handshake = "HTTP/1.1 101 Switching Protocols\r\n"
                       . "Upgrade: websocket\r\n"
                       . "Connection: Upgrade\r\n"
                       . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
                       . "\r\n";

            socket_write($socket, $handshake, strlen($handshake));
            return true;
        }
        return false;
    }

    /**
     * Send message through WebSocket frame
     */
    private function sendWebSocketMessage($socket, string $message): bool
    {
        $frame = $this->encodeWebSocketFrame($message);
        return @socket_write($socket, $frame, strlen($frame)) !== false;
    }

    /**
     * Encode message as WebSocket frame
     */
    private function encodeWebSocketFrame(string $data): string
    {
        $length = strlen($data);
        $frame = "\x81"; // FIN + Text frame

        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }

        return $frame . $data;
    }

    /**
     * Decode WebSocket frame
     */
    private function decodeWebSocketFrame(string $data): ?string
    {
        if (strlen($data) < 2) return null;

        $opcode = ord($data[0]) & 0x0F;
        $isFinal = (ord($data[0]) & 0x80) !== 0;

        if ($opcode === 8) {
            // Connection close
            return null;
        }

        if ($opcode !== 1 && $opcode !== 2) return null; // Not text or binary

        $payloadLength = ord($data[1]) & 127;
        $offset = 2;

        if ($payloadLength === 126) {
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            $payloadLength = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        $mask = substr($data, $offset, 4);
        $payload = substr($data, $offset + 4, $payloadLength);

        // Unmask data
        $unmasked = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $unmasked;
    }

    /**
     * Get all sockets for select
     */
    private function getAllSockets(): array
    {
        /** @var resource[] $sockets */
        $sockets = [$this->masterSocket];
        foreach ($this->webSocketClients as $client) {
            if (is_resource($client['socket'])) {
                $sockets[] = $client['socket'];
            }
        }
        return $sockets;
    }

    /**
     * Clean up resources
     */
    private function cleanup(): void
    {
        echo "\n[Bridge] Shutting down...\n";

        foreach ($this->webSocketClients as $clientId => $client) {
            @socket_close($client['socket']);
        }

        if ($this->mqttClient) {
            $this->mqttClient->disconnect();
        }

        if ($this->masterSocket) {
            @socket_close($this->masterSocket);
        }

        echo "[Bridge] Bridge stopped.\n";
    }

    /**
     * Stop the bridge server
     */
    public function stop(): void
    {
        $this->running = false;
    }
}

// ==================== CLI INTERFACE ====================

if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'start';
    $wsPort = (int)($argv[2] ?? 8080);

    switch ($command) {
        case 'start':
            $bridge = new WsMqttBridge(
                '0.0.0.0',
                $wsPort,
                getenv('MQTT_HOST') ?: '127.0.0.1',
                (int)(getenv('MQTT_PORT') ?: 1883),
                getenv('MQTT_USERNAME') ?: '',
                getenv('MQTT_PASSWORD') ?: ''
            );

            // Handle graceful shutdown
            pcntl_signal(SIGINT, function () use ($bridge) {
                $bridge->stop();
            });
            pcntl_signal(SIGTERM, function () use ($bridge) {
                $bridge->stop();
            });

            $bridge->start();
            break;

        case 'status':
            echo "WebSocket-MQTT Bridge\n";
            echo "Port: {$wsPort}\n";
            echo "Usage: php ws-mqtt-bridge.php start [port]\n";
            break;

        default:
            echo "Unknown command: {$command}\n";
            echo "Usage: php ws-mqtt-bridge.php start [port]\n";
            break;
    }
}
