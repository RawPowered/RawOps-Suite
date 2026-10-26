<?php
$SEO = [
    'title' => 'My Books — Sales Agent CRM',
    'description' => 'View your loan balances, repayment history, and commission deductions.',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/agent-loan-utils.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

$pdo = db();

// Check sales agent role
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isRealAdmin = current_admin_id() !== null;
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);
$hasFullAccess = $isAdminSimulation || ($isRealAdmin && $adminMode);

if (!$hasFullAccess) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try {
        $stmt->execute([$uid]);
        $hasRole = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $hasRole = false;
    }
    if (!$hasRole) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Get agent financial summary
$summary = [];
try {
    // Loans
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_loans,
            SUM(CASE WHEN status = "active" THEN balance_remaining ELSE 0 END) as total_balance,
            SUM(loan_amount) as total_borrowed,
            SUM(CASE WHEN status = "paid" THEN loan_amount ELSE 0 END) as total_repaid
        FROM sales_agent_loans
        WHERE agent_id = ?
    ');
    $stmt->execute([$uid]);
    $summary['loans'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Charges
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_charges,
            SUM(CASE WHEN charge_status = "pending" THEN charge_amount ELSE 0 END) as pending_charges
        FROM agent_expense_charges
        WHERE agent_id = ?
    ');
    $stmt->execute([$uid]);
    $summary['charges'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Commissions
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_commissions,
            SUM(amount) as gross_commissions,
            SUM(loan_repayment_deduction) as total_deductions,
            SUM(COALESCE(net_commission_after_loans, amount)) as net_commissions
        FROM commissions
        WHERE user_id = ? AND commission_type = "sales_agent"
    ');
    $stmt->execute([$uid]);
    $summary['commissions'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Throwable $e) {
    $summary = [
        'loans' => ['total_loans' => 0, 'total_balance' => 0, 'total_borrowed' => 0, 'total_repaid' => 0],
        'charges' => ['total_charges' => 0, 'pending_charges' => 0],
        'commissions' => ['total_commissions' => 0, 'gross_commissions' => 0, 'total_deductions' => 0, 'net_commissions' => 0]
    ];
}

// Get active loans
$activeLoans = get_agent_active_loans($pdo, $uid);

// Get repayment settings
$repaymentSettings = get_agent_repayment_settings($pdo, $uid, null);

// Get recent transactions
$stmt = $pdo->prepare('
    SELECT lrt.*, sal.loan_type, sal.loan_amount, o.order_number
    FROM loan_repayment_transactions lrt
    JOIN sales_agent_loans sal ON sal.id = lrt.loan_id
    LEFT JOIN orders o ON o.id = lrt.source_order_id
    WHERE lrt.agent_id = ?
    ORDER BY lrt.transaction_date DESC
    LIMIT 20
');
$stmt->execute([$uid]);
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent commission deductions
$stmt = $pdo->prepare('
    SELECT c.*, o.order_number, o.created_at as order_date
    FROM commissions c
    JOIN orders o ON o.id = c.order_id
    WHERE c.user_id = ? AND c.commission_type = "sales_agent" 
    AND c.loan_repayment_deduction > 0
    ORDER BY c.created_at DESC
    LIMIT 10
');
$stmt->execute([$uid]);
$commissionDeductions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <?php 
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <div class="page-header">
        <div>
            <h1 class="page-title">💰 My Books</h1>
            <div class="page-subtitle muted">View your loan balances, repayments, and commission deductions</div>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn secondary">← Back to CRM</a>
        </div>
    </div>

    <!-- Financial Summary -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px;">
        <div class="card" style="text-align: center; background: linear-gradient(135deg, #fee2e2, #fecaca);">
            <div style="font-size: 32px; font-weight: 700; color: #dc2626;">
                $<?php echo number_format($summary['loans']['total_balance'] ?? 0, 2); ?>
            </div>
            <div style="font-size: 12px; color: #991b1b; text-transform: uppercase; font-weight: 600;">
                Current Balance Owed
            </div>
        </div>
        
        <div class="card" style="text-align: center; background: linear-gradient(135deg, #dcfce7, #bbf7d0);">
            <div style="font-size: 32px; font-weight: 700; color: #059669;">
                $<?php echo number_format($summary['commissions']['net_commissions'] ?? 0, 2); ?>
            </div>
            <div style="font-size: 12px; color: #14532d; text-transform: uppercase; font-weight: 600;">
                Net Commissions Earned
            </div>
        </div>
        
        <div class="card" style="text-align: center; background: linear-gradient(135deg, #fef3c7, #fde68a);">
            <div style="font-size: 32px; font-weight: 700; color: #d97706;">
                $<?php echo number_format($summary['commissions']['total_deductions'] ?? 0, 2); ?>
            </div>
            <div style="font-size: 12px; color: #92400e; text-transform: uppercase; font-weight: 600;">
                Total Loan Deductions
            </div>
        </div>
        
        <div class="card" style="text-align: center;">
            <div style="font-size: 32px; font-weight: 700; color: #3b82f6;">
                <?php echo $summary['loans']['total_loans'] ?? 0; ?>
            </div>
            <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600;">
                Total Loans
            </div>
        </div>
    </div>

    <!-- Repayment Terms -->
    <?php if ($repaymentSettings): ?>
    <div class="card" style="background: #f0f9ff; border: 2px solid #3b82f6; margin-bottom: 24px;">
        <h2 style="margin-top: 0; color: #1e40af;">📋 Your Repayment Terms</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Method</div>
                <div style="font-weight: 600; color: #1f2937;">
                    <?php echo ucwords(str_replace('_', ' ', $repaymentSettings['repayment_method'])); ?>
                </div>
            </div>
            <?php if ($repaymentSettings['percentage_rate']): ?>
            <div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Deduction Rate</div>
                <div style="font-weight: 600; color: #1f2937;">
                    <?php echo number_format($repaymentSettings['percentage_rate'], 1); ?>% per commission
                </div>
            </div>
            <?php endif; ?>
            <div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Grace Period</div>
                <div style="font-weight: 600; color: #1f2937;">
                    <?php echo $repaymentSettings['grace_period_days']; ?> days
                </div>
            </div>
            <div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Auto-Deduct After</div>
                <div style="font-weight: 600; color: #1f2937;">
                    <?php echo $repaymentSettings['auto_deduct_after_days']; ?> days
                </div>
            </div>
        </div>
        <?php if ($repaymentSettings['notes']): ?>
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #bfdbfe;">
            <div style="font-size: 13px; color: #075985;">
                <strong>Notes:</strong> <?php echo htmlspecialchars($repaymentSettings['notes']); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Active Loans -->
    <?php if (!empty($activeLoans)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-top: 0; color: #dc2626;">📊 Active Loans</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 12px; text-align: left;">Type</th>
                    <th style="padding: 12px; text-align: left;">Loan Date</th>
                    <th style="padding: 12px; text-align: right;">Original Amount</th>
                    <th style="padding: 12px; text-align: right;">Balance</th>
                    <th style="padding: 12px; text-align: right;">% Repaid</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activeLoans as $loan): ?>
                <?php 
                $percentRepaid = ($loan['loan_amount'] > 0) 
                    ? (($loan['loan_amount'] - $loan['balance_remaining']) / $loan['loan_amount']) * 100 
                    : 0;
                ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px;">
                        <strong><?php echo ucwords(str_replace('_', ' ', $loan['loan_type'])); ?></strong>
                    </td>
                    <td style="padding: 12px;">
                        <?php echo date('M j, Y', strtotime($loan['loan_date'])); ?>
                    </td>
                    <td style="padding: 12px; text-align: right;">
                        $<?php echo number_format($loan['loan_amount'], 2); ?>
                    </td>
                    <td style="padding: 12px; text-align: right; font-weight: 600; color: #dc2626;">
                        $<?php echo number_format($loan['balance_remaining'], 2); ?>
                    </td>
                    <td style="padding: 12px; text-align: right;">
                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                            <div style="flex: 0 0 60px; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                <div style="height: 100%; background: #059669; width: <?php echo $percentRepaid; ?>%;"></div>
                            </div>
                            <span style="font-weight: 600; color: #059669;">
                                <?php echo number_format($percentRepaid, 0); ?>%
                            </span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card" style="text-align: center; padding: 40px; margin-bottom: 24px;">
        <div style="font-size: 48px; margin-bottom: 16px;">✅</div>
        <h3 style="color: #059669;">No Active Loans</h3>
        <p style="color: #6b7280;">You don't have any outstanding loan balances at this time.</p>
    </div>
    <?php endif; ?>

    <!-- Commission Deductions -->
    <?php if (!empty($commissionDeductions)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-top: 0;">📉 Recent Commission Deductions</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 12px; text-align: left;">Order</th>
                    <th style="padding: 12px; text-align: left;">Date</th>
                    <th style="padding: 12px; text-align: right;">Gross Commission</th>
                    <th style="padding: 12px; text-align: right;">Loan Deduction</th>
                    <th style="padding: 12px; text-align: right;">Net Commission</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commissionDeductions as $comm): ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px;">
                        <strong><?php echo htmlspecialchars($comm['order_number']); ?></strong>
                    </td>
                    <td style="padding: 12px;">
                        <?php echo date('M j, Y', strtotime($comm['order_date'])); ?>
                    </td>
                    <td style="padding: 12px; text-align: right;">
                        $<?php echo number_format($comm['amount'], 2); ?>
                    </td>
                    <td style="padding: 12px; text-align: right; color: #dc2626; font-weight: 600;">
                        -$<?php echo number_format($comm['loan_repayment_deduction'], 2); ?>
                    </td>
                    <td style="padding: 12px; text-align: right; font-weight: 600; color: #059669;">
                        $<?php echo number_format($comm['net_commission_after_loans'] ?? $comm['amount'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Repayment History -->
    <?php if (!empty($recentTransactions)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-top: 0;">💳 Repayment History</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 12px; text-align: left;">Date</th>
                    <th style="padding: 12px; text-align: left;">Loan Type</th>
                    <th style="padding: 12px; text-align: left;">Repayment Type</th>
                    <th style="padding: 12px; text-align: left;">Order</th>
                    <th style="padding: 12px; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentTransactions as $trans): ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px;">
                        <?php echo date('M j, Y g:ia', strtotime($trans['transaction_date'])); ?>
                    </td>
                    <td style="padding: 12px;">
                        <?php echo ucwords(str_replace('_', ' ', $trans['loan_type'])); ?>
                    </td>
                    <td style="padding: 12px;">
                        <span style="font-size: 11px; padding: 4px 8px; background: #f3f4f6; border-radius: 12px;">
                            <?php echo ucwords(str_replace('_', ' ', $trans['repayment_type'])); ?>
                        </span>
                    </td>
                    <td style="padding: 12px;">
                        <?php echo $trans['order_number'] ? htmlspecialchars($trans['order_number']) : '—'; ?>
                    </td>
                    <td style="padding: 12px; text-align: right; font-weight: 600; color: #059669;">
                        $<?php echo number_format($trans['amount'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Request Adjustment -->
    <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b;">
        <h2 style="margin-top: 0; color: #92400e;">📝 Need to Adjust Repayment Terms?</h2>
        <p style="color: #78350f; margin-bottom: 16px;">
            If you're experiencing financial difficulty or need to adjust your repayment schedule, 
            please contact your administrator. Include details about your current situation and 
            proposed new terms.
        </p>
        <form method="post" action="/admin/send-user-message.php" style="display: grid; gap: 12px;">
            <input type="hidden" name="to_admin" value="1">
            <input type="hidden" name="subject" value="Repayment Term Adjustment Request">
            <div>
                <label><strong>Proposed Changes</strong></label>
                <textarea name="message" class="input" rows="4" required 
                          placeholder="Explain your situation and what repayment terms you're requesting..."></textarea>
            </div>
            <div style="text-align: right;">
                <button type="submit" class="btn" style="background: #f59e0b;">
                    Submit Request to Admin
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




