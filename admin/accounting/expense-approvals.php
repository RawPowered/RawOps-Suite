<?php
$SEO = [
    'title' => 'Expense Approvals — Product Accounting',
    'description' => 'Review and approve pending expenses',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Ensure user has permission
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

require_accounting_permission($userId, 'approve_expenses', 'can_approve_expenses');

$pdo = db();

// Get pending expenses with receipt info
$pendingStmt = $pdo->query("
    SELECT 
        e.*,
        ec.category_name,
        creator.first_name as creator_first_name,
        creator.last_name as creator_last_name,
        CASE WHEN e.receipt_file_path IS NOT NULL THEN 1 ELSE 0 END as has_receipt
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
    LEFT JOIN users creator ON creator.id = e.created_by
    WHERE e.approval_status = 'pending'
    ORDER BY e.expense_date DESC
");
$pendingExpenses = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently approved
$approvedStmt = $pdo->query("
    SELECT 
        e.*,
        ec.category_name,
        approver.first_name as approver_first_name,
        approver.last_name as approver_last_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
    LEFT JOIN users approver ON approver.id = e.approved_by
    WHERE e.approval_status = 'approved'
    AND e.approved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY e.approved_at DESC
    LIMIT 20
");
$approvedExpenses = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently rejected
$rejectedStmt = $pdo->query("
    SELECT 
        e.*,
        ec.category_name,
        approver.first_name as approver_first_name,
        approver.last_name as approver_last_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
    LEFT JOIN users approver ON approver.id = e.approved_by
    WHERE e.approval_status = 'rejected'
    AND e.approved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY e.approved_at DESC
    LIMIT 20
");
$rejectedExpenses = $rejectedStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$totalPending = count($pendingExpenses);
$totalPendingAmount = array_sum(array_column($pendingExpenses, 'amount'));

// Load CSS inline
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Expense Approvals']]); ?>
        <?php render_accounting_secondary_nav('expense-approvals.php'); ?>
    <?php endif; ?>
    
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">Expense Approvals</h1>
            <p class="acct1015_page_subtitle">Review and approve pending expense submissions</p>
        </div>
        <a href="index.php" class="acct1015_btn_secondary">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-warning"><?= $totalPending ?></h2>
                    <small class="text-muted text-uppercase">Pending Approval</small>
                    <div class="mt-2">
                        <strong class="text-warning">$<?= number_format($totalPendingAmount, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-success">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-success"><?= count($approvedExpenses) ?></h2>
                    <small class="text-muted text-uppercase">Approved (30 days)</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-danger"><?= count($rejectedExpenses) ?></h2>
                    <small class="text-muted text-uppercase">Rejected (30 days)</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Action Bar -->
    <?php if ($totalPending > 0): ?>
    <div class="bulk-action-bar mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <span id="selectedCount">0</span> selected
            </div>
            <div>
                <button class="btn btn-success me-2" onclick="bulkApprove()" id="bulkApproveBtn" disabled>
                    <i class="bi bi-check-circle"></i> Approve Selected
                </button>
                <button class="btn btn-danger" onclick="bulkReject()" id="bulkRejectBtn" disabled>
                    <i class="bi bi-x-circle"></i> Reject Selected
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Expenses -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning bg-opacity-10">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-warning"><i class="bi bi-hourglass-split"></i> Pending Approval</h5>
                <?php if ($totalPending > 0): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                        <label class="form-check-label" for="selectAll">Select All</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (count($pendingExpenses) > 0): ?>
                <?php foreach ($pendingExpenses as $expense): ?>
                    <div style="padding: 1.5rem; margin-bottom: 0.75rem; background: white; border-radius: 0.75rem; border: 1px solid #e5e7eb; border-left: 4px solid #fbbf24; transition: all 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'" onmouseout="this.style.boxShadow=''">
                        <div style="display: grid; grid-template-columns: auto 2fr 1fr 1fr auto auto; gap: 1.5rem; align-items: center;">
                            <div>
                                <input type="checkbox" class="form-check-input expense-checkbox" 
                                       value="<?= $expense['id'] ?>" 
                                       style="width: 1.25rem; height: 1.25rem; cursor: pointer;"
                                       onclick="updateBulkActions()">
                            </div>
                            <div>
                                <div style="font-weight: 600; color: #111827; margin-bottom: 0.25rem;">
                                    <?= htmlspecialchars($expense['description']) ?>
                                </div>
                                <div style="font-size: 0.8125rem; color: #6b7280;">
                                    <i class="bi bi-shop"></i> <?= htmlspecialchars($expense['vendor_name'] ?? 'N/A') ?>
                                    • <?= date('M j, Y', strtotime($expense['expense_date'])) ?>
                                    <?php if ($expense['has_receipt']): ?>
                                        • <a href="view-receipt.php?expense_id=<?= $expense['id'] ?>" target="_blank" style="color: #10b981; text-decoration: none;">
                                            <i class="bi bi-file-pdf"></i> View Receipt
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <span class="acct1015_badge acct1015_badge_neutral">
                                    <?= htmlspecialchars($expense['category_name']) ?>
                                </span>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: #d97706;">
                                    $<?= number_format($expense['amount'], 2) ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #9ca3af;">
                                    by <?= htmlspecialchars($expense['creator_first_name']) ?>
                                </div>
                            </div>
                            <div>
                                <button class="acct1015_btn_primary" style="padding: 0.625rem 1rem; font-size: 0.875rem;" 
                                        onclick="approveExpense(<?= $expense['id'] ?>)">
                                    <i class="bi bi-check-lg"></i> Approve
                                </button>
                            </div>
                            <div>
                                <button class="acct1015_btn_secondary" style="padding: 0.625rem 1rem; font-size: 0.875rem; border-color: #fca5a5; color: #dc2626;" 
                                        onclick="rejectExpense(<?= $expense['id'] ?>)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-check-circle display-4 d-block mb-3 text-success opacity-25"></i>
                    <p class="mb-0">No pending expenses. All caught up!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs for Approved/Rejected -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#approved-tab">
                <i class="bi bi-check-circle text-success"></i> Recently Approved
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rejected-tab">
                <i class="bi bi-x-circle text-danger"></i> Recently Rejected
            </button>
        </li>
    </ul>
    
    <div class="tab-content">
        <div class="tab-pane fade show active p-3" id="approved-tab">
            <?php if (count($approvedExpenses) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th class="text-end">Amount</th>
                                <th>Approved By</th>
                                <th>Approved At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedExpenses as $expense): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($expense['expense_date'])) ?></td>
                                <td><?= htmlspecialchars($expense['description']) ?></td>
                                <td><span class="badge bg-secondary small"><?= htmlspecialchars($expense['category_name']) ?></span></td>
                                <td class="text-end fw-bold">$<?= number_format($expense['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($expense['approver_first_name'] . ' ' . $expense['approver_last_name']) ?></td>
                                <td><?= date('M j, Y g:i A', strtotime($expense['approved_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-4">No recently approved expenses.</p>
            <?php endif; ?>
        </div>
        
        <div class="tab-pane fade p-3" id="rejected-tab">
            <?php if (count($rejectedExpenses) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th class="text-end">Amount</th>
                                <th>Rejected By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejectedExpenses as $expense): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($expense['expense_date'])) ?></td>
                                <td><?= htmlspecialchars($expense['description']) ?></td>
                                <td><span class="badge bg-secondary small"><?= htmlspecialchars($expense['category_name']) ?></span></td>
                                <td class="text-end fw-bold">$<?= number_format($expense['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($expense['approver_first_name'] . ' ' . $expense['approver_last_name']) ?></td>
                                <td class="small"><?= htmlspecialchars($expense['rejection_reason'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-4">No recently rejected expenses.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let selectedExpenses = [];

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll').checked;
    document.querySelectorAll('.expense-checkbox').forEach(checkbox => {
        checkbox.checked = selectAll;
    });
    updateBulkActions();
}

function updateBulkActions() {
    selectedExpenses = [];
    document.querySelectorAll('.expense-checkbox:checked').forEach(checkbox => {
        selectedExpenses.push(parseInt(checkbox.value));
    });
    
    document.getElementById('selectedCount').textContent = selectedExpenses.length;
    document.getElementById('bulkApproveBtn').disabled = selectedExpenses.length === 0;
    document.getElementById('bulkRejectBtn').disabled = selectedExpenses.length === 0;
}

async function approveExpense(expenseId) {
    if (!confirm('Approve this expense?')) return;
    
    try {
        const response = await fetch('/api/accounting/expense-api.php?action=approve_expense', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ expense_id: expenseId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to approve expense: ' + error.message);
    }
}

async function rejectExpense(expenseId) {
    const reason = prompt('Enter rejection reason:');
    if (!reason) return;
    
    try {
        const response = await fetch('/api/accounting/expense-api.php?action=reject_expense', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                expense_id: expenseId,
                rejection_reason: reason
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to reject expense: ' + error.message);
    }
}

async function bulkApprove() {
    if (!confirm(`Approve ${selectedExpenses.length} expenses?`)) return;
    
    try {
        const response = await fetch('/api/accounting/expense-api.php?action=bulk_approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ expense_ids: selectedExpenses })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`${result.approved_count} expenses approved successfully`);
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to bulk approve: ' + error.message);
    }
}

async function bulkReject() {
    const reason = prompt('Enter rejection reason for all selected expenses:');
    if (!reason) return;
    
    try {
        const response = await fetch('/api/accounting/expense-api.php?action=bulk_reject', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                expense_ids: selectedExpenses,
                rejection_reason: reason
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`${result.rejected_count} expenses rejected`);
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to bulk reject: ' + error.message);
    }
}

function viewExpenseDetail(expenseId) {
    window.location.href = `/api/accounting/expense-api.php?action=get_expense&expense_id=${expenseId}`;
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

