<?php
/**
 * SendGrid Email Templates for Beta Sample Requests
 * 
 * Email notifications for beta sample workflow:
 * - request_received: Confirmation when request submitted
 * - approved: Sample approved for shipping
 * - shipped: Tracking number provided
 * - feedback_request: Ask for product review
 */

require_once __DIR__ . '/config.php';

/**
 * Get tracking URL for carrier
 */
function get_tracking_url(string $carrier, string $trackingNumber): string
{
    $carrier = strtoupper(trim($carrier));
    $trackingNumber = trim($trackingNumber);
    
    switch ($carrier) {
        case 'USPS':
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($trackingNumber);
        case 'UPS':
            return 'https://www.ups.com/track?tracknum=' . urlencode($trackingNumber);
        case 'FEDEX':
            return 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($trackingNumber);
        default:
            return '#';
    }
}

/**
 * Send beta sample email notification
 */
function send_beta_sample_email(string $type, array $data): bool
{
    $sendgridApiKey = getenv('SENDGRID_API_KEY');
    
    if (!$sendgridApiKey) {
        error_log('SendGrid API key not configured');
        return false;
    }
    
    $templates = [
        'request_received' => [
            'subject' => 'Beta Sample Request Received - Product',
            'template' => 'beta_sample_request_received'
        ],
        'approved' => [
            'subject' => 'Your Beta Sample Has Been Approved!',
            'template' => 'beta_sample_approved'
        ],
        'shipped' => [
            'subject' => 'Your Beta Sample Has Shipped!',
            'template' => 'beta_sample_shipped'
        ],
        'feedback_request' => [
            'subject' => 'How Was Your Beta Sample? We\'d Love Your Feedback',
            'template' => 'beta_sample_feedback'
        ]
    ];
    
    if (!isset($templates[$type])) {
        error_log("Unknown beta sample email type: {$type}");
        return false;
    }
    
    $config = $templates[$type];
    $to = $data['to'] ?? null;
    $name = $data['name'] ?? 'Customer';
    
    if (!$to) {
        error_log('No recipient email provided for beta sample email');
        return false;
    }
    
    // Build email content based on type
    $htmlContent = get_beta_sample_email_html($type, $data);
    $textContent = get_beta_sample_email_text($type, $data);
    
    // Send via SendGrid API
    $email = [
        'personalizations' => [
            [
                'to' => [['email' => $to, 'name' => $name]],
                'subject' => $config['subject']
            ]
        ],
        'from' => [
            'email' => 'sample-032@example.com',
            'name' => 'Product Beta Testing'
        ],
        'reply_to' => [
            'email' => 'sample-015@example.com',
            'name' => 'Product Support'
        ],
        'content' => [
            ['type' => 'text/plain', 'value' => $textContent],
            ['type' => 'text/html', 'value' => $htmlContent]
        ]
    ];
    
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $sendgridApiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($email)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        error_log("SendGrid beta sample email failed: HTTP {$httpCode}, Response: {$response}");
        return false;
    }
}

/**
 * Get HTML email content
 */
