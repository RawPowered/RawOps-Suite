<?php
$SEO = [
    'title' => 'My Financials — Product Sales',
    'description' => 'View your earnings, commissions, and tax projections',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Wrap in output buffering to catch errors
ob_start();

// Ensure user is a sales agent
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

$roles = get_user_roles($userId);
if (!in_array('sales_agent', $roles)) {
    flash_set('error', 'Access denied. Sales agents only.');
    redirect('/');
}

$pdo = db();

// Get year from request
$year = intval($_GET['year'] ?? date('Y'));

// Get YTD earnings
$ytdStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as commission_count,
        COALESCE(SUM(amount), 0) as total_commissions
    FROM commissions
    WHERE user_id = ?
    AND YEAR(created_at) = ?
");
$ytdStmt->execute([$userId, $year]);
$ytd = $ytdStmt->fetch(PDO::FETCH_ASSOC);

// Get quarterly breakdown
$quarterlyStmt = $pdo->prepare("
    SELECT 
        QUARTER(created_at) as quarter,
        COUNT(*) as commission_count,
        COALESCE(SUM(amount), 0) as total_commissions
    FROM commissions
    WHERE user_id = ?
    AND YEAR(created_at) = ?
    GROUP BY QUARTER(created_at)
    ORDER BY quarter ASC
");
$quarterlyStmt->execute([$userId, $year]);
$quarterly = $quarterlyStmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly trend
$monthlyStmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as commission_count,
        COALESCE(SUM(amount), 0) as total_commissions
    FROM commissions
    WHERE user_id = ?
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
");
$monthlyStmt->execute([$userId]);
$monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

// Get expense deductions (if table exists)
$expenses = ['expense_count' => 0, 'total_expenses' => 0];
try {
    $expensesStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as expense_count,
            COALESCE(SUM(amount), 0) as total_expenses
        FROM agent_expense_charges
        WHERE agent_id = ?
        AND YEAR(expense_date) = ?
    ");
    $expensesStmt->execute([$userId, $year]);
    $expenses = $expensesStmt->fetch(PDO::FETCH_ASSOC) ?: $expenses;
} catch (Exception $e) {
    // Table doesn't exist yet - use defaults
    error_log("Agent expense charges query failed: " . $e->getMessage());
}

// Calculate net income
$netIncome = $ytd['total_commissions'] - ($expenses['total_expenses'] ?? 0);

// Tax projections
$selfEmploymentTax = $netIncome * 0.153;
$estimatedFederalTax = ($netIncome - ($selfEmploymentTax / 2)) * 0.22;
$totalTaxLiability = $selfEmploymentTax + $estimatedFederalTax;
$netAfterTax = $netIncome - $totalTaxLiability;

// Get agent info
$agentStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$agentStmt->execute([$userId]);
$agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

$taxInfoComplete = !empty($agent['tax_id_last4']) && !empty($agent['tax_address_line1']);

// Load CSS inline
$cssFile = __DIR__ . '/../../admin/accounting/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>

<div class="acct1015_container">
    <?php if (function_exists('render_agent_financial_nav')): ?>
        <?php render_agent_financial_nav('my-financials.php'); ?>
    <?php endif; ?>
    
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">My Financials</h1>
            <p class="acct1015_page_subtitle">Your earnings, tax projections, and financial overview for <?= $year ?></p>
        </div>
        <select class="acct1015_form_input" style="width: 140px;" onchange="window.location.href='?year='+this.value">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <?php if (!$taxInfoComplete): ?>
    <div class="acct1015_card" style="background: #fffbeb; border-color: #fbbf24; margin-bottom: 2rem;">
        <div style="display: flex; gap: 1.5rem; align-items: center;">
            <i class="bi bi-exclamation-triangle" style="font-size: 2.5rem; color: #d97706;"></i>
            <div style="flex: 1;">
                <h5 style="color: #92400e; margin-bottom: 0.5rem;">Complete Your Tax Information</h5>
                <p style="color: #78350f; margin-bottom: 1rem;">To receive your 1099 form, please complete your tax information.</p>
                <a href="my-1099.php#update-info" class="acct1015_btn_primary">
                    <i class="bi bi-pencil"></i> Update Tax Info
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Key Metrics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
        <div class="acct1015_stat_card" style="--stat-color: #10b981;">
            <div class="acct1015_stat_label">Gross Income</div>
            <div class="acct1015_stat_value" style="color: #047857;">$<?= number_format($ytd['total_commissions'], 2) ?></div>
            <div class="acct1015_stat_subtext"><?= number_format($ytd['commission_count']) ?> commissions</div>
        </div>
        
        <div class="acct1015_stat_card" style="--stat-color: #d97706;">
            <div class="acct1015_stat_label">Deductions</div>
            <div class="acct1015_stat_value" style="color: #d97706;">$<?= number_format($expenses['total_expenses'] ?? 0, 2) ?></div>
            <div class="acct1015_stat_subtext"><?= number_format($expenses['expense_count'] ?? 0) ?> expenses</div>
        </div>
        
        <div class="acct1015_stat_card" style="--stat-color: #3b82f6;">
            <div class="acct1015_stat_label">Net Income</div>
            <div class="acct1015_stat_value" style="color: #1e3a8a;">$<?= number_format($netIncome, 2) ?></div>
            <div class="acct1015_stat_subtext">Before taxes</div>
        </div>
        
        <div class="acct1015_stat_card" style="--stat-color: #fbbf24;">
            <div class="acct1015_stat_label">Est. Tax Liability</div>
            <div class="acct1015_stat_value" style="color: #d97706;">$<?= number_format($totalTaxLiability, 2) ?></div>
            <div class="acct1015_stat_subtext">~<?= round(($totalTaxLiability / max($netIncome, 1)) * 100) ?>% effective rate</div>
        </div>
    </div>

    <!-- Quarterly Breakdown -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h2 class="acct1015_card_title">
                <i class="bi bi-calendar4-range" style="color: #3b82f6;"></i>
                Quarterly Breakdown
            </h2>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem;">
            <?php 
            $qColors = ['#3b82f6', '#10b981', '#d97706', '#6b7280'];
            for ($q = 1; $q <= 4; $q++): 
                $qData = array_filter($quarterly, fn($item) => $item['quarter'] == $q);
                $qData = !empty($qData) ? reset($qData) : ['commission_count' => 0, 'total_commissions' => 0];
            ?>
                <div style="text-align: center; padding: 1.5rem; background: #f9fafb; border-radius: 0.75rem;">
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">
                        Q<?= $q ?> <?= $year ?>
                    </div>
                    <div style="font-size: 2rem; font-weight: 700; color: <?= $qColors[$q-1] ?>; margin-bottom: 0.5rem;">
                        $<?= number_format($qData['total_commissions'], 0) ?>
                    </div>
                    <div style="font-size: 0.8125rem; color: #9ca3af;">
                        <?= number_format($qData['commission_count']) ?> commissions
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- 12-Month Trend -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h2 class="acct1015_card_title">
                <i class="bi bi-graph-up" style="color: #10b981;"></i>
                12-Month Commission Trend
            </h2>
        </div>
        <canvas id="monthlyTrendChart" height="80"></canvas>
    </div>

    <!-- Tax Projection -->
    <div class="acct1015_card" style="background: linear-gradient(135deg, #d97706 0%, #fbbf24 100%); color: white;">
        <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; opacity: 0.95;">Tax Projection Breakdown</h2>
        
        <div style="background: rgba(255,255,255,0.15); padding: 1rem; border-radius: 0.75rem; margin-bottom: 1.5rem; font-size: 0.8125rem;">
            <i class="bi bi-info-circle"></i> These are estimates. Consult with a tax professional for accurate calculations.
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem;">
            <div>
                <h6 style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 1rem; font-weight: 600;">Income Summary</h6>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                    <span style="opacity: 0.9;">Gross Income:</span>
                    <strong style="font-size: 1.125rem;">$<?= number_format($ytd['total_commissions'], 2) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                    <span style="opacity: 0.9;">Less Deductions:</span>
                    <strong style="font-size: 1.125rem;">($<?= number_format($expenses['total_expenses'] ?? 0, 2) ?>)</strong>
                </div>
                <div style="border-top: 2px solid rgba(255,255,255,0.3); padding-top: 0.75rem; margin-top: 0.75rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <strong>Net Income:</strong>
                        <strong style="font-size: 1.5rem;">$<?= number_format($netIncome, 2) ?></strong>
                    </div>
                </div>
            </div>
            
            <div>
                <h6 style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 1rem; font-weight: 600;">Estimated Taxes</h6>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                    <span style="opacity: 0.9;">Self-Employment (15.3%):</span>
                    <strong style="font-size: 1.125rem;">$<?= number_format($selfEmploymentTax, 2) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                    <span style="opacity: 0.9;">Federal Income (22%):</span>
                    <strong style="font-size: 1.125rem;">$<?= number_format($estimatedFederalTax, 2) ?></strong>
                </div>
                <div style="border-top: 2px solid rgba(255,255,255,0.3); padding-top: 0.75rem; margin-top: 0.75rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <strong>Total Tax:</strong>
                        <strong style="font-size: 1.5rem;">$<?= number_format($totalTaxLiability, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="background: rgba(255,255,255,0.2); padding: 1.5rem; border-radius: 0.75rem; margin-top: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 0.25rem;">Net After Tax</div>
                    <div style="font-size: 2.5rem; font-weight: 700;">$<?= number_format($netAfterTax, 2) ?></div>
                </div>
                <i class="bi bi-cash-stack" style="font-size: 4rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const monthlyData = <?= json_encode($monthly) ?>;
const labels = monthlyData.map(m => {
    const date = new Date(m.month + '-01');
    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
});
const amounts = monthlyData.map(m => parseFloat(m.total_commissions));

const ctx = document.getElementById('monthlyTrendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Commission Income',
            data: amounts,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointRadius: 0,
            pointHoverRadius: 7,
            pointBackgroundColor: '#10b981'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(17, 24, 39, 0.95)',
                padding: 12,
                callbacks: {
                    label: function(context) {
                        return '$' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f3f4f6' },
                ticks: {
                    color: '#6b7280',
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#6b7280' }
            }
        }
    }
});
</script>

<?php 
ob_end_flush();
require_once __DIR__ . '/../../required/footer.php'; 
?>
