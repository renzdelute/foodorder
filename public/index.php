<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$orders = [];

if ($search) {
    $stmt = $conn->prepare("
        SELECT 
            orders.*,
            users.name AS customer_name,
            GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
        FROM orders
        JOIN users ON orders.user_id = users.id
        LEFT JOIN order_items ON orders.id = order_items.order_id
        WHERE orders.order_code = ? OR users.name LIKE ?
        GROUP BY orders.id
        ORDER BY orders.created_at DESC
        LIMIT 10
    ");
    $searchParam = '%' . $search . '%';
    $stmt->bind_param("ss", $search, $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = mysqli_query($conn, "
        SELECT 
            orders.*,
            users.name AS customer_name,
            GROUP_CONCAT(CONCAT(order_items.item_name, ' (x', order_items.quantity, ')') SEPARATOR ', ') AS items
        FROM orders
        JOIN users ON orders.user_id = users.id
        LEFT JOIN order_items ON orders.id = order_items.order_id
        GROUP BY orders.id
        ORDER BY orders.created_at DESC
        LIMIT 20
    ");
    $orders = mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Get popular menu items for showcase
$popularItems = [];
$popularResult = mysqli_query($conn, "SELECT * FROM food_items WHERE is_available = 1 ORDER BY category, item_name LIMIT 8");
if ($popularResult) {
    while ($row = mysqli_fetch_assoc($popularResult)) {
        $popularItems[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>FoodPulse - Home</title>
    <link rel="stylesheet" href="../assets/css/main.css?v=2">
    <link rel="stylesheet" href="../assets/css/base.css?v=2">
    <link rel="stylesheet" href="../assets/css/components.css?v=2">
    <link rel="stylesheet" href="../assets/css/responsive.css?v=2">
    <link rel="stylesheet" href="../assets/css/toast.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body.home-page {
            margin: 0;
            padding: 0;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f172a;
            color: #1e293b;
            overflow-x: hidden;
        }

        /* ============ HERO ============ */
        /* Override main.css .hero and .hero-section */
        .hero, .hero-section, .container {
            min-height: auto !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
        }

        /* Navbar override for homepage */
        .navbar {
            background: rgba(15, 23, 42, 0.98) !important;
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
            padding: 14px 24px !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            max-width: 100vw !important;
            overflow: visible !important;
            border-bottom: 2px solid rgba(16, 185, 129, 0.3) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3) !important;
        }
        .nav-container1 {
            width: 100% !important;
            max-width: 100% !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-top: 0 !important;
        }
        .logo-header { flex-shrink: 0; }
        .dropdown { margin-left: auto !important; position: relative !important; right: auto !important; padding: 0 !important; }
        .navbar h2 { color: #fff !important; font-size: 1.3rem; margin: 0 !important; }
        .nav-links { list-style: none; margin: 0; padding: 0; display: flex !important; gap: 24px !important; align-items: center !important; }
        .nav-links a { color: #cbd5e1 !important; font-weight: 600; transition: color 0.2s; }
        .nav-links a:hover { color: #10B981 !important; }
        .hamburger-icon {
            color: #fff !important;
            background: rgba(255,255,255,0.1) !important;
            border: 1px solid rgba(255,255,255,0.2) !important;
            border-radius: 8px;
            font-size: 1.2rem;
            cursor: pointer;
            display: none;
        }

        @media (max-width: 768px) {
            .navbar { padding: 12px 16px !important; }
            .nav-container1 { flex-wrap: nowrap !important; margin-top: 0 !important; }
            .dropdown { right: auto !important; padding: 0 !important; }
            .hamburger-icon {
                display: block !important;
                position: static !important;
                z-index: 1200;
            }
            .nav-links {
                display: none !important;
                flex-direction: column !important;
                gap: 0 !important;
                background: rgba(15,23,42,0.98) !important;
                min-width: 180px;
                position: fixed !important;
                top: 52px !important;
                right: 16px !important;
                left: auto !important;
                z-index: 99999 !important;
                box-shadow: 0 8px 24px rgba(0,0,0,0.3);
                padding: 12px; border-radius: 12px;
                border: 1px solid rgba(255,255,255,0.1);
            }
            .nav-links.show {
                display: flex !important;
                opacity: 1 !important;
                visibility: visible !important;
            }
            .nav-links li { list-style: none; }
            .nav-links li a { padding: 12px 16px; display: block; white-space: nowrap; color: #e2e8f0 !important; }
        }
        .hero-section-home {
            background: linear-gradient(135deg, rgba(15,23,42,0.92) 0%, rgba(30,41,59,0.88) 50%, rgba(15,23,42,0.95) 100%),
                        url('../assets/images/food.jpg') center/cover no-repeat fixed;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: #fff;
            padding: 8rem 2rem 5rem;
            min-height: 80vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
        }
        .hero-section-home > * { position: relative; z-index: 2; }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #6ee7b7;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            letter-spacing: 0.3px;
        }
        .hero-badge i { font-size: 0.75rem; }
        .hero-section-home h1 {
            font-size: 3.5rem;
            font-weight: 900;
            margin: 0 0 1rem;
            line-height: 1.1;
            letter-spacing: -1px;
        }
        .hero-section-home h1 span { color: #10B981; }
        .hero-section-home p {
            font-size: 1.2rem;
            color: #94a3b8;
            margin: 0 0 2.5rem;
            max-width: 560px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        .hero-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .hero-buttons a {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 16px 36px;
            border-radius: 14px;
            font-size: 1.05rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .hero-buttons a:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.3);
        }
        .btn-order {
            background: linear-gradient(135deg, #10B981, #059669);
            color: #fff;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.4);
        }
        .btn-order:hover { background: linear-gradient(135deg, #059669, #047857); }
        .btn-track {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 2px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
        }
        .btn-track:hover {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.5);
        }

        /* ============ HOW IT WORKS ============ */
        .how-section {
            padding: 5rem 2rem;
            background: linear-gradient(rgba(15,23,42,0.88), rgba(15,23,42,0.92)), url('../assets/images/food.jpg') center/cover no-repeat fixed;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .section-container {
            max-width: 1100px;
            margin: 0 auto;
        }
        .section-title {
            text-align: center;
            margin-bottom: 50px;
        }
        .section-title h2 {
            font-size: 2rem;
            font-weight: 800;
            color: #fff;
            margin: 0 0 8px;
        }
        .section-title p {
            color: #94a3b8;
            font-size: 1.05rem;
            margin: 0;
        }
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 32px;
            position: relative;
        }
        .step-card {
            text-align: center;
            padding: 36px 24px;
            border-radius: 20px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 24px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            position: relative;
        }
        .step-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.08);
            border-color: #10B981;
        }
        .step-number {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, #10B981, #059669);
            color: #fff;
            font-size: 1.3rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        .step-card h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            margin: 0 0 8px;
        }
        .step-card p {
            color: #cbd5e1;
            font-size: 0.92rem;
            margin: 0;
            line-height: 1.5;
        }
        .step-icon {
            font-size: 2rem;
            color: #10B981;
            margin-bottom: 16px;
            display: block;
        }

        /* ============ POPULAR ITEMS ============ */
        .popular-section {
            padding: 5rem 2rem;
            background: linear-gradient(rgba(15,23,42,0.88), rgba(15,23,42,0.92)), url('../assets/images/food.jpg') center/cover no-repeat fixed;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .popular-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 24px;
        }
        .popular-card {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        .popular-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 48px rgba(0,0,0,0.1);
        }
        .popular-card-img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            display: block;
        }
        .popular-card-body {
            padding: 20px;
        }
        .popular-card-body h4 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
        }
        .popular-card-body .popular-category {
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .popular-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .popular-price {
            font-size: 1.2rem;
            font-weight: 800;
            color: #059669;
        }
        .popular-order-btn {
            padding: 8px 18px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, #3B82F6, #2563EB);
            color: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .popular-order-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        /* ============ TRACK SECTION ============ */
        .track-section {
            background: linear-gradient(rgba(15,23,42,0.88), rgba(15,23,42,0.92)), url('../assets/images/food.jpg') center/cover no-repeat fixed;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            padding: 5rem 2rem;
            position: relative;
        }
        .track-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: rgba(59, 130, 246, 0.08);
        }
        .track-inner {
            max-width: 680px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .track-inner h2 {
            margin: 0 0 8px;
            color: #fff;
            font-size: 1.8rem;
            font-weight: 800;
        }
        .track-inner > p {
            color: #94a3b8;
            margin: 0 0 2rem;
            font-size: 1rem;
        }
        .search-form {
            display: flex;
            gap: 12px;
            max-width: 540px;
            margin: 0 auto;
        }
        .search-form input {
            flex: 1;
            padding: 16px 24px;
            border: 2px solid rgba(255,255,255,0.15);
            border-radius: 14px;
            font-size: 16px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            outline: none;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        .search-form input::placeholder { color: #64748b; }
        .search-form input:focus {
            border-color: #3B82F6;
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 4px rgba(59,130,246,0.15);
        }
        .search-form button {
            padding: 16px 32px;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(135deg, #3B82F6, #2563EB);
            color: #fff;
            transition: all 0.3s;
        }
        .search-form button:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }

        /* ============ ORDERS SECTION ============ */
        .orders-section {
            padding: 5rem 2rem;
            background: linear-gradient(rgba(15,23,42,0.88), rgba(15,23,42,0.92)), url('../assets/images/food.jpg') center/cover no-repeat fixed;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .orders-inner {
            max-width: 1100px;
            margin: 0 auto;
        }
        .orders-section .section-title { margin-bottom: 32px; }
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .order-card {
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.15);
            padding: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 24px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .order-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        .order-card.status-pending-card::before { background: linear-gradient(90deg, #F59E0B, #D97706); }
        .order-card.status-preparing-card::before { background: linear-gradient(90deg, #3B82F6, #2563EB); }
        .order-card.status-ready-card::before { background: linear-gradient(90deg, #10B981, #059669); }
        .order-card.status-completed-card::before { background: linear-gradient(90deg, #6B7280, #9CA3AF); }
        .order-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.08);
        }
        .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .order-code-cell {
            font-weight: 800;
            color: #60a5fa;
            font-family: 'Inter', monospace;
            font-size: 1rem;
            letter-spacing: 0.5px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-badge i { font-size: 0.65rem; }
        .status-pending { background: #FEF3C7; color: #B45309; }
        .status-processing, .status-preparing { background: #DBEAFE; color: #1D4ED8; }
        .status-ready { background: #D1FAE5; color: #059669; }
        .status-completed { background: #F3F4F6; color: #6B7280; }
        .order-card-customer {
            font-size: 0.95rem;
            color: #e2e8f0;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .order-card-items {
            font-size: 0.88rem;
            color: #94a3b8;
            margin-bottom: 16px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .order-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .amount-cell {
            font-weight: 800;
            color: #34d399;
            font-size: 1.1rem;
        }
        .order-time {
            font-size: 0.82rem;
            color: #64748b;
        }
        .order-count {
            background: linear-gradient(135deg, #3B82F6, #2563EB);
            color: #fff;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
            grid-column: 1 / -1;
        }
        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
            display: block;
        }
        .empty-state p {
            font-size: 1.1rem;
            margin: 0;
            font-weight: 500;
        }

        /* ============ FOOTER ============ */
        .home-footer {
            background: #0f172a;
            color: #94a3b8;
            padding: 40px 2rem;
            text-align: center;
        }
        .home-footer-inner {
            max-width: 1100px;
            margin: 0 auto;
        }
        .home-footer h3 {
            color: #fff;
            font-size: 1.3rem;
            margin: 0 0 8px;
        }
        .home-footer p {
            margin: 0 0 20px;
            font-size: 0.9rem;
        }
        .footer-links {
            display: flex;
            gap: 24px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .footer-links a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        .footer-links a:hover { color: #10B981; }
        .footer-divider {
            border: none;
            border-top: 1px solid #1e293b;
            margin: 24px 0;
        }
        .footer-copy {
            font-size: 0.82rem;
            color: #475569;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            .hero-section-home { padding: 130px 1.5rem 80px; }
            .hero-section-home h1 { font-size: 2.2rem; }
            .hero-section-home p { font-size: 1rem; }
            .hero-buttons a { padding: 14px 28px; font-size: 0.95rem; }
            .search-form { flex-direction: column; }
            .search-form button { width: 100%; }
            .orders-grid { grid-template-columns: 1fr; }
            .popular-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
            .how-section, .popular-section, .orders-section { padding: 50px 1.5rem; }
            .track-section { padding: 50px 1.5rem; }
        }
        @media (max-width: 480px) {
            .hero-section-home h1 { font-size: 1.8rem; }
            .steps-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="home-page">
    <?php include __DIR__ . '/../template/navbar.php'; ?>

    <!-- Hero -->
    <section class="hero-section-home">
        <div class="hero-badge"><i class="fas fa-circle"></i> Now Serving Fresh Meals</div>
        <h1>Delicious Food,<br><span>Delivered Fast</span></h1>
        <p>Order your favorite meals and track them in real time. Fresh ingredients, fast service, right to your table.</p>
        <div class="hero-buttons">
            <a href="menu.php" class="btn-order"><i class="fas fa-shopping-bag"></i> Order Now</a>
            <a href="#track" class="btn-track"><i class="fas fa-search"></i> Track Order</a>
        </div>
    </section>

    <!-- Recent Orders -->
    <section class="orders-section">
      <div class="orders-inner">
        <div class="section-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <h2><?= $search ? 'Search Results' : 'Recent Orders' ?></h2>
                <p><?= $search ? 'Showing results for "' . htmlspecialchars($search) . '"' : 'Latest orders from our kitchen' ?></p>
            </div>
            <span class="order-count"><?= count($orders) ?> orders</span>
        </div>
        <div class="orders-grid" id="ordersGrid">
            <?php if (!empty($orders)): ?>
                <?php foreach($orders as $order):
                    $statusClass = '';
                    $cardClass = '';
                    switch($order['status']) {
                        case 'pending': $statusClass = 'status-pending'; $cardClass = 'status-pending-card'; break;
                        case 'preparing': $statusClass = 'status-preparing'; $cardClass = 'status-preparing-card'; break;
                        case 'ready': $statusClass = 'status-ready'; $cardClass = 'status-ready-card'; break;
                        case 'completed': $statusClass = 'status-completed'; $cardClass = 'status-completed-card'; break;
                        default: $statusClass = 'status-pending'; $cardClass = 'status-pending-card';
                    }
                    $statusIcon = [
                        'pending' => 'fa-clock',
                        'preparing' => 'fa-fire',
                        'ready' => 'fa-check-circle',
                        'completed' => 'fa-flag-checkered'
                    ][$order['status']] ?? 'fa-clock';
                ?>
                <div class="order-card <?= $cardClass ?>" data-order-id="<?= $order['id'] ?>">
                    <div class="order-card-header">
                        <span class="order-code-cell"><?= htmlspecialchars($order['order_code']); ?></span>
                        <span class="status-badge <?= $statusClass; ?>">
                            <i class="fas <?= $statusIcon ?>"></i>
                            <?= htmlspecialchars($order['status']); ?>
                        </span>
                    </div>
                    <div class="order-card-customer"><i class="fas fa-user" style="color:#94a3b8;margin-right:6px;font-size:0.8rem;"></i><?= htmlspecialchars($order['customer_name']); ?></div>
                    <div class="order-card-items"><?= htmlspecialchars($order['items'] ?? 'No items'); ?></div>
                    <div class="order-card-footer">
                        <span class="amount-cell">&#8369;<?= number_format($order['total_amount'], 2); ?></span>
                        <span class="order-time"><i class="far fa-clock"></i> <?= date('M d, h:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php elseif ($search): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <p>No orders found matching "<?= htmlspecialchars($search) ?>"</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No orders yet</p>
                </div>
            <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if (!empty($popularItems)): ?>
    <!-- Popular Menu Items -->
    <section class="popular-section">
        <div class="section-container">
            <div class="section-title">
                <h2>Popular Menu</h2>
                <p>Our most loved dishes, ready to order</p>
            </div>
            <div class="popular-grid">
                <?php foreach ($popularItems as $item): ?>
                    <?php $imgSrc = !empty($item['image_url']) ? $item['image_url'] : '../assets/images/food.jpg'; ?>
                    <div class="popular-card">
                        <img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" class="popular-card-img">
                        <div class="popular-card-body">
                            <h4><?= htmlspecialchars($item['item_name']) ?></h4>
                            <p class="popular-category"><?= htmlspecialchars($item['category']) ?></p>
                            <div class="popular-card-footer">
                                <span class="popular-price">&#8369;<?= number_format($item['price'], 2) ?></span>
                                <a href="menu.php" class="popular-order-btn"><i class="fas fa-plus"></i> Order</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Track -->
    <section class="track-section" id="track">
        <div class="track-inner">
            <h2><i class="fas fa-search-location"></i> Track Your Order</h2>
            <p>Enter your order code or customer name to check the latest status</p>
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Enter Order Code or Customer Name..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit"><i class="fas fa-search"></i> Search</button>
            </form>
        </div>
    </section>

    <!-- How It Works -->
    <section class="how-section">
        <div class="section-container">
            <div class="section-title">
                <h2>How It Works</h2>
                <p>Three simple steps to get your food</p>
            </div>
            <div class="steps-grid">
                <div class="step-card">
                    <i class="fas fa-utensils step-icon"></i>
                    <div class="step-number">1</div>
                    <h4>Browse Menu</h4>
                    <p>Explore our selection of fresh meals and pick your favorites</p>
                </div>
                <div class="step-card">
                    <i class="fas fa-paper-plane step-icon"></i>
                    <div class="step-number">2</div>
                    <h4>Place Order</h4>
                    <p>Submit your order and receive a unique tracking code</p>
                </div>
                <div class="step-card">
                    <i class="fas fa-bell step-icon"></i>
                    <div class="step-number">3</div>
                    <h4>Track & Enjoy</h4>
                    <p>Watch your order in real time from kitchen to your table</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="home-footer">
        <div class="home-footer-inner">
            <h3><i class="fas fa-utensils"></i> FoodPulse</h3>
            <p>Fast, fresh, and flavorful meals delivered to your table.</p>
            <div class="footer-links">
                <a href="index.php">Home</a>
                <a href="menu.php">Menu</a>
                <a href="#track">Track Order</a>
                <a href="login.php">Login</a>
            </div>
            <hr class="footer-divider">
            <p class="footer-copy">&copy; <?= date('Y') ?> FoodPulse. All rights reserved.</p>
        </div>
    </footer>

    <script src="../assets/js/ajax.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function renderOrders(orders) {
                const grid = document.getElementById('ordersGrid');
                if (!grid) return;
                if (!orders || orders.length === 0) {
                    grid.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No orders found</p>
                        </div>
                    `;
                    return;
                }
                const statusClasses = {
                    'pending': 'status-pending',
                    'preparing': 'status-preparing',
                    'ready': 'status-ready',
                    'completed': 'status-completed'
                };
                const cardClasses = {
                    'pending': 'status-pending-card',
                    'preparing': 'status-preparing-card',
                    'ready': 'status-ready-card',
                    'completed': 'status-completed-card'
                };
                const statusIcons = {
                    'pending': 'fa-clock',
                    'preparing': 'fa-fire',
                    'ready': 'fa-check-circle',
                    'completed': 'fa-flag-checkered'
                };
                grid.innerHTML = orders.map(order => {
                    const statusClass = statusClasses[order.status] || 'status-pending';
                    const cardClass = cardClasses[order.status] || 'status-pending-card';
                    const icon = statusIcons[order.status] || 'fa-clock';
                    const items = order.items || 'No items';
                    return `
                        <div class="order-card ${cardClass}" data-order-id="${order.id}">
                            <div class="order-card-header">
                                <span class="order-code-cell">${AJAX.formatText(order.order_code)}</span>
                                <span class="status-badge ${statusClass}"><i class="fas ${icon}"></i> ${order.status}</span>
                            </div>
                            <div class="order-card-customer"><i class="fas fa-user" style="color:#94a3b8;margin-right:6px;font-size:0.8rem;"></i>${AJAX.formatText(order.customer_name)}</div>
                            <div class="order-card-items">${AJAX.formatText(items)}</div>
                            <div class="order-card-footer">
                                <span class="amount-cell">&#8369;${parseFloat(order.total_amount).toFixed(2).replace(/\\d(?=(\\d{3})+\\.)/g, '$&,')}</span>
                                <span class="order-time"><i class="far fa-clock"></i> ${AJAX.formatDateTime(order.created_at)}</span>
                            </div>
                        </div>
                    `;
                }).join('');
            }
            AJAX.setPublicMode(true);
            AJAX.startAutoRefresh(async function(result) {
                if (result.success && result.orders) {
                    const currentCount = document.querySelectorAll('#ordersGrid .order-card').length;
                    if (result.orders.length !== currentCount && !document.querySelector('input[name="search"]').value) {
                        renderOrders(result.orders);
                        const countBadge = document.querySelector('.order-count');
                        if (countBadge) countBadge.textContent = result.orders.length + ' orders';
                    }
                }
            }, 3000);
        });
    </script>
</body>
</html>