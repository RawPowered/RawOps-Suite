<?php
$SEO = [
    'title' => 'Profit & Loss Report — Accounting',
    'description' => 'Comprehensive profit and loss statement',
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
$method = $_GET['method'] ?? $_SESSION['accounting_method'] ?? 'accrual';

// REVENUE
$revenueQuery = "
    SELECT COALESCE(SUM(o.total), 0) as total
    FROM orders o
    WHERE o.created_at BETWEEN ? AND ?
    AND o.status != 'cancelled'
";
if ($method === 'cash') {
    $revenueQuery .= " AND o.payment_status = 'paid'";
}
$revenueStmt = $pdo->prepare($revenueQuery);
$revenueStmt->execute([$startDate, $endDate]);
$revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);

// COGS - from expenses
$cogsBreakdown = [];
$totalCOGS = 0;
try {
    $cogsStmt = $pdo->prepare("
        SELECT 
            ec.category_name,
            coa.account_name,
            COALESCE(SUM(e.amount), 0) as total
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.expense_category_id
        JOIN chart_of_accounts coa ON coa.id = ec.account_id
        WHERE e.expense_date BETWEEN ? AND ?
        AND coa.account_type = 'cogs'
        GROUP BY ec.category_name, coa.account_name
        ORDER BY total DESC
    ");
    $cogsStmt->execute([$startDate, $endDate]);
    $cogsBreakdown = $cogsStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCOGS = array_sum(array_column($cogsBreakdown, 'total'));
} catch (Exception $e) {
    // Tables don't exist yet
}

// GROSS PROFIT
$grossProfit = $revenue['total'] - $totalCOGS;
$grossMargin = $revenue['total'] > 0 ? round(($grossProfit / $revenue['total']) * 100, 2) : 0;

// OPERATING EXPENSES
$opexBreakdown = [];
$totalOpex = 0;
try {
    $opexStmt = $pdo->prepare("
        SELECT 
            ec.category_name,
            coa.account_name,
            COALESCE(SUM(e.amount), 0) as total
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.expense_category_id
        JOIN chart_of_accounts coa ON coa.id = ec.account_id
        WHERE e.expense_date BETWEEN ? AND ?
        AND coa.account_type = 'expense'
        GROUP BY ec.category_name, coa.account_name
        ORDER BY total DESC
    ");
    $opexStmt->execute([$startDate, $endDate]);
    $opexBreakdown = $opexStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalOpex = array_sum(array_column($opexBreakdown, 'total'));
} catch (Exception $e) {
    // Tables don't exist yet
}

// COMMISSIONS
$commissionBreakdown = [];
$totalCommissions = 0;
$commissionStmt = $pdo->prepare("
    SELECT 
        commission_type,
        COALESCE(SUM(amount), 0) as total,
        COUNT(*) as count
    FROM commissions
    WHERE created_at BETWEEN ? AND ?
    GROUP BY commission_type
");
$commissionStmt->execute([$startDate, $endDate]);
$commissionBreakdown = $commissionStmt->fetchAll(PDO::FETCH_ASSOC);
$totalCommissions = array_sum(array_column($commissionBreakdown, 'total'));

// NET PROFIT
$netProfit = $grossProfit - $totalOpex - $totalCommissions;
$netMargin = $revenue['total'] > 0 ? round(($netProfit / $revenue['total']) * 100, 2) : 0;

// EBITDA (same as net profit in simplified version)
$ebitda = $netProfit;
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-graph-up text-success"></i> Profit & Loss Statement</h1>
            <p class="text-muted">Income statement for <?= date('M j, Y', strtotime($startDate)) ?> to <?= date('M j, Y', strtotime($endDate)) ?></p>
        </div>
        <div>
            <span class="badge bg-<?= $method === 'accrual' ? 'primary' : 'info' ?> me-2"><?= ucfirst($method) ?> Basis</span>
            <a href="../export_handler.php?report_type=profit_loss&format=csv&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" 
               class="btn btn-outline-primary btn-sm">
                <i class="bi bi-download"></i> Export
            </a>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Accounting Method</label>
                    <select name="method" class="form-select">
                        <option value="accrual" <?= $method === 'accrual' ? 'selected' : '' ?>>Accrual</option>
                        <option value="cash" <?= $method === 'cash' ? 'selected' : '' ?>>Cash</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- P&L Statement -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <tbody>
                    <!-- REVENUE -->
                    <tr class="table-primary">
                        <th colspan="2" class="ps-3">
                            <h5 class="mb-0"><i class="bi bi-cash-stack"></i> REVENUE</h5>
                        </th>
                        <th class="text-end pe-3"></th>
                    </tr>
                    <tr>
                        <td class="ps-4">Product Sales</td>
                        <td></td>
                        <td class="text-end fw-bold pe-3">$<?= number_format($revenue['total'], 2) ?></td>
                    </tr>
                    <tr class="table-light">
                        <td class="ps-3"><strong>Total Revenue</strong></td>
                        <td></td>
                        <td class="text-end pe-3"><strong>$<?= number_format($revenue['total'], 2) ?></strong></td>
                    </tr>
                    
                    <!-- COGS -->
                    <tr class="table-warning">
                        <th colspan="2" class="ps-3 pt-4">
                            <h5 class="mb-0"><i class="bi bi-box"></i> COST OF GOODS SOLD</h5>
                        </th>
                        <th class="text-end pe-3 pt-4"></th>
                    </tr>
                    <?php foreach ($cogsBreakdown as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars($item['category_name']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($item['account_name']) ?></td>
                        <td class="text-end pe-3">$<?= number_format($item['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($cogsBreakdown) === 0): ?>
                    <tr>
                        <td colspan="3" class="ps-4 text-muted fst-italic">No COGS recorded for this period</td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-light">
                        <td class="ps-3"><strong>Total COGS</strong></td>
                        <td></td>
                        <td class="text-end pe-3"><strong>$<?= number_format($totalCOGS, 2) ?></strong></td>
                    </tr>
                    
                    <!-- GROSS PROFIT -->
                    <tr class="table-success">
                        <td class="ps-3"><strong>GROSS PROFIT</strong></td>
                        <td class="text-muted small"><?= $grossMargin ?>% margin</td>
                        <td class="text-end pe-3"><strong class="<?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>">$<?= number_format($grossProfit, 2) ?></strong></td>
                    </tr>
                    
                    <!-- OPERATING EXPENSES -->
                    <tr class="table-danger">
                        <th colspan="2" class="ps-3 pt-4">
                            <h5 class="mb-0"><i class="bi bi-receipt"></i> OPERATING EXPENSES</h5>
                        </th>
                        <th class="text-end pe-3 pt-4"></th>
                    </tr>
                    <?php foreach ($opexBreakdown as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars($item['category_name']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($item['account_name']) ?></td>
                        <td class="text-end pe-3">$<?= number_format($item['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($opexBreakdown) === 0): ?>
                    <tr>
                        <td colspan="3" class="ps-4 text-muted fst-italic">No operating expenses recorded for this period</td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-light">
                        <td class="ps-3"><strong>Total Operating Expenses</strong></td>
                        <td></td>
                        <td class="text-end pe-3"><strong>$<?= number_format($totalOpex, 2) ?></strong></td>
                    </tr>
                    
                    <!-- COMMISSIONS -->
                    <tr class="table-info">
                        <th colspan="2" class="ps-3 pt-4">
                            <h5 class="mb-0"><i class="bi bi-wallet2"></i> COMMISSION EXPENSES</h5>
                        </th>
                        <th class="text-end pe-3 pt-4"></th>
                    </tr>
                    <?php foreach ($commissionBreakdown as $item): ?>
                    <tr>
                        <td class="ps-4"><?= ucwords(str_replace('_', ' ', $item['commission_type'])) ?></td>
                        <td class="text-muted small"><?= $item['count'] ?> commissions</td>
                        <td class="text-end pe-3">$<?= number_format($item['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($commissionBreakdown) === 0): ?>
                    <tr>
                        <td colspan="3" class="ps-4 text-muted fst-italic">No commissions for this period</td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-light">
                        <td class="ps-3"><strong>Total Commissions</strong></td>
                        <td></td>
                        <td class="text-end pe-3"><strong>$<?= number_format($totalCommissions, 2) ?></strong></td>
                    </tr>
                    
                    <!-- NET PROFIT -->
                    <tr class="<?= $netProfit >= 0 ? 'table-success' : 'table-danger' ?>" style="border-top: 3px solid #000;">
                        <td class="ps-3 py-3"><h5 class="mb-0">NET PROFIT</h5></td>
                        <td class="py-3"><span class="badge bg-<?= $netProfit >= 0 ? 'success' : 'danger' ?>"><?= $netMargin ?>% margin</span></td>
                        <td class="text-end pe-3 py-3">
                            <h4 class="mb-0 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                $<?= number_format($netProfit, 2) ?>
                            </h4>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mt-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-primary">
                <div class="card-body text-center">
                    <small class="text-muted">Total Revenue</small>
                    <h4 class="text-primary mb-0">$<?= number_format($revenue['total'], 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center">
                    <small class="text-muted">Total COGS</small>
                    <h4 class="text-warning mb-0">$<?= number_format($totalCOGS, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body text-center">
                    <small class="text-muted">Total Expenses</small>
                    <h4 class="text-danger mb-0">$<?= number_format($totalOpex + $totalCommissions, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-success">
                <div class="card-body text-center">
                    <small class="text-muted">Net Profit</small>
                    <h4 class="<?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?> mb-0">
                        $<?= number_format($netProfit, 2) ?>
                    </h4>
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
