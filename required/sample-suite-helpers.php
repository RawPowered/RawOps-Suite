<?php
/**
 * Shared helpers for Sample Suite automation (kickoff + follow-up chain)
 */

if (!function_exists('db')) {
    throw new Exception('Config must be loaded before sample-suite-helpers.php');
}

require_once __DIR__ . '/../api/sendgrid.php';

/**
 * Cached lookup for Sample Suite chain id
 */
function sample_suite_get_chain_id(PDO $pdo): ?int
{
    static $chainId = null;
    if ($chainId !== null) {
        return $chainId ?: null;
    }

    $stmt = $pdo->prepare("SELECT id FROM email_workflow_chains WHERE chain_name = ? LIMIT 1");
    $stmt->execute(['Sample Suite v1']);
    $chainId = intval($stmt->fetchColumn());
    return $chainId ?: null;
}

function sample_suite_step_definitions(): array
{
    return [
        // Pre-offer + rotation cadence
        'offer_reminder_1' => ['step' => 101, 'default_delay_minutes' => 2 * 24 * 60, 'phase' => 'pre_offer'],
        'offer_reminder_2' => ['step' => 102, 'default_delay_minutes' => 2 * 24 * 60, 'phase' => 'pre_offer'],
        'offer_day5'       => ['step' => 103, 'default_delay_minutes' => 5 * 24 * 60, 'phase' => 'pre_offer'],
        'rotation_a'       => ['step' => 104, 'default_delay_minutes' => 7 * 24 * 60, 'phase' => 'rotation', 'allow_multiple' => true],
        'rotation_b'       => ['step' => 105, 'default_delay_minutes' => 7 * 24 * 60, 'phase' => 'rotation', 'allow_multiple' => true],
        'rotation_c'       => ['step' => 106, 'default_delay_minutes' => 7 * 24 * 60, 'phase' => 'rotation', 'allow_multiple' => true],
        'click_probe'      => ['step' => 107, 'default_delay_minutes' => 24 * 60, 'phase' => 'behavior'],
        'kickoff_resend_fast' => ['step' => 110, 'default_delay_minutes' => 30, 'phase' => 'kickoff_reengage'],
        'kickoff_resend_followup' => ['step' => 111, 'default_delay_minutes' => 120, 'phase' => 'kickoff_reengage'],
        'feedback'         => ['step' => 108, 'default_delay_minutes' => 5 * 24 * 60, 'phase' => 'post_delivery'],
        'human_escalation' => ['step' => 109, 'default_delay_minutes' => 7 * 24 * 60, 'phase' => 'final'],

        // Logistics / post-delivery (existing chain steps)
        'kickoff'   => ['step' => 1],
        'shipped'   => ['step' => 2, 'default_delay_minutes' => 30, 'phase' => 'logistics'],
        'delivered' => ['step' => 3, 'default_delay_minutes' => 24 * 60, 'phase' => 'post_delivery'],
        'nurture'   => ['step' => 4, 'default_delay_minutes' => 5 * 24 * 60, 'phase' => 'post_delivery'],
        'last_call' => ['step' => 5, 'default_delay_minutes' => 7 * 24 * 60, 'phase' => 'post_delivery'],
    ];
}

function sample_suite_pre_offer_sequence(): array
{
    return ['offer_reminder_1', 'offer_reminder_2', 'offer_day5'];
}

function sample_suite_rotation_sequence(): array
{
    return ['rotation_a', 'rotation_b', 'rotation_c'];
}

