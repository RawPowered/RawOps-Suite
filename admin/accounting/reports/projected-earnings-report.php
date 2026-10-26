<?php
$SEO = [
    'title' => 'Projected Earnings Report — Accounting',
    'description' => 'Forward-looking revenue and cash flow projections',
    'type' => 'website'
];
require_once __DIR__ . '/../../../required/header.php';

$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}

$pdo = db();

// Pipeline revenue (orders in progress)
$pipelineStmt = $pdo->query("
    SELECT 
        COUNT(*) as order_count,
        COALESCE(SUM(total), 0) as pipeline_revenue
    FROM orders
    WHERE status IN ('pending', 'submitted')
    AND payment_status != 'paid'
");
$pipeline = $pipelineStmt->fetch(PDO::FETCH_ASSOC);

// Commission liability forecast
$commissionLiabilityStmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM commissions
    WHERE status = 'pending'
");
$commissionLiability = $commissionLiabilityStmt->fetch(PDO::FETCH_ASSOC);

// Average monthly revenue (last 3 months)
$avgRevenueStmt = $pdo->query("
    SELECT COALESCE(AVG(monthly_total), 0) as avg_revenue
    FROM (
        SELECT SUM(total) as monthly_total
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
        AND status != 'cancelled'
        AND payment_status = 'paid'
        GROUP BY YEAR(created_at), MONTH(created_at)
    ) as monthly_totals
");
$avgRevenue = $avgRevenueStmt->fetch(PDO::FETCH_ASSOC);

// Average monthly expenses (last 3 months)
$avgExpenses = 0;
try {
    $avgExpenseStmt = $pdo->query("
        SELECT COALESCE(AVG(monthly_total), 0) as avg_expenses
        FROM (
            SELECT SUM(amount) as monthly_total
            FROM expenses
            WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
            AND payment_status = 'paid'
            GROUP BY YEAR(expense_date), MONTH(expense_date)
        ) as monthly_totals
    ");
    $result = $avgExpenseStmt->fetch(PDO::FETCH_ASSOC);
    $avgExpenses = $result['avg_expenses'];
} catch (Exception $e) {
    // Table doesn't exist
}

// Monthly recurring subscriptions
$monthlyRecurring = 0;
try {
    $subStmt = $pdo->query("
        SELECT SUM(
            CASE 
                WHEN billing_cycle = 'monthly' THEN amount
                WHEN billing_cycle = 'quarterly' THEN amount / 3
                WHEN billing_cycle = 'annual' THEN amount / 12
                ELSE 0
            END
        ) as monthly_recurring
        FROM subscription_expenses
        WHERE status = 'active'
    ");
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
    $monthlyRecurring = $sub['monthly_recurring'] ?? 0;
} catch (Exception $e) {
    // Table doesn't exist
}

// Projected cash flow (next 30/60/90 days)
$projectedProfit30 = ($avgRevenue['avg_revenue'] - $avgExpenses - $monthlyRecurring);
$projectedProfit60 = $projectedProfit30 * 2;
$projectedProfit90 = $projectedProfit30 * 3;

// Add pipeline to 30-day projection
$cashFlow30 = $projectedProfit30 + $pipeline['pipeline_revenue'] - $commissionLiability['total'];
$cashFlow60 = $projectedProfit60;
$cashFlow90 = $projectedProfit90;
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-clipboard-data text-success"></i> Projected Earnings Report</h1>
            <p class="text-muted">Forward-looking revenue and cash flow analysis</p>
        </div>
    </div>

    <!-- Pipeline Revenue -->
    <div class="card shadow-sm border-primary mb-4">
        <div class="card-header bg-primary bg-opacity-10">
            <h5 class="mb-0 text-primary"><i class="bi bi-hourglass-split"></i> Pipeline Revenue</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-muted small">Orders in Progress</h6>
                    <h3 class="mb-0"><?= $pipeline['order_count'] ?></h3>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted small">Expected Revenue</h6>
                    <h3 class="text-success mb-0">$<?= number_format($pipeline['pipeline_revenue'], 2) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Cash Flow Projections -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-success h-100">
                <div class="card-header bg-success bg-opacity-10">
                    <h6 class="mb-0 text-success">30-Day Projection</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted">Projected Revenue:</small>
                        <div class="fw-bold">$<?= number_format($avgRevenue['avg_revenue'], 2) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Pipeline:</small>
                        <div class="fw-bold">$<?= number_format($pipeline['pipeline_revenue'], 2) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Expected Expenses:</small>
                        <div class="fw-bold text-danger">$<?= number_format($avgExpenses + $monthlyRecurring, 2) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Commission Liability:</small>
                        <div class="fw-bold text-warning">$<?= number_format($commissionLiability['total'], 2) ?></div>
                    </div>
                    <hr>
                    <div>
                        <strong>Projected Cash Flow:</strong>
                        <h4 class="<?= $cashFlow30 >= 0 ? 'text-success' : 'text-danger' ?> mb-0">
                            $<?= number_format($cashFlow30, 2) ?>
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-info h-100">
                <div class="card-header bg-info bg-opacity-10">
                    <h6 class="mb-0 text-info">60-Day Projection</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted">Projected Revenue:</small>
                        <div class="fw-bold">$<?= number_format($avgRevenue['avg_revenue'] * 2, 2) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Expected Expenses:</small>
                        <div class="fw-bold text-danger">$<?= number_format(($avgExpenses + $monthlyRecurring) * 2, 2) ?></div>
                    </div>
                    <hr>
                    <div>
                        <strong>Projected Cash Flow:</strong>
                        <h4 class="<?= $cashFlow60 >= 0 ? 'text-success' : 'text-danger' ?> mb-0">
                            $<?= number_format($cashFlow60, 2) ?>
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-warning h-100">
                <div class="card-header bg-warning bg-opacity-10">
                    <h6 class="mb-0 text-warning">90-Day Projection</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted">Projected Revenue:</small>
                        <div class="fw-bold">$<?= number_format($avgRevenue['avg_revenue'] * 3, 2) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Expected Expenses:</small>
                        <div class="fw-bold text-danger">$<?= number_format(($avgExpenses + $monthlyRecurring) * 3, 2) ?></div>
                    </div>
                    <hr>
                    <div>
                        <strong>Projected Cash Flow:</strong>
                        <h4 class="<?= $cashFlow90 >= 0 ? 'text-success' : 'text-danger' ?> mb-0">
                            $<?= number_format($cashFlow90, 2) ?>
                        </h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <strong><i class="bi bi-info-circle"></i> Note:</strong> Projections are based on average revenue and expenses from the last 3 months. Actual results may vary based on seasonality, market conditions, and business activities.
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
