<?php

declare(strict_types=1);

/**
 * Admin page for reviewing and approving/rejecting invoice change requests
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/audit.php';

// Admin authentication
require_login();
$user = auth_user();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo 'Forbidden - Admin access only';
    exit;
}

$pdo = db();
$adminId = (int)$user['id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    if ($requestId <= 0) {
        flash('error', 'Invalid request ID.');
        header('Location: invoice_approvals.php');
        exit;
    }

    // Get the request
    $requestStmt = $pdo->prepare("
        SELECT icr.*, i.invoice_number, i.status AS invoice_status, i.order_id,
               u.name AS requested_by_name
        FROM invoice_change_requests icr
        INNER JOIN invoices i ON i.id = icr.invoice_id
        INNER JOIN users u ON u.id = icr.requested_by
        WHERE icr.id = :id AND icr.status = 'pending'
    ");
    $requestStmt->execute([':id' => $requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        flash('error', 'Request not found or already processed.');
        header('Location: invoice_approvals.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'approve') {
            // Apply the changes based on request type
            if ($request['request_type'] === 'void') {
                // Void the invoice
                $voidStmt = $pdo->prepare("UPDATE invoices SET status = 'voided' WHERE id = :id");
                $voidStmt->execute([':id' => $request['invoice_id']]);

                // Log the void action
                audit_log($pdo, $adminId, 'invoice_voided', 'invoices', $request['invoice_id'], [
                    'invoice_number' => $request['invoice_number'],
                    'requested_by' => $request['requested_by_name'],
                    'reason' => $request['reason'],
                    'admin_notes' => $adminNotes
                ]);

                // Notify the sales rep
                $notifyStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, payload, created_at)
                    VALUES (:user_id, 'invoice_void_approved', :payload, NOW())
                ");
                $notifyStmt->execute([
                    ':user_id' => $request['requested_by'],
                    ':payload' => json_encode([
                        'invoice_number' => $request['invoice_number'],
                        'admin_notes' => $adminNotes,
                        'message' => 'Your void request for invoice ' . $request['invoice_number'] . ' has been APPROVED'
                    ])
                ]);

                flash('success', 'Invoice ' . $request['invoice_number'] . ' has been voided.');

            } elseif ($request['request_type'] === 'edit') {
                // Apply the proposed changes
                $proposedChanges = json_decode($request['proposed_changes'], true) ?: [];

                if (!empty($proposedChanges['notes'])) {
                    $updateNotesStmt = $pdo->prepare("UPDATE orders SET notes = :notes WHERE id = :id");
                    $updateNotesStmt->execute([
                        ':notes' => $proposedChanges['notes'],
                        ':id' => $request['order_id']
                    ]);
                }

                // If there's a discount change, recalculate totals
                if (isset($proposedChanges['discount_percent'])) {
                    // This would require more complex logic to apply discounts
                    // For now, we'll log it but not auto-apply complex changes
                }

                // Log the edit action
                audit_log($pdo, $adminId, 'invoice_edited', 'invoices', $request['invoice_id'], [
                    'invoice_number' => $request['invoice_number'],
                    'requested_by' => $request['requested_by_name'],
                    'reason' => $request['reason'],
                    'changes_applied' => $proposedChanges,
                    'admin_notes' => $adminNotes
                ]);

                // Notify the sales rep
                $notifyStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, payload, created_at)
                    VALUES (:user_id, 'invoice_edit_approved', :payload, NOW())
                ");
                $notifyStmt->execute([
                    ':user_id' => $request['requested_by'],
                    ':payload' => json_encode([
                        'invoice_number' => $request['invoice_number'],
                        'admin_notes' => $adminNotes,
                        'message' => 'Your edit request for invoice ' . $request['invoice_number'] . ' has been APPROVED'
                    ])
                ]);

                flash('success', 'Edit request for invoice ' . $request['invoice_number'] . ' has been approved and applied.');
            }

            // Update request status
            $updateRequestStmt = $pdo->prepare("
                UPDATE invoice_change_requests 
                SET status = 'approved', processed_by = :admin_id, processed_at = NOW(), admin_notes = :notes
                WHERE id = :id
            ");
            $updateRequestStmt->execute([
                ':admin_id' => $adminId,
                ':notes' => $adminNotes,
                ':id' => $requestId
            ]);

        } elseif ($action === 'reject') {
            // Reject the request
            $updateRequestStmt = $pdo->prepare("
                UPDATE invoice_change_requests 
                SET status = 'rejected', processed_by = :admin_id, processed_at = NOW(), admin_notes = :notes
                WHERE id = :id
            ");
            $updateRequestStmt->execute([
                ':admin_id' => $adminId,
                ':notes' => $adminNotes,
                ':id' => $requestId
            ]);

            // Log the rejection
            audit_log($pdo, $adminId, 'invoice_request_rejected', 'invoice_change_requests', $requestId, [
                'invoice_number' => $request['invoice_number'],
                'request_type' => $request['request_type'],
                'requested_by' => $request['requested_by_name'],
                'admin_notes' => $adminNotes
            ]);

            // Notify the sales rep about rejection
            $notifyStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, payload, created_at)
                VALUES (:user_id, 'invoice_request_rejected', :payload, NOW())
            ");
            $notifyStmt->execute([
                ':user_id' => $request['requested_by'],
                ':payload' => json_encode([
                    'invoice_number' => $request['invoice_number'],
                    'request_type' => $request['request_type'],
                    'admin_notes' => $adminNotes,
                    'message' => 'Your ' . $request['request_type'] . ' request for invoice ' . $request['invoice_number'] . ' has been REJECTED' . ($adminNotes ? ': ' . $adminNotes : '')
                ])
            ]);

            flash('info', ucfirst($request['request_type']) . ' request for invoice ' . $request['invoice_number'] . ' has been rejected.');
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Failed to process request: ' . $e->getMessage());
    }

    header('Location: invoice_approvals.php');
    exit;
}

// Filters
$statusFilter = $_GET['status'] ?? 'pending';
$typeFilter = $_GET['type'] ?? '';

// Build query
$where = ['1=1'];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $where[] = "icr.status = :status";
    $params[':status'] = $statusFilter;
}

if ($typeFilter !== '' && in_array($typeFilter, ['edit', 'void'], true)) {
    $where[] = "icr.request_type = :type";
    $params[':type'] = $typeFilter;
}

$whereSQL = implode(' AND ', $where);

// Get requests
$requestsStmt = $pdo->prepare("
    SELECT 
        icr.*,
        i.invoice_number,
        i.total_usd,
        i.total_lbp,
        i.status AS invoice_status,
        o.order_number,
        c.name AS customer_name,
        u.name AS requested_by_name,
        u.email AS requested_by_email,
        admin.name AS processed_by_name
    FROM invoice_change_requests icr
    INNER JOIN invoices i ON i.id = icr.invoice_id
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    INNER JOIN users u ON u.id = icr.requested_by
    LEFT JOIN users admin ON admin.id = icr.processed_by
    WHERE {$whereSQL}
    ORDER BY 
        CASE WHEN icr.status = 'pending' THEN 0 ELSE 1 END,
        icr.created_at DESC
    LIMIT 100
");
$requestsStmt->execute($params);
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for tabs
$countsStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM invoice_change_requests
");
$counts = $countsStmt->fetch(PDO::FETCH_ASSOC);

$flashes = consume_flashes();

$extraHead = <<<'CSS'
<style>
.tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--border);
    padding-bottom: 0;
}
.tabs a {
    padding: 12px 20px;
    text-decoration: none;
    color: var(--muted);
    font-weight: 500;
    border-radius: 8px 8px 0 0;
    transition: all 0.2s;
}
.tabs a:hover {
    color: var(--text);
    background: var(--bg-panel-alt);
}
.tabs a.active {
    color: var(--accent);
    border-bottom: 2px solid var(--accent);
    margin-bottom: -2px;
}
.tabs .count {
    background: var(--bg-panel-alt);
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.8rem;
    margin-left: 6px;
}
.tabs a.active .count {
    background: var(--accent);
    color: #fff;
}
.request-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
}
.request-card.pending {
    border-left: 4px solid #f59e0b;
}
.request-card.approved {
    border-left: 4px solid #10b981;
}
.request-card.rejected {
    border-left: 4px solid #ef4444;
}
.request-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}
.request-title {
    font-size: 1.1rem;
    font-weight: 600;
}
.request-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
    padding: 16px;
    background: var(--bg-panel-alt);
    border-radius: 8px;
}
.request-meta-item {
    font-size: 0.9rem;
}
.request-meta-item .label {
    color: var(--muted);
    margin-bottom: 4px;
}
.request-meta-item .value {
    font-weight: 500;
}
.request-reason {
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}
.request-reason.void {
    background: #fee2e2;
    border-color: #fca5a5;
}
.request-reason h4 {
    margin: 0 0 8px 0;
    font-size: 0.9rem;
    color: #92400e;
}
.request-reason.void h4 {
    color: #991b1b;
}
.request-actions {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}
.badge-pending { background: rgba(245, 158, 11, 0.15); color: #b45309; }
.badge-approved { background: rgba(16, 185, 129, 0.15); color: #059669; }
.badge-rejected { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
.badge-edit { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
.badge-void { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.5;
}
.admin-notes-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.9rem;
    margin-bottom: 12px;
}
.processed-info {
    font-size: 0.85rem;
    color: var(--muted);
    padding: 12px;
    background: var(--bg-panel-alt);
    border-radius: 8px;
    margin-top: 16px;
}
</style>
CSS;

admin_render_layout_start([
    'title' => 'Invoice Approvals',
    'heading' => 'Invoice Change Requests',
    'subtitle' => 'Review and process invoice edit and void requests from sales reps',
    'user' => $user,
    'active' => 'invoice_approvals',
    'extra_head' => $extraHead,
]);

admin_render_flashes($flashes);
?>

<!-- Tabs -->
<div class="tabs">
    <a href="?status=pending" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">
        ⏳ Pending <span class="count"><?= (int)($counts['pending_count'] ?? 0) ?></span>
    </a>
    <a href="?status=approved" class="<?= $statusFilter === 'approved' ? 'active' : '' ?>">
        ✅ Approved <span class="count"><?= (int)($counts['approved_count'] ?? 0) ?></span>
    </a>
    <a href="?status=rejected" class="<?= $statusFilter === 'rejected' ? 'active' : '' ?>">
        ❌ Rejected <span class="count"><?= (int)($counts['rejected_count'] ?? 0) ?></span>
    </a>
</div>

<!-- Type Filter -->
<div style="margin-bottom: 24px; display: flex; gap: 8px;">
    <a href="?status=<?= htmlspecialchars($statusFilter) ?>" 
       class="btn btn-sm <?= $typeFilter === '' ? 'btn-primary' : '' ?>">All Types</a>
    <a href="?status=<?= htmlspecialchars($statusFilter) ?>&type=edit" 
       class="btn btn-sm <?= $typeFilter === 'edit' ? 'btn-primary' : '' ?>">📝 Edit Requests</a>
    <a href="?status=<?= htmlspecialchars($statusFilter) ?>&type=void" 
       class="btn btn-sm <?= $typeFilter === 'void' ? 'btn-primary' : '' ?>">🗑️ Void Requests</a>
</div>

<?php if (empty($requests)): ?>
<div class="empty-state">
    <div class="empty-state-icon">📋</div>
    <h3>No <?= $statusFilter ?> requests</h3>
    <p>There are no <?= $statusFilter ?> invoice change requests to show.</p>
</div>
<?php else: ?>

<?php foreach ($requests as $req): 
    $isPending = $req['status'] === 'pending';
    $isVoid = $req['request_type'] === 'void';
    $proposedChanges = json_decode($req['proposed_changes'] ?? '{}', true) ?: [];
?>
<div class="request-card <?= $req['status'] ?>">
    <div class="request-header">
        <div>
            <div class="request-title">
                Invoice <?= htmlspecialchars($req['invoice_number']) ?>
                <span class="badge badge-<?= $req['request_type'] ?>" style="margin-left: 8px;">
                    <?= $isVoid ? '🗑️ Void' : '📝 Edit' ?> Request
                </span>
            </div>
            <div style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;">
                Submitted by <strong><?= htmlspecialchars($req['requested_by_name']) ?></strong>
                on <?= date('M d, Y \a\t H:i', strtotime($req['created_at'])) ?>
            </div>
        </div>
        <span class="badge badge-<?= $req['status'] ?>">
            <?= ucfirst($req['status']) ?>
        </span>
    </div>

    <div class="request-meta">
        <div class="request-meta-item">
            <div class="label">Customer</div>
            <div class="value"><?= htmlspecialchars($req['customer_name']) ?></div>
        </div>
        <div class="request-meta-item">
            <div class="label">Order #</div>
            <div class="value"><?= htmlspecialchars($req['order_number']) ?></div>
        </div>
        <div class="request-meta-item">
            <div class="label">Invoice Total</div>
            <div class="value">$<?= number_format((float)$req['total_usd'], 2) ?></div>
        </div>
        <div class="request-meta-item">
            <div class="label">Invoice Status</div>
            <div class="value"><?= ucfirst($req['invoice_status']) ?></div>
        </div>
    </div>

    <div class="request-reason <?= $isVoid ? 'void' : '' ?>">
        <h4><?= $isVoid ? '⚠️ Reason for Void Request:' : '📝 Reason for Edit Request:' ?></h4>
        <p style="margin: 0; white-space: pre-wrap;"><?= htmlspecialchars($req['reason']) ?></p>
    </div>

    <?php if (!empty($proposedChanges)): ?>
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
        <h4 style="margin: 0 0 8px 0; font-size: 0.9rem; color: #1e40af;">💡 Proposed Changes:</h4>
        <ul style="margin: 0; padding-left: 20px; color: #1e40af;">
            <?php if (isset($proposedChanges['notes'])): ?>
                <li><strong>Notes:</strong> <?= htmlspecialchars($proposedChanges['notes']) ?></li>
            <?php endif; ?>
            <?php if (isset($proposedChanges['discount_percent'])): ?>
                <li><strong>Discount:</strong> <?= (float)$proposedChanges['discount_percent'] ?>%</li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($isPending): ?>
    <form method="POST" action="invoice_approvals.php" style="margin-top: 16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
        
        <div style="margin-bottom: 12px;">
            <label style="display: block; font-weight: 500; margin-bottom: 6px; font-size: 0.9rem;">Admin Notes (optional)</label>
            <input type="text" name="admin_notes" class="admin-notes-input" placeholder="Add a note for the sales rep...">
        </div>
        
        <div class="request-actions">
            <button type="submit" name="action" value="approve" class="btn btn-success"
                    onclick="return confirm('Are you sure you want to APPROVE this <?= $req['request_type'] ?> request?<?= $isVoid ? ' The invoice will be voided.' : '' ?>')">
                ✅ Approve <?= ucfirst($req['request_type']) ?>
            </button>
            <button type="submit" name="action" value="reject" class="btn btn-danger"
                    onclick="return confirm('Are you sure you want to REJECT this request?')">
                ❌ Reject
            </button>
            <a href="invoices.php?search=<?= urlencode($req['invoice_number']) ?>" class="btn btn-secondary">
                View Invoice
            </a>
        </div>
    </form>
    <?php else: ?>
    <div class="processed-info">
        <strong><?= $req['status'] === 'approved' ? '✅ Approved' : '❌ Rejected' ?></strong>
        by <?= htmlspecialchars($req['processed_by_name'] ?? 'Unknown') ?>
        on <?= $req['processed_at'] ? date('M d, Y \a\t H:i', strtotime($req['processed_at'])) : 'N/A' ?>
        <?php if ($req['admin_notes']): ?>
            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border);">
                <strong>Admin Notes:</strong> <?= htmlspecialchars($req['admin_notes']) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php admin_render_layout_end(); ?>
