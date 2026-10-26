<?php
/**
 * Sample Offer Email API
 * 
 * Endpoint to send professional sample offer emails to leads/accounts
 * Uses the sample_offer template created in migration
 * 
 * Usage:
 * POST /api/send-sample-offer.php
 * {
 *   "lead_id": 123,              // OR account_id
 *   "account_id": 456,           // Optional if lead_id provided
 *   "recipient_email": "...",    // Required
 *   "recipient_name": "...",     // Required
 *   "custom_message": "...",     // Optional personal note
 *   "suggested_strength": "gold-30" // Optional: blue-10, gold-30, black-60
 * }
 */

header('Content-Type: application/json');
ob_start();

// Load dependencies
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/auth-session.php';
require_once __DIR__ . '/sendgrid.php';

// Validate authenticated user
if (!isset($_SESSION['id']) || !isset($_SESSION['role'])) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$userId = intval($_SESSION['id']);
$userRole = $_SESSION['role'];

// Sales agents and admins only
if (!in_array($userRole, ['sales_agent', 'admin', 'trainee'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = db();
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Extract parameters
    $leadId = intval($input['lead_id'] ?? 0);
    $accountId = intval($input['account_id'] ?? 0);
    $recipientEmail = trim($input['recipient_email'] ?? '');
    $recipientName = trim($input['recipient_name'] ?? '');
    $customMessage = trim($input['custom_message'] ?? '');
    $suggestedStrength = strtolower(trim($input['suggested_strength'] ?? 'gold-30'));
    
    // Validate inputs
    if (!$recipientEmail || !$recipientName) {
        throw new Exception('Recipient email and name are required');
    }
    
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    if ($leadId <= 0 && $accountId <= 0) {
        throw new Exception('Either lead_id or account_id is required');
    }
    
    // Validate suggested strength
    $validStrengths = ['blue-60', 'gold-120'];
    if (!in_array($suggestedStrength, $validStrengths)) {
        $suggestedStrength = 'gold-120'; // Default to most popular
    }
    
    // Get agent information
    $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$agent) {
        throw new Exception('Agent information not found');
    }
    
    $agentName = trim($agent['first_name'] . ' ' . $agent['last_name']);
    $agentEmail = $agent['email'];
    $agentPhone = $agent['phone'] ?? '';
    
    // Get email template
    $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE slug = ? AND is_active = 1 LIMIT 1');
    $stmt->execute(['sample_offer']);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        throw new Exception('Sample offer email template not found. Please run migration: 2025-10-31_restore-samples-and-email-template.sql');
    }
    
    // Build base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'product.example';
    $baseUrl = $protocol . $host;
    
    // Prepare template variables
    $variables = [
        'recipient_name' => htmlspecialchars($recipientName),
        'agent_name' => htmlspecialchars($agentName),
        'agent_email' => htmlspecialchars($agentEmail),
        'agent_phone' => htmlspecialchars($agentPhone),
        'agent_id' => $userId,
        'base_url' => $baseUrl,
        'suggested_strength' => $suggestedStrength
    ];
    
    // Replace variables in HTML and text bodies
    $htmlBody = $template['html_body'];
    $textBody = $template['text_body'];
    $subject = $template['subject'];
    
    // Replace variables using {variable} format (not {{variable}})
    foreach ($variables as $key => $value) {
        $placeholder = '{' . $key . '}';
        $htmlBody = str_replace($placeholder, $value, $htmlBody);
        $textBody = str_replace($placeholder, $value, $textBody);
        $subject = str_replace($placeholder, $value, $subject);
    }
    
    // Add custom message if provided
    if ($customMessage) {
        $customHtml = '<div style="background:#fff9db;border-left:4px solid #f59e0b;padding:16px 20px;margin:24px 0;border-radius:6px;">'
                    . '<p style="margin:0;font-size:14px;color:#92400e;font-style:italic;">'
                    . '<strong>Personal Note:</strong><br>'
                    . nl2br(htmlspecialchars($customMessage))
                    . '</p></div>';
        
        // Insert after greeting
        $htmlBody = str_replace('</tr>
                    
                    <!-- Main Content -->', '</tr>
                    
                    <!-- Custom Message -->
                    <tr>
                        <td style="padding:20px 32px 0;">
                            ' . $customHtml . '
                        </td>
                    </tr>
                    
                    <!-- Main Content -->', $htmlBody);
        
        $textBody = str_replace('I wanted to personally reach out', "PERSONAL NOTE:\n" . $customMessage . "\n\nI wanted to personally reach out", $textBody);
    }
    
    // Highlight suggested strength in HTML
    if ($suggestedStrength === 'blue-60') {
        // Highlight Blue 60 option
        $htmlBody = str_replace(
            '<!-- Blue 60 -->
                                <div style="border:2px solid #e5e7eb;', 
            '<!-- Blue 60 -->
                                <div style="border:3px solid #3b82f6;background:#eff6ff;box-shadow:0 0 0 3px rgba(59,130,246,0.1);border:2px solid #e5e7eb;', 
            $htmlBody
        );
    } elseif ($suggestedStrength === 'gold-120') {
        // Highlight Gold 120 option (already has gold background, just make border thicker)
        $htmlBody = str_replace(
            '<!-- Gold 120 -->
                                <div style="border:2px solid #f59e0b;', 
            '<!-- Gold 120 -->
                                <div style="border:3px solid #f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,0.1);border:2px solid #f59e0b;', 
            $htmlBody
        );
    }
    
    // Generate tracking token
    $trackingToken = bin2hex(random_bytes(32));
    
    // Send via SendGrid
    $sendResult = sendgrid_send([
        'to' => $recipientEmail,
        'to_name' => $recipientName,
        'from' => 'sample-006@example.com',
        'from_name' => 'Product',
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody,
        'tracking_token' => $trackingToken
    ]);
    
    if (!$sendResult['ok']) {
        throw new Exception('Email delivery failed: ' . ($sendResult['error'] ?? 'Unknown error'));
    }
    
    // Extract SendGrid message ID
    $messageId = null;
    if (!empty($sendResult['response_headers'])) {
        foreach ($sendResult['response_headers'] as $header) {
            if (stripos($header, 'X-Message-ID:') !== false) {
                $messageId = trim(str_ireplace('X-Message-ID:', '', $header));
                break;
            }
        }
    }
    
    // Save to agent_emails table for tracking
    $stmt = $pdo->prepare("
        INSERT INTO agent_emails (
            agent_id, lead_id, account_id, recipient_email, recipient_name,
            subject, body_html, body_text, status, sent_at, tracking_token,
            sendgrid_message_id, email_type, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?, ?, 'sample_offer', NOW())
    ");
    
    $stmt->execute([
        $userId,
        $leadId > 0 ? $leadId : null,
        $accountId > 0 ? $accountId : null,
        $recipientEmail,
        $recipientName,
        $subject,
        $htmlBody,
        $textBody,
        $trackingToken,
        $messageId
    ]);
    
    $emailId = $pdo->lastInsertId();
    
    // Evaluate workflow triggers for this email
    try {
        require_once __DIR__ . '/email-workflow-processor.php';
        
        // Get the email record we just created
        $emailStmt = $pdo->prepare('SELECT * FROM agent_emails WHERE id = ?');
        $emailStmt->execute([$emailId]);
        $sampleEmail = $emailStmt->fetch();
        
        if ($sampleEmail && $sampleEmail['status'] === 'sent') {
            $result = evaluate_workflow_triggers($pdo, $sampleEmail);
            if ($result['scheduled']) {
                error_log("Sample offer email #{$emailId}: Auto-scheduled workflow: workflow_id={$result['workflow_id']}, scheduled_for={$result['scheduled_for']}");
            }
        }
    } catch (Throwable $e) {
        error_log("Sample offer email #{$emailId}: Failed to evaluate workflow triggers: " . $e->getMessage());
        // Non-blocking
    }
    
    // Log activity
    if ($leadId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO lead_activities (lead_id, agent_id, activity_type, activity_date, notes, created_at)
            VALUES (?, ?, 'sample_offer_sent', NOW(), ?, NOW())
        ");
        $stmt->execute([$leadId, $userId, "Sent sample offer email with suggested strength: " . strtoupper($suggestedStrength)]);
    } elseif ($accountId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO account_activities (account_id, agent_id, activity_type, activity_date, notes, created_at)
            VALUES (?, ?, 'sample_offer_sent', NOW(), ?, NOW())
        ");
        $stmt->execute([$accountId, $userId, "Sent sample offer email with suggested strength: " . strtoupper($suggestedStrength)]);
    }
    
    ob_clean();
    echo json_encode([
        'ok' => true,
        'email_id' => $emailId,
        'message_id' => $messageId,
        'message' => 'Sample offer email sent successfully!',
        'recipient' => $recipientEmail,
        'suggested_strength' => $suggestedStrength
    ]);
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

