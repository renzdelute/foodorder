<?php
session_start();

require __DIR__ . '/../../includes/helpers.php';
require __DIR__ . '/../../config/app.php';

requireAdmin();

// Check database connection
if (!isset($conn) || !$conn) {
    die('Database connection not available');
}

$totalItems = countTable($conn, 'food_items');

$availableItems = countFoodItems($conn, 'is_available = 1');
$unavailableItems = countFoodItems($conn, 'is_available = 0');

$cards = [
    ['title' => 'Total Items', 'value' => $totalItems],
    ['title' => 'Available Items', 'value' => $availableItems],
    ['title' => 'Unavailable Items', 'value' => $unavailableItems]
];

$statIds = ['stat-total-items', 'stat-available-items', 'stat-unavailable-items'];

$foodItems = getAll($conn, "SELECT * FROM food_items ORDER by category, item_name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <link rel="stylesheet" href="../../assets/css/components.css">
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
    <?php include '../../template/navbarAdmin.php'; ?>

    <main class="addItem">
        <div class="headerItem">
            <h1>Add Item</h1>
        </div>

            <div class="stat">
            <?php foreach($cards as $index => $card): ?>
                <div class="stat-card"> 
                        <h2><?= e($card['title']); ?></h2>
                        <h1 id="<?= $statIds[$index] ?>"><?= e($card['value']); ?></h1>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="addBtn">
                <button onclick="openModal()">Add Item</button>
            </div>

            <div id="modalAlert" class="modal-alert"></div>

            <div id="itemModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal()">&times;</span>

                    <h2>Add Item</h2>
            
                     <form action="../../ajax/add_items.php" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                             <label for="item_image">Item Image</label>
                        </div>

                    <div class="upload-wrapper">
                        <label class="upload-box" for="imageInput">       
                            <img src="" id="image-preview" alt="preview">
                            <span class="upload-text" id="uploadText">
                                Upload Your Image
                            </span>
                        </label>
                        <input type="file" id="imageInput" name="item_image" hidden accept="image/*">
                    </div>

                        <div class="form-group">
                            <label for="item_name">Item Name</label>
                            <input type="text" name="item_name" placeholder="Enter Item Name">
                        </div>

                        <div class="form-group">
                             <label for="category_name">Category Name</label>
                             <input type="text" name="category_name" placeholder="Enter Category Name">
                        </div>

                        <div class="form-group">
                            <label for="price">Price</label>
                            <input type="number" name="price" placeholder="Enter Price (&#8369;)">
                        </div>

                         <div class="form-group">
                             <label>Availability</label>
                             <div class="availability-toggle">
                                 <label>
                                     <input type="radio" name="is_available" value="1" checked>
                                     Available
                                 </label>
                                 <label>
                                     <input type="radio" name="is_available" value="0">
                                     Unavailable
                                 </label>
                             </div>
                         </div>

                         <button type="submit" class="saveItem">Save Item</button>
                     </form>
                </div>
            </div>

             <div id="editModal" class="modal">
                 <div class="modal-content">
                     <span class="close" onclick="closeModal()">&times;</span>

                     <h2>Edit Item</h2>

                     <form id="editItemForm" action="../../ajax/edit_items.php" method="POST">
                         <input type="hidden" id="editItemId" name="item_id">
                         <div class="form-group">
                             <label for="item_image">Item Image</label>
                        </div>

                        <div class="upload-wrapper">
                            <label class="upload-box" for="imageInput">       
                                <img src="" id="image-preview" alt="preview">
                                <span class="upload-text" id="uploadText">
                                    Upload Your Image
                                </span>
                            </label>
                            <input type="file" id="imageInput" name="item_image" hidden accept="image/*">
                        </div>

                        <div class="form-group">
                            <label for="item_name">Item Name</label>
                            <input type="text" id="item_name" name="item_name" placeholder="Enter Item Name">
                        </div>

                         <div class="form-group">
                             <label for="category_name">Category Name</label>
                             <input type="text" id="category_name" name="category_name" placeholder="Enter Category Name">
                         </div>

                         <div class="form-group">
                             <label for="price">Price</label>
                             <input type="number" id="price" name="price" placeholder="Enter Price (&#8369;)">
                         </div>

                         <div class="form-group">
                             <label>Availability</label>
                             <div class="availability-toggle">
                                 <label>
                                     <input type="radio" name="is_available" id="editIsAvailableYes" value="1" checked>
                                     Available
                                 </label>
                                 <label>
                                     <input type="radio" name="is_available" id="editIsAvailableNo" value="0">
                                     Unavailable
                                 </label>
                             </div>
                         </div>

                         <button type="submit" class="saveItem">Save Item</button>
                     </form>
                 </div>
             </div>

            <div class="dashboard-section">
                <div class="section-header">
                    <h2>Current Items</h2>
                </div>

                <div class="table-wrapper">
                    <table class="items-table" id="ordersTable">
                        <thead>
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Image</th>
                                <th>Price</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="foodItemsTableBody">
                        <?php if(!empty($foodItems)): ?>
                            <?php foreach($foodItems as $foodItem): ?>
                                 <tr data-item-id="<?= e($foodItem['id']); ?>"
                                     data-category="<?= e($foodItem['category']); ?>"
                                     data-item-name="<?= e($foodItem['item_name']); ?>"
                                     data-is-available="<?= $foodItem['is_available'] ? '1' : '0'; ?>">
                                    <td><?= e($foodItem['item_code']); ?></td>
                                    <td><?= e($foodItem['item_name']); ?></td>
                                    <td>
                                        <img src="<?= e($foodItem['image_url'] ?? '') ?>" width="100" alt="Item image" class="imgUrl">
                                    </td>
                                    <td>&#8369;<?= number_format($foodItem['price'], 2); ?></td>
                                    <td><?= e($foodItem['category']); ?></td>
                                    <td>
                                        <span class="status-badge <?= $foodItem['is_available'] ? 'available' : 'unavailable'; ?>">
                                            <?= $foodItem['is_available'] ? 'Available' : 'Unavailable'; ?>
                                        </span>
                                    </td>
                                    <td>
                                         <div class="item-actions">
                                              <button type="button" class="action-btn btn-edit" onclick='editModal(<?= (int)$foodItem["id"] ?>, <?= htmlspecialchars(json_encode($foodItem["image_url"] ?? ""), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($foodItem["item_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($foodItem["category"] ?? ""), ENT_QUOTES, "UTF-8") ?>, <?= (float)$foodItem["price"] ?>, <?= (int)$foodItem["is_available"] ?>)'>Edit</button>
                                             <button type="button" class="action-btn btn-delete delete-item-btn" 
                                                     data-id="<?= $foodItem['id'] ?>">
                                                 Delete
                                             </button>
                                         </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                                <tr class="empty-state-row">
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="fas fa-utensils"></i>
                                            <p>No menu items found</p>
                                        </div>
                                    </td>
                                </tr> 
                        <?php endif; ?>          
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="fullscreen" id="fullscreen">
                <span id="closeBtn">&times;</span>
            
                <img src="" alt="Item image" id="fullscreenImg">
            </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../../assets/js/ajax.js"></script>
    <script src="../../assets/js/image.js"></script>
    <script src="../../assets/js/app.js"></script>
    <script>
        document.getElementById('imageInput').addEventListener('change', previewImage);

        function previewImage(event){
            const file = event.target.files[0];
                                            
            const preview = document.getElementById('image-preview');
            const uploadText = document.getElementById('uploadText');

            if (file) {
                preview.src = URL.createObjectURL(file);
                preview.style.display = 'block';
                uploadText.style.display = 'none';
            } else {
                preview.removeAttribute('src');
                preview.style.display = 'none';
                uploadText.style.display = 'block';
            }
        }

        function resetImageBox() {
            const imageInput = document.getElementById('imageInput');
            const uploadText = document.getElementById('uploadText');
            const preview = document.getElementById('image-preview');

            preview.removeAttribute('src');
            preview.style.display = 'none';
            uploadText.style.display = 'block';
            imageInput.value = '';
        }
    </script>
</body>
</html>
