<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle approve request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'approve') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'Security Error', 'message' => 'Invalid CSRF token.'];
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $approveStmt = $pdo->prepare("
                UPDATE van_restock_requests
                SET status = 'approved', approved_at = NOW(), approved_by = :user_id
                WHERE id = :id AND status = 'submitted'
            ");
            $approveStmt->execute([':user_id' => $user['id'], ':id' => $requestId]);

            if ($approveStmt->rowCount() > 0) {
                $flashes[] = ['type' => 'success', 'title' => 'Approved', 'message' => 'Restock request has been approved.'];
            }
        }
    }
}

// Handle reject request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reject') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'Security Error', 'message' => 'Invalid CSRF token.'];
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($requestId > 0) {
            $rejectStmt = $pdo->prepare("
                UPDATE van_restock_requests
                SET status = 'rejected', rejection_reason = :reason, approved_at = NOW(), approved_by = :user_id
                WHERE id = :id AND status = 'submitted'
            ");
            $rejectStmt->execute([':reason' => $reason ?: null, ':user_id' => $user['id'], ':id' => $requestId]);

            if ($rejectStmt->rowCount() > 0) {
                $flashes[] = ['type' => 'success', 'title' => 'Rejected', 'message' => 'Restock request has been rejected.'];
            }
        }
    }
}

// Handle cancel request (warehouse can also cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'Security Error', 'message' => 'Invalid CSRF token.'];
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($requestId > 0) {
            $cancelStmt = $pdo->prepare("
                UPDATE van_restock_requests
                SET status = 'cancelled', rejection_reason = :reason, approved_at = NOW(), approved_by = :user_id
                WHERE id = :id AND status IN ('submitted', 'approved')
            ");
            $cancelStmt->execute([':reason' => $reason ?: 'Cancelled by warehouse', ':user_id' => $user['id'], ':id' => $requestId]);

            if ($cancelStmt->rowCount() > 0) {
                $flashes[] = ['type' => 'success', 'title' => 'Cancelled', 'message' => 'Restock request has been cancelled.'];
            }
        }
    }
}

