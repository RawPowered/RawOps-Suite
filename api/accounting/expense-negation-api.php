<?php
/**
 * Expense Negation API
 * Create reversing journal entries for negated expenses
 * Proper accounting method - maintains audit trail
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
require_once __DIR__ . '/../../required/accounting-hooks.php';

start_session();

// Authentication & permission check
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if (!can_negate_expenses($userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. Accountant or Admin role required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'negate_expense':
            $input = json_decode(file_get_contents('php://input'), true);
            $expenseId = $input['expense_id'] ?? 0;
            $negationReason = $input['negation_reason'] ?? '';
            
            if (!$expenseId) {
                throw new Exception('Expense ID required');
            }
            
            if (empty($negationReason)) {
                throw new Exception('Negation reason is required');
            }
            
            $pdo->beginTransaction();
            
            // Get expense details
            $stmt = $pdo->prepare("
                SELECT e.*, 
                       ec.category_name,
                       ec.account_id as expense_account_id
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.id = ?
            ");
            $stmt->execute([$expenseId]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$expense) {
                throw new Exception('Expense not found');
            }
            
            // Check if already negated
            if ($expense['approval_status'] === 'negated') {
                throw new Exception('Expense has already been negated');
            }
            
            // Check if expense has posted journal entry
            if ($expense['journal_entry_id']) {
                $jeStmt = $pdo->prepare("SELECT status FROM journal_entries WHERE id = ?");
                $jeStmt->execute([$expense['journal_entry_id']]);
                $jeStatus = $jeStmt->fetchColumn();
                
                if ($jeStatus !== 'posted') {
                    throw new Exception('Only expenses with posted journal entries can be negated');
                }
                
                // Create reversing journal entry
                $entryNumber = generate_journal_entry_number('REV');
                $fiscalPeriod = date('Y-m');
                
                $stmt = $pdo->prepare("
                    INSERT INTO journal_entries 
                    (entry_number, entry_date, entry_type, description, reference_type, reference_id, 
                     fiscal_period, status, is_auto_generated, is_reversal, reversal_entry_id, reversal_reason, created_by)
                    VALUES (?, CURDATE(), 'reversing', ?, 'expense', ?, ?, 'draft', 1, 1, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $entryNumber,
                    "REVERSAL: {$expense['description']} - Reason: {$negationReason}",
                    $expenseId,
                    $fiscalPeriod,
                    $expense['journal_entry_id'],
                    $negationReason,
                    $userId
                ]);
                
                $reversalEntryId = $pdo->lastInsertId();
                
                // Get original journal entry lines
                $stmt = $pdo->prepare("
                    SELECT * FROM journal_entry_lines 
                    WHERE journal_entry_id = ?
                    ORDER BY line_number
                ");
                $stmt->execute([$expense['journal_entry_id']]);
                $originalLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Create reversed lines (swap debits and credits)
                $lineNumber = 1;
                foreach ($originalLines as $line) {
                    insert_journal_line(
                        $pdo,
                        $reversalEntryId,
                        $lineNumber++,
                        $line['account_id'],
                        $line['credit_amount'], // Swap: original credit becomes debit
                        $line['debit_amount'],  // Swap: original debit becomes credit
                        "REVERSAL: " . ($line['description'] ?? '')
                    );
                }
                
                // Auto-post the reversal entry
                $stmt = $pdo->prepare("
                    UPDATE journal_entries 
                    SET status = 'posted', approved_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([$userId, $reversalEntryId]);
                
                // Update ledger
                update_ledger_from_entry($reversalEntryId, $fiscalPeriod);
                
                // Update expense record
                $stmt = $pdo->prepare("
                    UPDATE expenses 
                    SET approval_status = 'negated',
                        negated_by = ?,
                        negated_at = CURRENT_TIMESTAMP,
                        negation_reason = ?,
                        reversal_journal_entry_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$userId, $negationReason, $reversalEntryId, $expenseId]);
                
            } else {
                // No journal entry yet, just mark as negated
                $stmt = $pdo->prepare("
                    UPDATE expenses 
                    SET approval_status = 'negated',
                        negated_by = ?,
                        negated_at = CURRENT_TIMESTAMP,
                        negation_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$userId, $negationReason, $expenseId]);
                
                $reversalEntryId = null;
            }
            
            // Log the negation
            $stmt = $pdo->prepare("
                INSERT INTO expense_approval_log 
                (expense_id, action, performed_by, notes, previous_status, new_status)
                VALUES (?, 'negated', ?, ?, ?, 'negated')
            ");
            $stmt->execute([
                $expenseId,
                $userId,
                $negationReason,
                $expense['approval_status']
            ]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense negated successfully',
                'reversal_entry_id' => $reversalEntryId,
                'expense_id' => $expenseId
            ]);
            break;
            
        case 'undo_negation':
            // Un-negate an expense (admin only)
            if (!in_array('admin', get_user_roles($userId))) {
                throw new Exception('Only admins can undo negations');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $expenseId = $input['expense_id'] ?? 0;
            $reason = $input['reason'] ?? '';
            
            if (!$expenseId) {
                throw new Exception('Expense ID required');
            }
            
            if (empty($reason)) {
                throw new Exception('Reason for undo is required');
            }
            
            $pdo->beginTransaction();
            
            // Get expense details
            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$expenseId]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$expense) {
                throw new Exception('Expense not found');
            }
            
            if ($expense['approval_status'] !== 'negated') {
                throw new Exception('Expense is not currently negated');
            }
            
            // Void the reversal entry if it exists
            if ($expense['reversal_journal_entry_id']) {
                $stmt = $pdo->prepare("
                    UPDATE journal_entries 
                    SET status = 'void',
                        voided_by = ?,
                        voided_at = CURRENT_TIMESTAMP,
                        void_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $userId,
                    "Undo negation: {$reason}",
                    $expense['reversal_journal_entry_id']
                ]);
            }
            
            // Restore expense to previous status
            $stmt = $pdo->prepare("
                UPDATE expenses 
                SET approval_status = 'approved',
                    negated_by = NULL,
                    negated_at = NULL,
                    negation_reason = NULL,
                    reversal_journal_entry_id = NULL
                WHERE id = ?
            ");
            $stmt->execute([$expenseId]);
            
            // Log the undo
            $stmt = $pdo->prepare("
                INSERT INTO expense_approval_log 
                (expense_id, action, performed_by, notes, previous_status, new_status)
                VALUES (?, 'un-negated', ?, ?, 'negated', 'approved')
            ");
            $stmt->execute([$expenseId, $userId, $reason]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Negation undone successfully'
            ]);
            break;
            
        case 'get_negated_expenses':
            // Get list of negated expenses
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $limit = min(intval($_GET['limit'] ?? 50), 500);
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $pdo->prepare("
                SELECT e.*,
                       ec.category_name,
                       negator.first_name as negated_by_first,
                       negator.last_name as negated_by_last,
                       je.entry_number as reversal_entry_number
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                LEFT JOIN users negator ON negator.id = e.negated_by
                LEFT JOIN journal_entries je ON je.id = e.reversal_journal_entry_id
                WHERE e.approval_status = 'negated'
                AND e.expense_date BETWEEN ? AND ?
                ORDER BY e.negated_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            
            $stmt->execute([$startDate, $endDate]);
            $negatedExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'expenses' => $negatedExpenses,
                'count' => count($negatedExpenses)
            ]);
            break;
            
        case 'get_negation_history':
            // Get negation history for specific expense
            $expenseId = $_GET['expense_id'] ?? 0;
            
            if (!$expenseId) {
                throw new Exception('Expense ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT l.*,
                       u.first_name,
                       u.last_name
                FROM expense_approval_log l
                LEFT JOIN users u ON u.id = l.performed_by
                WHERE l.expense_id = ?
                AND l.action IN ('negated', 'un-negated')
                ORDER BY l.created_at DESC
            ");
            
            $stmt->execute([$expenseId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'history' => $history
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log("Expense Negation API error: " . $e->getMessage());
}












