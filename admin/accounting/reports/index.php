<?php
$SEO = [
    'title' => 'Financial Reports — Accounting',
    'description' => 'Pre-built financial reports and custom report builder',
    'type' => 'website'
];
require_once __DIR__ . '/../../../required/header.php';

$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1>Financial Reports</h1>
            <p class="text-muted">Pre-built reports and custom report designer</p>
        </div>
        <a href="../report-designer.php" class="btn btn-success">
            <i class="bi bi-tools"></i> Custom Report Designer
        </a>
    </div>

    <div class="row">
        <!-- Profit & Loss Report -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 border-primary">
                <div class="card-body text-center">
                    <div class="display-4 text-primary mb-3">
                        <i class="bi bi-graph-up"></i>
                    </div>
                    <h5 class="card-title">Profit & Loss</h5>
                    <p class="card-text">Comprehensive income statement with revenue, COGS, and operating expenses</p>
                    <a href="profit-report.php" class="btn btn-primary">View Report</a>
                </div>
            </div>
        </div>

        <!-- Expense Report -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 border-warning">
                <div class="card-body text-center">
                    <div class="display-4 text-warning mb-3">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <h5 class="card-title">Expense Analysis</h5>
                    <p class="card-text">Detailed expense breakdown by category, vendor, and trends</p>
                    <a href="expense-report.php" class="btn btn-warning">View Report</a>
                </div>
            </div>
        </div>

        <!-- Inventory Value Report -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 border-info">
                <div class="card-body text-center">
                    <div class="display-4 text-info mb-3">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <h5 class="card-title">Inventory Value</h5>
                    <p class="card-text">Manufacturing cost analysis and inventory valuation</p>
                    <a href="inventory-value-report.php" class="btn btn-info">View Report</a>
                </div>
            </div>
        </div>

        <!-- Projected Earnings Report -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 border-success">
                <div class="card-body text-center">
                    <div class="display-4 text-success mb-3">
                        <i class="bi bi-clipboard-data"></i>
                    </div>
                    <h5 class="card-title">Projected Earnings</h5>
                    <p class="card-text">Forward-looking revenue forecasts and cash flow projections</p>
                    <a href="projected-earnings-report.php" class="btn btn-success">View Report</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <h4>Need a Custom Report?</h4>
                    <p class="text-muted mb-3">Use the Report Designer to create custom financial reports with your own filters and date ranges</p>
                    <a href="../report-designer.php" class="btn btn-success btn-lg">
                        <i class="bi bi-tools"></i> Open Report Designer
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <a href="../index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Accounting Dashboard
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../../required/footer.php'; ?>





