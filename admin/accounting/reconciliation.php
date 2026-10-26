<?php
$SEO = [
    'title' => 'Data Reconciliation — Accounting',
    'description' => 'Validate accounting data accuracy against source systems',
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

require_accounting_permission($userId, 'run_reconciliation', 'can_run_reconciliation');

$pdo = db();

// Get date range from request
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// ============================================
// ORDERS RECONCILIATION
// ============================================

// Total from orders table
$ordersRevenueStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as order_count,
        COALESCE(SUM(total), 0) as total_revenue
    FROM orders 
    WHERE payment_status = 'paid'
    AND created_at BETWEEN ? AND ?
");
$ordersRevenueStmt->execute([$startDate, $endDate]);
$ordersRevenue = $ordersRevenueStmt->fetch(PDO::FETCH_ASSOC);

// Total from journal entries
$journalRevenueStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT je.id) as entry_count,
        COALESCE(SUM(jel.credit_amount), 0) as total_revenue
    FROM journal_entries je
    JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE coa.account_type = 'revenue'
    AND je.reference_type = 'order'
    AND je.entry_date BETWEEN ? AND ?
    AND je.status = 'posted'
");
$journalRevenueStmt->execute([$startDate, $endDate]);
$journalRevenue = $journalRevenueStmt->fetch(PDO::FETCH_ASSOC);

$revenueDiscrepancy = abs($ordersRevenue['total_revenue'] - $journalRevenue['total_revenue']);
$revenueMatch = $revenueDiscrepancy < 0.01;

// ============================================
// COMMISSIONS RECONCILIATION
// ============================================

// Total from commissions table
$commissionsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as commission_count,
        COALESCE(SUM(amount), 0) as total_commissions
    FROM commissions 
    WHERE created_at BETWEEN ? AND ?
");
$commissionsStmt->execute([$startDate, $endDate]);
$commissions = $commissionsStmt->fetch(PDO::FETCH_ASSOC);

// Total from journal entries
$journalCommissionsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT je.id) as entry_count,
        COALESCE(SUM(jel.debit_amount), 0) as total_commissions
    FROM journal_entries je
    JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE coa.account_code LIKE '61%'
    AND je.reference_type = 'commission'
    AND je.entry_date BETWEEN ? AND ?
    AND je.status = 'posted'
");
$journalCommissionsStmt->execute([$startDate, $endDate]);
$journalCommissions = $journalCommissionsStmt->fetch(PDO::FETCH_ASSOC);

$commissionDiscrepancy = abs($commissions['total_commissions'] - $journalCommissions['total_commissions']);
$commissionMatch = $commissionDiscrepancy < 0.01;

// ============================================
// PRODUCTION COSTS RECONCILIATION
// ============================================

// Get production costs from work orders (if table exists)
$productionCosts = ['work_order_count' => 0, 'total_costs' => 0];
try {
    $productionStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as work_order_count,
            COALESCE(SUM(total_allocated_costs), 0) as total_costs
        FROM work_orders 
        WHERE created_at BETWEEN ? AND ?
    ");
    $productionStmt->execute([$startDate, $endDate]);
    $productionCosts = $productionStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist yet
}

// Total COGS from journal entries
$journalCogsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT je.id) as entry_count,
        COALESCE(SUM(jel.debit_amount), 0) as total_cogs
    FROM journal_entries je
    JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE coa.account_type = 'cogs'
    AND je.reference_type = 'work_order'
    AND je.entry_date BETWEEN ? AND ?
    AND je.status = 'posted'
");
$journalCogsStmt->execute([$startDate, $endDate]);
$journalCogs = $journalCogsStmt->fetch(PDO::FETCH_ASSOC);

$cogsDiscrepancy = abs($productionCosts['total_costs'] - $journalCogs['total_cogs']);
$cogsMatch = $cogsDiscrepancy < 0.01;

// ============================================
// JOURNAL ENTRY BALANCE VALIDATION
// ============================================

