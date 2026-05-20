<nav class="navbar">
    <div class="nav-container1">
        <div class="logo-header">
            <h2>FoodPulse</h2>
        </div>

        <div class="dropdown">
            <button class="hamburger-icon" id="toggleMenu">
                ☰
            </button>
        
            <ul class="nav-links" id="dropdownMenu">
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
    </div>
</nav>

<script>
    const toggleBtn = document.getElementById('toggleMenu');
    const dropdownMenu = document.getElementById('dropdownMenu');

    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdownMenu.classList.toggle('show');
    });

    window.addEventListener('click', () => {
        dropdownMenu.classList.remove('show');
    });
</script>