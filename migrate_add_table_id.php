<?php
require_once __DIR__ . '/config/database.php';

// Check if table_id column exists in orders table
$check = mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE 'table_id'");
if (!$check || mysqli_num_rows($check) == 0) {
    // Column doesn't exist, add it
    if (mysqli_query($conn, "ALTER TABLE orders ADD COLUMN table_id INT NULL AFTER user_id")) {
        echo "Successfully added table_id column to orders table.\n";
    } else {
        echo "Error adding table_id column: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "table_id column already exists in orders table.\n";
}

// Close connection
mysqli_close($conn);
?>