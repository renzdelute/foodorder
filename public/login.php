<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_once '../includes/validators/userValidator.php';
require_once '../includes/validators/loginValidator.php';

if($_SERVER['REQUEST_METHOD'] === 'POST'){  
    $username = post('username');
    $password = post('password');

    $errors = validateLogin($username, $password);

    handleErrors($errors, "login.php");

    if(!login($username, $password)){
        setFlash('Invalid username or password', 'error');
        redirect('login.php');
    }

    switch ($_SESSION['role']) {
        case 'admin':
            redirect('admin/dashboard.php');
            break;
        case 'staff':
            redirect('kitchen/index.php');
            break;
        case 'customer':
            redirect('customer/index.php');
            break;
        default:
            redirect('index.php');
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FoodPulse</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-header">
                <h2>Welcome Back</h2>
                <p>Sign in to continue</p>
            </div>
    
            <form action="login.php" method="post" class="auth-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-auth">Sign In</button>
            </form>

            <div class="auth-footer">
                <p>Don't have an account? <a href="register.php">Create one</a></p>
            </div>

            <?php include '../template/alerts.php'; ?>

            <div class="back-home">
                <a href="index.php">Back to homepage</a>
            </div>
        </div>
    </div>
</body>
</html>
