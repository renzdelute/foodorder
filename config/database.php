<?php
require_once __DIR__ . '/bootstrap.php';

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'food_order_system';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($servername, $username, $password, $dbname);

if (!$conn) {
    die("Connection Failed: " . mysqli_connect_error());
}
?>
