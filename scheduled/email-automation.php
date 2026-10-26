<?php
/**
 * Email Automation - Scheduled Task
 * 
 * Run this script hourly via cron to:
 * - Evaluate emails for automatic follow-up workflows
 * - Process and send scheduled follow-up emails
 * 
 * Cron example: 0 * * * * /usr/bin/php /path/to/scheduled/email-automation.php
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../api/email-workflow-processor.php';
require_once __DIR__ . '/../api/sendgrid.php';

// Prevent running from web browser (allow LiteSpeed cron)
$allowedSapis = ['cli', 'litespeed', 'cgi-fcgi'];
if (!in_array(php_sapi_name(), $allowedSapis, true) && empty($_ENV['PRODUCT_CRON_BYPASS'])) {
    die('This script must be run from the command line or cron.');
}

$pdo = db();
$summary = [
    'workflows_evaluated' => 0,
    'workflows_scheduled' => 0,
    'workflows_skipped' => 0,
    'followups_sent' => 0,
    'followups_failed' => 0,
    'errors' => []
];

// Setup logging to file
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cron-email-automation.log';
$logHandle = @fopen($logFile, 'a');

function log_output($message, $logHandle = null) {
    echo $message;
    if ($logHandle) {
        fwrite($logHandle, $message);
    }
}

log_output("Email Automation Starting at " . date('Y-m-d H:i:s') . " (SAPI: " . php_sapi_name() . ")\n", $logHandle);
log_output(str_repeat('=', 70) . "\n", $logHandle);

try {
    // ============================================
    // 1. Process email workflows (evaluate emails for follow-ups)
    // ============================================
    
    log_output("\n[1] Evaluating emails for workflow triggers...\n", $logHandle);
    
    try {
        $workflowStats = process_email_workflows($pdo);
        
        $summary['workflows_evaluated'] = $workflowStats['evaluated'];
        $summary['workflows_scheduled'] = $workflowStats['scheduled'];
        $summary['workflows_skipped'] = $workflowStats['skipped'];
        
        log_output("  Evaluated: {$workflowStats['evaluated']} emails\n", $logHandle);
        log_output("  Scheduled: {$workflowStats['scheduled']} follow-ups\n", $logHandle);
        log_output("  Skipped: {$workflowStats['skipped']} (no workflow match or disabled)\n", $logHandle);
        
        if ($workflowStats['errors'] > 0) {
            log_output("  Errors: {$workflowStats['errors']}\n", $logHandle);
            $summary['errors'][] = "Workflow evaluation errors: {$workflowStats['errors']}";
        }
    } catch (Throwable $e) {
        $errorMsg = "Workflow evaluation failed: " . $e->getMessage();
        $summary['errors'][] = $errorMsg;
        log_output("  ERROR: {$errorMsg}\n", $logHandle);
        error_log($errorMsg);
    }
    
    // ============================================
    // 2. Process scheduled follow-ups (send them)
    // ============================================
    
    log_output("\n[2] Processing scheduled follow-up emails...\n", $logHandle);
    
    $stmt = $pdo->prepare('
        SELECT * FROM email_followup_schedule 
        WHERE status = "pending" 
          AND scheduled_for <= NOW() 
        ORDER BY scheduled_for ASC 
        LIMIT 50
    ');
    $stmt->execute();
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    log_output("  Found " . count($followups) . " follow-ups ready to send\n", $logHandle);
    
    // Requeue any stalled follow-ups that were stuck in processing
    $stalled = $pdo->prepare('UPDATE email_followup_schedule SET status = "pending", cancel_reason = NULL WHERE status = "processing" AND (processed_at IS NULL OR processed_at < DATE_SUB(NOW(), INTERVAL 2 HOUR))');
    $stalled->execute();
    if ($stalled->rowCount() > 0) {
        log_output("  Requeued {$stalled->rowCount()} stalled follow-up jobs\n", $logHandle);
    }
    
    foreach ($followups as $followup) {
        try {
            $result = send_scheduled_followup($pdo, $followup);
            if ($result['ok']) {
                $summary['followups_sent']++;
                log_output("  ✅ Sent follow-up #{$followup['id']} (parent email #{$followup['parent_email_id']})\n", $logHandle);
            } else {
                $summary['followups_failed']++;
                $errorMsg = "Follow-up #{$followup['id']}: " . ($result['error'] ?? 'Unknown error');
                $summary['errors'][] = $errorMsg;
                log_output("  ❌ Failed: {$errorMsg}\n", $logHandle);
            }
        } catch (Throwable $e) {
            $summary['followups_failed']++;
            $errorMsg = "Follow-up #{$followup['id']}: " . $e->getMessage();
            $summary['errors'][] = $errorMsg;
            log_output("  ❌ Exception: {$errorMsg}\n", $logHandle);
            error_log($errorMsg);
        }
    }
    
    // ============================================
    // 3. Summary
    // ============================================
    
    log_output("\n" . str_repeat('=', 70) . "\n", $logHandle);
    log_output("SUMMARY:\n", $logHandle);
    log_output("  - Emails evaluated: {$summary['workflows_evaluated']}\n", $logHandle);
    log_output("  - Follow-ups scheduled: {$summary['workflows_scheduled']}\n", $logHandle);
    log_output("  - Follow-ups skipped: {$summary['workflows_skipped']}\n", $logHandle);
    log_output("  - Follow-ups sent: {$summary['followups_sent']}\n", $logHandle);
    log_output("  - Follow-ups failed: {$summary['followups_failed']}\n", $logHandle);
    log_output("  - Errors: " . count($summary['errors']) . "\n", $logHandle);
    
    if (!empty($summary['errors'])) {
        log_output("\nErrors:\n", $logHandle);
        foreach ($summary['errors'] as $error) {
            log_output("  - {$error}\n", $logHandle);
        }
    }
    
    $pendingFollowups = $pdo->query('SELECT COUNT(*) FROM email_followup_schedule WHERE status = "pending"')->fetchColumn();
    $pendingQueue = $pdo->query('SELECT COUNT(*) FROM email_send_queue WHERE status = "pending"')->fetchColumn();
    $nextQueued = $pdo->query('SELECT MIN(scheduled_for) FROM email_send_queue WHERE status = "pending"')->fetchColumn();
    log_output("  - Pending follow-ups remaining: {$pendingFollowups}\n", $logHandle);
    log_output("  - Pending queued agent emails: {$pendingQueue}" . ($nextQueued ? " (next at {$nextQueued})" : '') . "\n", $logHandle);
    
    log_output("\nCompleted at " . date('Y-m-d H:i:s') . "\n", $logHandle);
    
    // Log to database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO scheduled_task_logs (task_name, execution_time, status, summary, created_at)
            VALUES ('email_automation', NOW(), 'completed', ?, NOW())
        ");
        $stmt->execute([json_encode($summary)]);
    } catch (Throwable $e) {
        // Table might not exist yet, just log to error_log
        error_log("Email automation completed: " . json_encode($summary));
    }
    
} catch (Throwable $e) {
    $errorMsg = "\nFATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    log_output($errorMsg, $logHandle);
    error_log("Email automation fatal error: " . $e->getMessage());
    if ($logHandle) {
        fclose($logHandle);
    }
    exit(1);
}

if ($logHandle) {
    fclose($logHandle);
}

exit(0);



