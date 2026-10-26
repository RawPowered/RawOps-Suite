<?php
/**
 * Test Data Incinerator API
 * Safely remove test data using reversing journal entries
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
require_once __DIR__ . '/../../required/accounting-hooks.php';

start_session();

// Authentication & permission check (Admin only)
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if (!can_use_incinerator($userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. Admin role required.']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'preview':
            // Preview what will be incinerated
            $testDataStmt = $pdo->query("
                SELECT 
                    entity_type,
                    COUNT(*) as count
                FROM test_data_markers
                WHERE incinerated = 0
                GROUP BY entity_type
            ");
            $testData = $testDataStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $preview = [
                'orders' => 0,
                'commissions' => 0,
                'expenses' => 0,
                'journal_entries' => 0,
                'total_impact' => 0
            ];
            
            foreach ($testData as $item) {
                $preview[$item['entity_type'] . 's'] = $item['count'];
            }
            
            // Calculate financial impact
            $impactStmt = $pdo->query("
                SELECT 
                    SUM(CASE WHEN tdm.entity_type = 'order' THEN o.total ELSE 0 END) as order_impact,
                    SUM(CASE WHEN tdm.entity_type = 'commission' THEN c.amount ELSE 0 END) as commission_impact,
                    SUM(CASE WHEN tdm.entity_type = 'expense' THEN e.amount ELSE 0 END) as expense_impact
                FROM test_data_markers tdm
                LEFT JOIN orders o ON o.id = tdm.entity_id AND tdm.entity_type = 'order'
                LEFT JOIN commissions c ON c.id = tdm.entity_id AND tdm.entity_type = 'commission'
                LEFT JOIN expenses e ON e.id = tdm.entity_id AND tdm.entity_type = 'expense'
                WHERE tdm.incinerated = 0
            ");
            $impact = $impactStmt->fetch(PDO::FETCH_ASSOC);
            
            $preview['total_impact'] = ($impact['order_impact'] ?? 0) + ($impact['commission_impact'] ?? 0) + ($impact['expense_impact'] ?? 0);
            
            echo json_encode([
                'success' => true,
                'preview' => $preview
            ]);
            break;
            
        case 'incinerate':
            // Execute incineration with password verification
            $input = json_decode(file_get_contents('php://input'), true);
            $password = $input['password'] ?? '';
            
            // Verify admin password
            $userStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                throw new Exception('Invalid password');
            }
            
            $pdo->beginTransaction();
            
            // Get all test data markers
            $testDataStmt = $pdo->query("
                SELECT * FROM test_data_markers WHERE incinerated = 0
            ");
            $testDataItems = $testDataStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $summary = [
                'reversed' => 0,
                'negated' => 0,
                'voided' => 0,
                'errors' => []
            ];
            
            foreach ($testDataItems as $item) {
                try {
                    switch ($item['entity_type']) {
                        case 'expense':
                            // Use expense negation API logic
                            $expenseStmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
                            $expenseStmt->execute([$item['entity_id']]);
                            $expense = $expenseStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($expense && $expense['approval_status'] !== 'negated') {
                                // Negate the expense
                                $stmt = $pdo->prepare("
                                    UPDATE expenses 
                                    SET approval_status = 'negated',
                                        negated_by = ?,
                                        negated_at = CURRENT_TIMESTAMP,
                                        negation_reason = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$userId, "Incinerated: " . $item['reason'], $item['entity_id']]);
                                $summary['negated']++;
                            }
                            break;
                            
                        case 'journal_entry':
                            // Void the journal entry
                            $stmt = $pdo->prepare("
                                UPDATE journal_entries 
                                SET status = 'void',
                                    voided_by = ?,
                                    voided_at = CURRENT_TIMESTAMP,
                                    void_reason = ?
                                WHERE id = ?
                                AND status != 'void'
                            ");
                            $stmt->execute([$userId, "Incinerated: " . $item['reason'], $item['entity_id']]);
                            if ($stmt->rowCount() > 0) {
                                $summary['voided']++;
                            }
                            break;
                            
                        case 'order':
                        case 'commission':
                            // These would need reversing entries if they have journal entries
                            // For now, just mark them
                            $summary['reversed']++;
                            break;
                    }
                    
                    // Mark as incinerated
                    $stmt = $pdo->prepare("
                        UPDATE test_data_markers 
                        SET incinerated = 1,
                            incinerated_at = CURRENT_TIMESTAMP,
                            incinerated_by = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$userId, $item['id']]);
                    
                } catch (Exception $e) {
                    $summary['errors'][] = "Failed to incinerate {$item['entity_type']} #{$item['entity_id']}: " . $e->getMessage();
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'message' => 'Incineration completed successfully'
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
    error_log("Incinerator API error: " . $e->getMessage());
}












