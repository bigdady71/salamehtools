<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/CacheManager.php';
require_once __DIR__ . '/../../includes/import_products.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Settings';

// Initialize cache manager (using file-based caching as fallback)
$cache = new CacheManager('file');

// Helper function to get setting value (with caching)
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    global $cache;

    // Cache individual setting for 1 hour
    return $cache->remember("setting:{$key}", 3600, function() use ($pdo, $key, $default) {
        $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = :key");
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (string)$result : $default;
    });
}

// Helper function to set setting value (clears cache)
function set_setting(PDO $pdo, string $key, string $value): void
{
    global $cache;

    $stmt = $pdo->prepare("
        INSERT INTO settings (k, v) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':key' => $key, ':value' => $value]);

    // Clear the cache for this setting
    $cache->forget("setting:{$key}");
    // Also clear all settings cache
    $cache->forget('all_settings');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'manual_import') {
        try {
            $importPath = null;
            $uploadType = $_POST['manual_upload_type'] ?? 'file';

            // Handle file upload
            if ($uploadType === 'file' && isset($_FILES['manual_import_file']) && $_FILES['manual_import_file']['error'] === UPLOAD_ERR_OK) {
                $uploadedFile = $_FILES['manual_import_file'];
                $allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                $fileExt = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

                if (in_array($uploadedFile['type'], $allowedTypes) || in_array($fileExt, ['xls', 'xlsx'])) {
                    $importDir = __DIR__ . '/../../imports';
                    if (!is_dir($importDir)) {
                        mkdir($importDir, 0755, true);
                    }

                    $targetPath = $importDir . '/manual_import_' . time() . '.' . $fileExt;

                    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                        $importPath = realpath($targetPath);
                    } else {
                        throw new Exception('Failed to save uploaded file.');
                    }
                } else {
                    throw new Exception('Invalid file type. Please upload an Excel file (.xls or .xlsx).');
                }
            } else if ($uploadType === 'existing') {
                // Use existing file from selection
                $importPath = trim($_POST['manual_import_path'] ?? '');
                if (empty($importPath) || !file_exists($importPath)) {
                    throw new Exception('Please select a valid file to import.');
                }
            }

            if (!$importPath) {
                throw new Exception('Please upload a file or select an existing file to import.');
            }

            // Create import run record
            $pdo->beginTransaction();

            $checksum = hash_file('sha256', $importPath);
            $insertRunStmt = $pdo->prepare("
                INSERT INTO import_runs (kind, source_path, checksum, started_at)
                VALUES ('products', :source_path, :checksum, NOW())
            ");
            $insertRunStmt->execute([
                ':source_path' => $importPath,
                ':checksum' => $checksum
            ]);
            $runId = (int)$pdo->lastInsertId();

            // Run the import
            $importResult = import_products_from_path($pdo, $importPath, $runId);

            // Update import run record
            $updateRunStmt = $pdo->prepare("
                UPDATE import_runs
                SET finished_at = NOW(),
                    rows_ok = :rows_ok,
                    rows_updated = :rows_updated,
                    rows_skipped = :rows_skipped,
                    ok = :ok,
                    message = :message
                WHERE id = :id
            ");
            $updateRunStmt->execute([
                ':id' => $runId,
                ':rows_ok' => $importResult['inserted'],
                ':rows_updated' => $importResult['updated'],
                ':rows_skipped' => $importResult['skipped'],
                ':ok' => $importResult['ok'] ? 1 : 0,
                ':message' => $importResult['message']
            ]);

            if ($importResult['ok']) {
                $pdo->commit();
                $successMessage = sprintf(
                    'Import completed successfully! Added: %d, Updated: %d, Skipped: %d, Total: %d',
                    $importResult['inserted'],
                    $importResult['updated'],
                    $importResult['skipped'],
                    $importResult['total']
                );
                flash('success', '', [
                    'title' => 'Manual Import Successful',
                    'lines' => [$successMessage],
                    'dismissible' => true
                ]);
            } else {
                $pdo->rollBack();
                $errorMessage = 'Import failed: ' . $importResult['message'];
                if (!empty($importResult['errors'])) {
                    $errorMessage .= ' Errors: ' . implode(', ', array_slice($importResult['errors'], 0, 3));
                }
                flash('error', $errorMessage);
            }

            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Import failed: ' . $e->getMessage());
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    if ($_POST['action'] === 'upload_images') {
        try {
            $imagesDir = __DIR__ . '/../../images/products';
            if (!is_dir($imagesDir)) {
                mkdir($imagesDir, 0755, true);
            }

            $uploadedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            $errors = [];

            // Handle multiple file uploads
            if (isset($_FILES['product_images']) && is_array($_FILES['product_images']['name'])) {
                $fileCount = count($_FILES['product_images']['name']);

                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['product_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $fileName = $_FILES['product_images']['name'][$i];
                        $tmpName = $_FILES['product_images']['tmp_name'][$i];
                        $fileSize = $_FILES['product_images']['size'][$i];
                        $fileType = $_FILES['product_images']['type'][$i];

                        // Extract just the filename (in case of folder upload with path)
                        $baseFileName = basename($fileName);

                        // Validate file type
                        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                        $fileExt = strtolower(pathinfo($baseFileName, PATHINFO_EXTENSION));
                        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                        if (!in_array($fileType, $allowedTypes) && !in_array($fileExt, $allowedExts)) {
                            $errors[] = "{$baseFileName}: Invalid file type (must be JPG, PNG, GIF, or WEBP)";
                            $errorCount++;
                            continue;
                        }

                        // Validate file size (max 5MB)
                        if ($fileSize > 5 * 1024 * 1024) {
                            $errors[] = "{$baseFileName}: File too large (max 5MB)";
                            $errorCount++;
                            continue;
                        }

                        // Extract SKU from filename (remove extension)
                        $sku = pathinfo($baseFileName, PATHINFO_FILENAME);

                        // Check if product with this SKU exists
                        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = :sku");
                        $checkStmt->execute([':sku' => $sku]);
                        $exists = (int)$checkStmt->fetchColumn() > 0;

                        if (!$exists) {
                            $errors[] = "{$baseFileName}: No product found with SKU '{$sku}'";
                            $skippedCount++;
                            continue;
                        }

                        // Save the file with base filename only (no folder path)
                        $targetPath = $imagesDir . '/' . $baseFileName;

                        // If file exists, overwrite it
                        if (file_exists($targetPath)) {
                            unlink($targetPath);
                        }

                        if (move_uploaded_file($tmpName, $targetPath)) {
                            $uploadedCount++;
                        } else {
                            $errors[] = "{$baseFileName}: Failed to save file";
                            $errorCount++;
                        }
                    } else if ($_FILES['product_images']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                        $errorCount++;
                        $errors[] = "File {$i}: Upload error code " . $_FILES['product_images']['error'][$i];
                    }
                }
            }

            // Build success/error message
            if ($uploadedCount > 0) {
                $message = "Successfully uploaded {$uploadedCount} product image(s).";
                if ($skippedCount > 0) {
                    $message .= " Skipped {$skippedCount} (no matching product SKU).";
                }
                if ($errorCount > 0) {
                    $message .= " {$errorCount} error(s) occurred.";
                }

                flash('success', '', [
                    'title' => 'Product Images Uploaded',
                    'lines' => [$message],
                    'list' => !empty($errors) ? array_slice($errors, 0, 10) : [],
                    'dismissible' => true
                ]);
            } else {
                flash('error', 'No images were uploaded.', [
                    'list' => !empty($errors) ? array_slice($errors, 0, 10) : []
                ]);
            }

            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;

        } catch (Exception $e) {
            flash('error', 'Image upload failed: ' . $e->getMessage());
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    if ($_POST['action'] === 'save_settings') {
        try {
            $pdo->beginTransaction();

            // Handle file upload if provided
            $watchPath = trim($_POST['import_products_watch_path'] ?? '');
            $uploadType = $_POST['upload_type'] ?? 'path';

            if ($uploadType === 'file' && isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
                $uploadedFile = $_FILES['excel_file'];
                $allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                $fileExt = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

                if (in_array($uploadedFile['type'], $allowedTypes) || in_array($fileExt, ['xls', 'xlsx'])) {
                    // Create imports directory if it doesn't exist
                    $importDir = __DIR__ . '/../../imports';
                    if (!is_dir($importDir)) {
                        mkdir($importDir, 0755, true);
                    }

                    // Save file with original name (or use timestamped name to avoid conflicts)
                    $targetPath = $importDir . '/' . basename($uploadedFile['name']);

                    // If file exists, add timestamp
                    if (file_exists($targetPath)) {
                        $pathInfo = pathinfo($uploadedFile['name']);
                        $targetPath = $importDir . '/' . $pathInfo['filename'] . '_' . time() . '.' . $pathInfo['extension'];
                    }

                    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                        $watchPath = realpath($targetPath);
                        flash('success', 'File uploaded successfully: ' . basename($targetPath));
                    } else {
                        flash('error', 'Failed to save uploaded file.');
                    }
                } else {
                    flash('error', 'Invalid file type. Please upload an Excel file (.xls or .xlsx).');
                }
            }

            // Import settings
            $importEnabled = isset($_POST['import_products_enabled']) ? '1' : '0';

            set_setting($pdo, 'import.products.watch_path', $watchPath);
            set_setting($pdo, 'import.products.enabled', $importEnabled);

            $pdo->commit();
            flash('success', 'Settings saved successfully.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            flash('error', 'Failed to save settings: ' . $e->getMessage());
        }
    }
}

// Get current settings
$watchPath = get_setting($pdo, 'import.products.watch_path', '');
$importEnabled = get_setting($pdo, 'import.products.enabled', '0') === '1';

// Scan for Excel files in common import directories
$availableFiles = [];
$searchPaths = [
    'C:\\imports',
    'C:\\xampp\\htdocs\\salamehtools\\imports',
    __DIR__ . '/../../imports',
];

foreach ($searchPaths as $searchPath) {
    if (is_dir($searchPath) && is_readable($searchPath)) {
        $files = glob($searchPath . '/*.{xlsx,xls,XLSX,XLS}', GLOB_BRACE);
        if ($files) {
            foreach ($files as $file) {
                $availableFiles[] = realpath($file);
            }
        }
    }
}

// Remove duplicates and sort
$availableFiles = array_unique($availableFiles);
sort($availableFiles);

// Get last import run status
$lastImportRun = $pdo->query("
    SELECT id, source_path, checksum, started_at, finished_at,
           rows_ok, rows_updated, rows_skipped, ok, message
    FROM import_runs
    WHERE kind = 'products'
    ORDER BY started_at DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Settings',
    'subtitle' => 'Configure system settings and auto-import',
    'active' => 'settings',
    'user' => $user,
]);

$flashes = consume_flashes();
?>

<style>
    .settings-container {
        max-width: 900px;
    }
    .settings-section {
        background: var(--bg-panel);
        border-radius: 16px;
        padding: 32px;
        margin-bottom: 24px;
    }
    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 8px;
        color: var(--text);
    }
    .section-subtitle {
        color: var(--muted);
        margin-bottom: 24px;
        font-size: 0.9rem;
    }
    .form-row {
        margin-bottom: 24px;
    }
    .form-label {
        display: block;
        margin-bottom: 8px;
        color: var(--text);
        font-weight: 600;
        font-size: 0.95rem;
    }
    .form-input {
        width: 100%;
        padding: 12px 16px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 8px;
        color: var(--text);
        font-size: 1rem;
        font-family: 'Courier New', monospace;
    }
    .form-input:focus {
        outline: none;
        border-color: var(--accent-2);
        box-shadow: 0 0 0 3px rgba(74, 125, 255, 0.1);
    }
    .form-input:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .form-help {
        margin-top: 6px;
        font-size: 0.85rem;
        color: var(--muted);
    }
    .checkbox-wrapper {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .checkbox-input {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }
    .checkbox-label {
        margin: 0;
        cursor: pointer;
    }
    .btn-save {
        padding: 12px 32px;
        background: #6666ff;
        color: #ffffff;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-save:hover {
        background: #003366;
    }
    .btn-import-now {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 14px 36px;
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        color: #ffffff;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 1.05rem;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 14px rgba(34, 197, 94, 0.4);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .btn-import-now:hover {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(34, 197, 94, 0.5);
    }
    .btn-import-now:active {
        transform: translateY(0);
    }
    .btn-import-now svg {
        transition: transform 0.3s ease;
    }
    .btn-import-now:hover svg {
        transform: scale(1.1);
    }
    .status-panel {
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        margin-top: 24px;
    }
    .status-title {
        font-weight: 700;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-badge.success {
        background: rgba(110, 231, 183, 0.2);
        color: #6ee7b7;
    }
    .status-badge.error {
        background: rgba(255, 92, 122, 0.2);
        color: #ff5c7a;
    }
    .status-badge.neutral {
        background: rgba(156, 163, 175, 0.2);
        color: #9ca3af;
    }
    .status-details {
        margin-top: 12px;
        font-size: 0.9rem;
        line-height: 1.6;
    }
    .status-details dt {
        color: var(--muted);
        display: inline-block;
        width: 150px;
    }
    .status-details dd {
        display: inline;
        color: var(--text);
        margin-left: 0;
    }
    .status-details dd::after {
        content: "\A";
        white-space: pre;
    }
    .result-box {
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 16px;
    }
    .result-success {
        background: rgba(110, 231, 183, 0.2);
        border: 1px solid rgba(110, 231, 183, 0.3);
        color: #6ee7b7;
    }
    .result-error {
        background: rgba(255, 92, 122, 0.2);
        border: 1px solid rgba(255, 92, 122, 0.3);
        color: #ff5c7a;
    }
    .upload-tabs {
        display: flex;
        gap: 4px;
        margin-bottom: 20px;
    }
    .upload-tab {
        padding: 10px 20px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 8px 8px 0 0;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 600;
    }
    .upload-tab.active {
        background: #6666ff;
        color: #fff;
        border-color: #6666ff;
    }
    .upload-tab:hover:not(.active) {
        background: var(--bg-panel);
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    code {
        background: rgba(0, 0, 0, 0.1);
        padding: 2px 6px;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
    }
</style>

<script>
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.upload-tab').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');

    // Update tab content
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tab + '-tab').classList.add('active');

    // Update hidden input
    document.getElementById('upload_type').value = tab;
}

function switchManualTab(tab) {
    // Update tab buttons in manual import section
    const manualTabs = event.target.closest('.settings-section').querySelectorAll('.upload-tab');
    manualTabs.forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');

    // Update tab content in manual import section
    const manualContents = ['manual-file-tab', 'manual-existing-tab'];
    manualContents.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.remove('active');
    });
    document.getElementById('manual-' + tab + '-tab').classList.add('active');

    // Update hidden input
    document.getElementById('manual_upload_type').value = tab;

    // Update required attribute
    const fileInput = document.querySelector('input[name="manual_import_file"]');
    const selectInput = document.querySelector('select[name="manual_import_path"]');

    if (tab === 'file') {
        if (fileInput) fileInput.required = true;
        if (selectInput) selectInput.required = false;
    } else {
        if (fileInput) fileInput.required = false;
        if (selectInput) selectInput.required = true;
    }
}

