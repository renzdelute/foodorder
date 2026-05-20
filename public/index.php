<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$orders = [];

if ($search) {
    $stmt = $conn->prepare("
        SELECT 
            orders.*,
            users.name AS customer_name,
            GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
        FROM orders
        JOIN users ON orders.user_id = users.id
        LEFT JOIN order_items ON orders.id = order_items.order_id
        WHERE orders.order_code = ? OR users.name LIKE ?
        GROUP BY orders.id
        ORDER BY orders.created_at DESC
        LIMIT 10
    ");
    $searchParam = '%' . $search . '%';
    $stmt->bind_param("ss", $search, $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = mysqli_query($conn, "
        SELECT 
            orders.*,
            users.name AS customer_name,
            GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
        FROM orders
        JOIN users ON orders.user_id = users.id
        LEFT JOIN order_items ON orders.id = order_items.order_id
        GROUP BY orders.id
        ORDER BY orders.created_at DESC
        LIMIT 20
    ");
    $orders = mysqli_fetch_all($result, MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order - FoodPulse</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/css/toast.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body.track-page {
            background: linear-gradient(rgba(15,23,42,0.85), rgba(15,23,42,0.95)), url('../assets/images/food.jpg') center/cover no-repeat;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        .track-container {
            max-width: 950px;
            margin: 0 auto;
            padding: 2rem;
            min-height: calc(100vh - 80px);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .track-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .track-header h1 {
            color: #fff;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .track-header p {
            color: #cbd5e1;
            font-size: 1.1rem;
        }
        .search-form {
            display: flex;
            gap: 10px;
            max-width: 500px;
            margin: 0 auto 2rem;
        }
        .search-form input {
            flex: 1;
            padding: 14px 20px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            font-size: 16px;
            background: rgba(255,255,255,0.95);
            outline: none;
            transition: border-color 0.3s;
        }
        .search-form input:focus {
            border-color: #3B82F6;
        }
        .search-form button {
            padding: 14px 30px;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s;
        }
        .search-form button:hover {
            transform: scale(1.05);
        }
        .orders-card {
            background: rgba(255,255,255,0.98);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        .orders-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .orders-card-header h2 {
            margin: 0;
            color: #1e293b;
            font-size: 1.25rem;
        }
        .order-count {
            background: #3B82F6;
            color: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .orders-table {
            width: 100%;
            border-collapse: collapse;
        }
        .orders-table th {
            background: #f8fafc;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .orders-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .orders-table tr:last-child td {
            border-bottom: none;
        }
        .orders-table tr:hover {
            background: #f8fafc;
        }
        .order-code-cell {
            font-weight: 600;
            color: #3B82F6;
        }
        .amount-cell {
            font-weight: 600;
            color: #10B981;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending {
            background: #FEF3C7;
            color: #B45309;
        }
        .status-processing, .status-preparing {
            background: #DBEAFE;
            color: #1D4ED8;
        }
        .status-ready {
            background: #D1FAE5;
            color: #059669;
        }
        .status-completed {
            background: #E5E7EB;
            color: #6B7280;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }
        .empty-state p {
            font-size: 1.1rem;
            margin: 0;
        }
        .btn-primary {
            background: #3B82F6;
            color: #fff;
        }
        .btn-primary:hover {
            background: #2563EB;
        }
        @media (max-width: 768px) {
            .track-container {
                padding: 1rem;
            }
            .search-form {
                flex-direction: column;
            }
            .search-form button {
                width: 100%;
            }
            .orders-card-header {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }
        }
    </style>
</head>
<body class="track-page">
    <?php include __DIR__ . '/../template/navbar.php'; ?>

    <div class="track-container">
        <div class="track-header">
            <h1><i class="fas fa-search"></i> Track Your Order</h1>
            <p>Enter your order code or name to track your order status</p>
        </div>

        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Enter Order Code or Customer Name" value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
        </form>

        <div class="orders-card">
            <div class="orders-card-header">
                <h2><i class="fas fa-list"></i> <?= $search ? 'Search Results' : 'Recent Orders' ?></h2>
                <span class="order-count"><?= count($orders) ?> orders</span>
            </div>
            <div class="table-responsive">
                <?php if (!empty($orders)): ?>
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <?php foreach($orders as $order): 
                            $statusClass = '';
                            switch($order['status']) {
                                case 'pending': $statusClass = 'status-pending'; break;
                                case 'preparing': $statusClass = 'status-processing'; break;
                                case 'ready': $statusClass = 'status-ready'; break;
                                case 'completed': $statusClass = 'status-completed'; break;
                                default: $statusClass = 'status-pending';
                            }
                        ?>
                        <tr data-order-id="<?= $order['id'] ?>">
                            <td><span class="order-code-cell"><?= htmlspecialchars($order['order_code']); ?></span></td>
                            <td><?= htmlspecialchars($order['customer_name']); ?></td>
                            <td class="items-cell" title="<?= htmlspecialchars($order['items'] ?? 'No items'); ?>">
                                <?= htmlspecialchars($order['items'] ?? 'No items'); ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass; ?>">
                                    <?= htmlspecialchars($order['status']); ?>
                                </span>
                            </td>
                            <td><?= date('M d, h:i A', strtotime($order['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php elseif ($search): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <p>No orders found matching "<?= htmlspecialchars($search) ?>"</p>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No orders yet</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../assets/js/ajax.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function renderOrders(orders) {
                const tbody = document.getElementById('ordersTableBody');
                
                if (!orders || orders.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p>No orders found</p>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                const statusClasses = {
                    'pending': 'status-pending',
                    'preparing': 'status-processing',
                    'ready': 'status-ready',
                    'completed': 'status-completed'
                };

                tbody.innerHTML = orders.map(order => {
                    const statusClass = statusClasses[order.status] || 'status-pending';
                    const items = order.items || 'No items';
                    
                    return `
                        <tr data-order-id="${order.id}">
                            <td><span class="order-code-cell">${AJAX.formatText(order.order_code)}</span></td>
                            <td>${AJAX.formatText(order.customer_name)}</td>
                            <td class="items-cell" title="${AJAX.formatText(items)}" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${AJAX.formatText(items)}</td>
                            <td>
                                <span class="status-badge ${statusClass}">${order.status}</span>
                            </td>
                            <td>${AJAX.formatDateTime(order.created_at)}</td>
                        </tr>
                    `;
                }).join('');
            }

            let lastOrderCount = <?= count($orders) ?>;
            let lastOrderData = '<?= json_encode($orders) ?>';
            AJAX.setPublicMode(true);
            
            AJAX.startAutoRefresh(async function(result) {
                if (result.success && result.orders) {
                    const currentCount = document.querySelectorAll('#ordersTableBody tr').length;
                    const currentData = JSON.stringify(result.orders);
                    // Update if order count changed OR order data changed (status updates)
                    if ((result.orders.length !== currentCount || currentData !== lastOrderData) && 
                        !document.querySelector('input[name="search"]').value) {
                        renderOrders(result.orders);
                        document.querySelector('.order-count').textContent = result.orders.length + ' orders';
                        lastOrderData = currentData; // Update our stored data
                    }
                }
            }, 3000);
        });
    </script>
</body>
</html>