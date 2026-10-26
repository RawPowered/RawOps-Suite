<?php
/**
 * Report API - Real-time Financial Report Generation
 * Generates P&L, Balance Sheet, Cash Flow, and other financial reports
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';

start_session();

// Authentication check
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Role check
$roles = get_user_roles($userId);
if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'profit_loss':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $method = $_GET['method'] ?? 'accrual'; // accrual or cash
            
            $report = [];
            
            // REVENUE
            $revenueQuery = "
                SELECT COALESCE(SUM(o.total), 0) as total
                FROM orders o
                WHERE o.created_at BETWEEN ? AND ?
                AND o.status != 'cancelled'
            ";
            
            if ($method === 'cash') {
                $revenueQuery .= " AND o.payment_status = 'paid'";
            }
            
            $revenueStmt = $pdo->prepare($revenueQuery);
            $revenueStmt->execute([$startDate, $endDate]);
            $report['revenue'] = $revenueStmt->fetch(PDO::FETCH_ASSOC);
            
            // COGS - from expenses with COGS categories
            $cogsStmt = $pdo->prepare("
                SELECT 
                    ec.category_name,
                    COALESCE(SUM(e.amount), 0) as total
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.expense_category_id
                JOIN chart_of_accounts coa ON coa.id = ec.account_id
                WHERE e.expense_date BETWEEN ? AND ?
                AND coa.account_type = 'cogs'
                GROUP BY ec.category_name
            ");
            $cogsStmt->execute([$startDate, $endDate]);
            $report['cogs_breakdown'] = $cogsStmt->fetchAll(PDO::FETCH_ASSOC);
            $report['total_cogs'] = array_sum(array_column($report['cogs_breakdown'], 'total'));
            
            // GROSS PROFIT
            $report['gross_profit'] = $report['revenue']['total'] - $report['total_cogs'];
            $report['gross_margin_percent'] = $report['revenue']['total'] > 0 
                ? round(($report['gross_profit'] / $report['revenue']['total']) * 100, 2) 
                : 0;
            
            // OPERATING EXPENSES
            $opexStmt = $pdo->prepare("
                SELECT 
                    ec.category_name,
                    COALESCE(SUM(e.amount), 0) as total
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.expense_category_id
                JOIN chart_of_accounts coa ON coa.id = ec.account_id
                WHERE e.expense_date BETWEEN ? AND ?
                AND coa.account_type = 'expense'
                GROUP BY ec.category_name
            ");
            $opexStmt->execute([$startDate, $endDate]);
            $report['opex_breakdown'] = $opexStmt->fetchAll(PDO::FETCH_ASSOC);
            $report['total_opex'] = array_sum(array_column($report['opex_breakdown'], 'total'));
            
            // COMMISSIONS (from commissions table)
            $commissionStmt = $pdo->prepare("
                SELECT 
                    commission_type,
                    COALESCE(SUM(amount), 0) as total
                FROM commissions
                WHERE created_at BETWEEN ? AND ?
                GROUP BY commission_type
            ");
            $commissionStmt->execute([$startDate, $endDate]);
            $report['commission_breakdown'] = $commissionStmt->fetchAll(PDO::FETCH_ASSOC);
            $report['total_commissions'] = array_sum(array_column($report['commission_breakdown'], 'total'));
            
            // NET PROFIT
            $report['net_profit'] = $report['gross_profit'] - $report['total_opex'] - $report['total_commissions'];
            $report['net_margin_percent'] = $report['revenue']['total'] > 0 
                ? round(($report['net_profit'] / $report['revenue']['total']) * 100, 2) 
                : 0;
            
            // EBITDA (simplified - no interest, tax, depreciation, amortization in this version)
            $report['ebitda'] = $report['net_profit'];
            
            $report['period'] = [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'method' => $method
            ];
            
            echo json_encode(['success' => true, 'report' => $report]);
            break;
            
        case 'balance_sheet':
            $asOfDate = $_GET['as_of_date'] ?? date('Y-m-t');
            
            $report = [];
            
            // ASSETS
            $assetsStmt = $pdo->prepare("
                SELECT 
                    coa.account_code,
                    coa.account_name,
                    COALESCE(la.ending_balance, 0) as balance
                FROM chart_of_accounts coa
                LEFT JOIN ledger_accounts la ON la.account_id = coa.id 
                    AND la.fiscal_period = ?
                WHERE coa.account_type = 'asset'
                AND coa.is_active = 1
                ORDER BY coa.account_code
            ");
            $fiscalPeriod = date('Y-m', strtotime($asOfDate));
            $assetsStmt->execute([$fiscalPeriod]);
            $report['assets'] = $assetsStmt->fetchAll(PDO::FETCH_ASSOC);
            $report['total_assets'] = array_sum(array_column($report['assets'], 'balance'));
            
            // LIABILITIES
            $liabilitiesStmt = $pdo->prepare("
                SELECT 
                    coa.account_code,
                    coa.account_name,
                    COALESCE(la.ending_balance, 0) as balance
                FROM chart_of_accounts coa
                LEFT JOIN ledger_accounts la ON la.account_id = coa.id 
                    AND la.fiscal_period = ?
                WHERE coa.account_type = 'liability'
                AND coa.is_active = 1
                ORDER BY coa.account_code
            ");
            $liabilitiesStmt->execute([$fiscalPeriod]);
            $report['liabilities'] = $liabilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
            $report['total_liabilities'] = array_sum(array_column($report['liabilities'], 'balance'));
            
            // EQUITY
            $equityStmt = $pdo->prepare("
                SELECT 
                    coa.account_code,
                    coa.account_name,
                    COALESCE(la.ending_balance, 0) as balance
                FROM chart_of_accounts coa
                LEFT JOIN ledger_accounts la ON la.account_id = coa.id 
                    AND la.fiscal_period = ?
                WHERE coa.account_type = 'equity'
                AND coa.is_active = 1
                ORDER BY coa.account_code
            ");
            $equityStmt->execute([$fiscalPeriod]);
            $report['equity'] = $equityStmt->fetchAll(PDO::FETCH_ASSOC);
            $report['total_equity'] = array_sum(array_column($report['equity'], 'balance'));
            
            $report['as_of_date'] = $asOfDate;
            
            echo json_encode(['success' => true, 'report' => $report]);
            break;
            
        case 'general_ledger':
            $accountId = $_GET['account_id'] ?? null;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            
            if (!$accountId) {
                throw new Exception('Account ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    je.entry_date,
                    je.entry_number,
                    je.description,
                    jel.debit_amount,
                    jel.credit_amount,
                    jel.description as line_description
                FROM journal_entry_lines jel
                JOIN journal_entries je ON je.id = jel.journal_entry_id
                WHERE jel.account_id = ?
                AND je.entry_date BETWEEN ? AND ?
                AND je.status = 'posted'
                ORDER BY je.entry_date, je.id, jel.line_number
            ");
            $stmt->execute([$accountId, $startDate, $endDate]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get account info
            $accountStmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE id = ?");
            $accountStmt->execute([$accountId]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'account' => $account,
                'transactions' => $transactions,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate]
            ]);
            break;
            
        case 'trial_balance':
            $asOfDate = $_GET['as_of_date'] ?? date('Y-m-t');
            $fiscalPeriod = date('Y-m', strtotime($asOfDate));
            
            $stmt = $pdo->prepare("
                SELECT 
                    coa.account_code,
                    coa.account_name,
                    coa.account_type,
                    coa.normal_balance,
                    COALESCE(la.total_debits, 0) as total_debits,
                    COALESCE(la.total_credits, 0) as total_credits,
                    COALESCE(la.ending_balance, 0) as balance
                FROM chart_of_accounts coa
                LEFT JOIN ledger_accounts la ON la.account_id = coa.id 
                    AND la.fiscal_period = ?
                WHERE coa.is_active = 1
                ORDER BY coa.account_code
            ");
            $stmt->execute([$fiscalPeriod]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totals = [
                'total_debits' => array_sum(array_column($accounts, 'total_debits')),
                'total_credits' => array_sum(array_column($accounts, 'total_credits'))
            ];
            
            echo json_encode([
                'success' => true,
                'accounts' => $accounts,
                'totals' => $totals,
                'is_balanced' => abs($totals['total_debits'] - $totals['total_credits']) < 0.01,
                'as_of_date' => $asOfDate
            ]);
            break;
            
        case 'commission_summary':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            
            // Commission summary by agent
            $stmt = $pdo->prepare("
                SELECT 
                    u.id as agent_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    COUNT(c.id) as commission_count,
                    COALESCE(SUM(c.amount), 0) as total_commissions,
                    COALESCE(AVG(c.percentage), 0) as avg_percentage,
                    COALESCE(SUM(CASE WHEN c.status = 'paid' THEN c.amount ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END), 0) as pending_amount
                FROM commissions c
                JOIN users u ON u.id = c.user_id
                WHERE c.created_at BETWEEN ? AND ?
                AND c.commission_type = 'sales_agent'
                GROUP BY u.id, u.first_name, u.last_name, u.email
                ORDER BY total_commissions DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            $agentSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Commission summary by type
            $typeStmt = $pdo->prepare("
                SELECT 
                    commission_type,
                    COUNT(*) as count,
                    COALESCE(SUM(amount), 0) as total
                FROM commissions
                WHERE created_at BETWEEN ? AND ?
                GROUP BY commission_type
            ");
            $typeStmt->execute([$startDate, $endDate]);
            $typeSummary = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'by_agent' => $agentSummary,
                'by_type' => $typeSummary,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate]
            ]);
            break;
            
        case 'tax_summary':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            
            $stmt = $pdo->prepare("
                SELECT 
                    jurisdiction,
                    tax_type,
                    COALESCE(SUM(taxable_amount), 0) as total_taxable,
                    COALESCE(SUM(tax_owed), 0) as total_tax_owed,
                    COALESCE(SUM(payments_made), 0) as total_payments,
                    COALESCE(SUM(balance_due), 0) as total_balance_due
                FROM tax_calculations
                WHERE period_start >= ? AND period_end <= ?
                GROUP BY jurisdiction, tax_type
            ");
            $stmt->execute([$startDate, $endDate]);
            $taxSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'tax_summary' => $taxSummary,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate]
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}





