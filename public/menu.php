<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/MqttClient.php';

// Check if ordering as guest (from table QR code)
$isGuestOrder = isset($_SESSION['ordering_as_guest']) && $_SESSION['ordering_as_guest'] === true;
$tableId = $isGuestOrder ? (int)($_SESSION['table_id'] ?? 0) : 0;

// For guest orders, we don't require customer login
if (!$isGuestOrder) {
    requiredRole('customer', 'login.php');
}

$user_id = $isGuestOrder ? 0 : (int) $_SESSION['user_id']; // 0 for guest orders

$foodItems = [];
$foodItemsResult = mysqli_query($conn, "SELECT * FROM food_items WHERE is_available = 1 ORDER BY category, item_name");

if ($foodItemsResult) {
    while ($row = mysqli_fetch_assoc($foodItemsResult)) {
        $foodItems[] = $row;
    }
} else {
    setFlash('Failed to load menu items: ' . mysqli_error($conn), 'error');
}

$menuSignature = '';
foreach ($foodItems as $foodItem) {
    $menuSignature .= $foodItem['id'] . ':' . ($foodItem['updated_at'] ?? '') . ':' . (int) $foodItem['is_available'] . '|';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items_data = [];
    $total_amount = 0.0;

    foreach ($foodItems as $foodItem) {
        $qty_key = 'qty_' . $foodItem['id'];
        $quantity = isset($_POST[$qty_key]) ? (int) $_POST[$qty_key] : 0;

        if ($quantity > 0) {
            $items_data[] = [
                'name' => $foodItem['item_name'],
                'quantity' => $quantity,
                'price' => (float) $foodItem['price'],
            ];
            $total_amount += $quantity * (float) $foodItem['price'];
        }
    }

    if (empty($items_data)) {
        setFlash('Please select at least one item', 'error');
        header('Location: menu.php');
        exit;
    }

    $order_code = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));

            $insert_order_sql = "INSERT INTO orders (user_id, table_id, order_code, total_amount, status) VALUES (?, ?, ?, ?, 'pending')";
            $insert_order_stmt = $conn->prepare($insert_order_sql);

            if (!$insert_order_stmt) {
                setFlash('Failed to prepare order insert: ' . $conn->error, 'error');
                header('Location: menu.php');
                exit;
            }

            $insert_order_stmt->bind_param('iisd', $user_id, $tableId, $order_code, $total_amount);

        if ($insert_order_stmt->execute()) {
            $order_id = $conn->insert_id;
            $insert_order_stmt->close();

            $insert_item_sql = "INSERT INTO order_items (order_id, item_name, quantity, price) VALUES (?, ?, ?, ?)";
            $insert_item_stmt = $conn->prepare($insert_item_sql);

            if (!$insert_item_stmt) {
                setFlash('Failed to prepare order items insert: ' . $conn->error, 'error');
                header('Location: menu.php');
                exit;
            }

            foreach ($items_data as $cartItem) {
                $insert_item_stmt->bind_param(
                    'isid',
                    $order_id,
                    $cartItem['name'],
                    $cartItem['quantity'],
                    $cartItem['price']
                );
                $insert_item_stmt->execute();
            }

            $insert_item_stmt->close();

            // Generate QR codes for guest orders (table orders)
            if ($isGuestOrder && $tableId > 0) {
                require_once __DIR__ . '/../includes/QrCode.php';
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}";
                
                // Generate table QR code (for future reference)
                $tableQrCode = QrCodeGenerator::generateTableQrCode($tableId, $baseUrl);
                
                // Generate kitchen QR code for this order
                $kitchenQrCode = QrCodeGenerator::generateKitchenQrCode($order_id, $baseUrl);
                
                // You could store these QR codes in session or return them in the flash message
                // For now, we'll just note that they were generated
                setFlash('Order placed successfully! QR codes generated for kitchen display.', 'success');
            } else {
                setFlash('Order placed successfully!', 'success');
            }

            // Publish MQTT events so MQTT Explorer and live dashboards update immediately.
            $customerName = $isGuestOrder ? 'Table Guest' : ($_SESSION['name'] ?? 'Customer');
            $itemsList = array_map(function ($item) {
                return [
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ];
            }, $items_data);

            $mqtt = new MqttService();
            if ($mqtt->connect()) {
                $mqtt->publishNewOrder($order_code, $customerName, $itemsList, $total_amount, $user_id);
                $mqtt->publishOrderEvent($order_code, 'pending', $customerName, $user_id, $order_id);

                $counts = getOrderCounts($conn);
                $mqtt->publishKitchenStatus(
                    'pending',
                    $counts['pending'],
                    $counts['preparing'],
                    $counts['ready'],
                    $counts['completed']
                );

                $mqtt->disconnect();
            }
            
            header('Location: customer/index.php');
            exit;
        }


    setFlash('Failed to place order: ' . $insert_order_stmt->error, 'error');
    $insert_order_stmt->close();
    header('Location: menu.php');
    exit;
}

