<?php
/**
 * Sample Request Email Automation - Scheduled Task
 * 
 * Run this script hourly via cron to:
 * - Check for delivered samples based on estimated delivery dates
 * - Trigger "delivered" email workflows
 * - Create follow-up tasks for agents
 * - Check for overdue loan grace periods
 * 
 * Cron example: 0 * * * * /usr/bin/php /path/to/scheduled/sample-email-automation.php
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/agent-loan-utils.php';
require_once __DIR__ . '/../required/sample-suite-helpers.php';

// Prevent running from web browser (allow LiteSpeed cron)
$allowedSapis = ['cli', 'litespeed', 'cgi-fcgi'];
if (!in_array(php_sapi_name(), $allowedSapis, true) && empty($_ENV['PRODUCT_CRON_BYPASS'])) {
    die('This script must be run from the command line or cron.');
}

$pdo = db();
$summary = [
    'processed' => 0,
    'delivered_emails' => 0,
    'followup_tasks' => 0,
    'overdue_checks' => 0,
    'errors' => []
];

echo "Sample Email Automation Starting at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 70) . "\n";

try {
    // ============================================
    // 1. Check for samples that should be delivered
    // ============================================
    
    echo "\n[1] Checking for delivered samples...\n";
    
    $stmt = $pdo->query("
        SELECT sr.id, sr.status, ss.estimated_delivery_date, ss.actual_delivery_date
        FROM sample_requests sr
        JOIN sample_shipments ss ON ss.sample_request_id = sr.id
        WHERE sr.status = 'shipped'
        AND ss.delivery_status = 'in_transit'
        AND ss.estimated_delivery_date <= NOW()
        AND ss.actual_delivery_date IS NULL
    ");
    
    $deliveredSamples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($deliveredSamples as $sample) {
        try {
            // Mark as delivered
            $updateStmt = $pdo->prepare("
                UPDATE sample_requests 
                SET status = 'delivered', delivered_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$sample['id']]);
            
            $updateStmt = $pdo->prepare("
                UPDATE sample_shipments
                SET actual_delivery_date = NOW(), delivery_status = 'delivered', updated_at = NOW()
                WHERE sample_request_id = ?
            ");
            $updateStmt->execute([$sample['id']]);
            
            // Trigger email workflow (would integrate with actual email system)
            echo "  - Sample #{$sample['id']}: Marked as delivered\n";
            
            // Create follow-up task
            create_sample_followup_task($pdo, $sample['id']);
            
            sample_suite_handle_event($pdo, 'delivered', intval($sample['id']), [
                'delivered_at' => date('Y-m-d H:i:s')
            ]);
            
            $summary['delivered_emails']++;
            $summary['followup_tasks']++;
            
        } catch (Exception $e) {
            $summary['errors'][] = "Sample #{$sample['id']}: " . $e->getMessage();
            echo "  ERROR: Sample #{$sample['id']} - " . $e->getMessage() . "\n";
        }
        
        $summary['processed']++;
    }
    
    echo "  Processed {$summary['delivered_emails']} delivered samples\n";
    
    // ============================================
    // 2. Check for follow-up tasks that are due
    // ============================================
    
    echo "\n[2] Checking for follow-up tasks due today...\n";
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM crm_tasks
        WHERE task_type = 'follow_up'
        AND DATE(due_date) = CURDATE()
        AND status = 'pending'
    ");
    
    $dueTasks = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  Found {$dueTasks['count']} tasks due today\n";
    
    // ============================================
    // 3. Check for overdue loan grace periods
    // ============================================
    
    echo "\n[3] Checking for overdue loan grace periods...\n";
    
    $graceCheckResult = check_overdue_grace_periods($pdo);
    echo "  Checked {$graceCheckResult['checked']} loans\n";
    echo "  Marked {$graceCheckResult['forced_deductions']} as overdue\n";
    
    $summary['overdue_checks'] = $graceCheckResult['checked'];
    
    // ============================================
    // 4. Summary
    // ============================================
    
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "SUMMARY:\n";
    echo "  - Samples processed: {$summary['processed']}\n";
    echo "  - Delivery emails sent: {$summary['delivered_emails']}\n";
    echo "  - Follow-up tasks created: {$summary['followup_tasks']}\n";
    echo "  - Overdue loans checked: {$summary['overdue_checks']}\n";
    echo "  - Errors: " . count($summary['errors']) . "\n";
    
    if (!empty($summary['errors'])) {
        echo "\nErrors:\n";
        foreach ($summary['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
    
    echo "\nCompleted at " . date('Y-m-d H:i:s') . "\n";
    
    // Log to database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO scheduled_task_logs (task_name, execution_time, status, summary, created_at)
            VALUES ('sample_email_automation', NOW(), 'completed', ?, NOW())
        ");
        $stmt->execute([json_encode($summary)]);
    } catch (Exception $e) {
        // Table might not exist yet, just log to error_log
        error_log("Sample automation completed: " . json_encode($summary));
    }
    
} catch (Throwable $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Sample email automation fatal error: " . $e->getMessage());
    exit(1);
}

exit(0);

/**
 * Create CRM follow-up task for agent
 * Duplicated here to avoid complex requires
 */
function create_sample_followup_task(PDO $pdo, int $requestId): void {
    try {
        // Check if task already exists
        $stmt = $pdo->prepare("
            SELECT id FROM crm_tasks 
            WHERE task_type = 'follow_up' 
            AND title LIKE CONCAT('%', (
                SELECT CONCAT('sample delivery - ', COALESCE(l.company_name, wa.business_name, sr.recipient_name))
                FROM sample_requests sr
                LEFT JOIN crm_leads l ON l.id = sr.lead_id
                LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
                WHERE sr.id = ?
            ), '%')
            LIMIT 1
        ");
        $stmt->execute([$requestId]);
        if ($stmt->fetch()) {
            return; // Task already exists
        }
        
        // Get request and agent details
        $stmt = $pdo->prepare('
            SELECT sr.agent_id, sr.recipient_name, sr.lead_id, sr.account_id,
                   l.company_name as lead_company_name,
                   wa.business_name as account_business_name
            FROM sample_requests sr
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
            WHERE sr.id = ?
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            return;
        }
        
        $companyName = $request['lead_company_name'] ?: $request['account_business_name'] ?: $request['recipient_name'];
        
        // Create task due 3 days from now
        $stmt = $pdo->prepare('
            INSERT INTO crm_tasks (
                agent_id, lead_id, account_id, task_type, title,
                description, due_date, priority, status, created_at
            ) VALUES (?, ?, ?, "follow_up", ?, ?, DATE_ADD(NOW(), INTERVAL 3 DAY), "normal", "pending", NOW())
        ');
        $stmt->execute([
            $request['agent_id'],
            $request['lead_id'],
            $request['account_id'],
            'Follow up on sample delivery - ' . $companyName,
            'Check in with customer about their sample experience. Discuss their feedback and explore wholesale pricing options.'
        ]);
        
    } catch (Throwable $e) {
        throw $e;
    }
}




