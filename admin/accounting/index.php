<?php
$SEO = [
    'title' => 'Accounting Dashboard — Product',
    'description' => 'Financial overview and accounting management',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';

// Ensure user has accountant or admin role
$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}

$pdo = db();

// Get accounting method preference (default: accrual)
$accountingMethod = $_SESSION['accounting_method'] ?? 'accrual';
if (isset($_POST['toggle_method'])) {
    $accountingMethod = $_POST['method'] === 'cash' ? 'cash' : 'accrual';
    $_SESSION['accounting_method'] = $accountingMethod;
}

// Date ranges
$currentMonth = date('Y-m');
$currentYear = date('Y');
$ytdStart = $currentYear . '-01-01';
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

// Get revenue (MTD, QTD, YTD)
$revenueQuery = "
    SELECT 
        SUM(CASE WHEN MONTH(o.created_at) = MONTH(CURDATE()) THEN o.total ELSE 0 END) as mtd,
        SUM(CASE WHEN QUARTER(o.created_at) = QUARTER(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE()) THEN o.total ELSE 0 END) as qtd,
        SUM(CASE WHEN YEAR(o.created_at) = YEAR(CURDATE()) THEN o.total ELSE 0 END) as ytd
    FROM orders o
    WHERE o.status != 'cancelled'
";

if ($accountingMethod === 'cash') {
    $revenueQuery .= " AND o.payment_status = 'paid'";
}

$revenueStmt = $pdo->query($revenueQuery);
$revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);

// Get expenses (MTD, QTD, YTD)
$expenseQuery = "
    SELECT 
        SUM(CASE WHEN MONTH(e.expense_date) = MONTH(CURDATE()) THEN e.amount ELSE 0 END) as mtd,
        SUM(CASE WHEN QUARTER(e.expense_date) = QUARTER(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE()) THEN e.amount ELSE 0 END) as qtd,
        SUM(CASE WHEN YEAR(e.expense_date) = YEAR(CURDATE()) THEN e.amount ELSE 0 END) as ytd
    FROM expenses e
";

if ($accountingMethod === 'cash') {
    $expenseQuery .= " WHERE e.payment_status = 'paid'";
}

$expenseStmt = $pdo->query($expenseQuery);
$expenses = $expenseStmt->fetch(PDO::FETCH_ASSOC);

// Calculate net profit
$netProfit = [
    'mtd' => ($revenue['mtd'] ?? 0) - ($expenses['mtd'] ?? 0),
    'qtd' => ($revenue['qtd'] ?? 0) - ($expenses['qtd'] ?? 0),
    'ytd' => ($revenue['ytd'] ?? 0) - ($expenses['ytd'] ?? 0)
];

// Calculate profit margin
$profitMargin = [
    'mtd' => $revenue['mtd'] > 0 ? round(($netProfit['mtd'] / $revenue['mtd']) * 100, 2) : 0,
    'qtd' => $revenue['qtd'] > 0 ? round(($netProfit['qtd'] / $revenue['qtd']) * 100, 2) : 0,
    'ytd' => $revenue['ytd'] > 0 ? round(($netProfit['ytd'] / $revenue['ytd']) * 100, 2) : 0
];

// Get outstanding invoices
$outstandingStmt = $pdo->query("
    SELECT COUNT(*) as count, SUM(total) as amount
    FROM orders
    WHERE payment_status != 'paid' AND status != 'cancelled'
");
$outstanding = $outstandingStmt->fetch(PDO::FETCH_ASSOC);

// Get unpaid expenses
$unpaid = ['count' => 0, 'amount' => 0];
try {
    $unpaidStmt = $pdo->query("
        SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as amount
        FROM expenses
        WHERE payment_status = 'unpaid'
    ");
    $unpaid = $unpaidStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard expenses query error: " . $e->getMessage());
}

// Get commission liability
$commissionLiability = ['total' => 0];
try {
    $commissionStmt = $pdo->query("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM commissions
        WHERE status = 'pending'
    ");
    $commissionLiability = $commissionStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard commissions query error: " . $e->getMessage());
}

// Load CSS inline to avoid path issues
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
/* Product Accounting Dashboard - World-Class Design */
.acct1015_container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem 1.5rem;
}

/* Header Section */
.acct1015_header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.acct1015_title {
    font-size: 2.5rem;
    font-weight: 700;
    color: #111827;
    margin: 0;
    letter-spacing: -0.02em;
}

.acct1015_subtitle {
    color: #6b7280;
    font-size: 1rem;
    margin: 0.5rem 0 0 0;
    font-weight: 400;
}

/* Metric Cards */
.acct1015_metrics_grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.acct1015_metric_card {
    background: white;
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #f3f4f6;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.acct1015_metric_card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--card-accent);
}

.acct1015_metric_card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.12);
    border-color: var(--card-accent);
}

