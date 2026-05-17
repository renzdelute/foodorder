<?php
header('Content-Type: application/json');

require '../../includes/helpers.php';
require '../../config/app.php';

requireAdmin();

global $conn;

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS mqtt_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_incoming TINYINT(1) DEFAULT 0,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['topic']) || !isset($data['message'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$topic = $conn->real_escape_string($data['topic']);
$message = $conn->real_escape_string(json_encode($data['message']));

$result = $conn->query("INSERT INTO mqtt_history (topic, message) VALUES ('$topic', '$message')");

if ($result) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>
