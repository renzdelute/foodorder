<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

$public = isset($_GET['public']) && $_GET['public'] === '1';
$context = strtolower(trim($_GET['context'] ?? ''));

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$valid_statuses = ['all', 'pending', 'preparing', 'ready', 'completed'];

if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

$stmt = null;

if ($public) {
     $sql = "SELECT 
                 orders.*,
                 COALESCE(users.name, 'Guest Order') AS customer_name,
                 GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
             FROM orders
             LEFT JOIN users ON orders.user_id = users.id
             LEFT JOIN order_items ON orders.id = order_items.order_id";
    
    if ($status_filter !== 'all') {
        $sql .= " WHERE orders.status = ?";
    }
    
    $sql .= " GROUP BY orders.id ORDER BY orders.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($status_filter !== 'all') {
        $stmt->bind_param("s", $status_filter);
    }
} elseif ($context === 'customer') {
    if (!hasRole('customer')) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $user_id = authUserId('customer');
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

     $sql = "SELECT 
                 orders.*,
                 GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
             FROM orders
             LEFT JOIN order_items ON orders.id = order_items.order_id
             WHERE orders.user_id = ?";
    
    if ($status_filter !== 'all') {
        $sql .= " AND orders.status = ?";
    }
    
    $sql .= " GROUP BY orders.id ORDER BY orders.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($status_filter !== 'all') {
        $stmt->bind_param("is", $user_id, $status_filter);
    } else {
        $stmt->bind_param("i", $user_id);
    }
} elseif (in_array($context, ['admin', 'staff'], true)) {
    if (!hasRole(['admin', 'staff'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

     $sql = "SELECT 
                 orders.*,
                 COALESCE(users.name, 'Guest Order') AS customer_name,
                 GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
             FROM orders
             LEFT JOIN users ON orders.user_id = users.id
             LEFT JOIN order_items ON orders.id = order_items.order_id";
    
    if ($status_filter !== 'all') {
        $sql .= " WHERE orders.status = ?";
    }
    
    $sql .= " GROUP BY orders.id ORDER BY orders.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($status_filter !== 'all') {
        $stmt->bind_param("s", $status_filter);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ['pending' => 0, 'preparing' => 0, 'ready' => 0, 'completed' => 0];
foreach ($orders as $o) {
    if (isset($counts[$o['status']])) {
        $counts[$o['status']]++;
    }
}

$response = [
    'success' => true,
    'orders' => $orders,
    'counts' => $counts,
    'timestamp' => time()
];

echo json_encode($response);
