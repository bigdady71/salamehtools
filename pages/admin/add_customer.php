<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Add New Customer';
$repId = (int)$user['id'];

// AJAX endpoint: Get cities by governorate
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_cities') {
    header('Content-Type: application/json');
    $governorate = trim((string)($_GET['governorate'] ?? ''));

    if ($governorate === '') {
        echo json_encode(['cities' => []]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT DISTINCT city_name FROM lebanon_cities WHERE governorate = ? ORDER BY city_name");
        $stmt->execute([$governorate]);
        $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['cities' => $cities]);
    } catch (PDOException $e) {
        echo json_encode(['cities' => [], 'error' => 'Database error']);
    }
    exit;
}

// AJAX endpoint: Add new city
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_city') {
    header('Content-Type: application/json');
    $cityName = trim((string)($_POST['city_name'] ?? ''));
    $governorate = trim((string)($_POST['governorate'] ?? ''));

    if ($cityName === '' || $governorate === '') {
        echo json_encode(['success' => false, 'error' => 'City name and governorate are required']);
        exit;
    }

    try {
        // Check if city already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM lebanon_cities WHERE city_name = ? AND governorate = ?");
        $checkStmt->execute([$cityName, $governorate]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => true, 'message' => 'City already exists']);
            exit;
        }

        // Add new city
        $insertStmt = $pdo->prepare("INSERT INTO lebanon_cities (city_name, governorate) VALUES (?, ?)");
        $insertStmt->execute([$cityName, $governorate]);
        echo json_encode(['success' => true, 'message' => 'City added successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Fetch distinct governorates from database
$governorates = [];
try {
    $govStmt = $pdo->query("SELECT DISTINCT governorate FROM lebanon_cities ORDER BY governorate");
    $governorates = $govStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Fallback to hardcoded list if table doesn't exist yet
    $governorates = ['عكار', 'جبل لبنان', 'بعلبك-الهرمل', 'النبطية', 'الشمال','الجنوب', 'البقاع'];
}

// Handle customer creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_customer') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid CSRF token');
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $governorate = trim((string)($_POST['governorate'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $shopType = trim((string)($_POST['shop_type'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));
        $enablePortal = isset($_POST['enable_portal']) && $_POST['enable_portal'] === '1';

        $errors = [];

        // Validation
        if ($name === '') {
            $errors[] = 'Customer name is required.';
        }

        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } else {
            // Check for duplicate phone
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE phone = ?");
            $checkStmt->execute([$phone]);
            if ((int)$checkStmt->fetchColumn() > 0) {
                $errors[] = 'A customer with this phone number already exists.';
            }
        }

        if ($governorate === '') {
            $errors[] = 'Governorate is required.';
        }

        if ($enablePortal) {
            if ($password === '') {
                $errors[] = 'Password is required to enable portal access.';
            } elseif (strlen($password) < 4) {
                $errors[] = 'Password must be at least 4 characters long.';
            }
        }

        if (!empty($errors)) {
            flash('error', 'Please fix the following errors: ' . implode(' ', $errors));
        } else {
            try {
                $pdo->beginTransaction();

                // Insert customer with password
                $insertStmt = $pdo->prepare("
                    INSERT INTO customers (name, phone, location, governorate, city, address, shop_type, password_hash, assigned_sales_rep_id, login_enabled, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");

                $insertStmt->execute([
                    $name,
                    $phone,
                    $location !== '' ? $location : null,
                    $governorate !== '' ? $governorate : null,
                    $city !== '' ? $city : null,
                    $address !== '' ? $address : null,
                    $shopType !== '' ? $shopType : null,
                    $enablePortal && $password !== '' ? $password : null, // Store plain text password
                    $repId, // Automatically assign to current sales rep
                    $enablePortal ? 1 : 0
                ]);

                $pdo->commit();

                if ($enablePortal && $password) {
                    flash('success', "Customer \"{$name}\" has been successfully created with portal access!<br>
                        <strong>Login URL:</strong> <a href='/salamehtools/pages/login.php' target='_blank'>Customer Login</a><br>
                        <strong>Username:</strong> {$phone} or {$name}<br>
                        <strong>Password:</strong> {$password}<br>
                        <em>Please share these credentials with the customer.</em>");
                } else {
                    flash('success', "Customer \"{$name}\" has been successfully created and assigned to you.");
                }

                header('Location: users.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create customer: " . $e->getMessage());
                flash('error', 'Unable to create customer. Please try again.');
            }
        }
    }
}

$flashes = consume_flashes();

sales_portal_render_layout_start([
    'title' => 'إضافة زبون جديد',
    'heading' => '➕ إضافة زبون جديد',
    'subtitle' => 'إنشاء زبون جديد سيتم تعيينه لك تلقائياً',
    'user' => $user,
    'active' => 'add_customer' // Highlight "Add Customer" in sidebar
]);
?>

<style>
.form-container {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    padding: 32px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 24px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-label {
    font-weight: 600;
    font-size: 0.9rem;
    color: #374151;
}

.form-label.required::after {
    content: ' *';
    color: #ef4444;
}

.form-input,
.form-textarea {
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 100px;
}

.form-help {
    font-size: 0.875rem;
    color: #6b7280;
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid #e5e7eb;
    background: white;
    color: #111827;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.95rem;
}

.btn:hover {
    background: #f3f4f6;
}

.btn-primary {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
}

.btn-primary:hover {
    background: var(--accent-2);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.btn-secondary {
    background: #6b7280;
    border-color: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.alert {
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 24px;
    border-left: 4px solid;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-left-color: #10b981;
    color: #065f46;
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border-left-color: #ef4444;
    color: #991b1b;
}

.info-box {
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 24px;
}

.info-box-title {
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-box-content {
    font-size: 0.9rem;
    color: #1e3a8a;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

/* City Autocomplete Styles */
.city-autocomplete-wrapper {
    position: relative;
}

.city-autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--accent);
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.city-autocomplete-dropdown.show {
    display: block;
}

.city-option {
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s;
}

.city-option:last-child {
    border-bottom: none;
}

.city-option:hover,
.city-option.highlighted {
    background: #f0f9ff;
}

.city-option.add-new {
    background: #ecfdf5;
    color: #047857;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.city-option.add-new:hover {
    background: #d1fae5;
}

.city-option .match-highlight {
    background: #fef08a;
    padding: 0 2px;
    border-radius: 2px;
}

.city-input-wrapper {
    position: relative;
}

.city-input-wrapper .clear-btn {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    font-size: 1.2rem;
    padding: 0;
    display: none;
}

.city-input-wrapper .clear-btn.show {
    display: block;
}

.city-input-wrapper .clear-btn:hover {
    color: #ef4444;
}

.no-results {
    padding: 12px 16px;
    color: #6b7280;
    text-align: center;
    font-style: italic;
}
</style>

<!-- Flash Messages -->
<?php foreach ($flashes as $msg): ?>
<div class="alert alert-<?= $msg['type'] ?>">
    <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endforeach; ?>

<!-- Info Box -->
<div class="info-box">
    <div class="info-box-title">
        ℹ️ Auto-Assignment
    </div>
    <div class="info-box-content">
        All customers you create will be automatically assigned to you. You can view and manage them in the "My Customers" page.
    </div>
</div>

<!-- Customer Form -->
<div class="form-container">
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_customer">

        <div class="form-grid">
            <!-- Customer Name -->
            <div class="form-group full-width">
                <label class="form-label required" for="name">Customer Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    class="form-input"
                    placeholder="Enter customer name or business name"
                    required
                    autofocus
                    value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Full name or business name of the customer</div>
            </div>

            <!-- Phone Number -->
            <div class="form-group">
                <label class="form-label required" for="phone">Phone Number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    class="form-input"
                    placeholder="+961 XX XXX XXX"
                    required
                    value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Primary contact number (used as username for login)</div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label class="form-label required" for="password" id="password-label">Password</label>
                <input
                    type="text"
                    id="password"
                    name="password"
                    class="form-input"
                    placeholder="Enter customer password"
                    required
                    value="<?= htmlspecialchars($_POST['password'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Required if enabling portal access (min 4 characters)</div>
            </div>

            <!-- Shop Type -->
            <div class="form-group">
                <label class="form-label" for="shop_type">Shop Type</label>
                <input
                    type="text"
                    id="shop_type"
                    name="shop_type"
                    class="form-input"
                    placeholder="e.g., Grocery, Pharmacy, Restaurant"
                    value="<?= htmlspecialchars($_POST['shop_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Type of business or products they sell</div>
            </div>

            <!-- Governorate -->
            <div class="form-group">
                <label class="form-label" for="governorate">Governorate - المحافظة <span style="color:red;">*</span></label>
                <select
                    id="governorate"
                    name="governorate"
                    class="form-input"
                    required
                    onchange="loadCities(this.value)"
                >
                    <option value="">-- اختر المحافظة --</option>
                    <?php foreach ($governorates as $gov): ?>
                        <option value="<?= htmlspecialchars($gov, ENT_QUOTES, 'UTF-8') ?>" <?= (($_POST['governorate'] ?? '') === $gov) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($gov, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-help">اختر المحافظة أولاً</div>
            </div>

            <!-- City -->
            <div class="form-group">
                <label class="form-label" for="city">City - المدينة / البلدة <span style="color:red;">*</span></label>
                <div class="city-autocomplete-wrapper">
                    <div class="city-input-wrapper">
                        <input
                            type="text"
                            id="city"
                            name="city"
                            class="form-input"
                            placeholder="-- اختر المحافظة أولاً --"
                            autocomplete="off"
                            required
                            disabled
                            value="<?= htmlspecialchars($_POST['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button type="button" class="clear-btn" id="cityClearBtn" title="مسح">&times;</button>
                    </div>
                    <div class="city-autocomplete-dropdown" id="cityDropdown"></div>
                </div>
                <div class="form-help">ابدأ بالكتابة للبحث أو أضف مدينة جديدة</div>
            </div>

            <!-- Full Address -->
            <div class="form-group full-width">
                <label class="form-label" for="address">Full Address</label>
                <textarea
                    id="address"
                    name="address"
                    class="form-textarea"
                    placeholder="Enter street name, building number, floor, additional details..."
                ><?= htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-help">Complete street address and details for delivery</div>
            </div>

            <!-- Location (deprecated - keep for backward compatibility) -->
            <input type="hidden" name="location" value=""

>

            <!-- Enable Portal Access -->
            <div class="form-group full-width">
                <div style="background: rgba(16, 185, 129, 0.05); border: 2px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 20px;">
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; font-weight: 600; font-size: 1rem;">
                        <input
                            type="checkbox"
                            name="enable_portal"
                            id="enable_portal"
                            value="1"
                            style="width: 20px; height: 20px; cursor: pointer;"
                            <?= !isset($_POST['enable_portal']) || $_POST['enable_portal'] === '1' ? 'checked' : '' ?>
                        >
                        <span style="color: #065f46;">🌐 Enable Customer Portal Access</span>
                    </label>
                    <div style="margin-left: 32px; margin-top: 8px; font-size: 0.9rem; color: #047857;">
                        Allow this customer to access the online portal to view orders, invoices, make payments, and browse products.
                        <br><strong>Note:</strong> Password is required for portal access. Customer can login using phone number or name.
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Make password required when portal access is enabled
        document.getElementById('enable_portal').addEventListener('change', function() {
            const passwordInput = document.getElementById('password');
            const passwordLabel = document.getElementById('password-label');
            if (this.checked) {
                passwordInput.required = true;
                passwordLabel.classList.add('required');
            } else {
                passwordInput.required = false;
                passwordLabel.classList.remove('required');
            }
        });

        // City autocomplete variables
        let allCities = [];
        let highlightedIndex = -1;
        const cityInput = document.getElementById('city');
        const cityDropdown = document.getElementById('cityDropdown');
        const cityClearBtn = document.getElementById('cityClearBtn');
        const governorateSelect = document.getElementById('governorate');

        // Load cities based on selected governorate
        function loadCities(governorate) {
            allCities = [];
            cityInput.value = '';
            cityInput.placeholder = 'جاري التحميل...';
            cityInput.disabled = true;
            cityClearBtn.classList.remove('show');
            cityDropdown.classList.remove('show');

            if (!governorate) {
                cityInput.placeholder = '-- اختر المحافظة أولاً --';
                return;
            }

            fetch('?action=get_cities&governorate=' + encodeURIComponent(governorate))
                .then(response => response.json())
                .then(data => {
                    allCities = data.cities || [];
                    cityInput.disabled = false;
                    cityInput.placeholder = 'ابدأ بالكتابة للبحث عن المدينة...';

                    // If there was a previous value, keep it
                    <?php if (isset($_POST['city']) && $_POST['city'] !== ''): ?>
                    const prevCity = '<?= htmlspecialchars($_POST['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
                    if (prevCity) {
                        cityInput.value = prevCity;
                        cityClearBtn.classList.add('show');
                    }
                    <?php endif; ?>
                })
                .catch(error => {
                    console.error('Error loading cities:', error);
                    cityInput.disabled = false;
                    cityInput.placeholder = 'خطأ في التحميل - يمكنك الكتابة يدوياً';
                });
        }

        // Filter and display cities
        function filterCities(query) {
            const trimmedQuery = query.trim().toLowerCase();
            cityDropdown.innerHTML = '';
            highlightedIndex = -1;

            if (trimmedQuery === '') {
                // Show all cities when input is focused but empty
                if (allCities.length > 0) {
                    allCities.forEach((city, index) => {
                        const div = document.createElement('div');
                        div.className = 'city-option';
                        div.textContent = city;
                        div.dataset.index = index;
                        div.onclick = () => selectCity(city);
                        cityDropdown.appendChild(div);
                    });
                    cityDropdown.classList.add('show');
                }
                return;
            }

            // Filter matching cities
            const matches = allCities.filter(city =>
                city.toLowerCase().includes(trimmedQuery)
            );

            if (matches.length > 0) {
                matches.forEach((city, index) => {
                    const div = document.createElement('div');
                    div.className = 'city-option';
                    div.dataset.index = index;

                    // Highlight matching text
                    const lowerCity = city.toLowerCase();
                    const matchIndex = lowerCity.indexOf(trimmedQuery);
                    if (matchIndex >= 0) {
                        div.innerHTML =
                            escapeHtml(city.substring(0, matchIndex)) +
                            '<span class="match-highlight">' + escapeHtml(city.substring(matchIndex, matchIndex + trimmedQuery.length)) + '</span>' +
                            escapeHtml(city.substring(matchIndex + trimmedQuery.length));
                    } else {
                        div.textContent = city;
                    }

                    div.onclick = () => selectCity(city);
                    cityDropdown.appendChild(div);
                });
            }

            // Check if exact match exists
            const exactMatch = allCities.some(city => city.toLowerCase() === trimmedQuery);

            // Add "Add new city" option if no exact match
            if (!exactMatch && query.trim() !== '') {
                const addNewDiv = document.createElement('div');
                addNewDiv.className = 'city-option add-new';
                addNewDiv.innerHTML = '<span>➕</span> إضافة "' + escapeHtml(query.trim()) + '" كمدينة جديدة';
                addNewDiv.onclick = () => addNewCity(query.trim());
                cityDropdown.appendChild(addNewDiv);
            }

            if (cityDropdown.children.length > 0) {
                cityDropdown.classList.add('show');
            } else {
                cityDropdown.classList.remove('show');
            }
        }

        // Select a city
        function selectCity(city) {
            cityInput.value = city;
            cityDropdown.classList.remove('show');
            cityClearBtn.classList.add('show');
        }

        // Add new city to database
        function addNewCity(cityName) {
            const governorate = governorateSelect.value;
            if (!governorate) {
                alert('يرجى اختيار المحافظة أولاً');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_city');
            formData.append('city_name', cityName);
            formData.append('governorate', governorate);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Add to local list and select it
                    if (!allCities.includes(cityName)) {
                        allCities.push(cityName);
                        allCities.sort();
                    }
                    selectCity(cityName);
                } else {
                    alert('خطأ في إضافة المدينة: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error adding city:', error);
                // Still allow selection even if save fails
                selectCity(cityName);
            });
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Event listeners for city input
        cityInput.addEventListener('input', function() {
            filterCities(this.value);
            cityClearBtn.classList.toggle('show', this.value.length > 0);
        });

        cityInput.addEventListener('focus', function() {
            if (governorateSelect.value) {
                filterCities(this.value);
            }
        });

        cityInput.addEventListener('keydown', function(e) {
            const options = cityDropdown.querySelectorAll('.city-option');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightedIndex = Math.min(highlightedIndex + 1, options.length - 1);
                updateHighlight(options);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightedIndex = Math.max(highlightedIndex - 1, 0);
                updateHighlight(options);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlightedIndex >= 0 && options[highlightedIndex]) {
                    options[highlightedIndex].click();
                }
            } else if (e.key === 'Escape') {
                cityDropdown.classList.remove('show');
            }
        });

        function updateHighlight(options) {
            options.forEach((opt, i) => {
                opt.classList.toggle('highlighted', i === highlightedIndex);
                if (i === highlightedIndex) {
                    opt.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        // Clear button
        cityClearBtn.addEventListener('click', function() {
            cityInput.value = '';
            cityInput.focus();
            cityClearBtn.classList.remove('show');
            filterCities('');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!cityInput.contains(e.target) && !cityDropdown.contains(e.target)) {
                cityDropdown.classList.remove('show');
            }
        });

        // Load cities on page load if governorate is already selected
        document.addEventListener('DOMContentLoaded', function() {
            const governorate = governorateSelect.value;
            if (governorate) {
                loadCities(governorate);
            }
        });
        </script>

        <div class="form-actions">
            <a href="users.php" class="btn btn-secondary">
                Cancel
            </a>
            <button type="submit" class="btn btn-success">
                ✓ Create Customer
            </button>
        </div>
    </form>
</div>

<?php sales_portal_render_layout_end(); ?>
