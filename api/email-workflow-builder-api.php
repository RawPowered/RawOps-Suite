<?php
/**
 * Email Workflow Builder API
 * Advanced workflow features: AI preview, conditional logic, decision trees
 */

require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

start_session();

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
        die(json_encode(['ok' => false, 'error' => 'Access denied']));
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'preview_ai_template':
            // Generate AI preview for workflow template
            $input = json_decode(file_get_contents('php://input'), true);
            
            $prompt = trim($input['prompt'] ?? '');
            $triggerType = $input['trigger_type'] ?? 'unopened';
            $workflowId = intval($input['workflow_id'] ?? 0);
            
            if (!$prompt) {
                throw new Exception('Prompt is required');
            }
            
            // Build enhanced prompt with workflow context
            $contextPrompt = build_workflow_template_prompt($triggerType, $prompt);
            
            // Generate with AI
            global $gemini_api_key;
            $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=" . $gemini_api_key;
            
            $startTime = microtime(true);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $geminiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $contextPrompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topP' => 0.9,
                    'maxOutputTokens' => 1024
                ]
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception('AI API request failed: ' . curl_error($ch));
            }
            curl_close($ch);
            
            $generationTime = round((microtime(true) - $startTime) * 1000);
            
            $data = json_decode($response, true);
            if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('AI generation failed: ' . ($data['error']['message'] ?? 'Unknown error'));
            }
            
            $generatedText = $data['candidates'][0]['content']['parts'][0]['text'];
            
            // Parse subject and body
            $subject = '';
            $body = $generatedText;
            
            if (preg_match('/^SUBJECT:\s*(.+?)[\r\n]+/i', $generatedText, $matches)) {
                $subject = trim($matches[1]);
                $body = trim(preg_replace('/^SUBJECT:\s*.+?[\r\n]+/i', '', $generatedText));
            }
            
            if (preg_match('/BODY:\s*(.+)/is', $body, $bodyMatch)) {
                $body = trim($bodyMatch[1]);
            }
            
            // Save generation history
            $stmt = $pdo->prepare('INSERT INTO workflow_ai_generations (workflow_id, agent_id, prompt_text, generated_subject, generated_body, model_used, generation_time_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$workflowId, $uid, $prompt, $subject, $body, 'gemini-2.0-flash-exp', $generationTime]);
            $generationId = $pdo->lastInsertId();
            
            echo json_encode([
                'ok' => true,
                'generation_id' => $generationId,
                'subject' => $subject,
                'body' => $body,
                'generation_time_ms' => $generationTime,
                'trigger_type' => $triggerType
            ]);
            break;
            
        case 'save_workflow_template':
            // Save AI-generated content as workflow template
            $input = json_decode(file_get_contents('php://input'), true);
            
            $workflowId = intval($input['workflow_id'] ?? 0);
            $subject = trim($input['subject'] ?? '');
            $body = $input['body'] ?? '';
            $generationId = intval($input['generation_id'] ?? 0) ?: null;
            
            if ($workflowId <= 0 || !$subject || !$body) {
                throw new Exception('Workflow ID, subject, and body required');
            }
            
            // Check workflow access
            $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ?');
            $stmt->execute([$workflowId]);
            $workflow = $stmt->fetch();
            
            if (!$workflow) {
                throw new Exception('Workflow not found');
            }
            
            if (!$isAdmin && $workflow['scope'] === 'global') {
                throw new Exception('Cannot edit global workflow templates');
            }
            
            if (!$isAdmin && $workflow['scope'] === 'agent' && intval($workflow['owner_agent_id']) !== $uid) {
                throw new Exception('Access denied');
            }
            
            // Check if template exists
            $stmt = $pdo->prepare('SELECT id FROM email_workflow_templates WHERE workflow_id = ?');
            $stmt->execute([$workflowId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update
                $stmt = $pdo->prepare('UPDATE email_workflow_templates SET subject_template = ?, body_template = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$subject, $body, $existing['id']]);
                $templateId = $existing['id'];
            } else {
                // Insert
                $stmt = $pdo->prepare('INSERT INTO email_workflow_templates (workflow_id, subject_template, body_template, is_ai_generated, created_at) VALUES (?, ?, ?, 1, NOW())');
                $stmt->execute([$workflowId, $subject, $body]);
                $templateId = $pdo->lastInsertId();
            }
            
            // Mark generation as approved if provided
            if ($generationId) {
                $pdo->prepare('UPDATE workflow_ai_generations SET was_approved = 1, approved_at = NOW() WHERE id = ?')->execute([$generationId]);
            }
            
            echo json_encode(['ok' => true, 'template_id' => $templateId]);
            break;
            
        case 'get_workflow_template':
            // Get saved template content for workflow
            $workflowId = intval($_GET['workflow_id'] ?? 0);
            
            if ($workflowId <= 0) {
                throw new Exception('Workflow ID required');
            }
            
            $stmt = $pdo->prepare('SELECT * FROM email_workflow_templates WHERE workflow_id = ? ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$workflowId]);
            $template = $stmt->fetch();
            
            if (!$template) {
                echo json_encode(['ok' => true, 'template' => null]);
            } else {
                echo json_encode(['ok' => true, 'template' => $template]);
            }
            break;
            
        case 'add_condition':
            // Add conditional branching to workflow
            $input = json_decode(file_get_contents('php://input'), true);
            
            $workflowId = intval($input['workflow_id'] ?? 0);
            $conditionType = $input['condition_type'] ?? '';
            $operator = $input['operator'] ?? 'equals';
            $value = $input['condition_value'] ?? '';
            $nextWorkflowTrue = intval($input['next_workflow_id_true'] ?? 0) ?: null;
            $nextWorkflowFalse = intval($input['next_workflow_id_false'] ?? 0) ?: null;
            $metadata = $input['metadata'] ?? null;
            
            if ($workflowId <= 0 || !$conditionType) {
                throw new Exception('Workflow ID and condition type required');
            }
            
            // Verify workflow access
            $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ?');
            $stmt->execute([$workflowId]);
            $workflow = $stmt->fetch();
            
            if (!$workflow) {
                throw new Exception('Workflow not found');
            }
            
            if (!$isAdmin && $workflow['scope'] === 'agent' && intval($workflow['owner_agent_id']) !== $uid) {
                throw new Exception('Access denied');
            }
            
            // Insert condition
            $stmt = $pdo->prepare("
                INSERT INTO email_workflow_conditions 
                (workflow_id, condition_type, operator, condition_value, condition_metadata, next_workflow_id_true, next_workflow_id_false, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $workflowId,
                $conditionType,
                $operator,
                $value,
                $metadata ? json_encode($metadata) : null,
                $nextWorkflowTrue,
                $nextWorkflowFalse
            ]);
            
            $conditionId = $pdo->lastInsertId();
            
            // Mark workflow as having conditions
            $pdo->prepare('UPDATE email_followup_workflows SET has_conditions = 1 WHERE id = ?')->execute([$workflowId]);
            
            echo json_encode(['ok' => true, 'condition_id' => $conditionId]);
            break;
            
        case 'get_conditions':
            // Get all conditions for a workflow
            $workflowId = intval($_GET['workflow_id'] ?? 0);
            
            if ($workflowId <= 0) {
                throw new Exception('Workflow ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       wt.name as next_true_name,
                       wf.name as next_false_name
                FROM email_workflow_conditions c
                LEFT JOIN email_followup_workflows wt ON wt.id = c.next_workflow_id_true
                LEFT JOIN email_followup_workflows wf ON wf.id = c.next_workflow_id_false
                WHERE c.workflow_id = ?
                ORDER BY c.priority DESC, c.id ASC
            ");
            $stmt->execute([$workflowId]);
            $conditions = $stmt->fetchAll();
            
            echo json_encode(['ok' => true, 'conditions' => $conditions]);
            break;
            
        case 'delete_condition':
            // Delete a condition
            $input = json_decode(file_get_contents('php://input'), true);
            $conditionId = intval($input['id'] ?? 0);
            
            if ($conditionId <= 0) {
                throw new Exception('Condition ID required');
            }
            
            $pdo->prepare('DELETE FROM email_workflow_conditions WHERE id = ?')->execute([$conditionId]);
            
            echo json_encode(['ok' => true]);
            break;
            
        case 'create_chain':
            // Create a workflow chain (multi-step sequence)
            $input = json_decode(file_get_contents('php://input'), true);
            
            $chainName = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $rootWorkflowId = intval($input['root_workflow_id'] ?? 0);
            
            if (!$chainName || $rootWorkflowId <= 0) {
                throw new Exception('Chain name and root workflow required');
            }
            
            $ownerId = $isAdmin ? (intval($input['owner_agent_id'] ?? 0) ?: null) : $uid;
            
            $stmt = $pdo->prepare("
                INSERT INTO email_workflow_chains 
                (chain_name, description, owner_agent_id, root_workflow_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$chainName, $description, $ownerId, $rootWorkflowId]);
            
            $chainId = $pdo->lastInsertId();
            
            // Update root workflow
            $pdo->prepare('UPDATE email_followup_workflows SET chain_id = ?, chain_step = 0 WHERE id = ?')->execute([$chainId, $rootWorkflowId]);
            
            echo json_encode(['ok' => true, 'chain_id' => $chainId]);
            break;
            
        case 'get_chains':
            // Get all workflow chains
            $where = $isAdmin ? '1=1' : 'owner_agent_id = ?';
            $sql = "SELECT c.*, w.name as root_workflow_name FROM email_workflow_chains c JOIN email_followup_workflows w ON w.id = c.root_workflow_id WHERE {$where} ORDER BY c.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            if (!$isAdmin) {
                $stmt->execute([$uid]);
            } else {
                $stmt->execute();
            }
            
            $chains = $stmt->fetchAll();
            
            echo json_encode(['ok' => true, 'chains' => $chains]);
            break;
            
        case 'get_chain_details':
            // Get full chain with all workflows and conditions
            $chainId = intval($_GET['chain_id'] ?? 0);
            
            if ($chainId <= 0) {
                throw new Exception('Chain ID required');
            }
            
            // Get chain info
            $stmt = $pdo->prepare('SELECT * FROM email_workflow_chains WHERE id = ?');
            $stmt->execute([$chainId]);
            $chain = $stmt->fetch();
            
            if (!$chain) {
                throw new Exception('Chain not found');
            }
            
            // Check access
            if (!$isAdmin && $chain['owner_agent_id'] && intval($chain['owner_agent_id']) !== $uid) {
                throw new Exception('Access denied');
            }
            
            // Get all workflows in chain
            $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE chain_id = ? ORDER BY chain_step ASC');
            $stmt->execute([$chainId]);
            $workflows = $stmt->fetchAll();
            
            // Get conditions for each workflow
            foreach ($workflows as &$workflow) {
                $stmt = $pdo->prepare('SELECT * FROM email_workflow_conditions WHERE workflow_id = ? ORDER BY priority DESC');
                $stmt->execute([$workflow['id']]);
                $workflow['conditions'] = $stmt->fetchAll();
            }
            
            echo json_encode([
                'ok' => true,
                'chain' => $chain,
                'workflows' => $workflows
            ]);
            break;
            
        case 'visualize_chain':
            // Generate visual representation of workflow chain (decision tree)
            $chainId = intval($_GET['chain_id'] ?? 0);
            
            if ($chainId <= 0) {
                throw new Exception('Chain ID required');
            }
            
            // Get chain data
            $stmt = $pdo->prepare('SELECT * FROM email_workflow_chains WHERE id = ?');
            $stmt->execute([$chainId]);
            $chain = $stmt->fetch();
            
            if (!$chain) {
                throw new Exception('Chain not found');
            }
            
            // Build tree structure
            $stmt = $pdo->prepare("
                SELECT w.*, 
                       GROUP_CONCAT(c.id) as condition_ids
                FROM email_followup_workflows w
                LEFT JOIN email_workflow_conditions c ON c.workflow_id = w.id
                WHERE w.chain_id = ?
                GROUP BY w.id
                ORDER BY w.chain_step ASC
            ");
            $stmt->execute([$chainId]);
            $workflows = $stmt->fetchAll();
            
            // Build decision tree structure
            $tree = build_decision_tree($pdo, $workflows);
            
            echo json_encode([
                'ok' => true,
                'chain_name' => $chain['chain_name'],
                'tree' => $tree
            ]);
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

/**
 * Build workflow template AI prompt with context
 */
function build_workflow_template_prompt(string $triggerType, string $userPrompt): string {
    $triggerContext = [
        'unopened' => 'The recipient has not opened the original email. This is a gentle reminder.',
        'opened_not_clicked' => 'The recipient opened the email but did not click any links. They showed interest but did not engage further.',
        'clicked_no_action' => 'The recipient clicked through but took no action on the website. They are interested but may need more information.',
        'clicked_no_sale' => 'The recipient browsed the website but did not make a purchase. They are evaluating options.',
        'clicked_sale_with_agent' => 'The recipient made a purchase. This is a thank you and relationship-building email.',
        'clicked_ai_interaction' => 'The recipient engaged with the AI chat assistant. They are actively seeking information.',
    ];
    
    $context = $triggerContext[$triggerType] ?? 'Follow-up email based on recipient behavior.';
    
    return <<<PROMPT
You are creating an email template for an automated follow-up workflow.

**Workflow Context:**
Trigger Condition: {$triggerType}
Scenario: {$context}

**User's Instructions:**
{$userPrompt}

**Template Requirements:**
- This will be used as a template with variables like {{recipient_name}}, {{company_name}}, {{agent_name}}
- Write generic content that can be personalized automatically
- Include appropriate tone for the trigger condition
- Keep it professional but warm
- Clear call-to-action
- 2-3 paragraphs maximum

**IMPORTANT OUTPUT FORMAT:**
SUBJECT: [subject line with optional {{variables}}]

BODY:
<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
Hi <strong>{{recipient_name}}</strong>,
</p>

<!-- YOUR TEMPLATE CONTENT HERE -->

<p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">
Best regards,<br>
<strong>{{agent_name}}</strong><br>
<span style="color:#64748b;font-size:14px;">Sales Representative, Product</span><br>
<span style="color:#64748b;font-size:13px;">📧 {{agent_email}}</span>
</p>

Use inline styles for all formatting. Variables available:
- {{recipient_name}} - Recipient full name
- {{company_name}} - Company/business name
- {{agent_name}} - Sales agent name
- {{agent_email}} - Sales agent email
- {{login_url}} - Login URL
PROMPT;
}

/**
 * Build decision tree structure for visualization
 */
function build_decision_tree($pdo, array $workflows): array {
    $tree = [];
    
    foreach ($workflows as $workflow) {
        $node = [
            'id' => $workflow['id'],
            'name' => $workflow['name'],
            'step' => intval($workflow['chain_step']),
            'trigger' => $workflow['trigger_type'],
            'delay' => intval($workflow['trigger_delay_hours']),
            'branches' => []
        ];
        
        // Get conditions
        if ($workflow['condition_ids']) {
            $stmt = $pdo->prepare('SELECT * FROM email_workflow_conditions WHERE workflow_id = ? ORDER BY priority DESC');
            $stmt->execute([$workflow['id']]);
            $conditions = $stmt->fetchAll();
            
            foreach ($conditions as $condition) {
                $node['branches'][] = [
                    'condition_type' => $condition['condition_type'],
                    'operator' => $condition['operator'],
                    'value' => $condition['condition_value'],
                    'if_true' => intval($condition['next_workflow_id_true']),
                    'if_false' => intval($condition['next_workflow_id_false'])
                ];
            }
        }
        
        $tree[] = $node;
    }
    
    return $tree;
}





