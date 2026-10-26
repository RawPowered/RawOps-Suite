<?php
/**
 * SendGrid Webhook Handler for Legal Emails
 * Receives delivery, open, click, and bounce events from SendGrid
 * Configure in SendGrid: Settings → Mail Settings → Event Webhook
 * URL: https://product.example/api/legal/email-webhook.php
 */

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/email-tracking.php';

header('Content-Type: application/json');

// Verify SendGrid signature (optional but recommended)
// $signature = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '';
// $timestamp = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$events = json_decode($rawBody, true);

if (!is_array($events)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$processed = 0;
$errors = [];

foreach ($events as $event) {
    try {
        $eventType = $event['event'] ?? '';
        $messageId = $event['sg_message_id'] ?? null;
        
        if (!$messageId) {
            continue;
        }
        
        // Clean message ID (SendGrid sometimes adds suffixes)
        $messageId = explode('.', $messageId)[0];
        
        $metadata = [
            'ip' => $event['ip'] ?? null,
            'user_agent' => $event['useragent'] ?? null,
            'url' => $event['url'] ?? null,
            'timestamp' => $event['timestamp'] ?? null,
            'reason' => $event['reason'] ?? null,
            'type' => $event['type'] ?? null
        ];
        
        switch ($eventType) {
            case 'delivered':
                update_email_status($messageId, 'delivered', $metadata);
                $processed++;
                break;
                
            case 'open':
                update_email_status($messageId, 'opened', $metadata);
                $processed++;
                break;
                
            case 'click':
                update_email_status($messageId, 'clicked', $metadata);
                $processed++;
                break;
                
            case 'bounce':
            case 'dropped':
                update_email_status($messageId, 'bounced', $metadata);
                $processed++;
                break;
                
            case 'spamreport':
                update_email_status($messageId, 'spam', $metadata);
                $processed++;
                break;
        }
        
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        error_log('[email-webhook] error processing event: ' . $e->getMessage());
    }
}

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'errors' => $errors
]);






