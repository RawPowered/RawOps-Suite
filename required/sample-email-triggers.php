<?php
/**
 * Sample Email Trigger Functions
 * Handles SendGrid email notifications for sample request lifecycle
 * 
 * Requires: api/sendgrid.php
 * Used by: api/promo-product-pack-submit.php, operations/sample-fulfillment.php
 */

if (!function_exists('db')) {
    throw new Exception('Database connection required. Include config.php first.');
}

require_once __DIR__ . '/../api/sendgrid.php';

/**
 * Send email notification for sample request event
 * 
 * @param PDO $pdo Database connection
 * @param string $event Event type: account_created, review_submitted, approved, shipped, delivered
 * @param int $sampleRequestId Sample request ID
 * @param array $customData Additional data to override/supplement
 * @return array Result with 'success', 'customer_sent', 'agent_sent', 'errors'
 */
function send_sample_email_notification($pdo, $event, $sampleRequestId, $customData = []) {
$result = [
    'success' => false,
    'customer_sent' => false,
    'agent_sent' => false,
    'admin_sent' => false,
    'errors' => []
];
    
    try {
        // Get sample request details
        $stmt = $pdo->prepare('
            SELECT 
                sr.*,
                p.name as product_name,
                p.sku as product_sku,
                CONCAT(agent.first_name, " ", agent.last_name) as agent_name,
                agent.email as agent_email,
                agent.phone as agent_phone,
                COALESCE(l.company_name, wa.business_name, sr.recipient_name) as company_name,
                l.email as lead_email,
                l.first_name as lead_first_name,
                l.last_name as lead_last_name,
                wa.user_id as customer_user_id,
                u.email as customer_email,
                CONCAT(u.first_name, " ", u.last_name) as customer_full_name
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            JOIN users agent ON agent.id = sr.agent_id
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
            LEFT JOIN users u ON u.id = wa.user_id
            WHERE sr.id = ?
        ');
        $stmt->execute([$sampleRequestId]);
        $sr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sr) {
            $result['errors'][] = 'Sample request not found';
            return $result;
        }
        
        // Determine recipient email and name
        $recipientEmail = $customData['recipient_email'] ?? $sr['customer_email'] ?? $sr['lead_email'] ?? $sr['recipient_email'];
        $recipientName = $customData['recipient_name'] ?? $sr['customer_full_name'] ?? ($sr['lead_first_name'] . ' ' . $sr['lead_last_name']) ?? $sr['recipient_name'];
        
        if (!$recipientEmail) {
            $result['errors'][] = 'No recipient email found';
            return $result;
        }
        
        // Map events to templates
        $templateMap = [
            'account_created' => [
                'customer' => 'product-pack_trial_account_created',
                'agent' => 'product-pack_trial_agent_account_created'
            ],
            'review_submitted' => [
                'customer' => null, // No customer email for review
                'agent' => 'product-pack_trial_agent_review_submitted'
            ],
            'sample_requested' => [
                'customer' => '3oz_trial_sample_requested_customer',
                'agent' => '3oz_trial_sample_requested_agent'
            ],
            'approved' => [
                'customer' => 'sample_approved_customer',
                'agent' => null
            ],
        'shipped' => [
            'customer' => 'sample_approved_customer', // Has tracking info
            'agent' => 'sample_shipped_agent',
            'admin' => 'sample_shipped_agent'
        ],
            'delivered' => [
                'customer' => 'sample_delivered',
                'agent' => null
            ]
        ];
        
        if (!isset($templateMap[$event])) {
            $result['errors'][] = "Unknown event type: {$event}";
            return $result;
        }
        
        // Build template variables
        $variables = array_merge([
            'recipient_name' => $recipientName,
            'customer_name' => $recipientName,
            'customer_email' => $recipientEmail,
            'customer_phone' => $sr['recipient_phone'] ?? '',
            'company_name' => $sr['company_name'] ?? 'Your Store',
            'agent_name' => $sr['agent_name'] ?? '',
            'agent_email' => $sr['agent_email'] ?? '',
            'agent_phone' => $sr['agent_phone'] ?? '',
            'product_name' => $sr['product_name'] ?? 'product-pack Sample',
            'tracking_number' => $sr['tracking_number'] ?? 'Pending',
            'tracking_url' => $sr['tracking_number'] ? 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($sr['tracking_number']) : '',
            'tracking_info' => $sr['tracking_number'] ? "<p><strong>Tracking:</strong> {$sr['tracking_number']}</p>" : '',
            'carrier' => $sr['carrier'] ?? 'USPS',
            'service_type' => $sr['service_type'] ?? 'Priority Mail',
            'delivery_days' => '3-5',
            'rating' => '',
            'review_text' => ''
        ], $customData);
        $emailData = $variables;
        
        // Send customer email if template exists
        if ($templateMap[$event]['customer']) {
            $customerTemplate = $templateMap[$event]['customer'];
            $stmt = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = ? AND is_active = 1');
            $stmt->execute([$customerTemplate]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                // Replace variables in template
                $subject = replace_template_vars($template['subject'], $variables);
                $htmlBody = replace_template_vars($template['html_body'], $variables);
                $textBody = replace_template_vars($template['text_body'] ?? strip_tags($htmlBody), $variables);
                
                // Send email
                $sendResult = sendgrid_send([
                    'to' => $recipientEmail,
                    'to_name' => $recipientName,
                    'from' => 'sample-006@example.com',
                    'from_name' => 'Product',
                    'subject' => $subject,
                    'html' => $htmlBody,
                    'text' => $textBody
                ]);
                
                if ($sendResult['ok']) {
                    $result['customer_sent'] = true;
                    
                    // Log email event
                    log_sample_email_event($pdo, $sampleRequestId, $event, $customerTemplate, $recipientEmail, 'customer', $sendResult);
                } else {
                    $result['errors'][] = 'Customer email failed: ' . ($sendResult['error'] ?? 'Unknown error');
                }
            }
        }
        
        // Send agent email if template exists
        if ($templateMap[$event]['agent'] && $sr['agent_email']) {
            $agentTemplate = $templateMap[$event]['agent'];
            $stmt = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = ? AND is_active = 1');
            $stmt->execute([$agentTemplate]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                // Replace variables in template
                $subject = replace_template_vars($template['subject'], $variables);
                $htmlBody = replace_template_vars($template['html_body'], $variables);
                $textBody = replace_template_vars($template['text_body'] ?? strip_tags($htmlBody), $variables);
                
                // Send email
                $sendResult = sendgrid_send([
                    'to' => $sr['agent_email'],
                    'to_name' => $sr['agent_name'],
                    'from' => 'sample-006@example.com',
                    'from_name' => 'Product CRM',
                    'subject' => $subject,
                    'html' => $htmlBody,
                    'text' => $textBody
                ]);
                
                if ($sendResult['ok']) {
                    $result['agent_sent'] = true;
                    
                    // Log email event
                    log_sample_email_event($pdo, $sampleRequestId, $event, $agentTemplate, $sr['agent_email'], 'agent', $sendResult);
                } else {
                    $result['errors'][] = 'Agent email failed: ' . ($sendResult['error'] ?? 'Unknown error');
                }
            }
        }
        
        if (!empty($templateMap[$event]['admin'])) {
            $operationsEmail = get_operations_notification_email();
            if ($operationsEmail) {
                $adminTemplate = $templateMap[$event]['admin'];
                $stmt = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = ? AND is_active = 1');
                $stmt->execute([$adminTemplate]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($template) {
                    $subject = replace_template_vars($template['subject'], $variables);
                    $htmlBody = replace_template_vars($template['html_body'], $variables);
                    $textBody = replace_template_vars($template['text_body'] ?? strip_tags($htmlBody), $variables);
                    
                    $sendResult = sendgrid_send([
                        'to' => $operationsEmail,
                        'to_name' => 'Operations Team',
                        'from' => 'sample-006@example.com',
                        'from_name' => 'Product CRM',
                        'subject' => $subject,
                        'html' => $htmlBody,
                        'text' => $textBody
                    ]);
                    
                    if ($sendResult['ok']) {
                        $result['admin_sent'] = true;
                        log_sample_email_event($pdo, $sampleRequestId, $event, $adminTemplate, $operationsEmail, 'admin', $sendResult);
                    } else {
                        $result['errors'][] = 'Admin email failed: ' . ($sendResult['error'] ?? 'Unknown error');
                    }
                }
            }
        }
        
        // Notify operations team for new sample requests
        if ($event === 'sample_requested' && $result['agent_sent']) {
            $operationsEmail = get_operations_notification_email();
            
            $opsEmailData = array_merge($emailData, [
                'to_email' => $operationsEmail,
                'to_name' => 'Operations Team',
                'product_type' => $customData['product_type'] ?? 'N/A',
                'product_name' => $customData['product_name'] ?? $sr['product_name'] ?? 'N/A'
            ]);
            
            $opsTemplate = '3oz_trial_ops_notification';
            
            // Try to send operations notification (don't fail if template doesn't exist)
            try {
                $variables = build_email_variables($opsEmailData);
                $template = load_email_template($opsTemplate);
                
                if ($template) {
                    $subject = replace_template_vars($template['subject'], $variables);
                    $htmlBody = replace_template_vars($template['html_body'], $variables);
                    $textBody = replace_template_vars($template['text_body'] ?? strip_tags($htmlBody), $variables);
                    
                    $opsResult = sendgrid_send([
                        'to' => $operationsEmail,
                        'to_name' => 'Operations Team',
                        'from' => 'sample-006@example.com',
                        'from_name' => 'Product CRM',
                        'subject' => $subject,
                        'html' => $htmlBody,
                        'text' => $textBody
                    ]);
                    
                    $result['ops_sent'] = $opsResult['ok'];
                    if (!$opsResult['ok']) {
                        $result['errors'][] = 'Operations notification failed: ' . ($opsResult['error'] ?? 'Unknown');
                    }
                }
            } catch (Exception $e) {
                $result['errors'][] = 'Operations notification error: ' . $e->getMessage();
            }
        }
        
        $result['success'] = ($result['customer_sent'] || $result['agent_sent'] || $result['admin_sent']);
        
    } catch (Exception $e) {
        $result['errors'][] = 'Exception: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Replace template variables in text
 */
function replace_template_vars($text, $variables) {
    foreach ($variables as $key => $value) {
        $text = str_replace('{' . $key . '}', $value, $text);
    }
    return $text;
}

/**
 * Log sample email event to database
 */
function log_sample_email_event($pdo, $sampleRequestId, $eventType, $templateSlug, $recipientEmail, $recipientType, $sendResult) {
    try {
        // Extract SendGrid message ID if available
        $messageId = null;
        if (isset($sendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $matches)) {
            $messageId = trim($matches[1]);
        }
        
        $stmt = $pdo->prepare('
            INSERT INTO sample_email_events (
                sample_request_id, event_type, template_slug, 
                recipient_email, recipient_type, email_sent, 
                sent_at, sendgrid_message_id
            ) VALUES (?, ?, ?, ?, ?, 1, NOW(), ?)
        ');
        
        $stmt->execute([
            $sampleRequestId,
            $eventType,
            $templateSlug,
            $recipientEmail,
            $recipientType,
            $messageId
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to log sample email event: " . $e->getMessage());
    }
}

/**
 * Log CRM activity for sample request event
 * 
 * @param PDO $pdo Database connection
 * @param int $leadId CRM lead ID
 * @param string $activityType Activity type
 * @param string $description Activity description
 * @param array $metadata Additional metadata (JSON)
 * @return bool Success
 */
function log_sample_crm_activity($pdo, $leadId, $activityType, $description, $metadata = []) {
    if (!$leadId) {
        return false;
    }
    
    try {
        // Check if crm_activities table exists, otherwise use crm_lead_activities
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'crm_activities'")->fetchColumn();
        $table = $tableCheck ? 'crm_activities' : 'crm_lead_activities';
        
        $stmt = $pdo->prepare("
            INSERT INTO {$table} (
                lead_id, activity_type, description, metadata, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $leadId,
            $activityType,
            $description,
            json_encode($metadata)
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Failed to log CRM activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Resolve operations notification email with config fallback
 */
function get_operations_notification_email(): string {
    static $cachedEmail = null;
    
    if ($cachedEmail !== null) {
        return $cachedEmail;
    }
    
    $cachedEmail = 'sample-031@example.com';
    
    try {
        $configPath = __DIR__ . '/site_config.json';
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            if (!empty($config['operations_email'])) {
                $cachedEmail = $config['operations_email'];
            }
        }
    } catch (Exception $e) {
        // keep default
    }
    
    return $cachedEmail;
}

/**
 * Create sample request from product-pack trial account creation
 * 
 * @param PDO $pdo Database connection
 * @param int $agentId Sales agent ID
 * @param int $leadId CRM lead ID (optional)
 * @param int $accountId Wholesale account ID (optional)
 * @param array $recipientData Recipient information
 * @return int|false Sample request ID or false on failure
 */
function create_product_pack_trial_sample_request($pdo, $agentId, $leadId, $accountId, $recipientData) {
    try {
        // Get product-pack sample product
        $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND is_sample = 1 AND active = 1');
        $stmt->execute(['SAMPLE-product-pack-32-20']);
        $productId = $stmt->fetchColumn();
        
        if (!$productId) {
            error_log('product-pack sample product not found');
            return false;
        }
        
        // Create sample request
        $stmt = $pdo->prepare('
            INSERT INTO sample_requests (
                agent_id, lead_id, account_id, sample_product_id,
                request_type, quantity, promo_source,
                recipient_name, recipient_email, recipient_phone,
                recipient_address_line1, recipient_address_line2,
                recipient_city, recipient_state, recipient_zip, recipient_country,
                status, requested_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        
        $stmt->execute([
            $agentId,
            $leadId ?: null,
            $accountId ?: null,
            $productId,
            'shipping',
            1, // quantity
            'product-sample',
            $recipientData['name'] ?? '',
            $recipientData['email'] ?? '',
            $recipientData['phone'] ?? '',
            $recipientData['address_line1'] ?? '',
            $recipientData['address_line2'] ?? '',
            $recipientData['city'] ?? '',
            $recipientData['state'] ?? '',
            $recipientData['zip'] ?? '',
            $recipientData['country'] ?? 'USA',
            'pending'
        ]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Failed to create product-pack trial sample request: " . $e->getMessage());
        return false;
    }
}


