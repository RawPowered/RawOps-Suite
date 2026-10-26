<?php
$SEO = [
    'title' => 'My Books — Sales Agent',
    'description' => 'Detailed transaction ledger and personal accounting',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Wrap in output buffering to catch any errors
ob_start();

// Ensure user is a sales agent
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

if (!can_view_agent_books($userId, $userId)) {
    flash_set('error', 'Access denied.');
    redirect('/');
}

$pdo = db();

// Get filter parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$transactionType = $_GET['type'] ?? '';

// Get all transactions (commissions and expenses)
$transactions = [];

// Get commissions
$commissionsStmt = $pdo->prepare("
    SELECT 
        c.id,
        'commission' as type,
        c.created_at as date,
        c.amount,
        c.status,
        o.id as order_id,
        a.company_name as customer_name,
        c.notes as description
    FROM commissions c
    LEFT JOIN orders o ON o.id = c.order_id
    LEFT JOIN accounts a ON a.id = o.account_id
    WHERE c.user_id = ?
    AND DATE(c.created_at) BETWEEN ? AND ?
");
$commissionsStmt->execute([$userId, $startDate, $endDate]);
$commissions = $commissionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get expenses charged to agent
try {
    $expensesStmt = $pdo->prepare("
        SELECT 
            e.id,
            'expense' as type,
            e.expense_date as date,
            e.amount,
            'charged' as status,
            NULL as order_id,
            e.description as customer_name,
            e.notes as description
        FROM agent_expense_charges e
        WHERE e.agent_id = ?
        AND e.expense_date BETWEEN ? AND ?
    ");
    $expensesStmt->execute([$userId, $startDate, $endDate]);
    $expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expenses = [];
}

// Combine and sort transactions
$transactions = array_merge($commissions, $expenses);
usort($transactions, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

// Calculate running balance
$runningBalance = 0;
foreach ($transactions as &$txn) {
    if ($txn['type'] === 'commission') {
        $runningBalance += $txn['amount'];
    } else {
        $runningBalance -= $txn['amount'];
    }
    $txn['running_balance'] = $runningBalance;
}
unset($txn);

// Calculate period totals
$totalIncome = array_sum(array_map(fn($t) => $t['type'] === 'commission' ? $t['amount'] : 0, $transactions));
$totalExpenses = array_sum(array_map(fn($t) => $t['type'] === 'expense' ? $t['amount'] : 0, $transactions));
$netIncome = $totalIncome - $totalExpenses;

// Get agent info
$agentStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$agentStmt->execute([$userId]);
$agent = $agentStmt->fetch(PDO::FETCH_ASSOC);
?>

<style>
.transaction-row { transition: background-color 0.2s; }
.transaction-row:hover { background-color: #f8f9fa; }
.balance-positive { color: #28a745; font-weight: 600; }
.balance-negative { color: #dc3545; font-weight: 600; }
.balance-neutral { color: #6c757d; }
.filter-card { background: #f8f9fa; }
</style>

<div class="container-fluid mt-4">
    <?php if (function_exists('render_agent_financial_nav')): ?>
        <?php render_agent_financial_nav('books-detail.php'); ?>
    <?php endif; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-book text-primary"></i> My Books</h1>
            <p class="text-muted">Detailed transaction ledger for <?= htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']) ?></p>
        </div>
        <div>
            <a href="my-financials.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-graph-up"></i> Financials
            </a>
            <a href="my-1099.php" class="btn btn-outline-info">
                <i class="bi bi-file-earmark-text"></i> 1099 Info
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-success">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-muted mb-2">Total Income</div>
                    <h2 class="mb-0 text-success">$<?= number_format($totalIncome, 2) ?></h2>
                    <small class="text-muted">Commissions earned</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-muted mb-2">Total Expenses</div>
                    <h2 class="mb-0 text-danger">$<?= number_format($totalExpenses, 2) ?></h2>
                    <small class="text-muted">Deductions</small>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-primary">
                <div class="card-body text-center">
                    <div class="text-uppercase small text-muted mb-2">Net Income</div>
                    <h2 class="mb-0 <?= $netIncome >= 0 ? 'text-primary' : 'text-danger' ?>">
                        $<?= number_format($netIncome, 2) ?>
                    </h2>
                    <small class="text-muted">Period total</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4 filter-card">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All</option>
                        <option value="commission" <?= $transactionType === 'commission' ? 'selected' : '' ?>>Income</option>
                        <option value="expense" <?= $transactionType === 'expense' ? 'selected' : '' ?>>Expenses</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transaction Ledger -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-check"></i> Transaction Ledger</h5>
            <button class="btn btn-sm btn-outline-success" onclick="exportLedger()">
                <i class="bi bi-download"></i> Export CSV
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (count($transactions) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="transactionTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($transactions) as $txn): ?>
                        <tr class="transaction-row">
                            <td class="small"><?= date('M j, Y', strtotime($txn['date'])) ?></td>
                            <td>
                                <?php if ($txn['type'] === 'commission'): ?>
                                    <span class="badge bg-success"><i class="bi bi-arrow-down"></i> Income</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-arrow-up"></i> Expense</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($txn['type'] === 'commission'): ?>
                                    Commission - <?= htmlspecialchars($txn['customer_name'] ?? 'N/A') ?>
                                    <?php if ($txn['order_id']): ?>
                                        <a href="../invoices.php?id=<?= $txn['order_id'] ?>" class="small text-muted">
                                            (Order #<?= $txn['order_id'] ?>)
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($txn['customer_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($txn['type'] === 'expense'): ?>
                                    <span class="text-danger">$<?= number_format($txn['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($txn['type'] === 'commission'): ?>
                                    <span class="text-success">$<?= number_format($txn['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <span class="<?= $txn['running_balance'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                    $<?= number_format($txn['running_balance'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $statusColors = [
                                    'pending' => 'warning',
                                    'paid' => 'success',
                                    'charged' => 'info',
                                    'void' => 'secondary'
                                ];
                                $statusColor = $statusColors[$txn['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $statusColor ?> small"><?= ucfirst($txn['status']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Period Totals:</td>
                            <td class="text-end text-danger">$<?= number_format($totalExpenses, 2) ?></td>
                            <td class="text-end text-success">$<?= number_format($totalIncome, 2) ?></td>
                            <td class="text-end <?= $netIncome >= 0 ? 'text-success' : 'text-danger' ?>">
                                $<?= number_format($netIncome, 2) ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>
                <p class="mb-0">No transactions found for the selected period.</p>
                <small>Try adjusting your date range.</small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Help Section -->
    <div class="card shadow-sm border-info">
        <div class="card-body">
            <h6 class="text-info"><i class="bi bi-info-circle"></i> Understanding Your Books</h6>
            <div class="row">
                <div class="col-md-6">
                    <p class="small mb-2"><strong>Income (Credits):</strong> Commission payments you've earned from sales.</p>
                    <p class="small mb-2"><strong>Expenses (Debits):</strong> Costs charged to you (sample shipping, etc.).</p>
                </div>
                <div class="col-md-6">
                    <p class="small mb-2"><strong>Running Balance:</strong> Your net earnings after expenses.</p>
                    <p class="small mb-0"><strong>For Tax Purposes:</strong> Export this ledger for your tax preparer.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportLedger() {
    const startDate = '<?= $startDate ?>';
    const endDate = '<?= $endDate ?>';
    window.location.href = `/api/accounting/unified-api.php?action=export_agent_ledger&agent_id=<?= $userId ?>&start_date=${startDate}&end_date=${endDate}&format=csv`;
}

// DataTables integration (if available)
if (typeof $ !== 'undefined' && $.fn.DataTable) {
    $('#transactionTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 50,
        responsive: true
    });
}
</script>

<?php 
ob_end_flush();
require_once __DIR__ . '/../../required/footer.php'; 
?>

