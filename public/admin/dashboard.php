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
            margin-bottom: 20px;
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
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-start;
        }
        .log-entry .log-time {
            color: #64748b;
            white-space: nowrap;
        }
        .log-direction {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: #fbbf24;
            color: #0f172a;
        }
        .log-direction.incoming {
            background: #10b981;
            color: #ecfdf5;
        }
        .log-direction.outgoing {
            background: #3b82f6;
            color: #eff6ff;
        }
        .log-entry .log-topic {
            color: #3b82f6;
            font-weight: bold;
        }
        .log-entry .log-data {
            color: #94a3b8;
            word-break: break-word;
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
                    <i class="fas fa-play"></i> Reload History
                </button>
                <button class="mqtt-btn mqtt-btn-danger" onclick="clearLog()">
                    <i class="fas fa-trash"></i> Clear Log
                </button>
            </div>

            <div id="testMqttResult"></div>

            <div class="mqtt-log" id="mqttLog">
                <h4><i class="fas fa-list"></i> MQTT History Log</h4>
                <div id="logEntries">
                    <p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">Loading MQTT history...</p>
                </div>
            </div>
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
            const mqttSeenTopics = new Set();
            const mqttSeenEntries = new Set();

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
                if (shouldIgnoreHistoryTopic(topic)) {
                    return;
                }

                addLogEntry(topic, data, {
                    timestamp: extractMessageTimestamp(data),
                    isIncoming: false
                });

                if (data && data.order_code && data.new_status) {
                    syncOrderStatus(data.order_code, data.new_status);
                }

                if (data && data.counts) {
                    updateStats(data.counts);
                }
            }

            function extractMessageTimestamp(data) {
                if (!data || typeof data !== 'object') {
                    return null;
                }

                return data.timestamp || data.datetime || data.created_at || null;
            }

            function shouldIgnoreHistoryTopic(topic) {
                return topic === 'foodorder/system/status';
            }

            function formatMessagePreview(data) {
                if (data === null || data === undefined) {
                    return '-';
                }

                let previewSource = data;

                if (typeof previewSource === 'object' && previewSource !== null) {
                    if (Object.prototype.hasOwnProperty.call(previewSource, 'message')) {
                        previewSource = previewSource.message;
                    } else if (Object.prototype.hasOwnProperty.call(previewSource, 'payload')) {
                        previewSource = previewSource.payload;
                    }
                }

                if (typeof previewSource === 'string') {
                    const trimmed = previewSource.trim();
                    if (trimmed === '') {
                        return '-';
                    }

                    if ((trimmed.startsWith('{') && trimmed.endsWith('}')) ||
                        (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                        try {
                            previewSource = JSON.parse(trimmed);
                        } catch (e) {
                            return trimmed.length > 140 ? trimmed.slice(0, 140) + '...' : trimmed;
                        }
                    } else {
                        return trimmed.length > 140 ? trimmed.slice(0, 140) + '...' : trimmed;
                    }
                }

                if (typeof previewSource === 'object' && previewSource !== null) {
                    const parts = [];

                    if (previewSource.event) parts.push(String(previewSource.event));
                    if (previewSource.order_code) parts.push('Order ' + previewSource.order_code);
                    if (previewSource.status) parts.push('Status: ' + previewSource.status);
                    if (previewSource.customer) parts.push('Customer: ' + previewSource.customer);
                    if (previewSource.total_amount !== undefined) parts.push('Amount: ' + previewSource.total_amount);
                    if (previewSource.counts && typeof previewSource.counts === 'object') {
                        const counts = Object.entries(previewSource.counts)
                            .map(function(entry) {
                                return entry[0] + '=' + entry[1];
                            })
                            .join(', ');

                        if (counts) {
                            parts.push('Counts: ' + counts);
                        }
                    }
                    if (previewSource.items) {
                        parts.push(Array.isArray(previewSource.items) ? previewSource.items.join(', ') : String(previewSource.items));
                    }

                    if (parts.length > 0) {
                        return parts.join(' | ');
                    }

                    try {
                        const json = JSON.stringify(previewSource);
                        return json.length > 140 ? json.slice(0, 140) + '...' : json;
                    } catch (e) {
                        return '[unavailable payload]';
                    }
                }

                const text = String(previewSource);
                return text.length > 140 ? text.slice(0, 140) + '...' : text;
            }

            function formatHistoryTime(timestamp) {
                if (!timestamp) {
                    return new Date().toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                }

                const date = new Date(timestamp);
                if (Number.isNaN(date.getTime())) {
                    return String(timestamp);
                }

                return date.toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });
            }

            function buildHistorySignature(topic, data, meta) {
                const timestamp = (meta && meta.timestamp) || extractMessageTimestamp(data) || '';
                let messageValue = data;

                if (data && typeof data === 'object') {
                    if (Object.prototype.hasOwnProperty.call(data, 'message')) {
                        messageValue = data.message;
                    } else if (Object.prototype.hasOwnProperty.call(data, 'payload')) {
                        messageValue = data.payload;
                    }
                }

                if (messageValue && typeof messageValue === 'object') {
                    try {
                        messageValue = JSON.stringify(messageValue);
                    } catch (e) {
                        messageValue = '[unserializable]';
                    }
                }

                if (messageValue === null || messageValue === undefined) {
                    messageValue = '';
                }

                return [topic || '', timestamp || '', String(messageValue)].join('|');
            }

            function syncLogCounters() {
                const logEntries = document.getElementById('logEntries');
                const entryCount = logEntries ? logEntries.querySelectorAll('.log-entry').length : 0;

                msgCount = entryCount;
                document.getElementById('mqttMsgCount').textContent = msgCount;
                document.getElementById('mqttTopicCount').textContent = mqttSeenTopics.size;
            }

            function rebuildHistoryStateFromDom() {
                mqttSeenTopics.clear();
                mqttSeenEntries.clear();

                const entries = document.querySelectorAll('#logEntries .log-entry');
                entries.forEach(function(entry) {
                    const topic = entry.dataset.topic || '';
                    const historyKey = entry.dataset.historyKey || '';
                    const historyId = entry.dataset.historyId || '';

                    if (topic) {
                        mqttSeenTopics.add(topic);
                    }

                    if (historyKey) {
                        mqttSeenEntries.add(historyKey);
                    }

                    if (historyId) {
                        mqttSeenEntries.add('id:' + historyId);
                    }
                });

                syncLogCounters();
            }

            function addLogEntry(topic, data, meta = {}) {
                const logEntries = document.getElementById('logEntries');
                if (!logEntries) {
                    return false;
                }

                const resolvedTopic = topic || (data && data.topic) || 'unknown/topic';
                if (shouldIgnoreHistoryTopic(resolvedTopic)) {
                    return false;
                }

                const historyKeys = [];
                const signatureKey = 'sig:' + buildHistorySignature(topic, data, meta);
                historyKeys.push(signatureKey);

                if (meta && meta.historyId !== undefined && meta.historyId !== null && meta.historyId !== '') {
                    historyKeys.unshift('id:' + meta.historyId);
                }

                if (historyKeys.some(function(key) {
                    return mqttSeenEntries.has(key);
                })) {
                    return false;
                }

                historyKeys.forEach(function(key) {
                    mqttSeenEntries.add(key);
                });

                const placeholder = logEntries.querySelector('.log-placeholder');
                if (placeholder) {
                    placeholder.remove();
                }

                mqttSeenTopics.add(resolvedTopic);

                const entry = document.createElement('div');
                entry.className = 'log-entry';
                entry.dataset.topic = resolvedTopic;

                if (meta && meta.historyId !== undefined && meta.historyId !== null && meta.historyId !== '') {
                    entry.dataset.historyId = String(meta.historyId);
                }

                const directionIsIncoming = Number((meta && meta.isIncoming !== undefined ? meta.isIncoming : (data && data.is_incoming))) === 1;
                const directionLabel = directionIsIncoming ? 'IN' : 'OUT';
                const directionClass = directionIsIncoming ? 'incoming' : 'outgoing';
                const time = formatHistoryTime((meta && meta.timestamp) || extractMessageTimestamp(data));
                const dataPreview = formatMessagePreview(data);
                entry.dataset.historyKey = signatureKey;

                entry.innerHTML = `
                    <span class="log-time">[${time}]</span>
                    <span class="log-direction ${directionClass}">${directionLabel}</span>
                    <span class="log-topic">${escapeHtml(resolvedTopic)}</span>
                    <span class="log-data">${escapeHtml(dataPreview)}</span>
                `;

                logEntries.insertBefore(entry, logEntries.firstChild);

                // Keep only latest 100 entries
                while (logEntries.children.length > 100) {
                    logEntries.removeChild(logEntries.lastChild);
                }

                syncLogCounters();

                const lastEvent = document.getElementById('mqttLastEvent');
                if (lastEvent) {
                    const topicSuffix = resolvedTopic.split('/').pop() || resolvedTopic;
                    lastEvent.textContent = topicSuffix + ' - ' + dataPreview;
                }

                return true;
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
                if (!logEntries) {
                    return;
                }

                rebuildHistoryStateFromDom();

                const lastEvent = document.getElementById('mqttLastEvent');
                if (lastEvent) {
                    lastEvent.textContent = '-';
                }

                if (!logEntries.querySelector('.log-entry')) {
                    logEntries.innerHTML = '<p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">Loading recent MQTT history...</p>';
                }

                AJAX.api('mqtt_api.php?action=get_history&limit=50').then(function(result) {
                    if (result.success && Array.isArray(result.history)) {
                        rebuildHistoryStateFromDom();

                        const historyRows = result.history.filter(function(entry) {
                            return !shouldIgnoreHistoryTopic(entry.topic);
                        });

                        if (historyRows.length === 0) {
                            if (!logEntries.querySelector('.log-entry')) {
                                logEntries.innerHTML = '<p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">No MQTT history yet. Live events will appear here automatically.</p>';
                            }
                            return;
                        }

                        historyRows.slice().reverse().forEach(function(entry) {
                            addLogEntry(entry.topic, entry, {
                                historyId: entry.id,
                                timestamp: entry.timestamp,
                                isIncoming: entry.is_incoming
                            });
                        });
                    } else {
                        if (!logEntries.querySelector('.log-entry')) {
                            logEntries.innerHTML = '<p class="log-placeholder" style="color: #ef4444; text-align: center; padding: 20px;">Unable to load MQTT history right now.</p>';
                        }
                    }
                });
            };

            window.clearLog = function() {
                document.getElementById('logEntries').innerHTML = '<p class="log-placeholder" style="color: #64748b; text-align: center; padding: 20px;">Log cleared. Live MQTT monitoring is still active.</p>';
                msgCount = 0;
                mqttSeenTopics.clear();
                mqttSeenEntries.clear();
                syncLogCounters();

                const lastEvent = document.getElementById('mqttLastEvent');
                if (lastEvent) {
                    lastEvent.textContent = '-';
                }
            };

            if (!liveFeedAutoLoaded) {
                liveFeedAutoLoaded = true;
                window.startLiveFeed();
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
