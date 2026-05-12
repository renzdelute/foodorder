<?php
session_start();
require_once '../../config/database.php';
require_once '../helpers.php';
require_once '../../config/MqttClient.php';

global $conn;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = postInt('id');

    if ($id > 0) {
        $orderCheck = $conn->prepare("SELECT order_code, user_id FROM orders WHERE id = ?");
        $orderCheck->bind_param("i", $id);
        $orderCheck->execute();
        $orderResult = $orderCheck->get_result()->fetch_assoc();
        $orderCheck->close();
        $orderCode = $orderResult['order_code'] ?? '';
        $userId = (int)($orderResult['user_id'] ?? 0);

        $stmt = $conn->prepare('DELETE FROM order_status_logs WHERE order_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM order_items WHERE order_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM orders WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        if ($orderCode) {
            // Use new MqttService for publishing
            $mqtt = new MqttService();
            if ($mqtt->connect()) {
                $mqtt->publishOrderEvent($orderCode, 'deleted', 'admin', $userId, $id);
                $mqtt->disconnect();
            }
        }
    }

    header('Location: ../../public/admin/dashboard.php');
    exit;
}
?>