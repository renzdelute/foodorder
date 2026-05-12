<?php
session_start();

require '../../includes/helpers.php';
require '../../config/app.php';

requireAdmin();

global $conn;

$users = countTable($conn, 'users');
$totalOrders = countTable($conn, 'orders');

$pendingResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'pending'");
$pendingRow = mysqli_fetch_assoc($pendingResult);
$pendingOrders = $pendingRow['count'];

$completedResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'completed'");
$completedRow = mysqli_fetch_assoc($completedResult);
$completedOrders = $completedRow['count'];

$totalRevenue = totalRevenue($conn, 'orders');

$cards = [
    ["title" => "Total Users", "value" => $users, "icon" => "users", "color" => "#3B82F6"],
    ["title" => "Total Orders", "value" => $totalOrders, "icon" => "orders", "color" => "#8B5CF6"],
    ["title" => "Pending Orders", "value" => $pendingOrders, "icon" => "pending", "color" => "#F59E0B"],
    ["title" => "Completed Orders", "value" => $completedOrders, "icon" => "completed", "color" => "#10B981"],
    ["title" => "Total Revenue", "value" => "₱" . number_format($totalRevenue, 2), "icon" => "revenue", "color" => "#EF4444"]
];

// Pagination settings
$limit = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$totalResult = mysqli_query($conn, "SELECT COUNT(*) as total FROM orders");
$totalRow = mysqli_fetch_assoc($totalResult);
$totalOrdersCount = (int) ($totalRow['total'] ?? 0);
$totalPages = (int) max(1, ceil($totalOrdersCount / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$orderUser = getAll($conn, "
    SELECT
        orders.*,
        COALESCE(users.name, 'Guest Order') AS name,
        GROUP_CONCAT(order_items.item_name SEPARATOR ', ') AS items
    FROM orders
    LEFT JOIN users ON orders.user_id = users.id
    LEFT JOIN order_items ON orders.id = order_items.order_id
    GROUP BY orders.id
    ORDER BY orders.id DESC
    LIMIT $limit OFFSET $offset
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Food Pulse</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <link rel="stylesheet" href="../../assets/css/components.css">
    <link rel="stylesheet" href="../../assets/css/responsive.css">
    <link rel="stylesheet" href="../../assets/css/toast.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* MQTT Monitoring Panel Styles */
        .mqtt-monitor {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid #334155;
        }
        .mqtt-monitor h3 {
            color: #fbbf24;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mqtt-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .mqtt-status-card {
            background: #0f172a;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #334155;
        }
        .mqtt-status-card .label {
            color: #94a3b8;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mqtt-status-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #fbbf24;
            margin-top: 5px;
        }
        .mqtt-status-card .value.connected { color: #10b981; }
        .mqtt-status-card .value.disconnected { color: #ef4444; }

        .mqtt-log {
            background: #0f172a;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            border: 1px solid #334155;
        }
        .mqtt-log h4 {
            color: #94a3b8;
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .log-entry {
            padding: 4px 0;
            border-bottom: 1px solid #1e293b;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .log-entry .log-time {
            color: #64748b;
            white-space: nowrap;
        }
        .log-entry .log-topic {
            color: #3b82f6;
            font-weight: bold;
        }
        .log-entry .log-data {
            color: #94a3b8;
        }

        .topic-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .topic-list li {
            padding: 8px 12px;
            background: #0f172a;
            border-radius: 6px;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #334155;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .topic-list li .topic-name {
            color: #3b82f6;
        }
        .topic-list li .topic-desc {
            color: #64748b;
            font-size: 11px;
        }

        .mqtt-controls {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .mqtt-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .mqtt-btn-primary {
            background: #3b82f6;
            color: white;
        }
        .mqtt-btn-primary:hover { background: #2563eb; }
        .mqtt-btn-danger {
            background: #ef4444;
            color: white;
        }
        .mqtt-btn-danger:hover { background: #dc2626; }
        .mqtt-btn-success {
            background: #10b981;
            color: white;
        }
        .mqtt-btn-success:hover { background: #059669; }

        #testMqttResult {
            margin-top: 10px;
            padding: 10px;
            border-radius: 6px;
            display: none;
        }
        #testMqttResult.success {
            background: #d1fae5;
            color: #059669;
            display: block;
        }
        #testMqttResult.error {
            background: #fee2e2;
            color: #dc2626;
            display: block;
        }
    </style>
</head>
<body>
    <?php include '../../template/navbarAdmin.php'; ?>

    <main class="dashboard">
        <div class="header-dashboard">
            <h1>Dashboard</h1>
            <div style="display: flex; align-items: center; gap: 15px; margin-top: 5px;">
                <!-- MQTT Connection Status Indicator -->
                <div class="mqtt-badge" id="adminMqttBadge" style="position: static; transform: none;">
                    <div class="status-dot"></div>
                    <span id="adminMqttStatus">Checking MQTT...</span>
                </div>
            </div>
        </div>

        <?php include '../../template/alerts.php'; ?>

        <div class="dashboard-stats" id="statsContainer">
            <?php
            $colorMap = ['users' => 'bg-blue', 'orders' => 'bg-purple', 'pending' => 'bg-amber', 'completed' => 'bg-green', 'revenue' => 'bg-red'];
            $iconMap = ['users' => 'users', 'orders' => 'shopping-bag', 'pending' => 'clock', 'completed' => 'check-circle', 'revenue' => 'money-bill-wave'];
            $statKeys = ['users', 'totalOrders', 'pendingOrders', 'completedOrders', 'totalRevenue'];
            ?>
            <?php foreach ($cards as $index => $card): ?>
            <div class="stat-card" data-stat="<?= $statKeys[$index] ?>">
                <div class="stat-header">
                    <span class="stat-label"><?= htmlspecialchars($card['title']); ?></span>
                </div>
                <div class="stat-value" id="stat-<?= $statKeys[$index] ?>">
                    <?= $card['icon'] === 'revenue' ? $card['value'] : htmlspecialchars($card['value']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- MQTT Monitor Section -->
        <div class="mqtt-monitor">
            <h3><i class="fas fa-satellite-dish"></i> MQTT Real-Time Monitor</h3>

            <div class="mqtt-status-grid">
                <div class="mqtt-status-card">
                    <div class="label">Broker Status</div>
                    <div class="value disconnected" id="mqttBrokerStatus">● Offline</div>
                </div>
                <div class="mqtt-status-card">
                    <div class="label">Active Topics</div>
                    <div class="value" id="mqttTopicCount">0</div>
                </div>
                <div class="mqtt-status-card">
                    <div class="label">Messages Received</div>
                    <div class="value" id="mqttMsgCount">0</div>
                </div>
                <div class="mqtt-status-card">
                    <div class="label">Last Event</div>
                    <div class="value" id="mqttLastEvent" style="font-size:12px;">-</div>
                </div>
            </div>

            <div class="mqtt-controls">
                <button class="mqtt-btn mqtt-btn-primary" onclick="testMqttConnection()">
                    <i class="fas fa-plug"></i> Test MQTT Connection
                </button>
                <button class="mqtt-btn mqtt-btn-success" onclick="startLiveFeed()">
                    <i class="fas fa-play"></i> Reload Recent Events
                </button>
                <button class="mqtt-btn mqtt-btn-danger" onclick="clearLog()">
                    <i class="fas fa-trash"></i> Clear Log
                </button>
            </div>

            <div id="testMqttResult"></div>

            <div class="mqtt-log" id="mqttLog">
                <h4><i class="fas fa-list"></i> MQTT Event Log</h4>
                <div id="logEntries">
                    <p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">Waiting for live MQTT events...</p>
                </div>
            </div>
        </div>

        <!-- MQTT Topics Reference -->
        <div class="mqtt-monitor">
            <h3><i class="fas fa-list-ul"></i> MQTT Topic Structure (for MQTT Explorer)</h3>
            <p style="color: #94a3b8; margin-bottom: 15px; font-size: 13px;">
                Use these topics in MQTT Explorer to subscribe/publish. Broker: <code>MQTT_HOST:MQTT_PORT</code> from <code>.env</code> (default <code>127.0.0.1:1883</code>)
            </p>
            <ul class="topic-list">
                <li>
                    <div>
                        <div class="topic-name">foodorder/system/orders</div>
                        <div class="topic-desc">All system order events (PUBLISH from PHP)</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
                <li>
                    <div>
                        <div class="topic-name">foodorder/kitchen/orders</div>
                        <div class="topic-desc">New orders for kitchen staff (PUBLISH from PHP)</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
                <li>
                    <div>
                        <div class="topic-name">foodorder/kitchen/status</div>
                        <div class="topic-desc">Kitchen status/statistics updates</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
                <li>
                    <div>
                        <div class="topic-name">foodorder/customer/&#123;user_id&#125;/orders</div>
                        <div class="topic-desc">Per-customer order updates</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
                <li>
                    <div>
                        <div class="topic-name">foodorder/orders/&#123;order_code&#125;</div>
                        <div class="topic-desc">Per-order status tracking</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
                <li>
                    <div>
                        <div class="topic-name">foodorder/admin/dashboard</div>
                        <div class="topic-desc">Dashboard statistics updates</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
                <li>
                    <div>
                        <div class="topic-name">foodorder/admin/events</div>
                        <div class="topic-desc">Admin system events</div>
                    </div>
                    <span style="color: #10b981; font-size: 11px;">SUBSCRIBE</span>
                </li>
            </ul>
        </div>

        <!-- Existing Orders Table -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>Recent Orders</h2>
            </div>
            <div class="table-wrapper">
                <table class="dashboard-table" id="ordersTable">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Order Code</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <?php if(!empty($orderUser)): ?>
                            <?php foreach($orderUser as $order):
                                $statusClass = '';
                                switch($order['status']) {
                                    case 'pending': $statusClass = 'status-pending'; break;
                                    case 'completed': $statusClass = 'status-completed'; break;
                                    case 'processing': $statusClass = 'status-processing'; break;
                                    case 'cancelled': $statusClass = 'status-cancelled'; break;
                                    default: $statusClass = 'status-pending';
                                }
                            ?>
                            <tr data-order-id="<?= $order['id'] ?>" data-order-code="<?= $order['order_code'] ?>">
                                <td><?= htmlspecialchars($order['name']); ?></td>
                                <td class="items-cell" title="<?= htmlspecialchars($order['items'] ?? 'No items'); ?>">
                                    <?= htmlspecialchars($order['items'] ?? 'No items'); ?>
                                </td>
                                <td><span class="order-code"><?= htmlspecialchars($order['order_code']); ?></span></td>
                                <td class="amount-cell">₱<?= number_format($order['total_amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge <?= $statusClass; ?>">
                                        <?= htmlspecialchars($order['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="action-btn btn-delete delete-order-btn" data-id="<?= $order['id'] ?>">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noOrdersRow">
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <p>No orders found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                 </table>
             </div>
         </div>

         <!-- Pagination -->
         <?php if ($totalPages > 1): ?>
         <div class="dashboard-section">
             <div class="pagination">
                 <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
                 <div class="page-links">
                     <?php if ($page > 1): ?>
                     <a href="?page=<?= $page - 1 ?>" class="page-link">Previous</a>
                     <?php endif; ?>
                     <?php for ($i = $startPage ?? max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                     <a href="?page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                     <?php endfor; ?>
                     <?php if ($page < $totalPages): ?>
                     <a href="?page=<?= $page + 1 ?>" class="page-link">Next</a>
                     <?php endif; ?>
                 </div>
             </div>
         </div>
         <?php endif; ?>
    </main>

    <script src="../../assets/js/vendor/paho-mqtt.min.js"></script>
    <script src="../../assets/js/ajax.js"></script>
    <script src="../../assets/js/mqtt-client.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let msgCount = 0;
            const wsHost = window.location.hostname || 'localhost';
            let liveFeedAutoLoaded = false;

            // Admin MQTT Monitor using Paho MQTT over WebSocket
            const adminMqtt = initMqttClient({
                wsHost: wsHost,
                wsPort: 8080,
                debug: true,
                onConnected: function() {
                    updateAdminMqttBadge(true);

                    // Subscribe to all relevant topics for monitoring
                    adminMqtt.subscribe('foodorder/#', function(topic, data) {
                        handleMqttMessage(topic, data);
                    });

                    console.log('[Admin MQTT] Subscribed to foodorder/#');

                    // Load recent events once so the log is populated immediately.
                    if (!liveFeedAutoLoaded && typeof window.startLiveFeed === 'function') {
                        liveFeedAutoLoaded = true;
                        window.startLiveFeed();
                    }
                },
                onDisconnected: function() {
                    updateAdminMqttBadge(false);
                },
                onError: function(error) {
                    console.error('[Admin MQTT] Error:', error);
                    updateAdminMqttBadge(false);
                }
            });

            adminMqtt.connect();

            function updateAdminMqttBadge(connected) {
                const badge = document.getElementById('adminMqttBadge');
                const statusEl = document.getElementById('adminMqttStatus');
                const brokerStatus = document.getElementById('mqttBrokerStatus');

                if (connected) {
                    badge.classList.remove('disconnected');
                    statusEl.textContent = 'MQTT Connected';
                    if (brokerStatus) {
                        brokerStatus.className = 'value connected';
                        brokerStatus.textContent = '● Online';
                    }
                } else {
                    badge.classList.add('disconnected');
                    statusEl.textContent = 'MQTT Disconnected';
                    if (brokerStatus) {
                        brokerStatus.className = 'value disconnected';
                        brokerStatus.textContent = '● Offline';
                    }
                }
            }

            function handleMqttMessage(topic, data) {
                msgCount++;
                document.getElementById('mqttMsgCount').textContent = msgCount;
                document.getElementById('mqttTopicCount').textContent = adminMqtt.getSubscriptions().length;

                // Update last event
                const lastEvent = document.getElementById('mqttLastEvent');
                if (lastEvent) {
                    lastEvent.textContent = topic.split('/').pop() + ' - ' + (data.order_code || data.event || 'event');
                }

                // Add to log
                addLogEntry(topic, data);

                // Sync order statuses in the table
                if (data.order_code && data.new_status) {
                    syncOrderStatus(data.order_code, data.new_status);
                }

                // Update stats if provided
                if (data.counts) {
                    updateStats(data.counts);
                }
            }

            function addLogEntry(topic, data) {
                const logEntries = document.getElementById('logEntries');
                const placeholder = logEntries.querySelector('.log-placeholder');
                if (placeholder) {
                    placeholder.remove();
                }
                const entry = document.createElement('div');
                entry.className = 'log-entry';

                const time = new Date().toLocaleTimeString();
                const dataPreview = JSON.stringify(data).substring(0, 100);

                entry.innerHTML = `
                    <span class="log-time">[${time}]</span>
                    <span class="log-topic">${topic}</span>
                    <span class="log-data">${escapeHtml(dataPreview)}${dataPreview.length >= 100 ? '...' : ''}</span>
                `;

                logEntries.insertBefore(entry, logEntries.firstChild);

                // Keep only latest 100 entries
                while (logEntries.children.length > 100) {
                    logEntries.removeChild(logEntries.lastChild);
                }
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function syncOrderStatus(orderCode, newStatus) {
                const rows = document.querySelectorAll('#ordersTableBody tr[data-order-code]');
                rows.forEach(function(row) {
                    if (row.dataset.orderCode === orderCode) {
                        const badge = row.querySelector('.status-badge');
                        if (badge) {
                            badge.textContent = newStatus;
                            badge.className = 'status-badge status-' + newStatus;
                        }
                        row.classList.add('updated');
                        setTimeout(function() { row.classList.remove('updated'); }, 1000);
                    }
                });
            }

            function updateStats(counts) {
                if (!counts) return;
                if (counts.pending !== undefined) document.getElementById('stat-pendingOrders').textContent = counts.pending;
                if (counts.completed !== undefined) document.getElementById('stat-completedOrders').textContent = counts.completed;
            }

            // Test MQTT Connection
            window.testMqttConnection = function() {
                const resultDiv = document.getElementById('testMqttResult');
                try {
                    const ClientCtor = (typeof Paho !== 'undefined' && Paho.MQTT && typeof Paho.MQTT.Client === 'function')
                        ? Paho.MQTT.Client
                        : (typeof Paho !== 'undefined' && typeof Paho.Client === 'function' ? Paho.Client : null);

                    if (!ClientCtor) {
                        throw new Error('Paho MQTT library is not loaded. Check the CDN script or install a local copy.');
                    }

                    const testMqtt = new ClientCtor(wsHost, 8080, '/mqtt', 'test-' + Date.now());
                    testMqtt.connect({
                        onSuccess: function() {
                            resultDiv.className = 'success';
                            resultDiv.innerHTML = '<i class="fas fa-check-circle"></i> MQTT broker is reachable on ' + wsHost + ':8080. WebSocket connection successful!';
                            resultDiv.style.display = 'block';
                            setTimeout(function() { resultDiv.style.display = 'none'; }, 5000);
                            testMqtt.disconnect();
                        },
                        onFailure: function(err) {
                            resultDiv.className = 'error';
                            resultDiv.innerHTML = '<i class="fas fa-times-circle"></i> Connection failed: ' + err.errorMessage + '. Make sure Mosquitto broker is running with WebSocket support on port 8080.';
                            resultDiv.style.display = 'block';
                        }
                    });
                } catch (e) {
                    resultDiv.className = 'error';
                    resultDiv.innerHTML = '<i class="fas fa-times-circle"></i> Error: ' + e.message;
                    resultDiv.style.display = 'block';
                }
            };

            // Start Live Feed
            window.startLiveFeed = function() {
                const logEntries = document.getElementById('logEntries');
                if (!logEntries.querySelector('.log-entry')) {
                    logEntries.innerHTML = '<p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">Loading recent MQTT events...</p>';
                }

                // Also fetch recent events from API
                AJAX.api('mqtt_api.php?action=get_live_events').then(function(result) {
                    if (result.success && result.events) {
                        result.events.forEach(function(event) {
                            addLogEntry(event.mqtt_topic || 'foodorder/orders/' + event.order_code, event);
                        });
                    }
                });
            };

            function clearLog() {
                document.getElementById('logEntries').innerHTML = '<p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">Log cleared. Live MQTT monitoring is still active.</p>';
                msgCount = 0;
                document.getElementById('mqttMsgCount').textContent = 0;
            }

            // Attach delete handlers
            document.querySelectorAll('.delete-order-btn').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    const orderId = this.dataset.id;
                    const row = this.closest('tr');
                    row.classList.add('updating');

                    const result = await AJAX.deleteOrder(orderId);

                    if (result.success) {
                        row.classList.remove('updating');
                        row.classList.add('updated');
                        row.style.opacity = '0';
                        const stats = await AJAX.getStats();
                        updateStats(stats);

                        setTimeout(function() {
                            row.remove();
                        }, 300);
                    } else {
                        row.classList.remove('updating');
                        AJAX.showToast(result.error || 'Failed to delete order', 'error');
                    }
                });
            });

            // Auto-refresh with AJAX fallback
            AJAX.startAutoRefresh(function(orders, stats) {
                if (orders && orders.success) {
                    // Only sync if MQTT is not connected (as primary source)
                    if (!adminMqtt.isConnected()) {
                        // syncOrderStatuses(orders.orders);
                    }
                }

                if (stats && stats.success) {
                    updateStats(stats);
                }
            }, 5000);
        });
    </script>
</body>
</html>
