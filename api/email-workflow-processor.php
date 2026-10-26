<?php
/**
 * Email Follow-up Workflow Processor
 * Evaluates email engagement and schedules automated follow-ups
 * Called by email-queue-processor.php or cron
 */

require_once __DIR__ . '/../required/config.php';

/**
 * Main entry point: Process emails that may need follow-ups
 */
function process_email_workflows($pdo): array {
    $stats = [
        'evaluated' => 0,
        'scheduled' => 0,
        'skipped' => 0,
        'errors' => 0
    ];
    
    try {
        // Find emails sent in last 30 days that haven't been processed yet
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $sql = "SELECT ae.*,
                       pending.id AS pending_followup_id
                FROM agent_emails ae
                LEFT JOIN email_followup_schedule pending
                  ON pending.parent_email_id = ae.id
                 AND pending.status IN ('pending','processing')
                WHERE ae.status IN ('sent','delivered') 
                  AND ae.sent_at >= ? 
                  AND (
                        (ae.workflow_selection_mode IS NULL AND pending.id IS NULL)
                     OR ae.workflow_selection_mode = 'conditional'
                  )
                ORDER BY ae.sent_at ASC 
                LIMIT 100";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cutoff]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($emails as $email) {
            $stats['evaluated']++;
            try {
                $manualResult = handle_manual_workflow_selection($pdo, $email);
                if (is_array($manualResult)) {
                    if (!empty($manualResult['scheduled'])) {
                        $stats['scheduled']++;
                    } else {
                        $stats['skipped']++;
                    }
                    continue;
                }
                $result = evaluate_workflow_triggers($pdo, $email);
                if ($result['scheduled']) {
                    $stats['scheduled']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                error_log("Workflow evaluation error for email #{$email['id']}: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log("Workflow processor error: " . $e->getMessage());
        $stats['errors']++;
    }
    
    return $stats;
}

/**
 * Evaluate a single email for workflow triggers
 */
function evaluate_workflow_triggers($pdo, array $email): array {
    $emailId = intval($email['id']);
    $agentId = intval($email['agent_id']);
    $leadId = intval($email['lead_id'] ?? 0);
    $accountId = intval($email['account_id'] ?? 0);
    
    // Skip if this is already a follow-up
    if (!empty($email['followup_parent_id'])) {
        return ['scheduled' => false, 'reason' => 'is_followup'];
    }
    
    // Check if entity has workflows enabled
    $entityType = $leadId > 0 ? 'lead' : ($accountId > 0 ? 'account' : null);
    $entityId = $leadId > 0 ? $leadId : $accountId;
    
    if (!$entityType || !$entityId) {
        return ['scheduled' => false, 'reason' => 'no_entity'];
    }
    
    // Check automation settings
    if (!is_workflow_automation_enabled($pdo, $entityType, $entityId, $agentId)) {
        return ['scheduled' => false, 'reason' => 'automation_disabled'];
    }
    
    // Determine trigger type based on email behavior
    $triggerType = determine_trigger_type($pdo, $email);
    
    if (!$triggerType) {
        return ['scheduled' => false, 'reason' => 'no_trigger'];
    }
    
    // Find applicable workflow (hierarchy: entity > agent > global)
    $workflow = find_applicable_workflow($pdo, $triggerType, $agentId, $leadId, $accountId);
    
    if (!$workflow || !$workflow['is_active']) {
        return ['scheduled' => false, 'reason' => 'no_workflow'];
    }
    
    // Get entity-specific settings
    $entitySettings = get_entity_workflow_settings($pdo, $entityType, $entityId, $workflow['id']);
    
    // Check if entity has this workflow disabled
    if ($entitySettings && !$entitySettings['is_enabled']) {
        return ['scheduled' => false, 'reason' => 'entity_disabled'];
    }
    
    // Check max follow-ups limit
    $followupCount = count_existing_followups($pdo, $emailId);
    $maxFollowups = get_max_followups($pdo, $entityType, $entityId);
    
    if ($followupCount >= $maxFollowups) {
        return ['scheduled' => false, 'reason' => 'max_followups_reached'];
    }
    
    // Calculate delay hours (workflow default or entity-specific override)
    $delayHours = intval($workflow['trigger_delay_hours']);
    if ($entitySettings && $entitySettings['custom_delay_hours'] !== null) {
        $delayHours = intval($entitySettings['custom_delay_hours']);
    }
    
    // Calculate send time: sent_at + delay_hours (always schedule for future, even if delay is 0)
    $sentAt = strtotime($email['sent_at']);
    $scheduledFor = date('Y-m-d H:i:s', $sentAt + ($delayHours * 3600));
    
    // If the scheduled time is in the past (shouldn't happen, but handle gracefully), set to now + delay
    if (strtotime($scheduledFor) < time()) {
        $scheduledFor = date('Y-m-d H:i:s', time() + ($delayHours * 3600));
    }
    
    // Apply time-of-day optimization if enabled (overrides calculated time)
    if ($entitySettings && 
        $entitySettings['allow_time_of_day_optimization'] && 
        $entitySettings['preferred_send_hour'] !== null) {
        
        $preferredHour = intval($entitySettings['preferred_send_hour']);
        $calculatedTime = strtotime($scheduledFor);
        $todayAtHour = strtotime(date('Y-m-d') . ' ' . $preferredHour . ':00:00');
        
        // If preferred hour today is after calculated time, use it
        if ($todayAtHour > $calculatedTime) {
            $scheduledFor = date('Y-m-d H:i:s', $todayAtHour);
        } else {
            // Otherwise use tomorrow at preferred hour
            $scheduledFor = date('Y-m-d H:i:s', strtotime('+1 day', $todayAtHour));
        }
    }
    
    // Schedule the follow-up
    $scheduleId = schedule_followup($pdo, $workflow, $email, $entityType, $entityId, $scheduledFor, [
        'scheduled_via' => 'auto',
        'schedule_reason' => 'auto_trigger:' . $triggerType
    ]);
    
    return [
        'scheduled' => true,
        'schedule_id' => $scheduleId,
        'workflow_id' => $workflow['id'],
        'trigger_type' => $triggerType,
        'scheduled_for' => $scheduledFor
    ];
}

/**
 * Determine trigger type based on email engagement
 */
function determine_trigger_type($pdo, array $email): ?string {
    $emailId = intval($email['id']);
    $openCount = intval($email['open_count'] ?? 0);
    $clickCount = intval($email['click_count'] ?? 0);
    $hasConversion = intval($email['has_conversion'] ?? 0);
    $contributedToSale = intval($email['contributed_to_sale'] ?? 0);
    $relatedOrderId = intval($email['related_order_id'] ?? 0);
    
    // Priority order: most engaged to least engaged
    
    // Sale scenarios
    if ($contributedToSale && $relatedOrderId) {
        // Check if AI was involved
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_conversions WHERE agent_email_id = ? AND conversion_type = "ai_chat"');
        $stmt->execute([$emailId]);
        $hasAiChat = $stmt->fetchColumn() > 0;
        
        if ($hasAiChat) {
            return 'clicked_ai_interaction';
        }
        
        // Check if agent followed up
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM agent_emails WHERE followup_parent_id = ? AND status IN ("sent", "delivered")');
        $stmt->execute([$emailId]);
        $hasAgentFollowup = $stmt->fetchColumn() > 0;
        
        return $hasAgentFollowup ? 'clicked_sale_with_agent' : 'clicked_sale_no_agent';
    }
    
    // Click scenarios
    if ($clickCount > 0) {
        if (!$hasConversion) {
            return 'clicked_no_action';
        }
        
        // Has conversion but no sale
        return 'clicked_no_sale';
    }
    
    // Open scenarios
    if ($openCount > 0 && $clickCount === 0) {
        return 'opened_not_clicked';
    }
    
    // Not opened
    if ($openCount === 0) {
        return 'unopened';
    }
    
    return null;
}

/**
 * Find best matching workflow for trigger (hierarchy: entity > agent > global)
 */
function find_applicable_workflow($pdo, string $triggerType, int $agentId, int $leadId, int $accountId): ?array {
    $entityType = $leadId > 0 ? 'lead' : 'account';
    $entityId = $leadId > 0 ? $leadId : $accountId;
    
    // Try entity-specific first
    if ($entityId > 0) {
        $stmt = $pdo->prepare("
            SELECT w.* FROM email_followup_workflows w
            WHERE w.trigger_type = ?
            AND w.scope = ?
            AND w.is_active = 1
            ORDER BY w.priority DESC
            LIMIT 1
        ");
        $stmt->execute([$triggerType, $entityType]);
        $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($workflow) return $workflow;
    }
    
    // Try agent-specific
    $stmt = $pdo->prepare("
        SELECT w.* FROM email_followup_workflows w
        WHERE w.trigger_type = ?
        AND w.scope = 'agent'
        AND w.owner_agent_id = ?
        AND w.is_active = 1
        ORDER BY w.priority DESC
        LIMIT 1
    ");
    $stmt->execute([$triggerType, $agentId]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($workflow) return $workflow;
    
    // Fall back to global
    $stmt = $pdo->prepare("
        SELECT w.* FROM email_followup_workflows w
        WHERE w.trigger_type = ?
        AND w.scope = 'global'
        AND w.is_active = 1
        ORDER BY w.priority DESC
        LIMIT 1
    ");
    $stmt->execute([$triggerType]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get entity-specific workflow settings
 */
function get_entity_workflow_settings($pdo, string $entityType, int $entityId, int $workflowId): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM email_followup_entity_settings
        WHERE entity_type = ? AND entity_id = ? AND workflow_id = ?
        LIMIT 1
    ");
    $stmt->execute([$entityType, $entityId, $workflowId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Check if workflow automation is enabled for entity
 */
function is_workflow_automation_enabled($pdo, string $entityType, int $entityId, int $agentId): bool {
    // Check entity-specific settings first
    $stmt = $pdo->prepare("
        SELECT allow_followup_workflows FROM email_automation_settings
        WHERE entity_type = ? AND entity_id = ?
        LIMIT 1
    ");
    $stmt->execute([$entityType, $entityId]);
    $setting = $stmt->fetchColumn();
    
    if ($setting !== false) {
        return (bool)$setting;
    }
    
    // Check agent settings
    $stmt = $pdo->prepare("
        SELECT allow_followup_workflows FROM email_automation_settings
        WHERE entity_type = 'agent' AND entity_id = ?
        LIMIT 1
    ");
    $stmt->execute([$agentId]);
    $setting = $stmt->fetchColumn();
    
    if ($setting !== false) {
        return (bool)$setting;
    }
    
    // Fall back to global
    $stmt = $pdo->query("
        SELECT allow_followup_workflows FROM email_automation_settings
        WHERE entity_type = 'global' AND entity_id IS NULL
        LIMIT 1
    ");
    $setting = $stmt->fetchColumn();
    
    return $setting !== false ? (bool)$setting : false;
}

/**
 * Count existing follow-ups for an email
 */
function count_existing_followups($pdo, int $parentEmailId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM email_followup_schedule
        WHERE parent_email_id = ? AND status IN ('pending', 'sent')
    ");
    $stmt->execute([$parentEmailId]);
    return intval($stmt->fetchColumn());
}

/**
 * Get maximum allowed follow-ups for entity
 */
function get_max_followups($pdo, string $entityType, int $entityId): int {
    $stmt = $pdo->prepare("
        SELECT max_followups_per_email FROM email_automation_settings
        WHERE entity_type = ? AND entity_id = ?
        LIMIT 1
    ");
    $stmt->execute([$entityType, $entityId]);
    $max = $stmt->fetchColumn();
    
    if ($max !== false) {
        return intval($max);
    }
    
    // Fall back to global
    $stmt = $pdo->query("
        SELECT max_followups_per_email FROM email_automation_settings
        WHERE entity_type = 'global' AND entity_id IS NULL
        LIMIT 1
    ");
    $max = $stmt->fetchColumn();
    
    return $max !== false ? intval($max) : 3;
}

/**
 * Calculate optimal send time based on settings
 */
function calculate_send_time(?array $entitySettings): string {
    // Default: 5 minutes from now
    $scheduledFor = time() + (5 * 60);
    
    // Apply time-of-day optimization if enabled
    if ($entitySettings && 
        $entitySettings['allow_time_of_day_optimization'] && 
        $entitySettings['preferred_send_hour'] !== null) {
        
        $preferredHour = intval($entitySettings['preferred_send_hour']);
        $now = time();
        $todayAtHour = strtotime(date('Y-m-d') . ' ' . $preferredHour . ':00:00');
        
        if ($todayAtHour > $now) {
            // Today at preferred hour
            $scheduledFor = $todayAtHour;
        } else {
            // Tomorrow at preferred hour
            $scheduledFor = strtotime('+1 day', $todayAtHour);
        }
    }
    
    return date('Y-m-d H:i:s', $scheduledFor);
}

/**
 * Schedule a follow-up email
 */
function schedule_followup($pdo, array $workflow, array $parentEmail, string $entityType, int $entityId, string $scheduledFor, array $meta = []): int {
    // DEBUG: Log what we're ACTUALLY inserting
    error_log("[SCHEDULE_FOLLOWUP] Inserting: workflow_id=" . $workflow['id'] . ", parent=" . $parentEmail['id'] . ", scheduled_for=$scheduledFor, reason=" . ($meta['schedule_reason'] ?? 'none'));
    
    $stmt = $pdo->prepare("
        INSERT INTO email_followup_schedule 
        (workflow_id, parent_email_id, entity_type, entity_id, recipient_email, scheduled_for, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        $workflow['id'],
        $parentEmail['id'],
        $entityType,
        $entityId,
        $parentEmail['recipient_email'],
        $scheduledFor
    ]);
    
    $scheduleId = intval($pdo->lastInsertId());
    
    // DEBUG: Verify what was actually inserted
    $verify = $pdo->prepare("SELECT scheduled_for FROM email_followup_schedule WHERE id = ?");
    $verify->execute([$scheduleId]);
    $inserted = $verify->fetchColumn();
    error_log("[SCHEDULE_FOLLOWUP] Inserted schedule_id=$scheduleId, VERIFY scheduled_for=$inserted (requested=$scheduledFor)");
    
    if (!empty($meta)) {
        $updates = [];
        $params = [];
        if (!empty($meta['schedule_reason'])) {
            $updates[] = 'schedule_reason = ?';
            $params[] = substr($meta['schedule_reason'], 0, 255);
        }
        if (!empty($meta['scheduled_via'])) {
            $updates[] = 'scheduled_via = ?';
            $params[] = $meta['scheduled_via'];
        }
        if (!empty($meta['chain_id'])) {
            $updates[] = 'chain_id = ?';
            $params[] = intval($meta['chain_id']);
        }
        if (isset($meta['chain_step'])) {
            $updates[] = 'chain_step = ?';
            $params[] = intval($meta['chain_step']);
        }
        if (!empty($meta['payload'])) {
            $updates[] = 'payload_json = ?';
            $params[] = json_encode($meta['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($updates) {
            $params[] = $scheduleId;
            $pdo->prepare('UPDATE email_followup_schedule SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        }
    }
    
    return $scheduleId;
}

function log_workflow_execution(PDO $pdo, int $scheduleId, int $workflowId, int $parentEmailId, array $data = []): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO email_workflow_execution_log (schedule_id, workflow_id, agent_email_id, step_number, condition_evaluated, decision_made, next_workflow_id, execution_time_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $scheduleId,
            $workflowId,
            $parentEmailId,
            intval($data['step_number'] ?? 0),
            isset($data['condition']) ? json_encode($data['condition']) : null,
            $data['decision'] ?? null,
            $data['next_workflow_id'] ?? null,
            $data['execution_time_ms'] ?? null
        ]);
    } catch (Throwable $e) {
        // Log failures silently; diagnostics only
        error_log('workflow_execution_log error: ' . $e->getMessage());
    }
}

function get_email_entity_context(array $email): array {
    if (!empty($email['lead_id'])) {
        return ['lead', intval($email['lead_id'])];
    }
    if (!empty($email['account_id'])) {
        return ['account', intval($email['account_id'])];
    }
    return [null, null];
}

function decode_email_workflow_selection(array $email): ?array {
    if (empty($email['workflow_selection_mode']) || empty($email['workflow_selection_json'])) {
        return null;
    }
    $decoded = json_decode($email['workflow_selection_json'], true);
    if (!is_array($decoded)) {
        return null;
    }
    return [
        'mode' => $email['workflow_selection_mode'],
        'data' => $decoded,
        'reason' => $email['workflow_schedule_reason'] ?? null
    ];
}

function clear_email_workflow_selection(PDO $pdo, int $emailId): void {
    $stmt = $pdo->prepare('UPDATE agent_emails SET workflow_selection_mode = NULL, workflow_selection_json = NULL, workflow_schedule_reason = NULL WHERE id = ?');
    $stmt->execute([$emailId]);
}

function schedule_manual_workflow(PDO $pdo, array $email, int $workflowId, string $reason, string $via = 'manual_single'): array {
    [$entityType, $entityId] = get_email_entity_context($email);
    if (!$entityType || !$entityId) {
        return ['scheduled' => false, 'reason' => 'no_entity'];
    }
    
    $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$workflowId]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$workflow) {
        return ['scheduled' => false, 'reason' => 'workflow_not_found'];
    }
    
    $entitySettings = get_entity_workflow_settings($pdo, $entityType, $entityId, $workflowId);
    $delayHours = intval($workflow['trigger_delay_hours'] ?? 0);
    if ($entitySettings && $entitySettings['custom_delay_hours'] !== null) {
        $delayHours = intval($entitySettings['custom_delay_hours']);
    }
    $delaySeconds = max(0, $delayHours * 3600);
    $sentAt = !empty($email['sent_at']) ? strtotime($email['sent_at']) : time();
    $scheduledFor = date('Y-m-d H:i:s', max($sentAt + $delaySeconds, time() + 60));
    
    $scheduleId = schedule_followup($pdo, $workflow, $email, $entityType, $entityId, $scheduledFor, [
        'schedule_reason' => $reason,
        'scheduled_via' => $via
    ]);
    
    log_workflow_execution($pdo, $scheduleId, $workflowId, intval($email['id']), [
        'decision' => $via,
        'condition' => ['manual_reason' => $reason]
    ]);
    
    return [
        'scheduled' => true,
        'schedule_id' => $scheduleId,
        'workflow_id' => $workflowId,
        'scheduled_for' => $scheduledFor
    ];
}

function handle_manual_workflow_selection(PDO $pdo, array $email): ?array {
    if (empty($email['workflow_selection_mode'])) {
        return null;
    }
    
    $selection = decode_email_workflow_selection($email);
    if (!$selection) {
        clear_email_workflow_selection($pdo, intval($email['id']));
        return ['scheduled' => false, 'reason' => 'invalid_selection'];
    }
    
    $mode = $selection['mode'];
    $data = $selection['data'];
    $reason = $selection['reason'] ?: 'Agent attached workflow';
    
    if ($mode === 'single') {
        $workflowId = intval($data['workflow_id'] ?? 0);
        if ($workflowId <= 0) {
            clear_email_workflow_selection($pdo, intval($email['id']));
            return ['scheduled' => false, 'reason' => 'missing_workflow_id'];
        }
        $result = schedule_manual_workflow($pdo, $email, $workflowId, $reason, 'manual_single');
        if (!empty($result['scheduled'])) {
            clear_email_workflow_selection($pdo, intval($email['id']));
        }
        return $result;
    }
    
    if ($mode === 'conditional') {
        $openedWorkflowId = intval($data['opened_workflow_id'] ?? 0) ?: null;
        $unopenedWorkflowId = intval($data['unopened_workflow_id'] ?? 0) ?: null;
        $decisionWaitHours = max(1, intval($data['decision_wait_hours'] ?? 48));
        $sentAt = !empty($email['sent_at']) ? strtotime($email['sent_at']) : time();
        $hoursSinceSend = (time() - $sentAt) / 3600;
        $openCount = intval($email['open_count'] ?? 0);
        
        if ($openCount > 0 && $openedWorkflowId) {
            $result = schedule_manual_workflow($pdo, $email, $openedWorkflowId, 'manual_conditional_opened', 'manual_conditional');
            if (!empty($result['scheduled'])) {
                clear_email_workflow_selection($pdo, intval($email['id']));
            }
            return $result;
        }
        
        if ($openCount === 0 && $hoursSinceSend >= $decisionWaitHours && $unopenedWorkflowId) {
            $result = schedule_manual_workflow($pdo, $email, $unopenedWorkflowId, 'manual_conditional_unopened', 'manual_conditional');
            if (!empty($result['scheduled'])) {
                clear_email_workflow_selection($pdo, intval($email['id']));
            }
            return $result;
        }
        
        return ['scheduled' => false, 'reason' => 'conditional_waiting'];
    }
    
    return null;
}

/**
 * Send a scheduled follow-up
 */
function send_scheduled_followup($pdo, array $followup): array {
    $followupId = intval($followup['id']);
    $workflowId = intval($followup['workflow_id']);
    $parentEmailId = intval($followup['parent_email_id']);
    
    try {
        // Mark as processing
        $pdo->prepare("UPDATE email_followup_schedule SET status = 'processing' WHERE id = ?")->execute([$followupId]);
        
        // Get workflow details
        $stmt = $pdo->prepare("SELECT * FROM email_followup_workflows WHERE id = ?");
        $stmt->execute([$workflowId]);
        $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$workflow) {
            throw new Exception("Workflow not found");
        }
        
        // Get parent email
        $stmt = $pdo->prepare("SELECT * FROM agent_emails WHERE id = ?");
        $stmt->execute([$parentEmailId]);
        $parentEmail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$parentEmail) {
            throw new Exception("Parent email not found");
        }
        
        // Generate or load email content
        $payload = [];
        if (!empty($followup['payload_json'])) {
            $decoded = json_decode($followup['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($workflow['allow_ai_generation']) {
            // AI will generate follow-up content
            $emailContent = generate_ai_followup($pdo, $workflow, $parentEmail);
        } elseif ($workflow['template_id']) {
            // Use template
            $emailContent = load_template_for_followup($pdo, $workflow['template_id'], $parentEmail, $payload);
        } else {
            throw new Exception("No content source configured for workflow");
        }
        
        // Create agent_email record
        require_once __DIR__ . '/sendgrid.php';
        require_once __DIR__ . '/../required/email-functions.php';
        $trackingToken = bin2hex(random_bytes(32));
        
        $stmt = $pdo->prepare("
            INSERT INTO agent_emails 
            (agent_id, lead_id, account_id, recipient_email, recipient_name, subject, body_html, body_text, 
             is_ai_assisted, status, followup_parent_id, tracking_token, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, NOW())
        ");
        
        $stmt->execute([
            $parentEmail['agent_id'],
            $parentEmail['lead_id'],
            $parentEmail['account_id'],
            $followup['recipient_email'],
            $parentEmail['recipient_name'],
            $emailContent['subject'],
            $emailContent['body_html'],
            strip_tags($emailContent['body_html']),
            $workflow['allow_ai_generation'] ? 1 : 0,
            $parentEmailId,
            $trackingToken
        ]);
        
        $newEmailId = intval($pdo->lastInsertId());
        
        // Send via SendGrid (no AI disclaimer)
        $customArgs = [
            'agent_email_id' => $newEmailId,
            'parent_schedule_id' => $followupId
        ];
        if (!empty($payload['sample_request_id'])) {
            $customArgs['sample_request_id'] = intval($payload['sample_request_id']);
        }
        if (!empty($payload['sample_suite_enrollment_id'])) {
            $customArgs['sample_suite_enrollment_id'] = intval($payload['sample_suite_enrollment_id']);
        }
        if (!empty($payload['sample_suite_step_key'])) {
            $customArgs['sample_suite_step'] = $payload['sample_suite_step_key'];
        }

        $sendResult = sendgrid_send([
            'to' => $followup['recipient_email'],
            'to_name' => $parentEmail['recipient_name'] ?? '',
            'from' => 'sample-006@example.com',
            'from_name' => 'Product',
            'subject' => $emailContent['subject'],
            'html' => $emailContent['body_html'],
            'text' => strip_tags($emailContent['body_html']),
            'tracking_token' => $trackingToken,
            'custom_args' => $customArgs
        ]);
        
        if (!$sendResult['ok']) {
            throw new Exception('SendGrid send failed: ' . ($sendResult['error'] ?? 'Unknown error'));
        }
        
        // Update email as sent
        $sentMessageId = null;
        if (isset($sendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $m)) {
            $sentMessageId = trim($m[1]);
        }
        $pdo->prepare("UPDATE agent_emails SET status = 'sent', sent_at = NOW(), sendgrid_message_id = ?, tracking_token = ? WHERE id = ?")
            ->execute([$sentMessageId, $trackingToken, $newEmailId]);
        
        // Update schedule as sent
        $stmt = $pdo->prepare("
            UPDATE email_followup_schedule 
            SET status = 'sent', sent_email_id = ?, processed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newEmailId, $followupId]);
        
        // Hook Sample Suite cadence if applicable
        if (!empty($payload['sample_suite_step_key']) && !empty($payload['sample_request_id'])) {
            if (!function_exists('sample_suite_after_step_sent')) {
                require_once __DIR__ . '/../required/sample-suite-helpers.php';
            }
            sample_suite_after_step_sent($pdo, $payload, $followup, $newEmailId);
        }

        // Check if workflow has conditions (decision tree logic)
        if (!empty($workflow['has_conditions'])) {
            $nextWorkflowId = evaluate_workflow_conditions($pdo, $workflowId, $parentEmail);
            
            if ($nextWorkflowId) {
                // Schedule next workflow in the chain
                $stmt = $pdo->prepare("SELECT * FROM email_followup_workflows WHERE id = ?");
                $stmt->execute([$nextWorkflowId]);
                $nextWorkflow = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($nextWorkflow && $nextWorkflow['is_active']) {
                    $nextScheduledFor = date('Y-m-d H:i:s', time() + (intval($nextWorkflow['trigger_delay_hours']) * 3600));
                    
                    // Schedule next step in chain
                    $pdo->prepare("
                        INSERT INTO email_followup_schedule 
                        (workflow_id, parent_email_id, entity_type, entity_id, recipient_email, scheduled_for, status, schedule_reason, scheduled_via, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 'auto', NOW())
                    ")->execute([
                        $nextWorkflowId,
                        $parentEmailId,
                        $followup['entity_type'],
                        $followup['entity_id'],
                        $followup['recipient_email'],
                        $nextScheduledFor,
                        'auto_chain:' . $workflowId
                    ]);
                }
            }
        }
        
        log_workflow_execution($pdo, $followupId, $workflowId, $parentEmailId, [
            'decision' => 'auto_send',
            'next_workflow_id' => $nextWorkflowId ?? null,
            'condition' => ['scheduled_via' => $followup['scheduled_via'] ?? 'auto']
        ]);
        
        return ['ok' => true, 'email_id' => $newEmailId, 'chained_next' => $nextWorkflowId ?? null];
        
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        // Mark as failed
        $pdo->prepare("
            UPDATE email_followup_schedule 
            SET status = 'failed', cancel_reason = ?, processed_at = NOW()
            WHERE id = ?
        ")->execute([$errorMessage, $followupId]);

        if (!empty($payload['sample_suite_step_key']) && !empty($payload['sample_request_id'])) {
            if (!function_exists('sample_suite_mark_step_failed')) {
                require_once __DIR__ . '/../required/sample-suite-helpers.php';
            }
            $enrollmentId = intval($payload['sample_suite_enrollment_id'] ?? 0);
            sample_suite_mark_step_failed($pdo, $enrollmentId, $payload['sample_suite_step_key'], $followupId, $errorMessage);
            sample_suite_queue_retry_on_failure($pdo, $payload, $payload['sample_suite_step_key'], $errorMessage);
        }
        
        error_log("Follow-up send failed #{$followupId}: " . $errorMessage);
        
        return ['ok' => false, 'error' => $errorMessage];
    }
}

/**
 * Generate AI follow-up content (placeholder - will be implemented in ai-email-composer.php)
 */
function generate_ai_followup($pdo, array $workflow, array $parentEmail): array {
    // For now, return a simple follow-up
    // This will be enhanced with actual AI generation
    return [
        'subject' => 'Re: ' . $parentEmail['subject'],
        'body_html' => '<p>Following up on my previous email...</p>'
    ];
}

/**
 * Load template for follow-up
 */
function load_template_for_followup($pdo, int $templateId, array $parentEmail, array $payload = []): array {
    // First try workflow-specific template
    $stmt = $pdo->prepare("SELECT * FROM email_workflow_templates WHERE workflow_id = ? LIMIT 1");
    $stmt->execute([$templateId]);
    $workflowTemplate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($workflowTemplate) {
        return apply_followup_replacements([
            'subject' => $workflowTemplate['subject_template'],
            'body_html' => $workflowTemplate['body_template']
        ], $parentEmail, $payload);
    }
    
    // Fall back to global email template
    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        throw new Exception("Template not found");
    }
    
    // Simple variable replacement
    $html = $template['html_body'];
    $subject = $template['subject'];
    
    return apply_followup_replacements([
        'subject' => $subject,
        'body_html' => $html
    ], $parentEmail, $payload);
}

function apply_followup_replacements(array $content, array $parentEmail, array $payload = []): array {
    $replacements = array_merge([
        'recipient_name' => $parentEmail['recipient_name'] ?? '',
        'first_name' => $parentEmail['recipient_name'] ?? '',
        'agent_name' => $payload['agent_name'] ?? '',
        'agent_email' => $payload['agent_email'] ?? '',
        'agent_phone' => $payload['agent_phone'] ?? '',
        'company_name' => $payload['company_name'] ?? '',
        'product_name' => $payload['product_name'] ?? '',
        'sample_tracking' => $payload['sample_tracking'] ?? '',
        'sample_eta' => $payload['sample_eta'] ?? '',
        'sample_carrier' => $payload['sample_carrier'] ?? '',
        'tracking_pixel' => $payload['tracking_pixel'] ?? ''
    ], $payload);
    
    $content['subject'] = apply_followup_conditionals($content['subject'], $replacements);
    $content['body_html'] = apply_followup_conditionals($content['body_html'], $replacements);
    
    foreach ($replacements as $key => $value) {
        $token = '{{' . $key . '}}';
        $content['subject'] = str_replace($token, (string)$value, $content['subject']);
        $content['body_html'] = str_replace($token, (string)$value, $content['body_html']);
    }
    
    return $content;
}

function apply_followup_conditionals(string $text, array $replacements): string {
    $pattern = '/\{\{#if\s+([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/if\}\}/s';
    return preg_replace_callback($pattern, function($matches) use ($replacements) {
        $key = $matches[1];
        $inner = $matches[2];
        $value = $replacements[$key] ?? '';
        $hasValue = false;
        if (is_string($value)) {
            $hasValue = trim($value) !== '';
        } elseif (is_numeric($value)) {
            $hasValue = true;
        } elseif (is_bool($value)) {
            $hasValue = $value;
        } elseif (is_array($value)) {
            $hasValue = !empty($value);
        } else {
            $hasValue = !empty($value);
        }
        return $hasValue ? $inner : '';
    }, $text);
}

/**
 * Evaluate workflow conditions and determine next workflow
 * Returns: workflow ID to trigger next, or null
 */
function evaluate_workflow_conditions($pdo, int $workflowId, array $parentEmail): ?int {
    // Get conditions for this workflow
    $stmt = $pdo->prepare("
        SELECT * FROM email_workflow_conditions 
        WHERE workflow_id = ? AND is_active = 1 
        ORDER BY priority DESC, id ASC
    ");
    $stmt->execute([$workflowId]);
    $conditions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($conditions)) {
        return null; // No conditions, workflow ends here
    }
    
    // Evaluate each condition
    foreach ($conditions as $condition) {
        $result = evaluate_single_condition($pdo, $condition, $parentEmail);
        
        if ($result === true && $condition['next_workflow_id_true']) {
            return intval($condition['next_workflow_id_true']);
        } elseif ($result === false && $condition['next_workflow_id_false']) {
            return intval($condition['next_workflow_id_false']);
        }
    }
    
    return null;
}

/**
 * Evaluate a single condition
 */
function evaluate_single_condition($pdo, array $condition, array $parentEmail): bool {
    $type = $condition['condition_type'];
    $operator = $condition['operator'];
    $expectedValue = $condition['condition_value'];
    $emailId = intval($parentEmail['id']);
    
    $actualValue = null;
    
    switch ($type) {
        case 'email_opened':
            $actualValue = intval($parentEmail['open_count']) > 0;
            $expectedValue = filter_var($expectedValue, FILTER_VALIDATE_BOOLEAN);
            break;
            
        case 'email_clicked':
            $actualValue = intval($parentEmail['click_count']) > 0;
            $expectedValue = filter_var($expectedValue, FILTER_VALIDATE_BOOLEAN);
            break;
            
        case 'conversion_type':
            $actualValue = $parentEmail['conversion_type'] ?? '';
            break;
            
        case 'conversion_value':
            // Sum conversion values from email_conversions table
            $stmt = $pdo->prepare('SELECT SUM(conversion_value) FROM email_conversions WHERE agent_email_id = ?');
            $stmt->execute([$emailId]);
            $actualValue = floatval($stmt->fetchColumn());
            $expectedValue = floatval($expectedValue);
            break;
            
        case 'time_elapsed':
            // Hours since email was sent
            $actualValue = (time() - strtotime($parentEmail['sent_at'])) / 3600;
            $expectedValue = floatval($expectedValue);
            break;
            
        case 'day_of_week':
            $actualValue = date('N'); // 1 (Monday) to 7 (Sunday)
            $expectedValue = intval($expectedValue);
            break;
            
        default:
            return false;
    }
    
    // Apply operator
    switch ($operator) {
        case 'equals':
            return $actualValue == $expectedValue;
        case 'not_equals':
            return $actualValue != $expectedValue;
        case 'greater_than':
            return $actualValue > $expectedValue;
        case 'less_than':
            return $actualValue < $expectedValue;
        case 'contains':
            return stripos((string)$actualValue, (string)$expectedValue) !== false;
        default:
            return false;
    }
}

