<?php
/**
 * Accounting Automation - Scheduled Tasks
 * Daily and monthly automated accounting processes
 * 
 * Add to crontab:
 * 0 2 * * * /usr/bin/php /path/to/scheduled/accounting-automation.php daily >> /path/to/logs/accounting-daily.log 2>&1
 * 0 3 1 * * /usr/bin/php /path/to/scheduled/accounting-automation.php monthly >> /path/to/logs/accounting-monthly.log 2>&1
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/expense-automation.php';

// Get task type from command line or query string
$taskType = $argv[1] ?? $_GET['task'] ?? 'daily';

// Allow manual trigger with authentication
if (php_sapi_name() !== 'cli') {
    start_session();
    $userId = current_user_id();
    $roles = get_user_roles($userId);
    
    if (!$userId || (!in_array('admin', $roles) && !in_array('accountant', $roles))) {
        http_response_code(403);
        die('Access denied. Manual trigger requires admin or accountant role.');
    }
}

$pdo = db();
$results = ['task_type' => $taskType, 'timestamp' => date('Y-m-d H:i:s'), 'tasks' => []];

try {
    if ($taskType === 'daily') {
        echo "=== Daily Accounting Automation Started ===\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Task 1: Process recurring subscriptions
        echo "Task 1: Processing recurring subscriptions...\n";
        $subResults = process_recurring_subscriptions();
        $results['tasks']['subscriptions'] = $subResults;
        echo "  Processed: {$subResults['processed']}, Errors: {$subResults['errors']}\n\n";
        
        // Task 2: Aggregate API usage costs
        echo "Task 2: Aggregating API usage costs...\n";
        $apiResults = aggregate_api_usage_costs();
        $results['tasks']['api_costs'] = $apiResults;
        echo "  Services: " . count($apiResults['services']) . ", Total Cost: $" . number_format($apiResults['total_cost'], 2) . "\n";
        echo "  Expenses Created: {$apiResults['expenses_created']}\n\n";
        
        // Task 3: Check for overdue invoices
        echo "Task 3: Checking for overdue invoices...\n";
        $overdueStmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM invoices
            WHERE payment_status != 'paid'
            AND due_date < CURDATE()
        ");
        $overdue = $overdueStmt->fetch(PDO::FETCH_ASSOC);
        $results['tasks']['overdue_invoices'] = $overdue['count'];
        echo "  Overdue invoices: {$overdue['count']}\n\n";
        
        // Task 4: Update invoice payment status
        echo "Task 4: Updating invoice statuses...\n";
        $updateStmt = $pdo->prepare("
            UPDATE invoices 
            SET payment_status = 'overdue'
            WHERE payment_status = 'unpaid'
            AND due_date < CURDATE()
        ");
        $updateStmt->execute();
        $updated = $updateStmt->rowCount();
        $results['tasks']['invoices_marked_overdue'] = $updated;
        echo "  Marked as overdue: {$updated}\n\n";
        
        // Task 5: Check subscription renewals in next 7 days
        echo "Task 5: Checking upcoming subscription renewals...\n";
        $upcomingStmt = $pdo->query("
            SELECT COUNT(*) as count, SUM(amount) as total
            FROM subscription_expenses
            WHERE status = 'active'
            AND next_billing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $upcoming = $upcomingStmt->fetch(PDO::FETCH_ASSOC);
        $results['tasks']['upcoming_subscriptions'] = $upcoming;
        echo "  Upcoming renewals (7 days): {$upcoming['count']}, Amount: $" . number_format($upcoming['total'], 2) . "\n\n";
        
        echo "=== Daily Automation Complete ===\n";
        
    } elseif ($taskType === 'monthly') {
        echo "=== Monthly Accounting Automation Started ===\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
        
        $lastMonth = date('Y-m', strtotime('first day of last month'));
        
        // Task 1: Close previous month's books
        echo "Task 1: Closing fiscal period {$lastMonth}...\n";
        $closeStmt = $pdo->prepare("
            UPDATE ledger_accounts 
            SET period_closed = 1
            WHERE fiscal_period = ?
        ");
        $closeStmt->execute([$lastMonth]);
        $closed = $closeStmt->rowCount();
        $results['tasks']['periods_closed'] = $closed;
        echo "  Accounts closed: {$closed}\n\n";
        
        // Task 2: Calculate estimated taxes for last month
        echo "Task 2: Calculating monthly tax estimates...\n";
        $monthStart = date('Y-m-01', strtotime('first day of last month'));
        $monthEnd = date('Y-m-t', strtotime('last day of last month'));
        
        // Get revenue for last month
        $revenueStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total), 0) as total
            FROM orders
            WHERE created_at BETWEEN ? AND ?
            AND status != 'cancelled'
            AND payment_status = 'paid'
        ");
        $revenueStmt->execute([$monthStart, $monthEnd]);
        $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get expenses for last month
        $expenseStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM expenses
            WHERE expense_date BETWEEN ? AND ?
            AND payment_status = 'paid'
        ");
        $expenseStmt->execute([$monthStart, $monthEnd]);
        $expenses = $expenseStmt->fetch(PDO::FETCH_ASSOC);
        
        $profit = $revenue['total'] - $expenses['total'];
        $estimatedTax = max(0, $profit * 0.21); // 21% corporate rate
        
        $results['tasks']['tax_estimate'] = [
            'period' => $lastMonth,
            'revenue' => $revenue['total'],
            'expenses' => $expenses['total'],
            'profit' => $profit,
            'estimated_tax' => $estimatedTax
        ];
        
        echo "  Revenue: $" . number_format($revenue['total'], 2) . "\n";
        echo "  Expenses: $" . number_format($expenses['total'], 2) . "\n";
        echo "  Profit: $" . number_format($profit, 2) . "\n";
        echo "  Estimated Tax (21%): $" . number_format($estimatedTax, 2) . "\n\n";
        
        // Task 3: Generate month-end summary
        echo "Task 3: Generating month-end summary...\n";
        $summaryStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_entries,
                SUM(CASE WHEN je.status = 'posted' THEN 1 ELSE 0 END) as posted_entries,
                (SELECT COUNT(*) FROM expenses WHERE MONTH(expense_date) = MONTH(?) AND YEAR(expense_date) = YEAR(?)) as expense_count,
                (SELECT COUNT(*) FROM orders WHERE MONTH(created_at) = MONTH(?) AND YEAR(created_at) = YEAR(?) AND status != 'cancelled') as order_count
            FROM journal_entries je
            WHERE je.fiscal_period = ?
        ");
        $summaryStmt->execute([$monthStart, $monthStart, $monthStart, $monthStart, $lastMonth]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        $results['tasks']['month_summary'] = $summary;
        
        echo "  Journal Entries: {$summary['total_entries']} (Posted: {$summary['posted_entries']})\n";
        echo "  Expenses Recorded: {$summary['expense_count']}\n";
        echo "  Orders Processed: {$summary['order_count']}\n\n";
        
        echo "=== Monthly Automation Complete ===\n";
    }
    
    // Log results to operations_log
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO operations_log (log_level, log_category, log_message, context_data, created_at)
            VALUES ('info', 'accounting_automation', ?, ?, NOW())
        ");
        $logStmt->execute([
            "Accounting automation {$taskType} completed",
            json_encode($results)
        ]);
    } catch (Exception $e) {
        echo "Warning: Could not log to operations_log: " . $e->getMessage() . "\n";
    }
    
    // Output results as JSON if called via web
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($results);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $results['error'] = $e->getMessage();
    
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode($results);
    }
}





