<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/admin_page.php';

// Admin only
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied - Admins only');
}

$adminId = (int)$user['id'];
$pdo = db();
$flashes = [];

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        $returnId = (int)($_POST['return_id'] ?? 0);

        if ($returnId > 0) {
            try {
                // Verify return exists and is pending
                $checkStmt = $pdo->prepare("SELECT * FROM customer_returns WHERE id = :id");
                $checkStmt->execute([':id' => $returnId]);
                $return = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$return) {
                    $flashes[] = ['type' => 'error', 'message' => 'Return not found.'];
                } elseif ($action === 'approve' && $return['status'] === 'pending_cash_approval') {
                    $stmt = $pdo->prepare("
                        UPDATE customer_returns
                        SET status = 'cash_approved', approved_by = :admin_id, approved_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([':admin_id' => $adminId, ':id' => $returnId]);
                    $flashes[] = ['type' => 'success', 'message' => "Return #{$return['return_number']} approved for cash refund."];

                } elseif ($action === 'reject' && $return['status'] === 'pending_cash_approval') {
                    $reason = trim($_POST['rejection_reason'] ?? '');
                    $stmt = $pdo->prepare("
                        UPDATE customer_returns
                        SET status = 'cash_rejected', approved_by = :admin_id, approved_at = NOW(), rejection_reason = :reason
                        WHERE id = :id
                    ");
                    $stmt->execute([':admin_id' => $adminId, ':id' => $returnId, ':reason' => $reason]);
                    $flashes[] = ['type' => 'success', 'message' => "Return #{$return['return_number']} rejected."];

                } elseif ($action === 'mark_paid' && $return['status'] === 'cash_approved') {
                    $stmt = $pdo->prepare("
                        UPDATE customer_returns
                        SET status = 'cash_paid', cash_paid_by = :admin_id, cash_paid_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([':admin_id' => $adminId, ':id' => $returnId]);
                    $flashes[] = ['type' => 'success', 'message' => "Return #{$return['return_number']} marked as paid."];

                } else {
                    $flashes[] = ['type' => 'error', 'message' => 'Invalid action for current status.'];
                }
            } catch (Exception $e) {
                error_log("Cash refund approval error: " . $e->getMessage());
                $flashes[] = ['type' => 'error', 'message' => 'Database error occurred.'];
            }
        }
    }
}

