<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/MqttClient.php';

header('Content-Type: application/json');

if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    echo json_encode([
        'success' => false, 'error' => 'Invalid Request'
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$item_id = isset($data['id']) ? (int)$data['id'] : 0;

if(!$item_id){
    $item_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if(!$item_id){
    echo json_encode([
        'success' => false, 'error' => 'Invalid Item'
    ]);
    exit;
}

$check_sql = 'SELECT id, item_name FROM food_items WHERE id = ?';
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param('i', $item_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if($check_result->num_rows === 0){
    echo json_encode(['success' => false, 'error' => 'Item not found']);
    exit;
}

$item = $check_result->fetch_assoc();
$check_stmt->close();

$delete_sql = "DELETE FROM food_items WHERE id = ?";
$delete_sql = $conn->prepare($delete_sql);
$delete_sql->bind_param('i', $item_id);

if($delete_sql->execute()){
    $delete_sql->close();

    // Publish MQTT menu update event
    $mqtt = new MqttService();
    if ($mqtt->connect()) {
        $mqtt->publishAdminEvent('menu_item_deleted', [
            'item_id' => $item_id,
            'item_name' => $item['item_name'],
            'deleted_by' => 'admin'
        ]);
        $mqtt->disconnect();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Item deleted successfully',
        'item_id' => $item_id,
        'item_name' => $item['item_name']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete item']);
}
?>
