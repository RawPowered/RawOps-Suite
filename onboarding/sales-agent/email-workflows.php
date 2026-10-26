<?php
/**
 * Sales Agent Email Workflow Manager
 * Allows agents to create, manage, and apply custom workflows to individual emails
 */

$SEO = ['title' => 'Email Workflows', 'description' => 'Manage follow-up workflows', 'type' => 'website'];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/config.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

$pdo = db();

// Check sales_agent role
$isAdminSimulation = isset($_SESSION['admin_simulation']);
if (!$isAdminSimulation) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { $stmt->execute([$uid]); $hasRole = (bool)$stmt->fetch(); } catch (Throwable $e) { $hasRole = false; }
    if (!$hasRole) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Get agent info
$stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
$stmt->execute([$uid]);
$agent = $stmt->fetch();
$user = $agent; // For navigation
$agentName = trim($agent['first_name'] . ' ' . $agent['last_name']);
$userInitials = strtoupper(substr($agent['first_name'] ?? '', 0, 1) . substr($agent['last_name'] ?? '', 0, 1));
?>

<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; }
.page { max-width: 1400px; margin: 0 auto; padding: 24px; }
.card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(15,23,42,0.08); margin-bottom: 20px; overflow: hidden; }
.card-header { background: linear-gradient(135deg, #14b8a6, #0ea5e9); color: #fff; padding: 20px 24px; }
.card-header h1 { margin: 0; font-size: 24px; }
.card-header p { margin: 8px 0 0; opacity: .9; font-size: 14px; }
.card-body { padding: 24px; }
.tabs { display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; }
.tab { padding: 12px 20px; cursor: pointer; border-bottom: 3px solid transparent; color: #64748b; font-weight: 500; }
.tab.active { border-bottom-color: #0ea5e9; color: #0ea5e9; }
.tab-content { display: none; }
.tab-content.active { display: block; }
.grid { display: grid; gap: 16px; }
.grid-2 { grid-template-columns: 1fr 1fr; }
.grid-3 { grid-template-columns: 1fr 1fr 1fr; }
label { display: block; margin-bottom: 12px; font-size: 14px; font-weight: 500; color: #475569; }
input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; margin-top: 4px; }
textarea { font-family: inherit; resize: vertical; }
.btn { padding: 10px 18px; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; }
.btn:hover { background: #1d4ed8; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn-secondary { background: #64748b; }
.btn-secondary:hover { background: #475569; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.btn-success { background: #16a34a; }
.btn-success:hover { background: #15803d; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #f1f5f9; }
th { background: #f8fafc; font-weight: 600; color: #475569; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #f1f5f9; color: #475569; }
.badge-active { background: #dcfce7; color: #166534; }
.badge-inactive { background: #f1f5f9; color: #64748b; }
.notice { padding: 12px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 6px; margin-bottom: 16px; }
.info-box { background: #dbeafe; border-left: 4px solid #2563eb; padding: 16px; border-radius: 6px; margin-bottom: 20px; }
.info-box strong { color: #1e40af; }
</style>

<div class="container">
    <?php 
    // Include the sales ACP navigation
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    ?>

<div class="page">
    <div class="card">
        <div class="card-header">
            <h1>🔄 My Email Workflows</h1>
            <p>Create custom follow-up workflows and apply them to individual emails or campaigns</p>
        </div>
    </div>
    
    <div class="info-box">
        <strong>💡 What are workflows?</strong><br>
        Workflows automatically send follow-up emails based on recipient behavior (unopened, clicked, converted, etc.). 
        You can create reusable workflow templates or apply custom workflows to specific emails as one-off campaigns.
    </div>
    
    <div class="tabs">
        <div class="tab active" onclick="switchTab('my-workflows')">My Workflows</div>
        <div class="tab" onclick="switchTab('create')">Create Workflow</div>
        <div class="tab" onclick="switchTab('ai-preview')">✨ AI Preview</div>
        <div class="tab" onclick="switchTab('decision-trees')">🌳 Decision Trees</div>
        <div class="tab" onclick="switchTab('campaigns')">Active Campaigns</div>
        <div class="tab" onclick="switchTab('performance')">Performance</div>
    </div>
    
    <!-- TAB: My Workflows -->
    <div id="tab-my-workflows" class="tab-content active">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">My Workflow Templates</h3>
                <p style="color:#64748b;margin-bottom:20px;">Reusable workflow templates you can apply to any lead or email.</p>
                
                <div id="myWorkflowsLoading" style="text-align:center;padding:40px;color:#64748b;">Loading your workflows...</div>
                <div id="myWorkflowsContent" style="display:none;">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Trigger</th>
                                <th>Delay</th>
                                <th>AI</th>
                                <th>Status</th>
                                <th>Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="myWorkflowsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Global Workflows</h3>
                <p style="color:#64748b;margin-bottom:20px;">System-wide workflows available to all agents. You can customize these per lead/account.</p>
                
                <div id="globalWorkflowsLoading" style="text-align:center;padding:40px;color:#64748b;">Loading global workflows...</div>
                <div id="globalWorkflowsContent" style="display:none;">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Trigger</th>
                                <th>Delay</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody id="globalWorkflowsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Create Workflow -->
    <div id="tab-create" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Create Custom Workflow</h3>
                <p style="color:#64748b;margin-bottom:20px;">Build a reusable workflow template or a one-time campaign workflow.</p>
                
                <form id="workflowForm" onsubmit="return saveMyWorkflow(event)">
                    <input type="hidden" id="workflow_id" value="">
                    
                    <div class="grid grid-2">
                        <label>
                            Workflow Name *
                            <input type="text" id="workflow_name" required placeholder="e.g., High-Value Lead - Fast Follow-up">
                        </label>
                        
                        <label>
                            Workflow Type *
                            <select id="workflow_scope" required onchange="toggleWorkflowTypeHelp()">
                                <option value="agent">Reusable Template</option>
                                <option value="lead">One-Time (Specific Lead)</option>
                                <option value="account">One-Time (Specific Account)</option>
                            </select>
                        </label>
                    </div>
                    
                    <div id="entitySelector" style="display:none;margin-bottom:16px;">
                        <label>
                            Select Lead/Account *
                            <input type="text" id="entity_search" placeholder="Search by name or email..." onkeyup="searchEntities()">
                            <select id="entity_id" size="5" style="margin-top:8px;"></select>
                        </label>
                    </div>
                    
                    <label>
                        Description
                        <textarea id="workflow_description" rows="2" placeholder="Brief description of when to use this workflow..."></textarea>
                    </label>
                    
                    <div class="grid grid-3">
                        <label>
                            Trigger Condition *
                            <select id="workflow_trigger" required>
                                <option value="">Select trigger...</option>
                                <option value="unopened">Email Unopened</option>
                                <option value="opened_not_clicked">Opened but Not Clicked</option>
                                <option value="clicked_no_action">Clicked but No Website Action</option>
                                <option value="clicked_no_sale">Clicked but No Sale</option>
                                <option value="clicked_sale_with_agent">Sale Made (Thank You)</option>
                                <option value="clicked_ai_interaction">Engaged with AI Chat</option>
                            </select>
                        </label>
                        
                        <label>
                            Delay (hours) *
                            <input type="number" id="workflow_delay" required value="72" min="1" max="720">
                            <small style="color:#64748b;font-size:11px;">Wait time before sending follow-up</small>
                        </label>
                        
                        <label>
                            Priority
                            <input type="number" id="workflow_priority" value="50" min="0" max="100">
                            <small style="color:#64748b;font-size:11px;">Higher number = higher priority</small>
                        </label>
                    </div>
                    
                    <div class="grid grid-2">
                        <label>
                            Email Template (Optional)
                            <select id="workflow_template">
                                <option value="">Use AI Generation</option>
                                <!-- Templates loaded dynamically -->
                            </select>
                        </label>
                        
                        <label style="display:flex;align-items:center;gap:8px;margin-top:28px;">
                            <input type="checkbox" id="workflow_ai" checked>
                            <span>Allow AI to generate personalized follow-up content</span>
                        </label>
                    </div>
                    
                    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:16px 0;">
                        <h4 style="margin:0 0 12px;font-size:14px;">Advanced Options</h4>
                        
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <input type="checkbox" id="workflow_active" checked>
                            <span>Activate immediately</span>
                        </label>
                        
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" id="workflow_reusable">
                            <span>Make this a reusable template (can apply to multiple leads/accounts)</span>
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <button type="submit" class="btn">💾 Save Workflow</button>
                        <button type="button" class="btn btn-secondary" onclick="clearWorkflowForm()">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- TAB: AI Preview & Template Generator -->
    <div id="tab-ai-preview" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">✨ AI Template Preview & Generator</h3>
                <p style="color:#64748b;margin-bottom:20px;">Generate and preview email content for your workflows before activating them.</p>
                
                <div class="grid grid-2" style="gap:24px;">
                    <!-- Left: Generator -->
                    <div>
                        <h4 style="margin-top:0;">Generate Template</h4>
                        
                        <label>
                            Select Workflow to Preview
                            <select id="previewWorkflowSelect" onchange="loadWorkflowForPreview()" style="margin-bottom:16px;">
                                <option value="">Select a workflow...</option>
                            </select>
                        </label>
                        
                        <label>
                            Trigger Scenario
                            <select id="previewTriggerType" disabled style="background:#f9fafb;">
                                <option value="unopened">Unopened Email</option>
                                <option value="opened_not_clicked">Opened but Not Clicked</option>
                                <option value="clicked_no_action">Clicked but No Action</option>
                                <option value="clicked_no_sale">Clicked but No Sale</option>
                                <option value="clicked_sale_with_agent">Sale Made</option>
                                <option value="clicked_ai_interaction">AI Chat Interaction</option>
                            </select>
                        </label>
                        
                        <label>
                            AI Instructions *
                            <textarea id="previewPrompt" rows="6" placeholder="Example: Write a friendly reminder that we offer volume discounts. Mention our 15% bulk pricing and ask if they have any questions about our products. Include a call-to-action to schedule a quick call." required></textarea>
                        </label>
                        
                        <div style="background:#fef3c7;border:1px solid #fbbf24;padding:12px;border-radius:6px;margin:12px 0;font-size:13px;color:#92400e;">
                            💡 <strong>Tip:</strong> Be specific about tone, key points to mention, and desired call-to-action. The AI will generate a template you can save for this workflow.
                        </div>
                        
                        <button type="button" class="btn" onclick="generatePreview()" style="width:100%;margin-bottom:12px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="margin-right:4px;">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                            </svg>
                            Generate Preview
                        </button>
                        
                        <div id="previewGenerating" style="display:none;text-align:center;padding:20px;">
                            <div style="width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>
                            <p style="margin-top:12px;color:#64748b;">AI is generating your template...</p>
                        </div>
                        
                        <div id="previewActions" style="display:none;">
                            <button type="button" class="btn btn-success" onclick="savePreviewAsTemplate()" style="width:100%;">
                                💾 Save as Workflow Template
                            </button>
                        </div>
                    </div>
                    
                    <!-- Right: Preview -->
                    <div>
                        <h4 style="margin-top:0;">Live Preview</h4>
                        <div id="previewContainer" style="border:2px solid #e5e7eb;border-radius:12px;min-height:500px;background:#fff;">
                            <div style="background:#f8fafc;padding:16px;border-bottom:1px solid #e5e7eb;">
                                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Subject:</div>
                                <div id="previewSubject" style="font-size:16px;font-weight:600;color:#1f2937;">Select a workflow and generate preview</div>
                            </div>
                            <div id="previewBody" style="padding:24px;min-height:400px;color:#64748b;text-align:center;">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:40px auto;display:block;opacity:0.3;">
                                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                    <path d="M2 17l10 5 10-5"/>
                                    <path d="M2 12l10 5 10-5"/>
                                </svg>
                                <p>Preview will appear here</p>
                            </div>
                        </div>
                        
                        <div id="previewMeta" style="margin-top:12px;padding:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:12px;color:#0369a1;display:none;">
                            <strong>Variables available:</strong> {{recipient_name}}, {{company_name}}, {{agent_name}}, {{agent_email}}, {{login_url}}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Decision Trees -->
    <div id="tab-decision-trees" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">🌳 Workflow Decision Trees</h3>
                <p style="color:#64748b;margin-bottom:20px;">Create multi-step workflows with conditional branching based on recipient behavior.</p>
                
                <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:16px;border-radius:6px;margin-bottom:24px;">
                    <strong style="color:#92400e;">🎓 How Decision Trees Work:</strong><br>
                    <div style="margin-top:8px;color:#78350f;font-size:13px;line-height:1.6;">
                        1. Create a root workflow (e.g., "Unopened - 3 Day Reminder")<br>
                        2. Add conditions (e.g., "If opened → Thank You Email", "If not opened → Second Reminder")<br>
                        3. Chain multiple workflows together for complex nurture sequences<br>
                        4. System automatically evaluates conditions and triggers the right next step
                    </div>
                </div>
                
                <!-- Workflow Selector -->
                <div class="grid grid-2" style="margin-bottom:24px;">
                    <label>
                        Select Workflow to Configure
                        <select id="dtWorkflowSelect" onchange="loadWorkflowConditions()">
                            <option value="">Choose workflow...</option>
                        </select>
                    </label>
                    <div style="display:flex;align-items:flex-end;">
                        <button type="button" class="btn" onclick="showAddConditionModal()" id="btnAddCondition" disabled style="width:100%;">
                            + Add Condition Branch
                        </button>
                    </div>
                </div>
                
                <!-- Conditions List -->
                <div id="conditionsLoading" style="display:none;text-align:center;padding:40px;color:#64748b;">Loading conditions...</div>
                <div id="conditionsContent">
                    <div id="conditionsList" style="display:grid;gap:12px;"></div>
                </div>
                
                <!-- Decision Tree Visualization -->
                <div style="margin-top:32px;">
                    <h4>Visual Decision Tree</h4>
                    <div id="decisionTreeViz" style="background:#f8fafc;border:2px dashed #e5e7eb;border-radius:12px;padding:32px;min-height:300px;overflow-x:auto;">
                        <p style="text-align:center;color:#64748b;margin:80px 0;">Select a workflow to view its decision tree</p>
                    </div>
                </div>
                
                <!-- Quick Examples -->
                <div style="margin-top:24px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
                    <h4 style="margin:0 0 12px;color:#1e40af;">Example Decision Trees:</h4>
                    <div style="font-size:13px;color:#0c4a6e;line-height:1.8;">
                        <strong>Simple Follow-up Chain:</strong><br>
                        Step 1: If unopened after 3 days → Send reminder<br>
                        Step 2: If still unopened after 6 days → Send final follow-up<br>
                        Step 2b: If opened → Send thank you + offer<br>
                        <hr style="margin:12px 0;border:none;border-top:1px solid #bae6fd;">
                        <strong>Complex Nurture Sequence:</strong><br>
                        Step 1: If clicked but no action → Send product info<br>
                        Step 2a: If conversion value > $500 → VIP follow-up<br>
                        Step 2b: If conversion value < $500 → Standard follow-up<br>
                        Step 3: If purchased → Thank you + upsell<br>
                        Step 3b: If not purchased after 7 days → Discount offer
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Active Campaigns -->
    <div id="tab-campaigns" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">📧 Active Email Campaigns</h3>
                <p style="color:#64748b;margin-bottom:20px;">Individual emails with custom workflows attached (transactional campaigns).</p>
                
                <div style="margin-bottom:20px;padding:16px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:6px;">
                    <strong style="color:#92400e;">💡 Pro Tip:</strong> When you send an email, you can attach a custom workflow to it. 
                    The workflow will automatically trigger based on how the recipient interacts with that specific email.
                </div>
                
                <div id="campaignsLoading" style="text-align:center;padding:40px;color:#64748b;">Loading campaigns...</div>
                <div id="campaignsContent" style="display:none;">
                    <table>
                        <thead>
                            <tr>
                                <th>Email Sent</th>
                                <th>Recipient</th>
                                <th>Workflow</th>
                                <th>Status</th>
                                <th>Follow-ups Scheduled</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="campaignsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Performance -->
    <div id="tab-performance" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">📊 Workflow Performance</h3>
                <p style="color:#64748b;margin-bottom:20px;">Analytics for your custom workflows.</p>
                
                <div id="perfLoading" style="text-align:center;padding:40px;color:#64748b;">Loading analytics...</div>
                <div id="perfContent" style="display:none;">
                    <div id="perfStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;"></div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Workflow</th>
                                <th>Scheduled</th>
                                <th>Sent</th>
                                <th>Opened</th>
                                <th>Clicked</th>
                                <th>Converted</th>
                                <th>Open Rate</th>
                                <th>Conv. Rate</th>
                            </tr>
                        </thead>
                        <tbody id="perfTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script src="<?=asset_url('/assets/workflow-entity-config.js')?>"></script>

<script>
let currentPreviewGeneration = null;
let currentPreviewWorkflowId = null;

function switchTab(name) {
    const tabs = ['my-workflows', 'create', 'ai-preview', 'decision-trees', 'campaigns', 'performance'];
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    const idx = tabs.indexOf(name);
    if (idx > -1) {
        const tabEl = document.querySelector('.tabs .tab:nth-child(' + (idx + 1) + ')');
        if (tabEl) tabEl.classList.add('active');
    }
    
    const target = document.getElementById('tab-' + name);
    if (target) target.classList.add('active');
    
    // Load data when tab opened
    if (name === 'my-workflows') {
        loadMyWorkflows();
        loadGlobalWorkflows();
    } else if (name === 'ai-preview') {
        loadWorkflowsForPreview();
    } else if (name === 'decision-trees') {
        loadWorkflowsForDecisionTree();
    } else if (name === 'campaigns') {
        loadCampaigns();
    } else if (name === 'performance') {
        loadPerformance();
    }
}

async function loadMyWorkflows() {
    const loading = document.getElementById('myWorkflowsLoading');
    const content = document.getElementById('myWorkflowsContent');
    const tbody = document.getElementById('myWorkflowsTableBody');
    
    try {
        loading.style.display = 'block';
        content.style.display = 'none';
        
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) {
            loading.textContent = 'Error: ' + (json.error || 'Failed to load');
            return;
        }
        
        // Filter to agent's own workflows
        const myWorkflows = json.workflows.filter(w => w.scope === 'agent' && w.owner_agent_id == <?=$uid?>);
        
        tbody.innerHTML = '';
        
        if (myWorkflows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748b;">You haven\'t created any workflows yet. Click "Create Workflow" to get started!</td></tr>';
        } else {
            myWorkflows.forEach(w => {
                const tr = document.createElement('tr');
                const statusBadge = w.is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Inactive</span>';
                const aiBadge = w.allow_ai_generation ? '✓ AI' : '—';
                
                tr.innerHTML = `
                    <td><strong>${w.name}</strong></td>
                    <td><span class="badge">${w.trigger_type.replace(/_/g, ' ')}</span></td>
                    <td>${w.trigger_delay_hours}h</td>
                    <td>${aiBadge}</td>
                    <td>${statusBadge}</td>
                    <td><button class="btn btn-sm" onclick="viewWorkflowUsage(${w.id})">View</button></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm" onclick="editMyWorkflow(${w.id})">Edit</button>
                        <button class="btn btn-sm ${w.is_active ? 'btn-danger' : 'btn-success'}" onclick="toggleMyWorkflow(${w.id}, ${w.is_active ? 0 : 1})">${w.is_active ? 'Disable' : 'Enable'}</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteMyWorkflow(${w.id})">Delete</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        loading.style.display = 'none';
        content.style.display = 'block';
        
    } catch (e) {
        loading.textContent = 'Error: ' + e.message;
    }
}

async function loadGlobalWorkflows() {
    const loading = document.getElementById('globalWorkflowsLoading');
    const content = document.getElementById('globalWorkflowsContent');
    const tbody = document.getElementById('globalWorkflowsTableBody');
    
    try {
        loading.style.display = 'block';
        content.style.display = 'none';
        
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) {
            loading.textContent = 'Error: ' + (json.error || 'Failed to load');
            return;
        }
        
        // Filter to global workflows
        const globalWorkflows = json.workflows.filter(w => w.scope === 'global');
        
        tbody.innerHTML = '';
        
        globalWorkflows.forEach(w => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${w.name}</strong></td>
                <td><span class="badge">${w.trigger_type.replace(/_/g, ' ')}</span></td>
                <td>${w.trigger_delay_hours}h</td>
                <td style="font-size:12px;color:#64748b;">${w.description || '—'}</td>
            `;
            tbody.appendChild(tr);
        });
        
        loading.style.display = 'none';
        content.style.display = 'block';
        
    } catch (e) {
        loading.textContent = 'Error: ' + e.message;
    }
}

async function saveMyWorkflow(event) {
    event.preventDefault();
    
    const workflowId = document.getElementById('workflow_id').value;
    const scope = document.getElementById('workflow_scope').value;
    
    const payload = {
        name: document.getElementById('workflow_name').value,
        description: document.getElementById('workflow_description').value,
        trigger_type: document.getElementById('workflow_trigger').value,
        trigger_delay_hours: parseInt(document.getElementById('workflow_delay').value),
        scope: scope,
        owner_agent_id: <?=$uid?>,
        template_id: parseInt(document.getElementById('workflow_template').value) || null,
        allow_ai_generation: document.getElementById('workflow_ai').checked ? 1 : 0,
        priority: parseInt(document.getElementById('workflow_priority').value),
        is_active: document.getElementById('workflow_active').checked ? 1 : 0
    };
    
    if (workflowId) {
        payload.id = parseInt(workflowId);
    }
    
    try {
        const action = workflowId ? 'update_workflow' : 'create_workflow';
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=')?>' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const json = await r.json();
        
        if (!json.ok) {
            alert('Error: ' + (json.error || 'Failed to save'));
            return false;
        }
        
        alert('✅ Workflow saved successfully!');
        clearWorkflowForm();
        switchTab('my-workflows');
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
    
    return false;
}

async function editMyWorkflow(workflowId) {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow&id=')?>' + workflowId, {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok || !json.workflow) {
            alert('Error loading workflow');
            return;
        }
        
        const w = json.workflow;
        document.getElementById('workflow_id').value = w.id;
        document.getElementById('workflow_name').value = w.name;
        document.getElementById('workflow_description').value = w.description || '';
        document.getElementById('workflow_trigger').value = w.trigger_type;
        document.getElementById('workflow_delay').value = w.trigger_delay_hours;
        document.getElementById('workflow_scope').value = w.scope;
        document.getElementById('workflow_template').value = w.template_id || '';
        document.getElementById('workflow_ai').checked = !!w.allow_ai_generation;
        document.getElementById('workflow_priority').value = w.priority;
        document.getElementById('workflow_active').checked = !!w.is_active;
        
        switchTab('create');
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function clearWorkflowForm() {
    document.getElementById('workflow_id').value = '';
    document.getElementById('workflow_name').value = '';
    document.getElementById('workflow_description').value = '';
    document.getElementById('workflow_trigger').value = '';
    document.getElementById('workflow_delay').value = '72';
    document.getElementById('workflow_scope').value = 'agent';
    document.getElementById('workflow_template').value = '';
    document.getElementById('workflow_ai').checked = true;
    document.getElementById('workflow_priority').value = '50';
    document.getElementById('workflow_active').checked = true;
    toggleWorkflowTypeHelp();
}

async function toggleMyWorkflow(workflowId, enable) {
    if (!confirm('Are you sure you want to ' + (enable ? 'enable' : 'disable') + ' this workflow?')) return;
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=update_workflow')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: workflowId, is_active: enable})
        });
        const json = await r.json();
        
        if (!json.ok) {
            alert('Error: ' + (json.error || 'Failed'));
            return;
        }
        
        loadMyWorkflows();
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteMyWorkflow(workflowId) {
    if (!confirm('Are you sure you want to delete this workflow? This cannot be undone.')) return;
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=delete_workflow')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: workflowId})
        });
        const json = await r.json();
        
        if (!json.ok) {
            alert('Error: ' + (json.error || 'Failed'));
            return;
        }
        
        alert('Workflow deleted');
        loadMyWorkflows();
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function viewWorkflowUsage(workflowId) {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow_analytics&workflow_id=')?>' + workflowId, {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok || !json.analytics || json.analytics.length === 0) {
            alert('No usage data available yet for this workflow.');
            return;
        }
        
        const w = json.analytics[0];
        const msg = `Usage for: ${w.workflow_name}\n\n` +
                    `Total Scheduled: ${w.total_scheduled}\n` +
                    `Sent: ${w.sent}\n` +
                    `Failed: ${w.failed}\n\n` +
                    `Performance:\n` +
                    `Opened: ${w.opened} (${w.open_rate}%)\n` +
                    `Clicked: ${w.clicked} (${w.click_rate}%)\n` +
                    `Converted: ${w.converted} (${w.conversion_rate}%)`;
        
        alert(msg);
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function toggleWorkflowTypeHelp() {
    const scope = document.getElementById('workflow_scope').value;
    const entitySelector = document.getElementById('entitySelector');
    entitySelector.style.display = (scope === 'lead' || scope === 'account') ? 'block' : 'none';
}

async function searchEntities() {
    // TODO: Implement entity search
    console.log('Entity search not yet implemented');
}

async function loadCampaigns() {
    const loading = document.getElementById('campaignsLoading');
    const content = document.getElementById('campaignsContent');
    const tbody = document.getElementById('campaignsTableBody');
    
    try {
        loading.style.display = 'block';
        content.style.display = 'none';
        
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_scheduled_followups')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) {
            loading.textContent = 'Error: ' + (json.error || 'Failed to load');
            return;
        }
        
        tbody.innerHTML = '';
        
        if (!json.followups || json.followups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748b;">No active campaigns yet</td></tr>';
        } else {
            json.followups.forEach(f => {
                const tr = document.createElement('tr');
                const statusBadge = `<span class="badge">${f.status.toUpperCase()}</span>`;
                
                tr.innerHTML = `
                    <td>${new Date(f.parent_sent_at || Date.now()).toLocaleDateString()}</td>
                    <td>${f.recipient_email}</td>
                    <td>${f.workflow_name}</td>
                    <td>${statusBadge}</td>
                    <td>${new Date(f.scheduled_for).toLocaleString()}</td>
                    <td>${f.status === 'pending' ? '<button class="btn btn-sm btn-danger" onclick="WorkflowEntityConfig.cancelFollowup(' + f.id + ')">Cancel</button>' : '—'}</td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        loading.style.display = 'none';
        content.style.display = 'block';
        
    } catch (e) {
        loading.textContent = 'Error: ' + e.message;
    }
}

async function loadPerformance() {
    const loading = document.getElementById('perfLoading');
    const content = document.getElementById('perfContent');
    const statsDiv = document.getElementById('perfStats');
    const tbody = document.getElementById('perfTableBody');
    
    try {
        loading.style.display = 'block';
        content.style.display = 'none';
        
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow_analytics')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) {
            loading.textContent = 'Error: ' + (json.error || 'Failed to load');
            return;
        }
        
        // Filter to agent's workflows
        const myAnalytics = json.analytics.filter(w => w.workflow_id);
        
        // Calculate totals
        let totalScheduled = 0, totalSent = 0, totalOpened = 0, totalConverted = 0;
        myAnalytics.forEach(w => {
            totalScheduled += parseInt(w.total_scheduled || 0);
            totalSent += parseInt(w.sent || 0);
            totalOpened += parseInt(w.opened || 0);
            totalConverted += parseInt(w.converted || 0);
        });
        
        const statCard = (label, value, color) => {
            return `<div style="background:#fff;padding:16px;border-radius:10px;border:1px solid #e5e7eb;">
                <div style="font-size:2rem;font-weight:700;color:${color};">${value}</div>
                <div style="font-size:0.85rem;color:#64748b;text-transform:uppercase;">${label}</div>
            </div>`;
        };
        
        statsDiv.innerHTML = statCard('Scheduled', totalScheduled, '#0369a1') +
                            statCard('Sent', totalSent, '#10b981') +
                            statCard('Opened', totalOpened, '#f59e0b') +
                            statCard('Converted', totalConverted, '#8b5cf6');
        
        tbody.innerHTML = '';
        
        if (myAnalytics.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#64748b;">No performance data yet</td></tr>';
        } else {
            myAnalytics.forEach(w => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>${w.workflow_name || 'Unknown'}</strong></td>
                    <td>${w.total_scheduled}</td>
                    <td>${w.sent}</td>
                    <td>${w.opened}</td>
                    <td>${w.clicked}</td>
                    <td>${w.converted}</td>
                    <td><strong>${w.open_rate}%</strong></td>
                    <td><strong>${w.conversion_rate}%</strong></td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        loading.style.display = 'none';
        content.style.display = 'block';
        
    } catch (e) {
        loading.textContent = 'Error: ' + e.message;
    }
}

// Load templates on page load
async function loadTemplates() {
    try {
        const r = await fetch('<?=outbound_path('/api/ai-email-composer.php?action=get_templates')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (json.ok && json.templates) {
            const select = document.getElementById('workflow_template');
            json.templates.forEach(t => {
                const option = document.createElement('option');
                option.value = t.id;
                option.textContent = t.name;
                select.appendChild(option);
            });
        }
    } catch (e) {
        console.error('Failed to load templates:', e);
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadMyWorkflows();
    loadGlobalWorkflows();
    loadTemplates();
});

// ============================================================================
// AI Preview Functions
// ============================================================================

async function loadWorkflowsForPreview() {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) return;
        
        const select = document.getElementById('previewWorkflowSelect');
        select.innerHTML = '<option value="">Select a workflow...</option>';
        
        // Add agent's workflows
        const myWorkflows = json.workflows.filter(w => w.scope === 'agent' && w.owner_agent_id == <?=$uid?>);
        myWorkflows.forEach(w => {
            const opt = document.createElement('option');
            opt.value = w.id;
            opt.dataset.trigger = w.trigger_type;
            opt.textContent = w.name;
            select.appendChild(opt);
        });
        
    } catch (e) {
        console.error('Failed to load workflows:', e);
    }
}

function loadWorkflowForPreview() {
    const select = document.getElementById('previewWorkflowSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption || !selectedOption.value) {
        currentPreviewWorkflowId = null;
        return;
    }
    
    currentPreviewWorkflowId = parseInt(selectedOption.value);
    const triggerType = selectedOption.dataset.trigger;
    
    document.getElementById('previewTriggerType').value = triggerType;
    
    // Load existing template if available
    loadExistingTemplate(currentPreviewWorkflowId);
}

async function loadExistingTemplate(workflowId) {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=get_workflow_template&workflow_id=')?>' + workflowId, {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (json.ok && json.template) {
            document.getElementById('previewSubject').textContent = json.template.subject_template || 'No subject';
            document.getElementById('previewBody').innerHTML = json.template.body_template || '<p style="color:#64748b;text-align:center;">No template saved yet</p>';
            document.getElementById('previewMeta').style.display = 'block';
            
            if (json.template.ai_prompt_used) {
                document.getElementById('previewPrompt').value = json.template.ai_prompt_used;
            }
        }
    } catch (e) {
        console.error('Failed to load template:', e);
    }
}

async function generatePreview() {
    const workflowId = currentPreviewWorkflowId;
    const prompt = document.getElementById('previewPrompt').value.trim();
    const triggerType = document.getElementById('previewTriggerType').value;
    
    if (!workflowId) {
        alert('Please select a workflow first');
        return;
    }
    
    if (!prompt) {
        alert('Please provide AI instructions');
        return;
    }
    
    const generating = document.getElementById('previewGenerating');
    generating.style.display = 'block';
    document.getElementById('previewActions').style.display = 'none';
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=preview_ai_template')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                workflow_id: workflowId,
                trigger_type: triggerType,
                prompt: prompt
            })
        });
        
        const json = await r.json();
        
        if (!json.ok) {
            throw new Error(json.error || 'Generation failed');
        }
        
        // Update preview
        document.getElementById('previewSubject').textContent = json.subject;
        document.getElementById('previewBody').innerHTML = json.body;
        document.getElementById('previewMeta').style.display = 'block';
        document.getElementById('previewActions').style.display = 'block';
        
        currentPreviewGeneration = json;
        
    } catch (e) {
        alert('Generation failed: ' + e.message);
    } finally {
        generating.style.display = 'none';
    }
}

async function savePreviewAsTemplate() {
    if (!currentPreviewGeneration || !currentPreviewWorkflowId) {
        alert('No preview to save');
        return;
    }
    
    if (!confirm('Save this AI-generated content as the template for this workflow?')) {
        return;
    }
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=save_workflow_template')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                workflow_id: currentPreviewWorkflowId,
                subject: currentPreviewGeneration.subject,
                body: currentPreviewGeneration.body,
                generation_id: currentPreviewGeneration.generation_id
            })
        });
        
        const json = await r.json();
        
        if (!json.ok) {
            throw new Error(json.error || 'Save failed');
        }
        
        alert('✅ Template saved! This workflow will now use this content for all follow-ups.');
        
    } catch (e) {
        alert('Save failed: ' + e.message);
    }
}

// ============================================================================
// Decision Tree Functions
// ============================================================================

async function loadWorkflowsForDecisionTree() {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) return;
        
        const select = document.getElementById('dtWorkflowSelect');
        select.innerHTML = '<option value="">Choose workflow...</option>';
        
        // Add agent's workflows
        const myWorkflows = json.workflows.filter(w => w.scope === 'agent' && w.owner_agent_id == <?=$uid?>);
        myWorkflows.forEach(w => {
            const opt = document.createElement('option');
            opt.value = w.id;
            opt.textContent = w.name + (w.has_conditions ? ' 🌳' : '');
            select.appendChild(opt);
        });
        
    } catch (e) {
        console.error('Failed to load workflows:', e);
    }
}

async function loadWorkflowConditions() {
    const workflowId = parseInt(document.getElementById('dtWorkflowSelect').value);
    
    if (!workflowId) {
        document.getElementById('btnAddCondition').disabled = true;
        document.getElementById('conditionsList').innerHTML = '';
        document.getElementById('decisionTreeViz').innerHTML = '<p style="text-align:center;color:#64748b;margin:80px 0;">Select a workflow to view its decision tree</p>';
        return;
    }
    
    document.getElementById('btnAddCondition').disabled = false;
    document.getElementById('conditionsLoading').style.display = 'block';
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=get_conditions&workflow_id=')?>' + workflowId, {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) {
            throw new Error(json.error || 'Failed to load');
        }
        
        renderConditions(json.conditions || []);
        renderDecisionTree(workflowId, json.conditions || []);
        
    } catch (e) {
        alert('Error: ' + e.message);
    } finally {
        document.getElementById('conditionsLoading').style.display = 'none';
    }
}

function renderConditions(conditions) {
    const container = document.getElementById('conditionsList');
    
    if (conditions.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;border:2px dashed #e5e7eb;border-radius:12px;">No conditions configured. Click "Add Condition Branch" to create decision tree logic.</div>';
        return;
    }
    
    let html = '';
    
    conditions.forEach(c => {
        html += `
            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                    <div>
                        <div style="font-weight:600;font-size:15px;margin-bottom:4px;">
                            ${c.condition_type.replace(/_/g, ' ')} ${c.operator} "${c.condition_value || 'any'}"
                        </div>
                        <div style="font-size:12px;color:#64748b;">Priority: ${c.priority}</div>
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="deleteCondition(${c.id})">Delete</button>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding-top:12px;border-top:1px solid #e5e7eb;">
                    <div style="background:#dcfce7;padding:12px;border-radius:6px;">
                        <div style="font-size:11px;color:#166534;font-weight:600;margin-bottom:4px;">✓ IF TRUE:</div>
                        <div style="font-size:13px;color:#065f46;">${c.next_true_name || 'No action'}</div>
                    </div>
                    <div style="background:#fee2e2;padding:12px;border-radius:6px;">
                        <div style="font-size:11px;color:#991b1b;font-weight:600;margin-bottom:4px;">✗ IF FALSE:</div>
                        <div style="font-size:13px;color:#7f1d1d;">${c.next_false_name || 'No action'}</div>
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function renderDecisionTree(workflowId, conditions) {
    const viz = document.getElementById('decisionTreeViz');
    
    if (conditions.length === 0) {
        viz.innerHTML = '<p style="text-align:center;color:#64748b;margin:80px 0;">No decision branches configured yet</p>';
        return;
    }
    
    // Simple text-based tree visualization
    let tree = '<div style="font-family:monospace;font-size:13px;line-height:2;">';
    tree += '<div style="font-weight:600;margin-bottom:12px;color:#1f2937;">📧 Workflow Decision Flow:</div>';
    
    conditions.forEach((c, idx) => {
        tree += `
            <div style="margin-left:${idx * 20}px;padding:8px;background:#f8fafc;border-left:3px solid #0369a1;margin-bottom:8px;">
                <div style="color:#0369a1;font-weight:600;">Condition ${idx + 1}: ${c.condition_type}</div>
                <div style="margin-left:20px;margin-top:4px;">
                    <div style="color:#16a34a;">✓ ${c.operator} "${c.condition_value}" → ${c.next_true_name || 'End'}</div>
                    <div style="color:#dc2626;">✗ Otherwise → ${c.next_false_name || 'End'}</div>
                </div>
            </div>
        `;
    });
    
    tree += '</div>';
    viz.innerHTML = tree;
}

function showAddConditionModal() {
    const workflowId = parseInt(document.getElementById('dtWorkflowSelect').value);
    
    if (!workflowId) {
        alert('Please select a workflow first');
        return;
    }
    
    // Simple prompt-based condition creation
    const conditionType = prompt('Condition type:\n1. email_opened\n2. email_clicked\n3. conversion_type\n4. conversion_value\n5. time_elapsed\n\nEnter number:', '1');
    
    if (!conditionType) return;
    
    const typeMap = {'1': 'email_opened', '2': 'email_clicked', '3': 'conversion_type', '4': 'conversion_value', '5': 'time_elapsed'};
    const selectedType = typeMap[conditionType] || 'email_opened';
    
    const operator = prompt('Operator:\n1. equals\n2. not_equals\n3. greater_than\n4. less_than\n\nEnter number:', '1');
    const opMap = {'1': 'equals', '2': 'not_equals', '3': 'greater_than', '4': 'less_than'};
    const selectedOp = opMap[operator] || 'equals';
    
    const value = prompt('Condition value (e.g., "true", "500", "order_complete"):', '');
    
    const nextTrue = prompt('Next workflow ID if TRUE (0 for none):', '0');
    const nextFalse = prompt('Next workflow ID if FALSE (0 for none):', '0');
    
    addCondition(workflowId, selectedType, selectedOp, value, parseInt(nextTrue) || null, parseInt(nextFalse) || null);
}

async function addCondition(workflowId, conditionType, operator, value, nextTrue, nextFalse) {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=add_condition')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                workflow_id: workflowId,
                condition_type: conditionType,
                operator: operator,
                condition_value: value,
                next_workflow_id_true: nextTrue,
                next_workflow_id_false: nextFalse
            })
        });
        
        const json = await r.json();
        
        if (!json.ok) {
            throw new Error(json.error || 'Failed to add condition');
        }
        
        alert('✅ Condition added successfully!');
        loadWorkflowConditions();
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteCondition(conditionId) {
    if (!confirm('Delete this condition?')) return;
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=delete_condition')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: conditionId})
        });
        
        const json = await r.json();
        
        if (!json.ok) {
            throw new Error(json.error || 'Failed');
        }
        
        loadWorkflowConditions();
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Add CSS for spinner animation
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

