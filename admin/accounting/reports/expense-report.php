<?php
$SEO = [
    'title' => 'Expense Report — Accounting',
    'description' => 'Detailed expense analysis and trends',
    'type' => 'website'
];
require_once __DIR__ . '/../../../required/header.php';

$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}

$pdo = db();

// Get parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// Total expenses
$totalExpenses = 0;
$expensesByCategory = [];
$expensesByVendor = [];
$apiCosts = [];
$subscriptionCosts = [];

try {
    // Expenses by category
    $categoryStmt = $pdo->prepare("
        SELECT 
            ec.category_name,
            COUNT(e.id) as count,
            COALESCE(SUM(e.amount), 0) as total,
            COALESCE(AVG(e.amount), 0) as average
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.expense_category_id
        WHERE e.expense_date BETWEEN ? AND ?
        GROUP BY ec.category_name
        ORDER BY total DESC
    ");
    $categoryStmt->execute([$startDate, $endDate]);
    $expensesByCategory = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalExpenses = array_sum(array_column($expensesByCategory, 'total'));
    
    // Top vendors
    $vendorStmt = $pdo->prepare("
        SELECT 
            vendor_name,
            COUNT(*) as transaction_count,
            COALESCE(SUM(amount), 0) as total_spent
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
        AND vendor_name IS NOT NULL
        GROUP BY vendor_name
        ORDER BY total_spent DESC
        LIMIT 10
    ");
    $vendorStmt->execute([$startDate, $endDate]);
    $expensesByVendor = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // API costs breakdown
    $apiStmt = $pdo->prepare("
        SELECT 
            api_service,
            COUNT(*) as call_count,
            COALESCE(SUM(total_cost), 0) as total_cost
        FROM api_usage_tracking
        WHERE DATE(usage_date) BETWEEN ? AND ?
        GROUP BY api_service
        ORDER BY total_cost DESC
    ");
    $apiStmt->execute([$startDate, $endDate]);
    $apiCosts = $apiStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Subscription costs
    $subStmt = $pdo->prepare("
        SELECT 
            subscription_name,
            vendor_name,
            amount,
            billing_cycle,
            next_billing_date
        FROM subscription_expenses
        WHERE status = 'active'
        ORDER BY amount DESC
    ");
    $subStmt->execute();
    $subscriptionCosts = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Tables don't exist yet
}

// Calculate monthly recurring total
$monthlyRecurring = 0;
foreach ($subscriptionCosts as $sub) {
    switch ($sub['billing_cycle']) {
        case 'monthly':
            $monthlyRecurring += $sub['amount'];
            break;
        case 'quarterly':
            $monthlyRecurring += $sub['amount'] / 3;
            break;
        case 'annual':
            $monthlyRecurring += $sub['amount'] / 12;
            break;
    }
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-receipt text-warning"></i> Expense Analysis Report</h1>
            <p class="text-muted">Detailed expense breakdown for <?= date('M j, Y', strtotime($startDate)) ?> to <?= date('M j, Y', strtotime($endDate)) ?></p>
        </div>
        <a href="../export_handler.php?report_type=expenses&format=csv&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" 
           class="btn btn-outline-primary">
            <i class="bi bi-download"></i> Export
        </a>
    </div>

    <!-- Date Filter -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body text-center">
                    <small class="text-muted">Total Expenses (Period)</small>
                    <h3 class="text-danger mb-0">$<?= number_format($totalExpenses, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-info">
                <div class="card-body text-center">
                    <small class="text-muted">Monthly Recurring</small>
                    <h3 class="text-info mb-0">$<?= number_format($monthlyRecurring, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center">
                    <small class="text-muted">Active Subscriptions</small>
                    <h3 class="text-warning mb-0"><?= count($subscriptionCosts) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Expenses by Category -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-tags"></i> Expenses by Category</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (count($expensesByCategory) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <th class="text-center">Count</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expensesByCategory as $cat): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($cat['category_name']) ?></strong></td>
                                <td class="text-center"><?= $cat['count'] ?></td>
                                <td class="text-end">$<?= number_format($cat['total'], 2) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-secondary">
                                        <?= $totalExpenses > 0 ? round(($cat['total'] / $totalExpenses) * 100, 1) : 0 ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <p>No expenses recorded for this period</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Vendors -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-building"></i> Top Vendors</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (count($expensesByVendor) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vendor</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Total Spent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expensesByVendor as $vendor): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($vendor['vendor_name']) ?></strong></td>
                                <td class="text-center"><?= $vendor['transaction_count'] ?></td>
                                <td class="text-end">$<?= number_format($vendor['total_spent'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <p>No vendor expenses recorded</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- API Usage Costs -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-cloud"></i> API Usage Costs</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (count($apiCosts) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Service</th>
                                <th class="text-center">Calls</th>
                                <th class="text-end">Total Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiCosts as $api): ?>
                            <tr>
                                <td><strong><?= ucwords(str_replace('_', ' ', $api['api_service'])) ?></strong></td>
                                <td class="text-center"><?= number_format($api['call_count']) ?></td>
                                <td class="text-end">$<?= number_format($api['total_cost'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>Total API Costs</th>
                                <th></th>
                                <th class="text-end">$<?= number_format(array_sum(array_column($apiCosts, 'total_cost')), 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <p>No API usage tracked for this period</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Active Subscriptions -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Active Subscriptions</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (count($subscriptionCosts) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Subscription</th>
                                <th>Vendor</th>
                                <th class="text-center">Cycle</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscriptionCosts as $sub): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($sub['subscription_name']) ?></strong></td>
                                <td><?= htmlspecialchars($sub['vendor_name']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-info small"><?= ucfirst($sub['billing_cycle']) ?></span>
                                </td>
                                <td class="text-end">$<?= number_format($sub['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3">Est. Monthly Recurring</th>
                                <th class="text-end">$<?= number_format($monthlyRecurring, 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <p>No active subscriptions</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <a href="../index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
        <a href="./" class="btn btn-outline-primary ms-2">
            <i class="bi bi-folder2-open"></i> All Reports
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../../required/footer.php'; ?>
