<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

function generateItemCode() {
    try {
        return 'ITEM-' . strtoupper(bin2hex(random_bytes(4)));
    } catch (Exception $e) {
        return 'ITEM-' . strtoupper(uniqid());
    }
}

if (!$conn) {
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

$item_name = post('item_name');
$category_name = post('category_name');
$price = post('price');
$is_available = postInt('is_available', 1); 
$item_image = $_FILES['item_image'] ?? null;

$error = [];

if(empty($item_name)){
    $error[] = 'Item name is required';
}

if(empty($category_name)){
    $error[] = 'Category name is required';
}

if(empty($price)){
    $error[] = 'Price is required';
}

if(!$item_image || $item_image['error'] === UPLOAD_ERR_NO_FILE){
    $error[] = 'Item Image is required';
} elseif ($item_image['error'] !== UPLOAD_ERR_OK) {
    $error[] = 'Failed to upload image';
}

if (!empty($error)) {
    echo json_encode([
        'success' => false,
        'message' => implode(', ', $error)
    ]);
    exit;
}

$check_sql = "SELECT id, item_name FROM food_items WHERE item_name = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);

if (!$check_stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare duplicate check: ' . $conn->error
    ]);
    exit;
}

$check_stmt->bind_param('s', $item_name);
if (!$check_stmt->execute()) {
    $checkError = $check_stmt->error;
    $check_stmt->close();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to check item name: ' . $checkError
    ]);
    exit;
}

$check_result = $check_stmt->get_result();

if($check_result->num_rows > 0){
    $check_stmt->close();
    echo json_encode([
        'success' => false, 
        'message' => 'Item Already Exists'
    ]);
    exit;
}

$check_stmt->close();

$item_code = generateItemCode();

//image

$uploadDir = dirname(__DIR__) . '/assets/uploads/items';

if(!is_dir($uploadDir)){
    mkdir($uploadDir, 0777, true);
}

$ext = strtolower(pathinfo($item_image['name'], PATHINFO_EXTENSION));
$allowedExt = ['jpg', 'webp', 'png', 'gif', 'jpeg'];

if(!in_array($ext, $allowedExt, true)){
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Image Type'
    ]);
    exit;
}

$fileName = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

if(!move_uploaded_file($item_image['tmp_name'], $targetPath)){
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save uploaded image'
    ]);
    exit;
}

$projectFolder = basename(str_replace('\\', '/', dirname(__DIR__)));
$image_url = '/' . $projectFolder . '/assets/uploads/items/' . $fileName;

$insert_sql = "INSERT INTO food_items (item_code, item_name, category, price, image_url, is_available) VALUES (?, ?, ?, ?, ?, ?)";
$insert_stmt = $conn->prepare($insert_sql);
if (!$insert_stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare insert: ' . $conn->error
    ]);
    exit;
}

$price_value = (float) $price;
$insert_stmt->bind_param('sssdsi', $item_code, $item_name, $category_name, $price_value, $image_url, $is_available);

if($insert_stmt->execute()){
    $item_id = $conn->insert_id;
    $insert_stmt->close();
    echo json_encode([
        'success' => true, 
        'message' => 'Item Added Successfully',
        'item' => [
            'id' => $item_id,
            'item_code' => $item_code,
            'item_name' => $item_name,
            'category' => $category_name,
            'price' => $price_value,
            'image_url' => $image_url,
            'is_available' => $is_available
        ]
    ]);
} else {
    $insertError = $insert_stmt->error;
    $insert_stmt->close();
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to add item: ' . $insertError
    ]);
}
?>



