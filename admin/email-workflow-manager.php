<?php
/**
 * Admin Email Workflow Manager
 * Comprehensive workflow administration, analytics, and monitoring
 */

require_once __DIR__ . '/../required/config.php';

$SEO = ['title' => 'Email Workflow Manager', 'description' => 'Manage email workflows', 'type' => 'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();

// Get all workflows with owner information
$workflows = $pdo->query("
    SELECT w.*, 
           CONCAT(u.first_name, ' ', u.last_name) as owner_name
    FROM email_followup_workflows w
    LEFT JOIN users u ON u.id = w.owner_agent_id
    ORDER BY w.scope, w.trigger_type, w.priority DESC
")->fetchAll();

// Get overall stats
$stats = $pdo->query("
    SELECT 
        COUNT(DISTINCT w.id) as total_workflows,
        COUNT(DISTINCT CASE WHEN w.is_active = 1 THEN w.id END) as active_workflows,
        COUNT(fs.id) as total_scheduled,
        SUM(CASE WHEN fs.status = 'sent' THEN 1 ELSE 0 END) as total_sent,
        SUM(CASE WHEN fs.status = 'pending' THEN 1 ELSE 0 END) as pending_followups
    FROM email_followup_workflows w
    LEFT JOIN email_followup_schedule fs ON fs.workflow_id = w.id
")->fetch();
?>

<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; }
.page { max-width: 1600px; margin: 0 auto; padding: 24px; }
.card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(15,23,42,0.08); margin-bottom: 20px; overflow: hidden; }
.card-header { background: linear-gradient(135deg, #14b8a6, #0ea5e9); color: #fff; padding: 20px 24px; }
.card-header h1 { margin: 0; font-size: 24px; }
.card-header p { margin: 8px 0 0; opacity: .9; font-size: 14px; }
.card-body { padding: 24px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: #f8fafc; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb; }
.stat-value { font-size: 2.5rem; font-weight: 700; margin-bottom: 4px; }
.stat-label { font-size: 0.85rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
.tabs { display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; }
.tab { padding: 12px 20px; cursor: pointer; border-bottom: 3px solid transparent; color: #64748b; font-weight: 500; }
.tab.active { border-bottom-color: #0ea5e9; color: #0ea5e9; }
.tab-content { display: none; }
.tab-content.active { display: block; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #f1f5f9; }
th { background: #f8fafc; font-weight: 600; color: #475569; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #f1f5f9; color: #475569; }
.badge-global { background: #dbeafe; color: #1e40af; }
.badge-agent { background: #fef3c7; color: #92400e; }
.badge-lead { background: #f3e8ff; color: #6b21a8; }
.badge-account { background: #dcfce7; color: #166534; }
.btn { padding: 8px 14px; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; }
.btn:hover { background: #1d4ed8; }
.btn-sm { padding: 4px 10px; font-size: 11px; }
.btn-secondary { background: #64748b; }
.btn-secondary:hover { background: #475569; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.btn-success { background: #16a34a; }
.btn-success:hover { background: #15803d; }
</style>

<div class="page">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h1>🔄 Email Workflow Manager</h1>
            <p>Comprehensive workflow administration, analytics, and monitoring</p>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" style="color:#0369a1;"><?=intval($stats['total_workflows'])?></div>
            <div class="stat-label">Total Workflows</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#10b981;"><?=intval($stats['active_workflows'])?></div>
            <div class="stat-label">Active Workflows</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#f59e0b;"><?=intval($stats['pending_followups'])?></div>
            <div class="stat-label">Pending Follow-ups</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#8b5cf6;"><?=intval($stats['total_sent'])?></div>
            <div class="stat-label">Total Sent</div>
        </div>
    </div>
    
    <div class="tabs">
        <div class="tab active" onclick="switchTab('all')">All Workflows</div>
        <div class="tab" onclick="switchTab('global')">Global Templates</div>
        <div class="tab" onclick="switchTab('agents')">Agent Workflows</div>
        <div class="tab" onclick="switchTab('scheduled')">Scheduled Follow-ups</div>
        <div class="tab" onclick="switchTab('analytics')">Analytics</div>
    </div>
    
    <!-- TAB: All Workflows -->
    <div id="tab-all" class="tab-content active">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">All Workflows</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Trigger</th>
                            <th>Delay</th>
                            <th>Scope</th>
                            <th>Owner</th>
                            <th>AI</th>
                            <th>Priority</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workflows as $w): ?>
                        <tr>
                            <td><?=$w['id']?></td>
                            <td><strong><?=htmlspecialchars($w['name'])?></strong></td>
                            <td><span class="badge"><?=str_replace('_', ' ', $w['trigger_type'])?></span></td>
                            <td><?=$w['trigger_delay_hours']?>h</td>
                            <td><span class="badge badge-<?=$w['scope']?>"><?=$w['scope']?></span></td>
                            <td><?=htmlspecialchars($w['owner_name'] ?? '—')?></td>
                            <td><?=$w['allow_ai_generation'] ? '✓' : '—'?></td>
                            <td><?=$w['priority']?></td>
                            <td><?=$w['is_active'] ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#94a3b8;">—</span>'?></td>
                            <td style="white-space:nowrap;">
                                <button class="btn btn-sm" onclick="viewWorkflowDetails(<?=$w['id']?>)">Details</button>
                                <button class="btn btn-sm <?=$w['is_active'] ? 'btn-danger' : 'btn-success'?>" 
                                        onclick="toggleWorkflow(<?=$w['id']?>, <?=$w['is_active'] ? 0 : 1?>)">
                                    <?=$w['is_active'] ? 'Disable' : 'Enable'?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- TAB: Global Templates -->
    <div id="tab-global" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Global Workflow Templates</h3>
                <p style="color:#64748b;margin-bottom:20px;">System-wide workflows available to all sales agents.</p>
                
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Trigger</th>
                            <th>Delay</th>
                            <th>Description</th>
                            <th>Template</th>
                            <th>AI</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $globalWorkflows = array_filter($workflows, fn($w) => $w['scope'] === 'global');
                        foreach ($globalWorkflows as $w): 
                        ?>
                        <tr>
                            <td><strong><?=htmlspecialchars($w['name'])?></strong></td>
                            <td><span class="badge"><?=str_replace('_', ' ', $w['trigger_type'])?></span></td>
                            <td><?=$w['trigger_delay_hours']?>h</td>
                            <td style="font-size:12px;color:#64748b;"><?=htmlspecialchars($w['description'] ?? '—')?></td>
                            <td><?=$w['template_id'] ? 'Template #' . $w['template_id'] : '—'?></td>
                            <td><?=$w['allow_ai_generation'] ? '✓ AI' : '—'?></td>
                            <td><?=$w['priority']?></td>
                            <td><?=$w['is_active'] ? '<span style="color:#16a34a;">Active</span>' : '<span style="color:#94a3b8;">Inactive</span>'?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- TAB: Agent Workflows -->
    <div id="tab-agents" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Agent-Created Workflows</h3>
                <p style="color:#64748b;margin-bottom:20px;">Custom workflows created by individual sales agents.</p>
                
                <table>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Workflow Name</th>
                            <th>Trigger</th>
                            <th>Delay</th>
                            <th>Scope</th>
                            <th>Active</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $agentWorkflows = array_filter($workflows, fn($w) => $w['scope'] === 'agent');
                        foreach ($agentWorkflows as $w): 
                        ?>
                        <tr>
                            <td><?=htmlspecialchars($w['owner_name'] ?? 'Unknown')?></td>
                            <td><strong><?=htmlspecialchars($w['name'])?></strong></td>
                            <td><span class="badge"><?=str_replace('_', ' ', $w['trigger_type'])?></span></td>
                            <td><?=$w['trigger_delay_hours']?>h</td>
                            <td><span class="badge badge-agent">Agent</span></td>
                            <td><?=$w['is_active'] ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#94a3b8;">—</span>'?></td>
                            <td><button class="btn btn-sm" onclick="viewWorkflowPerformance(<?=$w['id']?>)">View Stats</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- TAB: Scheduled Follow-ups -->
    <div id="tab-scheduled" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Scheduled Follow-ups</h3>
                
                <div style="display:flex;gap:12px;margin-bottom:20px;">
                    <select id="filterStatus" onchange="loadScheduled()" style="padding:8px;border:1px solid #e5e7eb;border-radius:6px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="sent">Sent</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="failed">Failed</option>
                    </select>
                    <select id="filterScope" onchange="loadScheduled()" style="padding:8px;border:1px solid #e5e7eb;border-radius:6px;">
                        <option value="">All Scopes</option>
                        <option value="global">Global</option>
                        <option value="agent">Agent</option>
                    </select>
                    <button class="btn btn-sm" onclick="loadScheduled()">Refresh</button>
                </div>
                
                <div id="scheduledLoading" style="text-align:center;padding:40px;color:#64748b;">Loading...</div>
                <div id="scheduledContent" style="display:none;">
                    <table id="scheduledTable">
                        <thead>
                            <tr>
                                <th>Scheduled</th>
                                <th>Workflow</th>
                                <th>Agent</th>
                                <th>Recipient</th>
                                <th>Entity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="scheduledBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Analytics -->
    <div id="tab-analytics" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Workflow Performance Analytics</h3>
                
                <div id="analyticsLoading" style="text-align:center;padding:40px;color:#64748b;">Loading analytics...</div>
                <div id="analyticsContent" style="display:none;">
                    <table id="analyticsTable">
                        <thead>
                            <tr>
                                <th>Workflow</th>
                                <th>Scope</th>
                                <th>Scheduled</th>
                                <th>Sent</th>
                                <th>Failed</th>
                                <th>Opened</th>
                                <th>Clicked</th>
                                <th>Converted</th>
                                <th>Open Rate</th>
                                <th>Conv. Rate</th>
                            </tr>
                        </thead>
                        <tbody id="analyticsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(name) {
    const tabs = ['all', 'global', 'agents', 'scheduled', 'analytics'];
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    const idx = tabs.indexOf(name);
    if (idx > -1) {
        const tabEl = document.querySelector('.tabs .tab:nth-child(' + (idx + 1) + ')');
        if (tabEl) tabEl.classList.add('active');
    }
    
    const target = document.getElementById('tab-' + name);
    if (target) target.classList.add('active');
    
    // Load data
    if (name === 'scheduled') {
        loadScheduled();
    } else if (name === 'analytics') {
        loadAnalytics();
    }
}

async function loadScheduled() {
    const loading = document.getElementById('scheduledLoading');
    const content = document.getElementById('scheduledContent');
    const tbody = document.getElementById('scheduledBody');
    
    try {
        loading.style.display = 'block';
        content.style.display = 'none';
        
        const status = document.getElementById('filterStatus').value;
        let url = '<?=outbound_path('/api/email-workflow-api.php?action=get_scheduled_followups')?>';
        if (status) url += '&status=' + encodeURIComponent(status);
        
        const r = await fetch(url, {credentials: 'same-origin'});
        const json = await r.json();
        
        if (!json.ok) {
            loading.textContent = 'Error: ' + (json.error || 'Failed');
            return;
        }
        
        tbody.innerHTML = '';
        
        if (!json.followups || json.followups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748b;">No scheduled follow-ups</td></tr>';
        } else {
            json.followups.forEach(f => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${new Date(f.scheduled_for).toLocaleString()}</td>
                    <td>${f.workflow_name}</td>
                    <td>${f.owner_name || '—'}</td>
                    <td>${f.recipient_email}</td>
                    <td>${f.entity_type} #${f.entity_id}</td>
                    <td><span class="badge">${f.status.toUpperCase()}</span></td>
                    <td>${f.status === 'pending' ? '<button class="btn btn-sm btn-danger" onclick="cancelFollowup(' + f.id + ')">Cancel</button>' : '—'}</td>
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

async function loadAnalytics() {
    const loading = document.getElementById('analyticsLoading');
    const content = document.getElementById('analyticsContent');
    const tbody = document.getElementById('analyticsBody');
    
    try {
        loading.style.display = 'block';
        content.style.display = 'none';
        
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow_analytics')?>', {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok) {
            loading.textContent = 'Error: ' + (json.error || 'Failed');
            return;
        }
        
        tbody.innerHTML = '';
        
        if (!json.analytics || json.analytics.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#64748b;">No analytics data yet</td></tr>';
        } else {
            json.analytics.forEach(w => {
                const tr = document.createElement('tr');
                const scopeBadge = `<span class="badge badge-${w.scope || 'global'}">${w.scope || 'global'}</span>`;
                
                tr.innerHTML = `
                    <td><strong>${w.workflow_name}</strong></td>
                    <td>${scopeBadge}</td>
                    <td>${w.total_scheduled}</td>
                    <td>${w.sent}</td>
                    <td>${w.failed}</td>
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

async function toggleWorkflow(workflowId, enable) {
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
        
        location.reload();
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function cancelFollowup(followupId) {
    const reason = prompt('Reason for cancellation:', 'Admin cancelled');
    if (reason === null) return;
    
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=cancel_followup')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: followupId, reason: reason})
        });
        const json = await r.json();
        
        if (!json.ok) {
            alert('Error: ' + (json.error || 'Failed'));
            return;
        }
        
        alert('Follow-up cancelled');
        loadScheduled();
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function viewWorkflowDetails(workflowId) {
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
        const details = `Workflow: ${w.name}\n\n` +
                       `Description: ${w.description || 'None'}\n` +
                       `Trigger: ${w.trigger_type}\n` +
                       `Delay: ${w.trigger_delay_hours} hours\n` +
                       `Scope: ${w.scope}\n` +
                       `Priority: ${w.priority}\n` +
                       `AI Generation: ${w.allow_ai_generation ? 'Enabled' : 'Disabled'}\n` +
                       `Template ID: ${w.template_id || 'None'}\n` +
                       `Status: ${w.is_active ? 'Active' : 'Inactive'}\n` +
                       `Created: ${w.created_at}`;
        
        alert(details);
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function viewWorkflowPerformance(workflowId) {
    try {
        const r = await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow_analytics&workflow_id=')?>' + workflowId, {
            credentials: 'same-origin'
        });
        const json = await r.json();
        
        if (!json.ok || !json.analytics || json.analytics.length === 0) {
            alert('No performance data available yet');
            return;
        }
        
        const w = json.analytics[0];
        const msg = `Performance: ${w.workflow_name}\n\n` +
                   `Scheduled: ${w.total_scheduled}\n` +
                   `Sent: ${w.sent}\n` +
                   `Failed: ${w.failed}\n` +
                   `Cancelled: ${w.cancelled}\n\n` +
                   `Engagement:\n` +
                   `Opened: ${w.opened} (${w.open_rate}%)\n` +
                   `Clicked: ${w.clicked} (${w.click_rate}%)\n` +
                   `Converted: ${w.converted} (${w.conversion_rate}%)`;
        
        alert(msg);
        
    } catch (e) {
        alert('Error: ' + e.message);
    }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>





