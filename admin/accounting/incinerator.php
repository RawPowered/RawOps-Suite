<?php
$SEO = [
    'title' => 'Test Data Incinerator — Accounting',
    'description' => 'Safely remove test data with proper reversing entries',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';

// Ensure user has permission (Admin only)
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

require_accounting_permission($userId, 'use_incinerator', 'can_use_incinerator');

$pdo = db();

// Get all test data markers
$testDataStmt = $pdo->query("
    SELECT 
        tdm.*,
        marker.first_name as marked_by_first,
        marker.last_name as marked_by_last,
        CASE 
            WHEN tdm.entity_type = 'order' THEN (SELECT CONCAT('Order #', id, ' - $', total) FROM orders WHERE id = tdm.entity_id)
            WHEN tdm.entity_type = 'commission' THEN (SELECT CONCAT('Commission #', id, ' - $', amount) FROM commissions WHERE id = tdm.entity_id)
            WHEN tdm.entity_type = 'expense' THEN (SELECT CONCAT('Expense #', id, ' - $', amount) FROM expenses WHERE id = tdm.entity_id)
            WHEN tdm.entity_type = 'journal_entry' THEN (SELECT CONCAT('JE ', entry_number) FROM journal_entries WHERE id = tdm.entity_id)
        END as entity_description
    FROM test_data_markers tdm
    LEFT JOIN users marker ON marker.id = tdm.marked_by
    WHERE tdm.incinerated = 0
    ORDER BY tdm.marked_at DESC
");
$testData = $testDataStmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts by type
$countsByType = [];
foreach ($testData as $item) {
    if (!isset($countsByType[$item['entity_type']])) {
        $countsByType[$item['entity_type']] = 0;
    }
    $countsByType[$item['entity_type']]++;
}

// Handle marking as test data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_test_data'])) {
    try {
        $entityType = $_POST['entity_type'];
        $entityId = intval($_POST['entity_id']);
        $reason = $_POST['reason'];
        
        $stmt = $pdo->prepare("
            INSERT INTO test_data_markers 
            (entity_type, entity_id, marked_by, reason)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                marked_as_test = 1,
                marked_by = VALUES(marked_by),
                marked_at = CURRENT_TIMESTAMP,
                reason = VALUES(reason)
        ");
        $stmt->execute([$entityType, $entityId, $userId, $reason]);
        
        flash_set('success', 'Entity marked as test data.');
        redirect($_SERVER['REQUEST_URI']);
        
    } catch (Exception $e) {
        flash_set('error', 'Failed to mark test data: ' . $e->getMessage());
    }
}
?>

<style>
.danger-zone { border: 2px solid #dc3545; background: #fff5f5; }
.test-data-card { transition: all 0.3s; border-left: 4px solid #ffc107; }
.test-data-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.entity-badge { font-size: 0.85rem; font-weight: 600; }
.incinerator-btn { animation: pulse 2s infinite; }
@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
    50% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-fire text-danger"></i> Test Data Incinerator</h1>
            <p class="text-muted">Safely remove test data using proper reversing journal entries</p>
        </div>
        <div>
            <a href="sync-controller.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-arrow-repeat"></i> Sync Controller
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Warning Alert -->
    <div class="alert alert-danger alert-dismissible fade show">
        <h5 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Dangerous Operation</h5>
        <p class="mb-0">The incinerator creates <strong>reversing journal entries</strong> to properly negate test data. This maintains audit trails but effectively removes the financial impact. Use with caution.</p>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-warning"><?= count($testData) ?></h2>
                    <small class="text-muted text-uppercase">Total Marked</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $countsByType['order'] ?? 0 ?></h2>
                    <small class="text-muted text-uppercase">Orders</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $countsByType['commission'] ?? 0 ?></h2>
                    <small class="text-muted text-uppercase">Commissions</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $countsByType['expense'] ?? 0 ?></h2>
                    <small class="text-muted text-uppercase">Expenses</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Mark New Test Data -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Mark Entity as Test Data</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="mark_test_data" value="1">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Entity Type</label>
                        <select name="entity_type" class="form-select" required>
                            <option value="">Select...</option>
                            <option value="order">Order</option>
                            <option value="commission">Commission</option>
                            <option value="expense">Expense</option>
                            <option value="journal_entry">Journal Entry</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Entity ID</label>
                        <input type="number" name="entity_id" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Reason</label>
                        <input type="text" name="reason" class="form-control" placeholder="Why is this test data?" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-tag"></i> Mark as Test
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Test Data List -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-check"></i> Marked Test Data</h5>
            <span class="badge bg-warning"><?= count($testData) ?> items</span>
        </div>
        <div class="card-body p-0">
            <?php if (count($testData) > 0): ?>
                <?php foreach ($testData as $item): ?>
                    <div class="test-data-card p-3 border-bottom">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <span class="entity-badge badge bg-secondary">
                                    <?= strtoupper($item['entity_type']) ?>
                                </span>
                            </div>
                            <div class="col-md-4">
                                <div class="fw-bold"><?= htmlspecialchars($item['entity_description'] ?? 'N/A') ?></div>
                                <small class="text-muted">ID: <?= $item['entity_id'] ?></small>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Marked by:</small>
                                <small class="fw-bold"><?= htmlspecialchars($item['marked_by_first'] . ' ' . $item['marked_by_last']) ?></small>
                                <br>
                                <small class="text-muted"><?= date('M j, Y', strtotime($item['marked_at'])) ?></small>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Reason:</small>
                                <small><?= htmlspecialchars($item['reason']) ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>
                    <p class="mb-0">No test data marked. Use the form above to mark entities as test data.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="card shadow-sm danger-zone">
        <div class="card-header bg-danger bg-opacity-10">
            <h5 class="mb-0 text-danger">
                <i class="bi bi-exclamation-octagon-fill"></i> Danger Zone: Incinerate Test Data
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-danger">
                <h6 class="alert-heading">This action will:</h6>
                <ul class="mb-2">
                    <li>Create reversing journal entries for all marked financial transactions</li>
                    <li>Negate expenses using proper accounting method</li>
                    <li>Mark all entities as "incinerated"</li>
                    <li>Maintain complete audit trail</li>
                </ul>
                <p class="mb-0"><strong>This action cannot be easily undone.</strong> A full audit trail will be maintained, but financial reports will reflect the reversals.</p>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h6 class="fw-bold mb-3">Preview Impact:</h6>
                    <div id="incineratorPreview" class="p-3 bg-light rounded">
                        <div class="spinner-border spinner-border-sm me-2"></div> Loading preview...
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold mb-3">Execute Incineration:</h6>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Administrator Password</label>
                        <input type="password" id="adminPassword" class="form-control" placeholder="Enter your password to confirm">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmIncinerate">
                        <label class="form-check-label" for="confirmIncinerate">
                            I understand this will create reversing entries for <?= count($testData) ?> items
                        </label>
                    </div>
                    <button class="btn btn-danger btn-lg w-100 incinerator-btn" onclick="executeIncineration()" id="incinerateBtn" disabled>
                        <i class="bi bi-fire"></i> INCINERATE ALL TEST DATA
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Enable/disable incinerate button
document.getElementById('confirmIncinerate')?.addEventListener('change', function() {
    document.getElementById('incinerateBtn').disabled = !this.checked;
});

// Load preview
async function loadPreview() {
    try {
        const response = await fetch('/api/accounting/incinerator-api.php?action=preview');
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('incineratorPreview').innerHTML = `
                <ul class="mb-0">
                    <li><strong>${result.preview.orders || 0}</strong> orders will be reversed</li>
                    <li><strong>${result.preview.commissions || 0}</strong> commissions will be negated</li>
                    <li><strong>${result.preview.expenses || 0}</strong> expenses will be negated</li>
                    <li><strong>${result.preview.journal_entries || 0}</strong> journal entries will be voided</li>
                    <li class="text-danger mt-2"><strong>Total Financial Impact: $${result.preview.total_impact?.toLocaleString() || '0.00'}</strong></li>
                </ul>
            `;
        } else {
            document.getElementById('incineratorPreview').innerHTML = '<p class="text-danger mb-0">Failed to load preview</p>';
        }
    } catch (error) {
        document.getElementById('incineratorPreview').innerHTML = '<p class="text-danger mb-0">Error loading preview</p>';
    }
}

async function executeIncineration() {
    const password = document.getElementById('adminPassword').value;
    const confirmed = document.getElementById('confirmIncinerate').checked;
    
    if (!password) {
        alert('Please enter your administrator password.');
        return;
    }
    
    if (!confirmed) {
        alert('Please confirm you understand the impact.');
        return;
    }
    
    if (!confirm('FINAL WARNING: This will create reversing entries for ALL marked test data. Continue?')) {
        return;
    }
    
    // Disable button
    const btn = document.getElementById('incinerateBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Incinerating...';
    
    try {
        const response = await fetch('/api/accounting/incinerator-api.php?action=incinerate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`Incineration complete!\n\n${result.summary.reversed} entries reversed\n${result.summary.negated} expenses negated\n${result.summary.voided} journal entries voided`);
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-fire"></i> INCINERATE ALL TEST DATA';
        }
    } catch (error) {
        alert('Failed to execute incineration: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-fire"></i> INCINERATE ALL TEST DATA';
    }
}

// Load preview on page load
document.addEventListener('DOMContentLoaded', loadPreview);
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>