function sample_suite_decode_behavior_state($json): array
{
    if (empty($json)) {
        return [];
    }
    if (is_array($json)) {
        return $json;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function sample_suite_encode_behavior_state(array $state): string
{
    return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sample_suite_default_sample_product_id(PDO $pdo): ?int
{
    static $productId = null;
    if ($productId !== null) {
        return $productId ?: null;
    }

    $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = 'SAMPLE-BLUE-10-2OZ' LIMIT 1");
    $stmt->execute();
    $productId = intval($stmt->fetchColumn());
    if ($productId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE is_sample = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $productId = intval($stmt->fetchColumn());
    }
    return $productId ?: null;
}

function sample_suite_fetch_request_context(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare("
        SELECT 
            sr.*,
            p.name AS product_name,
            p.sku AS product_sku,
            CONCAT(agent.first_name, ' ', agent.last_name) AS agent_full_name,
            agent.email AS agent_email,
            agent.phone AS agent_phone,
            l.email AS lead_email,
            l.first_name AS lead_first_name,
            l.last_name AS lead_last_name,
            l.company_name AS lead_company_name,
            wa.business_name AS account_business_name,
            wa.user_id AS account_user_id,
            u.email AS account_user_email,
            ss.tracking_number,
            ss.carrier,
            ss.service_type,
            ss.estimated_delivery_date,
            ss.actual_delivery_date
        FROM sample_requests sr
        JOIN products p ON p.id = sr.sample_product_id
        JOIN users agent ON agent.id = sr.agent_id
        LEFT JOIN crm_leads l ON l.id = sr.lead_id
        LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
        LEFT JOIN users u ON u.id = wa.user_id
        LEFT JOIN sample_shipments ss
            ON ss.sample_request_id = sr.id
            AND ss.id = (
                SELECT s2.id FROM sample_shipments s2
                WHERE s2.sample_request_id = sr.id
                ORDER BY s2.updated_at DESC
                LIMIT 1
            )
        WHERE sr.id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        return null;
    }

    $recipientName = trim($request['lead_first_name'] . ' ' . $request['lead_last_name']);
    if ($recipientName === '') {
        $recipientName = $request['recipient_name'] ?: $request['account_business_name'] ?: $request['lead_company_name'] ?: 'there';
    }

    $recipientEmail = $request['lead_email'] ?: $request['recipient_email'] ?: $request['account_user_email'] ?: null;

    return array_merge($request, [
        'recipient_name_pretty' => $recipientName,
        'recipient_email_final' => $recipientEmail,
        'company_name_fallback' => $request['lead_company_name'] ?: $request['account_business_name'] ?: $request['recipient_name'],
        'agent_phone_formatted' => $request['agent_phone'] ?: 'N/A',
    ]);
}

function sample_suite_render_template(PDO $pdo, string $slug, array $vars): array
{
    $stmt = $pdo->prepare("SELECT subject, html_body, text_body FROM email_templates WHERE slug = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        throw new Exception("Template {$slug} not found");
    }

    $subject = $template['subject'] ?? '';
    $html = $template['html_body'] ?? '';
    $text = $template['text_body'] ?? strip_tags($html);

    foreach ($vars as $key => $value) {
        $token = '{{' . $key . '}}';
        $subject = str_replace($token, (string)$value, $subject);
        $html = str_replace($token, (string)$value, $html);
        $text = str_replace($token, (string)$value, $text);
    }

    $html = sample_suite_apply_conditionals($html, $vars);
    $text = sample_suite_apply_conditionals($text, $vars);

    $html = sample_suite_strip_brand_footer($html);
    $text = sample_suite_strip_brand_footer($text, true);

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text
    ];
}

function sample_suite_strip_brand_footer(string $content, bool $isText = false): string
{
    $patterns = [
        '/<p[^>]*>[^<]*Product\s*\|\s*Premium Wholesale Supplier[^<]*<\/p>/i',
        '/<p[^>]*>[^<]*Email:\s*sales@product\.org[^<]*<\/p>/i',
        '/<p[^>]*>[^<]*product\.org[^<]*<\/p>/i',
        '/<p[^>]*>[^<]*All rights reserved[^<]*<\/p>/i'
    ];
    foreach ($patterns as $pattern) {
        $content = preg_replace($pattern, '', $content);
    }
    if ($isText) {
        $content = preg_replace('/Product\s*\|\s*Premium Wholesale Supplier.*(?:\r?\n)*/i', '', $content);
        $content = preg_replace('/Email:\s*sales@product\.org.*(?:\r?\n)*/i', '', $content);
        $content = preg_replace('/product\.org.*(?:\r?\n)*/i', '', $content);
        $content = preg_replace('/All rights reserved.*(?:\r?\n)*/i', '', $content);
    }
    return $content;
}

/**
 * Create minimal placeholder sample request for Sample Suite kickoff
 * Placeholder is hidden from admin view until customer completes promo portal (via blank agent_notes filter)
 */
function sample_suite_create_placeholder_request(PDO $pdo, array $lead, int $agentId): int
{
    $leadId = intval($lead['id']);
    
    // Get default sample product
    $productId = sample_suite_default_sample_product_id($pdo);
    if (!$productId) {
        throw new Exception('No sample product available');
    }
    
    // Create placeholder with minimal data
    // Note: agent_notes is NULL = hidden from admin view
    $stmt = $pdo->prepare("
        INSERT INTO sample_requests (
            agent_id, lead_id, sample_product_id,
            request_type, quantity,
            recipient_name,
            status, requested_at, created_at
        ) VALUES (?, ?, ?, 'shipping', 1, ?, 'pending', NOW(), NOW())
    ");
    
    $recipientName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?: ($lead['company_name'] ?? 'Prospect');
    
    $stmt->execute([
        $agentId,
        $leadId,
        $productId,
        $recipientName
    ]);
    
    return intval($pdo->lastInsertId());
}

/**
 * Start Sample Suite email-only flow (no sample request yet)
 * Used when agent sends kickoff email before customer completes promo portal
 */
function sample_suite_start_email_only(PDO $pdo, array $lead, int $agentId): array
{
    $leadId = intval($lead['id']);
    
    // Validate lead has email
    if (empty($lead['email']) || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Lead must have a valid email address.');
    }
    
    // Get chain ID
    $chainId = sample_suite_get_chain_id($pdo);
    if (!$chainId) {
        throw new Exception('Sample Suite chain is not configured.');
    }
    
    // Check for existing active enrollment
    $checkStmt = $pdo->prepare("
        SELECT id FROM sample_suite_enrollments 
        WHERE lead_id = ? AND status IN ('pending','active')
        LIMIT 1
    ");
    $checkStmt->execute([$leadId]);
    if ($checkStmt->fetchColumn()) {
        throw new Exception('Sample Suite is already running for this lead.');
    }
    
    // Build minimal context from lead (no sample request yet)
    $agentStmt = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ?");
    $agentStmt->execute([$agentId]);
    $agent = $agentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $context = [
        'lead_id' => $leadId,
        'account_id' => null,
        'agent_id' => $agentId,
        'recipient_email_final' => $lead['email'],
        'recipient_name_pretty' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?: ($lead['company_name'] ?? 'there'),
        'agent_name' => trim(($agent['first_name'] ?? '') . ' ' . ($agent['last_name'] ?? '')),
        'agent_email' => $agent['email'] ?? '',
        'agent_phone_formatted' => $agent['phone'] ?? '',
        'company_name' => $lead['company_name'] ?? '',
        'product_name' => 'Sample Kit',
        'promo_request_url' => site_base_url() . '/promo/product-sample/?lead_id=' . $leadId . '&agent_id=' . $agentId
    ];
    
    // Render kickoff email template
    $templateVars = $context;
    $rendered = sample_suite_render_template($pdo, 'sample-suite-kickoff', $templateVars);
    $trackingToken = bin2hex(random_bytes(32));
    
    // Create agent_emails record
    $stmt = $pdo->prepare("
        INSERT INTO agent_emails (
            agent_id, lead_id, account_id, recipient_email, recipient_name,
            subject, body_html, body_text, status, tracking_token, created_at
        ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'draft', ?, NOW())
    ");
    $stmt->execute([
        $agentId,
        $leadId,
        $context['recipient_email_final'],
        $context['recipient_name_pretty'],
        $rendered['subject'],
        $rendered['html'],
        $rendered['text'],
        $trackingToken
    ]);
    $kickoffEmailId = intval($pdo->lastInsertId());
    
    // Send via SendGrid
    $customArgs = [
        'tracking_token' => $trackingToken,
        'email_id' => (string)$kickoffEmailId,
        'lead_id' => (string)$leadId,
        'agent_id' => (string)$agentId,
        'sample_suite' => 'kickoff'
    ];
    
    $sendResult = sendgrid_send([
        'to' => $context['recipient_email_final'],
        'to_name' => $context['recipient_name_pretty'],
        'from' => 'sample-006@example.com',
        'from_name' => 'Product',
        'reply_to' => $context['agent_email'],
        'subject' => $rendered['subject'],
        'html' => $rendered['html'],
        'text' => $rendered['text'],
        'tracking_token' => $trackingToken,
        'custom_args' => $customArgs
    ]);
    
    if (!$sendResult['ok']) {
        throw new Exception('Failed to send kickoff email: ' . ($sendResult['error'] ?? 'Unknown error'));
    }
    
    // Extract SendGrid message ID
    $messageId = null;
    if (isset($sendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $m)) {
        $messageId = trim($m[1]);
    }
    
    // Mark email as sent
    $pdo->prepare("UPDATE agent_emails SET status = 'sent', sent_at = NOW(), sendgrid_message_id = ? WHERE id = ?")
        ->execute([$messageId, $kickoffEmailId]);
    
    // Create enrollment WITHOUT sample_request_id (will be linked later)
    $enrollmentStmt = $pdo->prepare("
        INSERT INTO sample_suite_enrollments (
            sample_request_id, chain_id, lead_id, account_id, agent_id, kickoff_email_id,
            status, current_step_key, current_step_number, metadata, created_at
        ) VALUES (NULL, ?, ?, NULL, ?, ?, 'active', 'kickoff', 1, ?, NOW())
    ");
    $enrollmentStmt->execute([
        $chainId,
        $leadId,
        $agentId,
        $kickoffEmailId,
        json_encode([
            'email_only' => true,
            'awaiting_promo_signup' => true,
            'agent_email' => $context['agent_email'],
            'agent_phone' => $context['agent_phone_formatted']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    $enrollmentId = intval($pdo->lastInsertId());
    
    // Create step log
    $pdo->prepare("
        INSERT INTO sample_suite_step_logs (
            enrollment_id, step_key, step_number, attempt_number,
            workflow_id, schedule_id, agent_email_id, status,
            scheduled_for, sent_at, created_at
        ) VALUES (?, 'kickoff', 1, 1, NULL, NULL, ?, 'sent', NOW(), NOW(), NOW())
    ")->execute([$enrollmentId, $kickoffEmailId]);
    
    // Schedule follow-up reminder (2 days later if no response)
    try {
        require_once __DIR__ . '/../api/email-workflow-processor.php';
        $workflowStmt = $pdo->prepare("
            SELECT * FROM email_followup_workflows 
            WHERE chain_id = ? AND chain_step = 101 AND is_active = 1
            LIMIT 1
        ");
        $workflowStmt->execute([$chainId]);
        $workflow = $workflowStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($workflow) {
            $scheduledFor = date('Y-m-d H:i:s', time() + (2 * 24 * 60 * 60)); // 2 days
            $payload = [
                'sample_suite_step_key' => 'offer_reminder_1',
                'sample_suite_enrollment_id' => $enrollmentId,
                'lead_id' => $leadId,
                'agent_id' => $agentId,
                'promo_request_url' => $context['promo_request_url']
            ];
            
            schedule_followup(
                $pdo,
                $workflow,
                $kickoffEmailId,
                $lead['email'],
                $context['recipient_name_pretty'],
                $scheduledFor,
                $payload
            );
        }
    } catch (Throwable $e) {
        error_log("Quick start: Failed to schedule follow-up: " . $e->getMessage());
        // Non-blocking
    }
    
    return [
        'enrollment_id' => $enrollmentId, 
        'kickoff_email_id' => $kickoffEmailId
    ];
}

function sample_suite_start_chain(PDO $pdo, int $sampleRequestId, int $agentId): array
{
    $context = sample_suite_fetch_request_context($pdo, $sampleRequestId);
    if (!$context) {
        throw new Exception('Sample request not found');
    }
    if (intval($context['agent_id']) !== $agentId) {
        throw new Exception('You can only start Sample Suite on your own requests.');
    }
    if (empty($context['recipient_email_final']) || !filter_var($context['recipient_email_final'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('No valid recipient email is available for this sample request.');
    }

    $chainId = sample_suite_get_chain_id($pdo);
    if (!$chainId) {
        throw new Exception('Sample Suite chain is not configured in email_workflow_chains.');
    }

    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if ($enrollment) {
        return [
            'ok' => true,
            'enrollment_id' => intval($enrollment['id']),
            'kickoff_email_id' => intval($enrollment['kickoff_email_id']),
            'message' => 'Sample Suite is already running for this request.'
        ];
    }

    $templateVars = sample_suite_build_payload($context);
    $rendered = sample_suite_render_template($pdo, 'sample-suite-kickoff', $templateVars);
    $trackingToken = bin2hex(random_bytes(32));

    // Create agent_emails record first (as draft) so we have the ID for custom_args
    $stmt = $pdo->prepare("
        INSERT INTO agent_emails (
            agent_id, lead_id, account_id, recipient_email, recipient_name,
            subject, body_html, body_text, status, tracking_token, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW())
    ");
    $stmt->execute([
        $agentId,
        $context['lead_id'] ?: null,
        $context['account_id'] ?: null,
        $context['recipient_email_final'],
        $context['recipient_name_pretty'],
        $rendered['subject'],
        $rendered['html'],
        $rendered['text'],
        $trackingToken
    ]);
    $kickoffEmailId = intval($pdo->lastInsertId());

    // Now send via SendGrid with agent_email_id in custom_args for webhook reconciliation
    $sendResult = sendgrid_send([
        'to' => $context['recipient_email_final'],
        'to_name' => $context['recipient_name_pretty'],
        'from' => 'sample-006@example.com',
        'from_name' => 'Product',
        'subject' => $rendered['subject'],
        'html' => $rendered['html'],
        'text' => $rendered['text'],
        'tracking_token' => $trackingToken,
        'custom_args' => [
            'sample_request_id' => $sampleRequestId,
            'agent_id' => $agentId,
            'agent_email_id' => $kickoffEmailId,
            'sample_suite_step' => 'kickoff'
        ]
    ]);

    if (empty($sendResult['ok'])) {
        throw new Exception('Failed to send kickoff email: ' . ($sendResult['error'] ?? 'Unknown error'));
    }

    // Extract SendGrid message ID and update record to 'sent'
    $messageId = null;
    if (!empty($sendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $m)) {
        $messageId = trim($m[1]);
    }

    $pdo->prepare("
        UPDATE agent_emails 
        SET status = 'sent', sent_at = NOW(), sendgrid_message_id = ? 
        WHERE id = ?
    ")->execute([$messageId, $kickoffEmailId]);

    $enrollmentStmt = $pdo->prepare("
        INSERT INTO sample_suite_enrollments (
            sample_request_id, chain_id, lead_id, account_id, agent_id,
            kickoff_email_id, status, current_step_key, current_step_number,
            attempt_count, max_attempts, rotation_cursor, last_step_sent_at,
            metadata, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'active', 'kickoff', 1, 1, 10, 0, NOW(), ?, NOW())
    ");
    $enrollmentStmt->execute([
        $sampleRequestId,
        $chainId,
        $context['lead_id'] ?: null,
        $context['account_id'] ?: null,
        $agentId,
        $kickoffEmailId,
        json_encode([
            'product_name' => $context['product_name'],
            'agent_email' => $context['agent_email'],
            'agent_phone' => $context['agent_phone_formatted']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    $enrollmentId = intval($pdo->lastInsertId());

    $pdo->prepare("
        UPDATE sample_requests
        SET suite_enrollment_id = ?, suite_status = 'pending', suite_started_at = NOW()
        WHERE id = ?
    ")->execute([$enrollmentId, $sampleRequestId]);

    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId) ?: ['id' => $enrollmentId];
    $pdo->prepare("UPDATE sample_suite_enrollments SET status = 'active' WHERE id = ?")->execute([$enrollmentId]);

    // Create step log for kickoff email to track it properly
    $pdo->prepare("
        INSERT INTO sample_suite_step_logs (
            enrollment_id, step_key, step_number, attempt_number,
            workflow_id, schedule_id, agent_email_id, status,
            scheduled_for, sent_at, created_at
        ) VALUES (?, 'kickoff', 1, 1, NULL, NULL, ?, 'sent', NOW(), NOW(), NOW())
    ")->execute([$enrollmentId, $kickoffEmailId]);

    // queue unconditional 2-hour follow-up (sent regardless of opens/clicks)
    // Note: offer_reminder_1 will be scheduled AFTER this reminder is sent (via chaining)
    if (!in_array($context['status'], ['shipped', 'delivered'], true)) {
        $twoHourReminderAt = date('Y-m-d H:i:s', time() + (2 * 3600));
        
        // Debug logging
        error_log("[Sample Suite] Scheduling 2-hour reminder: current_time=" . date('Y-m-d H:i:s') . ", scheduled_for=" . $twoHourReminderAt . ", enrollment_id=" . $enrollmentId);
        
        sample_suite_schedule_step($pdo, $enrollment, 'kickoff_resend_followup', $context, [
            'scheduled_for' => $twoHourReminderAt
        ]);
    }

    // Auto-handle current status if already shipped/delivered
    if ($context['status'] === 'shipped') {
        sample_suite_handle_event($pdo, 'shipped', $sampleRequestId, ['context' => $context]);
    } elseif ($context['status'] === 'delivered') {
        sample_suite_handle_event($pdo, 'delivered', $sampleRequestId, ['context' => $context]);
    }

    return [
        'ok' => true,
        'enrollment_id' => $enrollmentId,
        'kickoff_email_id' => $kickoffEmailId,
        'message_id' => $messageId
    ];
}

function sample_suite_handle_event(PDO $pdo, string $eventKey, int $sampleRequestId, array $options = []): void
{
    try {
        $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
        if (!$enrollment) {
            return;
        }

        $context = $options['context'] ?? sample_suite_fetch_request_context($pdo, $sampleRequestId);
        if (!$context) {
            return;
        }

        if ($eventKey === 'shipped') {
            // pause offer cadence once logistics start
            sample_suite_cancel_pending_steps($pdo, intval($enrollment['id']), array_merge(
                sample_suite_pre_offer_sequence(),
                sample_suite_rotation_sequence(),
                ['click_probe']
            ));

            $scheduledFor = date('Y-m-d H:i:s', time() + 15 * 60);
            sample_suite_schedule_step($pdo, $enrollment, 'shipped', $context, [
                'scheduled_for' => $scheduledFor,
                'payload_overrides' => [
                    'sample_tracking' => $context['tracking_number'] ?? $context['shipping_tracking'],
                    'sample_carrier' => $context['carrier'] ?? 'Carrier',
                    'sample_eta' => sample_suite_eta_text($context)
                ]
            ]);

            $pdo->prepare("UPDATE sample_requests SET suite_status = 'in_transit' WHERE id = ?")->execute([$sampleRequestId]);
        }

        if ($eventKey === 'delivered') {
            $deliveredAt = !empty($options['delivered_at']) ? strtotime($options['delivered_at']) : (strtotime($context['delivered_at'] ?? '') ?: time());

            $checkInTime = date('Y-m-d H:i:s', $deliveredAt + (24 * 3600));
            sample_suite_schedule_step($pdo, $enrollment, 'delivered', $context, ['scheduled_for' => $checkInTime]);

            $nurtureTime = date('Y-m-d H:i:s', $deliveredAt + (5 * 24 * 3600));
            sample_suite_schedule_step($pdo, $enrollment, 'nurture', $context, ['scheduled_for' => $nurtureTime]);

            $lastCallTime = date('Y-m-d H:i:s', $deliveredAt + (7 * 24 * 3600));
            sample_suite_schedule_step($pdo, $enrollment, 'last_call', $context, ['scheduled_for' => $lastCallTime]);

            $feedbackTime = date('Y-m-d H:i:s', $deliveredAt + (8 * 24 * 3600));
            sample_suite_schedule_step($pdo, $enrollment, 'feedback', $context, ['scheduled_for' => $feedbackTime]);

            $pdo->prepare("UPDATE sample_requests SET suite_status = 'post_delivery' WHERE id = ?")->execute([$sampleRequestId]);
        }

        if ($eventKey === 'sale') {
            sample_suite_mark_sale($pdo, $sampleRequestId, ['source' => 'event']);
        }
    } catch (Throwable $e) {
        error_log('Sample Suite event handler error: ' . $e->getMessage());
    }
}

function sample_suite_schedule_step(PDO $pdo, array $enrollment, string $stepKey, array $context, array $options = []): ?int
{
    $defs = sample_suite_step_definitions();
    if (!isset($defs[$stepKey])) {
        return null;
    }
    $def = $defs[$stepKey];
    $allowMultiple = !empty($def['allow_multiple']);
    $forceSchedule = !empty($options['force']);

    if (!$forceSchedule && sample_suite_step_exists($pdo, intval($enrollment['id']), $stepKey, $allowMultiple)) {
        return null;
    }

    $workflow = sample_suite_fetch_workflow($pdo, $enrollment['chain_id'], $def['step']);
    if (!$workflow) {
        return null;
    }

    $parentEmail = sample_suite_fetch_parent_email($pdo, intval($enrollment['kickoff_email_id']));
    if (!$parentEmail) {
        return null;
    }

    $entityType = $enrollment['lead_id'] ? 'lead' : 'account';
    $entityId = $enrollment[$entityType . '_id'] ?? null;
    if (!$entityId) {
        return null;
    }

    $scheduledFor = $options['scheduled_for'] ?? date('Y-m-d H:i:s', time() + (($def['default_delay_minutes'] ?? 5) * 60));

    // Debug: log what time we're trying to schedule
    error_log("[Sample Suite] sample_suite_schedule_step called: step=$stepKey, requested_time=$scheduledFor, enrollment_id=" . $enrollment['id']);

    require_once __DIR__ . '/../api/email-workflow-processor.php';
    $attemptSeed = max(1, intval($enrollment['attempt_count'] ?? 1) + 1);
    $payloadOverridesBase = [
        'sample_suite_step_key' => $stepKey,
        'sample_suite_enrollment_id' => intval($enrollment['id']),
        'sample_suite_attempt_seed' => $attemptSeed
    ];
    $payloadOverrides = array_merge($payloadOverridesBase, $options['payload_overrides'] ?? []);
    $payload = sample_suite_build_payload($context, $payloadOverrides);

    $scheduleId = schedule_followup(
        $pdo,
        $workflow,
        $parentEmail,
        $entityType,
        $entityId,
        $scheduledFor,
        [
            'schedule_reason' => 'sample_suite:' . $stepKey,
            'scheduled_via' => 'auto',
            'chain_id' => $enrollment['chain_id'],
            'chain_step' => $def['step'],
            'payload' => $payload
        ]
    );
    
    // Debug: confirm what was inserted
    error_log("[Sample Suite] schedule_followup returned schedule_id=$scheduleId for step=$stepKey");

    $eventContext = [];
    $retryCount = intval($payloadOverrides['sample_suite_retry_count'] ?? 0);
    if ($retryCount > 0) {
        $eventContext['retry_count'] = $retryCount;
    }
    if ($forceSchedule) {
        $eventContext['forced'] = true;
    }
    $eventContextJson = $eventContext ? json_encode($eventContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $stmt = $pdo->prepare("
        INSERT INTO sample_suite_step_logs (
            enrollment_id, step_key, step_number, attempt_number, workflow_id,
            schedule_id, status, scheduled_for, event_context, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW())
    ");
    $stmt->execute([
        $enrollment['id'],
        $stepKey,
        $def['step'],
        $attemptSeed,
        $workflow['id'],
        $scheduleId,
        $scheduledFor,
        $eventContextJson
    ]);

    $pdo->prepare("
        UPDATE sample_suite_enrollments
        SET current_step_key = ?, current_step_number = ?, next_step_scheduled_for = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$stepKey, $def['step'], $scheduledFor, $enrollment['id']]);

    return $scheduleId;
}

function sample_suite_fetch_workflow(PDO $pdo, int $chainId, int $chainStep): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM email_followup_workflows WHERE chain_id = ? AND chain_step = ? LIMIT 1");
    $stmt->execute([$chainId, $chainStep]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sample_suite_fetch_parent_email(PDO $pdo, int $emailId): ?array
{
    if ($emailId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM agent_emails WHERE id = ? LIMIT 1");
    $stmt->execute([$emailId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sample_suite_fetch_enrollment(PDO $pdo, int $sampleRequestId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM sample_suite_enrollments WHERE sample_request_id = ? LIMIT 1");
    $stmt->execute([$sampleRequestId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sample_suite_step_exists(PDO $pdo, int $enrollmentId, string $stepKey, bool $allowMultiple = false): bool
{
    if ($allowMultiple) {
        $stmt = $pdo->prepare("SELECT id FROM sample_suite_step_logs WHERE enrollment_id = ? AND step_key = ? AND status = 'scheduled' LIMIT 1");
        $stmt->execute([$enrollmentId, $stepKey]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT id FROM sample_suite_step_logs 
        WHERE enrollment_id = ? 
          AND step_key = ? 
          AND status IN ('scheduled','sent','processing')
        LIMIT 1
    ");
    $stmt->execute([$enrollmentId, $stepKey]);
    return (bool)$stmt->fetchColumn();
}

function sample_suite_build_payload(array $context, array $overrides = []): array
{
    $eta = sample_suite_eta_text($context);
    $promoUrl = getenv('SAMPLE_SUITE_PROMO_URL') ?: 'https://product.example/promo/product-sample/?utm_source=sample-suite';
    $payload = [
        'recipient_name' => $context['recipient_name_pretty'],
        'agent_name' => $context['agent_full_name'],
        'agent_email' => $context['agent_email'],
        'agent_phone' => $context['agent_phone_formatted'],
        'company_name' => $context['company_name_fallback'],
        'product_name' => $context['product_name'],
        'sample_tracking' => $context['tracking_number'] ?? '',
        'sample_carrier' => $context['carrier'] ?? '',
        'sample_eta' => $eta,
        'sample_request_id' => $context['id'],
        'promo_request_url' => $promoUrl
    ];

    return array_merge($payload, $overrides);
}

function sample_suite_eta_text(array $context): string
{
    if (!empty($context['estimated_delivery_date'])) {
        return date('M j, Y', strtotime($context['estimated_delivery_date']));
    }
    return '3-5 business days';
}

function sample_suite_cancel_pending_steps(PDO $pdo, int $enrollmentId, array $stepKeys = []): void
{
    $params = [$enrollmentId];
    $where = "";
    if (!empty($stepKeys)) {
        $placeholders = implode(',', array_fill(0, count($stepKeys), '?'));
        $where = "AND step_key IN ($placeholders)";
        $params = array_merge($params, $stepKeys);
    }

    $stmt = $pdo->prepare("SELECT schedule_id FROM sample_suite_step_logs WHERE enrollment_id = ? $where AND status = 'scheduled'");
    $stmt->execute($params);
    $scheduleIds = array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    if (!$scheduleIds) {
        return;
    }

    $in = implode(',', array_fill(0, count($scheduleIds), '?'));
    $pdo->prepare("UPDATE email_followup_schedule SET status = 'cancelled', cancel_reason = 'sample_suite_cancelled', processed_at = NOW() WHERE id IN ($in)")
        ->execute($scheduleIds);
    $pdo->prepare("UPDATE sample_suite_step_logs SET status = 'cancelled', sent_at = NOW(), notes = 'auto_cancelled' WHERE schedule_id IN ($in)")
        ->execute($scheduleIds);
}

function sample_suite_mark_step_failed(PDO $pdo, int $enrollmentId, string $stepKey, ?int $scheduleId = null, ?string $reason = null): void
{
    if ($enrollmentId <= 0 || $stepKey === '') {
        return;
    }

    $sql = "UPDATE sample_suite_step_logs SET status = 'failed'";
    $params = [];

    if ($reason !== null && $reason !== '') {
        $sql .= ", notes = ?";
        $params[] = substr($reason, 0, 255);
    }

    $sql .= " WHERE enrollment_id = ? AND step_key = ?";
    $params[] = $enrollmentId;
    $params[] = $stepKey;

    if ($scheduleId) {
        $sql .= " AND schedule_id = ?";
        $params[] = $scheduleId;
    }

    $sql .= " ORDER BY id DESC LIMIT 1";

    $pdo->prepare($sql)->execute($params);
}

function sample_suite_queue_retry_on_failure(PDO $pdo, array $payload, string $stepKey, string $errorMessage = ''): void
{
    $sampleRequestId = intval($payload['sample_request_id'] ?? 0);
    if ($sampleRequestId <= 0 || $stepKey === '') {
        return;
    }

    $retryCount = intval($payload['sample_suite_retry_count'] ?? 0);
    $maxRetries = max(1, intval(getenv('SAMPLE_SUITE_MAX_RETRIES') ?: 3));
    if ($retryCount >= $maxRetries) {
        return;
    }

    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if (!$enrollment) {
        return;
    }

    $context = sample_suite_fetch_request_context($pdo, $sampleRequestId);
    if (!$context) {
        return;
    }

    $delayMinutes = min(240, max(15, ($retryCount + 1) * 15));
    $scheduledFor = date('Y-m-d H:i:s', time() + ($delayMinutes * 60));

    $overrides = [
        'sample_suite_retry_count' => $retryCount + 1,
        'sample_suite_last_error' => substr($errorMessage, 0, 255)
    ];

    sample_suite_schedule_step($pdo, $enrollment, $stepKey, $context, [
        'scheduled_for' => $scheduledFor,
        'payload_overrides' => $overrides,
        'force' => true
    ]);
}

function sample_suite_after_step_sent(PDO $pdo, array $payload, array $followup, int $agentEmailId): void
{
    $stepKey = $payload['sample_suite_step_key'] ?? null;
    $sampleRequestId = intval($payload['sample_request_id'] ?? 0);
    if (!$stepKey || $sampleRequestId <= 0) {
        return;
    }

    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if (!$enrollment) {
        return;
    }

    $scheduleId = intval($followup['id'] ?? 0);
    $attemptSeed = intval($payload['sample_suite_attempt_seed'] ?? 0);

    $pdo->prepare("
        UPDATE sample_suite_step_logs
        SET status = 'sent',
            sent_at = NOW(),
            sent_email_id = ?,
            agent_email_id = ?,
            attempt_number = COALESCE(attempt_number, ?)
        WHERE enrollment_id = ? AND step_key = ? AND schedule_id = ?
        LIMIT 1
    ")->execute([
        $agentEmailId,
        $agentEmailId,
        $attemptSeed ?: null,
        $enrollment['id'],
        $stepKey,
        $scheduleId
    ]);

    $defs = sample_suite_step_definitions();
    $stepNumber = $defs[$stepKey]['step'] ?? 0;
    $currentAttempts = intval($enrollment['attempt_count'] ?? 1);
    $newAttempts = max($currentAttempts, $attemptSeed ?: ($currentAttempts + 1));
    if (in_array($stepKey, ['kickoff_resend_fast', 'kickoff_resend_followup'], true)) {
        $newAttempts = $currentAttempts;
    }

    $state = sample_suite_decode_behavior_state($enrollment['behavior_state'] ?? null);
    $scheduleFollowupAfterKick = false;
    if ($stepKey === 'kickoff_resend_fast') {
        $state['kickoff_resend_fast_sent'] = true;
        if (empty($state['kickoff_resend_followup_scheduled'])) {
            $state['kickoff_resend_followup_scheduled'] = true;
            $scheduleFollowupAfterKick = true;
        }
    } elseif ($stepKey === 'kickoff_resend_followup') {
        $state['kickoff_resend_followup_sent'] = true;
    }
    if ($stepKey === 'click_probe') {
        $state['click_probe_sent'] = true;
        unset($state['click_probe_scheduled']);
    }

    $rotationCursor = intval($enrollment['rotation_cursor'] ?? 0);
    if (in_array($stepKey, sample_suite_rotation_sequence(), true)) {
        $rotationKeys = sample_suite_rotation_sequence();
        $idx = array_search($stepKey, $rotationKeys, true);
        if ($idx !== false) {
            $rotationCursor = ($idx + 1) % count($rotationKeys);
        }
    }

    $pdo->prepare("
        UPDATE sample_suite_enrollments
        SET attempt_count = ?, last_step_sent_at = NOW(), current_step_key = ?, current_step_number = ?, rotation_cursor = ?, behavior_state = ?
        WHERE id = ?
    ")->execute([
        $newAttempts,
        $stepKey,
        $stepNumber,
        $rotationCursor,
        sample_suite_encode_behavior_state($state),
        $enrollment['id']
    ]);

    // refresh enrollment snapshot for scheduling decisions
    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if (!$enrollment) {
        return;
    }
    $context = sample_suite_fetch_request_context($pdo, $sampleRequestId);
    if (!$context) {
        return;
    }
    $state = sample_suite_decode_behavior_state($enrollment['behavior_state'] ?? null);
    if ($scheduleFollowupAfterKick && $context && sample_suite_pre_offer_allowed($context, $enrollment)) {
        $followupTime = date('Y-m-d H:i:s', time() + 4 * 3600);
        sample_suite_schedule_step($pdo, $enrollment, 'kickoff_resend_followup', $context, [
            'scheduled_for' => $followupTime
        ]);
    }

    if (!empty($enrollment['sale_detected_at']) || in_array($enrollment['status'], ['completed', 'cancelled'], true)) {
        return;
    }

    $clicks = intval($state['clicks'] ?? 0);
    $maxAttempts = intval($enrollment['max_attempts'] ?? 10);
    $attemptCount = intval($enrollment['attempt_count'] ?? 1);

    // Schedule escalation if we hit the cap without clicks
    if ($stepKey !== 'human_escalation' && $clicks === 0 && $attemptCount >= ($maxAttempts - 1)) {
        sample_suite_schedule_step($pdo, $enrollment, 'human_escalation', $context);
        return;
    }

    // Continue offer/rotation cadence when applicable
    if (
        in_array($stepKey, sample_suite_pre_offer_sequence(), true) ||
        in_array($stepKey, sample_suite_rotation_sequence(), true) ||
        in_array($stepKey, ['click_probe', 'kickoff_resend_fast', 'kickoff_resend_followup'], true)
    ) {
        sample_suite_queue_next_pre_offer_step($pdo, $enrollment, $context, $stepKey, $state);
        return;
    }

    // After last_call in post-delivery, keep gentle rotation if attempts remain
    if ($stepKey === 'last_call' && $attemptCount < $maxAttempts) {
        sample_suite_queue_next_pre_offer_step($pdo, $enrollment, $context, 'rotation_c', $state, true);
    }
}

function sample_suite_queue_next_pre_offer_step(PDO $pdo, array $enrollment, array $context, string $justSentKey, array $state = [], bool $force = false): void
{
    if (!$force && !sample_suite_pre_offer_allowed($context, $enrollment)) {
        return;
    }

    $maxAttempts = intval($enrollment['max_attempts'] ?? 10);
    $attemptCount = intval($enrollment['attempt_count'] ?? 1);
    if ($attemptCount >= ($maxAttempts - 1)) {
        return; // reserved for escalation logic handled elsewhere
    }

    $nextKey = sample_suite_determine_next_offer_key($justSentKey, $enrollment);
    if (!$nextKey) {
        $rotation = sample_suite_rotation_sequence();
        $cursor = intval($enrollment['rotation_cursor'] ?? 0) % count($rotation);
        $nextKey = $rotation[$cursor];
    }

    $defs = sample_suite_step_definitions();
    $delayMinutes = $defs[$nextKey]['default_delay_minutes'] ?? (3 * 24 * 60);
    $scheduledFor = date('Y-m-d H:i:s', time() + ($delayMinutes * 60));
    sample_suite_schedule_step($pdo, $enrollment, $nextKey, $context, ['scheduled_for' => $scheduledFor]);
}

function sample_suite_pre_offer_allowed(array $context, array $enrollment): bool
{
    if (!empty($enrollment['sale_detected_at'])) {
        return false;
    }

    $suiteStatus = $context['suite_status'] ?? null;
    if (in_array($suiteStatus, ['post_delivery', 'completed', 'cancelled'], true)) {
        return false;
    }

    $requestStatus = $context['status'] ?? 'pending';
    if (in_array($requestStatus, ['shipped', 'delivered'], true)) {
        return false;
    }

    return true;
}

function sample_suite_determine_next_offer_key(string $currentKey, array $enrollment): ?string
{
    $sequence = sample_suite_pre_offer_sequence();
    $rotation = sample_suite_rotation_sequence();

    // After kickoff reminders, proceed to offer_reminder_1
    if (in_array($currentKey, ['kickoff_resend_fast', 'kickoff_resend_followup'], true)) {
        return 'offer_reminder_1';
    }

    $idx = array_search($currentKey, $sequence, true);
    if ($idx !== false) {
        return $sequence[$idx + 1] ?? null;
    }

    if ($currentKey === 'offer_day5' || $currentKey === 'click_probe') {
        $cursor = intval($enrollment['rotation_cursor'] ?? 0) % count($rotation);
        return $rotation[$cursor];
    }

    if (in_array($currentKey, $rotation, true)) {
        $cursor = intval($enrollment['rotation_cursor'] ?? 0) % count($rotation);
        return $rotation[$cursor];
    }

    return null;
}

function sample_suite_mark_sale(PDO $pdo, int $sampleRequestId, array $options = []): array
{
    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if (!$enrollment) {
        return ['ok' => false, 'error' => 'No enrollment'];
    }
    if (!empty($enrollment['sale_detected_at'])) {
        return ['ok' => true, 'message' => 'Sale already recorded'];
    }

    sample_suite_cancel_pending_steps($pdo, intval($enrollment['id']));

    $state = sample_suite_decode_behavior_state($enrollment['behavior_state'] ?? null);
    $state['sale'] = true;

    $pdo->prepare("
        UPDATE sample_suite_enrollments
        SET sale_detected_at = NOW(), status = 'completed', finalized_reason = 'sale', behavior_state = ?
        WHERE id = ?
    ")->execute([sample_suite_encode_behavior_state($state), $enrollment['id']]);

    $pdo->prepare("
        UPDATE sample_requests
        SET suite_status = 'completed', suite_completed_at = NOW()
        WHERE id = ?
    ")->execute([$sampleRequestId]);

    return ['ok' => true];
}

function sample_suite_mark_unsubscribed(PDO $pdo, int $sampleRequestId, array $options = []): void
{
    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if (!$enrollment) {
        return;
    }

    sample_suite_cancel_pending_steps($pdo, intval($enrollment['id']));
    $state = sample_suite_decode_behavior_state($enrollment['behavior_state'] ?? null);
    $state['unsubscribed'] = true;

    $pdo->prepare("
        UPDATE sample_suite_enrollments
        SET unsubscribe_at = NOW(), status = 'paused', behavior_state = ?, finalized_reason = 'unsubscribe'
        WHERE id = ?
    ")->execute([sample_suite_encode_behavior_state($state), $enrollment['id']]);
}

function sample_suite_record_engagement(PDO $pdo, int $sampleRequestId, string $eventType, array $event = []): void
{
    $enrollment = sample_suite_fetch_enrollment($pdo, $sampleRequestId);
    if (!$enrollment) {
        return;
    }

    $state = sample_suite_decode_behavior_state($enrollment['behavior_state'] ?? null);
    $now = $event['event_at'] ?? date('Y-m-d H:i:s');
    $context = null;
    $agentEmailId = intval($event['agent_email_id'] ?? 0);

    switch ($eventType) {
        case 'open':
            $state['opens'] = intval($state['opens'] ?? 0) + 1;
            $state['last_open_at'] = $now;
            $kickoffEmailId = intval($enrollment['kickoff_email_id'] ?? 0);
            $hasClicks = intval($state['clicks'] ?? 0) > 0;
            if (
                $agentEmailId &&
                $agentEmailId === $kickoffEmailId &&
                empty($state['kickoff_resend_fast_scheduled']) &&
                empty($state['kickoff_resend_fast_sent']) &&
                !$hasClicks
            ) {
                $context = $context ?: sample_suite_fetch_request_context($pdo, $sampleRequestId);
                if ($context && sample_suite_pre_offer_allowed($context, $enrollment)) {
                    $state['kickoff_resend_fast_scheduled'] = true;
                    $openedAt = strtotime($now) ?: time();
                    $scheduledFor = date('Y-m-d H:i:s', $openedAt + (30 * 60));
                    sample_suite_schedule_step($pdo, $enrollment, 'kickoff_resend_fast', $context, [
                        'scheduled_for' => $scheduledFor
                    ]);
                    
                    // Cancel the 2-hour unconditional reminder since they opened it
                    sample_suite_cancel_pending_steps($pdo, intval($enrollment['id']), ['kickoff_resend_followup']);
                    $state['kickoff_resend_followup_cancelled_on_open'] = true;
                }
            }
            break;
        case 'click':
            $state['clicks'] = intval($state['clicks'] ?? 0) + 1;
            $state['last_click_at'] = $now;
            
            // Cancel all kickoff reminders when they click
            sample_suite_cancel_pending_steps($pdo, intval($enrollment['id']), ['kickoff_resend_fast', 'kickoff_resend_followup']);
            $state['kickoff_reminders_cancelled_on_click'] = true;
            
            // Schedule click probe if not already scheduled/sent
            if (empty($state['click_probe_sent']) && empty($state['click_probe_scheduled'])) {
                $context = $context ?: sample_suite_fetch_request_context($pdo, $sampleRequestId);
                if ($context && sample_suite_pre_offer_allowed($context, $enrollment)) {
                    $state['click_probe_scheduled'] = true;
                    sample_suite_schedule_step($pdo, $enrollment, 'click_probe', $context, [
                        'scheduled_for' => date('Y-m-d H:i:s', time() + 24 * 3600)
                    ]);
                }
            }
            break;
        case 'unsubscribe':
            sample_suite_mark_unsubscribed($pdo, $sampleRequestId);
            break;
        case 'sale':
            sample_suite_mark_sale($pdo, $sampleRequestId, ['source' => 'engagement']);
            break;
    }

    $pdo->prepare("
        UPDATE sample_suite_enrollments
        SET last_behavior = ?, last_engagement = ?, last_engaged_at = ?, behavior_state = ?
        WHERE id = ?
    ")->execute([
        $eventType,
        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $now,
        sample_suite_encode_behavior_state($state),
        $enrollment['id']
    ]);

    $pdo->prepare("
        INSERT INTO sample_suite_engagements (
            enrollment_id, sample_request_id, agent_email_id, event_type, event_payload, event_at
        ) VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $enrollment['id'],
        $sampleRequestId,
        $event['agent_email_id'] ?? null,
        $eventType,
        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $now
    ]);
}

function sample_suite_apply_conditionals(string $content, array $vars): string
{
    $pattern = '/\{\{#if\s+([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/if\}\}/s';
    return preg_replace_callback($pattern, function ($matches) use ($vars) {
        $key = $matches[1];
        $block = $matches[2];
        $value = $vars[$key] ?? '';
        if (empty($value)) {
            return '';
        }
        return $block;
    }, $content);
}

