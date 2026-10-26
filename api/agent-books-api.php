<?php
/**
 * Sales Agent Books & Loan Management API
 * CRUD operations for agent loans, repayments, and financial tracking
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/agent-loan-utils.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

start_session();

$uid = current_user_id();
$isAdmin = current_admin_id() !== null;

if (!$uid) {
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
}

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_agent_loans':
            // Get loans for an agent (admin sees any agent, agents see their own)
            $agentId = intval($_GET['agent_id'] ?? ($isAdmin ? 0 : $uid));
            
            if (!$isAdmin && $agentId !== $uid) {
                throw new Exception('Access denied');
            }
            
            if ($agentId <= 0) {
                throw new Exception('Agent ID required');
            }
            
            $stmt = $pdo->prepare('
                SELECT sal.*, 
                       CONCAT(approver.first_name, " ", approver.last_name) as approved_by_name,
                       (SELECT COUNT(*) FROM loan_repayment_transactions WHERE loan_id = sal.id) as repayment_count,
                       (SELECT SUM(amount) FROM loan_repayment_transactions WHERE loan_id = sal.id) as total_repaid
                FROM sales_agent_loans sal
                LEFT JOIN users approver ON approver.id = sal.approved_by
                WHERE sal.agent_id = ?
                ORDER BY sal.loan_date DESC, sal.id DESC
            ');
            $stmt->execute([$agentId]);
            $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'loans' => $loans]);
            break;
            
        case 'create_loan':
            // Admin only
            if (!$isAdmin) {
                throw new Exception('Admin access required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $agentId = intval($input['agent_id'] ?? 0);
            $amount = floatval($input['amount'] ?? 0);
            $loanType = $input['loan_type'] ?? 'startup';
            $notes = trim($input['notes'] ?? '');
            $repaymentTerms = $input['repayment_terms'] ?? [];
            
            if ($agentId <= 0 || $amount <= 0) {
                throw new Exception('Valid agent ID and amount required');
            }
            
            $loanId = create_agent_loan($pdo, $agentId, $amount, $loanType, $uid, $repaymentTerms, $notes);
            
            if (!$loanId) {
                throw new Exception('Failed to create loan');
            }
            
            echo json_encode(['ok' => true, 'loan_id' => $loanId, 'message' => 'Loan created successfully']);
            break;
            
        case 'get_repayment_settings':
            $agentId = intval($_GET['agent_id'] ?? ($isAdmin ? 0 : $uid));
            
            if (!$isAdmin && $agentId !== $uid) {
                throw new Exception('Access denied');
            }
            
            if ($agentId <= 0) {
                throw new Exception('Agent ID required');
            }
            
            $loanId = !empty($_GET['loan_id']) ? intval($_GET['loan_id']) : null;
            $settings = get_agent_repayment_settings($pdo, $agentId, $loanId);
            
            echo json_encode(['ok' => true, 'settings' => $settings]);
            break;
            
        case 'update_repayment_settings':
            // Admin only
            if (!$isAdmin) {
                throw new Exception('Admin access required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $agentId = intval($input['agent_id'] ?? 0);
            $loanId = !empty($input['loan_id']) ? intval($input['loan_id']) : null;
            $method = $input['repayment_method'] ?? 'percentage_per_commission';
            $percentage = isset($input['percentage_rate']) ? floatval($input['percentage_rate']) : null;
            $gracePeriod = intval($input['grace_period_days'] ?? 30);
            $autoDeduct = intval($input['auto_deduct_after_days'] ?? 30);
            $notes = trim($input['notes'] ?? '');
            
            if ($agentId <= 0) {
                throw new Exception('Agent ID required');
            }
            
            // Check if settings exist
            $stmt = $pdo->prepare('SELECT id FROM agent_repayment_settings WHERE agent_id = ? AND loan_id <=> ?');
            $stmt->execute([$agentId, $loanId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update
                $stmt = $pdo->prepare('
                    UPDATE agent_repayment_settings
                    SET repayment_method = ?, percentage_rate = ?, grace_period_days = ?,
                        auto_deduct_after_days = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$method, $percentage, $gracePeriod, $autoDeduct, $notes, $existing['id']]);
                $settingsId = $existing['id'];
            } else {
                // Create
                $stmt = $pdo->prepare('
                    INSERT INTO agent_repayment_settings (
                        agent_id, loan_id, repayment_method, percentage_rate,
                        grace_period_days, auto_deduct_after_days, is_active, created_by, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
                ');
                $stmt->execute([$agentId, $loanId, $method, $percentage, $gracePeriod, $autoDeduct, $uid, $notes]);
                $settingsId = $pdo->lastInsertId();
            }
            
            echo json_encode(['ok' => true, 'settings_id' => $settingsId, 'message' => 'Repayment settings updated']);
            break;
            
        case 'get_repayment_transactions':
            $agentId = intval($_GET['agent_id'] ?? ($isAdmin ? 0 : $uid));
            
            if (!$isAdmin && $agentId !== $uid) {
                throw new Exception('Access denied');
            }
            
            if ($agentId <= 0) {
                throw new Exception('Agent ID required');
            }
            
            $loanId = !empty($_GET['loan_id']) ? intval($_GET['loan_id']) : null;
            
            $where = 'lrt.agent_id = ?';
            $params = [$agentId];
            
            if ($loanId) {
                $where .= ' AND lrt.loan_id = ?';
                $params[] = $loanId;
            }
            
            $stmt = $pdo->prepare("
                SELECT lrt.*,
                       sal.loan_type,
                       o.order_number,
                       c.amount as commission_amount
                FROM loan_repayment_transactions lrt
                JOIN sales_agent_loans sal ON sal.id = lrt.loan_id
                LEFT JOIN orders o ON o.id = lrt.source_order_id
                LEFT JOIN commissions c ON c.id = lrt.source_commission_id
                WHERE {$where}
                ORDER BY lrt.transaction_date DESC
                LIMIT 100
            ");
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'transactions' => $transactions]);
            break;
            
        case 'manual_repayment':
            // Admin only - record a manual payment
            if (!$isAdmin) {
                throw new Exception('Admin access required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $loanId = intval($input['loan_id'] ?? 0);
            $agentId = intval($input['agent_id'] ?? 0);
            $amount = floatval($input['amount'] ?? 0);
            $notes = trim($input['notes'] ?? '');
            
            if ($loanId <= 0 || $agentId <= 0 || $amount <= 0) {
                throw new Exception('Loan ID, agent ID, and amount required');
            }
            
            $transactionId = apply_loan_repayment(
                $pdo,
                $loanId,
                $agentId,
                $amount,
                'direct_payment',
                null,
                null,
                $notes
            );
            
            if (!$transactionId) {
                throw new Exception('Failed to record repayment');
            }
            
            echo json_encode(['ok' => true, 'transaction_id' => $transactionId, 'message' => 'Repayment recorded successfully']);
            break;
            
        case 'forgive_loan':
            // Admin only
            if (!$isAdmin) {
                throw new Exception('Admin access required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $loanId = intval($input['loan_id'] ?? 0);
            $reason = trim($input['reason'] ?? '');
            
            if ($loanId <= 0) {
                throw new Exception('Loan ID required');
            }
            
            $pdo->beginTransaction();
            
            // Get loan details
            $stmt = $pdo->prepare('SELECT agent_id, balance_remaining FROM sales_agent_loans WHERE id = ?');
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                throw new Exception('Loan not found');
            }
            
            $balance = (float)$loan['balance_remaining'];
            
            if ($balance > 0) {
                // Create forgiveness transaction
                $stmt = $pdo->prepare('
                    INSERT INTO loan_repayment_transactions (
                        loan_id, agent_id, amount, repayment_type, notes, created_by
                    ) VALUES (?, ?, ?, "forgiveness", ?, ?)
                ');
                $stmt->execute([$loanId, $loan['agent_id'], $balance, $reason, $uid]);
            }
            
            // Mark loan as forgiven
            $stmt = $pdo->prepare('
                UPDATE sales_agent_loans
                SET status = "forgiven", balance_remaining = 0, paid_off_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$loanId]);
            
            $pdo->commit();
            
            echo json_encode(['ok' => true, 'message' => 'Loan forgiven successfully']);
            break;
            
        case 'get_agent_summary':
            // Get financial summary for agent
            $agentId = intval($_GET['agent_id'] ?? ($isAdmin ? 0 : $uid));
            
            if (!$isAdmin && $agentId !== $uid) {
                throw new Exception('Access denied');
            }
            
            if ($agentId <= 0) {
                throw new Exception('Agent ID required');
            }
            
            // Get loan summary
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_loans,
                    SUM(CASE WHEN status = "active" THEN balance_remaining ELSE 0 END) as total_balance,
                    SUM(loan_amount) as total_borrowed,
                    SUM(CASE WHEN status = "paid" THEN loan_amount ELSE 0 END) as total_repaid
                FROM sales_agent_loans
                WHERE agent_id = ?
            ');
            $stmt->execute([$agentId]);
            $loanSummary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get charge summary
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_charges,
                    SUM(CASE WHEN charge_status = "pending" THEN charge_amount ELSE 0 END) as pending_charges
                FROM agent_expense_charges
                WHERE agent_id = ?
            ');
            $stmt->execute([$agentId]);
            $chargeSummary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent commissions with deductions
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_commissions,
                    SUM(amount) as gross_commissions,
                    SUM(loan_repayment_deduction) as total_deductions,
                    SUM(net_commission_after_loans) as net_commissions
                FROM commissions
                WHERE user_id = ? AND commission_type = "sales_agent"
            ');
            $stmt->execute([$agentId]);
            $commissionSummary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'ok' => true,
                'summary' => [
                    'loans' => $loanSummary,
                    'charges' => $chargeSummary,
                    'commissions' => $commissionSummary
                ]
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}




