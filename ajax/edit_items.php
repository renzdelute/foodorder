<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

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

$item_id = post('item_id');
$item_name = post('item_name');
$category_name = post('category_name');
$price = post('price');
$is_available = postInt('is_available', 1);

$error = [];

if(empty($item_id)){
    $error[] = 'Item ID is required';
}

if(empty($item_name)){
    $error[] = 'Item name is required';
}

if(empty($category_name)){
    $error[] = 'Category name is required';
}

if(empty($price)){
    $error[] = 'Price is required';
}

if (!empty($error)) {
    echo json_encode([
        'success' => false,
        'message' => implode(', ', $error)
    ]);
    exit;
}

// check if item exists
$check_sql = "SELECT id FROM food_items WHERE id = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare item check: ' . $conn->error
    ]);
    exit;
}

$check_stmt->bind_param('i', $item_id);
if (!$check_stmt->execute()) {
    $checkError = $check_stmt->error;
    $check_stmt->close();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to check item: ' . $checkError
    ]);
    exit;
}

$check_result = $check_stmt->get_result();

if($check_result->num_rows == 0){
    $check_stmt->close();
    echo json_encode([
        'success' => false, 
        'message' => 'Item not found'
    ]);
    exit;
}
$check_stmt->close();

$duplicate_sql = "SELECT id FROM food_items WHERE item_name = ? AND id != ? LIMIT 1";
$duplicate_stmt = $conn->prepare($duplicate_sql);
if (!$duplicate_stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare duplicate check: ' . $conn->error
    ]);
    exit;
}

$duplicate_stmt->bind_param('si', $item_name, $item_id);
if (!$duplicate_stmt->execute()) {
    $dupError = $duplicate_stmt->error;
    $duplicate_stmt->close();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to check duplicate item: ' . $dupError
    ]);
    exit;
}

$duplicate_result = $duplicate_stmt->get_result();

if($duplicate_result->num_rows > 0){
    $duplicate_stmt->close();
    echo json_encode([
        'success' => false, 
        'message' => 'Item Already Exists'
    ]);
    exit;
}

$duplicate_stmt->close();

$update_sql = "UPDATE food_items SET item_name = ?, category = ?, price = ?, is_available = ? WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
if (!$update_stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare update: ' . $conn->error
    ]);
    exit;
}

$price_value = (float) $price;
$update_stmt->bind_param('ssdii', $item_name, $category_name, $price_value, $is_available, $item_id);

if($update_stmt->execute()){
    $update_stmt->close();
    echo json_encode([
        'success' => true, 
        'message' => 'Item Updated Successfully',
        'item' => [
            'id' => $item_id,
            'item_name' => $item_name,
            'category' => $category_name,
            'price' => $price_value,
            'is_available' => $is_available
        ]
    ]);
} else {
    $updateError = $update_stmt->error;
    $update_stmt->close();
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update item: ' . $updateError
    ]);
}
?>