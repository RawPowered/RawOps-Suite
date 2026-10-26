<?php
/**
 * SendGrid Event Webhook Endpoint
 * Receives delivery events from SendGrid and updates agent_emails table
 * 
 * Configure in SendGrid Dashboard:
 * Settings > Mail Settings > Event Webhook
 * URL: https://product.example/api/sendgrid-webhook.php
 * 
 * Events to enable:
 * - Processed, Delivered, Opened, Clicked, Bounced, Dropped, Spam Report
 */

// No session needed for webhooks
require_once __DIR__ . '/../required/config.php';

// Get raw POST data
$rawPayload = file_get_contents('php://input');
$events = json_decode($rawPayload, true);

// Log webhook receipt for debugging
$logFile = __DIR__ . '/../required/logs/sendgrid-webhook.log';
@mkdir(dirname($logFile), 0755, true);

$logEntry = date('[Y-m-d H:i:s] ') . "Webhook received\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND);

// Validate payload
if (!is_array($events)) {
    http_response_code(400);
    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Invalid payload - not array\n", FILE_APPEND);
    die('Invalid payload');
}

$pdo = db();
$processed = 0;
$errors = 0;

foreach ($events as $event) {
    try {
        $eventType = $event['event'] ?? '';
        $messageId = $event['sg_message_id'] ?? ($event['sg_event_id'] ?? '');
        $recipientEmail = $event['email'] ?? '';
        $timestamp = isset($event['timestamp']) ? date('Y-m-d H:i:s', $event['timestamp']) : null;
        $customArgs = [];
        if (!empty($event['custom_args']) && is_array($event['custom_args'])) {
            $customArgs = $event['custom_args'];
        } elseif (!empty($event['unique_args']) && is_array($event['unique_args'])) {
            $customArgs = $event['unique_args'];
        }
        $tokenFromArgs = $customArgs['tracking_token'] ?? null;
        $sampleRequestId = isset($customArgs['sample_request_id']) ? intval($customArgs['sample_request_id']) : 0;
        $emailIdFromArgs = isset($customArgs['agent_email_id']) ? intval($customArgs['agent_email_id']) : null;
        
        // Try multiple ways to find the email
        $agentEmail = null;
        
        // Method 0: explicit email id passed via custom args
        if ($emailIdFromArgs) {
            $stmt = $pdo->prepare('SELECT id, status, open_count, click_count FROM agent_emails WHERE id = ? LIMIT 1');
            $stmt->execute([$emailIdFromArgs]);
            $agentEmail = $stmt->fetch();
        }
        
        // Method 0.5: tracking token from custom args
        if (!$agentEmail && $tokenFromArgs) {
            $stmt = $pdo->prepare('SELECT id, status, open_count, click_count FROM agent_emails WHERE tracking_token = ? LIMIT 1');
            $stmt->execute([$tokenFromArgs]);
            $agentEmail = $stmt->fetch();
        }
        
        // Method 1: Try by SendGrid message ID
        if ($messageId) {
            $stmt = $pdo->prepare('SELECT id, status, open_count, click_count FROM agent_emails WHERE sendgrid_message_id = ? LIMIT 1');
            $stmt->execute([$messageId]);
            $agentEmail = $stmt->fetch();
        }
        
        // Method 2: If not found and we have recipient email, try by recent sent email to that recipient
        if (!$agentEmail && $recipientEmail) {
            $stmt = $pdo->prepare('SELECT id, status, open_count, click_count FROM agent_emails WHERE recipient_email = ? AND status IN ("sent", "delivered") AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY sent_at DESC LIMIT 1');
            $stmt->execute([$recipientEmail]);
            $agentEmail = $stmt->fetch();
            
            if ($agentEmail) {
                // Update with the message ID we now have
                $pdo->prepare('UPDATE agent_emails SET sendgrid_message_id = ? WHERE id = ?')->execute([$messageId, $agentEmail['id']]);
                @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Matched by recipient email: {$recipientEmail}, updated message_id\n", FILE_APPEND);
            }
        }
        
        // Method 3: Try to find in sample_email_events table
        $sampleEmail = null;
        if (!$agentEmail && $recipientEmail) {
            $stmt = $pdo->prepare('SELECT id, sample_request_id FROM sample_email_events WHERE recipient_email = ? AND email_sent = 1 AND sendgrid_message_id = ? LIMIT 1');
            $stmt->execute([$recipientEmail, $messageId]);
            $sampleEmail = $stmt->fetch();
            
            if (!$sampleEmail && $messageId) {
                // Try without matching message ID (recent fallback)
                $stmt = $pdo->prepare('SELECT id, sample_request_id FROM sample_email_events WHERE recipient_email = ? AND email_sent = 1 AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY sent_at DESC LIMIT 1');
                $stmt->execute([$recipientEmail]);
                $sampleEmail = $stmt->fetch();
                
                if ($sampleEmail) {
                    // Update with the message ID we now have
                    $pdo->prepare('UPDATE sample_email_events SET sendgrid_message_id = ? WHERE id = ?')->execute([$messageId, $sampleEmail['id']]);
                }
            }
        }
        
        if (!$agentEmail && !$sampleEmail) {
            // Email not found - might be a system email or too old
            @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Email not found for message_id: {$messageId}, recipient: {$recipientEmail}\n", FILE_APPEND);
            continue;
        }
        
        // Try to infer Sample Suite context if custom arg missing
        if ($sampleRequestId <= 0 && $agentEmail) {
            $kickoffLookup = $pdo->prepare("SELECT sample_request_id FROM sample_suite_enrollments WHERE kickoff_email_id = ? LIMIT 1");
            $kickoffLookup->execute([intval($agentEmail['id'])]);
            $sampleRequestId = intval($kickoffLookup->fetchColumn()) ?: 0;
        }
        if ($sampleRequestId <= 0 && !empty($customArgs['parent_schedule_id'])) {
            $scheduleId = intval($customArgs['parent_schedule_id']);
            if ($scheduleId > 0) {
                $payloadStmt = $pdo->prepare("SELECT payload_json FROM email_followup_schedule WHERE id = ? LIMIT 1");
                $payloadStmt->execute([$scheduleId]);
                $payloadJson = $payloadStmt->fetchColumn();
                if ($payloadJson) {
                    $payloadData = json_decode($payloadJson, true);
                    if (is_array($payloadData) && !empty($payloadData['sample_request_id'])) {
                        $sampleRequestId = intval($payloadData['sample_request_id']);
                    }
                }
            }
        }

        // Determine which table to update
        $isAgentEmail = $agentEmail !== null;
        $isSampleEmail = $sampleEmail !== null;
        
        // Process event type
        switch ($eventType) {
            case 'delivered':
                // Email successfully delivered
                if ($isAgentEmail) {
                    $emailId = intval($agentEmail['id']);
                    $stmt = $pdo->prepare('UPDATE agent_emails SET status = "delivered" WHERE id = ?');
                    $stmt->execute([$emailId]);
                } elseif ($isSampleEmail) {
                    // Note: sample_email_events doesn't have open_count, just track that it was delivered
                    // Could add open tracking columns in future migration if needed
                }
                $processed++;
                break;
                
            case 'open':
                // Email opened by recipient
                if ($isAgentEmail) {
                    $emailId = intval($agentEmail['id']);
                    $openCount = intval($agentEmail['open_count']) + 1;
                    $stmt = $pdo->prepare('UPDATE agent_emails SET open_count = ?, last_opened_at = ? WHERE id = ?');
                    $stmt->execute([$openCount, $timestamp, $emailId]);
                    
                    // Log first open as status change
                    if ($openCount === 1) {
                        @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "First open for email #{$emailId}\n", FILE_APPEND);
                    }
                } elseif ($isSampleEmail) {
                    // Log sample email open event
                    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Sample email opened: {$recipientEmail}\n", FILE_APPEND);
                }
                if ($sampleRequestId > 0) {
                    if (!function_exists('sample_suite_record_engagement')) {
                        require_once __DIR__ . '/../required/sample-suite-helpers.php';
                    }
                    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Recording Sample Suite open: request_id={$sampleRequestId}, agent_email_id=" . ($agentEmail['id'] ?? 'null') . "\n", FILE_APPEND);
                    sample_suite_record_engagement($pdo, $sampleRequestId, 'open', [
                        'event_at' => $timestamp,
                        'agent_email_id' => $agentEmail['id'] ?? null,
                        'sendgrid_message_id' => $messageId
                    ]);
                } else {
                    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Open event but no sample_request_id in custom_args for email #{$emailId}\n", FILE_APPEND);
                }
                $processed++;
                break;
                
            case 'click':
                // Link clicked in email
                if ($isAgentEmail) {
                    $emailId = intval($agentEmail['id']);
                    $clickCount = intval($agentEmail['click_count']) + 1;
                    $stmt = $pdo->prepare('UPDATE agent_emails SET click_count = ?, last_clicked_at = ? WHERE id = ?');
                    $stmt->execute([$clickCount, $timestamp, $emailId]);
                } elseif ($isSampleEmail) {
                    // Log sample email click
                    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Sample email clicked: {$recipientEmail}\n", FILE_APPEND);
                }
                if ($sampleRequestId > 0) {
                    if (!function_exists('sample_suite_record_engagement')) {
                        require_once __DIR__ . '/../required/sample-suite-helpers.php';
                    }
                    sample_suite_record_engagement($pdo, $sampleRequestId, 'click', [
                        'event_at' => $timestamp,
                        'agent_email_id' => $agentEmail['id'] ?? null,
                        'sendgrid_message_id' => $messageId
                    ]);
                }
                $processed++;
                break;
                
            case 'bounce':
            case 'dropped':
                // Email bounced or dropped
                $bounceType = $event['type'] ?? 'unknown'; // hard, soft, blocked
                $reason = $event['reason'] ?? '';
                
                if ($isAgentEmail) {
                    $emailId = intval($agentEmail['id']);
                    $stmt = $pdo->prepare('UPDATE agent_emails SET status = "bounced" WHERE id = ?');
                    $stmt->execute([$emailId]);
                    
                    // Log bounce for agent review
                    try {
                        $pdo->prepare('INSERT INTO operations_log (user_id, action, details, created_at) VALUES (NULL, "email_bounced", ?, NOW())')
                            ->execute([json_encode(['email_id' => $emailId, 'bounce_type' => $bounceType, 'reason' => $reason])]);
                    } catch (Throwable $e) {}
                }
                
                if ($sampleRequestId > 0) {
                    if (!function_exists('sample_suite_record_engagement')) {
                        require_once __DIR__ . '/../required/sample-suite-helpers.php';
                    }
                    sample_suite_record_engagement($pdo, $sampleRequestId, 'bounce', [
                        'event_at' => $timestamp,
                        'agent_email_id' => $agentEmail['id'] ?? null,
                        'sendgrid_message_id' => $messageId,
                        'reason' => $reason,
                        'bounce_type' => $bounceType
                    ]);
                }
                $processed++;
                @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Bounce: {$bounceType} - {$reason} for {$recipientEmail}\n", FILE_APPEND);
                break;
                
            case 'spamreport':
            case 'unsubscribe':
                // Recipient marked as spam
                if ($isAgentEmail) {
                    $emailId = intval($agentEmail['id']);
                    $stmt = $pdo->prepare('UPDATE agent_emails SET status = "failed" WHERE id = ?');
                    $stmt->execute([$emailId]);
                    
                    try {
                        $pdo->prepare('INSERT INTO operations_log (user_id, action, details, created_at) VALUES (NULL, "email_spam_report", ?, NOW())')
                            ->execute([json_encode(['email_id' => $emailId, 'recipient' => $recipientEmail])]);
                    } catch (Throwable $e) {}
                }
                
                if ($sampleRequestId > 0) {
                    if (!function_exists('sample_suite_record_engagement')) {
                        require_once __DIR__ . '/../required/sample-suite-helpers.php';
                    }
                    sample_suite_record_engagement($pdo, $sampleRequestId, 'unsubscribe', [
                        'event_at' => $timestamp,
                        'agent_email_id' => $agentEmail['id'] ?? null,
                        'sendgrid_message_id' => $messageId
                    ]);
                }
                $processed++;
                break;
                
            case 'processed':
                // Email accepted by SendGrid
                // No action needed, already marked as sent
                break;
                
            default:
                // Unknown event type, log it
                @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Unknown event: {$eventType} for {$messageId}\n", FILE_APPEND);
        }
        
    } catch (Throwable $e) {
        $errors++;
        @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Error processing event: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Log summary
@file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Processed {$processed} events, {$errors} errors\n", FILE_APPEND);

// Return 200 OK to SendGrid
http_response_code(200);
echo json_encode(['ok' => true, 'processed' => $processed, 'errors' => $errors]);

