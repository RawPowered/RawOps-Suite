<?php
/**
 * Journal API - Double-Entry Bookkeeping Management
 * Handles journal entries, posting, voiding, and balanced validation
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
if (!in_array('accountant', $roles) && !in_array('admin', $roles) && !in_array('operations', $roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'create_entry':
            // Create new journal entry (draft status)
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['entry_date']) || empty($input['description']) || empty($input['lines'])) {
                throw new Exception('Entry date, description, and lines are required');
            }
            
            if (count($input['lines']) < 2) {
                throw new Exception('Journal entry must have at least 2 lines');
            }
            
            // Validate balanced entry
            $totalDebits = 0;
            $totalCredits = 0;
            
            foreach ($input['lines'] as $line) {
                if (empty($line['account_id'])) {
                    throw new Exception('All lines must have an account');
                }
                
                $debit = floatval($line['debit_amount'] ?? 0);
                $credit = floatval($line['credit_amount'] ?? 0);
                
                if ($debit > 0 && $credit > 0) {
                    throw new Exception('A line cannot have both debit and credit amounts');
                }
                
                if ($debit <= 0 && $credit <= 0) {
                    throw new Exception('Each line must have either a debit or credit amount');
                }
                
                $totalDebits += $debit;
                $totalCredits += $credit;
            }
            
            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new Exception("Entry not balanced. Debits: {$totalDebits}, Credits: {$totalCredits}");
            }
            
            $pdo->beginTransaction();
            
            // Generate entry number
            $entryNumber = $input['entry_number'] ?? ('JE-' . date('Y') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT));
            
            // Check for duplicate entry number
            $checkStmt = $pdo->prepare("SELECT id FROM journal_entries WHERE entry_number = ?");
            $checkStmt->execute([$entryNumber]);
            if ($checkStmt->fetch()) {
                $entryNumber = 'JE-' . date('Y') . '-' . uniqid();
            }
            
            $fiscalPeriod = date('Y-m', strtotime($input['entry_date']));
            
            // Create journal entry
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries 
                (entry_number, entry_date, entry_type, description, reference_type, reference_id, fiscal_period, status, is_auto_generated, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 0, ?)
            ");
            
            $stmt->execute([
                $entryNumber,
                $input['entry_date'],
                $input['entry_type'] ?? 'standard',
                $input['description'],
                $input['reference_type'] ?? null,
                $input['reference_id'] ?? null,
                $fiscalPeriod,
                $userId
            ]);
            
            $journalEntryId = $pdo->lastInsertId();
            
            // Insert lines
            $lineNumber = 1;
            $lineStmt = $pdo->prepare("
                INSERT INTO journal_entry_lines 
                (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description, reference_details)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($input['lines'] as $line) {
                $lineStmt->execute([
                    $journalEntryId,
                    $lineNumber++,
                    $line['account_id'],
                    $line['debit_amount'] ?? 0,
                    $line['credit_amount'] ?? 0,
                    $line['description'] ?? null,
                    isset($line['reference_details']) ? json_encode($line['reference_details']) : null
                ]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Journal entry created successfully',
                'entry_id' => $journalEntryId,
                'entry_number' => $entryNumber
            ]);
            break;
            
        case 'post_entry':
            // Post a draft entry (makes it permanent)
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can post entries');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $entryId = $input['entry_id'] ?? 0;
            
            if (!$entryId) {
                throw new Exception('Entry ID required');
            }
            
            $pdo->beginTransaction();
            
            // Get entry details
            $stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ?");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$entry) {
                throw new Exception('Entry not found');
            }
            
            if ($entry['status'] !== 'draft') {
                throw new Exception('Only draft entries can be posted');
            }
            
            // Verify balance one more time
            $stmt = $pdo->prepare("
                SELECT SUM(debit_amount) as total_debits, SUM(credit_amount) as total_credits
                FROM journal_entry_lines
                WHERE journal_entry_id = ?
            ");
            $stmt->execute([$entryId]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (abs($totals['total_debits'] - $totals['total_credits']) > 0.01) {
                throw new Exception('Entry is not balanced');
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
            
            echo json_encode([
                'success' => true,
                'message' => 'Journal entry posted successfully'
            ]);
            break;
            
        case 'void_entry':
            // Void a posted entry (keeps for audit trail)
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can void entries');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $entryId = $input['entry_id'] ?? 0;
            $voidReason = $input['void_reason'] ?? '';
            
            if (!$entryId) {
                throw new Exception('Entry ID required');
            }
            
            if (empty($voidReason)) {
                throw new Exception('Void reason is required');
            }
            
            $pdo->beginTransaction();
            
            // Get entry
            $stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ?");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$entry) {
                throw new Exception('Entry not found');
            }
            
            if ($entry['status'] === 'void') {
                throw new Exception('Entry is already voided');
            }
            
            // Void the entry
            $stmt = $pdo->prepare("
                UPDATE journal_entries 
                SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $voidReason, $entryId]);
            
            // Reverse the ledger impact
            reverse_ledger_from_entry($entryId, $entry['fiscal_period']);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Journal entry voided successfully'
            ]);
            break;
            
        case 'get_entries':
            // Get journal entries with filters
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $status = $_GET['status'] ?? null;
            $referenceType = $_GET['reference_type'] ?? null;
            $limit = min(intval($_GET['limit'] ?? 100), 1000);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "
                SELECT je.*,
                       creator.first_name as creator_first_name, creator.last_name as creator_last_name,
                       approver.first_name as approver_first_name, approver.last_name as approver_last_name,
                       (SELECT SUM(debit_amount) FROM journal_entry_lines WHERE journal_entry_id = je.id) as total_amount
                FROM journal_entries je
                LEFT JOIN users creator ON creator.id = je.created_by
                LEFT JOIN users approver ON approver.id = je.approved_by
                WHERE je.entry_date BETWEEN ? AND ?
            ";
            $params = [$startDate, $endDate];
            
            if ($status) {
                $sql .= " AND je.status = ?";
                $params[] = $status;
            }
            
            if ($referenceType) {
                $sql .= " AND je.reference_type = ?";
                $params[] = $referenceType;
            }
            
            $sql .= " ORDER BY je.entry_date DESC, je.id DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'entries' => $entries,
                'filters' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $status,
                    'reference_type' => $referenceType
                ]
            ]);
            break;
            
        case 'get_entry_detail':
            // Get full entry with all lines
            $entryId = $_GET['entry_id'] ?? 0;
            
            if (!$entryId) {
                throw new Exception('Entry ID required');
            }
            
            // Get entry header
            $stmt = $pdo->prepare("
                SELECT je.*,
                       creator.first_name as creator_first_name, creator.last_name as creator_last_name,
                       approver.first_name as approver_first_name, approver.last_name as approver_last_name,
                       voider.first_name as voider_first_name, voider.last_name as voider_last_name
                FROM journal_entries je
                LEFT JOIN users creator ON creator.id = je.created_by
                LEFT JOIN users approver ON approver.id = je.approved_by
                LEFT JOIN users voider ON voider.id = je.voided_by
                WHERE je.id = ?
            ");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$entry) {
                throw new Exception('Entry not found');
            }
            
            // Get entry lines
            $stmt = $pdo->prepare("
                SELECT jel.*,
                       coa.account_code, coa.account_name, coa.account_type
                FROM journal_entry_lines jel
                JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE jel.journal_entry_id = ?
                ORDER BY jel.line_number ASC
            ");
            $stmt->execute([$entryId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $entry['lines'] = $lines;
            
            // Calculate totals
            $entry['total_debits'] = array_sum(array_column($lines, 'debit_amount'));
            $entry['total_credits'] = array_sum(array_column($lines, 'credit_amount'));
            $entry['is_balanced'] = abs($entry['total_debits'] - $entry['total_credits']) < 0.01;
            
            echo json_encode(['success' => true, 'entry' => $entry]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Update ledger accounts from a posted journal entry
 */
function update_ledger_from_entry($entryId, $fiscalPeriod) {
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

/**
 * Reverse ledger impact from a voided journal entry
 */
function reverse_ledger_from_entry($entryId, $fiscalPeriod) {
    $pdo = db();
    
    // Get all lines for this entry
    $stmt = $pdo->prepare("SELECT * FROM journal_entry_lines WHERE journal_entry_id = ?");
    $stmt->execute([$entryId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($lines as $line) {
        // Reverse the amounts
        $updateStmt = $pdo->prepare("
            UPDATE ledger_accounts 
            SET total_debits = total_debits - ?,
                total_credits = total_credits - ?,
                ending_balance = beginning_balance + total_debits - total_credits
            WHERE account_id = ? AND fiscal_period = ?
        ");
        $updateStmt->execute([
            $line['debit_amount'],
            $line['credit_amount'],
            $line['account_id'],
            $fiscalPeriod
        ]);
    }
}





