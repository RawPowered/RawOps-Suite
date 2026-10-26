<?php
/**
 * Ledger API - Chart of Accounts Management
 * Handles CRUD operations for chart of accounts, account balances, and transactions
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

// Role check - require accountant or admin
$roles = get_user_roles($userId);
if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions. Accountant or Admin role required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'get_accounts':
            // Get all accounts with optional filters
            $accountType = $_GET['account_type'] ?? null;
            $activeOnly = isset($_GET['active_only']) ? (int)$_GET['active_only'] : 1;
            $includeBalances = isset($_GET['include_balances']) ? (int)$_GET['include_balances'] : 0;
            
            $sql = "
                SELECT coa.*,
                       parent.account_name as parent_name
                FROM chart_of_accounts coa
                LEFT JOIN chart_of_accounts parent ON parent.id = coa.parent_account_id
                WHERE 1=1
            ";
            $params = [];
            
            if ($accountType) {
                $sql .= " AND coa.account_type = ?";
                $params[] = $accountType;
            }
            
            if ($activeOnly) {
                $sql .= " AND coa.is_active = 1";
            }
            
            $sql .= " ORDER BY coa.account_code ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Optionally include current balances
            if ($includeBalances) {
                $currentPeriod = date('Y-m');
                foreach ($accounts as &$account) {
                    $balanceStmt = $pdo->prepare("
                        SELECT ending_balance 
                        FROM ledger_accounts 
                        WHERE account_id = ? AND fiscal_period = ?
                    ");
                    $balanceStmt->execute([$account['id'], $currentPeriod]);
                    $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
                    $account['current_balance'] = $balance ? $balance['ending_balance'] : 0.00;
                }
            }
            
            echo json_encode(['success' => true, 'accounts' => $accounts]);
            break;
            
        case 'create_account':
            // Create new account
            if (!in_array('admin', $roles)) {
                throw new Exception('Only admins can create new accounts');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $required = ['account_code', 'account_name', 'account_type', 'normal_balance'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Field {$field} is required");
                }
            }
            
            // Validate account type
            $validTypes = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'];
            if (!in_array($input['account_type'], $validTypes)) {
                throw new Exception('Invalid account type');
            }
            
            // Validate normal balance
            if (!in_array($input['normal_balance'], ['debit', 'credit'])) {
                throw new Exception('Normal balance must be debit or credit');
            }
            
            // Check for duplicate account code
            $checkStmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
            $checkStmt->execute([$input['account_code']]);
            if ($checkStmt->fetch()) {
                throw new Exception('Account code already exists');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO chart_of_accounts 
                (account_code, account_name, account_type, parent_account_id, is_system, is_active, description, tax_category, normal_balance)
                VALUES (?, ?, ?, ?, 0, 1, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['account_code'],
                $input['account_name'],
                $input['account_type'],
                $input['parent_account_id'] ?? null,
                $input['description'] ?? null,
                $input['tax_category'] ?? null,
                $input['normal_balance']
            ]);
            
            $accountId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Account created successfully',
                'account_id' => $accountId
            ]);
            break;
            
        case 'update_account':
            // Update existing account
            $input = json_decode(file_get_contents('php://input'), true);
            $accountId = $input['account_id'] ?? 0;
            
            if (!$accountId) {
                throw new Exception('Account ID required');
            }
            
            // Check if system account
            $checkStmt = $pdo->prepare("SELECT is_system FROM chart_of_accounts WHERE id = ?");
            $checkStmt->execute([$accountId]);
            $account = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                throw new Exception('Account not found');
            }
            
            if ($account['is_system'] && !in_array('admin', $roles)) {
                throw new Exception('Cannot modify system accounts without admin role');
            }
            
            // Build update query dynamically
            $updates = [];
            $params = [];
            
            $allowedFields = ['account_name', 'description', 'tax_category', 'is_active'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $params[] = $accountId;
            $sql = "UPDATE chart_of_accounts SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Account updated successfully'
            ]);
            break;
            
        case 'get_account_balance':
            // Get account balance for a specific period
            $accountId = $_GET['account_id'] ?? 0;
            $fiscalPeriod = $_GET['fiscal_period'] ?? date('Y-m');
            
            if (!$accountId) {
                throw new Exception('Account ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT la.*, coa.account_name, coa.account_code, coa.normal_balance
                FROM ledger_accounts la
                JOIN chart_of_accounts coa ON coa.id = la.account_id
                WHERE la.account_id = ? AND la.fiscal_period = ?
            ");
            $stmt->execute([$accountId, $fiscalPeriod]);
            $balance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$balance) {
                // No transactions yet, get account info
                $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE id = ?");
                $stmt->execute([$accountId]);
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $balance = [
                    'account_id' => $accountId,
                    'fiscal_period' => $fiscalPeriod,
                    'beginning_balance' => 0.00,
                    'total_debits' => 0.00,
                    'total_credits' => 0.00,
                    'ending_balance' => 0.00,
                    'account_name' => $account['account_name'] ?? '',
                    'account_code' => $account['account_code'] ?? '',
                    'normal_balance' => $account['normal_balance'] ?? 'debit'
                ];
            }
            
            echo json_encode(['success' => true, 'balance' => $balance]);
            break;
            
        case 'get_account_transactions':
            // Get all transactions for an account in a period
            $accountId = $_GET['account_id'] ?? 0;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            
            if (!$accountId) {
                throw new Exception('Account ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT jel.*, 
                       je.entry_number, je.entry_date, je.description as entry_description, 
                       je.status, je.reference_type, je.reference_id,
                       coa.account_name, coa.account_code
                FROM journal_entry_lines jel
                JOIN journal_entries je ON je.id = jel.journal_entry_id
                JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE jel.account_id = ?
                AND je.entry_date BETWEEN ? AND ?
                AND je.status = 'posted'
                ORDER BY je.entry_date DESC, je.id DESC
            ");
            $stmt->execute([$accountId, $startDate, $endDate]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate running balance
            $runningBalance = 0;
            foreach ($transactions as &$trans) {
                if ($trans['debit_amount'] > 0) {
                    $runningBalance += $trans['debit_amount'];
                } else {
                    $runningBalance -= $trans['credit_amount'];
                }
                $trans['running_balance'] = $runningBalance;
            }
            
            echo json_encode([
                'success' => true,
                'transactions' => $transactions,
                'period' => ['start' => $startDate, 'end' => $endDate]
            ]);
            break;
            
        case 'get_hierarchy':
            // Get accounts in hierarchical structure
            $sql = "
                SELECT coa.*,
                       (SELECT COUNT(*) FROM chart_of_accounts WHERE parent_account_id = coa.id) as child_count
                FROM chart_of_accounts coa
                WHERE coa.is_active = 1
                ORDER BY coa.account_code ASC
            ";
            
            $stmt = $pdo->query($sql);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build hierarchy
            $hierarchy = [];
            $accountsById = [];
            
            foreach ($accounts as $account) {
                $accountsById[$account['id']] = $account;
                $accountsById[$account['id']]['children'] = [];
            }
            
            foreach ($accounts as $account) {
                if ($account['parent_account_id']) {
                    $accountsById[$account['parent_account_id']]['children'][] = &$accountsById[$account['id']];
                } else {
                    $hierarchy[] = &$accountsById[$account['id']];
                }
            }
            
            echo json_encode(['success' => true, 'hierarchy' => $hierarchy]);
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