// Filters
$statusFilter = $_GET['status'] ?? 'pending_cash_approval';
$repFilter = $_GET['rep'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$whereConditions = ["cr.refund_method = 'cash'"];
$params = [];

if ($statusFilter !== 'all') {
    $whereConditions[] = 'cr.status = :status';
    $params[':status'] = $statusFilter;
}

if ($repFilter !== 'all') {
    $whereConditions[] = 'cr.sales_rep_id = :rep_id';
    $params[':rep_id'] = (int)$repFilter;
}

if ($dateFrom) {
    $whereConditions[] = 'DATE(cr.created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = 'DATE(cr.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Get returns
$returnsStmt = $pdo->prepare("
    SELECT
        cr.*,
        c.name as customer_name,
        c.phone as customer_phone,
        u.name as sales_rep_name,
        i.invoice_number,
        approver.name as approved_by_name,
        payer.name as paid_by_name
    FROM customer_returns cr
    JOIN customers c ON c.id = cr.customer_id
    JOIN users u ON u.id = cr.sales_rep_id
    LEFT JOIN invoices i ON i.id = cr.invoice_id
    LEFT JOIN users approver ON approver.id = cr.approved_by
    LEFT JOIN users payer ON payer.id = cr.cash_paid_by
    WHERE {$whereClause}
    ORDER BY
        CASE cr.status
            WHEN 'pending_cash_approval' THEN 1
            WHEN 'cash_approved' THEN 2
            ELSE 3
        END,
        cr.created_at DESC
");
$returnsStmt->execute($params);
$returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_count,
        SUM(total_usd) as total_usd,
        SUM(CASE WHEN status = 'pending_cash_approval' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'pending_cash_approval' THEN total_usd ELSE 0 END) as pending_usd,
        SUM(CASE WHEN status = 'cash_approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'cash_approved' THEN total_usd ELSE 0 END) as approved_usd,
        SUM(CASE WHEN status = 'cash_paid' THEN total_usd ELSE 0 END) as paid_usd
    FROM customer_returns cr
    WHERE {$whereClause}
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

// Get sales reps for filter
$repsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$salesReps = $repsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

// Start admin layout
admin_render_layout_start([
    'title' => 'Cash Refund Approvals',
    'heading' => 'Cash Refund Approvals',
    'subtitle' => 'Review and approve customer cash refund requests',
    'active' => 'cash_refunds',
    'user' => $user,
    'extra_head' => '<style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .stat-card .label {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 700;
        }
        .stat-card .value.pending { color: #f59e0b; }
        .stat-card .value.approved { color: #3b82f6; }
        .stat-card .value.paid { color: #22c55e; }
        .filters-bar {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--border);
        }
        .filter-btn {
            padding: 10px 18px;
            border: 2px solid var(--border);
            background: var(--bg-panel);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            color: var(--muted);
            transition: all 0.2s;
        }
        .filter-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
            text-decoration: none;
        }
        .filter-btn.active {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        .filter-select {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .return-card {
            background: var(--bg-panel);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 16px;
            border: 1px solid var(--border);
        }
        .return-card.pending {
            border-left: 4px solid #f59e0b;
        }
        .return-card.approved {
            border-left: 4px solid #3b82f6;
        }
        .return-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .return-number {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--text);
        }
        .return-amount {
            text-align: right;
        }
        .return-amount .usd {
            font-weight: 700;
            font-size: 1.4rem;
            color: #dc2626;
        }
        .return-amount .lbp {
            font-size: 0.9rem;
            color: var(--muted);
        }
        .return-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .detail-item {
            font-size: 0.9rem;
        }
        .detail-item .label {
            color: var(--muted);
            margin-bottom: 4px;
        }
        .detail-item .value {
            color: var(--text);
            font-weight: 500;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending_cash_approval { background: #fef3c7; color: #92400e; }
        .status-cash_approved { background: #dbeafe; color: #1e40af; }
        .status-cash_rejected { background: #fee2e2; color: #991b1b; }
        .status-cash_paid { background: #d1fae5; color: #065f46; }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .btn-approve {
            background: #22c55e;
            color: white;
        }
        .btn-approve:hover {
            background: #16a34a;
        }
        .btn-reject {
            background: #fee2e2;
            color: #dc2626;
        }
        .btn-reject:hover {
            background: #fecaca;
        }
        .btn-paid {
            background: #3b82f6;
            color: white;
        }
        .btn-paid:hover {
            background: #2563eb;
        }
        .btn-view {
            background: var(--bg-panel-alt);
            color: var(--text);
        }
        .flash {
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .flash.success {
            background: #dcfce7;
            color: #166534;
        }
        .flash.error {
            background: #fee2e2;
            color: #991b1b;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
            background: var(--bg-panel);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .empty-state .icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal-content {
            background: var(--bg-panel);
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-header h3 {
            margin: 0;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--muted);
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
        }
        .items-list {
            background: var(--bg-panel-alt);
            border-radius: 8px;
            padding: 12px;
            margin-top: 12px;
        }
        .items-list .item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }
        .items-list .item:last-child {
            border-bottom: none;
        }
    </style>',
]);
?>

<!-- Flash Messages -->
<?php foreach ($flashes as $flash): ?>
    <div class="flash <?= $flash['type'] ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endforeach; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="label">Pending Approval</div>
        <div class="value pending"><?= (int)($totals['pending_count'] ?? 0) ?></div>
        <div style="font-size:0.85rem; color:var(--muted);">$<?= number_format((float)($totals['pending_usd'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Approved (Awaiting Payment)</div>
        <div class="value approved"><?= (int)($totals['approved_count'] ?? 0) ?></div>
        <div style="font-size:0.85rem; color:var(--muted);">$<?= number_format((float)($totals['approved_usd'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Total Cash Paid</div>
        <div class="value paid">$<?= number_format((float)($totals['paid_usd'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Total Cash Returns</div>
        <div class="value"><?= (int)($totals['total_count'] ?? 0) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filters-bar">
    <a href="?status=pending_cash_approval" class="filter-btn <?= $statusFilter === 'pending_cash_approval' ? 'active' : '' ?>">
        Pending (<?= (int)($totals['pending_count'] ?? 0) ?>)
    </a>
    <a href="?status=cash_approved" class="filter-btn <?= $statusFilter === 'cash_approved' ? 'active' : '' ?>">
        Approved
    </a>
    <a href="?status=cash_paid" class="filter-btn <?= $statusFilter === 'cash_paid' ? 'active' : '' ?>">
        Paid
    </a>
    <a href="?status=cash_rejected" class="filter-btn <?= $statusFilter === 'cash_rejected' ? 'active' : '' ?>">
        Rejected
    </a>
    <a href="?status=all" class="filter-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">
        All
    </a>

    <select class="filter-select" onchange="filterByRep(this.value)">
        <option value="all">All Sales Reps</option>
        <?php foreach ($salesReps as $rep): ?>
            <option value="<?= $rep['id'] ?>" <?= $repFilter == $rep['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($rep['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Returns List -->
<?php if (count($returns) > 0): ?>
    <?php foreach ($returns as $return): ?>
        <?php
        $isPending = $return['status'] === 'pending_cash_approval';
        $isApproved = $return['status'] === 'cash_approved';
        ?>
        <div class="return-card <?= $isPending ? 'pending' : ($isApproved ? 'approved' : '') ?>">
            <div class="return-header">
                <div>
                    <div class="return-number"><?= htmlspecialchars($return['return_number']) ?></div>
                    <span class="status-badge status-<?= $return['status'] ?>">
                        <?= str_replace('_', ' ', $return['status']) ?>
                    </span>
                </div>
                <div class="return-amount">
                    <div class="usd">$<?= number_format((float)$return['total_usd'], 2) ?></div>
                    <div class="lbp"><?= number_format((float)$return['total_lbp'], 0) ?> LBP</div>
                </div>
            </div>

            <div class="return-details">
                <div class="detail-item">
                    <div class="label">Customer</div>
                    <div class="value"><?= htmlspecialchars($return['customer_name']) ?></div>
                    <div style="font-size:0.8rem; color:var(--muted);"><?= htmlspecialchars($return['customer_phone'] ?? '') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Sales Rep</div>
                    <div class="value"><?= htmlspecialchars($return['sales_rep_name']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Date</div>
                    <div class="value"><?= date('M j, Y g:i A', strtotime($return['created_at'])) ?></div>
                </div>
                <?php if ($return['invoice_number']): ?>
                    <div class="detail-item">
                        <div class="label">Invoice</div>
                        <div class="value"><?= htmlspecialchars($return['invoice_number']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($return['reason']): ?>
                    <div class="detail-item">
                        <div class="label">Reason</div>
                        <div class="value"><?= htmlspecialchars($return['reason']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($return['approved_by_name']): ?>
                    <div class="detail-item">
                        <div class="label"><?= $return['status'] === 'cash_rejected' ? 'Rejected By' : 'Approved By' ?></div>
                        <div class="value"><?= htmlspecialchars($return['approved_by_name']) ?></div>
                        <div style="font-size:0.8rem; color:var(--muted);"><?= date('M j, Y', strtotime($return['approved_at'])) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($return['rejection_reason']): ?>
                    <div class="detail-item">
                        <div class="label">Rejection Reason</div>
                        <div class="value" style="color:#dc2626;"><?= htmlspecialchars($return['rejection_reason']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($return['paid_by_name']): ?>
                    <div class="detail-item">
                        <div class="label">Paid By</div>
                        <div class="value"><?= htmlspecialchars($return['paid_by_name']) ?></div>
                        <div style="font-size:0.8rem; color:var(--muted);"><?= date('M j, Y', strtotime($return['cash_paid_at'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($return['notes']): ?>
                <div style="background:var(--bg-panel-alt); padding:12px; border-radius:8px; margin-bottom:16px; font-size:0.9rem;">
                    <strong>Notes:</strong> <?= htmlspecialchars($return['notes']) ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button class="btn btn-view" onclick="viewItems(<?= $return['id'] ?>)">
                    View Items
                </button>

                <?php if ($isPending): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="return_id" value="<?= $return['id'] ?>">
                        <button type="submit" class="btn btn-approve" onclick="return confirm('Approve this cash refund of $<?= number_format((float)$return['total_usd'], 2) ?>?')">
                            Approve
                        </button>
                    </form>
                    <button class="btn btn-reject" onclick="openRejectModal(<?= $return['id'] ?>, '<?= htmlspecialchars($return['return_number']) ?>')">
                        Reject
                    </button>
                <?php endif; ?>

                <?php if ($isApproved): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="return_id" value="<?= $return['id'] ?>">
                        <button type="submit" class="btn btn-paid" onclick="return confirm('Mark as cash paid?')">
                            Mark as Paid
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">
        <div class="icon">&#128230;</div>
        <h3>No Cash Refund Requests</h3>
        <p>There are no cash refund requests matching your filters.</p>
    </div>
<?php endif; ?>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reject Refund</h3>
            <button onclick="closeRejectModal()" class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="return_id" id="reject_return_id" value="">

            <p style="margin-bottom:16px;">
                Rejecting return: <strong id="reject_return_number"></strong>
            </p>

            <div class="form-group">
                <label>Rejection Reason (optional)</label>
                <textarea name="rejection_reason" class="form-control" rows="3"
                          placeholder="Explain why this refund is being rejected..."></textarea>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeRejectModal()" class="btn btn-view">Cancel</button>
                <button type="submit" class="btn btn-reject">Reject Refund</button>
            </div>
        </form>
    </div>
</div>

<!-- Items Modal -->
<div id="itemsModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Return Items</h3>
            <button onclick="closeItemsModal()" class="modal-close">&times;</button>
        </div>
        <div id="itemsContent">Loading...</div>
    </div>
</div>

<script>
function filterByRep(repId) {
    const url = new URL(window.location);
    if (repId === 'all') {
        url.searchParams.delete('rep');
    } else {
        url.searchParams.set('rep', repId);
    }
    window.location = url;
}

function openRejectModal(returnId, returnNumber) {
    document.getElementById('reject_return_id').value = returnId;
    document.getElementById('reject_return_number').textContent = returnNumber;
    document.getElementById('rejectModal').classList.add('show');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('show');
}

function viewItems(returnId) {
    document.getElementById('itemsContent').innerHTML = 'Loading...';
    document.getElementById('itemsModal').classList.add('show');

    fetch(`/api/get_return_items.php?return_id=${returnId}`)
        .then(r => r.json())
        .then(data => {
            if (data.items && data.items.length > 0) {
                let html = '<div class="items-list">';
                data.items.forEach(item => {
                    html += `
                        <div class="item">
                            <div>
                                <strong>${escapeHtml(item.item_name)}</strong><br>
                                <small style="color:var(--muted);">${escapeHtml(item.sku)} x ${item.quantity}</small>
                                ${item.reason ? '<br><small style="color:#f59e0b;">Reason: ' + escapeHtml(item.reason) + '</small>' : ''}
                            </div>
                            <div style="text-align:right;">
                                <strong>$${parseFloat(item.line_total_usd).toFixed(2)}</strong>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                document.getElementById('itemsContent').innerHTML = html;
            } else {
                document.getElementById('itemsContent').innerHTML = '<p>No items found.</p>';
            }
        })
        .catch(err => {
            document.getElementById('itemsContent').innerHTML = '<p>Error loading items.</p>';
        });
}

function closeItemsModal() {
    document.getElementById('itemsModal').classList.remove('show');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRejectModal();
        closeItemsModal();
    }
});
</script>

<?php admin_render_layout_end(); ?>
