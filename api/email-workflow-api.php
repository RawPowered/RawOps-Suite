<?php
/**
 * Email Workflow Management API
 * CRUD operations for follow-up workflows and entity settings
 */

require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

start_session();

// Require admin or sales agent authentication
$uid = current_user_id();
$isAdmin = current_admin_id() !== null;
$isSalesAgent = false;

if (!$uid) {
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
}

$pdo = db();

// Check if user is sales agent
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    $stmt->execute([$uid]);
    $isSalesAgent = (bool)$stmt->fetch();
    
    if (!$isSalesAgent) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Access denied. Admin or sales agent role required.']));
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_workflows':
            // Get all workflows (admins see all, agents see global + their own)
            $where = $isAdmin ? '1=1' : "(scope = 'global' OR (scope = 'agent' AND owner_agent_id = ?))";
            $sql = "SELECT * FROM email_followup_workflows WHERE {$where} ORDER BY trigger_type, priority DESC, name";
            
            $stmt = $pdo->prepare($sql);
            if (!$isAdmin) {
                $stmt->execute([$uid]);
            } else {
                $stmt->execute();
            }
            
            $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'workflows' => $workflows]);
            break;
            
        case 'get_workflow':
            // Get single workflow
            $workflowId = intval($_GET['id'] ?? 0);
            
            if ($workflowId <= 0) {
                throw new Exception('Workflow ID required');
            }
            
            $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ?');
            $stmt->execute([$workflowId]);
            $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$workflow) {
                throw new Exception('Workflow not found');
            }
            
            // Check access
            if (!$isAdmin && $workflow['scope'] === 'agent' && intval($workflow['owner_agent_id']) !== $uid) {
                throw new Exception('Access denied');
            }
            
            echo json_encode(['ok' => true, 'workflow' => $workflow]);
            break;
            
        case 'create_workflow':
            // Create new workflow (admin or agent for scope=agent)
            $input = json_decode(file_get_contents('php://input'), true);
            
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $triggerType = $input['trigger_type'] ?? '';
            $triggerDelayHours = intval($input['trigger_delay_hours'] ?? 72);
            $scope = $input['scope'] ?? 'global';
            $templateId = intval($input['template_id'] ?? 0) ?: null;
            $allowAi = !empty($input['allow_ai_generation']);
            $priority = intval($input['priority'] ?? 50);
            
            // Validate
            if (!$name || !$triggerType) {
                throw new Exception('Name and trigger type required');
            }
            
            $validTriggers = ['unopened', 'opened_not_clicked', 'clicked_no_action', 'clicked_no_sale', 
                              'clicked_sale_with_agent', 'clicked_sale_no_agent', 'clicked_ai_interaction', 'custom'];
            if (!in_array($triggerType, $validTriggers)) {
                throw new Exception('Invalid trigger type');
            }
            
            $validScopes = ['global', 'agent', 'lead', 'account'];
            if (!in_array($scope, $validScopes)) {
                throw new Exception('Invalid scope');
            }
            
            // Only admins can create global workflows
            if ($scope === 'global' && !$isAdmin) {
                throw new Exception('Only admins can create global workflows');
            }
            
            // Set owner for agent-scoped workflows
            $ownerId = null;
            if ($scope === 'agent') {
                $ownerId = $isAdmin ? (intval($input['owner_agent_id'] ?? 0) ?: null) : $uid;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO email_followup_workflows 
                (name, description, trigger_type, trigger_delay_hours, scope, owner_agent_id, 
                 template_id, allow_ai_generation, priority, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $stmt->execute([
                $name, $description, $triggerType, $triggerDelayHours, $scope,
                $ownerId, $templateId, $allowAi ? 1 : 0, $priority
            ]);
            
            $workflowId = intval($pdo->lastInsertId());
            
            echo json_encode(['ok' => true, 'workflow_id' => $workflowId]);
            break;
            
        case 'update_workflow':
            // Update existing workflow
            $input = json_decode(file_get_contents('php://input'), true);
            $workflowId = intval($input['id'] ?? 0);
            
            if ($workflowId <= 0) {
                throw new Exception('Workflow ID required');
            }
            
            // Check access
            $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ?');
            $stmt->execute([$workflowId]);
            $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$workflow) {
                throw new Exception('Workflow not found');
            }
            
            if (!$isAdmin) {
                if ($workflow['scope'] === 'global') {
                    throw new Exception('Only admins can edit global workflows');
                }
                if ($workflow['scope'] === 'agent' && intval($workflow['owner_agent_id']) !== $uid) {
                    throw new Exception('Access denied');
                }
            }
            
            // Update fields
            $name = trim($input['name'] ?? $workflow['name']);
            $description = trim($input['description'] ?? $workflow['description']);
            $triggerDelayHours = intval($input['trigger_delay_hours'] ?? $workflow['trigger_delay_hours']);
            $templateId = isset($input['template_id']) ? (intval($input['template_id']) ?: null) : $workflow['template_id'];
            $allowAi = isset($input['allow_ai_generation']) ? !empty($input['allow_ai_generation']) : $workflow['allow_ai_generation'];
            $priority = intval($input['priority'] ?? $workflow['priority']);
            $isActive = isset($input['is_active']) ? !empty($input['is_active']) : $workflow['is_active'];
            
            $stmt = $pdo->prepare("
                UPDATE email_followup_workflows 
                SET name = ?, description = ?, trigger_delay_hours = ?, template_id = ?, 
                    allow_ai_generation = ?, priority = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $name, $description, $triggerDelayHours, $templateId,
                $allowAi ? 1 : 0, $priority, $isActive ? 1 : 0, $workflowId
            ]);
            
            echo json_encode(['ok' => true]);
            break;
            
        case 'delete_workflow':
            // Delete workflow (admin only for global, owner for agent-scoped)
            $input = json_decode(file_get_contents('php://input'), true);
            $workflowId = intval($input['id'] ?? 0);
            
            if ($workflowId <= 0) {
                throw new Exception('Workflow ID required');
            }
            
            // Check access
            $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ?');
            $stmt->execute([$workflowId]);
            $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$workflow) {
                throw new Exception('Workflow not found');
            }
            
            if (!$isAdmin) {
                if ($workflow['scope'] === 'global') {
                    throw new Exception('Only admins can delete global workflows');
                }
                if ($workflow['scope'] === 'agent' && intval($workflow['owner_agent_id']) !== $uid) {
                    throw new Exception('Access denied');
                }
            }
            
            $pdo->prepare('DELETE FROM email_followup_workflows WHERE id = ?')->execute([$workflowId]);
            
            echo json_encode(['ok' => true]);
            break;
            
        case 'get_entity_settings':
            // Get workflow settings for specific lead/account
            $entityType = $_GET['entity_type'] ?? '';
            $entityId = intval($_GET['entity_id'] ?? 0);
            
            if (!in_array($entityType, ['lead', 'account']) || $entityId <= 0) {
                throw new Exception('Valid entity_type and entity_id required');
            }
            
            // Check if agent has access to this entity
            if (!$isAdmin) {
                if ($entityType === 'lead') {
                    $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND sales_agent_id = ?');
                    $stmt->execute([$entityId, $uid]);
                } else {
                    $stmt = $pdo->prepare('SELECT 1 FROM wholesale_accounts WHERE id = ? AND sales_agent_id = ?');
                    $stmt->execute([$entityId, $uid]);
                }
                
                if (!$stmt->fetch()) {
                    throw new Exception('Access denied to this entity');
                }
            }
            
            $stmt = $pdo->prepare("
                SELECT es.*, w.name as workflow_name, w.trigger_type
                FROM email_followup_entity_settings es
                LEFT JOIN email_followup_workflows w ON w.id = es.workflow_id
                WHERE es.entity_type = ? AND es.entity_id = ?
                ORDER BY w.trigger_type, w.name
            ");
            $stmt->execute([$entityType, $entityId]);
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'settings' => $settings]);
            break;
            
        case 'update_entity_settings':
            // Update workflow settings for specific entity
            $input = json_decode(file_get_contents('php://input'), true);
            
            $entityType = $input['entity_type'] ?? '';
            $entityId = intval($input['entity_id'] ?? 0);
            $workflowId = intval($input['workflow_id'] ?? 0) ?: null;
            
            if (!in_array($entityType, ['lead', 'account']) || $entityId <= 0) {
                throw new Exception('Valid entity_type and entity_id required');
            }
            
            // Check access
            if (!$isAdmin) {
                if ($entityType === 'lead') {
                    $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND sales_agent_id = ?');
                    $stmt->execute([$entityId, $uid]);
                } else {
                    $stmt = $pdo->prepare('SELECT 1 FROM wholesale_accounts WHERE id = ? AND sales_agent_id = ?');
                    $stmt->execute([$entityId, $uid]);
                }
                
                if (!$stmt->fetch()) {
                    throw new Exception('Access denied to this entity');
                }
            }
            
            $isEnabled = isset($input['is_enabled']) ? (!empty($input['is_enabled']) ? 1 : 0) : 1;
            $customDelayHours = isset($input['custom_delay_hours']) ? (intval($input['custom_delay_hours']) ?: null) : null;
            $customTemplateId = isset($input['custom_template_id']) ? (intval($input['custom_template_id']) ?: null) : null;
            $allowTimeOptimization = !empty($input['allow_time_of_day_optimization']) ? 1 : 0;
            $preferredSendHour = isset($input['preferred_send_hour']) ? intval($input['preferred_send_hour']) : null;
            $notes = trim($input['notes'] ?? '');
            
            $stmt = $pdo->prepare("
                INSERT INTO email_followup_entity_settings 
                (entity_type, entity_id, workflow_id, is_enabled, custom_delay_hours, custom_template_id, 
                 allow_time_of_day_optimization, preferred_send_hour, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    custom_delay_hours = VALUES(custom_delay_hours),
                    custom_template_id = VALUES(custom_template_id),
                    allow_time_of_day_optimization = VALUES(allow_time_of_day_optimization),
                    preferred_send_hour = VALUES(preferred_send_hour),
                    notes = VALUES(notes),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                $entityType, $entityId, $workflowId, $isEnabled, $customDelayHours, $customTemplateId,
                $allowTimeOptimization, $preferredSendHour, $notes
            ]);
            
            echo json_encode(['ok' => true]);
            break;
            
        case 'get_scheduled_followups':
            // Get scheduled follow-ups (admin sees all, agent sees their own)
            $entityType = $_GET['entity_type'] ?? '';
            $entityId = intval($_GET['entity_id'] ?? 0);
            $status = $_GET['status'] ?? '';
            
            $where = [];
            $params = [];
            
            if ($entityType && in_array($entityType, ['lead', 'account'])) {
                $where[] = 'fs.entity_type = ?';
                $params[] = $entityType;
            }
            
            if ($entityId > 0) {
                $where[] = 'fs.entity_id = ?';
                $params[] = $entityId;
            }
            
            if ($status && in_array($status, ['pending', 'cancelled', 'sent', 'failed'])) {
                $where[] = 'fs.status = ?';
                $params[] = $status;
            }
            
            // Agents only see their own
            if (!$isAdmin) {
                $where[] = 'ae.agent_id = ?';
                $params[] = $uid;
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "
                SELECT fs.*, w.name as workflow_name, w.trigger_type,
                       ae.subject as parent_subject, ae.sent_at as parent_sent_at
                FROM email_followup_schedule fs
                JOIN email_followup_workflows w ON w.id = fs.workflow_id
                JOIN agent_emails ae ON ae.id = fs.parent_email_id
                {$whereClause}
                ORDER BY fs.scheduled_for DESC
                LIMIT 100
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'followups' => $followups]);
            break;
            
        case 'cancel_followup':
            // Cancel a scheduled follow-up
            $input = json_decode(file_get_contents('php://input'), true);
            $followupId = intval($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? 'Manually cancelled');
            
            if ($followupId <= 0) {
                throw new Exception('Follow-up ID required');
            }
            
            // Check access
            $stmt = $pdo->prepare("
                SELECT fs.*, ae.agent_id
                FROM email_followup_schedule fs
                JOIN agent_emails ae ON ae.id = fs.parent_email_id
                WHERE fs.id = ?
            ");
            $stmt->execute([$followupId]);
            $followup = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$followup) {
                throw new Exception('Follow-up not found');
            }
            
            if (!$isAdmin && intval($followup['agent_id']) !== $uid) {
                throw new Exception('Access denied');
            }
            
            if ($followup['status'] !== 'pending') {
                throw new Exception('Can only cancel pending follow-ups');
            }
            
            $pdo->prepare("
                UPDATE email_followup_schedule 
                SET status = 'cancelled', cancel_reason = ?, processed_at = NOW()
                WHERE id = ?
            ")->execute([$reason, $followupId]);
            
            echo json_encode(['ok' => true]);
            break;
            
        case 'get_workflow_analytics':
            // Get performance analytics for workflows
            $workflowId = intval($_GET['workflow_id'] ?? 0);
            
            $where = $workflowId > 0 ? 'WHERE fs.workflow_id = ?' : '';
            $params = $workflowId > 0 ? [$workflowId] : [];
            
            $sql = "
                SELECT 
                    w.id as workflow_id,
                    w.name as workflow_name,
                    w.trigger_type,
                    COUNT(fs.id) as total_scheduled,
                    SUM(CASE WHEN fs.status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN fs.status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN fs.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN sent_ae.open_count > 0 THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN sent_ae.click_count > 0 THEN 1 ELSE 0 END) as clicked,
                    SUM(CASE WHEN sent_ae.contributed_to_sale = 1 THEN 1 ELSE 0 END) as converted
                FROM email_followup_workflows w
                LEFT JOIN email_followup_schedule fs ON fs.workflow_id = w.id
                LEFT JOIN agent_emails sent_ae ON sent_ae.id = fs.sent_email_id
                {$where}
                GROUP BY w.id
                ORDER BY total_scheduled DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate rates
            foreach ($analytics as &$row) {
                $sent = intval($row['sent']);
                $row['open_rate'] = $sent > 0 ? round((intval($row['opened']) / $sent) * 100, 1) : 0;
                $row['click_rate'] = $sent > 0 ? round((intval($row['clicked']) / $sent) * 100, 1) : 0;
                $row['conversion_rate'] = $sent > 0 ? round((intval($row['converted']) / $sent) * 100, 1) : 0;
            }
            
            echo json_encode(['ok' => true, 'analytics' => $analytics]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}





