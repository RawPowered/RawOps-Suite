<?php
$SEO = [
    'title' => 'Chart of Accounts — Product Accounting',
    'description' => 'Manage chart of accounts and ledger balances',
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
$accounts = [];
$balances = [];
$migrationNeeded = false;

try {
    $testStmt = $pdo->query("SELECT COUNT(*) FROM chart_of_accounts LIMIT 1");
    if ($testStmt) {
        $accountsStmt = $pdo->query("
            SELECT coa.*,
                   parent.account_name as parent_name,
                   (SELECT COUNT(*) FROM chart_of_accounts WHERE parent_account_id = coa.id) as child_count
            FROM chart_of_accounts coa
            LEFT JOIN chart_of_accounts parent ON parent.id = coa.parent_account_id
            WHERE coa.is_active = 1
            ORDER BY coa.account_code ASC
        ");
        $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

        $currentPeriod = date('Y-m');

        $balanceStmt = $pdo->prepare("
            SELECT account_id, ending_balance
            FROM ledger_accounts
            WHERE fiscal_period = ?
        ");
        $balanceStmt->execute([$currentPeriod]);
        while ($row = $balanceStmt->fetch(PDO::FETCH_ASSOC)) {
            $balances[$row['account_id']] = $row['ending_balance'];
        }
    } else {
        $migrationNeeded = true;
    }
} catch (Exception $e) {
    error_log("Ledger page error: " . $e->getMessage());
    $migrationNeeded = true;
}

// Group accounts by type
$accountsByType = [
    'asset' => [],
    'liability' => [],
    'equity' => [],
    'revenue' => [],
    'cogs' => [],
    'expense' => []
];

foreach ($accounts as $account) {
    $accountsByType[$account['account_type']][] = $account;
}

$typeLabels = [
    'asset' => 'Assets',
    'liability' => 'Liabilities',
    'equity' => 'Equity',
    'revenue' => 'Revenue',
    'cogs' => 'Cost of Goods Sold',
    'expense' => 'Operating Expenses'
];

$typeColors = [
    'asset' => '#3b82f6',
    'liability' => '#dc2626',
    'equity' => '#6b7280',
    'revenue' => '#10b981',
    'cogs' => '#f59e0b',
    'expense' => '#d97706'
];

// Load CSS inline
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Chart of Accounts']]); ?>
        <?php render_accounting_secondary_nav('ledger.php'); ?>
    <?php endif; ?>
    
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">Chart of Accounts</h1>
            <p class="acct1015_page_subtitle">Hierarchical account structure with current balances • Period: <?= date('F Y') ?></p>
        </div>
        <a href="index.php" class="acct1015_btn_secondary">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>

    <?php if ($migrationNeeded): ?>
    <div class="acct1015_card" style="background: #fffbeb; border-color: #fbbf24;">
        <h5 style="color: #d97706; margin-bottom: 1rem;"><i class="bi bi-exclamation-triangle"></i> Database Migration Required</h5>
        <p style="color: #92400e;">Run the database migration to create the chart of accounts.</p>
        <a href="index.php" class="acct1015_btn_primary" style="margin-top: 1rem;">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    <?php else: ?>

    <?php foreach ($accountsByType as $type => $typeAccounts): ?>
        <?php if (count($typeAccounts) > 0): ?>
        <div class="acct1015_card acct1015_animate">
            <div class="acct1015_card_header">
                <h2 class="acct1015_card_title">
                    <i class="bi bi-folder" style="color: <?= $typeColors[$type] ?>;"></i>
                    <?= $typeLabels[$type] ?>
                </h2>
                <span class="acct1015_badge acct1015_badge_neutral"><?= count($typeAccounts) ?> accounts</span>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="acct1015_table">
                    <thead class="acct1015_table_header">
                        <tr>
                            <th style="width: 100px;">Code</th>
                            <th>Account Name</th>
                            <th style="width: 120px;">Normal Balance</th>
                            <th style="width: 150px; text-align: right;">Current Balance</th>
                            <th style="width: 80px; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($typeAccounts as $account): ?>
                        <tr class="acct1015_table_row">
                            <td>
                                <code style="background: #f3f4f6; padding: 0.375rem 0.625rem; border-radius: 0.375rem; font-weight: 600; font-size: 0.875rem;">
                                    <?= htmlspecialchars($account['account_code']) ?>
                                </code>
                            </td>
                            <td>
                                <?php if ($account['parent_account_id']): ?>
                                    <span style="color: #9ca3af; margin-right: 0.5rem;">└─</span>
                                <?php endif; ?>
                                <strong style="color: #111827;"><?= htmlspecialchars($account['account_name']) ?></strong>
                                <?php if ($account['description']): ?>
                                    <div style="font-size: 0.8125rem; color: #6b7280; margin-top: 0.25rem;">
                                        <?= htmlspecialchars($account['description']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($account['is_system']): ?>
                                    <span class="acct1015_badge acct1015_badge_neutral" style="margin-top: 0.25rem; font-size: 0.75rem;">
                                        <i class="bi bi-lock-fill"></i> System
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="acct1015_badge <?= $account['normal_balance'] === 'debit' ? 'acct1015_badge_info' : 'acct1015_badge_warning' ?>">
                                    <?= ucfirst($account['normal_balance']) ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <?php 
                                $balance = $balances[$account['id']] ?? 0;
                                $balanceColor = $balance >= 0 ? '#047857' : '#dc2626';
                                ?>
                                <strong style="color: <?= $balanceColor ?>; font-size: 1.125rem;">
                                    $<?= number_format(abs($balance), 2) ?>
                                </strong>
                            </td>
                            <td style="text-align: center;">
                                <button class="acct1015_btn_secondary" style="padding: 0.5rem 0.75rem; font-size: 0.875rem;" 
                                        onclick="viewAccountHistory(<?= $account['id'] ?>, '<?= htmlspecialchars($account['account_name']) ?>')">
                                    <i class="bi bi-graph-up"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<!-- Account History Modal -->
<div class="modal fade" id="accountHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: 1rem; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <h5 class="modal-title" style="font-weight: 600; color: #111827;">
                    <i class="bi bi-graph-up" style="color: #10b981;"></i> <span id="historyAccountTitle">Balance History</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <canvas id="accountBalanceChart" height="80"></canvas>
                <hr style="margin: 2rem 0; border-color: #e5e7eb;">
                <div id="accountHistoryTable"></div>
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
let historyModal;

document.addEventListener('DOMContentLoaded', function() {
    historyModal = new bootstrap.Modal(document.getElementById('accountHistoryModal'));
});

async function viewAccountHistory(accountId, accountName) {
    document.getElementById('historyAccountTitle').textContent = accountName;
    historyModal.show();
    
    try {
        const response = await fetch(`/api/accounting/unified-api.php?action=get_account_history&account_id=${accountId}&months=12`);
        const result = await response.json();
        
        if (result.success && result.history) {
            const history = result.history;
            
            createAccountBalanceChart('accountBalanceChart', {
                accountName: accountName,
                periods: history.map(h => h.fiscal_period),
                balances: history.map(h => parseFloat(h.ending_balance))
            });
            
            let tableHtml = `
                <table class="acct1015_table">
                    <thead class="acct1015_table_header">
                        <tr>
                            <th>Period</th>
                            <th style="text-align: right;">Debits</th>
                            <th style="text-align: right;">Credits</th>
                            <th style="text-align: right;">Ending Balance</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            history.forEach(h => {
                tableHtml += `
                    <tr class="acct1015_table_row">
                        <td style="font-weight: 600;">${h.fiscal_period}</td>
                        <td style="text-align: right;">$${parseFloat(h.total_debits).toFixed(2)}</td>
                        <td style="text-align: right;">$${parseFloat(h.total_credits).toFixed(2)}</td>
                        <td style="text-align: right; font-weight: 700; color: #111827;">$${parseFloat(h.ending_balance).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            tableHtml += '</tbody></table>';
            document.getElementById('accountHistoryTable').innerHTML = tableHtml;
        }
    } catch (error) {
        console.error('Failed to load account history:', error);
    }
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
