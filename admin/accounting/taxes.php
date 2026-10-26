<?php
$SEO = [
    'title' => 'Tax Management — Product Accounting',
    'description' => 'Configure taxes, calculate liabilities, and track filings',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Ensure user has accountant or admin role
$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}

$pdo = db();

// Initialize variables
$taxConfigs = [];
$taxCalculations = [];
$migrationNeeded = false;

// Get current year and quarter
$currentYear = date('Y');
$currentQuarter = ceil(date('n') / 3);

try {
    $testStmt = $pdo->query("SELECT COUNT(*) FROM tax_configuration LIMIT 1");
    if ($testStmt) {
        $taxConfigStmt = $pdo->query("
            SELECT * FROM tax_configuration
            WHERE is_active = 1
            ORDER BY jurisdiction, tax_type
        ");
        $taxConfigs = $taxConfigStmt->fetchAll(PDO::FETCH_ASSOC);

        $taxCalcsStmt = $pdo->query("
            SELECT tc.*,
                   calculator.first_name as calc_first_name, calculator.last_name as calc_last_name
            FROM tax_calculations tc
            LEFT JOIN users calculator ON calculator.id = tc.calculated_by
            WHERE YEAR(tc.period_end) = YEAR(CURDATE())
            ORDER BY tc.period_end DESC
            LIMIT 10
        ");
        $taxCalculations = $taxCalcsStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $migrationNeeded = true;
    }
} catch (Exception $e) {
    error_log("Taxes page error: " . $e->getMessage());
    $migrationNeeded = true;
}

// Calculate estimated current quarter taxes
$quarterStart = date('Y-m-01', strtotime("first day of $currentYear-" . (($currentQuarter - 1) * 3 + 1) . "-01"));
$quarterEnd = date('Y-m-t', strtotime("last day of $currentYear-" . ($currentQuarter * 3) . "-01"));

$quarterRevenueStmt = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) as revenue
    FROM orders
    WHERE created_at BETWEEN ? AND ?
    AND status != 'cancelled'
    AND payment_status = 'paid'
");
$quarterRevenueStmt->execute([$quarterStart, $quarterEnd]);
$quarterRevenue = $quarterRevenueStmt->fetch(PDO::FETCH_ASSOC);

$quarterExpenseStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as expenses
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    AND payment_status = 'paid'
");
$quarterExpenseStmt->execute([$quarterStart, $quarterEnd]);
$quarterExpenses = $quarterExpenseStmt->fetch(PDO::FETCH_ASSOC);

$quarterProfit = ($quarterRevenue['revenue'] ?? 0) - ($quarterExpenses['expenses'] ?? 0);
$estimatedIncomeTax = $quarterProfit * 0.21;

// Load CSS inline
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Tax Management']]); ?>
        <?php render_accounting_secondary_nav('taxes.php'); ?>
    <?php endif; ?>
    
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">Tax Management</h1>
            <p class="acct1015_page_subtitle">Configure tax rates, calculate liabilities, and track filings</p>
        </div>
        <button class="acct1015_btn_primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
            <i class="bi bi-cash-coin"></i> Record Tax Payment
        </button>
    </div>

    <?php if ($migrationNeeded): ?>
    <div class="acct1015_card" style="background: #fffbeb; border-color: #fbbf24;">
        <h5 style="color: #d97706;"><i class="bi bi-exclamation-triangle"></i> Migration Required</h5>
        <p style="color: #92400e;">Run the database migration to enable tax tracking.</p>
    </div>
    <?php else: ?>

    <!-- Quarterly Tax Liability - Hero Card -->
    <div class="acct1015_card" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 3rem;">
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 3rem; align-items: center;">
            <div>
                <div style="font-size: 0.875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.9; margin-bottom: 0.5rem;">
                    Q<?= $currentQuarter ?> <?= $currentYear ?> Estimated Tax Liability
                </div>
                <div style="font-size: 4rem; font-weight: 700; line-height: 1; margin-bottom: 1rem;">
                    $<?= number_format(max(0, $estimatedIncomeTax), 0) ?>
                </div>
                <div style="font-size: 1rem; opacity: 0.85;">Based on 21% corporate tax rate</div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem; text-align: center;">
                <div style="background: rgba(255,255,255,0.15); padding: 1.5rem; border-radius: 0.75rem;">
                    <div style="font-size: 0.75rem; opacity: 0.9; margin-bottom: 0.5rem;">Revenue</div>
                    <div style="font-size: 1.5rem; font-weight: 700;">$<?= number_format($quarterRevenue['revenue'] ?? 0, 0) ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.15); padding: 1.5rem; border-radius: 0.75rem;">
                    <div style="font-size: 0.75rem; opacity: 0.9; margin-bottom: 0.5rem;">Expenses</div>
                    <div style="font-size: 1.5rem; font-weight: 700;">$<?= number_format($quarterExpenses['expenses'] ?? 0, 0) ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.15); padding: 1.5rem; border-radius: 0.75rem;">
                    <div style="font-size: 0.75rem; opacity: 0.9; margin-bottom: 0.5rem;">Net Profit</div>
                    <div style="font-size: 1.5rem; font-weight: 700;">$<?= number_format($quarterProfit, 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tax Configuration -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h2 class="acct1015_card_title">
                <i class="bi bi-gear-fill" style="color: #6b7280;"></i>
                Tax Configuration
            </h2>
            <button class="acct1015_btn_primary" onclick="alert('Tax configuration UI coming soon!')">
                <i class="bi bi-plus-circle"></i> Add Tax Rule
            </button>
        </div>
        
        <?php if (count($taxConfigs) > 0): ?>
            <?php foreach ($taxConfigs as $config): ?>
                <div style="padding: 1.5rem; margin-bottom: 0.75rem; background: #f9fafb; border-radius: 0.75rem; border-left: 4px solid #d97706;">
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 2rem; align-items: center;">
                        <div>
                            <h6 style="margin: 0 0 0.25rem 0; font-weight: 600; color: #111827;">
                                <i class="bi bi-geo-alt" style="color: #d97706;"></i> <?= htmlspecialchars($config['jurisdiction']) ?>
                            </h6>
                            <div style="font-size: 0.8125rem; color: #6b7280;">
                                Effective <?= date('M j, Y', strtotime($config['effective_date'])) ?>
                            </div>
                        </div>
                        <div class="text-center">
                            <span class="acct1015_badge acct1015_badge_info"><?= htmlspecialchars($config['tax_type']) ?></span>
                        </div>
                        <div class="text-center">
                            <div style="font-size: 1.75rem; font-weight: 700; color: #d97706;">
                                <?= number_format($config['tax_rate'] * 100, 2) ?>%
                            </div>
                            <div style="font-size: 0.75rem; color: #6b7280;">Rate</div>
                        </div>
                        <div class="text-center">
                            <span class="acct1015_badge acct1015_badge_neutral">
                                <?= ucfirst($config['filing_frequency'] ?? 'N/A') ?>
                            </span>
                        </div>
                        <div>
                            <span class="acct1015_badge acct1015_badge_success" style="padding: 0.5rem 1rem;">
                                <i class="bi bi-check-circle"></i> Active
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="acct1015_empty">
            <i class="bi bi-info-circle acct1015_empty_icon"></i>
            <div class="acct1015_empty_title">No tax configuration</div>
            <p class="acct1015_empty_text">Click "Add Tax Rule" to configure tax rates</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tax Calculations History -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h2 class="acct1015_card_title">
                <i class="bi bi-clock-history" style="color: #3b82f6;"></i>
                Tax Calculations History
            </h2>
        </div>
        
        <?php if (count($taxCalculations) > 0): ?>
            <?php foreach ($taxCalculations as $calc): ?>
                <?php
                $statusColors = [
                    'not_filed' => 'acct1015_badge_warning',
                    'filed' => 'acct1015_badge_info',
                    'paid' => 'acct1015_badge_success'
                ];
                $borderColors = [
                    'not_filed' => '#d97706',
                    'filed' => '#10b981',
                    'paid' => '#3b82f6'
                ];
                ?>
                <div style="padding: 1.5rem; margin-bottom: 0.75rem; background: #f9fafb; border-radius: 0.75rem; border-left: 4px solid <?= $borderColors[$calc['filing_status']] ?? '#6b7280' ?>;">
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto; gap: 1.5rem; align-items: center;">
                        <div>
                            <strong style="color: #111827; display: block; margin-bottom: 0.25rem;"><?= htmlspecialchars($calc['jurisdiction']) ?></strong>
                            <span style="font-size: 0.8125rem; color: #6b7280;">
                                <?= date('M j', strtotime($calc['period_start'])) ?> - <?= date('M j, \'y', strtotime($calc['period_end'])) ?>
                            </span>
                        </div>
                        <div class="text-center">
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Taxable</div>
                            <div style="font-weight: 600;">$<?= number_format($calc['taxable_amount'], 0) ?></div>
                        </div>
                        <div class="text-center">
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Rate</div>
                            <div style="font-weight: 600;"><?= number_format($calc['tax_rate'] * 100, 2) ?>%</div>
                        </div>
                        <div class="text-center">
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Tax Owed</div>
                            <div style="font-weight: 700; color: #111827;">$<?= number_format($calc['tax_owed'], 2) ?></div>
                        </div>
                        <div class="text-center">
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Balance Due</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: <?= $calc['balance_due'] > 0 ? '#dc2626' : '#10b981' ?>;">
                                $<?= number_format($calc['balance_due'], 2) ?>
                            </div>
                        </div>
                        <div>
                            <span class="acct1015_badge <?= $statusColors[$calc['filing_status']] ?? 'acct1015_badge_neutral' ?>" style="padding: 0.5rem 1rem;">
                                <?= str_replace('_', ' ', ucwords($calc['filing_status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="acct1015_empty">
            <i class="bi bi-inbox acct1015_empty_icon"></i>
            <div class="acct1015_empty_title">No tax calculations yet</div>
            <p class="acct1015_empty_text">Tax calculations will appear after processing</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tax Liability Chart -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h2 class="acct1015_card_title">
                <i class="bi bi-graph-up" style="color: #d97706;"></i>
                Quarterly Tax Liability Trend
            </h2>
        </div>
        <canvas id="taxLiabilityChart" height="80"></canvas>
    </div>

    <?php endif; ?>
</div>

<!-- Record Tax Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 1rem; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <h5 class="modal-title" style="font-weight: 600; color: #111827;">
                    <i class="bi bi-cash-coin" style="color: #10b981;"></i> Record Tax Payment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <form id="taxPaymentForm">
                    <div style="margin-bottom: 1.5rem;">
                        <label class="acct1015_form_label">Tax Calculation</label>
                        <select class="acct1015_form_input" id="taxCalcId" required>
                            <option value="">Select...</option>
                            <?php foreach ($taxCalculations as $calc): ?>
                                <?php if ($calc['balance_due'] > 0): ?>
                                    <option value="<?= $calc['id'] ?>" data-balance="<?= $calc['balance_due'] ?>">
                                        <?= htmlspecialchars($calc['jurisdiction']) ?> - <?= htmlspecialchars($calc['tax_type']) ?> - $<?= number_format($calc['balance_due'], 2) ?> due
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label class="acct1015_form_label">Payment Amount</label>
                        <input type="number" class="acct1015_form_input" id="paymentAmount" step="0.01" min="0" required>
                        <small style="color: #6b7280; font-size: 0.8125rem;">Balance due: <span id="balanceDueDisplay">$0.00</span></small>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label class="acct1015_form_label">Payment Date</label>
                        <input type="date" class="acct1015_form_input" id="paymentDate" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div>
                        <label class="acct1015_form_label">Confirmation Number (Optional)</label>
                        <input type="text" class="acct1015_form_input" id="confirmationNumber">
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <button type="button" class="acct1015_btn_secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="acct1015_btn_primary" onclick="recordTaxPayment()">
                    <i class="bi bi-save"></i> Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php 
$chartJsFile = __DIR__ . '/assets/accounting-charts.js';
if (file_exists($chartJsFile)) {
    echo file_get_contents($chartJsFile);
}
?>
</script>
<script>
let paymentModal;

document.addEventListener('DOMContentLoaded', function() {
    paymentModal = new bootstrap.Modal(document.getElementById('recordPaymentModal'));
    loadTaxChartData();
    
    document.getElementById('taxCalcId')?.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const balance = selected.getAttribute('data-balance') || 0;
        document.getElementById('balanceDueDisplay').textContent = '$' + parseFloat(balance).toFixed(2);
        document.getElementById('paymentAmount').value = balance;
    });
});

function loadTaxChartData() {
    try {
        const quarters = ['Q1', 'Q2', 'Q3', 'Q4'];
        const estimated = [<?= $estimatedIncomeTax ?>, 0, 0, 0];
        const paid = [0, 0, 0, 0];
        
        createTaxLiabilityChart('taxLiabilityChart', {
            quarters: quarters,
            estimated: estimated,
            paid: paid
        });
    } catch (error) {
        console.error('Failed to load tax chart:', error);
    }
}

async function recordTaxPayment() {
    const taxCalcId = document.getElementById('taxCalcId').value;
    const amount = document.getElementById('paymentAmount').value;
    const paymentDate = document.getElementById('paymentDate').value;
    const confirmationNumber = document.getElementById('confirmationNumber').value;
    
    if (!taxCalcId || !amount || !paymentDate) {
        alert('Please fill in all required fields');
        return;
    }
    
    try {
        const response = await fetch('/api/accounting/tax-api.php?action=record_payment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tax_calculation_id: taxCalcId,
                payment_amount: amount,
                payment_date: paymentDate,
                confirmation_number: confirmationNumber
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            paymentModal.hide();
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to record payment: ' + error.message);
    }
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