function getOrderCounts($conn): array
{
    $counts = ['pending' => 0, 'preparing' => 0, 'ready' => 0, 'completed' => 0];
    $result = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['cnt'];
            }
        }
    }

    return $counts;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu - FoodPulse</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>
    <div style="padding: 30px;">
        <div class="menu-header">
            <div class="header-title">  
                <h2>Foodpulse Menu</h2>
            </div>
        
            <div class="menu-buttons">
                <button type="submit" class="btn-menu" id="placeOrderBtn" <?= empty($foodItems) ? 'disabled' : '' ?>>Place Order</button>
                <a href="customer/index.php" class="back-btn">Back</a>
            </div>
        </div>
        
        <form method="POST" action="menu.php" id="menuForm">
            <div
                id="menu-container"
                class="menu-cards-container"
                data-menu-signature="<?= htmlspecialchars($menuSignature, ENT_QUOTES, 'UTF-8') ?>"
            >
                <?php if (!empty($foodItems)): ?>
                    <?php foreach ($foodItems as $item): ?>
                        <?php $imgSrc = !empty($item['image_url']) ? $item['image_url'] : '../assets/images/food.jpg'; ?>
                        <div class="menu-cards">
                            <div class="menu-img">
                                <img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" class="image-url">
                            </div>
  
                            <div class="menu-card-body">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p id="no-items-msg">No menu items available at the moment.</p>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-menu" id="placeOrderBtn" <?= empty($foodItems) ? 'disabled' : '' ?>>Place Order</button>
            <?php include '../template/alerts.php'; ?>
        </form>
    </div>

    <script src="../assets/js/ajax.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('menu-container');
            const placeOrderBtn = document.getElementById('placeOrderBtn');

            if (!container) {
                return;
            }

            let lastSignature = container.dataset.menuSignature || '';

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value ?? '';
                return div.innerHTML;
            }

            function getSavedQuantities() {
                const values = {};
                container.querySelectorAll('input[type="number"][name^="qty_"]').forEach(function (input) {
                    values[input.name] = input.value;
                });
                return values;
            }

            function restoreQuantities(values) {
                Object.keys(values).forEach(function (name) {
                    const input = container.querySelector(`[name="${name}"]`);
                    if (input) {
                        input.value = values[name];
                    }
                });
            }

            function buildSignature(items) {
                return items.map(function (item) {
                    return `${item.id}:${item.updated_at || ''}:${item.is_available ?? 1}`;
                }).join('|');
            }

            function renderMenu(items) {
                if (!Array.isArray(items) || items.length === 0) {
                    container.innerHTML = '<p id="no-items-msg">No menu items available at the moment.</p>';
                    if (placeOrderBtn) {
                        placeOrderBtn.disabled = true;
                    }
                    return;
                }

                const savedValues = getSavedQuantities();

                container.innerHTML = items.map(function (item) {
                    const imgSrc = item.image_url || '../assets/images/food.jpg';

                    return `
                        <div class="menu-cards">
                            <div class="menu-img">
                                <img src="${escapeHtml(imgSrc)}" alt="" class="image-url">
                            </div>
                            <div class="menu-card-body">
                                <div class="menu-title-header">
                                    <span>Title</span>
                                    <strong class="menu-title">${escapeHtml(item.item_name)}</strong><br></div>
                                <small>${escapeHtml(item.category)}</small><br>
                                <strong>&#8369;${Number(item.price).toFixed(2)}</strong><br>
                                Quantity:
                                <input type="number" name="qty_${item.id}" value="0" min="0" style="width: 50px;">
                            </div>
                        </div>
                    `;
                }).join('');

                restoreQuantities(savedValues);

                if (placeOrderBtn) {
                    placeOrderBtn.disabled = false;
                }
            }

            async function refreshMenu() {
                try {
                    const response = await AJAX.api('get_menu.php');

                    if (!response.success || !Array.isArray(response.items)) {
                        return;
                    }

                    const signature = buildSignature(response.items);
                    if (signature === lastSignature) {
                        return;
                    }

                    lastSignature = signature;
                    container.dataset.menuSignature = signature;
                    renderMenu(response.items);
                } catch (error) {
                    console.error('Menu refresh error:', error);
                }
            }

            refreshMenu();
            setInterval(refreshMenu, 3000);
        });
    </script>
</body>
</html>
