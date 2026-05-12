<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/MqttClient.php';
require_once __DIR__ . '/../includes/QrCode.php';

header('Content-Type: application/json');

if(!$conn){
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . mysqli_connect_error()
    ]);
    exit; 
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid Request'
    ]);
    exit;
}

// Check if user is logged in as customer or if table_id is provided (for table ordering)
$isCustomer = hasRole('customer');
$tableId = isset($_POST['table_id']) ? (int)$_POST['table_id'] : null;

// For table ordering, we don't require customer login
if (!$isCustomer && !$tableId) {
    echo json_encode([
        'success' => false,
        'message' => 'Please log in to place an order or provide a table ID'
    ]);
    exit;
}

$user_id = $isCustomer ? authUserId('customer') : 0; // 0 for table orders (guest)

// Process form data
$items = [];
$total_amount = 0;

// Get all available items to validate quantities
$foodItems = getAll($conn, "SELECT * FROM food_items WHERE is_available = 1");

foreach ($foodItems as $item) {
    $qty_key = 'qty_' . $item['id'];
    if (isset($_POST[$qty_key]) && is_numeric($_POST[$qty_key]) && (int)$_POST[$qty_key] > 0) {
        $quantity = (int)$_POST[$qty_key];
        $items[] = [
            'name' => $item['item_name'],
            'quantity' => $quantity,
            'price' => (float)$item['price']
        ];
        $total_amount += $quantity * (float)$item['price'];
    }
}

// Check if any items were selected
if (empty($items)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please select at least one item'
    ]);
    exit;
}

// Generate order code
$order_code = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));

// Start transaction
$conn->autocommit(FALSE);

try {
    // Insert order
    $insert_order_sql = "INSERT INTO orders (user_id, table_id, order_code, total_amount, status) VALUES (?, ?, ?, ?, 'pending')";
    $insert_order_stmt = $conn->prepare($insert_order_sql);
    if (!$insert_order_stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }
    
     $insert_order_stmt->bind_param('iisd', $user_id, $tableId, $order_code, $total_amount);
    
    if(!$insert_order_stmt->execute()){
        throw new Exception('Failed to insert order: ' . $insert_order_stmt->error);
    }
    
    $order_id = $conn->insert_id;
    $insert_order_stmt->close();
    
    // Insert order items
    $insert_item_sql = "INSERT INTO order_items (order_id, item_name, quantity, price) VALUES (?, ?, ?, ?)";
    $insert_item_stmt = $conn->prepare($insert_item_sql);
    if (!$insert_item_stmt) {
        throw new Exception('Failed to prepare items statement: ' . $conn->error);
    }
    
    foreach ($items as $item) {
        $insert_item_stmt->bind_param('isid', $order_id, $item['name'], $item['quantity'], $item['price']);
        
        if(!$insert_item_stmt->execute()){
            throw new Exception('Failed to insert order item: ' . $insert_item_stmt->error);
        }
    }
    
    $insert_item_stmt->close();

    // Commit transaction
    $conn->commit();

    // Generate QR codes
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}";

    // Table QR code (if table_id provided)
    $tableQrCode = null;
    if ($tableId) {
        $tableQrCode = QrCodeGenerator::generateTableQrCode($tableId, $baseUrl);
    }

    // Kitchen QR code for this order
    $kitchenQrCode = QrCodeGenerator::generateKitchenQrCode($order_id, $baseUrl);

    // Publish order event via MQTT
    $customerName = $isCustomer ? authUserName('customer') : 'Table Guest';
    $itemsList = array_map(function($item) {
        return ['name' => $item['name'], 'quantity' => $item['quantity'], 'price' => $item['price']];
    }, $items);

    $mqtt = new MqttService();
    if ($mqtt->connect()) {
        $mqtt->publishNewOrder($order_code, $customerName, $itemsList, $total_amount, $user_id);

        // Also publish order status event
        $mqtt->publishOrderEvent($order_code, 'pending', $customerName, $user_id, $order_id);

        // Publish kitchen status update
        $counts = getOrderCounts($conn);
        $mqtt->publishKitchenStatus(
            'pending',
            $counts['pending'],
            $counts['preparing'],
            $counts['ready'],
            $counts['completed']
        );

        $mqtt->disconnect();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Order placed successfully',
        'order_id' => $order_id,
        'order_code' => $order_code,
        'table_qr_code' => $tableQrCode,
        'kitchen_qr_code' => $kitchenQrCode
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();

    echo json_encode([
        'success' => false,
        'message' => 'Error placing order: ' . $e->getMessage()
    ]);
}

function getOrderCounts($conn): array
{
    $counts = ['pending' => 0, 'preparing' => 0, 'ready' => 0, 'completed' => 0];
    $result = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['cnt'];
            }
        }
    }
    return $counts;
}

// Close connection
$conn->close();
?>
