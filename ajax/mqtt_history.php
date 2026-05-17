<?php
header('Content-Type: application/json');

require '../../includes/helpers.php';
require '../../config/app.php';

requireAdmin();

global $conn;

// Handle clear action
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    $conn->query("TRUNCATE TABLE mqtt_history");
    echo json_encode(['success' => true]);
    exit;
}

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS mqtt_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_incoming TINYINT(1) DEFAULT 0,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

$result = $conn->query("SELECT * FROM mqtt_history ORDER BY timestamp DESC, id DESC LIMIT $limit OFFSET $offset");
$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

echo json_encode([
    'success' => true,
    'history' => $history
]);
?>
