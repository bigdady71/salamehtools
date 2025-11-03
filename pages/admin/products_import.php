<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/import_products.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$title = 'Admin · Import Products';
$defaultPath = '';
$summary = null;
$errors = [];
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadType = $_POST['upload_type'] ?? 'path';
    
    if ($uploadType === 'file' && isset($_FILES['excel_file'])) {
        // Handle file upload
        $uploadedFile = $_FILES['excel_file'];
        
        if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            $fileExt = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            
            if (!in_array($uploadedFile['type'], $allowedTypes) && !in_array($fileExt, ['xls', 'xlsx'])) {
                $errors[] = 'Invalid file type. Please upload an Excel file (.xls or .xlsx).';
            } elseif ($uploadedFile['size'] > 10 * 1024 * 1024) { // 10MB limit
                $errors[] = 'File too large. Maximum size is 10MB.';
            } else {
                $uploadPath = sys_get_temp_dir() . '/' . uniqid('product_import_') . '.' . $fileExt;
                if (move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
                    $path = $uploadPath;
                } else {
                    $errors[] = 'Failed to save uploaded file.';
                }
            }
        } else {
            $errors[] = 'File upload error: ' . $uploadedFile['error'];
        }
    } else {
        // Handle file path
        $path = trim($_POST['path'] ?? '');
        if ($path === '') {
            $errors[] = 'Please provide a file path or upload a file.';
        } elseif (!file_exists($path)) {
            $errors[] = "File not found: {$path}";
        } elseif (!is_readable($path)) {
            $errors[] = "Cannot read file: {$path}";
        }
    }
    
    if (empty($errors) && isset($path)) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Use the reusable import function
            $result = import_products_from_path($pdo, $path);

            if ($result['ok']) {
                $pdo->commit();
                $summary = [
                    'inserted' => $result['inserted'],
                    'updated' => $result['updated'],
                    'skipped' => $result['skipped'],
                    'total' => $result['total']
                ];
                $warnings = array_merge($warnings, $result['warnings']);
            } else {
                $pdo->rollBack();
                $errors = array_merge($errors, $result['errors']);
            }

            // Clean up temp file if uploaded
            if (isset($uploadPath) && file_exists($uploadPath)) {
                unlink($uploadPath);
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Import failed: ' . $e->getMessage();

            // Clean up temp file on error
            if (isset($uploadPath) && file_exists($uploadPath)) {
                unlink($uploadPath);
            }
        }
    }
}

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Import Products',
    'subtitle' => 'Bulk import products from Excel',
    'active' => 'products',
    'user' => $user,
]);
?>

<style>
    .import-section {
        background: var(--bg-panel);
        border-radius: 16px;
        padding: 32px;
        margin-bottom: 24px;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-label {
        display: block;
        margin-bottom: 8px;
        color: var(--muted);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .form-input {
        width: 100%;
        padding: 12px 16px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 8px;
        color: var(--text);
        font-size: 1rem;
    }
    .form-input:focus {
        outline: none;
        border-color: var(--accent-2);
    }
    .upload-tabs {
        display: flex;
        gap: 4px;
        margin-bottom: 24px;
    }
    .upload-tab {
        padding: 10px 20px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 8px 8px 0 0;
        cursor: pointer;
        transition: all 0.2s;
    }
    .upload-tab.active {
        background: var(--accent-2);
        color: #fff;
        border-color: var(--accent-2);
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    .submit-btn {
        padding: 12px 32px;
        background: var(--accent);
        color: #000;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .submit-btn:hover {
        background: #00e079;
        transform: translateY(-1px);
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
    .result-warning {
        background: rgba(255, 209, 102, 0.2);
        border: 1px solid rgba(255, 209, 102, 0.3);
        color: #ffd166;
    }
    .result-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        margin-top: 16px;
    }
    .stat-item {
        text-align: center;
    }
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        display: block;
    }
    .stat-label {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        opacity: 0.8;
    }
    .help-text {
        margin-top: 16px;
        padding: 16px;
        background: rgba(74, 125, 255, 0.1);
        border: 1px solid rgba(74, 125, 255, 0.2);
        border-radius: 8px;
        font-size: 0.9rem;
        color: #b9c9ff;
    }
    .error-list {
        margin: 12px 0 0;
        padding-left: 20px;
        font-size: 0.9rem;
    }
</style>

<section class="import-section">
    <?php if ($errors): ?>
        <div class="result-box result-error">
            <strong>Import Errors:</strong>
            <ul class="error-list">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if ($warnings): ?>
        <div class="result-box result-warning">
            <strong>Warnings:</strong>
            <ul class="error-list">
                <?php foreach ($warnings as $warning): ?>
                    <li><?= htmlspecialchars($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if ($summary): ?>
        <div class="result-box result-success">
            <strong>Import Completed Successfully!</strong>
            <div class="result-stats">
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($summary['inserted']) ?></span>
                    <span class="stat-label">Products Added</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($summary['updated']) ?></span>
                    <span class="stat-label">Products Updated</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($summary['skipped']) ?></span>
                    <span class="stat-label">Rows Skipped</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($summary['total']) ?></span>
                    <span class="stat-label">Total Processed</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <form method="post" enctype="multipart/form-data">
        <div class="upload-tabs">
            <div class="upload-tab active" onclick="switchTab('file')">Upload File</div>
            <div class="upload-tab" onclick="switchTab('path')">File Path</div>
        </div>
        
        <div id="file-tab" class="tab-content active">
            <div class="form-group">
                <label class="form-label">Upload Excel File</label>
                <input type="file" name="excel_file" class="form-input" accept=".xls,.xlsx">
                <input type="hidden" name="upload_type" value="file">
            </div>
        </div>
        
        <div id="path-tab" class="tab-content">
            <div class="form-group">
                <label class="form-label">Excel File Path (UNC or Local)</label>
                <input type="text" name="path" class="form-input" 
                       placeholder="e.g., C:\imports\products.xlsx or \\server\share\products.xlsx"
                       value="<?= htmlspecialchars($defaultPath) ?>">
            </div>
        </div>
        
        <button type="submit" class="submit-btn">Import Products</button>
    </form>
    
    <div class="help-text">
        <strong>Expected Excel Format:</strong><br>
        Row 1 should contain these headers: Type, Name, SecondName, Code, Unit, SalePrice, 
        WholeSalePrice1, WholeSalePrice2, Description, ExistQuantity, 
        TopCat, TopCatName, MidCat, MidCatName<br><br>
        <strong>Notes:</strong>
        <ul style="margin: 8px 0 0 20px;">
            <li>Code (SKU) and Name are required fields</li>
            <li>Products are matched by SKU for updates</li>
            <li>Decimal separators can be either . or ,</li>
            <li>Maximum file size for uploads: 10MB</li>
            <li>Empty rows will be automatically skipped</li>
        </ul>
    </div>
</section>

<script>
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.upload-tab').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    
    // Update tab content
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tab + '-tab').classList.add('active');
    
    // Update hidden input
    document.querySelector('input[name="upload_type"]').value = tab;
}
</script>

<?php admin_render_layout_end(); ?>
