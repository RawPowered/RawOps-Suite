<?php
$SEO = [
    'title' => 'Finances — Sales Agent',
    'description' => 'View your commissions, invoices, and tax information.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_login_or_redirect('/login.php');

$uid = current_user_id();
$pdo = db();

// Verify user is sales agent
$userRoles = user_roles($uid);
if (!in_array('sales_agent', $userRoles ?? []) && !in_array('admin', $userRoles ?? [])) {
    flash_set('error', 'Sales agent access required.');
    redirect('/');
}

// Get user info
$stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

// Get current year and tax year
$currentYear = date('Y');
$taxYear = isset($_GET['year']) ? intval($_GET['year']) : $currentYear;

// Get commission summary for current tax year
try {
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_commissions,
            SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END) as pending_amount,
            SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) as paid_amount,
            SUM(amount) as total_earned,
            SUM(CASE WHEN YEAR(created_at) = ? THEN amount ELSE 0 END) as year_earned
        FROM commissions 
        WHERE user_id = ? AND commission_type = "sales_agent"
    ');
    $stmt->execute([$taxYear, $uid]);
    $commissionSummary = $stmt->fetch();
    
    // Get monthly breakdown for tax year
    $stmt = $pdo->prepare('
        SELECT 
            DATE_FORMAT(created_at, "%Y-%m") as month,
            SUM(amount) as monthly_total,
            COUNT(*) as commission_count
        FROM commissions
        WHERE user_id = ? 
        AND commission_type = "sales_agent"
        AND YEAR(created_at) = ?
        AND status = "paid"
        GROUP BY DATE_FORMAT(created_at, "%Y-%m")
        ORDER BY month ASC
    ');
    $stmt->execute([$uid, $taxYear]);
    $monthlyBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent invoices
    $stmt = $pdo->prepare('
        SELECT 
            o.*,
            wa.business_name,
            c.amount as commission_amount
        FROM orders o
        LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        LEFT JOIN commissions c ON c.order_id = o.id AND c.user_id = ? AND c.commission_type = "sales_agent"
        WHERE o.sales_agent_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ');
    $stmt->execute([$uid, $uid]);
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get loan balances
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_loans,
            SUM(remaining_balance) as total_balance
        FROM sales_agent_loans
        WHERE agent_id = ? AND loan_status IN ("active", "pending")
    ');
    $stmt->execute([$uid]);
    $loanSummary = $stmt->fetch();
    
    // Get active 1099 form
    $stmt = $pdo->prepare('
        SELECT * FROM form_1099_documents 
        WHERE tax_year = ? AND is_active = 1 
        LIMIT 1
    ');
    $stmt->execute([$taxYear]);
    $form1099 = $stmt->fetch();
    
} catch (Throwable $e) {
    error_log('Finances page error: ' . $e->getMessage());
    $commissionSummary = ['total_commissions' => 0, 'pending_amount' => 0, 'paid_amount' => 0, 'total_earned' => 0, 'year_earned' => 0];
    $monthlyBreakdown = [];
    $recentInvoices = [];
    $loanSummary = ['total_loans' => 0, 'total_balance' => 0];
    $form1099 = null;
}
?>

<style>
.finances-page {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px;
}

.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
}

.page-subtitle {
    font-size: 16px;
    color: #6b7280;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-bottom: 40px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.card-label {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.card-value {
    font-size: 36px;
    font-weight: 700;
    color: #1f2937;
}

.card-value.green {
    color: #10b981;
}

.card-value.yellow {
    color: #f59e0b;
}

.card-value.red {
    color: #ef4444;
}

.section {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    margin-bottom: 32px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.section-title {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-block;
}

.btn-primary {
    background: #10b981;
    color: white;
}

.btn-primary:hover {
    background: #059669;
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.year-selector {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 24px;
}

.year-btn {
    padding: 8px 16px;
    border-radius: 6px;
    background: #f3f4f6;
    color: #374151;
    font-weight: 600;
    font-size: 14px;
    border: 2px solid transparent;
    cursor: pointer;
}

.year-btn.active {
    background: #10b981;
    color: white;
    border-color: #059669;
}

.monthly-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
}

.month-card {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.month-name {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 8px;
}

.month-amount {
    font-size: 20px;
    font-weight: 700;
    color: #10b981;
}

.month-count {
    font-size: 12px;
    color: #9ca3af;
    margin-top: 4px;
}

.invoices-table {
    width: 100%;
    border-collapse: collapse;
}

.invoices-table th {
    text-align: left;
    padding: 12px;
    background: #f9fafb;
    color: #6b7280;
    font-weight: 600;
    font-size: 14px;
    border-bottom: 2px solid #e5e7eb;
}

.invoices-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.tax-resources {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 24px;
}

.tax-resources h4 {
    font-size: 18px;
    font-weight: 700;
    color: #1e40af;
    margin-top: 0;
    margin-bottom: 16px;
}

.tax-resources ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tax-resources li {
    padding: 8px 0;
    border-bottom: 1px solid #dbeafe;
}

.tax-resources li:last-child {
    border-bottom: none;
}

.tax-resources a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
}

.tax-resources a:hover {
    text-decoration: underline;
}

.info-box {
    background: #fef3c7;
    border: 1px solid #fbbf24;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
}

.info-box p {
    margin: 0;
    color: #92400e;
    font-size: 14px;
    line-height: 1.6;
}

.collapsible-header {
    cursor: pointer;
    user-select: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.collapsible-header:hover {
    color: #10b981;
}

.collapsible-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.collapsible-content.open {
    max-height: 1000px;
}
</style>

<div class="finances-page">
    <?php 
    $currentPage = 'finances.php';
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <div class="page-header">
        <h1 class="page-title">💰 Finances</h1>
        <p class="page-subtitle">View your commissions, invoices, and tax information</p>
    </div>
    
    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="card-label">Total Earned (All Time)</div>
            <div class="card-value green">$<?php echo number_format($commissionSummary['total_earned'] ?? 0, 2); ?></div>
        </div>
        
        <div class="summary-card">
            <div class="card-label">Pending Commissions</div>
            <div class="card-value yellow">$<?php echo number_format($commissionSummary['pending_amount'] ?? 0, 2); ?></div>
        </div>
        
        <div class="summary-card">
            <div class="card-label">Paid Out</div>
            <div class="card-value">$<?php echo number_format($commissionSummary['paid_amount'] ?? 0, 2); ?></div>
        </div>
        
        <?php if ($loanSummary && $loanSummary['total_balance'] > 0): ?>
        <div class="summary-card">
            <div class="card-label">Loan Balance</div>
            <div class="card-value red">$<?php echo number_format($loanSummary['total_balance'] ?? 0, 2); ?></div>
            <a href="my-books.php" style="font-size: 14px; color: #2563eb; margin-top: 8px; display: inline-block;">View Books →</a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 1099 Download Section -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">📋 Tax Year <?php echo $taxYear; ?> - Form 1099-MISC</h2>
        </div>
        
        <div class="year-selector">
            <span style="font-weight: 600; color: #6b7280;">Select Year:</span>
            <?php for ($year = $currentYear; $year >= 2020; $year--): ?>
            <a href="?year=<?php echo $year; ?>" class="year-btn<?php echo $year == $taxYear ? ' active' : ''; ?>">
                <?php echo $year; ?>
            </a>
            <?php endfor; ?>
        </div>
        
        <div class="info-box">
            <p>
                <strong>📊 Your Total Earnings for <?php echo $taxYear; ?>:</strong> 
                $<?php echo number_format($commissionSummary['year_earned'] ?? 0, 2); ?>
            </p>
        </div>
        
        <?php if ($form1099): ?>
        <div style="display: flex; gap: 16px; align-items: center; margin-bottom: 24px;">
            <a href="<?php echo htmlspecialchars($form1099['file_path']); ?>" target="_blank" class="btn btn-primary" download>
                📥 Download 1099-MISC Form (<?php echo $taxYear; ?>)
            </a>
            <p style="margin: 0; color: #6b7280; font-size: 14px;">
                Pre-filled with company information. Add your earnings data from the breakdown below.
            </p>
        </div>
        <?php else: ?>
        <p style="color: #6b7280;">No 1099 form available for tax year <?php echo $taxYear; ?> yet. Check back later or contact administration.</p>
        <?php endif; ?>
        
        <!-- Monthly Breakdown (Collapsible) -->
        <div style="margin-top: 24px;">
            <h3 class="collapsible-header" onclick="toggleCollapsible('monthly-breakdown')">
                <span>📅 Monthly Earnings Breakdown</span>
                <span id="monthly-breakdown-toggle">▼</span>
            </h3>
            <div class="collapsible-content" id="monthly-breakdown">
                <?php if (empty($monthlyBreakdown)): ?>
                <p style="color: #6b7280; padding: 20px 0;">No earnings recorded for <?php echo $taxYear; ?>.</p>
                <?php else: ?>
                <div class="monthly-breakdown" style="margin-top: 16px;">
                    <?php 
                    $monthNames = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'May', '06' => 'Jun',
                                   '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'];
                    foreach ($monthlyBreakdown as $month): 
                        $monthNum = substr($month['month'], -2);
                    ?>
                    <div class="month-card">
                        <div class="month-name"><?php echo $monthNames[$monthNum] ?? $monthNum; ?> <?php echo $taxYear; ?></div>
                        <div class="month-amount">$<?php echo number_format($month['monthly_total'], 2); ?></div>
                        <div class="month-count"><?php echo $month['commission_count']; ?> commission<?php echo $month['commission_count'] != 1 ? 's' : ''; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Invoices -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">🧾 Recent Invoices</h2>
            <a href="invoices.php" class="btn btn-secondary">View All →</a>
        </div>
        
        <?php if (empty($recentInvoices)): ?>
        <p style="color: #6b7280; text-align: center; padding: 40px;">No invoices found.</p>
        <?php else: ?>
        <table class="invoices-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Commission</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentInvoices as $invoice): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($invoice['order_number']); ?></strong></td>
                    <td><?php echo date('M j, Y', strtotime($invoice['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($invoice['business_name'] ?? 'N/A'); ?></td>
                    <td>$<?php echo number_format($invoice['total'], 2); ?></td>
                    <td>$<?php echo number_format($invoice['commission_amount'] ?? 0, 2); ?></td>
                    <td><?php echo ucfirst($invoice['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- Tax Resources -->
    <div class="section">
        <h2 class="section-title">📚 Tax Resources & Information</h2>
        
        <div class="tax-resources">
            <h4>💡 Helpful Links</h4>
            <ul>
                <li>
                    <a href="https://www.irs.gov/newsroom/irs-releases-tax-inflation-adjustments-for-tax-year-2026-including-amendments-from-the-one-big-beautiful-bill" target="_blank">
                        📊 2026 Tax Year Updates & Standard Deduction Changes
                    </a>
                </li>
                <li>
                    <a href="https://www.irs.gov/forms-pubs/about-form-1099-misc" target="_blank">
                        📄 IRS Form 1099-MISC Information
                    </a>
                </li>
                <li>
                    <a href="https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center" target="_blank">
                        💼 Self-Employed Tax Center
                    </a>
                </li>
            </ul>
        </div>
        
        <div style="margin-top: 24px; padding: 16px; background: #f9fafb; border-radius: 8px;">
            <h4 style="font-size: 16px; font-weight: 700; margin-top: 0;">🎯 2026 Tax Year Quick Reference</h4>
            <p style="font-size: 14px; color: #6b7280; margin: 0;">
                <strong>Standard Deduction (Single):</strong> $16,100<br>
                <strong>Standard Deduction (Married Filing Jointly):</strong> $32,200<br>
                <strong>Standard Deduction (Head of Household):</strong> $24,150
            </p>
        </div>
    </div>
</div>

<script>
function toggleCollapsible(id) {
    const content = document.getElementById(id);
    const toggle = document.getElementById(id + '-toggle');
    
    if (content.classList.contains('open')) {
        content.classList.remove('open');
        toggle.textContent = '▼';
    } else {
        content.classList.add('open');
        toggle.textContent = '▲';
    }
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




