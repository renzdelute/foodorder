<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if(!$conn){
    die("Database connection failed: " . mysqli_connect_error());
}

// Get table ID from URL
$tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;

if ($tableId <= 0) {
    die("Invalid table ID");
}

// Store table ID in session for this ordering session
$_SESSION['table_id'] = $tableId;
$_SESSION['ordering_as_guest'] = true;

// Redirect to menu
header('Location: menu.php');
exit;
?>