// Update file count display
function updateFileCount(input) {
    const display = document.getElementById('file-count-display');
    const count = input.files.length;
    if (count > 0) {
        display.textContent = `${count} file(s) selected`;
        display.style.color = '#15803d';
        display.style.fontWeight = '600';
    } else {
        display.textContent = 'Select one or more product images to upload (hold Ctrl/Cmd to select multiple files)';
        display.style.color = '';
        display.style.fontWeight = '';
    }
}

// Update folder count display
function updateFolderCount(input) {
    const display = document.getElementById('folder-count-display');
    const count = input.files.length;
    if (count > 0) {
        // Get folder name from first file path
        const firstFile = input.files[0];
        const folderPath = firstFile.webkitRelativePath || firstFile.name;
        const folderName = folderPath.split('/')[0] || 'selected folder';
        display.textContent = `${count} image(s) found in "${folderName}"`;
        display.style.color = '#15803d';
        display.style.fontWeight = '600';
    } else {
        display.textContent = 'Click to select a folder - all images inside will be uploaded';
        display.style.color = '';
        display.style.fontWeight = '';
    }
}

// Switch between file/folder upload tabs for images
function switchImageTab(tab) {
    // Update tab buttons
    const tabs = event.target.closest('.upload-tabs').querySelectorAll('.upload-tab');
    tabs.forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');

    // Update tab content
    document.getElementById('image-files-tab').classList.remove('active');
    document.getElementById('image-folder-tab').classList.remove('active');
    document.getElementById('image-' + tab + '-tab').classList.add('active');

    // Update hidden input
    document.getElementById('image_upload_type').value = tab;

    // Update required attribute
    const filesInput = document.getElementById('product_images_files');
    const folderInput = document.getElementById('product_images_folder');

    if (tab === 'files') {
        if (filesInput) filesInput.required = true;
        if (folderInput) folderInput.required = false;
    } else {
        if (filesInput) filesInput.required = false;
        if (folderInput) folderInput.required = true;
    }
}

