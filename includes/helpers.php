<?php
function redirect($path) {
    header("Location: $path");
    exit;
}

function authRoles(){
    if (!isset($_SESSION['auth_roles']) || !is_array($_SESSION['auth_roles'])) {
        return [];
    }

    return $_SESSION['auth_roles'];
}

function authRole($role){
    $roles = authRoles();
    return $roles[$role] ?? null;
}

function authUserId($role){
    $auth = authRole($role);
    return (int)($auth['user_id'] ?? 0);
}

function authUserName($role){
    $auth = authRole($role);
    return $auth['name'] ?? null;
}

function authUserEmail($role){
    $auth = authRole($role);
    return $auth['email'] ?? null;
}

function syncActiveAuthRole($role){
    $auth = authRole($role);

    if (!$auth) {
        return false;
    }

    $_SESSION['user_id'] = (int)($auth['user_id'] ?? 0);
    $_SESSION['role'] = $auth['role'] ?? $role;
    $_SESSION['name'] = $auth['name'] ?? '';

    return true;
}

function isLoggedIn(){
    return !empty(authRoles());
}

function requireLogIn(){
    if(!isLoggedIn()){
        redirect('login.php');
    }
}

function requireAdmin(){
    if(!authRole('admin')){
        redirect('../login.php');
    }

    syncActiveAuthRole('admin');
}
function hasRole($role){
    if(is_array($role)){
        foreach ($role as $singleRole) {
            if (authRole($singleRole)) {
                return true;
            }
        }

        return false;
    }

    return (bool) authRole($role);
}

function resolveRole($role){
    $roles = is_array($role) ? $role : [$role];
    $currentRole = $_SESSION['role'] ?? null;

    if ($currentRole && in_array($currentRole, $roles, true) && authRole($currentRole)) {
        return $currentRole;
    }

    foreach ($roles as $singleRole) {
        if (authRole($singleRole)) {
            return $singleRole;
        }
    }

    return null;
}

function requiredRole($role, $redirectPath = 'login.php'){
    $matchedRole = resolveRole($role);

    if(!$matchedRole){
        redirect($redirectPath);
    }

    syncActiveAuthRole($matchedRole);
}

function post($key, $default = null){
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function postInt($key, $default = null){
    return isset($_POST[$key]) ? (int) trim($_POST[$key]) : $default;
}

function setFlash($message, $type = 'success'){
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type
        ];
}

function getFlash(){
    if(!isset($_SESSION['flash'])){
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function handleErrors($errors, $redirectPath) {
        if(!empty($errors)){
            foreach ($errors as $error){
                setFlash($error, 'error');
            }
            redirect($redirectPath);
        }
}

function countTable($conn, $table){
    $sql = "SELECT COUNT(*) as count FROM $table";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return (int)($row['count'] ?? 0);
}
function getAll($conn, $sql){
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return [];
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }

    return $data;
}

function totalRevenue($conn, $table){
    $sql = "SELECT SUM(total_amount) AS total_revenue from $table WHERE status = 'completed'";
    $result = mysqli_query($conn, $sql);

    if(!$result){
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return $row['total_revenue'] ?? 0;
}

function e($value){
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function countFoodItems($conn, $condition = ''){
    $sql = "SELECT COUNT(*) as count FROM food_items" . ($condition ? " WHERE $condition" : "");
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return (int)($row['count'] ?? 0);
}
?>
