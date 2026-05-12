<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if(!$conn){
    die("Database connection failed: " . mysqli_connect_error());
}

// Get order ID from URL
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId <= 0) {
    die("Invalid order ID");
}

// Fetch order details
$orderQuery = "
    SELECT o.*, u.name AS customer_name 
    FROM orders o 
    LEFT JOIN users u ON o.user_id = u.id 
    WHERE o.id = ?
";
$orderStmt = $conn->prepare($orderQuery);
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();
$orderStmt->close();

if (!$order) {
    die("Order not found");
}

// Fetch order items
$itemsQuery = "SELECT * FROM order_items WHERE order_id = ?";
$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param('i', $orderId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$orderItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Set content type and disable caching for real-time updates
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Display - Order #<?= htmlspecialchars($order['order_code']) ?></title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <link rel="stylesheet" href="../../assets/css/components.css">
    <link rel="stylesheet" href="../../assets/css/responsive.css">
    <style>
        body.kitchen-display {
            background: #0f172a; /* dark blue-gray */
            color: #f8fafc; /* light gray */
            margin: 0;
            padding: 0;
            height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .kitchen-container {
            padding: 2rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .kitchen-header {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 2px solid #334155;
            padding-bottom: 1rem;
        }
        
        .kitchen-header h1 {
            color: #fbbf24; /* amber */
            font-size: 2.5rem;
            margin: 0;
        }
        
        .order-info {
            background: #1e293b; /* slate 800 */
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }
        
        .order-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #334155;
        }
        
        .order-info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }
        
        .order-info-label {
            font-weight: 600;
            color: #94a3b8; /* slate 400 */
        }
        
        .order-info-value {
            font-weight: 500;
            color: #f8fafc;
        }
        
        .order-code {
            font-size: 1.8rem;
            font-weight: bold;
            color: #fbbf24; /* amber */
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        .customer-name {
            font-size: 1.2rem;
            text-align: center;
            color: #60a5fa; /* blue 400 */
            margin-bottom: 1rem;
        }
        
        .items-section {
            flex-grow: 1;
            overflow-y: auto;
        }
        
        .items-section h2 {
            color: #fbbf24; /* amber */
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        
        .items-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: #94a3b8; /* slate 400 */
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .items-table td {
            padding: 0.75rem 1rem;
            background: #334155; /* slate 700 */
            border-radius: 8px;
            color: #f8fafc;
        }
        
        .item-name {
            font-weight: 600;
        }
        
        .item-quantity {
            color: #60a5fa; /* blue 400 */
            font-weight: 500;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background: #fef3c7; /* amber 50 */
            color: #92400e; /* amber 800 */
        }
        
        .status-preparing {
            background: #dbeafe; /* blue 50 */
            color: #1e40af; /* blue 800 */
        }
        
        .status-ready {
            background: #d1fae5; /* emerald 50 */
            color: #065f46; /* emerald 800 */
        }
        
        .status-completed {
            background: #e5e7eb; /* gray 200 */
            color: #6b7280; /* gray 500 */
        }
        
        .refresh-indicator {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background: rgba(0, 0, 0, 0.5);
            color: #f8fafc;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .kitchen-container {
                padding: 1rem;
            }
            
            .kitchen-header h1 {
                font-size: 2rem;
            }
            
            .order-info {
                padding: 1rem;
            }
        }
    </style>
</head>
<body class="kitchen-display">
    <div class="kitchen-container">
        <div class="kitchen-header">
            <h1><i class="fas fa-utensils"></i> Kitchen Display</h1>
        </div>
        
        <div class="order-info">
            <div class="order-code">Order #<?= htmlspecialchars($order['order_code']) ?></div>
            <div class="customer-name"><?= htmlspecialchars($order['customer_name'] ?? 'Guest') ?></div>
            
            <div class="order-info-item">
                <span class="order-info-label">Status:</span>
                <span class="order-info-value">
                    <span class="status-badge status-<?= htmlspecialchars($order['status']) ?>">
                        <?= ucfirst(htmlspecialchars($order['status'])) ?>
                    </span>
                </span>
            </div>
            
            <div class="order-info-item">
                <span class="order-info-label">Time:</span>
                <span class="order-info-value"><?= date('M d, h:i A', strtotime($order['created_at'])) ?></span>
            </div>
            
            <?php if ($order['table_id']): ?>
            <div class="order-info-item">
                <span class="order-info-label">Table:</span>
                <span class="order-info-value">#<?= htmlspecialchars($order['table_id']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="items-section">
            <h2><i class="fas fa-list"></i> Order Items</h2>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($orderItems as $item): ?>
                    <tr>
                        <td class="item-name"><?= htmlspecialchars($item['item_name']) ?></td>
                        <td class="item-quantity"><?= htmlspecialchars($item['quantity']) ?>x</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="refresh-indicator">
        Updating...
    </div>
    
    <script>
        // Auto-refresh every 5 seconds to get latest order status
        setInterval(function() {
            // In a real implementation, this would fetch updated order status via AJAX
            // For now, we'll just reload the page to simulate real-time updates
            window.location.reload();
        }, 5000);
        
        // Add current time to refresh indicator
        document.querySelector('.refresh-indicator').textContent = 
            'Last updated: ' + new Date().toLocaleTimeString();
    </script>
</body>
</html>