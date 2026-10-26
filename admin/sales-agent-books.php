<?php
$SEO = [
    'title' => 'Sales Agent Books & Loans — Admin',
    'description' => 'Manage sales agent loans, repayment terms, and financial tracking.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_login_or_redirect('/login.php');

$userId = current_user_id();
$adminId = current_admin_id();

if (!$adminId) {
    flash_set('error', 'Admin access required.');
    redirect('/');
}

$pdo = db();
require_once __DIR__ . '/../required/agent-loan-utils.php';

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create_loan') {
            $agentId = intval($_POST['agent_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $loanType = $_POST['loan_type'] ?? 'startup';
            $notes = trim($_POST['notes'] ?? '');
            
            $repaymentTerms = [
                'method' => $_POST['repayment_method'] ?? 'percentage_per_commission',
                'percentage_rate' => isset($_POST['percentage_rate']) ? floatval($_POST['percentage_rate']) : 20.0,
                'grace_period_days' => intval($_POST['grace_period_days'] ?? 30),
                'auto_deduct_after_days' => intval($_POST['auto_deduct_after_days'] ?? 30)
            ];
            
            if ($agentId <= 0 || $amount <= 0) {
                throw new Exception('Valid agent and amount required');
            }
            
            $loanId = create_agent_loan($pdo, $agentId, $amount, $loanType, $adminId, $repaymentTerms, $notes);
            
            if ($loanId) {
                // Also create repayment settings for this loan
                $stmt = $pdo->prepare('
                    INSERT INTO agent_repayment_settings (
                        agent_id, loan_id, repayment_method, percentage_rate,
                        grace_period_days, auto_deduct_after_days, is_active, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                ');
                $stmt->execute([
                    $agentId,
                    $loanId,
                    $repaymentTerms['method'],
                    $repaymentTerms['percentage_rate'],
                    $repaymentTerms['grace_period_days'],
                    $repaymentTerms['auto_deduct_after_days'],
                    $adminId
                ]);
                
                $message = 'Loan created successfully! ID: ' . $loanId;
            } else {
                throw new Exception('Failed to create loan');
            }
            
        } elseif ($action === 'manual_repayment') {
            $loanId = intval($_POST['loan_id'] ?? 0);
            $agentId = intval($_POST['agent_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($loanId <= 0 || $amount <= 0) {
                throw new Exception('Valid loan ID and amount required');
            }
            
            $transactionId = apply_loan_repayment($pdo, $loanId, $agentId, $amount, 'direct_payment', null, null, $notes);
            
            if ($transactionId) {
                $message = 'Repayment recorded successfully!';
            } else {
                throw new Exception('Failed to record repayment');
            }
            
        } elseif ($action === 'forgive_loan') {
            $loanId = intval($_POST['loan_id'] ?? 0);
            $reason = trim($_POST['forgive_reason'] ?? '');
            
            if ($loanId <= 0) {
                throw new Exception('Loan ID required');
            }
            
            // Get loan balance
            $stmt = $pdo->prepare('SELECT agent_id, balance_remaining FROM sales_agent_loans WHERE id = ?');
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                throw new Exception('Loan not found');
            }
            
            if ($loan['balance_remaining'] > 0) {
                apply_loan_repayment($pdo, $loanId, $loan['agent_id'], $loan['balance_remaining'], 'forgiveness', null, null, $reason);
            }
            
            $stmt = $pdo->prepare('UPDATE sales_agent_loans SET status = "forgiven", balance_remaining = 0, paid_off_at = NOW() WHERE id = ?');
            $stmt->execute([$loanId]);
            
            $message = 'Loan forgiven successfully';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all sales agents
$agents = $pdo->query('
    SELECT u.id, u.first_name, u.last_name, u.email,
           COUNT(DISTINCT sal.id) as loan_count,
           SUM(CASE WHEN sal.status = "active" THEN sal.balance_remaining ELSE 0 END) as total_balance
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN sales_agent_loans sal ON sal.agent_id = u.id
    WHERE r.name = "sales_agent" AND u.status = "active"
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
')->fetchAll(PDO::FETCH_ASSOC);

// Get selected agent details if viewing specific agent
$selectedAgentId = intval($_GET['agent_id'] ?? 0);
$selectedAgent = null;
$agentLoans = [];
$agentTransactions = [];

if ($selectedAgentId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$selectedAgentId]);
    $selectedAgent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedAgent) {
        // Get loans for this agent
        $stmt = $pdo->prepare('
            SELECT sal.*, 
                   CONCAT(approver.first_name, " ", approver.last_name) as approved_by_name,
                   (SELECT SUM(amount) FROM loan_repayment_transactions WHERE loan_id = sal.id) as total_repaid
            FROM sales_agent_loans sal
            LEFT JOIN users approver ON approver.id = sal.approved_by
            WHERE sal.agent_id = ?
            ORDER BY sal.loan_date DESC
        ');
        $stmt->execute([$selectedAgentId]);
        $agentLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent transactions
        $stmt = $pdo->prepare('
            SELECT lrt.*, sal.loan_type, o.order_number
            FROM loan_repayment_transactions lrt
            JOIN sales_agent_loans sal ON sal.id = lrt.loan_id
            LEFT JOIN orders o ON o.id = lrt.source_order_id
            WHERE lrt.agent_id = ?
            ORDER BY lrt.transaction_date DESC
            LIMIT 50
        ');
        $stmt->execute([$selectedAgentId]);
        $agentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<style>
.books-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.agent-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 32px; }
.agent-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; cursor: pointer; transition: all 0.2s; }
.agent-card:hover { border-color: #059669; box-shadow: 0 4px 12px rgba(5,150,105,0.15); transform: translateY(-2px); }
.agent-card.has-balance { border-left: 4px solid #f59e0b; }
.loan-table { width: 100%; border-collapse: collapse; }
.loan-table th, .loan-table td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
.loan-table th { background: #f9fafb; font-weight: 600; }
.status-badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.status-active { background: #fef3c7; color: #92400e; }
.status-paid { background: #dcfce7; color: #14532d; }
.status-forgiven { background: #dbeafe; color: #1e40af; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
.modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; }
</style>

<div class="books-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
        <div>
            <h1 style="margin: 0; font-size: 32px; color: #1f2937;">💰 Sales Agent Books</h1>
            <p style="color: #6b7280; margin: 8px 0 0;">Manage agent loans, repayments, and financial tracking</p>
        </div>
        <button onclick="showCreateLoanModal()" class="btn" style="background: #059669;">
            + Create Loan
        </button>
    </div>

    <?php if ($message): ?>
        <div style="background: #dcfce7; border: 1px solid #059669; color: #14532d; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="background: #fee2e2; border: 1px solid #dc2626; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedAgent): ?>
        <!-- Agent Detail View -->
        <div style="background: #f0f9ff; border: 2px solid #0369a1; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <h2 style="margin: 0; color: #1e40af;">
                        <?php echo htmlspecialchars($selectedAgent['first_name'] . ' ' . $selectedAgent['last_name']); ?>
                    </h2>
                    <p style="color: #075985; margin: 4px 0 0;"><?php echo htmlspecialchars($selectedAgent['email']); ?></p>
                </div>
                <a href="sales-agent-books.php" class="btn secondary">← Back to All Agents</a>
            </div>
        </div>

        <!-- Loan Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <?php
            $activeLoanCount = 0;
            $totalBalance = 0;
            $totalRepaid = 0;
            foreach ($agentLoans as $loan) {
                if ($loan['status'] === 'active') $activeLoanCount++;
                $totalBalance += ($loan['status'] === 'active') ? (float)$loan['balance_remaining'] : 0;
                $totalRepaid += (float)($loan['total_repaid'] ?? 0);
            }
            ?>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #f59e0b;"><?php echo $activeLoanCount; ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Active Loans</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #dc2626;">$<?php echo number_format($totalBalance, 2); ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Balance Owed</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #059669;">$<?php echo number_format($totalRepaid, 2); ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Total Repaid</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #3b82f6;"><?php echo count($agentTransactions); ?></div>
                <div style="font-size: 12px; color: #6b7280; text-transform: uppercase;">Transactions</div>
            </div>
        </div>

        <!-- Loans Table -->
        <div class="card" style="margin-bottom: 24px;">
            <h2 style="margin-top: 0;">Loans</h2>
            <?php if (empty($agentLoans)): ?>
                <p style="text-align: center; color: #6b7280; padding: 40px;">No loans for this agent.</p>
            <?php else: ?>
                <table class="loan-table">
                    <thead>
                        <tr>
                            <th>Loan ID</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Balance</th>
                            <th>Repaid</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agentLoans as $loan): ?>
                        <tr>
                            <td><strong>#<?php echo $loan['id']; ?></strong></td>
                            <td><?php echo ucwords(str_replace('_', ' ', $loan['loan_type'])); ?></td>
                            <td><?php echo date('M j, Y', strtotime($loan['loan_date'])); ?></td>
                            <td>$<?php echo number_format($loan['loan_amount'], 2); ?></td>
                            <td style="font-weight: 600; color: <?php echo $loan['balance_remaining'] > 0 ? '#dc2626' : '#059669'; ?>;">
                                $<?php echo number_format($loan['balance_remaining'], 2); ?>
                            </td>
                            <td>$<?php echo number_format($loan['total_repaid'] ?? 0, 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $loan['status']; ?>">
                                    <?php echo $loan['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($loan['status'] === 'active' && $loan['balance_remaining'] > 0): ?>
                                    <button onclick="showRepaymentModal(<?php echo $loan['id']; ?>, <?php echo $selectedAgentId; ?>, <?php echo $loan['balance_remaining']; ?>)" 
                                            class="btn" style="font-size: 11px; padding: 4px 8px;">
                                        Record Payment
                                    </button>
                                    <button onclick="showForgiveModal(<?php echo $loan['id']; ?>, <?php echo $loan['balance_remaining']; ?>)" 
                                            class="btn" style="font-size: 11px; padding: 4px 8px; background: #6b7280;">
                                        Forgive
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Transaction History -->
        <div class="card">
            <h2 style="margin-top: 0;">Repayment History</h2>
            <?php if (empty($agentTransactions)): ?>
                <p style="text-align: center; color: #6b7280; padding: 40px;">No transactions yet.</p>
            <?php else: ?>
                <table class="loan-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Loan Type</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Order</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agentTransactions as $trans): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:ia', strtotime($trans['transaction_date'])); ?></td>
                            <td><?php echo ucwords(str_replace('_', ' ', $trans['loan_type'])); ?></td>
                            <td style="font-weight: 600; color: #059669;">$<?php echo number_format($trans['amount'], 2); ?></td>
                            <td><?php echo ucwords(str_replace('_', ' ', $trans['repayment_type'])); ?></td>
                            <td><?php echo $trans['order_number'] ? htmlspecialchars($trans['order_number']) : '—'; ?></td>
                            <td style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($trans['notes'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Agent List View -->
        <div class="card" style="margin-bottom: 24px;">
            <h2 style="margin-top: 0;">Sales Agents</h2>
            <p style="color: #6b7280;">Select an agent to view or manage their loans and repayments.</p>
        </div>

        <div class="agent-grid">
            <?php foreach ($agents as $agent): ?>
            <div class="agent-card <?php echo $agent['total_balance'] > 0 ? 'has-balance' : ''; ?>" 
                 onclick="window.location='?agent_id=<?php echo $agent['id']; ?>'">
                <h3 style="margin: 0 0 8px; font-size: 18px; color: #1f2937;">
                    <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                </h3>
                <p style="margin: 0 0 12px; font-size: 13px; color: #6b7280;">
                    <?php echo htmlspecialchars($agent['email']); ?>
                </p>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-size: 12px; color: #6b7280;">Loans</div>
                        <div style="font-weight: 600;"><?php echo $agent['loan_count']; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #6b7280;">Balance</div>
                        <div style="font-weight: 600; color: <?php echo $agent['total_balance'] > 0 ? '#dc2626' : '#6b7280'; ?>;">
                            $<?php echo number_format($agent['total_balance'], 2); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Create Loan Modal -->
<div id="createLoanModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-top: 0;">Create New Loan</h2>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="create_loan">
            
            <div>
                <label><strong>Sales Agent *</strong></label>
                <select name="agent_id" class="input" required>
                    <option value="">-- Select Agent --</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo $agent['id']; ?>" <?php echo ($selectedAgentId == $agent['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label><strong>Loan Amount *</strong></label>
                    <input type="number" name="amount" class="input" min="1" step="0.01" required>
                </div>
                <div>
                    <label><strong>Loan Type *</strong></label>
                    <select name="loan_type" class="input" required>
                        <option value="startup">Startup Costs</option>
                        <option value="sample_expense">Sample Expense</option>
                        <option value="advance">Commission Advance</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <div>
                <label><strong>Repayment Method *</strong></label>
                <select name="repayment_method" class="input" onchange="togglePercentageField(this.value)">
                    <option value="percentage_per_commission">Percentage Per Commission</option>
                    <option value="first_sale_full">First Sale Full Repayment</option>
                    <option value="upfront">Upfront (No Auto-Deduct)</option>
                    <option value="hybrid">Hybrid</option>
                </select>
            </div>
            
            <div id="percentageField">
                <label><strong>Commission Percentage %</strong></label>
                <input type="number" name="percentage_rate" class="input" min="1" max="100" step="0.1" value="20">
                <span style="font-size: 12px; color: #6b7280;">Percentage of each commission to deduct</span>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label><strong>Grace Period (days)</strong></label>
                    <input type="number" name="grace_period_days" class="input" value="30" min="0">
                </div>
                <div>
                    <label><strong>Auto-Deduct After (days)</strong></label>
                    <input type="number" name="auto_deduct_after_days" class="input" value="30" min="0">
                </div>
            </div>
            
            <div>
                <label><strong>Notes</strong></label>
                <textarea name="notes" class="input" rows="3" placeholder="Reason for loan, special terms, etc."></textarea>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="closeModal('createLoanModal')" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">Create Loan</button>
            </div>
        </form>
    </div>
</div>

<!-- Manual Repayment Modal -->
<div id="repaymentModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-top: 0;">Record Manual Payment</h2>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="manual_repayment">
            <input type="hidden" name="loan_id" id="repay_loan_id">
            <input type="hidden" name="agent_id" id="repay_agent_id">
            
            <div>
                <label><strong>Payment Amount *</strong></label>
                <input type="number" name="amount" id="repay_amount" class="input" min="0.01" step="0.01" required>
                <span id="max_amount_hint" style="font-size: 12px; color: #6b7280;"></span>
            </div>
            
            <div>
                <label><strong>Payment Notes</strong></label>
                <textarea name="notes" class="input" rows="3" placeholder="Check number, payment method, etc."></textarea>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="closeModal('repaymentModal')" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Forgive Loan Modal -->
<div id="forgiveModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-top: 0; color: #dc2626;">⚠️ Forgive Loan</h2>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="forgive_loan">
            <input type="hidden" name="loan_id" id="forgive_loan_id">
            
            <div style="background: #fef2f2; border: 1px solid #dc2626; padding: 16px; border-radius: 8px;">
                <p style="margin: 0; color: #991b1b;">
                    This will write off the remaining balance of <strong id="forgive_amount">$0.00</strong>. 
                    This action cannot be undone.
                </p>
            </div>
            
            <div>
                <label><strong>Reason for Forgiveness *</strong></label>
                <textarea name="forgive_reason" class="input" rows="3" required 
                          placeholder="Explain why this loan is being forgiven..."></textarea>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="closeModal('forgiveModal')" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626;">Forgive Loan</button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreateLoanModal() {
    document.getElementById('createLoanModal').style.display = 'block';
}

function showRepaymentModal(loanId, agentId, maxAmount) {
    document.getElementById('repay_loan_id').value = loanId;
    document.getElementById('repay_agent_id').value = agentId;
    document.getElementById('repay_amount').max = maxAmount;
    document.getElementById('max_amount_hint').textContent = 'Max: $' + maxAmount.toFixed(2);
    document.getElementById('repaymentModal').style.display = 'block';
}

function showForgiveModal(loanId, balance) {
    document.getElementById('forgive_loan_id').value = loanId;
    document.getElementById('forgive_amount').textContent = '$' + balance.toFixed(2);
    document.getElementById('forgiveModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function togglePercentageField(method) {
    const field = document.getElementById('percentageField');
    field.style.display = (method === 'percentage_per_commission' || method === 'hybrid') ? 'block' : 'none';
}

// Close modal on background click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});

// ESC key closes modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>