// Handle fulfill request (transfer stock to van)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'fulfill') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'Security Error', 'message' => 'Invalid CSRF token.'];
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            try {
                $pdo->beginTransaction();

                // Get request details
                $requestStmt = $pdo->prepare("
                    SELECT r.*, u.name as rep_name
                    FROM van_restock_requests r
                    JOIN users u ON u.id = r.sales_rep_id
                    WHERE r.id = :id AND r.status = 'approved'
                ");
                $requestStmt->execute([':id' => $requestId]);
                $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception('Request not found or not approved.');
                }

                // Get items
                $itemsStmt = $pdo->prepare("
                    SELECT ri.*, p.item_name, p.quantity_on_hand as warehouse_stock
                    FROM van_restock_items ri
                    JOIN products p ON p.id = ri.product_id
                    WHERE ri.request_id = :request_id
                ");
                $itemsStmt->execute([':request_id' => $requestId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                // Process each item
                foreach ($items as $item) {
                    $qty = (float)$item['quantity'];
                    $productId = (int)$item['product_id'];
                    $repId = (int)$request['sales_rep_id'];

                    // Check warehouse stock
                    if ((float)$item['warehouse_stock'] < $qty) {
                        throw new Exception("Insufficient warehouse stock for {$item['item_name']}");
                    }

                    // Deduct from warehouse
                    $deductStmt = $pdo->prepare("
                        UPDATE products SET quantity_on_hand = quantity_on_hand - :qty WHERE id = :id
                    ");
                    $deductStmt->execute([':qty' => $qty, ':id' => $productId]);

                    // Add to van stock
                    $vanStockStmt = $pdo->prepare("
                        INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand)
                        VALUES (:rep_id, :product_id, :qty)
                        ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + :qty2
                    ");
                    $vanStockStmt->execute([
                        ':rep_id' => $repId,
                        ':product_id' => $productId,
                        ':qty' => $qty,
                        ':qty2' => $qty
                    ]);

                    // Log stock movement
                    $movementStmt = $pdo->prepare("
                        INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                        VALUES (:rep_id, :product_id, :qty, 'restock', :note, NOW())
                    ");
                    $movementStmt->execute([
                        ':rep_id' => $repId,
                        ':product_id' => $productId,
                        ':qty' => $qty,
                        ':note' => "Van restock request #$requestId"
                    ]);

                    // Update fulfilled quantity
                    $updateItemStmt = $pdo->prepare("
                        UPDATE van_restock_items SET fulfilled_quantity = :qty WHERE id = :id
                    ");
                    $updateItemStmt->execute([':qty' => $qty, ':id' => $item['id']]);
                }

                // Mark request as fulfilled
                $fulfillStmt = $pdo->prepare("
                    UPDATE van_restock_requests
                    SET status = 'fulfilled', fulfilled_at = NOW(), fulfilled_by = :user_id
                    WHERE id = :id
                ");
                $fulfillStmt->execute([':user_id' => $user['id'], ':id' => $requestId]);

                $pdo->commit();
                $flashes[] = ['type' => 'success', 'title' => 'Fulfilled', 'message' => "Stock transferred to {$request['rep_name']}'s van successfully."];
            } catch (Exception $e) {
                $pdo->rollBack();
                $flashes[] = ['type' => 'error', 'title' => 'Error', 'message' => $e->getMessage()];
            }
        }
    }
}

// Get pending/submitted requests
$pendingStmt = $pdo->prepare("
    SELECT r.*, u.name as rep_name, u.phone as rep_phone,
           (SELECT COUNT(*) FROM van_restock_items WHERE request_id = r.id) as item_count,
           (SELECT SUM(quantity) FROM van_restock_items WHERE request_id = r.id) as total_quantity
    FROM van_restock_requests r
    JOIN users u ON u.id = r.sales_rep_id
    WHERE r.status IN ('submitted', 'approved')
    ORDER BY r.submitted_at ASC
");
$pendingStmt->execute();
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent fulfilled/rejected/cancelled requests
$historyStmt = $pdo->prepare("
    SELECT r.*, u.name as rep_name,
           (SELECT COUNT(*) FROM van_restock_items WHERE request_id = r.id) as item_count,
           (SELECT SUM(quantity) FROM van_restock_items WHERE request_id = r.id) as total_quantity
    FROM van_restock_requests r
    JOIN users u ON u.id = r.sales_rep_id
    WHERE r.status IN ('fulfilled', 'rejected', 'cancelled')
    ORDER BY r.fulfilled_at DESC, r.approved_at DESC
    LIMIT 50
");
$historyStmt->execute();
$historyRequests = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

// Count requests by status for summary
$submittedCount = 0;
$approvedCount = 0;
foreach ($pendingRequests as $req) {
    if ($req['status'] === 'submitted') $submittedCount++;
    if ($req['status'] === 'approved') $approvedCount++;
}

warehouse_portal_render_layout_start([
    'title' => 'Van Restock Requests',
    'heading' => '🚚 Van Restock Requests',
    'subtitle' => 'Manage stock transfer requests from sales reps',
    'active' => 'van_restock',
    'user' => $user,
    'extra_head' => '<style>
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: var(--bg-panel);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 2px solid var(--border);
            transition: all 0.2s;
        }
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .summary-card.pending { border-left: 4px solid #f59e0b; }
        .summary-card.ready { border-left: 4px solid #3b82f6; }
        .summary-card.done { border-left: 4px solid #059669; }
        .summary-card .count {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 8px;
        }
        .summary-card.pending .count { color: #f59e0b; }
        .summary-card.ready .count { color: #3b82f6; }
        .summary-card.done .count { color: #059669; }
        .summary-card .label {
            font-size: 0.9rem;
            color: var(--muted);
            font-weight: 600;
        }
        .requests-container { max-width: 1200px; margin: 0 auto; }
        .section-card {
            background: var(--bg-panel);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0 0 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .request-card {
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            background: #f8fafc;
        }
        .request-card.submitted { border-color: #f59e0b; background: #fffbeb; }
        .request-card.approved { border-color: #3b82f6; background: #eff6ff; }
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .rep-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .rep-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .rep-name { font-weight: 700; font-size: 1.1rem; }
        .rep-phone { font-size: 0.9rem; color: var(--muted); }
        .request-status {
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .status-submitted { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }
        .items-table th, .items-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .items-table th {
            font-weight: 600;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .request-notes {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            margin: 12px 0;
            font-size: 0.9rem;
        }
        .request-meta {
            font-size: 0.85rem;
            color: var(--muted);
            margin-top: 12px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-success { background: #059669; color: white; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .flash {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .flash-success { background: #d1fae5; color: #065f46; }
        .flash-error { background: #fee2e2; color: #991b1b; }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--muted);
        }
        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }
        .history-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-fulfilled { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f3f4f6; color: #4b5563; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex !important; }
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 28px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transform: scale(1);
            animation: modalIn 0.2s ease-out;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-title { 
            font-size: 1.3rem; 
            font-weight: 700; 
            margin-bottom: 20px; 
            color: #1f2937;
        }
        .modal-content textarea {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: 10px;
            margin-bottom: 20px;
            resize: none;
            height: 120px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .modal-content textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
    </style>',
]);

// Display flash messages
foreach ($flashes as $flash) {
    echo '<div class="flash flash-' . $flash['type'] . '">';
    echo '<strong>' . htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') . ':</strong> ';
    echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
}
?>

<!-- Summary Cards -->
<div class="summary-cards">
    <div class="summary-card pending">
        <div class="count"><?= $submittedCount ?></div>
        <div class="label">⏳ Awaiting Approval</div>
    </div>
    <div class="summary-card ready">
        <div class="count"><?= $approvedCount ?></div>
        <div class="label">✅ Ready to Fulfill</div>
    </div>
    <div class="summary-card done">
        <div class="count"><?= count($historyRequests) ?></div>
        <div class="label">📜 Recent History</div>
    </div>
</div>

<div class="requests-container">
    <!-- Pending Requests -->
    <div class="section-card">
        <h2 class="section-title">⏳ Pending Restock Requests (<?= count($pendingRequests) ?>)</h2>

        <?php if (empty($pendingRequests)): ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 12px;">📭</div>
                <p>No pending restock requests</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendingRequests as $request):
                // Get items for this request
                $itemsStmt = $pdo->prepare("
                    SELECT ri.*, p.sku, p.item_name, p.quantity_on_hand as warehouse_stock
                    FROM van_restock_items ri
                    JOIN products p ON p.id = ri.product_id
                    WHERE ri.request_id = :request_id
                ");
                $itemsStmt->execute([':request_id' => $request['id']]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
                <div class="request-card <?= $request['status'] ?>">
                    <div class="request-header">
                        <div class="rep-info">
                            <div class="rep-avatar"><?= strtoupper(substr($request['rep_name'], 0, 1)) ?></div>
                            <div>
                                <div class="rep-name"><?= htmlspecialchars($request['rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="rep-phone"><?= htmlspecialchars($request['rep_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <span class="request-status status-<?= $request['status'] ?>">
                            <?= $request['status'] === 'submitted' ? '📨 Submitted' : '✅ Approved' ?>
                        </span>
                    </div>

                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Requested</th>
                                <th>Warehouse Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item):
                                $stockOk = (float)$item['warehouse_stock'] >= (float)$item['quantity'];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><code><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><strong><?= number_format((float)$item['quantity'], 1) ?></strong></td>
                                    <td style="color: <?= $stockOk ? '#059669' : '#dc2626' ?>;">
                                        <?= number_format((float)$item['warehouse_stock'], 1) ?>
                                        <?= !$stockOk ? '⚠️' : '' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($request['notes']): ?>
                        <div class="request-notes">
                            <strong>Notes:</strong> <?= htmlspecialchars($request['notes'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <div class="request-meta">
                        Submitted: <?= date('M j, Y g:i A', strtotime($request['submitted_at'])) ?>
                        | <?= (int)$request['item_count'] ?> products,
                        <?= number_format((float)$request['total_quantity'], 1) ?> units
                    </div>

                    <div class="action-buttons">
                        <?php if ($request['status'] === 'submitted'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                <button type="submit" class="btn btn-success">✅ Approve</button>
                            </form>
                            <button type="button" class="btn btn-danger" onclick="showRejectModal(<?= $request['id'] ?>)">❌ Reject</button>
                            <button type="button" class="btn btn-secondary" onclick="showCancelModal(<?= $request['id'] ?>)">🚫 Cancel</button>
                        <?php elseif ($request['status'] === 'approved'): ?>
                            <form method="POST" style="display: inline;"
                                onsubmit="return confirm('Transfer stock to van? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="fulfill">
                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                <button type="submit" class="btn btn-primary">🚚 Fulfill & Transfer Stock</button>
                            </form>
                            <button type="button" class="btn btn-secondary" onclick="showCancelModal(<?= $request['id'] ?>)">🚫 Cancel</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- History -->
    <?php if (!empty($historyRequests)): ?>
        <div class="section-card">
            <h2 class="section-title">📜 Recent History</h2>
            <?php foreach ($historyRequests as $history): ?>
                <div class="history-item">
                    <div>
                        <strong><?= htmlspecialchars($history['rep_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span style="color: var(--muted);">- <?= (int)$history['item_count'] ?> products,
                            <?= number_format((float)$history['total_quantity'], 1) ?> units</span>
                        <br>
                        <small style="color: var(--muted);">
                            <?= date('M j, Y g:i A', strtotime($history['fulfilled_at'] ?? $history['approved_at'])) ?>
                        </small>
                    </div>
                    <span class="history-status status-<?= $history['status'] ?>">
                        <?php
                        $statusLabels = [
                            'fulfilled' => '✅ Fulfilled',
                            'rejected' => '❌ Rejected',
                            'cancelled' => '🚫 Cancelled'
                        ];
                        echo $statusLabels[$history['status']] ?? $history['status'];
                        ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal" id="rejectModal">
    <div class="modal-content">
        <div class="modal-title">❌ Reject Request</div>
        <p style="color: var(--muted); margin-bottom: 16px; font-size: 0.95rem;">
            This will reject the restock request. The sales rep will be notified.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rejectRequestId" value="">
            <textarea name="reason" placeholder="Reason for rejection (optional)..."></textarea>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal" id="cancelModal">
    <div class="modal-content">
        <div class="modal-title">🚫 Cancel Request</div>
        <p style="color: var(--muted); margin-bottom: 16px; font-size: 0.95rem;">
            This will cancel the restock request without fulfilling it.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="request_id" id="cancelRequestId" value="">
            <textarea name="reason" placeholder="Reason for cancellation (optional)..."></textarea>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideCancelModal()">Close</button>
                <button type="submit" class="btn btn-danger">Cancel Request</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showRejectModal(requestId) {
        document.getElementById('rejectRequestId').value = requestId;
        document.getElementById('rejectModal').classList.add('show');
    }

    function hideRejectModal() {
        document.getElementById('rejectModal').classList.remove('show');
    }

    function showCancelModal(requestId) {
        document.getElementById('cancelRequestId').value = requestId;
        document.getElementById('cancelModal').classList.add('show');
    }

    function hideCancelModal() {
        document.getElementById('cancelModal').classList.remove('show');
    }

    document.getElementById('rejectModal').addEventListener('click', function(e) {
        if (e.target === this) hideRejectModal();
    });

    document.getElementById('cancelModal').addEventListener('click', function(e) {
        if (e.target === this) hideCancelModal();
    });
</script>

<?php
warehouse_portal_render_layout_end();
