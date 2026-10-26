<?php
/**
 * Email Queue Processor - Cron job to send scheduled emails
 * Run hourly via crontab: 0 * * * * /usr/bin/php /path/to/api/email-queue-processor.php
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/sendgrid.php';
require_once __DIR__ . '/email-workflow-processor.php';

// Allow manual mode for admin testing
$manualMode = isset($_GET['manual']) && $_GET['manual'] === '1';

// CLI only (unless manual mode from admin)
if (php_sapi_name() !== 'cli' && !$manualMode) {
    header('Content-Type: application/json');
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'This script must be run from command line']));
}

// If manual mode, require admin auth
if ($manualMode) {
    start_session();
    if (!current_admin_id()) {
        header('Content-Type: text/plain');
        http_response_code(403);
        die('Admin authentication required for manual queue processing');
    }
    header('Content-Type: text/plain');
}

$pdo = db();
$processed = 0;
$sent = 0;
$failed = 0;
$errors = [];

try {
    // Get emails ready to send (scheduled time is in the past, status = pending)
    $stmt = $pdo->prepare('
        SELECT 
            eq.*,
            ae.*,
            eq.tracking_token AS queue_tracking_token,
            ae.tracking_token AS email_tracking_token
        FROM email_send_queue eq
        JOIN agent_emails ae ON ae.id = eq.agent_email_id
        WHERE eq.status = "pending" 
          AND eq.scheduled_for <= NOW()
          AND eq.attempts < 3
        ORDER BY eq.priority DESC, eq.scheduled_for ASC
        LIMIT 50
    ');
    $stmt->execute();
    $queue = $stmt->fetchAll();
    
    foreach ($queue as $item) {
        $queueId = intval($item['id']);
        $emailId = intval($item['agent_email_id']);
        
        try {
            // Mark as processing
            $pdo->prepare('UPDATE email_send_queue SET status = "processing", last_attempt_at = NOW(), attempts = attempts + 1 WHERE id = ?')
                ->execute([$queueId]);
            
            // Check automation settings if configured
            $canSend = true;
            $entityType = null;
            $entityId = null;
            
            if (!empty($item['lead_id'])) {
                $entityType = 'lead';
                $entityId = intval($item['lead_id']);
            } elseif (!empty($item['account_id'])) {
                $entityType = 'account';
                $entityId = intval($item['account_id']);
            }
            
            if ($entityType && $entityId) {
                // Check if automation is enabled for this entity
                $settingsStmt = $pdo->prepare('SELECT automation_enabled, allow_scheduled_emails FROM email_automation_settings WHERE entity_type = ? AND entity_id = ?');
                $settingsStmt->execute([$entityType, $entityId]);
                $settings = $settingsStmt->fetch();
                
                if ($settings && (!$settings['automation_enabled'] || !$settings['allow_scheduled_emails'])) {
                    $canSend = false;
                    $pdo->prepare('UPDATE email_send_queue SET status = "cancelled", last_error = ? WHERE id = ?')
                        ->execute(['Automation disabled for this entity', $queueId]);
                    continue;
                }
            }
            
            if (!$canSend) {
                continue;
            }
            
            $trackingToken = $item['queue_tracking_token'] ?: $item['email_tracking_token'];
            if (!$trackingToken) {
                $trackingToken = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE agent_emails SET tracking_token = ? WHERE id = ?')
                    ->execute([$trackingToken, $emailId]);
                $pdo->prepare('UPDATE email_send_queue SET tracking_token = ? WHERE id = ?')
                    ->execute([$trackingToken, $queueId]);
            } elseif (!$item['email_tracking_token'] && $trackingToken) {
                $pdo->prepare('UPDATE agent_emails SET tracking_token = ? WHERE id = ?')
                    ->execute([$trackingToken, $emailId]);
            }
            
            // Send via SendGrid
            $sendResult = sendgrid_send([
                'to' => $item['recipient_email'],
                'to_name' => $item['recipient_name'],
                'from' => 'sample-006@example.com',
                'from_name' => 'Product',
                'subject' => $item['subject'],
                'html' => $item['body_html'],
                'text' => $item['body_text'] ?: strip_tags($item['body_html']),
                'tracking_token' => $trackingToken,
                'custom_args' => [
                    'agent_email_id' => $emailId,
                    'queue_id' => $queueId
                ]
            ]);
            
            if ($sendResult['ok']) {
                // Extract message ID
                $messageId = null;
                if (isset($sendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $matches)) {
                    $messageId = trim($matches[1]);
                }
                
                // Update email status
                $pdo->prepare('UPDATE agent_emails SET status = "sent", sent_at = NOW(), sendgrid_message_id = ?, tracking_token = ? WHERE id = ?')
                    ->execute([$messageId, $trackingToken, $emailId]);
                
                // Update queue status
                $pdo->prepare('UPDATE email_send_queue SET status = "sent", processed_at = NOW() WHERE id = ?')
                    ->execute([$queueId]);
                
                // Schedule manual workflow selections if needed
                $latestEmailRow = $item;
                $latestEmailRow['id'] = $emailId;
                $latestEmailRow['workflow_selection_mode'] = $item['workflow_selection_mode'] ?? null;
                $latestEmailRow['workflow_selection_json'] = $item['workflow_selection_json'] ?? null;
                $latestEmailRow['workflow_schedule_reason'] = $item['workflow_schedule_reason'] ?? null;
                $latestEmailRow['sent_at'] = date('Y-m-d H:i:s');
                $manualSelection = decode_email_workflow_selection($latestEmailRow);
                if ($manualSelection && $manualSelection['mode'] === 'single') {
                    $manualWorkflowId = intval($manualSelection['data']['workflow_id'] ?? 0);
                    if ($manualWorkflowId > 0) {
                        $manualResult = schedule_manual_workflow($pdo, $latestEmailRow, $manualWorkflowId, $manualSelection['reason'] ?: 'manual_single', 'manual_single');
                        if (!empty($manualResult['scheduled'])) {
                            clear_email_workflow_selection($pdo, $emailId);
                        }
                    }
                }
                
                $sent++;
                echo "[" . date('Y-m-d H:i:s') . "] ✅ Sent email #{$emailId} to {$item['recipient_email']}\n";
            } else {
                throw new Exception($sendResult['error'] ?? 'SendGrid failed with status ' . ($sendResult['status'] ?? 'unknown'));
            }
            
            $processed++;
            
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $errors[] = "Email #{$emailId}: " . $error;
            
            // Update queue with error
            $pdo->prepare('UPDATE email_send_queue SET last_error = ?, status = IF(attempts >= 3, "failed", "pending") WHERE id = ?')
                ->execute([$error, $queueId]);
            
            // If max attempts reached, mark email as failed
            if ($item['attempts'] >= 2) { // Will be 3 after this attempt
                $pdo->prepare('UPDATE agent_emails SET status = "failed" WHERE id = ?')
                    ->execute([$emailId]);
            }
            
            $failed++;
            echo "[" . date('Y-m-d H:i:s') . "] ❌ Failed email #{$emailId}: {$error}\n";
        }
    }
    
    // Summary
    echo "\n=== Email Queue Processing Complete ===\n";
    echo "Processed: {$processed}\n";
    echo "Sent: {$sent}\n";
    echo "Failed: {$failed}\n";
    
    if (!empty($errors)) {
        echo "\nErrors:\n";
        foreach ($errors as $err) {
            echo "  - {$err}\n";
        }
    }
    
    // Process email follow-up workflows
    echo "\n=== Processing Follow-up Workflows ===\n";
    try {
        require_once __DIR__ . '/email-workflow-processor.php';
        $workflowStats = process_email_workflows($pdo);
        
        echo "Evaluated: {$workflowStats['evaluated']}\n";
        echo "Scheduled: {$workflowStats['scheduled']}\n";
        echo "Skipped: {$workflowStats['skipped']}\n";
        echo "Errors: {$workflowStats['errors']}\n";
        
        // Process scheduled follow-ups
        echo "\n=== Sending Scheduled Follow-ups ===\n";
        $stmt = $pdo->prepare('
            SELECT * FROM email_followup_schedule 
            WHERE status = "pending" 
              AND scheduled_for <= NOW() 
            ORDER BY scheduled_for ASC 
            LIMIT 20
        ');
        $stmt->execute();
        $followups = $stmt->fetchAll();
        
        $followupsSent = 0;
        $followupsFailed = 0;
        
        foreach ($followups as $followup) {
            try {
                $result = send_scheduled_followup($pdo, $followup);
                if ($result['ok']) {
                    $followupsSent++;
                    echo "[" . date('Y-m-d H:i:s') . "] ✅ Sent follow-up #{$followup['id']} (parent email #{$followup['parent_email_id']})\n";
                } else {
                    $followupsFailed++;
                    echo "[" . date('Y-m-d H:i:s') . "] ❌ Failed follow-up #{$followup['id']}: {$result['error']}\n";
                }
            } catch (Throwable $e) {
                $followupsFailed++;
                echo "[" . date('Y-m-d H:i:s') . "] ❌ Failed follow-up #{$followup['id']}: {$e->getMessage()}\n";
            }
        }
        
        echo "\nFollow-ups sent: {$followupsSent}\n";
        echo "Follow-ups failed: {$followupsFailed}\n";
        
    } catch (Throwable $e) {
        echo "❌ Workflow processor error: " . $e->getMessage() . "\n";
        error_log("Workflow processor error: " . $e->getMessage());
    }
    
    // Log to database
    try {
        $summary = json_encode([
            'email_queue' => [
                'processed' => $processed,
                'sent' => $sent,
                'failed' => $failed,
                'errors' => $errors
            ],
            'workflows' => $workflowStats ?? ['error' => 'not_run'],
            'followups' => [
                'sent' => $followupsSent ?? 0,
                'failed' => $followupsFailed ?? 0
            ],
            'timestamp' => date('c')
        ]);
        
        $pdo->prepare('INSERT INTO operations_log (user_id, action, details, created_at) VALUES (NULL, "email_queue_process", ?, NOW())')
            ->execute([$summary]);
    } catch (Throwable $e) {
        echo "Warning: Could not log to operations_log: " . $e->getMessage() . "\n";
    }
    
    exit(0);
    
} catch (Throwable $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

