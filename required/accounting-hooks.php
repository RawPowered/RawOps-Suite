<?php
/**
 * Accounting Automation Hooks
 * Automatically create journal entries for business transactions
 * All entries created as draft for review, auto-post based on settings
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting-permissions.php';

if (!function_exists('accounting_hook_order_payment')) {
    /**
     * Create journal entry when order is paid
     * DR: Cash, CR: Revenue, CR: Sales Tax Payable
     */
    function accounting_hook_order_payment(int $orderId, int $userId = null): ?int {
        try {
            $pdo = db();
            $userId = $userId ?? commission_find_developer_user_id($pdo);
            
            // Get order details
            $stmt = $pdo->prepare("
                SELECT id, total, payment_status, created_at, account_id,
                       COALESCE(tax_amount, 0) as tax_amount,
                       (total - COALESCE(tax_amount, 0)) as subtotal
                FROM orders 
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order || $order['payment_status'] !== 'paid') {
                return null;
            }
            
            // Check if already has journal entry
            if (!empty($order['journal_entry_id'])) {
                return $order['journal_entry_id'];
            }
            
            // Get account IDs
            $cashAccount = get_account_id_by_code('1110'); // Cash
            $revenueAccount = get_account_id_by_code('4110'); // Wholesale Sales
            $salesTaxAccount = get_account_id_by_code('2130'); // Sales Tax Payable
            $arAccount = get_account_id_by_code('1120'); // Accounts Receivable
            
            if (!$cashAccount || !$revenueAccount) {
                throw new Exception("Required accounts not found in chart of accounts");
            }
            
            $pdo->beginTransaction();
            
            // Create journal entry
            $entryNumber = generate_journal_entry_number('ORD');
            $fiscalPeriod = date('Y-m', strtotime($order['created_at']));
            
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries 
                (entry_number, entry_date, entry_type, description, reference_type, reference_id, 
                 fiscal_period, status, is_auto_generated, created_by)
                VALUES (?, CURDATE(), 'standard', ?, 'order', ?, ?, 'draft', 1, ?)
            ");
            
            $stmt->execute([
                $entryNumber,
                "Order #{$orderId} - Revenue Recognition",
                $orderId,
                $fiscalPeriod,
                $userId
            ]);
            
            $journalEntryId = $pdo->lastInsertId();
            
            // Create journal entry lines
            $lineNumber = 1;
            
            // DR: Cash (or AR if not yet paid)
            insert_journal_line($pdo, $journalEntryId, $lineNumber++, $cashAccount, $order['total'], 0, "Cash received - Order #{$orderId}");
            
            // CR: Revenue
            insert_journal_line($pdo, $journalEntryId, $lineNumber++, $revenueAccount, 0, $order['subtotal'], "Sales revenue - Order #{$orderId}");
            
            // CR: Sales Tax Payable (if applicable)
            if ($order['tax_amount'] > 0 && $salesTaxAccount) {
                insert_journal_line($pdo, $journalEntryId, $lineNumber++, $salesTaxAccount, 0, $order['tax_amount'], "Sales tax collected - Order #{$orderId}");
            }
            
            // Link journal entry to order
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET journal_entry_id = ?, 
                    revenue_recognition_date = CURDATE(),
                    accounting_status = 'posted'
                WHERE id = ?
            ");
            $stmt->execute([$journalEntryId, $orderId]);
            
            // Auto-post if setting enabled
            if (get_accounting_setting('auto_post_order_entries', false)) {
                post_journal_entry($journalEntryId, $userId);
            }
            
            $pdo->commit();
            
            return $journalEntryId;
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Order payment hook failed for order {$orderId}: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('accounting_hook_commission_created')) {
    /**
     * Create journal entry for commission expense
     * DR: Commission Expense, CR: Commission Payable
     */
    function accounting_hook_commission_created(int $commissionId, int $userId = null): ?int {
        try {
            $pdo = db();
            $userId = $userId ?? commission_find_developer_user_id($pdo);
            
            // Get commission details
            $stmt = $pdo->prepare("
                SELECT c.*, u.first_name, u.last_name
                FROM commissions c
                LEFT JOIN users u ON u.id = c.user_id
                WHERE c.id = ?
            ");
            $stmt->execute([$commissionId]);
            $commission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$commission) {
                return null;
            }
            
            // Check if already has journal entry
            if (!empty($commission['journal_entry_id'])) {
                return $commission['journal_entry_id'];
            }
            
            // Get account IDs
            $commExpenseAccount = get_account_id_by_code('6110'); // Sales Agent Commissions
            $commPayableAccount = get_account_id_by_code('2120'); // Commission Payable
            
            if (!$commExpenseAccount || !$commPayableAccount) {
                throw new Exception("Commission accounts not found in chart of accounts");
            }
            
            $pdo->beginTransaction();
            
            // Create journal entry
            $entryNumber = generate_journal_entry_number('COM');
            $fiscalPeriod = date('Y-m', strtotime($commission['created_at']));
            
            $agentName = trim($commission['first_name'] . ' ' . $commission['last_name']);
            
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries 
                (entry_number, entry_date, entry_type, description, reference_type, reference_id, 
                 fiscal_period, status, is_auto_generated, created_by)
                VALUES (?, CURDATE(), 'standard', ?, 'commission', ?, ?, 'draft', 1, ?)
            ");
            
            $stmt->execute([
                $entryNumber,
                "Commission - {$agentName} - Order #{$commission['order_id']}",
                $commissionId,
                $fiscalPeriod,
                $userId
            ]);
            
            $journalEntryId = $pdo->lastInsertId();
            
            // Create journal entry lines
            $lineNumber = 1;
            
            // DR: Commission Expense
            insert_journal_line($pdo, $journalEntryId, $lineNumber++, $commExpenseAccount, $commission['amount'], 0, "Commission expense - {$agentName}");
            
            // CR: Commission Payable
            insert_journal_line($pdo, $journalEntryId, $lineNumber++, $commPayableAccount, 0, $commission['amount'], "Commission payable - {$agentName}");
            
            // Link journal entry to commission
            $stmt = $pdo->prepare("
                UPDATE commissions 
                SET journal_entry_id = ?,
                    expense_recognition_date = CURDATE(),
                    accounting_status = 'posted'
                WHERE id = ?
            ");
            $stmt->execute([$journalEntryId, $commissionId]);
            
            // Auto-post if setting enabled
            if (get_accounting_setting('auto_post_commission_entries', false)) {
                post_journal_entry($journalEntryId, $userId);
            }
            
            $pdo->commit();
            
            return $journalEntryId;
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Commission hook failed for commission {$commissionId}: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('accounting_hook_production_costs')) {
    /**
     * Create journal entry for production/work order costs
     * DR: COGS (Ingredients, Packaging, Materials, Labor), CR: Inventory/Cash
     */
    function accounting_hook_production_costs(int $workOrderId, array $costs, int $userId = null): ?int {
        try {
            $pdo = db();
            $userId = $userId ?? commission_find_developer_user_id($pdo);
            
            if (empty($costs)) {
                return null;
            }
            
            // Get account IDs
            $ingredientCogsAccount = get_account_id_by_code('5100'); // Ingredient Costs
            $packagingCogsAccount = get_account_id_by_code('5200'); // Packaging Costs
            $materialCogsAccount = get_account_id_by_code('5300'); // Materials Costs
            $laborCogsAccount = get_account_id_by_code('5400'); // Direct Labor
            $cashAccount = get_account_id_by_code('1110'); // Cash
            
            $pdo->beginTransaction();
            
            // Create journal entry
            $entryNumber = generate_journal_entry_number('WO');
            $fiscalPeriod = date('Y-m');
            
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries 
                (entry_number, entry_date, entry_type, description, reference_type, reference_id, 
                 fiscal_period, status, is_auto_generated, created_by)
                VALUES (?, CURDATE(), 'standard', ?, 'work_order', ?, ?, 'draft', 1, ?)
            ");
            
            $stmt->execute([
                $entryNumber,
                "Production Costs - Work Order #{$workOrderId}",
                $workOrderId,
                $fiscalPeriod,
                $userId
            ]);
            
            $journalEntryId = $pdo->lastInsertId();
            
            // Create journal entry lines
            $lineNumber = 1;
            $totalDebits = 0;
            
            // DR: Various COGS accounts
            if (!empty($costs['ingredients'])) {
                insert_journal_line($pdo, $journalEntryId, $lineNumber++, $ingredientCogsAccount, $costs['ingredients'], 0, "Ingredient costs - WO #{$workOrderId}");
                $totalDebits += $costs['ingredients'];
            }
            
            if (!empty($costs['packaging'])) {
                insert_journal_line($pdo, $journalEntryId, $lineNumber++, $packagingCogsAccount, $costs['packaging'], 0, "Packaging costs - WO #{$workOrderId}");
                $totalDebits += $costs['packaging'];
            }
            
            if (!empty($costs['materials'])) {
                insert_journal_line($pdo, $journalEntryId, $lineNumber++, $materialCogsAccount, $costs['materials'], 0, "Material costs - WO #{$workOrderId}");
                $totalDebits += $costs['materials'];
            }
            
            if (!empty($costs['labor'])) {
                insert_journal_line($pdo, $journalEntryId, $lineNumber++, $laborCogsAccount, $costs['labor'], 0, "Labor costs - WO #{$workOrderId}");
                $totalDebits += $costs['labor'];
            }
            
            // CR: Cash (total costs paid)
            insert_journal_line($pdo, $journalEntryId, $lineNumber++, $cashAccount, 0, $totalDebits, "Production costs paid - WO #{$workOrderId}");
            
            // Link journal entry to work order
            $stmt = $pdo->prepare("
                UPDATE work_orders 
                SET total_allocated_costs = ?,
                    accounting_status = 'posted'
                WHERE id = ?
            ");
            $stmt->execute([$totalDebits, $workOrderId]);
            
            // Auto-post if setting enabled
            if (get_accounting_setting('auto_post_production_entries', false)) {
                post_journal_entry($journalEntryId, $userId);
            }
            
            $pdo->commit();
            
            return $journalEntryId;
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Production costs hook failed for WO {$workOrderId}: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('accounting_hook_api_usage_batch')) {
    /**
     * Create expense entry for batched API usage costs
     * Called monthly to batch all API costs
     */
    function accounting_hook_api_usage_batch(string $month, int $userId = null): ?int {
        try {
            $pdo = db();
            $userId = $userId ?? commission_find_developer_user_id($pdo);
            
            // Get total API costs for the month
            $stmt = $pdo->prepare("
                SELECT api_service, SUM(total_cost) as total_cost, COUNT(*) as call_count
                FROM api_usage_tracking
                WHERE DATE_FORMAT(usage_date, '%Y-%m') = ?
                AND expense_created = 0
                GROUP BY api_service
            ");
            $stmt->execute([$month]);
            $apiCosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($apiCosts)) {
                return null;
            }
            
            // Get expense category for API Usage
            $stmt = $pdo->prepare("
                SELECT id FROM expense_categories WHERE category_name = 'API Usage' LIMIT 1
            ");
            $stmt->execute();
            $categoryId = $stmt->fetchColumn();
            
            if (!$categoryId) {
                throw new Exception("API Usage expense category not found");
            }
            
            $pdo->beginTransaction();
            
            // Create expense for each API service
            $expenseIds = [];
            
            foreach ($apiCosts as $cost) {
                $stmt = $pdo->prepare("
                    INSERT INTO expenses 
                    (expense_date, expense_category_id, amount, vendor_name, description, 
                     reference_type, reference_id, allocation_method, payment_status, approval_status, created_by)
                    VALUES (LAST_DAY(?), ?, ?, ?, ?, 'api_usage', NULL, 'overhead', 'paid', 'approved', ?)
                ");
                
                $stmt->execute([
                    $month . '-01',
                    $categoryId,
                    $cost['total_cost'],
                    ucfirst($cost['api_service']),
                    "{$cost['api_service']} API usage - {$cost['call_count']} calls - {$month}",
                    $userId
                ]);
                
                $expenseIds[] = $pdo->lastInsertId();
            }
            
            // Mark API usage records as expense created
            $stmt = $pdo->prepare("
                UPDATE api_usage_tracking
                SET expense_created = 1
                WHERE DATE_FORMAT(usage_date, '%Y-%m') = ?
            ");
            $stmt->execute([$month]);
            
            $pdo->commit();
            
            return count($expenseIds);
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("API usage batch hook failed for {$month}: " . $e->getMessage());
            return null;
        }
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

if (!function_exists('get_account_id_by_code')) {
    function get_account_id_by_code(string $code): ?int {
        static $cache = [];
        
        if (isset($cache[$code])) {
            return $cache[$code];
        }
        
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$code]);
            $id = $stmt->fetchColumn();
            
            $cache[$code] = $id ? (int)$id : null;
            return $cache[$code];
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('generate_journal_entry_number')) {
    function generate_journal_entry_number(string $prefix = 'JE'): string {
        return strtoupper($prefix) . '-' . date('Y') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('insert_journal_line')) {
    function insert_journal_line(PDO $pdo, int $journalEntryId, int $lineNumber, int $accountId, float $debit, float $credit, string $description = null): void {
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines 
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $journalEntryId,
            $lineNumber,
            $accountId,
            $debit,
            $credit,
            $description
        ]);
    }
}

if (!function_exists('post_journal_entry')) {
    function post_journal_entry(int $entryId, int $userId): bool {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Get entry details
            $stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ?");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$entry || $entry['status'] !== 'draft') {
                return false;
            }
            
            // Verify balance
            $stmt = $pdo->prepare("
                SELECT SUM(debit_amount) as total_debits, SUM(credit_amount) as total_credits
                FROM journal_entry_lines
                WHERE journal_entry_id = ?
            ");
            $stmt->execute([$entryId]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (abs($totals['total_debits'] - $totals['total_credits']) > 0.01) {
                throw new Exception('Entry not balanced');
            }
            
            // Post the entry
            $stmt = $pdo->prepare("
                UPDATE journal_entries 
                SET status = 'posted', approved_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $entryId]);
            
            // Update ledger accounts
            update_ledger_from_entry($entryId, $entry['fiscal_period']);
            
            $pdo->commit();
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Failed to post journal entry {$entryId}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('update_ledger_from_entry')) {
    function update_ledger_from_entry(int $entryId, string $fiscalPeriod): void {
        $pdo = db();
        
        // Get all lines for this entry
        $stmt = $pdo->prepare("SELECT * FROM journal_entry_lines WHERE journal_entry_id = ?");
        $stmt->execute([$entryId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($lines as $line) {
            // Get or create ledger account for this period
            $ledgerStmt = $pdo->prepare("
                SELECT * FROM ledger_accounts 
                WHERE account_id = ? AND fiscal_period = ?
            ");
            $ledgerStmt->execute([$line['account_id'], $fiscalPeriod]);
            $ledger = $ledgerStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ledger) {
                // Update existing
                $updateStmt = $pdo->prepare("
                    UPDATE ledger_accounts 
                    SET total_debits = total_debits + ?,
                        total_credits = total_credits + ?,
                        ending_balance = beginning_balance + total_debits - total_credits,
                        last_transaction_date = CURDATE()
                    WHERE id = ?
                ");
                $updateStmt->execute([$line['debit_amount'], $line['credit_amount'], $ledger['id']]);
            } else {
                // Create new ledger entry
                $insertStmt = $pdo->prepare("
                    INSERT INTO ledger_accounts 
                    (account_id, fiscal_period, beginning_balance, total_debits, total_credits, ending_balance, last_transaction_date)
                    VALUES (?, ?, 0, ?, ?, ? - ?, CURDATE())
                ");
                $insertStmt->execute([
                    $line['account_id'],
                    $fiscalPeriod,
                    $line['debit_amount'],
                    $line['credit_amount'],
                    $line['debit_amount'],
                    $line['credit_amount']
                ]);
            }
        }
    }
}