// Handle custom path toggle
<?php if (!empty($availableFiles)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('import_products_watch_path');
    const customInput = document.getElementById('custom_path_input');

    if (select) {
        select.addEventListener('change', function() {
            if (this.value === 'custom') {
                customInput.style.display = 'block';
                customInput.required = true;
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.required = false;
                customInput.value = '';
            }
        });
    }

    // Handle form submission
    document.querySelector('form').addEventListener('submit', function(e) {
        if (select.value === 'custom' && customInput.value.trim() !== '') {
            // Replace select value with custom input value
            select.value = customInput.value.trim();
        }
    });
});
<?php endif; ?>
</script>

<div class="settings-container">
    <?php foreach ($flashes as $flash): ?>
        <div class="result-box result-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>" style="margin-bottom: 20px;">
            <?php if (isset($flash['title']) && $flash['title']): ?>
                <strong><?= htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <?php endif; ?>
            <?php if (isset($flash['lines']) && is_array($flash['lines'])): ?>
                <?php foreach ($flash['lines'] as $line): ?>
                    <?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?><br>
                <?php endforeach; ?>
            <?php else: ?>
                <?= htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Manual Import Section -->
    <div class="settings-section" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #86efac;">
        <h2 class="section-title" style="color: #15803d; display: flex; align-items: center; gap: 10px;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
            </svg>
            Manual Import Now
        </h2>
        <p class="section-subtitle" style="color: #166534;">
            Upload and immediately process an Excel file to update products right now
        </p>

        <form method="post" action="" enctype="multipart/form-data" style="margin-bottom: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="manual_import">
            <input type="hidden" name="manual_upload_type" id="manual_upload_type" value="file">

            <div class="upload-tabs">
                <div class="upload-tab active" onclick="switchManualTab('file')">Upload File</div>
                <?php if (!empty($availableFiles)): ?>
                    <div class="upload-tab" onclick="switchManualTab('existing')">Select Existing File</div>
                <?php endif; ?>
            </div>

            <div id="manual-file-tab" class="tab-content active">
                <div class="form-row">
                    <label class="form-label">Choose Excel File to Import</label>
                    <input type="file" name="manual_import_file" class="form-input" accept=".xls,.xlsx" required>
                    <p class="form-help">
                        Select an Excel file to upload and process immediately. The import will update all products in the database.
                    </p>
                </div>
            </div>

            <?php if (!empty($availableFiles)): ?>
                <div id="manual-existing-tab" class="tab-content">
                    <div class="form-row">
                        <label class="form-label">Select File to Import</label>
                        <select name="manual_import_path" class="form-input" style="font-family: 'Courier New', monospace;">
                            <option value="">-- Select a file --</option>
                            <?php foreach ($availableFiles as $file): ?>
                                <option value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            Select an existing Excel file from the imports directory to process immediately.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn-import-now">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                Import Now
            </button>
        </form>
    </div>

    <!-- Product Images Upload Section -->
    <div class="settings-section" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #fcd34d;">
        <h2 class="section-title" style="color: #92400e; display: flex; align-items: center; gap: 10px;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                <polyline points="21 15 16 10 5 21"></polyline>
            </svg>
            Upload Product Images
        </h2>
        <p class="section-subtitle" style="color: #78350f;">
            Upload product images in bulk. Images will be matched to products based on their filename (SKU)
        </p>

        <div style="background: rgba(146, 64, 14, 0.1); border: 1px solid #fcd34d; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
            <strong style="color: #92400e; display: block; margin-bottom: 8px;">📋 Important Instructions:</strong>
            <ul style="margin: 0; padding-left: 20px; color: #78350f; font-size: 0.9rem;">
                <li>Image filenames must match the product SKU exactly (e.g., <code>403_1.jpg</code> for SKU "403_1")</li>
                <li>Supported formats: JPG, JPEG, PNG, GIF, WEBP</li>
                <li>Maximum file size: 5MB per image</li>
                <li>You can upload up to 3000 images at once (folder or multiple files)</li>
                <li>Images with no matching product SKU will be skipped</li>
                <li>Existing images with the same name will be <strong>automatically overwritten</strong></li>
                <li><strong>⚠️ If uploading many images fails, restart Apache in XAMPP Control Panel</strong></li>
            </ul>
        </div>

        <form method="post" action="" enctype="multipart/form-data" style="margin-bottom: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_images">
            <input type="hidden" name="image_upload_type" id="image_upload_type" value="files">

            <div class="upload-tabs" style="margin-bottom: 20px;">
                <div class="upload-tab active" onclick="switchImageTab('files')">Select Multiple Files</div>
                <div class="upload-tab" onclick="switchImageTab('folder')">Upload Entire Folder</div>
            </div>

            <div id="image-files-tab" class="tab-content active">
                <div class="form-row">
                    <label class="form-label">Select Product Images</label>
                    <input
                        type="file"
                        name="product_images[]"
                        id="product_images_files"
                        class="form-input"
                        accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                        multiple
                        required
                        onchange="updateFileCount(this)"
                    >
                    <p class="form-help" id="file-count-display">
                        Select one or more product images to upload (hold Ctrl/Cmd to select multiple files)
                    </p>
                </div>
            </div>

            <div id="image-folder-tab" class="tab-content">
                <div class="form-row">
                    <label class="form-label">Select Folder Containing Product Images</label>
                    <input
                        type="file"
                        name="product_images[]"
                        id="product_images_folder"
                        class="form-input"
                        accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                        multiple
                        webkitdirectory
                        directory
                        onchange="updateFolderCount(this)"
                    >
                    <p class="form-help" id="folder-count-display">
                        Click to select a folder - all images inside will be uploaded
                    </p>
                </div>
            </div>

            <button type="submit" class="btn-import-now" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Upload Images
            </button>
        </form>
    </div>

    <form method="post" action="" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="upload_type" id="upload_type" value="path">

        <div class="settings-section">
            <h2 class="section-title">Product Auto-Import</h2>
            <p class="section-subtitle">Configure automatic product imports from a watched file path</p>

            <div class="upload-tabs" style="margin-bottom: 20px;">
                <div class="upload-tab active" onclick="switchTab('path')">Select Existing File</div>
                <div class="upload-tab" onclick="switchTab('file')">Upload New File</div>
            </div>

            <div id="path-tab" class="tab-content active">
                <div class="form-row">
                    <label class="form-label" for="import_products_watch_path">
                        Watched File Path
                    </label>

                    <?php if (!empty($availableFiles)): ?>
                        <select
                            id="import_products_watch_path"
                            name="import_products_watch_path"
                            class="form-input"
                            style="font-family: 'Courier New', monospace;"
                        >
                            <option value="">-- Select a file --</option>
                            <?php foreach ($availableFiles as $file): ?>
                                <option value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $watchPath === $file ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom">-- Enter custom path --</option>
                        </select>

                        <input
                            type="text"
                            id="custom_path_input"
                            name="custom_watch_path"
                            class="form-input"
                            style="margin-top: 12px; display: none; font-family: 'Courier New', monospace;"
                            placeholder="e.g., C:\imports\products.xlsx or \\server\share\products.xlsx"
                        >
                    <?php else: ?>
                        <input
                            type="text"
                            id="import_products_watch_path"
                            name="import_products_watch_path"
                            class="form-input"
                            value="<?= htmlspecialchars($watchPath, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="e.g., C:\imports\products.xlsx or \\server\share\products.xlsx"
                        >
                        <p class="form-help" style="color: #ffd166; margin-top: 8px;">
                            No Excel files found in default directories. Enter path manually or upload a file.
                        </p>
                    <?php endif; ?>

                    <p class="form-help">
                        <?php if (!empty($availableFiles)): ?>
                            Select an Excel file to monitor for automatic imports.
                        <?php else: ?>
                            Absolute path to the Excel file that will be monitored for changes.
                        <?php endif; ?>
                        Leave empty to disable path-based imports.
                    </p>
                </div>
            </div>

            <div id="file-tab" class="tab-content">
                <div class="form-row">
                    <label class="form-label">Upload Excel File</label>
                    <input type="file" name="excel_file" class="form-input" accept=".xls,.xlsx">
                    <p class="form-help">
                        Upload an Excel file to save it to the imports directory and use it for auto-import.
                        Maximum file size: 10MB
                    </p>
                </div>
            </div>

            <div class="form-row">
                <div class="checkbox-wrapper">
                    <input
                        type="checkbox"
                        id="import_products_enabled"
                        name="import_products_enabled"
                        class="checkbox-input"
                        <?= $importEnabled ? 'checked' : '' ?>
                    >
                    <label class="checkbox-label" for="import_products_enabled">
                        <strong>Enable Auto-Import</strong> — Run imports automatically via cron when file changes
                    </label>
                </div>
                <p class="form-help" style="margin-left: 32px;">
                    When enabled, the cron job will check for file changes and import automatically.
                    Disable this quickly if imports are causing issues.
                </p>
            </div>

            <div class="status-panel">
                <div class="status-title">
                    Last Import Status
                    <?php if ($lastImportRun): ?>
                        <span class="status-badge <?= $lastImportRun['ok'] ? 'success' : 'error' ?>">
                            <?= $lastImportRun['ok'] ? 'Success' : 'Failed' ?>
                        </span>
                    <?php else: ?>
                        <span class="status-badge neutral">No Runs Yet</span>
                    <?php endif; ?>
                </div>

                <?php if ($lastImportRun): ?>
                    <dl class="status-details">
                        <dt>Started:</dt>
                        <dd><?= htmlspecialchars($lastImportRun['started_at'], ENT_QUOTES, 'UTF-8') ?></dd>

                        <dt>Finished:</dt>
                        <dd><?= htmlspecialchars($lastImportRun['finished_at'] ?? 'In progress...', ENT_QUOTES, 'UTF-8') ?></dd>

                        <dt>Source File:</dt>
                        <dd><?= htmlspecialchars($lastImportRun['source_path'], ENT_QUOTES, 'UTF-8') ?></dd>

                        <dt>Checksum:</dt>
                        <dd style="font-family: 'Courier New', monospace; font-size: 0.8rem;">
                            <?= htmlspecialchars(substr($lastImportRun['checksum'], 0, 16), ENT_QUOTES, 'UTF-8') ?>...
                        </dd>

                        <?php if ($lastImportRun['ok']): ?>
                            <dt>Products Added:</dt>
                            <dd><?= number_format($lastImportRun['rows_ok']) ?></dd>

                            <dt>Products Updated:</dt>
                            <dd><?= number_format($lastImportRun['rows_updated']) ?></dd>

                            <dt>Rows Skipped:</dt>
                            <dd><?= number_format($lastImportRun['rows_skipped']) ?></dd>
                        <?php endif; ?>

                        <?php if ($lastImportRun['message']): ?>
                            <dt>Message:</dt>
                            <dd><?= htmlspecialchars($lastImportRun['message'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <p style="color: var(--muted); margin-top: 12px;">
                        No import runs recorded yet. Configure the settings above and enable auto-import.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="btn-save">Save Settings</button>
    </form>
</div>

<?php admin_render_layout_end(); ?>