$unbalancedEntriesStmt = $pdo->prepare("
    SELECT 
        je.id,
        je.entry_number,
        je.entry_date,
        je.description,
        SUM(jel.debit_amount) as total_debits,
        SUM(jel.credit_amount) as total_credits,
        ABS(SUM(jel.debit_amount) - SUM(jel.credit_amount)) as difference
    FROM journal_entries je
    JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    WHERE je.entry_date BETWEEN ? AND ?
    GROUP BY je.id
    HAVING ABS(SUM(jel.debit_amount) - SUM(jel.credit_amount)) > 0.01
    ORDER BY difference DESC
    LIMIT 20
");
$unbalancedEntriesStmt->execute([$startDate, $endDate]);
$unbalancedEntries = $unbalancedEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ORPHANED RECORDS
// ============================================

// Orders without journal entries
$orphanedOrdersStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM orders 
    WHERE payment_status = 'paid'
    AND created_at BETWEEN ? AND ?
    AND (journal_entry_id IS NULL OR journal_entry_id = 0)
");
$orphanedOrdersStmt->execute([$startDate, $endDate]);
$orphanedOrders = $orphanedOrdersStmt->fetchColumn();

// Commissions without journal entries
$orphanedCommissionsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM commissions 
    WHERE created_at BETWEEN ? AND ?
    AND (journal_entry_id IS NULL OR journal_entry_id = 0)
");
$orphanedCommissionsStmt->execute([$startDate, $endDate]);
$orphanedCommissions = $orphanedCommissionsStmt->fetchColumn();

?>

<style>
.reconciliation-card { border-left: 4px solid transparent; }
.reconciliation-card.match { border-color: #28a745; }
.reconciliation-card.mismatch { border-color: #dc3545; }
.metric-large { font-size: 2rem; font-weight: 700; }
.metric-small { font-size: 1.5rem; font-weight: 600; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-check2-square text-success"></i> Data Reconciliation</h1>
            <p class="text-muted">Validate accounting data accuracy against source systems</p>
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

    <!-- Date Range Filter -->
    <div class="card shadow-sm mb-4">
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
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Run Reconciliation
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Revenue Reconciliation -->
    <div class="card shadow-sm mb-4 reconciliation-card <?= $revenueMatch ? 'match' : 'mismatch' ?>">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-<?= $revenueMatch ? 'check-circle text-success' : 'exclamation-triangle text-danger' ?>"></i>
                Revenue Reconciliation
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Orders Table</h6>
                    <div class="metric-large text-primary">$<?= number_format($ordersRevenue['total_revenue'], 2) ?></div>
                    <small class="text-muted"><?= number_format($ordersRevenue['order_count']) ?> paid orders</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Journal Entries</h6>
                    <div class="metric-large text-info">$<?= number_format($journalRevenue['total_revenue'], 2) ?></div>
                    <small class="text-muted"><?= number_format($journalRevenue['entry_count']) ?> journal entries</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Discrepancy</h6>
                    <div class="metric-large <?= $revenueMatch ? 'text-success' : 'text-danger' ?>">
                        $<?= number_format($revenueDiscrepancy, 2) ?>
                    </div>
                    <small class="<?= $revenueMatch ? 'text-success' : 'text-danger' ?>">
                        <?= $revenueMatch ? '✓ Reconciled' : '✗ Mismatch' ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Commission Reconciliation -->
    <div class="card shadow-sm mb-4 reconciliation-card <?= $commissionMatch ? 'match' : 'mismatch' ?>">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-<?= $commissionMatch ? 'check-circle text-success' : 'exclamation-triangle text-danger' ?>"></i>
                Commission Reconciliation
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Commissions Table</h6>
                    <div class="metric-large text-warning">$<?= number_format($commissions['total_commissions'], 2) ?></div>
                    <small class="text-muted"><?= number_format($commissions['commission_count']) ?> commissions</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Journal Entries</h6>
                    <div class="metric-large text-info">$<?= number_format($journalCommissions['total_commissions'], 2) ?></div>
                    <small class="text-muted"><?= number_format($journalCommissions['entry_count']) ?> journal entries</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Discrepancy</h6>
                    <div class="metric-large <?= $commissionMatch ? 'text-success' : 'text-danger' ?>">
                        $<?= number_format($commissionDiscrepancy, 2) ?>
                    </div>
                    <small class="<?= $commissionMatch ? 'text-success' : 'text-danger' ?>">
                        <?= $commissionMatch ? '✓ Reconciled' : '✗ Mismatch' ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Production Costs Reconciliation -->
    <div class="card shadow-sm mb-4 reconciliation-card <?= $cogsMatch ? 'match' : 'mismatch' ?>">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-<?= $cogsMatch ? 'check-circle text-success' : 'exclamation-triangle text-danger' ?>"></i>
                Production Costs Reconciliation
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Work Orders</h6>
                    <div class="metric-large text-primary">$<?= number_format($productionCosts['total_costs'], 2) ?></div>
                    <small class="text-muted"><?= number_format($productionCosts['work_order_count']) ?> work orders</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">COGS Journal Entries</h6>
                    <div class="metric-large text-info">$<?= number_format($journalCogs['total_cogs'], 2) ?></div>
                    <small class="text-muted"><?= number_format($journalCogs['entry_count']) ?> journal entries</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase mb-2">Discrepancy</h6>
                    <div class="metric-large <?= $cogsMatch ? 'text-success' : 'text-danger' ?>">
                        $<?= number_format($cogsDiscrepancy, 2) ?>
                    </div>
                    <small class="<?= $cogsMatch ? 'text-success' : 'text-danger' ?>">
                        <?= $cogsMatch ? '✓ Reconciled' : '✗ Mismatch' ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Orphaned Records Alert -->
    <?php if ($orphanedOrders > 0 || $orphanedCommissions > 0): ?>
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Orphaned Records Detected</h5>
        <p class="mb-2">The following records need to be synced to accounting:</p>
        <ul class="mb-0">
            <?php if ($orphanedOrders > 0): ?>
                <li><strong><?= number_format($orphanedOrders) ?></strong> paid orders without journal entries</li>
            <?php endif; ?>
            <?php if ($orphanedCommissions > 0): ?>
                <li><strong><?= number_format($orphanedCommissions) ?></strong> commissions without journal entries</li>
            <?php endif; ?>
        </ul>
        <hr>
        <a href="sync-controller.php" class="btn btn-warning btn-sm">
            <i class="bi bi-arrow-repeat"></i> Go to Sync Controller
        </a>
    </div>
    <?php endif; ?>

    <!-- Unbalanced Entries -->
    <?php if (count($unbalancedEntries) > 0): ?>
    <div class="card shadow-sm border-danger mb-4">
        <div class="card-header bg-danger bg-opacity-10">
            <h5 class="mb-0 text-danger">
                <i class="bi bi-x-circle"></i> Unbalanced Journal Entries
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Entry Number</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th class="text-end">Total Debits</th>
                            <th class="text-end">Total Credits</th>
                            <th class="text-end">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unbalancedEntries as $entry): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($entry['entry_number']) ?></code></td>
                            <td><?= date('M j, Y', strtotime($entry['entry_date'])) ?></td>
                            <td><?= htmlspecialchars(substr($entry['description'], 0, 50)) ?></td>
                            <td class="text-end">$<?= number_format($entry['total_debits'], 2) ?></td>
                            <td class="text-end">$<?= number_format($entry['total_credits'], 2) ?></td>
                            <td class="text-end text-danger fw-bold">$<?= number_format($entry['difference'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Export Options -->
    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="mb-3">Export Reconciliation Report</h5>
            <div class="btn-group">
                <a href="/api/accounting/report-api.php?action=export_reconciliation&format=csv&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" 
                   class="btn btn-outline-primary">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <a href="/api/accounting/report-api.php?action=export_reconciliation&format=xlsx&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" 
                   class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>












