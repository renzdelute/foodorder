<?php
require_once '../../includes/helpers.php';

?>

<nav class="navbar" id="navbar">
    <div class="nav-container">
    
        <div class="header-text"> 
            <h1>Food Pulse Admin Panel</h1>
        </div>

        <ul class="nav-links">
            <p>Welcome, <?php echo $_SESSION['name'] ?>!</p>
            <input type="hidden" name="role" value="admin">
        </ul>

        <button class="hamburger-icon" onclick="onToggle()">
            ☰
        </button>
    </div>
</nav>

<div class="container1">
    <aside id="sidebar" class="sidebar">
        <ul class="sidebar-menu">
            <li><a href="../admin/dashboard.php">Dashboard</a></li>
            <li><a href="../admin/add_items.php">Add Items</a></li>
            <li><a href="../admin/add_items.php">User Management</a></li>
            
            <li class="logout-item">
            <form action="../../includes/logout.php" method="post">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
            </li>
        </ul>
    </aside>
</div>

<script>
    function onToggle() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) sidebar.classList.toggle('active');
    }
</script>