function get_beta_sample_email_html(string $type, array $data): string
{
    $baseUrl = site_origin();
    $productName = htmlspecialchars($data['product_name'] ?? 'Beta Sample');
    $name = htmlspecialchars($data['name'] ?? 'Customer');
    
    $header = '
    <div style="background:#0f172a;padding:32px 20px;text-align:center;">
        <h1 style="margin:0;color:#38bdf8;font-size:28px;font-weight:900;">Product™</h1>
        <p style="margin:8px 0 0;color:#cbd5e1;font-size:14px;">Public Beta Testing Program</p>
    </div>';
    
    $footer = '
    <div style="background:#f8fafc;padding:24px 20px;text-align:center;border-top:2px solid #e2e8f0;">
        <p style="margin:0 0 8px;color:#64748b;font-size:13px;">
            <strong>Remember:</strong> This is a beta product and has not been lab tested for final quality information.
        </p>
        <p style="margin:0 0 16px;color:#64748b;font-size:13px;">
            Questions? Contact us at <a href="mailto:sample-015@example.com" style="color:#0ea5e9;">sample-015@example.com</a>
        </p>
        <p style="margin:0;color:#94a3b8;font-size:12px;">
            © ' . date('Y') . ' Product. All rights reserved.
        </p>
    </div>';
    
    switch ($type) {
        case 'request_received':
            $requestId = $data['request_id'] ?? '';
            $shippingAddress = htmlspecialchars($data['shipping_address'] ?? '');
            
            $body = "
            <div style='padding:32px 20px;'>
                <h2 style='margin:0 0 16px;color:#0f172a;font-size:24px;'>Thank You for Joining Our Beta Test!</h2>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    Hi {$name},
                </p>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    We've received your request for a <strong>{$productName}</strong>. Your sample will be reviewed by our operations team and shipped within 2-3 business days.
                </p>
                
                <div style='background:#fef2f2;border-left:4px solid #dc2626;padding:16px;margin:24px 0;'>
                    <p style='margin:0 0 8px;color:#991b1b;font-weight:700;'>⚠️ Important Reminder:</p>
                    <p style='margin:0;color:#7f1d1d;font-size:14px;line-height:1.5;'>
                        This is a <strong>beta test product</strong> that has not been lab tested for final quality information. 
                        By accepting this sample, you agree to the beta testing waiver and assume all risks.
                    </p>
                </div>
                
                <div style='background:#f8fafc;border-radius:8px;padding:20px;margin:24px 0;'>
                    <h3 style='margin:0 0 12px;color:#0f172a;font-size:16px;'>Request Details:</h3>
                    <p style='margin:0 0 8px;color:#64748b;font-size:14px;'><strong>Request ID:</strong> #{$requestId}</p>
                    <p style='margin:0 0 8px;color:#64748b;font-size:14px;'><strong>Product:</strong> {$productName}</p>
                    <p style='margin:0;color:#64748b;font-size:14px;'><strong>Shipping To:</strong> {$shippingAddress}</p>
                </div>
                
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    You'll receive another email with tracking information once your sample ships.
                </p>
                
                <p style='margin:0 0 8px;color:#334155;font-size:16px;'>
                    Thank you for helping us perfect our product!
                </p>
                <p style='margin:0;color:#334155;font-size:16px;'>
                    — The Product Team
                </p>
            </div>";
            break;
            
        case 'approved':
            $body = "
            <div style='padding:32px 20px;'>
                <h2 style='margin:0 0 16px;color:#0f172a;font-size:24px;'>✅ Your Beta Sample is Approved!</h2>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    Hi {$name},
                </p>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    Great news! Your <strong>{$productName}</strong> request has been approved and will ship within 1-2 business days.
                </p>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    You'll receive tracking information as soon as your sample ships.
                </p>
                <p style='margin:0;color:#334155;font-size:16px;'>
                    — The Product Team
                </p>
            </div>";
            break;
            
        case 'shipped':
            $trackingNumber = htmlspecialchars($data['tracking_number'] ?? 'N/A');
            $carrier = htmlspecialchars($data['carrier'] ?? 'USPS');
            $trackingUrl = htmlspecialchars($data['tracking_url'] ?? '#');
            $feedbackUrl = $baseUrl . '/customer/beta-feedback.php';
            
            $body = "
            <div style='padding:32px 20px;'>
                <h2 style='margin:0 0 16px;color:#0f172a;font-size:24px;'>📦 Your Beta Sample Has Shipped!</h2>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    Hi {$name},
                </p>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    Your <strong>{$productName}</strong> is on its way!
                </p>
                
                <div style='background:#ecfdf5;border-radius:8px;padding:20px;margin:24px 0;text-align:center;'>
                    <p style='margin:0 0 8px;color:#14532d;font-size:14px;font-weight:600;'>Tracking Number:</p>
                    <p style='margin:0 0 16px;color:#0f172a;font-size:20px;font-weight:900;'>{$trackingNumber}</p>
                    <p style='margin:0 0 16px;color:#166534;font-size:14px;'>Carrier: {$carrier}</p>
                    <a href='{$trackingUrl}' style='display:inline-block;background:#16a34a;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:700;'>
                        Track Your Package
                    </a>
                </div>
                
                <div style='background:#fffbeb;border-left:4px solid #f59e0b;padding:16px;margin:24px 0;'>
                    <p style='margin:0 0 12px;color:#78350f;font-weight:700;'>We'd love your feedback!</p>
                    <p style='margin:0 0 16px;color:#78350f;font-size:14px;line-height:1.5;'>
                        Once you try the product, please share your honest thoughts. Your feedback is invaluable 
                        in helping us perfect this formula before the official launch.
                    </p>
                    <a href='{$feedbackUrl}' style='display:inline-block;background:#f59e0b;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;'>
                        Leave Your Review
                    </a>
                </div>
                
                <p style='margin:0;color:#334155;font-size:16px;'>
                    — The Product Team
                </p>
            </div>";
            break;
            
        case 'feedback_request':
            $feedbackUrl = $baseUrl . '/customer/beta-feedback.php';
            
            $body = "
            <div style='padding:32px 20px;'>
                <h2 style='margin:0 0 16px;color:#0f172a;font-size:24px;'>How Was Your Beta Sample?</h2>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    Hi {$name},
                </p>
                <p style='margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;'>
                    We hope you've had a chance to try your <strong>{$productName}</strong>! 
                    Your honest feedback is crucial to perfecting this product.
                </p>
                
                <div style='text-align:center;margin:32px 0;'>
                    <a href='{$feedbackUrl}' style='display:inline-block;background:#0ea5e9;color:#fff;padding:16px 40px;border-radius:8px;text-decoration:none;font-weight:700;font-size:18px;'>
                        Share Your Feedback
                    </a>
                </div>
                
                <p style='margin:0 0 8px;color:#334155;font-size:14px;line-height:1.6;'>
                    We'd love to hear about:
                </p>
                <ul style='margin:0 0 16px;color:#334155;font-size:14px;line-height:1.6;'>
                    <li>Taste and clarity</li>
                    <li>Effects and duration</li>
                    <li>Overall experience</li>
                    <li>Suggestions for improvement</li>
                </ul>
                
                <p style='margin:0;color:#334155;font-size:16px;'>
                    Thank you for being part of our beta testing program!
                </p>
                <p style='margin:8px 0 0;color:#334155;font-size:16px;'>
                    — The Product Team
                </p>
            </div>";
            break;
            
        default:
            $body = '<div style="padding:32px 20px;"><p>No content available.</p></div>';
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body style='margin:0;padding:0;font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;'>
        <div style='max-width:600px;margin:0 auto;background:#ffffff;'>
            {$header}
            {$body}
            {$footer}
        </div>
    </body>
    </html>";
}

/**
 * Get plain text email content
 */
function get_beta_sample_email_text(string $type, array $data): string
{
    $productName = $data['product_name'] ?? 'Beta Sample';
    $name = $data['name'] ?? 'Customer';
    
    switch ($type) {
        case 'request_received':
            $requestId = $data['request_id'] ?? '';
            return "Hi {$name},\n\nThank you for requesting a {$productName}! Your request (#{$requestId}) has been received and will be reviewed by our operations team.\n\nIMPORTANT: This is a beta test product that has not been lab tested for final quality information. By accepting this sample, you agree to the beta testing waiver.\n\nYou'll receive tracking information once your sample ships (typically within 2-3 business days).\n\n— The Product Team";
            
        case 'approved':
            return "Hi {$name},\n\nGreat news! Your {$productName} request has been approved and will ship within 1-2 business days.\n\nYou'll receive tracking information soon.\n\n— The Product Team";
            
        case 'shipped':
            $trackingNumber = $data['tracking_number'] ?? 'N/A';
            $carrier = $data['carrier'] ?? 'USPS';
            return "Hi {$name},\n\nYour {$productName} has shipped!\n\nTracking Number: {$trackingNumber}\nCarrier: {$carrier}\n\nOnce you try it, we'd love your honest feedback!\n\n— The Product Team";
            
        case 'feedback_request':
            return "Hi {$name},\n\nWe hope you've had a chance to try your {$productName}!\n\nYour honest feedback is crucial to perfecting this product. Please take a moment to share your thoughts.\n\nThank you for being part of our beta testing program!\n\n— The Product Team";
            
        default:
            return '';
    }
}

