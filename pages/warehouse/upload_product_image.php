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

        // Move old image to history folder (for future reference/editing)
        $old_image = $pdo->prepare("SELECT image_url FROM products WHERE id = ?");
        $old_image->execute([$product_id]);
        $old_image_url = $old_image->fetchColumn();

        if ($old_image_url) {
            $old_image_path = __DIR__ . '/../../' . ltrim($old_image_url, '/');
            if (file_exists($old_image_path) && is_file($old_image_path)) {
                // Create history directory for this product
                $history_dir = __DIR__ . '/../../uploads/products/history/' . $product_id;
                if (!is_dir($history_dir)) {
                    mkdir($history_dir, 0755, true);
                }

                // Move to history with timestamp
                $history_filename = date('Y-m-d_His') . '_' . basename($old_image_path);
                $history_path = $history_dir . '/' . $history_filename;
                rename($old_image_path, $history_path);
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
    <div
        style="background:#fee2e2;border:2px solid #dc2626;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:20px;">
        <strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div
        style="background:#d1fae5;border:2px solid #059669;color:#065f46;padding:16px;border-radius:8px;margin-bottom:20px;">
        <strong>Success:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        <div style="margin-top:8px;font-size:0.9rem;">Redirecting back to products page...</div>
    </div>
<?php endif; ?>

<?php if ($product): ?>
    <style>
        .upload-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }

        .upload-tab {
            flex: 1;
            padding: 14px 20px;
            border: 2px solid var(--border);
            border-radius: 10px;
            background: var(--bg-panel);
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            transition: all 0.2s;
        }

        .upload-tab:hover {
            border-color: var(--accent);
        }

        .upload-tab.active {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .upload-panel {
            display: none;
        }

        .upload-panel.active {
            display: block;
        }

        .camera-container {
            position: relative;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        #cameraVideo {
            width: 100%;
            max-height: 400px;
            display: block;
        }

        #cameraCanvas {
            display: none;
        }

        #capturedImage {
            width: 100%;
            max-height: 400px;
            display: none;
            border-radius: 12px;
        }

        .camera-controls {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 16px;
        }

        .camera-btn {
            padding: 16px 32px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .camera-btn.capture {
            background: #dc2626;
            color: white;
        }

        .camera-btn.capture:hover {
            background: #b91c1c;
            transform: scale(1.05);
        }

        .camera-btn.retake {
            background: #6b7280;
            color: white;
        }

        .camera-btn.use {
            background: #059669;
            color: white;
        }

        .camera-status {
            text-align: center;
            padding: 40px;
            color: var(--muted);
        }

        .image-history {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
        }

        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .history-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid var(--border);
        }

        .history-item img {
            width: 100%;
            height: 100px;
            object-fit: cover;
        }

        .history-item .date {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            font-size: 0.7rem;
            padding: 4px;
            text-align: center;
        }
    </style>

    <div class="card">
        <h2>📸 Upload Image for Product</h2>

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

        <!-- Upload Method Tabs -->
        <div class="upload-tabs">
            <div class="upload-tab active" onclick="switchTab('file')">
                📁 Upload File
            </div>
            <div class="upload-tab" onclick="switchTab('camera')">
                📷 Take Photo
            </div>
        </div>

        <!-- File Upload Panel -->
        <div id="filePanel" class="upload-panel active">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">

                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;margin-bottom:8px;">
                        Select Image
                    </label>
                    <input type="file" name="product_image" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                        required style="padding:10px;border:2px solid #e5e7eb;border-radius:6px;width:100%;">
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

        <!-- Camera Panel -->
        <div id="cameraPanel" class="upload-panel">
            <div class="camera-container">
                <video id="cameraVideo" autoplay playsinline></video>
                <canvas id="cameraCanvas"></canvas>
                <img id="capturedImage" alt="Captured photo">
            </div>

            <div id="cameraStatus" class="camera-status">
                <div style="font-size:2rem;margin-bottom:12px;">📷</div>
                <p>Click "Start Camera" to begin</p>
            </div>

            <div class="camera-controls">
                <button type="button" id="startCameraBtn" class="camera-btn capture" onclick="startCamera()">
                    🎥 Start Camera
                </button>
                <button type="button" id="captureBtn" class="camera-btn capture" onclick="capturePhoto()"
                    style="display:none;">
                    📸 Capture
                </button>
                <button type="button" id="retakeBtn" class="camera-btn retake" onclick="retakePhoto()"
                    style="display:none;">
                    🔄 Retake
                </button>
                <button type="button" id="usePhotoBtn" class="camera-btn use" onclick="usePhoto()" style="display:none;">
                    ✅ Use This Photo
                </button>
            </div>

            <form id="cameraForm" method="POST" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                <input type="file" name="product_image" id="cameraFileInput" accept="image/*">
            </form>
        </div>

        <!-- Image History (for future reference/editing) -->
        <?php
        // Get image history for this product
        $historyDir = __DIR__ . '/../../uploads/products/history/' . $product['id'];
        $historyImages = [];
        if (is_dir($historyDir)) {
            $files = glob($historyDir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
            foreach ($files as $file) {
                $historyImages[] = [
                    'path' => '/uploads/products/history/' . $product['id'] . '/' . basename($file),
                    'date' => date('M j, Y H:i', filemtime($file)),
                    'timestamp' => filemtime($file)
                ];
            }
            // Sort by newest first
            usort($historyImages, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        }
        ?>

        <?php if (!empty($historyImages)): ?>
            <div class="image-history">
                <h3 style="margin:0 0 8px;">📜 Image History</h3>
                <p style="color:var(--muted);font-size:0.9rem;margin:0;">Previous images captured for this product (for
                    reference/editing)</p>
                <div class="history-grid">
                    <?php foreach (array_slice($historyImages, 0, 8) as $img): ?>
                        <div class="history-item">
                            <img src="<?= htmlspecialchars($img['path'], ENT_QUOTES, 'UTF-8') ?>" alt="History">
                            <div class="date"><?= htmlspecialchars($img['date'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let stream = null;
        const video = document.getElementById('cameraVideo');
        const canvas = document.getElementById('cameraCanvas');
        const capturedImage = document.getElementById('capturedImage');
        const cameraStatus = document.getElementById('cameraStatus');

        function switchTab(tab) {
            document.querySelectorAll('.upload-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.upload-panel').forEach(p => p.classList.remove('active'));

            if (tab === 'file') {
                document.querySelector('.upload-tab:first-child').classList.add('active');
                document.getElementById('filePanel').classList.add('active');
                stopCamera();
            } else {
                document.querySelector('.upload-tab:last-child').classList.add('active');
                document.getElementById('cameraPanel').classList.add('active');
            }
        }

        async function startCamera() {
            try {
                cameraStatus.style.display = 'none';
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment', // Use back camera on mobile
                        width: {
                            ideal: 1280
                        },
                        height: {
                            ideal: 720
                        }
                    }
                });
                video.srcObject = stream;
                video.style.display = 'block';

                document.getElementById('startCameraBtn').style.display = 'none';
                document.getElementById('captureBtn').style.display = 'inline-block';
            } catch (err) {
                console.error('Camera error:', err);
                cameraStatus.innerHTML = `
                <div style="font-size:2rem;margin-bottom:12px;">❌</div>
                <p>Could not access camera</p>
                <p style="font-size:0.85rem;color:#dc2626;">${err.message}</p>
                <p style="font-size:0.85rem;">Please ensure camera permissions are granted.</p>
            `;
                cameraStatus.style.display = 'block';
            }
        }

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            video.style.display = 'none';
            capturedImage.style.display = 'none';
            cameraStatus.style.display = 'block';
            cameraStatus.innerHTML = `
            <div style="font-size:2rem;margin-bottom:12px;">📷</div>
            <p>Click "Start Camera" to begin</p>
        `;

            document.getElementById('startCameraBtn').style.display = 'inline-block';
            document.getElementById('captureBtn').style.display = 'none';
            document.getElementById('retakeBtn').style.display = 'none';
            document.getElementById('usePhotoBtn').style.display = 'none';
        }

        function capturePhoto() {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);

            capturedImage.src = canvas.toDataURL('image/jpeg', 0.9);
            capturedImage.style.display = 'block';
            video.style.display = 'none';

            // Stop camera stream
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }

            document.getElementById('captureBtn').style.display = 'none';
            document.getElementById('retakeBtn').style.display = 'inline-block';
            document.getElementById('usePhotoBtn').style.display = 'inline-block';
        }

        function retakePhoto() {
            capturedImage.style.display = 'none';
            startCamera();

            document.getElementById('retakeBtn').style.display = 'none';
            document.getElementById('usePhotoBtn').style.display = 'none';
        }

        function usePhoto() {
            // Convert canvas to blob and submit
            canvas.toBlob(function(blob) {
                const file = new File([blob], 'camera_capture_<?= date('Y-m-d_His') ?>.jpg', {
                    type: 'image/jpeg'
                });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);

                document.getElementById('cameraFileInput').files = dataTransfer.files;
                document.getElementById('cameraForm').submit();
            }, 'image/jpeg', 0.9);
        }

        // Clean up camera when leaving page
        window.addEventListener('beforeunload', stopCamera);
    </script>
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