.acct1015_metric_label {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6b7280;
    margin-bottom: 1rem;
}

.acct1015_metric_value {
    font-size: 3rem;
    font-weight: 700;
    color: #111827;
    line-height: 1;
    margin-bottom: 1.5rem;
}

.acct1015_metric_breakdown {
    display: flex;
    gap: 1.5rem;
    font-size: 0.875rem;
}

.acct1015_metric_item {
    flex: 1;
}

.acct1015_metric_item_label {
    color: #9ca3af;
    font-size: 0.75rem;
    margin-bottom: 0.25rem;
}

.acct1015_metric_item_value {
    color: #374151;
    font-weight: 600;
    font-size: 1rem;
}

/* Section Cards */
.acct1015_section {
    background: white;
    border-radius: 1rem;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #f3f4f6;
}

.acct1015_section_header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #f3f4f6;
}

.acct1015_section_title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

/* Tool Grid */
.acct1015_tools_grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.acct1015_tool_card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2.5rem 2rem;
    background: white;
    border: 2px solid #f3f4f6;
    border-radius: 1rem;
    text-decoration: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.acct1015_tool_card:hover {
    border-color: #d97706;
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(217,119,6,0.15);
}

.acct1015_tool_icon {
    font-size: 3.5rem;
    margin-bottom: 1.25rem;
    transition: transform 0.3s;
}

.acct1015_tool_card:hover .acct1015_tool_icon {
    transform: scale(1.1);
}

.acct1015_tool_title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
    margin-bottom: 0.5rem;
}

.acct1015_tool_desc {
    font-size: 0.875rem;
    color: #6b7280;
    text-align: center;
    margin: 0;
}

/* Quick Actions */
.acct1015_quick_actions {
    display: grid;
    gap: 0.75rem;
}

.acct1015_quick_btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    background: white;
    color: #374151;
    text-decoration: none !important;
    font-weight: 500;
    transition: all 0.2s;
}

.acct1015_quick_btn:hover {
    background: #f9fafb;
    border-color: #d97706;
    color: #111827;
    transform: translateX(4px);
}

.acct1015_quick_btn i {
    font-size: 1.25rem;
    color: #9ca3af;
}

.acct1015_quick_btn:hover i {
    color: #d97706;
}

/* Info Boxes */
.acct1015_info_grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.acct1015_info_box {
    padding: 1.5rem;
    background: #f9fafb;
    border-radius: 0.75rem;
    border-left: 4px solid var(--box-color);
}

.acct1015_info_label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.acct1015_info_value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #111827;
}

.acct1015_info_subtext {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-top: 0.25rem;
}

