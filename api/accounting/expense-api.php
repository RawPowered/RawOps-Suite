<?php
/**
 * Expense API - Universal Expense Tracking
 * Handles expense CRUD, allocations, subscriptions, and API usage tracking
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/expense-automation.php';

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
$canManageExpenses = in_array('accountant', $roles) || in_array('admin', $roles) || in_array('operations', $roles);

if (!$canManageExpenses) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'get_categories':
            // Get all expense categories
            $stmt = $pdo->query("
                SELECT id, category_name, description
                FROM expense_categories
                WHERE is_active = 1
                ORDER BY category_name ASC
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'categories' => $categories
            ]);
            break;
            
        case 'quick_log_expense':
            // Quick expense logging with file upload and supplier linking
            $expenseDate = $_POST['expense_date'] ?? '';
            $amount = $_POST['amount'] ?? '';
            $vendorName = $_POST['vendor_name'] ?? '';
            $description = $_POST['description'] ?? '';
            $categoryId = $_POST['expense_category_id'] ?? '';
            $supplierId = $_POST['supplier_id'] ?? null; // Optional pre-selected supplier
            
            if (!$expenseDate || !$amount || !$vendorName || !$description || !$categoryId) {
                throw new Exception('All fields are required');
            }
            
            // Handle file upload
            $receiptPath = null;
            if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt_file'];
                
                // Validate PDF
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if ($mimeType !== 'application/pdf') {
                    throw new Exception('Only PDF files are allowed');
                }
                
                if ($file['size'] > 10 * 1024 * 1024) {
                    throw new Exception('File size must be less than 10MB');
                }
                
                // Create receipts directory if it doesn't exist
                $receiptsDir = __DIR__ . '/../../admin/accounting/documents/receipts/' . date('Y') . '/' . date('m');
                if (!is_dir($receiptsDir)) {
                    mkdir($receiptsDir, 0755, true);
                }
                
                // Generate unique filename
                $fileName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME)) . '.pdf';
                $filePath = $receiptsDir . '/' . $fileName;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $receiptPath = 'documents/receipts/' . date('Y') . '/' . date('m') . '/' . $fileName;
                } else {
                    throw new Exception('Failed to upload receipt file');
                }
            }
            
            $pdo->beginTransaction();
            
            // Create expense record
            $stmt = $pdo->prepare("
                INSERT INTO expenses 
                (expense_date, expense_category_id, amount, vendor_name, description, supplier_id,
                 reference_type, allocation_method, payment_status, approval_status, receipt_file_path, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'manual', 'overhead', 'unpaid', 'pending', ?, ?)
            ");
            
            $stmt->execute([
                $expenseDate,
                $categoryId,
                $amount,
                $vendorName,
                $description,
                $supplierId,
                $receiptPath,
                $userId
            ]);
            
            $expenseId = $pdo->lastInsertId();
            
            // Supplier matching logic
            $matchInfo = null;
            if ($supplierId) {
                // Direct supplier selection - create confirmed link
                $linkStmt = $pdo->prepare("
                    INSERT INTO supplier_expenses_link 
                    (expense_id, supplier_id, match_type, original_vendor_name, verification_status, verified_by, verified_at)
                    VALUES (?, ?, 'manual', ?, 'confirmed', ?, NOW())
                ");
                $linkStmt->execute([$expenseId, $supplierId, $vendorName, $userId]);
                $matchInfo = ['type' => 'manual', 'supplier_id' => $supplierId];
            } else {
                // Try fuzzy matching
                require_once __DIR__ . '/supplier-matching-helper.php';
                $matches = findSupplierMatches($vendorName, $pdo);
                
                if (!empty($matches) && $matches[0]['confidence'] >= 80) {
                    // High confidence match - create pending link for verification
                    $bestMatch = $matches[0];
                    $linkStmt = $pdo->prepare("
                        INSERT INTO supplier_expenses_link 
                        (expense_id, supplier_id, match_type, match_confidence, original_vendor_name, 
                         match_algorithm, verification_status)
                        VALUES (?, ?, 'fuzzy', ?, ?, ?, 'pending')
                    ");
                    $linkStmt->execute([
                        $expenseId,
                        $bestMatch['supplier_id'],
                        $bestMatch['confidence'],
                        $vendorName,
                        $bestMatch['match_algorithm']
                    ]);
                    
                    $matchInfo = [
                        'type' => 'fuzzy',
                        'supplier_id' => $bestMatch['supplier_id'],
                        'supplier_name' => $bestMatch['supplier_name'],
                        'confidence' => $bestMatch['confidence'],
                        'requires_verification' => true
                    ];
                } elseif (!empty($matches)) {
                    // Lower confidence - return suggestions without auto-linking
                    $matchInfo = [
                        'type' => 'suggestions',
                        'suggestions' => array_slice($matches, 0, 3)
                    ];
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense logged successfully',
                'expense_id' => $expenseId,
                'receipt_path' => $receiptPath,
                'supplier_match' => $matchInfo
            ]);
            break;
        
        case 'create_expense':
            $input = json_decode(file_get_contents('php://input'), true);
            
            $required = ['expense_date', 'expense_category_id', 'amount', 'description'];
            foreach ($required as $field) {
                if (!isset($input[$field]) || $input[$field] === '') {
                    throw new Exception("Field {$field} is required");
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO expenses 
                (expense_date, expense_category_id, amount, vendor_name, description, reference_type, reference_id, 
                 allocation_method, is_recurring, recurrence_pattern, payment_status, payment_date, payment_method, 
                 receipt_file_path, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['expense_date'],
                $input['expense_category_id'],
                $input['amount'],
                $input['vendor_name'] ?? null,
                $input['description'],
                $input['reference_type'] ?? null,
                $input['reference_id'] ?? null,
                $input['allocation_method'] ?? 'overhead',
                $input['is_recurring'] ?? 0,
                isset($input['recurrence_pattern']) ? json_encode($input['recurrence_pattern']) : null,
                $input['payment_status'] ?? 'unpaid',
                $input['payment_date'] ?? null,
                $input['payment_method'] ?? null,
                $input['receipt_file_path'] ?? null,
                $userId
            ]);
            
            $expenseId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense created successfully',
                'expense_id' => $expenseId
            ]);
            break;
            
        case 'update_expense':
            $input = json_decode(file_get_contents('php://input'), true);
            $expenseId = $input['expense_id'] ?? 0;
            
            if (!$expenseId) {
                throw new Exception('Expense ID required');
            }
            
            // Build dynamic update
            $updates = [];
            $params = [];
            
            $allowedFields = [
                'expense_date', 'expense_category_id', 'amount', 'vendor_name', 'description',
                'reference_type', 'reference_id', 'allocation_method', 'payment_status',
                'payment_date', 'payment_method', 'receipt_file_path'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $params[] = $expenseId;
            $sql = "UPDATE expenses SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense updated successfully'
            ]);
            break;
            
        case 'delete_expense':
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can delete expenses');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $expenseId = $input['expense_id'] ?? 0;
            
            if (!$expenseId) {
                throw new Exception('Expense ID required');
            }
            
            // Check if expense has been allocated or has journal entry
            $checkStmt = $pdo->prepare("
                SELECT journal_entry_id FROM expenses WHERE id = ?
            ");
            $checkStmt->execute([$expenseId]);
            $expense = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($expense && $expense['journal_entry_id']) {
                throw new Exception('Cannot delete expense with posted journal entry. Void the journal entry first.');
            }
            
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->execute([$expenseId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ]);
            break;
            
        case 'get_expenses':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $categoryId = $_GET['category_id'] ?? null;
            $paymentStatus = $_GET['payment_status'] ?? null;
            $limit = min(intval($_GET['limit'] ?? 100), 1000);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "
                SELECT e.*,
                       ec.category_name,
                       coa.account_name, coa.account_code
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.expense_category_id
                JOIN chart_of_accounts coa ON coa.id = ec.account_id
                WHERE e.expense_date BETWEEN ? AND ?
            ";
            $params = [$startDate, $endDate];
            
            if ($categoryId) {
                $sql .= " AND e.expense_category_id = ?";
                $params[] = $categoryId;
            }
            
            if ($paymentStatus) {
                $sql .= " AND e.payment_status = ?";
                $params[] = $paymentStatus;
            }
            
            $sql .= " ORDER BY e.expense_date DESC, e.id DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total for period
            $totalStmt = $pdo->prepare("
                SELECT SUM(amount) as total
                FROM expenses
                WHERE expense_date BETWEEN ? AND ?
            ");
            $totalStmt->execute([$startDate, $endDate]);
            $total = $totalStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'expenses' => $expenses,
                'total_amount' => $total['total'] ?? 0,
                'filters' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'category_id' => $categoryId,
                    'payment_status' => $paymentStatus
                ]
            ]);
            break;
            
        case 'allocate_expense':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['expense_id']) || empty($input['allocations'])) {
                throw new Exception('Expense ID and allocations are required');
            }
            
            $pdo->beginTransaction();
            
            // Delete existing allocations
            $deleteStmt = $pdo->prepare("DELETE FROM expense_allocations WHERE expense_id = ?");
            $deleteStmt->execute([$input['expense_id']]);
            
            // Insert new allocations
            $insertStmt = $pdo->prepare("
                INSERT INTO expense_allocations 
                (expense_id, allocation_type, target_id, allocated_amount, allocation_percentage, allocation_notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($input['allocations'] as $allocation) {
                $insertStmt->execute([
                    $input['expense_id'],
                    $allocation['allocation_type'],
                    $allocation['target_id'],
                    $allocation['allocated_amount'],
                    $allocation['allocation_percentage'] ?? null,
                    $allocation['allocation_notes'] ?? null
                ]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense allocated successfully'
            ]);
            break;
            
        case 'track_api_usage':
            $input = json_decode(file_get_contents('php://input'), true);
            
            $required = ['api_service', 'endpoint', 'estimated_cost_per_call'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    throw new Exception("Field {$field} is required");
                }
            }
            
            $result = track_api_usage(
                $input['api_service'],
                $input['endpoint'],
                $input['estimated_cost_per_call'],
                $input['user_id'] ?? $userId,
                $input['session_id'] ?? session_id(),
                $input['metadata'] ?? null
            );
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'API usage tracked successfully'
                ]);
            } else {
                throw new Exception('Failed to track API usage');
            }
            break;
            
        case 'create_subscription':
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can create subscriptions');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $required = ['subscription_name', 'vendor_name', 'category_id', 'amount', 'billing_cycle', 'start_date', 'next_billing_date'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Field {$field} is required");
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO subscription_expenses 
                (subscription_name, vendor_name, category_id, amount, billing_cycle, start_date, next_billing_date,
                 auto_renew, payment_method, account_number_last4, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ");
            
            $stmt->execute([
                $input['subscription_name'],
                $input['vendor_name'],
                $input['category_id'],
                $input['amount'],
                $input['billing_cycle'],
                $input['start_date'],
                $input['next_billing_date'],
                $input['auto_renew'] ?? 1,
                $input['payment_method'] ?? null,
                $input['account_number_last4'] ?? null,
                $input['notes'] ?? null,
                $userId
            ]);
            
            $subscriptionId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Subscription created successfully',
                'subscription_id' => $subscriptionId
            ]);
            break;
            
        case 'update_subscription':
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can update subscriptions');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $subscriptionId = $input['subscription_id'] ?? 0;
            
            if (!$subscriptionId) {
                throw new Exception('Subscription ID required');
            }
            
            $updates = [];
            $params = [];
            
            $allowedFields = [
                'subscription_name', 'vendor_name', 'category_id', 'amount', 'billing_cycle',
                'next_billing_date', 'auto_renew', 'payment_method', 'account_number_last4',
                'status', 'cancellation_date', 'notes'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $params[] = $subscriptionId;
            $sql = "UPDATE subscription_expenses SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Subscription updated successfully'
            ]);
            break;
            
        case 'get_subscriptions':
            $status = $_GET['status'] ?? 'active';
            
            $sql = "
                SELECT se.*,
                       ec.category_name,
                       DATEDIFF(se.next_billing_date, CURDATE()) as days_until_billing
                FROM subscription_expenses se
                JOIN expense_categories ec ON ec.id = se.category_id
                WHERE 1=1
            ";
            $params = [];
            
            if ($status) {
                $sql .= " AND se.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY se.next_billing_date ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate total monthly cost
            $monthlyTotal = 0;
            foreach ($subscriptions as $sub) {
                if ($sub['status'] === 'active') {
                    switch ($sub['billing_cycle']) {
                        case 'monthly':
                            $monthlyTotal += $sub['amount'];
                            break;
                        case 'quarterly':
                            $monthlyTotal += $sub['amount'] / 3;
                            break;
                        case 'annual':
                            $monthlyTotal += $sub['amount'] / 12;
                            break;
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'subscriptions' => $subscriptions,
                'monthly_cost_estimate' => round($monthlyTotal, 2)
            ]);
            break;
            
        case 'get_categories':
            $stmt = $pdo->query("
                SELECT ec.*, coa.account_code, coa.account_name
                FROM expense_categories ec
                JOIN chart_of_accounts coa ON coa.id = ec.account_id
                WHERE ec.is_active = 1
                ORDER BY ec.category_name ASC
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'categories' => $categories
            ]);
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





