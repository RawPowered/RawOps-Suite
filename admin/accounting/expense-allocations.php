<?php
$SEO = [
    'title' => 'Expense Allocations — Accounting',
    'description' => 'Manage and visualize expense allocations to products and batches',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';

// Ensure user has permission
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

require_accounting_permission($userId, 'manage_allocations', 'can_manage_allocations');

$pdo = db();

// Get unallocated expenses
$unallocatedStmt = $pdo->query("
    SELECT 
        e.id,
        e.expense_date,
        e.amount,
        e.description,
        e.vendor_name,
        ec.category_name,
        e.allocation_method,
        COALESCE((SELECT SUM(allocated_amount) FROM expense_allocations WHERE expense_id = e.id), 0) as allocated_amount
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
    WHERE e.approval_status = 'approved'
    AND e.allocation_method IN ('direct', 'hybrid')
    HAVING (e.amount - allocated_amount) > 0.01
    ORDER BY e.expense_date DESC
    LIMIT 50
");
$unallocatedExpenses = $unallocatedStmt->fetchAll(PDO::FETCH_ASSOC);

// Get allocation summary by type
$allocationSummaryStmt = $pdo->query("
    SELECT 
        allocation_type,
        COUNT(*) as allocation_count,
        COALESCE(SUM(allocated_amount), 0) as total_allocated
    FROM expense_allocations
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY allocation_type
");
$allocationSummary = $allocationSummaryStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent allocations
$recentAllocationsStmt = $pdo->query("
    SELECT 
        ea.*,
        e.description as expense_description,
        e.amount as expense_total,
        ec.category_name
    FROM expense_allocations ea
    LEFT JOIN expenses e ON e.id = ea.expense_id
    LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
    ORDER BY ea.created_at DESC
    LIMIT 30
");
$recentAllocations = $recentAllocationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$totalUnallocated = count($unallocatedExpenses);
$totalUnallocatedAmount = array_sum(array_map(fn($e) => $e['amount'] - $e['allocated_amount'], $unallocatedExpenses));
?>

<style>
.allocation-card { transition: all 0.3s; border-left: 4px solid transparent; }
.allocation-card.unallocated { border-color: #dc3545; }
.allocation-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.allocation-type-badge { font-size: 0.8rem; font-weight: 600; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-diagram-3 text-primary"></i> Expense Allocations</h1>
            <p class="text-muted">Manage cost allocations to products, batches, and overhead</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-danger"><?= $totalUnallocated ?></h2>
                    <small class="text-muted text-uppercase">Unallocated Expenses</small>
                    <div class="mt-2">
                        <strong class="text-danger">$<?= number_format($totalUnallocatedAmount, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <?php foreach ($allocationSummary as $summary): ?>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $summary['allocation_count'] ?></h2>
                    <small class="text-muted text-uppercase"><?= ucwords(str_replace('_', ' ', $summary['allocation_type'])) ?></small>
                    <div class="mt-2">
                        <strong>$<?= number_format($summary['total_allocated'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Allocation Pie Chart -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Allocation Distribution (90 Days)</h5>
                </div>
                <div class="card-body">
                    <canvas id="allocationPieChart" height="150"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-info-circle"></i> Allocation Methods</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2"><span class="badge bg-primary me-2">Direct</span> Expenses directly traceable to specific products/batches (COGS)</p>
                    <p class="mb-2"><span class="badge bg-warning me-2">Overhead</span> General business expenses distributed across all operations</p>
                    <p class="mb-0"><span class="badge bg-info me-2">Hybrid</span> Partially direct, partially overhead</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Unallocated Expenses -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-danger bg-opacity-10">
            <h5 class="mb-0 text-danger"><i class="bi bi-exclamation-triangle"></i> Unallocated Expenses Requiring Action</h5>
        </div>
        <div class="card-body p-0">
            <?php if (count($unallocatedExpenses) > 0): ?>
                <?php foreach ($unallocatedExpenses as $expense): ?>
                    <div class="allocation-card unallocated p-3 border-bottom">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <strong><?= date('M j, Y', strtotime($expense['expense_date'])) ?></strong>
                            </div>
                            <div class="col-md-4">
                                <div class="fw-bold"><?= htmlspecialchars($expense['description']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($expense['vendor_name'] ?? 'N/A') ?></small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-secondary"><?= htmlspecialchars($expense['category_name']) ?></span>
                                <br>
                                <span class="allocation-type-badge badge bg-<?= $expense['allocation_method'] === 'direct' ? 'primary' : ($expense['allocation_method'] === 'overhead' ? 'warning' : 'info') ?> mt-1">
                                    <?= ucfirst($expense['allocation_method']) ?>
                                </span>
                            </div>
                            <div class="col-md-2 text-end">
                                <div class="small text-muted">Total: $<?= number_format($expense['amount'], 2) ?></div>
                                <div class="small text-muted">Allocated: $<?= number_format($expense['allocated_amount'], 2) ?></div>
                                <div class="fw-bold text-danger">
                                    Remaining: $<?= number_format($expense['amount'] - $expense['allocated_amount'], 2) ?>
                                </div>
                            </div>
                            <div class="col-md-2 text-end">
                                <button class="btn btn-sm btn-primary" onclick="allocateExpense(<?= $expense['id'] ?>)">
                                    <i class="bi bi-diagram-3"></i> Allocate
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-check-circle display-4 d-block mb-3 text-success opacity-25"></i>
                    <p class="mb-0">All direct expenses are fully allocated!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Allocations -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Allocations</h5>
        </div>
        <div class="card-body p-0">
            <?php if (count($recentAllocations) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Expense</th>
                            <th>Category</th>
                            <th>Allocation Type</th>
                            <th>Target</th>
                            <th class="text-end">Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAllocations as $allocation): ?>
                        <tr>
                            <td><?= htmlspecialchars(substr($allocation['expense_description'], 0, 30)) ?></td>
                            <td><span class="badge bg-secondary small"><?= htmlspecialchars($allocation['category_name']) ?></span></td>
                            <td>
                                <span class="badge bg-<?= $allocation['allocation_type'] === 'batch' ? 'primary' : 'info' ?> small">
                                    <?= ucwords(str_replace('_', ' ', $allocation['allocation_type'])) ?>
                                </span>
                            </td>
                            <td><code class="small"><?= htmlspecialchars($allocation['target_id']) ?></code></td>
                            <td class="text-end fw-bold">$<?= number_format($allocation['allocated_amount'], 2) ?></td>
                            <td class="small"><?= date('M j', strtotime($allocation['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="p-4 text-center text-muted">
                <p class="mb-0">No allocations yet.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Allocate Expense Modal -->
<div class="modal fade" id="allocateExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Allocate Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="expenseInfoDisplay" class="mb-3"></div>
                
                <form id="allocationForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Allocation Type</label>
                        <select class="form-select" id="allocationType" required>
                            <option value="">Select...</option>
                            <option value="batch">Batch/Work Order</option>
                            <option value="product_group">Product Group</option>
                            <option value="product">Individual Product</option>
                            <option value="overhead">Overhead</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Target ID</label>
                        <input type="text" class="form-control" id="targetId" placeholder="Batch code, product group ID, etc." required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Allocated Amount</label>
                        <input type="number" class="form-control" id="allocatedAmount" step="0.01" min="0" required>
                        <small class="text-muted">Remaining: <span id="remainingAmount">$0.00</span></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Notes (Optional)</label>
                        <textarea class="form-control" id="allocationNotes" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAllocation()">
                    <i class="bi bi-save"></i> Save Allocation
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let allocationModal;
let currentExpenseId = null;
let currentExpenseTotal = 0;
let currentAllocated = 0;

document.addEventListener('DOMContentLoaded', function() {
    allocationModal = new bootstrap.Modal(document.getElementById('allocateExpenseModal'));
    
    // Load allocation pie chart
    loadAllocationChart();
    
    // Update remaining when amount changes
    document.getElementById('allocatedAmount')?.addEventListener('input', function() {
        const remaining = currentExpenseTotal - currentAllocated - parseFloat(this.value || 0);
        document.getElementById('remainingAmount').textContent = '$' + remaining.toFixed(2);
        document.getElementById('remainingAmount').className = remaining < -0.01 ? 'text-danger' : 'text-success';
    });
});

function loadAllocationChart() {
    const summaryData = <?= json_encode($allocationSummary) ?>;
    
    if (summaryData && summaryData.length > 0) {
        const ctx = document.getElementById('allocationPieChart').getContext('2d');
        
        const colors = {
            'batch': '#007bff',
            'product_group': '#28a745',
            'product': '#17a2b8',
            'overhead': '#ffc107'
        };
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: summaryData.map(s => s.allocation_type.replace('_', ' ').toUpperCase()),
                datasets: [{
                    data: summaryData.map(s => parseFloat(s.total_allocated)),
                    backgroundColor: summaryData.map(s => colors[s.allocation_type] || '#6c757d'),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': $' + context.parsed.toLocaleString('en-US', {minimumFractionDigits: 2});
                            }
                        }
                    }
                }
            }
        });
    }
}

async function allocateExpense(expenseId) {
    currentExpenseId = expenseId;
    
    // Fetch expense details
    try {
        const response = await fetch(`/api/accounting/expense-api.php?action=get_expense&expense_id=${expenseId}`);
        const result = await response.json();
        
        if (result.success && result.expense) {
            const expense = result.expense;
            currentExpenseTotal = parseFloat(expense.amount);
            currentAllocated = parseFloat(expense.allocated_amount || 0);
            
            const remaining = currentExpenseTotal - currentAllocated;
            
            document.getElementById('expenseInfoDisplay').innerHTML = `
                <div class="alert alert-info">
                    <strong>Expense:</strong> ${expense.description}<br>
                    <strong>Total:</strong> $${currentExpenseTotal.toFixed(2)}<br>
                    <strong>Already Allocated:</strong> $${currentAllocated.toFixed(2)}<br>
                    <strong>Remaining:</strong> $${remaining.toFixed(2)}
                </div>
            `;
            
            document.getElementById('allocatedAmount').value = remaining.toFixed(2);
            document.getElementById('remainingAmount').textContent = '$0.00';
            
            allocationModal.show();
        }
    } catch (error) {
        alert('Failed to load expense details');
    }
}

async function saveAllocation() {
    const allocationType = document.getElementById('allocationType').value;
    const targetId = document.getElementById('targetId').value;
    const amount = document.getElementById('allocatedAmount').value;
    const notes = document.getElementById('allocationNotes').value;
    
    if (!allocationType || !targetId || !amount) {
        alert('Please fill in all required fields');
        return;
    }
    
    if (parseFloat(amount) > (currentExpenseTotal - currentAllocated + 0.01)) {
        alert('Allocation amount exceeds remaining expense balance');
        return;
    }
    
    try {
        const response = await fetch('/api/accounting/expense-api.php?action=create_allocation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                expense_id: currentExpenseId,
                allocation_type: allocationType,
                target_id: targetId,
                allocated_amount: amount,
                allocation_notes: notes
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Allocation saved successfully!');
            allocationModal.hide();
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to save allocation: ' + error.message);
    }
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>












