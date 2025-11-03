<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Settings';

// Helper function to get setting value
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = :key");
    $stmt->execute([':key' => $key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? (string)$result : $default;
}

// Helper function to set setting value
function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO settings (k, v) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':key' => $key, ':value' => $value]);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'save_settings') {
        try {
            $pdo->beginTransaction();

            // Import settings
            $watchPath = trim($_POST['import_products_watch_path'] ?? '');
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
</style>

<div class="settings-container">
    <?php foreach ($flashes as $flash): ?>
        <div class="result-box result-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>" style="margin-bottom: 20px;">
            <?php if ($flash['title']): ?>
                <strong><?= htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <?php endif; ?>
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endforeach; ?>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="settings-section">
            <h2 class="section-title">Product Auto-Import</h2>
            <p class="section-subtitle">Configure automatic product imports from a watched file path</p>

            <div class="form-row">
                <label class="form-label" for="import_products_watch_path">
                    Watched File Path
                </label>
                <input
                    type="text"
                    id="import_products_watch_path"
                    name="import_products_watch_path"
                    class="form-input"
                    value="<?= htmlspecialchars($watchPath, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="e.g., C:\imports\products.xlsx or \\server\share\products.xlsx"
                >
                <p class="form-help">
                    Absolute path to the Excel file that will be monitored for changes.
                    Leave empty to disable path-based imports.
                </p>
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