/* Method Toggle */
.acct1015_method_toggle {
    display: inline-flex;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 0.5rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.acct1015_method_btn {
    padding: 0.5rem 1.25rem;
    border: none;
    background: transparent;
    color: #6b7280;
    font-weight: 500;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
}

.acct1015_method_btn.active {
    background: linear-gradient(135deg, #d97706 0%, #fbbf24 100%);
    color: white;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(217,119,6,0.3);
}

/* Charts */
.acct1015_chart_container {
    background: white;
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #f3f4f6;
    height: 100%;
}

.acct1015_chart_title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Responsive */
@media (max-width: 768px) {
    .acct1015_metrics_grid {
        grid-template-columns: 1fr;
    }
    .acct1015_tools_grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="acct1015_container">
    <!-- Header -->
    <div class="acct1015_header">
        <div>
            <h1 class="acct1015_title">Accounting</h1>
            <p class="acct1015_subtitle">Financial overview and management</p>
        </div>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <a href="add-expense.php" class="acct1015_btn_primary" style="padding: 0.875rem 1.75rem; font-size: 1rem; text-decoration: none;">
                <i class="bi bi-receipt-cutoff"></i>
                <span>Log Expense</span>
            </a>
            <form method="POST" class="acct1015_method_toggle">
                <button type="submit" name="toggle_method" value="1" 
                        class="acct1015_method_btn <?= $accountingMethod === 'accrual' ? 'active' : '' ?>">
                    <input type="hidden" name="method" value="accrual">
                    Accrual
                </button>
                <button type="submit" name="toggle_method" value="1" 
                        class="acct1015_method_btn <?= $accountingMethod === 'cash' ? 'active' : '' ?>">
                    <input type="hidden" name="method" value="cash">
                    Cash
                </button>
            </form>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="acct1015_metrics_grid">
        <!-- Revenue -->
        <div class="acct1015_metric_card" style="--card-accent: #10b981;">
            <div class="acct1015_metric_label">Revenue</div>
            <div class="acct1015_metric_value" style="color: #047857;">
                $<?= number_format($revenue['ytd'] ?? 0, 0) ?>
            </div>
            <div class="acct1015_metric_breakdown">
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">MTD</div>
                    <div class="acct1015_metric_item_value">$<?= number_format($revenue['mtd'] ?? 0, 0) ?></div>
                </div>
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">QTD</div>
                    <div class="acct1015_metric_item_value">$<?= number_format($revenue['qtd'] ?? 0, 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Expenses -->
        <div class="acct1015_metric_card" style="--card-accent: #fbbf24;">
            <div class="acct1015_metric_label">Expenses</div>
            <div class="acct1015_metric_value" style="color: #d97706;">
                $<?= number_format($expenses['ytd'] ?? 0, 0) ?>
            </div>
            <div class="acct1015_metric_breakdown">
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">MTD</div>
                    <div class="acct1015_metric_item_value">$<?= number_format($expenses['mtd'] ?? 0, 0) ?></div>
                </div>
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">QTD</div>
                    <div class="acct1015_metric_item_value">$<?= number_format($expenses['qtd'] ?? 0, 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Net Profit -->
        <div class="acct1015_metric_card" style="--card-accent: #3b82f6;">
            <div class="acct1015_metric_label">Net Profit</div>
            <div class="acct1015_metric_value" style="color: <?= $netProfit['ytd'] >= 0 ? '#1e3a8a' : '#dc2626' ?>;">
                $<?= number_format($netProfit['ytd'], 0) ?>
            </div>
            <div class="acct1015_metric_breakdown">
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">MTD</div>
                    <div class="acct1015_metric_item_value">$<?= number_format($netProfit['mtd'], 0) ?></div>
                </div>
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">QTD</div>
                    <div class="acct1015_metric_item_value">$<?= number_format($netProfit['qtd'], 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Margin -->
        <div class="acct1015_metric_card" style="--card-accent: #9ca3af;">
            <div class="acct1015_metric_label">Profit Margin</div>
            <div class="acct1015_metric_value" style="color: #6b7280;">
                <?= $profitMargin['ytd'] ?>%
            </div>
            <div class="acct1015_metric_breakdown">
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">MTD</div>
                    <div class="acct1015_metric_item_value"><?= $profitMargin['mtd'] ?>%</div>
                </div>
                <div class="acct1015_metric_item">
                    <div class="acct1015_metric_item_label">QTD</div>
                    <div class="acct1015_metric_item_value"><?= $profitMargin['qtd'] ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Boxes -->
    <div class="acct1015_info_grid">
        <div class="acct1015_info_box" style="--box-color: #3b82f6;">
            <div class="acct1015_info_label">Outstanding Invoices</div>
            <div class="acct1015_info_value"><?= $outstanding['count'] ?? 0 ?></div>
            <div class="acct1015_info_subtext">$<?= number_format($outstanding['amount'] ?? 0, 2) ?> receivable</div>
        </div>
        <div class="acct1015_info_box" style="--box-color: #d97706;">
            <div class="acct1015_info_label">Unpaid Expenses</div>
            <div class="acct1015_info_value"><?= $unpaid['count'] ?? 0 ?></div>
            <div class="acct1015_info_subtext">$<?= number_format($unpaid['amount'] ?? 0, 2) ?> payable</div>
        </div>
        <div class="acct1015_info_box" style="--box-color: #10b981;">
            <div class="acct1015_info_label">Commission Liability</div>
            <div class="acct1015_info_value">$<?= number_format($commissionLiability['total'] ?? 0, 0) ?></div>
            <div class="acct1015_info_subtext">Pending payment</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2rem;">
        <div class="acct1015_chart_container">
            <div class="acct1015_chart_title">
                <i class="bi bi-graph-up" style="color: #10b981;"></i>
                Revenue vs Expenses Trend
            </div>
            <canvas id="revenueTrendChart" height="80"></canvas>
        </div>
        
        <div class="acct1015_section">
            <div class="acct1015_section_title" style="font-size: 1.125rem; margin-bottom: 1.5rem;">Quick Actions</div>
            <div class="acct1015_quick_actions">
                <a href="add-expense.php" class="acct1015_quick_btn">
                    <i class="bi bi-receipt-cutoff"></i>
                    <span>Log Expense</span>
                </a>
                <a href="suppliers-list.php" class="acct1015_quick_btn">
                    <i class="bi bi-building"></i>
                    <span>Suppliers</span>
                </a>
                <a href="journal.php" class="acct1015_quick_btn">
                    <i class="bi bi-journal-text"></i>
                    <span>Journal Entries</span>
                </a>
                <a href="ledger.php" class="acct1015_quick_btn">
                    <i class="bi bi-list-ul"></i>
                    <span>Chart of Accounts</span>
                </a>
                <a href="expense-approvals.php" class="acct1015_quick_btn">
                    <i class="bi bi-check2-square"></i>
                    <span>Approve Expenses</span>
                </a>
                <a href="taxes.php" class="acct1015_quick_btn">
                    <i class="bi bi-calculator"></i>
                    <span>Tax Management</span>
                </a>
                <a href="1099-management.php" class="acct1015_quick_btn">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>1099 Forms</span>
                </a>
                <a href="report-designer.php" class="acct1015_quick_btn">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Custom Reports</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Tools Section -->
    <div class="acct1015_section">
        <div class="acct1015_section_header">
            <h2 class="acct1015_section_title">Accounting Tools</h2>
        </div>
        <div class="acct1015_tools_grid">
            <a href="sync-controller.php" class="acct1015_tool_card">
                <i class="bi bi-arrow-repeat acct1015_tool_icon" style="color: #3b82f6;"></i>
                <div class="acct1015_tool_title">Data Sync</div>
                <p class="acct1015_tool_desc">Migrate historical transactions</p>
            </a>
            
            <a href="reconciliation.php" class="acct1015_tool_card">
                <i class="bi bi-check2-square acct1015_tool_icon" style="color: #10b981;"></i>
                <div class="acct1015_tool_title">Reconciliation</div>
                <p class="acct1015_tool_desc">Validate data accuracy</p>
            </a>
            
            <a href="expense-allocations.php" class="acct1015_tool_card">
                <i class="bi bi-diagram-3 acct1015_tool_icon" style="color: #6b7280;"></i>
                <div class="acct1015_tool_title">Allocations</div>
                <p class="acct1015_tool_desc">Cost distribution</p>
            </a>
            
            <a href="incinerator.php" class="acct1015_tool_card">
                <i class="bi bi-fire acct1015_tool_icon" style="color: #d97706;"></i>
                <div class="acct1015_tool_title">Incinerator</div>
                <p class="acct1015_tool_desc">Clean test data</p>
            </a>
        </div>
    </div>

    <!-- Supplier Management Section -->
    <div class="acct1015_section" style="margin-top: 2.5rem;">
        <div class="acct1015_section_header">
            <h2 class="acct1015_section_title">
                <i class="bi bi-building"></i> Supplier Management
            </h2>
            <div style="display: flex; gap: 0.75rem;">
                <a href="suppliers.php" class="acct1015_btn_primary">
                    <i class="bi bi-plus-circle"></i> Add Supplier
                </a>
                <a href="suppliers-list.php" class="acct1015_btn_secondary">
                    <i class="bi bi-list-ul"></i> View All
                </a>
            </div>
        </div>

        <!-- Supplier Stats Mini Widget -->
        <div class="acct1016_stats_widget">
            <div class="acct1016_stat_mini">
                <div class="acct1016_stat_mini_value" id="totalSuppliersCount">0</div>
                <div class="acct1016_stat_mini_label">Active Suppliers</div>
            </div>
            <div class="acct1016_stat_mini">
                <div class="acct1016_stat_mini_value" id="pendingMatchesCount">0</div>
                <div class="acct1016_stat_mini_label">Pending Matches</div>
            </div>
            <div class="acct1016_stat_mini">
                <div class="acct1016_stat_mini_value" id="totalCredentialsCount">0</div>
                <div class="acct1016_stat_mini_label">Stored Credentials</div>
            </div>
        </div>

        <!-- Pending Matches Container -->
        <div id="pendingMatchesContainer" style="margin-top: 1.5rem;">
            <!-- Dynamically populated by JavaScript -->
        </div>
    </div>
</div>
</div>

<!-- Include Supplier Management JavaScript for dashboard stats -->
<script src="assets/supplier-management.js"></script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
