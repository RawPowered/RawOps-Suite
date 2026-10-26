<?php
/**
 * Migration API - Historical Data Backfill
 * Execute one-time import of all existing transactions into accounting system
 * Handles orders, commissions, production costs, and API usage
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

if (!can_run_data_sync($userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. Admin or Accountant role required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'migrate_orders':
            // Migrate all paid orders to journal entries
            $input = json_decode(file_get_contents('php://input'), true);
            $startDate = $input['start_date'] ?? '2020-01-01';
            $endDate = $input['end_date'] ?? date('Y-m-d');
            $limit = min(intval($input['limit'] ?? 100), 1000);
            
            // Create sync log
            $logStmt = $pdo->prepare("
                INSERT INTO accounting_sync_log 
                (sync_type, sync_status, initiated_by, sync_parameters)
                VALUES ('orders', 'running', ?, ?)
            ");
            $logStmt->execute([$userId, json_encode(['start_date' => $startDate, 'end_date' => $endDate])]);
            $syncLogId = $pdo->lastInsertId();
            
            // Get orders without journal entries
            $stmt = $pdo->prepare("
                SELECT id 
                FROM orders 
                WHERE payment_status = 'paid'
                AND created_at BETWEEN ? AND ?
                AND (journal_entry_id IS NULL OR journal_entry_id = 0)
                ORDER BY created_at ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$startDate, $endDate]);
            $orders = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $processed = 0;
            $created = 0;
            $failed = 0;
            $errors = [];
            
            foreach ($orders as $orderId) {
                $processed++;
                try {
                    $journalEntryId = accounting_hook_order_payment($orderId, $userId);
                    if ($journalEntryId) {
                        $created++;
                    } else {
                        $failed++;
                        $errors[] = "Order {$orderId}: Failed to create entry";
                    }
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = "Order {$orderId}: " . $e->getMessage();
                }
            }
            
            // Update sync log
            $updateStmt = $pdo->prepare("
                UPDATE accounting_sync_log 
                SET sync_status = 'completed',
                    records_processed = ?,
                    records_created = ?,
                    records_failed = ?,
                    error_log = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([
                $processed,
                $created,
                $failed,
                $failed > 0 ? json_encode($errors) : null,
                $syncLogId
            ]);
            
            echo json_encode([
                'success' => true,
                'sync_log_id' => $syncLogId,
                'processed' => $processed,
                'created' => $created,
                'failed' => $failed,
                'errors' => array_slice($errors, 0, 10), // Limit error output
                'has_more' => count($orders) === $limit
            ]);
            break;
            
        case 'migrate_commissions':
            // Migrate all commissions to journal entries
            $input = json_decode(file_get_contents('php://input'), true);
            $startDate = $input['start_date'] ?? '2020-01-01';
            $endDate = $input['end_date'] ?? date('Y-m-d');
            $limit = min(intval($input['limit'] ?? 100), 1000);
            
            // Create sync log
            $logStmt = $pdo->prepare("
                INSERT INTO accounting_sync_log 
                (sync_type, sync_status, initiated_by, sync_parameters)
                VALUES ('commissions', 'running', ?, ?)
            ");
            $logStmt->execute([$userId, json_encode(['start_date' => $startDate, 'end_date' => $endDate])]);
            $syncLogId = $pdo->lastInsertId();
            
            // Get commissions without journal entries
            $stmt = $pdo->prepare("
                SELECT id 
                FROM commissions 
                WHERE created_at BETWEEN ? AND ?
                AND (journal_entry_id IS NULL OR journal_entry_id = 0)
                ORDER BY created_at ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$startDate, $endDate]);
            $commissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $processed = 0;
            $created = 0;
            $failed = 0;
            $errors = [];
            
            foreach ($commissions as $commissionId) {
                $processed++;
                try {
                    $journalEntryId = accounting_hook_commission_created($commissionId, $userId);
                    if ($journalEntryId) {
                        $created++;
                    } else {
                        $failed++;
                        $errors[] = "Commission {$commissionId}: Failed to create entry";
                    }
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = "Commission {$commissionId}: " . $e->getMessage();
                }
            }
            
            // Update sync log
            $updateStmt = $pdo->prepare("
                UPDATE accounting_sync_log 
                SET sync_status = 'completed',
                    records_processed = ?,
                    records_created = ?,
                    records_failed = ?,
                    error_log = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([
                $processed,
                $created,
                $failed,
                $failed > 0 ? json_encode($errors) : null,
                $syncLogId
            ]);
            
            echo json_encode([
                'success' => true,
                'sync_log_id' => $syncLogId,
                'processed' => $processed,
                'created' => $created,
                'failed' => $failed,
                'errors' => array_slice($errors, 0, 10),
                'has_more' => count($commissions) === $limit
            ]);
            break;
            
        case 'migrate_api_usage':
            // Batch migrate API usage by month
            $input = json_decode(file_get_contents('php://input'), true);
            $month = $input['month'] ?? date('Y-m');
            
            // Create sync log
            $logStmt = $pdo->prepare("
                INSERT INTO accounting_sync_log 
                (sync_type, sync_status, initiated_by, sync_parameters)
                VALUES ('api_usage', 'running', ?, ?)
            ");
            $logStmt->execute([$userId, json_encode(['month' => $month])]);
            $syncLogId = $pdo->lastInsertId();
            
            try {
                $expenseCount = accounting_hook_api_usage_batch($month, $userId);
                
                $updateStmt = $pdo->prepare("
                    UPDATE accounting_sync_log 
                    SET sync_status = 'completed',
                        records_processed = 1,
                        records_created = ?,
                        completed_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updateStmt->execute([$expenseCount ?? 0, $syncLogId]);
                
                echo json_encode([
                    'success' => true,
                    'sync_log_id' => $syncLogId,
                    'expenses_created' => $expenseCount ?? 0,
                    'month' => $month
                ]);
                
            } catch (Exception $e) {
                $updateStmt = $pdo->prepare("
                    UPDATE accounting_sync_log 
                    SET sync_status = 'failed',
                        records_failed = 1,
                        error_log = ?,
                        completed_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updateStmt->execute([json_encode([$e->getMessage()]), $syncLogId]);
                
                throw $e;
            }
            break;
            
        case 'get_migration_status':
            // Get status of all migrations
            $stmt = $pdo->query("
                SELECT 
                    sync_type,
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN sync_status = 'completed' THEN 1 ELSE 0 END) as completed_runs,
                    SUM(records_processed) as total_processed,
                    SUM(records_created) as total_created,
                    SUM(records_failed) as total_failed,
                    MAX(completed_at) as last_run
                FROM accounting_sync_log
                GROUP BY sync_type
                ORDER BY sync_type
            ");
            $status = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get counts of unmigrated records
            $unmigratedOrders = $pdo->query("
                SELECT COUNT(*) FROM orders 
                WHERE payment_status = 'paid' 
                AND (journal_entry_id IS NULL OR journal_entry_id = 0)
            ")->fetchColumn();
            
            $unmigratedCommissions = $pdo->query("
                SELECT COUNT(*) FROM commissions 
                WHERE (journal_entry_id IS NULL OR journal_entry_id = 0)
            ")->fetchColumn();
            
            $unmigratedApiUsage = $pdo->query("
                SELECT COUNT(DISTINCT DATE_FORMAT(usage_date, '%Y-%m')) 
                FROM api_usage_tracking 
                WHERE expense_created = 0
            ")->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'sync_status' => $status,
                'unmigrated' => [
                    'orders' => $unmigratedOrders,
                    'commissions' => $unmigratedCommissions,
                    'api_usage_months' => $unmigratedApiUsage
                ]
            ]);
            break;
            
        case 'get_sync_log':
            // Get detailed sync log
            $limit = min(intval($_GET['limit'] ?? 50), 500);
            $offset = intval($_GET['offset'] ?? 0);
            $syncType = $_GET['sync_type'] ?? null;
            
            $sql = "
                SELECT s.*, u.first_name, u.last_name
                FROM accounting_sync_log s
                LEFT JOIN users u ON u.id = s.initiated_by
            ";
            
            if ($syncType) {
                $sql .= " WHERE s.sync_type = ?";
            }
            
            $sql .= " ORDER BY s.started_at DESC LIMIT {$limit} OFFSET {$offset}";
            
            if ($syncType) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$syncType]);
            } else {
                $stmt = $pdo->query($sql);
            }
            
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'logs' => $logs
            ]);
            break;
            
        case 'rollback_sync':
            // Rollback a specific sync operation
            if (!in_array('admin', get_user_roles($userId))) {
                throw new Exception('Only admins can rollback sync operations');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $syncLogId = $input['sync_log_id'] ?? 0;
            
            if (!$syncLogId) {
                throw new Exception('Sync log ID required');
            }
            
            // Get sync log details
            $stmt = $pdo->prepare("SELECT * FROM accounting_sync_log WHERE id = ?");
            $stmt->execute([$syncLogId]);
            $syncLog = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$syncLog) {
                throw new Exception('Sync log not found');
            }
            
            $pdo->beginTransaction();
            
            // Void all journal entries created in this sync
            // This is a destructive operation - use with caution
            $params = json_decode($syncLog['sync_parameters'], true);
            
            $voided = 0;
            
            if ($syncLog['sync_type'] === 'orders') {
                $voidStmt = $pdo->prepare("
                    UPDATE journal_entries 
                    SET status = 'void',
                        voided_by = ?,
                        voided_at = CURRENT_TIMESTAMP,
                        void_reason = ?
                    WHERE reference_type = 'order'
                    AND entry_date BETWEEN ? AND ?
                    AND is_auto_generated = 1
                    AND status = 'draft'
                ");
                $voidStmt->execute([
                    $userId,
                    "Rollback of sync #{$syncLogId}",
                    $params['start_date'] ?? '2020-01-01',
                    $params['end_date'] ?? date('Y-m-d')
                ]);
                $voided = $voidStmt->rowCount();
                
                // Clear journal_entry_id from orders
                $clearStmt = $pdo->prepare("
                    UPDATE orders 
                    SET journal_entry_id = NULL,
                        accounting_status = 'pending'
                    WHERE journal_entry_id IN (
                        SELECT id FROM journal_entries 
                        WHERE status = 'void' 
                        AND voided_by = ?
                    )
                ");
                $clearStmt->execute([$userId]);
            }
            
            // Mark sync as cancelled
            $updateStmt = $pdo->prepare("
                UPDATE accounting_sync_log 
                SET sync_status = 'cancelled'
                WHERE id = ?
            ");
            $updateStmt->execute([$syncLogId]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Rollback completed. {$voided} entries voided.",
                'voided_count' => $voided
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log("Migration API error: " . $e->getMessage());
}












