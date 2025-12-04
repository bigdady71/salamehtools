<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$error = null;
$success = null;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

// Handle image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    try {
        $product_id = (int)$_POST['product_id'];

        // Validate product exists
        $product = $pdo->prepare("SELECT id, sku, item_name FROM products WHERE id = ?");
        $product->execute([$product_id]);
        $product = $product->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception('Product not found');
        }

        // Check if file was uploaded
        if (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Please select an image file');
        }

        $file = $_FILES['product_image'];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed with error code: ' . $file['error']);
        }

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types, true)) {
            throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.');
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('File size exceeds 5MB limit');
        }

        // Create uploads directory if it doesn't exist
        $upload_dir = __DIR__ . '/../../uploads/products';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'product_' . $product_id . '_' . time() . '.' . $extension;
        $filepath = $upload_dir . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to save uploaded file');
        }

        // Delete old image if exists
        $old_image = $pdo->prepare("SELECT image_url FROM products WHERE id = ?");
        $old_image->execute([$product_id]);
        $old_image_url = $old_image->fetchColumn();

        if ($old_image_url) {
            $old_image_path = __DIR__ . '/../../' . ltrim($old_image_url, '/');
            if (file_exists($old_image_path) && is_file($old_image_path)) {
                unlink($old_image_path);
            }
        }

        // Update database with relative URL
        $image_url = '/uploads/products/' . $filename;
        $update = $pdo->prepare("UPDATE products SET image_url = ? WHERE id = ?");
        $update->execute([$image_url, $product_id]);

        $success = 'Image uploaded successfully!';

        // Redirect back to products page after 2 seconds
        header('Refresh: 2; URL=products.php');

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get product info if product_id is provided
$product = null;
if ($product_id > 0) {
    $product_stmt = $pdo->prepare("SELECT id, sku, item_name, image_url FROM products WHERE id = ?");
    $product_stmt->execute([$product_id]);
    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
}

$title = 'Upload Product Image - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Upload Product Image',
    'subtitle' => 'Add or update product photos',
    'user' => $user,
    'active' => 'products',
]);
?>

<?php if ($error): ?>
    <div style="background:#fee2e2;border:2px solid #dc2626;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:20px;">
        <strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background:#d1fae5;border:2px solid #059669;color:#065f46;padding:16px;border-radius:8px;margin-bottom:20px;">
        <strong>Success:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        <div style="margin-top:8px;font-size:0.9rem;">Redirecting back to products page...</div>
    </div>
<?php endif; ?>

<?php if ($product): ?>
    <div class="card">
        <h2>Upload Image for Product</h2>

        <div style="background:#f3f4f6;padding:16px;border-radius:8px;margin-bottom:24px;">
            <div style="font-weight:600;margin-bottom:4px;">
                <?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size:0.9rem;color:#6b7280;">
                SKU: <?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <?php if ($product['image_url']): ?>
            <div style="margin-bottom:24px;">
                <h3 style="margin:0 0 12px;">Current Image</h3>
                <img src="<?= htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                     style="max-width:300px;border-radius:8px;border:2px solid #e5e7eb;">
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">

            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;">
                    Select Image
                </label>
                <input type="file"
                       name="product_image"
                       accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                       required
                       style="padding:10px;border:2px solid #e5e7eb;border-radius:6px;width:100%;">
                <div style="font-size:0.85rem;color:#6b7280;margin-top:8px;">
                    Accepted formats: JPG, PNG, GIF, WebP | Max size: 5MB
                </div>
            </div>

            <div style="display:flex;gap:12px;">
                <button type="submit" class="btn btn-success">
                    📤 Upload Image
                </button>
                <a href="products.php" class="btn btn-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            No product selected. Please go back to the products page and select a product.
        </p>
        <div style="text-align:center;">
            <a href="products.php" class="btn">Go to Products</a>
        </div>
    </div>
<?php endif; ?>

<?php
warehouse_portal_render_layout_end();
