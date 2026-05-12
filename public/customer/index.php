<?php
session_start();

require_once '../../config/database.php';
require_once '../../includes/helpers.php';

requiredRole('customer', '../login.php');

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['name'] ?? 'Customer';

$sql = "SELECT
            orders.*,
            GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR '||') AS items
        FROM orders
        LEFT JOIN order_items ON orders.id = order_items.order_id
        WHERE orders.user_id = ?
        GROUP BY orders.id
        ORDER BY orders.created_at DESC";

global $conn;

if (!$conn) {
    die('Connection Failed: ' . mysqli_connect_error());
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pending_count = 0;
$preparing_count = 0;
$ready_count = 0;
$completed_count = 0;

foreach ($orders as $o) {
    if ($o['status'] === 'pending') $pending_count++;
    elseif ($o['status'] === 'preparing') $preparing_count++;
    elseif ($o['status'] === 'ready') $ready_count++;
    elseif ($o['status'] === 'completed') $completed_count++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - FoodPulse</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <link rel="stylesheet" href="../../assets/css/toast.css">
    <link rel="stylesheet" href="../../assets/css/components.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .mqtt-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .mqtt-connected { background: #d1fae5; color: #059669; }
        .mqtt-disconnected { background: #fee2e2; color: #dc2626; }

        .mqtt-badge {
            position: fixed;
            top: 15px;
            right: 15px;
            z-index: 9999;
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mqtt-badge .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
        }
        .mqtt-badge.disconnected .status-dot { background: #ef4444; }

        .live-update {
            animation: livePulse 0.5s ease;
        }
        @keyframes livePulse {
            0% { background-color: rgba(59, 130, 246, 0.3); }
            100% { background-color: transparent; }
        }
    </style>
</head>
<body class="kitchen-body">
    <!-- MQTT Status Badge -->
    <div class="mqtt-badge" id="mqttBadge">
        <div class="status-dot"></div>
        <span id="mqttStatusText">Connecting...</span>
    </div>

    <div class="kitchen-container" style="margin-top: 30px;">
        <div class="kitchen-header">
            <h1>My Orders <i class="fas fa-clipboard-list"></i></h1>
            <div>
                <span class="welcome-text">Hi, <?= htmlspecialchars($user_name); ?></span>
                <a href="actions/logout.php" class="logout-link">Logout</a>
            </div>
        </div>

        <?php include '../../template/alerts.php'; ?>

        <div class="kitchen-stats" id="statsContainer">
            <div class="stat-box">Pending: <strong id="stat-pending"><?= $pending_count; ?></strong></div>
            <div class="stat-box">Preparing: <strong id="stat-preparing"><?= $preparing_count; ?></strong></div>
            <div class="stat-box">Ready: <strong id="stat-ready"><?= $ready_count; ?></strong></div>
            <div class="stat-box">Completed: <strong id="stat-completed"><?= $completed_count; ?></strong></div>
        </div>

        <div class="menu-button">
            <a href="../menu.php"><i class="fas fa-plus"></i> Place New Order</a>
        </div>

        <div class="orders-list" id="ordersList">
            <?php if (!empty($orders)): ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $items = $order['items'] ? explode('||', $order['items']) : [];
                    ?>
                    <div class="order-item" data-order-id="<?= $order['id'] ?>" data-status="<?= $order['status'] ?>" data-order-code="<?= $order['order_code'] ?>">
                        <div class="order-top">
                            <span class="order-code"><?= htmlspecialchars($order['order_code']); ?></span>
                            <span class="order-status <?= $order['status']; ?>"><?= $order['status']; ?></span>
                        </div>
                        <div class="order-details">
                            <strong>Items:</strong>
                            <ul>
                                <?php foreach ($items as $item): ?>
                                    <?php if (!empty($item)): ?>
                                    <li><?= htmlspecialchars($item); ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="order-meta">
                            <span>Total: ₱<?= number_format($order['total_amount'], 2); ?></span>
                            <span><?= date('M d, h:i A', strtotime($order['created_at'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                 <p class="no-orders" id="noOrders">No orders yet. <a href="../menu.php">Order now!</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Paho MQTT JS Library -->
    <script src="../../assets/js/vendor/paho-mqtt.min.js"></script>
    <script src="../../assets/js/ajax.js"></script>
    <script src="../../assets/js/mqtt-client.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ordersList = document.getElementById('ordersList');
            const userId = <?= $user_id ?>;

            // ==================== MQTT SETUP (Customer as Subscriber) ====================
            const wsHost = window.location.hostname || 'localhost';
            const wsPort = 8080;

            const customerMqtt = initMqttClient({
                wsHost: wsHost,
                wsPort: wsPort,
                debug: true,
                onConnected: function() {
                    updateMqttBadge(true);

                    // Customer SUBSCRIBES to their personal order updates
                    customerMqtt.subscribe('foodorder/customer/' + userId + '/orders', function(topic, data) {
                        handleOrderUpdate(data);
                    });

                    // Also subscribe to general system events
                    customerMqtt.subscribe('foodorder/system/orders', function(topic, data) {
                        // Only process events relevant to this customer's orders
                        if (data.user_id === userId) {
                            handleOrderUpdate(data);
                        }
                    });

                    console.log('[MQTT Customer] Subscribed to personal order updates');
                },
                onDisconnected: function() {
                    updateMqttBadge(false);
                },
                onError: function(error) {
                    console.error('[MQTT Customer] Error:', error);
                    updateMqttBadge(false);
                }
            });

            customerMqtt.connect();

            function updateMqttBadge(connected) {
                const badge = document.getElementById('mqttBadge');
                const text = document.getElementById('mqttStatusText');
                if (connected) {
                    badge.classList.remove('disconnected');
                    text.textContent = 'Live Updates Active';
                } else {
                    badge.classList.add('disconnected');
                    text.textContent = 'Disconnected - Using polling';
                }
            }

            function handleOrderUpdate(data) {
                if (!data) return;

                console.log('[MQTT Customer] Received update:', data);

                // Update order status in the UI
                if (data.order_code) {
                    const orderEl = document.querySelector('[data-order-code="' + data.order_code + '"]');
                    if (orderEl) {
                        // Add animation class
                        orderEl.classList.add('live-update');

                        // Update status badge
                        const statusBadge = orderEl.querySelector('.order-status');
                        if (statusBadge && data.new_status) {
                            statusBadge.textContent = data.new_status;
                            statusBadge.className = 'order-status ' + data.new_status;
                        }

                        // Remove animation after it completes
                        setTimeout(function() {
                            orderEl.classList.remove('live-update');
                        }, 1000);
                    } else {
                        // New order - refresh list
                        AJAX.getOrders('all', 'customer').then(function(result) {
                            if (result.success) {
                                renderOrders(result.orders, result.counts);
                            }
                        });
                    }
                }

                // Update stats if counts provided
                if (data.counts) {
                    updateStats(data.counts);
                }
            }

            function renderOrders(orders, counts) {
                if (!orders || orders.length === 0) {
                    ordersList.innerHTML = '<p class="no-orders" id="noOrders">No orders yet. <a href="../menu.php">Order now!</a></p>';
                    updateStats(counts);
                    return;
                }

                ordersList.innerHTML = orders.map(function(order) {
                    const items = order.items ? order.items.split('||') : [];

                    return `
                        <div class="order-item" data-order-id="${order.id}" data-status="${order.status}" data-order-code="${order.order_code}">
                            <div class="order-top">
                                <span class="order-code">${AJAX.formatText(order.order_code)}</span>
                                <span class="order-status ${order.status}">${order.status}</span>
                            </div>
                            <div class="order-details">
                                <strong>Items:</strong>
                                <ul>
                                    ${items.map(function(item) {
                                        return item ? '<li>' + AJAX.formatText(item) + '</li>' : '';
                                    }).join('')}
                                </ul>
                            </div>
                            <div class="order-meta">
                                <span>Total: ₱${parseFloat(order.total_amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,')}</span>
                                <span>${AJAX.formatDateTime(order.created_at)}</span>
                            </div>
                        </div>
                    `;
                }).join('');

                updateStats(counts);
            }

            function updateStats(counts) {
                if (counts) {
                    document.getElementById('stat-pending').textContent = counts.pending || 0;
                    document.getElementById('stat-preparing').textContent = counts.preparing || 0;
                    document.getElementById('stat-ready').textContent = counts.ready || 0;
                    document.getElementById('stat-completed').textContent = counts.completed || 0;
                }
            }

            // Fallback polling when MQTT is not connected
            AJAX.startAutoRefresh(function(orders) {
                if (orders.success) {
                    renderOrders(orders.orders, orders.counts);
                }
            }, 5000);

            // Initial setup
            updateMqttBadge(false);
        });
    </script>
</body>
</html>
