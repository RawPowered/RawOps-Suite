<?php
/**
 * Unified Accounting API
 * Central routing for all accounting operations
 * Provides consistent error handling and response format
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';

start_session();

// Authentication check
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        // ============================================
        // SETTINGS MANAGEMENT
        // ============================================
        
        case 'get_settings':
            $settings = get_accounting_settings();
            echo json_encode([
                'success' => true,
                'settings' => $settings
            ]);
            break;
            
        case 'update_setting':
            if (!can_manage_chart_of_accounts($userId)) {
                throw new Exception('Permission denied');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $key = $input['setting_key'] ?? '';
            $value = $input['setting_value'] ?? '';
            
            if (empty($key)) {
                throw new Exception('Setting key required');
            }
            
            $success = update_accounting_setting($key, $value, $userId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Setting updated' : 'Failed to update setting'
            ]);
            break;
            
        // ============================================
        // ACCOUNT OPERATIONS
        // ============================================
        
        case 'get_accounts':
            if (!can_view_accounting($userId)) {
                throw new Exception('Permission denied');
            }
            
            $type = $_GET['type'] ?? null;
            $activeOnly = isset($_GET['active_only']) ? (bool)$_GET['active_only'] : true;
            
            $sql = "SELECT * FROM chart_of_accounts WHERE 1=1";
            $params = [];
            
            if ($type) {
                $sql .= " AND account_type = ?";
                $params[] = $type;
            }
            
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            
            $sql .= " ORDER BY account_code ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'accounts' => $accounts
            ]);
            break;
            
        case 'get_account_balance':
            if (!can_view_accounting($userId)) {
                throw new Exception('Permission denied');
            }
            
            $accountId = $_GET['account_id'] ?? 0;
            $period = $_GET['period'] ?? date('Y-m');
            
            if (!$accountId) {
                throw new Exception('Account ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT * FROM ledger_accounts 
                WHERE account_id = ? AND fiscal_period = ?
            ");
            $stmt->execute([$accountId, $period]);
            $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'ledger' => $ledger,
                'balance' => $ledger ? $ledger['ending_balance'] : 0
            ]);
            break;
            
        case 'get_account_history':
            if (!can_view_accounting($userId)) {
                throw new Exception('Permission denied');
            }
            
            $accountId = $_GET['account_id'] ?? 0;
            $months = intval($_GET['months'] ?? 12);
            
            if (!$accountId) {
                throw new Exception('Account ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT fiscal_period, ending_balance, total_debits, total_credits
                FROM ledger_accounts
                WHERE account_id = ?
                ORDER BY fiscal_period DESC
                LIMIT ?
            ");
            $stmt->execute([$accountId, $months]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'history' => array_reverse($history)
            ]);
            break;
            
        // ============================================
        // JOURNAL ENTRY QUICK OPERATIONS
        // ============================================
        
        case 'get_entry_summary':
            if (!can_view_accounting($userId)) {
                throw new Exception('Permission denied');
            }
            
            $entryId = $_GET['entry_id'] ?? 0;
            
            if (!$entryId) {
                throw new Exception('Entry ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT je.*,
                       creator.first_name as creator_first_name,
                       creator.last_name as creator_last_name,
                       approver.first_name as approver_first_name,
                       approver.last_name as approver_last_name
                FROM journal_entries je
                LEFT JOIN users creator ON creator.id = je.created_by
                LEFT JOIN users approver ON approver.id = je.approved_by
                WHERE je.id = ?
            ");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$entry) {
                throw new Exception('Entry not found');
            }
            
            // Get lines
            $stmt = $pdo->prepare("
                SELECT jel.*, coa.account_code, coa.account_name
                FROM journal_entry_lines jel
                LEFT JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE jel.journal_entry_id = ?
                ORDER BY jel.line_number
            ");
            $stmt->execute([$entryId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'entry' => $entry,
                'lines' => $lines
            ]);
            break;
            
        // ============================================
        // DASHBOARD DATA
        // ============================================
        
        case 'get_dashboard_data':
            if (!can_view_accounting($userId)) {
                throw new Exception('Permission denied');
            }
            
            $months = intval($_GET['months'] ?? 6);
            
            // Get revenue/expense trend
            $trendStmt = $pdo->prepare("
                SELECT 
                    DATE_FORMAT(je.entry_date, '%Y-%m') as period,
                    SUM(CASE WHEN coa.account_type = 'revenue' THEN jel.credit_amount ELSE 0 END) as revenue,
                    SUM(CASE WHEN coa.account_type IN ('expense', 'cogs') THEN jel.debit_amount ELSE 0 END) as expenses
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.status = 'posted'
                AND je.entry_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                GROUP BY period
                ORDER BY period ASC
            ");
            $trendStmt->execute([$months]);
            $trend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate net profit for each period
            foreach ($trend as &$period) {
                $period['netProfit'] = $period['revenue'] - $period['expenses'];
                $period['margin'] = $period['revenue'] > 0 ? 
                    round(($period['netProfit'] / $period['revenue']) * 100, 2) : 0;
            }
            
            // Get expense breakdown
            $expenseStmt = $pdo->prepare("
                SELECT 
                    ec.category_name,
                    SUM(e.amount) as total_amount
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                AND e.approval_status != 'negated'
                GROUP BY e.expense_category_id
                ORDER BY total_amount DESC
                LIMIT 10
            ");
            $expenseStmt->execute();
            $expenseBreakdown = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get commission breakdown
            $commissionStmt = $pdo->prepare("
                SELECT 
                    u.first_name,
                    u.last_name,
                    SUM(c.amount) as total_commission
                FROM commissions c
                LEFT JOIN users u ON u.id = c.user_id
                WHERE c.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                GROUP BY c.user_id
                ORDER BY total_commission DESC
                LIMIT 10
            ");
            $commissionStmt->execute();
            $commissionBreakdown = $commissionStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'trend' => $trend,
                'expense_breakdown' => $expenseBreakdown,
                'commission_breakdown' => $commissionBreakdown
            ]);
            break;
            
        // ============================================
        // 1099 DATA
        // ============================================
        
        case 'calculate_1099_totals':
            if (!can_generate_1099($userId)) {
                throw new Exception('Permission denied');
            }
            
            $year = intval($_GET['year'] ?? date('Y'));
            $threshold = floatval(get_accounting_setting('1099_threshold', 600));
            
            $stmt = $pdo->prepare("
                SELECT 
                    c.user_id as agent_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.tax_id_type,
                    u.tax_id_last4,
                    SUM(c.amount) as total_compensation
                FROM commissions c
                LEFT JOIN users u ON u.id = c.user_id
                WHERE YEAR(c.created_at) = ?
                AND u.is_1099_contractor = 1
                GROUP BY c.user_id
                HAVING total_compensation >= ?
                ORDER BY total_compensation DESC
            ");
            $stmt->execute([$year, $threshold]);
            $contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'year' => $year,
                'threshold' => $threshold,
                'contractors' => $contractors,
                'count' => count($contractors)
            ]);
            break;
            
        // ============================================
        // STATISTICS
        // ============================================
        
        case 'get_stats':
            if (!can_view_accounting($userId)) {
                throw new Exception('Permission denied');
            }
            
            $stats = [];
            
            // Total assets
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(ending_balance), 0) as total
                FROM ledger_accounts la
                JOIN chart_of_accounts coa ON coa.id = la.account_id
                WHERE coa.account_type = 'asset'
                AND la.fiscal_period = DATE_FORMAT(CURDATE(), '%Y-%m')
            ");
            $stats['total_assets'] = $stmt->fetchColumn();
            
            // Total liabilities
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(ending_balance), 0) as total
                FROM ledger_accounts la
                JOIN chart_of_accounts coa ON coa.id = la.account_id
                WHERE coa.account_type = 'liability'
                AND la.fiscal_period = DATE_FORMAT(CURDATE(), '%Y-%m')
            ");
            $stats['total_liabilities'] = $stmt->fetchColumn();
            
            // Equity
            $stats['equity'] = $stats['total_assets'] - $stats['total_liabilities'];
            
            // Pending journal entries
            $stats['pending_entries'] = $pdo->query("
                SELECT COUNT(*) FROM journal_entries WHERE status = 'draft'
            ")->fetchColumn();
            
            // Unmigrated orders
            $stats['unmigrated_orders'] = $pdo->query("
                SELECT COUNT(*) FROM orders 
                WHERE payment_status = 'paid' 
                AND (journal_entry_id IS NULL OR journal_entry_id = 0)
            ")->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'action' => $action
    ]);
    error_log("Unified Accounting API error ({$action}): " . $e->getMessage());
}

