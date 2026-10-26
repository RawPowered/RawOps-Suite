<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../api/sendgrid.php';
require_once __DIR__ . '/email-tracking.php';
require_once __DIR__ . '/email-template-renderer.php';

/**
 * Send legal access approval email via SendGrid
 */
function send_legal_access_approved(string $email, string $firstName, string $username, string $loginUrl): array {
    try {
        // Load template
        $templatePath = __DIR__ . '/../admin/emails/legal-access-approved.html';
        if (!is_file($templatePath)) {
            return ['ok' => false, 'error' => 'Template not found'];
        }
        
        $html = file_get_contents($templatePath);
        
        // Render template with variables
        $html = render_email_template($html, [
            'first_name' => $firstName,
            'username' => $username,
            'login_url' => $loginUrl
        ]);
        
        $params = [
            'to' => $email,
            'to_name' => $firstName,
            'from' => 'sample-006@example.com',
            'from_name' => 'Product® Legal Management',
            'subject' => 'Legal Portal Access Approved — Product® Legal Management',
            'html' => $html,
            'text' => "Hello $firstName,\n\nYour access to the Product® Legal Management System has been approved.\n\nUsername: $username\nLogin: $loginUrl\n\nThank you,\nProduct® Legal Management Team",
            'sandbox' => false
        ];
        
        $result = sendgrid_send($params);
        
        // Log to database
        if ($result['ok'] ?? false) {
            $messageId = null;
            if (isset($result['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $result['headers'], $matches)) {
                $messageId = trim($matches[1]);
            }
            
            log_email_send([
                'message_id' => $messageId,
                'user_id' => null,
                'recipient_email' => $email,
                'recipient_name' => $firstName,
                'sender_email' => $params['from'],
                'sender_name' => $params['from_name'],
                'subject' => $params['subject'],
                'template_id' => null,
                'event_key' => 'legal_access_approved',
                'status' => 'sent',
                'status_code' => $result['status'] ?? null,
                'metadata' => ['username' => $username]
            ]);
        }
        
        return $result;
        
    } catch (Throwable $e) {
        error_log('[legal-email] send_legal_access_approved error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send legal access denial email via SendGrid
 */
function send_legal_access_denied(string $email, string $firstName, ?string $reason = null): array {
    try {
        $templatePath = __DIR__ . '/../admin/emails/legal-access-denied.html';
        if (!is_file($templatePath)) {
            return ['ok' => false, 'error' => 'Template not found'];
        }
        
        $html = file_get_contents($templatePath);
        
        // Render template with variables
        $html = render_email_template($html, [
            'first_name' => $firstName,
            'reason' => $reason
        ]);
        
        $params = [
            'to' => $email,
            'to_name' => $firstName,
            'from' => 'sample-006@example.com',
            'from_name' => 'Product® Legal Management',
            'subject' => 'Legal Portal Access Request Update — Product® Legal Management',
            'html' => $html,
            'text' => "Hello $firstName,\n\nThank you for your interest in the Product® Legal Management System. After review, we are unable to approve your access request at this time.\n\n" . ($reason ? "Reason: $reason\n\n" : '') . "If you have questions, please contact the administrator.\n\nThank you,\nProduct® Legal Management Team",
            'sandbox' => false
        ];
        
        $result = sendgrid_send($params);
        
        // Log to database
        if ($result['ok'] ?? false) {
            $messageId = null;
            if (isset($result['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $result['headers'], $matches)) {
                $messageId = trim($matches[1]);
            }
            
            log_email_send([
                'message_id' => $messageId,
                'user_id' => null,
                'recipient_email' => $email,
                'recipient_name' => $firstName,
                'sender_email' => $params['from'],
                'sender_name' => $params['from_name'],
                'subject' => $params['subject'],
                'template_id' => null,
                'event_key' => 'legal_access_denied',
                'status' => 'sent',
                'status_code' => $result['status'] ?? null,
                'metadata' => ['reason' => $reason]
            ]);
        }
        
        return $result;
        
    } catch (Throwable $e) {
        error_log('[legal-email] send_legal_access_denied error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send admin notification when new access request submitted
 */
function send_legal_access_request_notification(int $requestId, string $requestorName, string $email, ?string $caseNumber = null): array {
    try {
        $pdo = db();
        
        // Get all admin users
        $stmt = $pdo->query('
            SELECT DISTINCT u.email, u.first_name 
            FROM users u 
            JOIN user_roles ur ON ur.user_id = u.id 
            JOIN roles r ON r.id = ur.role_id 
            WHERE r.name = "admin" AND u.email IS NOT NULL
        ');
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($admins)) {
            return ['ok' => false, 'error' => 'No admin emails found'];
        }
        
        $reviewUrl = sg_base_url() . path_with_base('/admin/legal/access-requests.php');
        $caseInfo = $caseNumber ? " for case $caseNumber" : '';
        
        $html = "
        <div style='font-family:sans-serif;max-width:600px;margin:0 auto;'>
            <h2 style='color:#1e3a8a;'>New Legal Access Request</h2>
            <p><strong>$requestorName</strong> has requested access to the Legal Management System$caseInfo.</p>
            <p><strong>Email:</strong> $email<br><strong>Request ID:</strong> #$requestId</p>
            <p style='margin-top:24px;'><a href='$reviewUrl' style='display:inline-block;background:#1e3a8a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;'>Review Request</a></p>
        </div>
        ";
        
        $results = [];
        foreach ($admins as $admin) {
        $params = [
            'to' => $admin['email'],
            'to_name' => $admin['first_name'],
            'from' => 'sample-006@example.com',
            'from_name' => 'Product® Legal Management',
            'subject' => 'New Legal Access Request' . $caseInfo,
            'html' => $html,
            'text' => "New legal access request from $requestorName ($email)$caseInfo. Review at: $reviewUrl",
            'sandbox' => false
        ];
            
            $result = sendgrid_send($params);
            $results[] = $result;
            
            // Log each send
            if ($result['ok'] ?? false) {
                $messageId = null;
                if (isset($result['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $result['headers'], $matches)) {
                    $messageId = trim($matches[1]);
                }
                
                log_email_send([
                    'message_id' => $messageId,
                    'user_id' => null,
                    'recipient_email' => $admin['email'],
                    'recipient_name' => $admin['first_name'],
                    'sender_email' => $params['from'],
                    'sender_name' => $params['from_name'],
                    'subject' => $params['subject'],
                    'template_id' => null,
                    'event_key' => 'legal_access_requested',
                    'status' => 'sent',
                    'status_code' => $result['status'] ?? null,
                    'metadata' => ['request_id' => $requestId, 'requestor' => $requestorName, 'case_number' => $caseNumber]
                ]);
            }
        }
        
        // Return success if at least one email sent
        $anySuccess = array_reduce($results, fn($carry, $r) => $carry || ($r['ok'] ?? false), false);
        return ['ok' => $anySuccess, 'sent_count' => count($results)];
        
    } catch (Throwable $e) {
        error_log('[legal-email] send_legal_access_request_notification error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send confirmation email to requestor when they submit access request
 */
function send_legal_access_request_confirmation(string $email, string $requestorName, int $requestId, ?string $caseNumber = null): array {
    try {
        $caseInfo = $caseNumber ? " for case $caseNumber" : '';
        
        $html = "
        <div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
            <div style='text-align:center;margin-bottom:32px;'>
                <h1 style='color:#1e3a8a;margin:0;'>Product<sup style='font-size:0.5em;'>®</sup> Legal Management</h1>
            </div>
            
            <div style='background:#dcfce7;border:2px solid #86efac;border-radius:12px;padding:20px;margin-bottom:24px;'>
                <h2 style='color:#15803d;margin:0 0 12px 0;'>✅ Access Request Received</h2>
                <p style='color:#166534;margin:0;'>Your request has been submitted successfully and is pending admin review.</p>
            </div>
            
            <h3 style='color:#0f172a;margin:24px 0 12px 0;'>Request Details</h3>
            <table style='width:100%;border-collapse:collapse;'>
                <tr>
                    <td style='padding:8px 0;color:#64748b;font-weight:600;'>Request ID:</td>
                    <td style='padding:8px 0;color:#0f172a;'>#$requestId</td>
                </tr>
                <tr>
                    <td style='padding:8px 0;color:#64748b;font-weight:600;'>Name:</td>
                    <td style='padding:8px 0;color:#0f172a;'>$requestorName</td>
                </tr>
                <tr>
                    <td style='padding:8px 0;color:#64748b;font-weight:600;'>Email:</td>
                    <td style='padding:8px 0;color:#0f172a;'>$email</td>
                </tr>
                " . ($caseNumber ? "<tr><td style='padding:8px 0;color:#64748b;font-weight:600;'>Case:</td><td style='padding:8px 0;color:#0f172a;'>$caseNumber</td></tr>" : "") . "
            </table>
            
            <div style='background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:16px;margin:24px 0;'>
                <p style='margin:0;color:#92400e;'><strong>⏳ What's Next?</strong></p>
                <p style='margin:8px 0 0 0;color:#854d0e;'>An administrator will review your request. You will receive an email notification once your request has been approved or if additional information is needed.</p>
            </div>
            
            <p style='color:#64748b;font-size:0.9rem;margin-top:32px;'>If you have any questions about your request, please contact sample-015@example.com with your request ID.</p>
            
            <hr style='border:none;border-top:1px solid #e2e8f0;margin:32px 0;'>
            
            <p style='text-align:center;color:#94a3b8;font-size:0.85rem;margin:0;'>
                <strong>Product® Legal Management</strong><br>
                Secure Case Management & Evidence Handling
            </p>
        </div>
        ";
        
        $params = [
            'to' => $email,
            'to_name' => $requestorName,
            'from' => 'sample-006@example.com',
            'from_name' => 'Product® Legal Management',
            'subject' => 'Access Request Received — Pending Review',
            'html' => $html,
            'text' => "Hello $requestorName,\n\nYour access request (#$requestId)$caseInfo has been received and is pending admin review.\n\nYou will receive an email notification once your request has been processed.\n\nThank you,\nProduct® Legal Management Team",
            'sandbox' => false
        ];
        
        $result = sendgrid_send($params);
        
        // Log to database
        if ($result['ok'] ?? false) {
            $messageId = null;
            if (isset($result['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $result['headers'], $matches)) {
                $messageId = trim($matches[1]);
            }
            
            log_email_send([
                'message_id' => $messageId,
                'user_id' => null,
                'recipient_email' => $email,
                'recipient_name' => $requestorName,
                'sender_email' => $params['from'],
                'sender_name' => $params['from_name'],
                'subject' => $params['subject'],
                'template_id' => null,
                'event_key' => 'legal_access_request_confirmation',
                'status' => 'sent',
                'status_code' => $result['status'] ?? null,
                'metadata' => ['request_id' => $requestId, 'case_number' => $caseNumber]
            ]);
        }
        
        return $result;
        
    } catch (Throwable $e) {
        error_log('[legal-email] send_legal_access_request_confirmation error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send legal user created email with temporary password via SendGrid
 */
function send_legal_user_created(string $email, string $firstName, string $tempPassword, string $loginUrl, ?string $caseNumber = null, ?string $caseTitle = null): array {
    try {
        // Load template
        $templatePath = __DIR__ . '/../admin/emails/legal-user-created.html';
        if (!is_file($templatePath)) {
            return ['ok' => false, 'error' => 'Template not found'];
        }
        
        $html = file_get_contents($templatePath);
        
        // Render template with variables
        $html = render_email_template($html, [
            'first_name' => $firstName,
            'email' => $email,
            'temp_password' => $tempPassword,
            'login_url' => $loginUrl,
            'case_number' => $caseNumber,
            'case_title' => $caseTitle
        ]);
        
        $caseInfo = $caseNumber ? " (Case: $caseNumber)" : '';
        
        $params = [
            'to' => $email,
            'to_name' => $firstName,
            'from' => 'sample-006@example.com',
            'from_name' => 'Product® Legal Management',
            'subject' => 'Legal Portal Account Created — Product® Legal Management',
            'html' => $html,
            'text' => "Hello $firstName,\n\nAn administrator has created a legal portal account for you.\n\nLogin Email: $email\nTemporary Password: $tempPassword\nLogin URL: $loginUrl\n\nYou will be required to change your password immediately upon first login.$caseInfo\n\nThank you,\nProduct® Legal Management Team",
            'sandbox' => false
        ];
        
        $result = sendgrid_send($params);
        
        // Log to database
        if ($result['ok'] ?? false) {
            $messageId = null;
            if (isset($result['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $result['headers'], $matches)) {
                $messageId = trim($matches[1]);
            }
            
            log_email_send([
                'message_id' => $messageId,
                'user_id' => null,
                'recipient_email' => $email,
                'recipient_name' => $firstName,
                'sender_email' => $params['from'],
                'sender_name' => $params['from_name'],
                'subject' => $params['subject'],
                'template_id' => null,
                'event_key' => 'legal_user_created',
                'status' => 'sent',
                'status_code' => $result['status'] ?? null,
                'metadata' => ['case_number' => $caseNumber, 'has_temp_password' => true]
            ]);
        }
        
        return $result;
        
    } catch (Throwable $e) {
        error_log('[legal-email] send_legal_user_created error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}