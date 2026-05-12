<?php
session_start();

require_once '../../config/database.php';
require_once '../../includes/helpers.php';

requiredRole(['staff', 'admin'], '../login.php');

global $conn;

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

$sql = "SELECT
            orders.*,
            users.name AS customer_name,
            GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR '||') AS items
        FROM orders
        JOIN users ON orders.user_id = users.id
        LEFT JOIN order_items ON orders.id = order_items.order_id";

if ($status_filter !== 'all') {
    $sql .= " WHERE orders.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}

$sql .= " GROUP BY orders.id ORDER BY orders.created_at DESC";

$orders = getAll($conn, $sql);

$pending_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'pending'"))['count'];
$preparing_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'preparing'"))['count'];
$ready_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'ready'"))['count'];
$completed_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'completed'"))['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Panel - FoodPulse</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <link rel="stylesheet" href="../../assets/css/toast.css">
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
            margin-left: 10px;
        }
        .mqtt-connected {
            background: #d1fae5;
            color: #059669;
        }
        .mqtt-disconnected {
            background: #fee2e2;
            color: #dc2626;
        }
        .mqtt-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
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
        .mqtt-badge.disconnected .status-dot {
            background: #ef4444;
        }
        .notification-toast {
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 10000;
            background: #1e293b;
            color: #fff;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            border-left: 4px solid #3b82f6;
            animation: slideIn 0.3s ease;
            max-width: 400px;
        }
        .notification-toast h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
        }
        .notification-toast p {
            margin: 0;
            font-size: 13px;
            color: #94a3b8;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    </style>
</head>
<body class="kitchen-body">
    <!-- MQTT Status Badge -->
    <div class="mqtt-badge" id="mqttBadge">
        <div class="status-dot"></div>
        <span id="mqttStatusText">Connecting to MQTT...</span>
    </div>

    <div class="kitchen-container" style="margin-top: 30px;">
        <div class="kitchen-header">
            <h1>Kitchen Orders <i class="fas fa-utensils"></i></h1>
            <a href="actions/logout.php" class="logout-link">Logout</a>
        </div>

        <?php include '../../template/alerts.php'; ?>

        <div class="kitchen-stats" id="statsContainer">
            <div class="stat-box">Pending: <strong id="stat-pending"><?= $pending_count; ?></strong></div>
            <div class="stat-box">Preparing: <strong id="stat-preparing"><?= $preparing_count; ?></strong></div>
            <div class="stat-box">Ready: <strong id="stat-ready"><?= $ready_count; ?></strong></div>
            <div class="stat-box">Completed: <strong id="stat-completed"><?= $completed_count; ?></strong></div>
        </div>

        <div class="filter-links" id="filterLinks">
            <a href="#" data-status="all" class="<?= $status_filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="#" data-status="pending" class="<?= $status_filter === 'pending' ? 'active' : '' ?>">Pending</a>
            <a href="#" data-status="preparing" class="<?= $status_filter === 'preparing' ? 'active' : '' ?>">Preparing</a>
            <a href="#" data-status="ready" class="<?= $status_filter === 'ready' ? 'active' : '' ?>">Ready</a>
            <a href="#" data-status="completed" class="<?= $status_filter === 'completed' ? 'active' : '' ?>">Completed</a>
        </div>

        <div class="orders-list" id="ordersList">
            <?php if (!empty($orders)): ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $items = $order['items'] ? explode('||', $order['items']) : [];
                    $nextStatus = '';
                    $nextLabel = '';
                    $showButton = false;

                    if ($order['status'] === 'pending') {
                        $nextStatus = 'preparing';
                        $nextLabel = 'Start Preparing';
                        $showButton = true;
                    } elseif ($order['status'] === 'preparing') {
                        $nextStatus = 'ready';
                        $nextLabel = 'Mark Ready';
                        $showButton = true;
                    } elseif ($order['status'] === 'ready') {
                        $nextStatus = 'completed';
                        $nextLabel = 'Mark Complete';
                        $showButton = true;
                    }
                    ?>
                    <div class="order-item" data-order-id="<?= $order['id'] ?>" data-status="<?= $order['status'] ?>">
                        <div class="order-top">
                            <span class="order-code"><?= htmlspecialchars($order['order_code']); ?></span>
                            <span class="order-status <?= $order['status']; ?>"><?= $order['status']; ?></span>
                        </div>
                        <div class="order-customer">Customer: <?= htmlspecialchars($order['customer_name']); ?></div>
                        <div class="order-details">
                            <strong>Items:</strong>
                            <ul id="items-list-<?= $order['id'] ?>">
                                <?php foreach ($items as $item): ?>
                                    <?php if (!empty($item)): ?>
                                    <li><?= htmlspecialchars($item); ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="order-meta">
                            <span>Total: ₱<?= number_format($order['total_amount'], 2); ?></span>
                            <span>Time: <?= date('h:i A', strtotime($order['created_at'])); ?></span>
                        </div>
                        <div class="order-actions" id="actions-<?= $order['id'] ?>">
                            <?php if ($showButton): ?>
                                <button type="button" class="btn-action status-btn" data-id="<?= $order['id'] ?>" data-status="<?= $nextStatus ?>">
                                    <?= $nextLabel ?>
                                </button>
                            <?php else: ?>
                                <span class="done-text">Done</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-orders">No orders found</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Paho MQTT JS Library for browser-based MQTT over WebSocket -->
    <script src="../../assets/js/vendor/paho-mqtt.min.js"></script>
    <script src="../../assets/js/ajax.js"></script>
    <script src="../../assets/js/mqtt-client.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let currentStatus = '<?= $status_filter ?>';
            const ordersList = document.getElementById('ordersList');

            // ==================== MQTT SETUP (Kitchen as Publisher) ====================
            const wsHost = window.location.hostname || 'localhost';
            const wsPort = 8080;
            let mqttNotifications = [];
            const MAX_NOTIFICATIONS = 5;

            // Initialize MQTT client for the kitchen
            const kitchenMqtt = initMqttClient({
                wsHost: wsHost,
                wsPort: wsPort,
                debug: true,
                onConnected: function() {
                    updateMqttBadge(true);

                    // Kitchen PUBLISHES status changes - but SUBSCRIBES to new orders
                    // to get real-time notifications
                    kitchenMqtt.subscribe('foodorder/kitchen/orders', function(topic, data) {
                        handleNewOrderNotification(data);
                    });

                    kitchenMqtt.subscribe('foodorder/system/orders', function(topic, data) {
                        handleSystemOrderEvent(data);
                    });

                    kitchenMqtt.subscribe('foodorder/kitchen/status', function(topic, data) {
                        if (data && data.counts) {
                            updateStats(data.counts);
                        }
                    });

                    console.log('[MQTT Kitchen] Subscribed to kitchen orders, system orders, kitchen status');
                },
                onDisconnected: function() {
                    updateMqttBadge(false);
                },
                onError: function(error) {
                    console.error('[MQTT Kitchen] Error:', error);
                    updateMqttBadge(false);
                }
            });

            kitchenMqtt.connect();

            function updateMqttBadge(connected) {
                const badge = document.getElementById('mqttBadge');
                const text = document.getElementById('mqttStatusText');
                if (connected) {
                    badge.classList.remove('disconnected');
                    text.textContent = 'MQTT Connected';
                } else {
                    badge.classList.add('disconnected');
                    text.textContent = 'MQTT Disconnected';
                }
            }

            function handleNewOrderNotification(data) {
                if (!data || data.event !== 'new_order') return;

                // Show notification toast
                showNotificationToast(data);

                // If viewing all orders or pending orders, refresh
                if (currentStatus === 'all' || currentStatus === 'pending') {
                    refreshOrders();
                }
            }

            function handleSystemOrderEvent(data) {
                if (!data) return;

                // Refresh when status changes happen
                if (data.event === 'status_change') {
                    refreshOrders();
                } else if (data.event === 'new_order') {
                    if (currentStatus === 'all' || currentStatus === 'pending') {
                        refreshOrders();
                    }
                }
            }

            function showNotificationToast(data) {
                // Remove old toasts beyond limit
                const existing = document.querySelectorAll('.notification-toast');
                if (existing.length >= MAX_NOTIFICATIONS) {
                    existing[0].remove();
                }

                const toast = document.createElement('div');
                toast.className = 'notification-toast';
                toast.innerHTML = `
                    <h4><i class="fas fa-bell"></i> New Order Received</h4>
                    <p><strong>${data.order_code || 'Unknown'}</strong> - ${data.customer || 'Guest'}</p>
                    <p style="margin-top:4px;">${data.items ? data.items.length + ' item(s)' : ''} - ₱${(data.total_amount || 0).toFixed(2)}</p>
                `;
                document.body.appendChild(toast);

                setTimeout(() => {
                    toast.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => toast.remove(), 300);
                }, 5000);
            }

            function refreshOrders() {
                AJAX.getOrders(currentStatus).then(function(result) {
                    if (result.success) {
                        renderOrders(result.orders, result.counts);
                    }
                });
            }

            // ==================== RENDERING ====================

            function renderOrders(orders, counts) {
                if (!orders || orders.length === 0) {
                    ordersList.innerHTML = '<p class="no-orders">No orders found</p>';
                    updateStats(counts);
                    return;
                }

                ordersList.innerHTML = orders.map(function(order) {
                    const items = order.items ? order.items.split('||') : [];
                    const nextStatus = AJAX.getNextStatus(order.status);
                    const showButton = nextStatus !== null;

                    return `
                        <div class="order-item" data-order-id="${order.id}" data-status="${order.status}" data-mqtt-order="${order.order_code}">
                            <div class="order-top">
                                <span class="order-code">${AJAX.formatText(order.order_code)}</span>
                                <span class="order-status ${order.status}">${order.status}</span>
                            </div>
                            <div class="order-customer">Customer: ${AJAX.formatText(order.customer_name)}</div>
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
                                <span>Time: ${AJAX.formatTime(order.created_at)}</span>
                            </div>
                            <div class="order-actions" id="actions-${order.id}">
                                ${showButton ? '<button type="button" class="btn-action status-btn" data-id="' + order.id + '" data-status="' + nextStatus.next + '">' + nextStatus.label + '</button>' : '<span class="done-text">Done</span>'}
                            </div>
                        </div>
                    `;
                }).join('');

                updateStats(counts);
                attachStatusHandlers();
            }

            function updateStats(counts) {
                if (counts) {
                    document.getElementById('stat-pending').textContent = counts.pending || 0;
                    document.getElementById('stat-preparing').textContent = counts.preparing || 0;
                    document.getElementById('stat-ready').textContent = counts.ready || 0;
                    document.getElementById('stat-completed').textContent = counts.completed || 0;
                }
            }

            function attachStatusHandlers() {
                document.querySelectorAll('.status-btn').forEach(function(btn) {
                    btn.addEventListener('click', async function() {
                        const orderId = this.dataset.id;
                        const newStatus = this.dataset.status;
                        const orderItem = this.closest('.order-item');

                        orderItem.classList.add('updating');
                        this.disabled = true;

                        const result = await AJAX.updateOrderStatus(orderId, newStatus);

                        if (result.success) {
                            orderItem.classList.add('updated');

                            const nextStatus = AJAX.getNextStatus(newStatus);
                            orderItem.dataset.status = newStatus;
                            orderItem.querySelector('.order-status').textContent = newStatus;
                            orderItem.querySelector('.order-status').className = 'order-status ' + newStatus;

                            const actionsDiv = document.getElementById('actions-' + orderId);
                            if (nextStatus) {
                                actionsDiv.innerHTML = '<button type="button" class="btn-action status-btn" data-id="' + orderId + '" data-status="' + nextStatus.next + '">' + nextStatus.label + '</button>';
                                attachStatusHandlers();
                            } else {
                                actionsDiv.innerHTML = '<span class="done-text">Done</span>';
                            }

                            setTimeout(function() {
                                orderItem.classList.remove('updating', 'updated');
                                if (currentStatus !== 'all' && newStatus !== currentStatus) {
                                    orderItem.style.opacity = '0';
                                    setTimeout(function() { orderItem.remove(); }, 300);
                                }
                            }, 500);

                            // Publish MQTT status change from kitchen
                            if (mqttClient && mqttClient.isConnected()) {
                                const orderCode = orderItem.dataset.mqttOrder;
                                if (orderCode) {
                                    const message = JSON.stringify({
                                        event: 'status_change',
                                        order_code: orderCode,
                                        new_status: newStatus,
                                        kitchen: 'staff',
                                        timestamp: new Date().toISOString()
                                    });
                                    mqttClient.publish('foodorder/orders/' + orderCode, message, 1);
                                    mqttClient.publish('foodorder/system/orders', message, 1);
                                }
                            }

                        } else {
                            orderItem.classList.remove('updating');
                            this.disabled = false;
                            AJAX.showToast(result.error || 'Failed to update status', 'error');
                        }
                    });
                });
            }

            document.getElementById('filterLinks').addEventListener('click', async function(e) {
                if (e.target.tagName === 'A') {
                    e.preventDefault();
                    currentStatus = e.target.dataset.status;

                    document.querySelectorAll('#filterLinks a').forEach(function(a) { a.classList.remove('active'); });
                    e.target.classList.add('active');

                    const result = await AJAX.getOrders(currentStatus);
                    renderOrders(result.orders, result.counts);
                }
            });

            AJAX.startAutoRefresh(function(orders) {
                if (orders.success) {
                    if (currentStatus !== 'all') {
                        const filtered = orders.orders.filter(function(o) { return o.status === currentStatus; });
                        renderOrders(filtered, orders.counts);
                    } else {
                        renderOrders(orders.orders, orders.counts);
                    }
                }
            }, 5000);

            attachStatusHandlers();
        });
    </script>
</body>
</html>
