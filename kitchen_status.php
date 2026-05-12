<?php
/**
 * Kitchen Status Publisher
 * Run as a background process to periodically publish kitchen status
 * 
 * Usage: php kitchen_status.php start
 * 
 * This publishes the current kitchen queue status at regular intervals
 * so dashboards can update in real-time.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/MqttClient.php';

$command = $argv[1] ?? 'start';

switch ($command) {
    case 'start':
        startKitchenStatusPublisher();
        break;
    case 'once':
        publishKitchenStatusOnce();
        break;
    default:
        echo "Usage: php kitchen_status.php [start|once]\n";
        break;
}

function getOrderCounts($conn): array
{
    $counts = ['pending' => 0, 'preparing' => 0, 'ready' => 0, 'completed' => 0];
    $result = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM orders WHERE status != 'cancelled' GROUP BY status");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['cnt'];
            }
        }
    }
    return $counts;
}

function publishKitchenStatusOnce()
{
    global $conn;

    echo "Publishing kitchen status once...\n";

    $counts = getOrderCounts($conn);

    $mqtt = new MqttService();
    if ($mqtt->connect()) {
        $result = $mqtt->publishKitchenStatus(
            'active',
            $counts['pending'],
            $counts['preparing'],
            $counts['ready'],
            $counts['completed']
        );

        if ($result) {
            echo "✓ Kitchen status published successfully\n";
            echo "  Pending: {$counts['pending']}\n";
            echo "  Preparing: {$counts['preparing']}\n";
            echo "  Ready: {$counts['ready']}\n";
            echo "  Completed: {$counts['completed']}\n";
        } else {
            echo "✗ Failed to publish kitchen status\n";
        }

        $mqtt->disconnect();
    } else {
        echo "✗ Failed to connect to MQTT broker\n";
    }
}

function startKitchenStatusPublisher()
{
    global $conn;

    echo "Starting Kitchen Status Publisher...\n";
    echo "Press Ctrl+C to stop.\n\n";

    $mqtt = new MqttService();

    if (!$mqtt->connect()) {
        echo "✗ Failed to connect to MQTT broker\n";
        exit(1);
    }

    echo "✓ Connected to MQTT broker\n";
    echo "Publishing kitchen status every 30 seconds...\n\n";

    // Set up signal handlers for graceful shutdown
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGINT, function () use ($mqtt) {
            echo "\nShutting down...\n";
            $mqtt->disconnect();
            exit(0);
        });
        pcntl_signal(SIGTERM, function () use ($mqtt) {
            $mqtt->disconnect();
            exit(0);
        });
    }

    $interval = 30; // seconds

    while (true) {
        $counts = getOrderCounts($conn);

        $mqtt->publishKitchenStatus(
            'active',
            $counts['pending'],
            $counts['preparing'],
            $counts['ready'],
            $counts['completed']
        );

        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] Status published - Pending: {$counts['pending']}, Preparing: {$counts['preparing']}, Ready: {$counts['ready']}, Completed: {$counts['completed']}\n";

        // Sleep in small increments to allow signal handling
        $slept = 0;
        while ($slept < $interval) {
            sleep(1);
            $slept++;

            // Process signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
}