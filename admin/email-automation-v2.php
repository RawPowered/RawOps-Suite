<?php
// admin/email-automation-v2.php
// Modern SendGrid-powered email automation with clean JSON API separation

// All JSON API calls now handled by separate api/email-admin-api.php file

// === NORMAL PAGE FLOW (only runs if NOT an API call) ===
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/email_triggers_templates.php';
require_once __DIR__ . '/../api/sendgrid.php';

header('Content-Type: text/html; charset=utf-8');

$SEO = ['title'=>'Email Automation V2','description'=>'SendGrid automation','type'=>'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));
$pdo = db();

$welcomeTemplates = [
    [
        'slug' => 'welcome-email-template-sendgrid',
        'file' => 'welcome-email-template-sendgrid.html',
        'name' => 'Welcome — General',
        'scope' => 'platform',
        'category' => 'welcome'
    ],
    [
        'slug' => 'welcome-wholesale-sendgrid',
        'file' => 'welcome-wholesale-sendgrid.html',
        'name' => 'Welcome — Wholesale',
        'scope' => 'customer',
        'category' => 'welcome'
    ],
    [
        'slug' => 'welcome-agent-sendgrid',
        'file' => 'welcome-agent-sendgrid.html',
        'name' => 'Welcome — Sales Agent',
        'scope' => 'sales_agent',
        'category' => 'welcome'
    ],
    [
        'slug' => 'legal-access-approved',
        'file' => 'legal-access-approved.html',
        'name' => 'Legal Access Approved',
        'scope' => 'legal',
        'category' => 'legal_access'
    ],
    [
        'slug' => 'legal-access-denied',
        'file' => 'legal-access-denied.html',
        'name' => 'Legal Access Denied',
        'scope' => 'legal',
        'category' => 'legal_access'
    ],
    [
        'slug' => 'inactive-user-3-days',
        'file' => 'inactive-user-3-days.html',
        'name' => 'Inactive User - 3 Days',
        'scope' => 'platform',
        'category' => 'inactive_user'
    ],
    [
        'slug' => 'inactive-user-7-days',
        'file' => 'inactive-user-7-days.html',
        'name' => 'Inactive User - 7 Days',
        'scope' => 'platform',
        'category' => 'inactive_user'
    ],
    [
        'slug' => 'inactive-user-14-days',
        'file' => 'inactive-user-14-days.html',
        'name' => 'Inactive User - 14 Days',
        'scope' => 'platform',
        'category' => 'inactive_user'
    ],
    [
        'slug' => 'inactive-user-30-days',
        'file' => 'inactive-user-30-days.html',
        'name' => 'Inactive User - 30 Days Final Notice',
        'scope' => 'platform',
        'category' => 'inactive_user'
    ],
    [
        'slug' => 'inactive-user-35-days-suspension',
        'file' => 'inactive-user-35-days-suspension.html',
        'name' => 'Inactive User - Account Suspended',
        'scope' => 'platform',
        'category' => 'inactive_user'
    ]
];

$seededTemplates = [];
$seedErrors = [];

foreach ($welcomeTemplates as $tplDef) {
    $slug = $tplDef['slug'];
    $stmt = $pdo->prepare('SELECT id FROM email_templates WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) { continue; }

    $path = __DIR__ . '/emails/' . $tplDef['file'];
    if (!is_file($path)) {
        $seedErrors[] = $tplDef['name'] . ' template file missing (' . $tplDef['file'] . ')';
        continue;
    }

    $html = (string)file_get_contents($path);
    $subject = $tplDef['name'];
    if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
        $subject = trim(strip_tags($m[1]));
    }
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    try {
        $ins = $pdo->prepare('INSERT INTO email_templates (slug, name, subject, html_body, text_body, category, scope, is_active, created_at) VALUES (?,?,?,?,?,?,?,1,NOW())');
        $ins->execute([
            $slug,
            $tplDef['name'],
            $subject,
            $html,
            $text,
            $tplDef['category'],
            $tplDef['scope']
        ]);
        $seededTemplates[] = $tplDef['name'];
    } catch (Throwable $e) {
        $seedErrors[] = $tplDef['name'] . ' failed to seed: ' . $e->getMessage();
    }
}

$notice = null; $error = null;
if (!empty($seededTemplates)) {
    $notice = 'Seeded welcome templates: ' . implode(', ', $seededTemplates);
}
if (!empty($seedErrors)) {
    $error = 'Template issues: ' . implode(' | ', $seedErrors);
}

// Form handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['api'])) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_template') {
            $id = intval($_POST['id'] ?? 0);
            $slug = trim($_POST['slug'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $html = $_POST['html_body'] ?? '';
            $category = trim($_POST['category'] ?? '') ?: null;
            $scope = $_POST['scope'] ?? 'platform';
            $owner = ($tmp = $_POST['owner_user_id'] ?? '') !== '' ? intval($tmp) : null;
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (!$slug || !$name || !$subject) throw new Exception('Slug, name, and subject required');
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE email_templates SET slug=?,name=?,subject=?,html_body=?,text_body=?,category=?,scope=?,owner_user_id=?,is_active=?,updated_at=NOW() WHERE id=?');
                $st->execute([$slug,$name,$subject,$html,$text,$category,$scope,$owner,$active,$id]);
                $notice = 'Template updated';
            } else {
                $st = $pdo->prepare('INSERT INTO email_templates (slug,name,subject,html_body,text_body,category,scope,owner_user_id,is_active,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
                $st->execute([$slug,$name,$subject,$html,$text,$category,$scope,$owner,$active]);
                $notice = 'Template created';
            }
        } elseif ($action === 'delete_template') {
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM email_templates WHERE id=?')->execute([$id]);
            $notice = 'Template deleted';
        } elseif ($action === 'test_send') {
            $to = trim($_POST['to'] ?? '');
            $tid = intval($_POST['template_id'] ?? 0);
            $t = $pdo->prepare('SELECT subject,html_body,text_body FROM email_templates WHERE id=?');
            $t->execute([$tid]);
            $tpl = $t->fetch();
            if (!$tpl) throw new Exception('Template not found');
            $res = sendgrid_send([
                'to'=>$to,'from'=>'sample-006@example.com','from_name'=>'Product',
                'subject'=>$tpl['subject'],'html'=>$tpl['html_body'],'text'=>$tpl['text_body']?:strip_tags($tpl['html_body'])
            ]);
            if (!($res['ok']??false)) throw new Exception('SendGrid failed: HTTP '.($res['status']??0));
            $notice = 'Test sent via SendGrid';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

// Load data
$templates = $pdo->query('SELECT * FROM email_templates ORDER BY updated_at DESC')->fetchAll();
$templatesBySlug = [];
foreach ($templates as $tpl) {
    $templatesBySlug[$tpl['slug']] = $tpl;
}
$events = $pdo->query('SELECT e.event_key,e.name,e.scope,r.id AS rule_id,r.is_active,r.template_id,t.name AS template_name
  FROM email_events e
  LEFT JOIN email_notification_rules r ON r.event_key=e.event_key
  LEFT JOIN email_templates t ON t.id=r.template_id
  WHERE e.is_active=1 ORDER BY e.name')->fetchAll();
$agentTemplates = $pdo->query("SELECT * FROM email_templates WHERE scope='sales_agent' ORDER BY updated_at DESC")->fetchAll();
?>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f8fafc;color:#1e293b}
.page{max-width:1400px;margin:0 auto;padding:24px}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(15,23,42,0.08);margin-bottom:20px;overflow:hidden}
.card-header{background:linear-gradient(135deg,#14b8a6,#0ea5e9);color:#fff;padding:20px 24px}
.card-header h1{margin:0;font-size:24px}
.card-header p{margin:8px 0 0;opacity:.9;font-size:14px}
.card-body{padding:20px}
.grid{display:grid;gap:16px}
.grid-2{grid-template-columns:1fr 1fr}
.grid-3{grid-template-columns:1fr 1fr 1fr}
label{display:block;margin-bottom:12px;font-size:14px;font-weight:500;color:#475569}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;margin-top:4px}
textarea{font-family:monospace;resize:vertical}
.btn{padding:10px 18px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px}
.btn:hover{background:#1d4ed8}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-secondary{background:#64748b}.btn-secondary:hover{background:#475569}
.btn-danger{background:#dc2626}.btn-danger:hover{background:#b91c1c}
.btn-success{background:#16a34a}.btn-success:hover{background:#15803d}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:10px;text-align:left;border-bottom:1px solid #f1f5f9}
th{background:#f8fafc;font-weight:600;color:#475569}
.notice{padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:6px;margin-bottom:16px}
.error{padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:6px;margin-bottom:16px}
.tabs{display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:16px}
.tab{padding:12px 20px;cursor:pointer;border-bottom:3px solid transparent;color:#64748b;font-weight:500}
.tab.active{border-bottom-color:#0ea5e9;color:#0ea5e9}
.tab-content{display:none}.tab-content.active{display:block}
.split{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
.preview{border:1px solid #e2e8f0;border-radius:6px;background:#fff;min-height:500px;width:100%}
.toolbar{display:flex;gap:8px;margin-bottom:8px;align-items:center}
pre{background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto;max-height:300px}
.badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#f1f5f9;color:#475569}
.badge-platform{background:#dbeafe;color:#1e40af}
.badge-agent{background:#fef3c7;color:#92400e}
.badge-customer{background:#f3e8ff;color:#6b21a8}
.status-sent{background:#e0e7ff;color:#4338ca}
.status-delivered{background:#d1fae5;color:#065f46}
.status-opened{background:#fef3c7;color:#92400e}
.status-clicked{background:#dbeafe;color:#1e40af}
.status-bounced{background:#fee2e2;color:#991b1b}
.status-failed{background:#fee2e2;color:#991b1b}
.status-spam{background:#fce7f3;color:#9f1239}
</style>

<div class="page">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h1>📧 Email Automation (SendGrid)</h1>
            <p>Manage templates, events, and automation. Powered by SendGrid delivery.</p>
        </div>
    </div>
    
    <?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif?>
    <?php if($error):?><div class="error">Error: <?=htmlspecialchars($error)?></div><?php endif?>
    
    <div class="tabs">
        <div class="tab active" onclick="switchTab('templates')">Templates</div>
        <div class="tab" onclick="switchTab('events')">Active Events</div>
        <div class="tab" onclick="switchTab('agents')">Agent Templates</div>
        <div class="tab" onclick="switchTab('salesemails')">📧 Agent Emails</div>
        <div class="tab" onclick="switchTab('workflows')">🔄 Follow-up Workflows</div>
        <div class="tab" onclick="switchTab('analytics')">📊 Analytics</div>
        <div class="tab" onclick="switchTab('diagnostics')">Diagnostics</div>
        <div class="tab" onclick="switchTab('heartbeat')">SendGrid Heartbeat</div>
    </div>
    
    <!-- TAB: Templates -->
    <div id="tab-templates" class="tab-content active">
        <div class="card">
            <div class="card-body">
                <div class="grid grid-2">
                    <div>
                        <h3 style="margin-top:0">Editor</h3>
                        <form method="post" id="tplForm">
                            <input type="hidden" name="action" value="save_template">
                            <input type="hidden" name="id" id="tpl_id">
                            <label>Slug<input name="slug" id="tpl_slug" required></label>
                            <label>Name<input name="name" id="tpl_name" required></label>
                            <label>Subject<input name="subject" id="tpl_subject" required></label>
                            <div class="grid grid-3">
                                <label>Category<input name="category" id="tpl_category" placeholder="welcome, invoice"></label>
                                <label>Scope<select name="scope" id="tpl_scope">
                                    <option value="platform">platform</option>
                                    <option value="sales_agent">sales_agent</option>
                                    <option value="customer">customer</option>
                                </select></label>
                                <label>Owner ID<input type="number" name="owner_user_id" id="tpl_owner"></label>
                            </div>
                            
                            <label>
                                HTML Body
                                <div class="toolbar">
                                    <select id="varPicker">
                                        <option value="">Insert variable…</option>
                                        <option value="{{first_name}}">first_name</option>
                                        <option value="{{last_name}}">last_name</option>
                                        <option value="{{email}}">email</option>
                                        <option value="{{login_url}}">login_url</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="insertVar()">Insert</button>
                                </div>
                                <textarea name="html_body" id="tpl_html" rows="18" oninput="updatePreview()" style="font-family:monospace"></textarea>
                            </label>
                            
                            <label style="display:flex;align-items:center;gap:8px">
                                <input type="checkbox" name="is_active" id="tpl_active" checked> Active
                            </label>
                            
                            <div style="display:flex;gap:8px;margin-top:16px">
                                <button class="btn" type="submit">Save Template</button>
                                <button class="btn btn-secondary" type="button" onclick="clearForm()">New</button>
                                <button class="btn btn-secondary" type="button" onclick="syncFromDisk()">Sync from HTML directory</button>
                            </div>
                        </form>
                    </div>
                    
                    <div>
                        <h3 style="margin-top:0">Live Preview</h3>
                        <iframe id="preview" class="preview"></iframe>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">All Templates</h3>
                <table>
                    <thead><tr><th>ID</th><th>Slug</th><th>Name</th><th>Category</th><th>Scope</th><th>Active</th><th>Updated</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach($templates as $t):?>
                        <tr>
                            <td><?=$t['id']?></td>
                            <td><?=htmlspecialchars($t['slug'])?></td>
                            <td><?=htmlspecialchars($t['name'])?></td>
                            <td><?=htmlspecialchars($t['category']??'')?></td>
                            <td><span class="badge badge-<?=$t['scope']??'platform'?>"><?=htmlspecialchars($t['scope']??'platform')?></span></td>
                            <td><?=$t['is_active']?'Yes':'No'?></td>
                            <td><?=htmlspecialchars($t['updated_at'])?></td>
                            <td style="white-space:nowrap">
                                <button class="btn btn-sm" onclick='editTemplate(<?=json_encode($t,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="id" value="<?=$t['id']?>">
                                    <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach?>
                    </tbody>
                </table>
                
                <hr style="margin:20px 0">
                <h3>Test Send</h3>
                <form method="post" style="display:flex;gap:8px">
                    <input type="hidden" name="action" value="test_send">
                    <label style="flex:1">To<input name="to" placeholder="sample-001@example.com" required></label>
                    <label style="flex:1">Template<select name="template_id" required>
                        <?php foreach($templates as $t):?>
                            <option value="<?=$t['id']?>"><?=htmlspecialchars($t['name'])?></option>
                        <?php endforeach?>
                    </select></label>
                    <button class="btn" style="margin-top:28px">Send Test</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- TAB: Events -->
    <div id="tab-events" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">Active Events</h3>
                <p style="color:#64748b;margin-bottom:16px">Manage automated email triggers. Assign templates and enable/disable per event.</p>
                <table>
                    <thead><tr><th>Event</th><th>Scope</th><th>Template</th><th>Enabled</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach($events as $ev):?>
                        <tr>
                            <td>
                                <strong><?=htmlspecialchars($ev['name'])?></strong>
                                <div style="font-size:11px;color:#94a3b8"><?=htmlspecialchars($ev['event_key'])?></div>
                            </td>
                            <td><span class="badge badge-<?=$ev['scope']??'platform'?>"><?=htmlspecialchars($ev['scope']??'')?></span></td>
                            <td>
                                <select onchange="assignTemplate('<?=htmlspecialchars($ev['event_key'])?>',this.value)">
                                    <option value="">— Select template —</option>
                                    <?php foreach($templates as $t):?>
                                        <option value="<?=$t['id']?>" <?=intval($ev['template_id']??0)===intval($t['id'])?'selected':''?>><?=htmlspecialchars($t['name'])?></option>
                                    <?php endforeach?>
                                </select>
                                <?php if($ev['template_name']):?><div style="font-size:11px;color:#64748b;margin-top:4px">Current: <?=htmlspecialchars($ev['template_name'])?></div><?php endif?>
                            </td>
                            <td><?=intval($ev['is_active']??0)===1?'<span style="color:#16a34a">✓ Yes</span>':'<span style="color:#94a3b8">No</span>'?></td>
                            <td>
                                <?php if(intval($ev['is_active']??0)===1):?>
                                    <button class="btn btn-sm btn-danger" onclick="toggleEvent('<?=htmlspecialchars($ev['event_key'])?>',0)">Disable</button>
                                <?php else:?>
                                    <button class="btn btn-sm btn-success" onclick="toggleEvent('<?=htmlspecialchars($ev['event_key'])?>',1)">Enable</button>
                                <?php endif?>
                            </td>
                        </tr>
                    <?php endforeach?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- TAB: Agent Templates -->
    <div id="tab-agents" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">Agent Templates (scope=sales_agent)</h3>
                <p style="color:#64748b;margin-bottom:16px">Templates created by agents. Set scope='sales_agent' and owner_user_id in the editor.</p>
                <table>
                    <thead><tr><th>ID</th><th>Slug</th><th>Name</th><th>Owner</th><th>Category</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach($agentTemplates as $at):?>
                        <tr>
                            <td><?=$at['id']?></td>
                            <td><?=htmlspecialchars($at['slug'])?></td>
                            <td><?=htmlspecialchars($at['name'])?></td>
                            <td><?=htmlspecialchars($at['owner_user_id']??'—')?></td>
                            <td><?=htmlspecialchars($at['category']??'—')?></td>
                            <td><?=htmlspecialchars($at['updated_at'])?></td>
                        </tr>
                    <?php endforeach?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: Agent Emails (Sales Email Composer) -->
    <div id="tab-salesemails" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">📧 Sales Agent Emails - Monitoring & Review</h3>
                <p style="color:#64748b;margin-bottom:16px">Monitor all emails drafted and sent by sales agents. Review AI-generated content for compliance and quality assurance.</p>
                
                <!-- Per-Agent Stats Summary -->
                <div id="perAgentStats" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px;"></div>
                
                <!-- Filters -->
                <div style="display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
                    <select id="filterAgentEmailAgent" class="input" style="max-width:200px;" onchange="loadAgentEmails()">
                        <option value="">All Agents</option>
                    </select>
                    <select id="filterAgentEmailStatus" class="input" style="max-width:150px;" onchange="loadAgentEmails()">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="sent">Sent</option>
                        <option value="delivered">Delivered</option>
                        <option value="bounced">Bounced</option>
                    </select>
                    <select id="filterAiAssisted" class="input" style="max-width:180px;" onchange="loadAgentEmails()">
                        <option value="">All Emails</option>
                        <option value="1">AI-Assisted Only</option>
                        <option value="0">Manual Only</option>
                    </select>
                    <select id="filterReviewed" class="input" style="max-width:180px;" onchange="loadAgentEmails()">
                        <option value="">All Review Status</option>
                        <option value="0">Needs Review</option>
                        <option value="1">Reviewed</option>
                    </select>
                    <button class="btn btn-sm" onclick="loadAgentEmails()">Refresh</button>
                    <button class="btn btn-sm btn-secondary" onclick="loadPerAgentStats()">Refresh Stats</button>
                </div>
                
                <div id="agentEmailsLoading" style="text-align:center;padding:40px;color:#64748b;">
                    Loading agent emails...
                </div>
                
                <div id="agentEmailsContent" style="display:none;">
                    <table id="agentEmailsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Agent</th>
                                <th>To</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>AI</th>
                                <th>Engagement</th>
                                <th>Review</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="agentEmailsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Analytics -->
    <div id="tab-analytics" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">Email Performance Overview</h3>
                <div id="analytics-loading" style="text-align:center;padding:40px;color:#64748b;">Loading analytics...</div>
                <div id="analytics-content" style="display:none;">
                    <div class="grid grid-3" style="margin-bottom:24px;">
                        <div style="background:#f8fafc;padding:20px;border-radius:10px;border:1px solid #e2e8f0;">
                            <div style="font-size:2.5rem;font-weight:700;color:#0d47a1;" id="stat-total-sent">0</div>
                            <div style="font-size:0.85rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Total Emails Sent</div>
                        </div>
                        <div style="background:#f8fafc;padding:20px;border-radius:10px;border:1px solid #e2e8f0;">
                            <div style="font-size:2.5rem;font-weight:700;color:#10b981;" id="stat-delivery-rate">0%</div>
                            <div style="font-size:0.85rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Delivery Rate</div>
                        </div>
                        <div style="background:#f8fafc;padding:20px;border-radius:10px;border:1px solid #e2e8f0;">
                            <div style="font-size:2.5rem;font-weight:700;color:#f59e0b;" id="stat-open-rate">0%</div>
                            <div style="font-size:0.85rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Open Rate</div>
                        </div>
                    </div>
                    
                    <h4>By Event Type</h4>
                    <table id="analytics-by-event">
                        <thead>
                            <tr><th>Event</th><th>Sent</th><th>Delivered</th><th>Opened</th><th>Clicked</th><th>Bounced</th><th>Open Rate</th><th>Click Rate</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    
                    <h4 style="margin-top:32px;">By Template</h4>
                    <table id="analytics-by-template">
                        <thead>
                            <tr><th>Template</th><th>Sent</th><th>Delivered</th><th>Opened</th><th>Clicked</th><th>Open Rate</th><th>Click Rate</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    
                    <h4 style="margin-top:32px;">Recent Emails (Last 100)</h4>
                    <table id="recent-emails">
                        <thead>
                            <tr><th>Recipient</th><th>Subject</th><th>Event</th><th>Status</th><th>Sent</th><th>Opens</th><th>Clicks</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Diagnostics -->
    <div id="tab-diagnostics" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">SendGrid Test</h3>
                <div class="card" style="margin-bottom:16px;background:#f8fafc;border:1px solid #e2e8f0;">
                    <div style="padding:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                        <div style="flex:1 1 260px;">
                            <label style="margin:0;font-weight:600;font-size:13px;color:#475569;display:block;">SendGrid API Key
                                <input id="sendgridKeyInput" type="password" placeholder="SG.xxxxx" style="margin-top:6px;" autocomplete="new-password">
                            </label>
                            <small style="display:block;margin-top:6px;color:#64748b;font-size:12px;">The key is stored encrypted in configuration. Leaving blank keeps the existing key.</small>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button class="btn btn-secondary" type="button" onclick="toggleKeyVisibility()" id="toggleKeyBtn">Show</button>
                        <button class="btn" type="button" onclick="saveSendgridKey()">Save Key</button>
                        </div>
                    </div>
                    <div id="sendgridKeyStatus" style="padding:12px 16px;border-top:1px solid #e2e8f0;background:#fff;font-size:12px;color:#64748b;display:none;"></div>
                </div>
                <label>Send test to<input id="diag_to" value="<?=htmlspecialchars($user['email']??'')?>" placeholder="sample-001@example.com"></label>
                <button class="btn" onclick="runDiagnostics()">Send Test Email</button>
                <button class="btn btn-secondary" type="button" onclick="runDebugDiagnostics()" style="margin-left:8px">🔍 Debug Mode</button>
                <pre id="diagOutput" style="margin-top:16px">Ready.</pre>
                <div style="margin-top:12px;padding:12px;background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;font-size:12px">
                    <strong>Debug Mode:</strong> Uses verbose logging endpoint that writes to <code>required/logs/email-api-debug.log</code>
                </div>
                <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-secondary" type="button" onclick="runSandboxProbe()">Sandbox Heartbeat</button>
                    <button class="btn btn-secondary" type="button" onclick="fetchSendgridStatus()">Refresh Status</button>
                    <strong id="sendgridStatusLabel" style="color:#475569;font-size:13px;">Status unknown</strong>
                </div>
                <pre id="sendgridStatusDump" style="margin-top:12px;white-space:pre-wrap;overflow:auto;max-height:260px;background:#0f172a;color:#d1d5db;padding:12px;border-radius:8px;display:none;"></pre>
            </div>
        </div>
    </div>

    <!-- TAB: Heartbeat -->
    <div id="tab-heartbeat" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">SendGrid Heartbeat & Event Probe</h3>
                <p style="color:#64748b;margin-bottom:16px">Run sandbox pings, live tests, and dry-run probes against notification events to verify automation wiring.</p>
                <div class="grid grid-2">
                    <div>
                        <h4>Heartbeat</h4>
                        <p style="color:#64748b;font-size:13px;margin-bottom:12px">Ping SendGrid using sandbox mode to confirm API key validity and transport availability.</p>
                        <button class="btn" type="button" onclick="runSandboxProbe()">Run Sandbox Heartbeat</button>
                        <button class="btn btn-secondary" type="button" style="margin-left:8px" onclick="runLiveProbe()">Send Live Test</button>
                        <div style="margin-top:12px;font-size:12px;color:#475569;">
                            <strong>Current status:</strong>
                            <span id="heartbeatStatus">Not checked yet</span>
                        </div>
                        <pre id="heartbeatOutput" style="margin-top:12px;white-space:pre-wrap;overflow:auto;max-height:240px;background:#0f172a;color:#d1d5db;padding:12px;border-radius:8px;">Run a heartbeat to begin…</pre>
                    </div>
                    <div>
                        <h4>Event Probe</h4>
                        <form onsubmit="return runEventProbe(event)" style="display:flex;flex-direction:column;gap:12px;">
                            <label>Event Key<select id="probeEventKey" required>
                                <option value="">Select an event…</option>
                                <?php foreach($events as $ev):?>
                                    <option value="<?=htmlspecialchars($ev['event_key'])?>"><?=htmlspecialchars($ev['event_key'].' — '.$ev['name'])?></option>
                                <?php endforeach?>
                            </select></label>
                            <label>User ID<input type="number" id="probeUserId" min="1" placeholder="123" required></label>
                            <label><input type="checkbox" id="probeDryRun" checked> Dry run (no email sent)</label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button class="btn" type="submit">Run Probe</button>
                                <button class="btn btn-secondary" type="button" onclick="clearProbeOutput()">Clear</button>
                            </div>
                        </form>
                        <pre id="probeOutput" style="margin-top:12px;white-space:pre-wrap;overflow:auto;max-height:280px;background:#0f172a;color:#d1d5db;padding:12px;border-radius:8px;">Awaiting probe…</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TAB: Follow-up Workflows -->
    <div id="tab-workflows" class="tab-content">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">🔄 Email Follow-up Workflows</h3>
                <p style="color:#64748b;margin-bottom:16px">Automated follow-up emails based on recipient behavior. Create triggers for unopened emails, clicks without conversion, and more.</p>
                
                <!-- Workflow Stats Summary -->
                <div id="workflowStats" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px;"></div>
                
                <!-- Create/Edit Workflow Form -->
                <div style="background:#f8fafc;padding:20px;border-radius:10px;margin-bottom:20px;">
                    <h4 style="margin-top:0">Create/Edit Workflow</h4>
                    <form id="workflowForm" onsubmit="return saveWorkflow(event)" style="display:grid;gap:12px;">
                        <input type="hidden" id="workflow_id" value="">
                        
                        <div class="grid grid-2">
                            <label>Workflow Name<input type="text" id="workflow_name" required placeholder="e.g., Unopened - 3 Day Follow-up"></label>
                            <label>Trigger Type<select id="workflow_trigger" required>
                                <option value="">Select trigger...</option>
                                <option value="unopened">Unopened</option>
                                <option value="opened_not_clicked">Opened but Not Clicked</option>
                                <option value="clicked_no_action">Clicked but No Action</option>
                                <option value="clicked_no_sale">Clicked but No Sale</option>
                                <option value="clicked_sale_with_agent">Sale with Agent Follow-up</option>
                                <option value="clicked_sale_no_agent">Sale without Agent Follow-up</option>
                                <option value="clicked_ai_interaction">AI Chat Interaction</option>
                            </select></label>
                        </div>
                        
                        <label>Description<textarea id="workflow_description" rows="2" placeholder="Brief description of this workflow..."></textarea></label>
                        
                        <div class="grid grid-3">
                            <label>Delay (hours)<input type="number" id="workflow_delay" required value="72" min="1" max="720"></label>
                            <label>Scope<select id="workflow_scope" required>
                                <option value="global">Global (All Agents)</option>
                                <option value="agent">Per-Agent</option>
                            </select></label>
                            <label>Priority<input type="number" id="workflow_priority" value="50" min="0" max="100"></label>
                        </div>
                        
                        <div class="grid grid-2">
                            <label>Email Template<select id="workflow_template">
                                <option value="">No template (use AI)</option>
                                <?php foreach($templates as $t):?>
                                    <option value="<?=$t['id']?>"><?=htmlspecialchars($t['name'])?></option>
                                <?php endforeach?>
                            </select></label>
                            <label style="display:flex;align-items:center;gap:8px;margin-top:28px;">
                                <input type="checkbox" id="workflow_ai" checked> Allow AI Generation
                            </label>
                        </div>
                        
                        <div style="display:flex;gap:8px;">
                            <button class="btn" type="submit">Save Workflow</button>
                            <button class="btn btn-secondary" type="button" onclick="clearWorkflowForm()">Clear</button>
                        </div>
                    </form>
                </div>
                
                <!-- Existing Workflows List -->
                <h4>Active Workflows</h4>
                <div id="workflowsLoading" style="text-align:center;padding:40px;color:#64748b;">Loading workflows...</div>
                <div id="workflowsContent" style="display:none;">
                    <table id="workflowsTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Trigger</th>
                                <th>Delay</th>
                                <th>Scope</th>
                                <th>AI</th>
                                <th>Active</th>
                                <th>Performance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="workflowsTableBody"></tbody>
                    </table>
                </div>
                
                <hr style="margin:32px 0">
                
                <!-- Scheduled Follow-ups -->
                <h4>Scheduled Follow-ups</h4>
                <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                    <select id="filterScheduleStatus" onchange="loadScheduledFollowups()" style="max-width:150px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="sent">Sent</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="failed">Failed</option>
                    </select>
                    <button class="btn btn-sm" onclick="loadScheduledFollowups()">Refresh</button>
                </div>
                
                <div id="scheduledLoading" style="text-align:center;padding:40px;color:#64748b;">Loading scheduled follow-ups...</div>
                <div id="scheduledContent" style="display:none;">
                    <table id="scheduledTable">
                        <thead>
                            <tr>
                                <th>Scheduled For</th>
                                <th>Workflow</th>
                                <th>Recipient</th>
                                <th>Parent Email</th>
                                <th>Entity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="scheduledTableBody"></tbody>
                    </table>
                </div>
                
                <hr style="margin:32px 0">
                
                <!-- AI Template Preview -->
                <h4>✨ AI Template Preview & Generator</h4>
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;">Generate and preview email content for workflows before activating them.</p>
                
                <div class="split" style="gap:24px;">
                    <div>
                        <label>Select Workflow
                            <select id="adminPreviewWorkflowSelect" onchange="adminLoadWorkflowForPreview()">
                                <option value="">Select workflow...</option>
                            </select>
                        </label>
                        
                        <label>AI Instructions
                            <textarea id="adminPreviewPrompt" rows="4" placeholder="Example: Write a gentle reminder about our premium products. Keep it friendly and mention our volume discounts."></textarea>
                        </label>
                        
                        <div style="display:flex;gap:8px;">
                            <button class="btn" onclick="adminGeneratePreview()">Generate Preview</button>
                            <button class="btn btn-success" onclick="adminSavePreviewTemplate()" id="adminBtnSavePreview" disabled>Save as Template</button>
                        </div>
                    </div>
                    
                    <div>
                        <div style="border:2px solid #e5e7eb;border-radius:8px;min-height:300px;background:#fff;">
                            <div style="background:#f8fafc;padding:12px;border-bottom:1px solid #e5e7eb;">
                                <strong style="font-size:13px;color:#475569;">Preview:</strong>
                                <div id="adminPreviewSubject" style="margin-top:6px;font-size:15px;font-weight:600;color:#1f2937;">Subject will appear here</div>
                            </div>
                            <div id="adminPreviewBody" style="padding:20px;min-height:250px;color:#64748b;font-size:13px;">
                                Preview content will appear here...
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr style="margin:32px 0">
                
                <!-- Decision Tree Builder -->
                <h4>🌳 Decision Tree Builder</h4>
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;">Add conditional branching to create multi-step workflow sequences.</p>
                
                <div class="grid grid-2">
                    <label>Workflow to Configure
                        <select id="adminDTWorkflowSelect" onchange="adminLoadConditions()">
                            <option value="">Choose workflow...</option>
                        </select>
                    </label>
                    <div style="display:flex;align-items:flex-end;">
                        <button class="btn" onclick="adminShowAddCondition()" id="adminBtnAddCondition" disabled style="width:100%;">+ Add Condition</button>
                    </div>
                </div>
                
                <div id="adminConditionsList" style="margin-top:16px;display:grid;gap:12px;"></div>
                
                <div id="adminDecisionTreeViz" style="margin-top:24px;background:#f8fafc;border:2px dashed #e5e7eb;border-radius:8px;padding:24px;min-height:200px;">
                    <p style="text-align:center;color:#64748b;">Select workflow to view decision tree</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(name){
    const tabs=['templates','events','agents','salesemails','workflows','analytics','diagnostics','heartbeat'];
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
    const idx=tabs.indexOf(name);
    if(idx>-1){
        const tabEl=document.querySelector('.tabs .tab:nth-child('+(idx+1)+')');
        if(tabEl){tabEl.classList.add('active');}
    }
    const target=document.getElementById('tab-'+name);
    if(target){target.classList.add('active');}
    
    // Load data when tab opened
    if(name === 'analytics'){
        loadAnalytics();
    }
    if(name === 'salesemails'){
        loadPerAgentStats();
        loadAgentEmails();
    }
    if(name === 'workflows'){
        loadWorkflows();
        loadWorkflowStats();
        loadScheduledFollowups();
    }
}

function editTemplate(t){
    document.getElementById('tpl_id').value=t.id;
    document.getElementById('tpl_slug').value=t.slug;
    document.getElementById('tpl_name').value=t.name;
    document.getElementById('tpl_subject').value=t.subject;
    document.getElementById('tpl_category').value=t.category||'';
    document.getElementById('tpl_scope').value=t.scope||'platform';
    document.getElementById('tpl_owner').value=t.owner_user_id||'';
    document.getElementById('tpl_html').value=t.html_body||'';
    document.getElementById('tpl_active').checked=parseInt(t.is_active)===1;
    updatePreview();
    window.scrollTo(0,0);
}

function clearForm(){
    document.getElementById('tpl_id').value='';
    document.getElementById('tpl_slug').value='';
    document.getElementById('tpl_name').value='';
    document.getElementById('tpl_subject').value='';
    document.getElementById('tpl_category').value='';
    document.getElementById('tpl_scope').value='platform';
    document.getElementById('tpl_owner').value='';
    document.getElementById('tpl_html').value='';
    document.getElementById('tpl_active').checked=true;
    updatePreview();
}

function toggleKeyVisibility(){
    const input=document.getElementById('sendgridKeyInput');
    const btn=document.getElementById('toggleKeyBtn');
    if(input.type==='password'){
        input.type='text';
        btn.textContent='Hide';
    }else{
        input.type='password';
        btn.textContent='Show';
    }
}

async function saveSendgridKey(){
    const input=document.getElementById('sendgridKeyInput');
    const status=document.getElementById('sendgridKeyStatus');
    status.style.display='block';
    status.textContent='Saving key...';
    try{
        const payload={api_key:input.value};
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=set_sendgrid_key')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const j=await r.json();
        if(j.ok){
            status.style.color='#15803d';
            status.textContent='SendGrid key saved.';
            input.value='';
        }else{
            status.style.color='#b91c1c';
            status.textContent='Error: '+(j.error||'Failed to save key');
        }
    }catch(e){
        status.style.color='#b91c1c';
        status.textContent='Error: '+e.message;
    }
}

function insertVar(){
    const sel=document.getElementById('varPicker');
    if(!sel.value)return;
    const ta=document.getElementById('tpl_html');
    ta.value+=sel.value;
    sel.value='';
    updatePreview();
}

function updatePreview(){
    try{
        const html=document.getElementById('tpl_html').value;
        const iframe=document.getElementById('preview');
        const doc=iframe.contentDocument||iframe.contentWindow.document;
        doc.open();doc.write(html);doc.close();
    }catch(e){}
}

async function syncFromDisk(){
    if(!confirm('Sync HTML templates from admin/emails directory?'))return;
    try{
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=sync_from_disk')?>',{method:'POST',credentials:'same-origin'});
        const j=await r.json();
        alert('Synced: '+j.inserted+' inserted, '+j.updated+' updated'+(j.errors?.length?('\nErrors: '+j.errors.join('\n')):''));
        location.reload();
    }catch(e){alert('Sync error: '+e.message)}
}

async function toggleEvent(eventKey,enable){
    try{
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=toggle_event')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({event_key:eventKey,enable})});
        const j=await r.json();
        if(j.ok){location.reload()}else{alert('Error: '+j.error)}
    }catch(e){alert('Error: '+e.message)}
}

async function assignTemplate(eventKey,templateId){
    try{
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=assign_template')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({event_key:eventKey,template_id:parseInt(templateId)||null})});
        const j=await r.json();
        if(j.ok){location.reload()}else{alert('Error: '+j.error)}
    }catch(e){alert('Error: '+e.message)}
}

async function runDiagnostics(){
    const to=document.getElementById('diag_to').value.trim();
    const out=document.getElementById('diagOutput');
    out.textContent='Sending test...';
    try{
        const payload={to:to||'sample-007@example.com',from:'sample-006@example.com',from_name:'Product',subject:'Product Diagnostics',html:'<p>Test email from Email Automation V2</p>',text:'Test email'};
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=sendgrid_test')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
        const text=await r.text();
        let json=null;
        try{ json=JSON.parse(text);}catch(parseErr){ out.textContent=text; return; }
        out.textContent=JSON.stringify(json,null,2);
    }catch(e){out.textContent='Error: '+e.message}
}

async function runDebugDiagnostics(){
    const to=document.getElementById('diag_to').value.trim();
    const out=document.getElementById('diagOutput');
    out.textContent='[DEBUG MODE] Sending test with verbose logging...';
    try{
        const payload={to:to||'sample-007@example.com',from:'sample-006@example.com',from_name:'Product',subject:'Debug Test',html:'<p>Debug test from Email Automation V2</p>',text:'Debug test',sandbox:true};
        const r=await fetch('<?=outbound_path('/api/email-admin-api-debug.php?action=sendgrid_test')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
        const requestId=r.headers.get('X-Debug-Request-ID')||'n/a';
        const text=await r.text();
        let json=null;
        try{ json=JSON.parse(text);}catch(parseErr){ out.textContent='[DEBUG MODE] request_id: '+requestId+'\n\nNon-JSON response:\n'+text; return; }
        out.textContent='[DEBUG MODE] request_id: '+requestId+'\n\nCheck required/logs/email-api-debug.log for full trace\n\n'+JSON.stringify(json,null,2);
    }catch(e){out.textContent='[DEBUG MODE] Error: '+e.message}
}

async function fetchSendgridStatus(){
    const label=document.getElementById('sendgridStatusLabel');
    const dump=document.getElementById('sendgridStatusDump');
    try{
        label.textContent='Checking…';
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=get_sendgrid_status')?>',{credentials:'same-origin',headers:{'Accept':'application/json'}});
        const requestId=r.headers.get('X-Debug-Request-ID');
        const text=await r.text();
        let j=null;
        try{ j=JSON.parse(text);}catch(parseErr){dump.style.display='block';dump.textContent=text;label.textContent='Status: non-JSON response';return;}
        dump.style.display='block';
        dump.textContent=JSON.stringify(j,null,2);
        const rid=j.request_id||requestId||'n/a';
        if(!j.ok){label.textContent='Status error (request '+rid+')';return;}
        label.textContent=(j.has_key?('API key present ('+(j.key_snippet||'hidden')+')'):'No API key configured')+' • req '+rid;
        if(j.ping && j.ping.ok){label.textContent+=' • Sandbox OK';}
        else if(j.ping && j.ping.error){label.textContent+=' • Sandbox error: '+j.ping.error;}
    }catch(e){label.textContent='Status request failed';dump.style.display='block';dump.textContent=String(e);}
}

async function runSandboxProbe(){
    const out=document.getElementById('heartbeatOutput');
    const status=document.getElementById('heartbeatStatus');
    status.textContent='Running sandbox heartbeat…';
    out.textContent='Working…';
    try{
        const payload={to:'sample-006@example.com',from:'sample-006@example.com',subject:'SendGrid Sandbox Heartbeat',html:'<p>Sandbox heartbeat at '+new Date().toISOString()+'</p>',text:'Sandbox heartbeat',sandbox:true};
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=sendgrid_test')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
        const requestId=r.headers.get('X-Debug-Request-ID');
        const text=await r.text();
        let json=null;
        try{ json=JSON.parse(text);}catch(parseErr){ status.textContent='Sandbox response not JSON'; out.textContent=text; return; }
        const rid=json.request_id||requestId||'n/a';
        if(!r.ok || !json.ok){ status.textContent='Sandbox failed ❌ (req '+rid+')'; out.textContent=JSON.stringify(json,null,2); return; }
        status.textContent='Sandbox transport healthy ✅ (req '+rid+')';
        out.textContent=JSON.stringify(json,null,2);
    }catch(e){status.textContent='Sandbox error';out.textContent='Error: '+e.message;}
}

async function runLiveProbe(){
    const out=document.getElementById('heartbeatOutput');
    const status=document.getElementById('heartbeatStatus');
    const to=prompt('Send live email to:','<?=htmlspecialchars($user['email']??'')?>');
    if(!to){return;}
    status.textContent='Sending live email…';
    out.textContent='Working…';
    try{
        const payload={to:to.trim(),from:'sample-006@example.com',subject:'Product Live Test '+new Date().toISOString(),html:'<p>This is a live SendGrid test from Email Automation V2.</p>',text:'Live SendGrid test',sandbox:false};
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=sendgrid_test')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
        const requestId=r.headers.get('X-Debug-Request-ID');
        const text=await r.text();
        let json=null;
        try{ json=JSON.parse(text);}catch(parseErr){ status.textContent='Live response not JSON'; out.textContent=text; return; }
        const rid=json.request_id||requestId||'n/a';
        if(!r.ok || !json.ok){ status.textContent='Live send failed ❌ (req '+rid+')'; out.textContent=JSON.stringify(json,null,2); return; }
        status.textContent='Live email sent ✅ (req '+rid+')';
        out.textContent=JSON.stringify(json,null,2);
    }catch(e){status.textContent='Live send error';out.textContent='Error: '+e.message;}
}

async function runEventProbe(ev){
    ev.preventDefault();
    const eventKey=document.getElementById('probeEventKey').value;
    const userId=parseInt(document.getElementById('probeUserId').value,10);
    const dryRun=document.getElementById('probeDryRun').checked;
    const out=document.getElementById('probeOutput');
    if(!eventKey||!userId){out.textContent='Please provide event key and user id.';return false;}
    out.textContent='Probing…';
    try{
        const payload={event_key:eventKey,user_id:userId,dry_run:dryRun};
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=event_probe')?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
        const requestId=r.headers.get('X-Debug-Request-ID');
        const text=await r.text();
        let json=null;
        try{ json=JSON.parse(text);}catch(parseErr){ out.textContent=text; return; }
        if(!r.ok || !json.ok){
            out.textContent=JSON.stringify(json,null,2);
            return;
        }
        json.request_id=json.request_id||requestId||'n/a';
        out.textContent=JSON.stringify(json,null,2);
    }catch(e){out.textContent='Probe error: '+e.message;}
    return false;
}

function clearProbeOutput(){
    const out=document.getElementById('probeOutput');
    if(out){out.textContent='Awaiting probe…';}
}

async function loadPerAgentStats(){
    try{
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=get_per_agent_stats')?>',{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok)return;
        
        const container=document.getElementById('perAgentStats');
        const agentFilter=document.getElementById('filterAgentEmailAgent');
        
        container.innerHTML='';
        agentFilter.innerHTML='<option value="">All Agents</option>';
        
        json.agents.forEach(agent=>{
            const card=document.createElement('div');
            card.style.cssText='background:#fff;padding:16px;border-radius:10px;border:1px solid #e5e7eb;';
            card.innerHTML=`
                <div style="font-weight:600;color:#1f2937;margin-bottom:8px;">${agent.agent_name}</div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;font-size:13px;">
                    <div><span style="color:#0369a1;font-weight:700;">${agent.total_sent}</span> Sent</div>
                    <div><span style="color:#10b981;font-weight:700;">${agent.opened}</span> Opened</div>
                    <div><span style="color:#f59e0b;font-weight:700;">${agent.total_clicks}</span> Clicks</div>
                    <div><span style="color:#ef4444;font-weight:700;">${agent.bounced}</span> Bounced</div>
                </div>
                <div style="margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;font-size:12px;color:#64748b;">
                    Open Rate: <strong>${agent.open_rate}%</strong> | Click Rate: <strong>${agent.click_rate}%</strong>
                </div>
            `;
            container.appendChild(card);
            
            const opt=document.createElement('option');
            opt.value=agent.agent_id;
            opt.textContent=agent.agent_name;
            agentFilter.appendChild(opt);
        });
        
    }catch(e){
        console.error('Failed to load per-agent stats:',e);
    }
}

async function loadAgentEmails(){
    const loading=document.getElementById('agentEmailsLoading');
    const content=document.getElementById('agentEmailsContent');
    const tbody=document.getElementById('agentEmailsTableBody');
    
    try{
        loading.style.display='block';
        content.style.display='none';
        
        const agent=document.getElementById('filterAgentEmailAgent').value;
        const status=document.getElementById('filterAgentEmailStatus').value;
        const aiAssisted=document.getElementById('filterAiAssisted').value;
        const reviewed=document.getElementById('filterReviewed').value;
        
        let url='<?=outbound_path('/api/email-admin-api.php?action=get_agent_emails')?>';
        const params=[];
        if(agent)params.push('agent='+encodeURIComponent(agent));
        if(status)params.push('status='+encodeURIComponent(status));
        if(aiAssisted)params.push('ai_assisted='+encodeURIComponent(aiAssisted));
        if(reviewed)params.push('reviewed='+encodeURIComponent(reviewed));
        if(params.length)url+='&'+params.join('&');
        
        const r=await fetch(url,{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok){
            loading.textContent='Error loading emails: '+(json.error||'Unknown error');
            return;
        }
        
        tbody.innerHTML='';
        
        if(!json.emails || json.emails.length===0){
            tbody.innerHTML='<tr><td colspan="9" style="text-align:center;padding:40px;color:#64748b;">No agent emails found</td></tr>';
        }else{
            json.emails.forEach(email=>{
                const tr=document.createElement('tr');
                const aiBadge=email.is_ai_assisted?'<span class="badge" style="background:#dbeafe;color:#1e40af;">AI</span>':'';
                const reviewBadge=email.admin_reviewed?'<span class="badge" style="background:#dcfce7;color:#166534;">✓ Reviewed</span>':'<span class="badge status-pending">Needs Review</span>';
                const engagement=`<div style="font-size:12px;"><span title="Opens">${email.open_count||0} 👁️</span> <span title="Clicks">${email.click_count||0} 🖱️</span></div>`;
                
                let statusBadge=email.status;
                if(email.status==='sent' && email.open_count>0){
                    statusBadge='<span class="badge" style="background:#dcfce7;color:#166534;">✅ OPENED</span>';
                }else if(email.status==='bounced'){
                    statusBadge='<span class="badge" style="background:#fee2e2;color:#991b1b;">❌ BOUNCED</span>';
                }else{
                    statusBadge='<span class="badge status-'+email.status+'">'+email.status.toUpperCase()+'</span>';
                }
                
                tr.innerHTML=`
                    <td style="font-size:0.85rem;">${email.created_at?new Date(email.created_at).toLocaleString().slice(0,16):'—'}</td>
                    <td><strong>${email.agent_name||'Agent #'+email.agent_id}</strong></td>
                    <td>${email.recipient_email}</td>
                    <td style="max-width:280px;">${email.subject||'(no subject)'}</td>
                    <td>${statusBadge}</td>
                    <td>${aiBadge}</td>
                    <td>${engagement}</td>
                    <td>${reviewBadge}</td>
                    <td style="white-space:nowrap;">
                        <button class="btn-small" onclick="viewAgentEmail(${email.id})">View</button>
                        ${!email.admin_reviewed?'<button class="btn-small success" onclick="markEmailReviewed('+email.id+')">Review</button>':''}
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        loading.style.display='none';
        content.style.display='block';
        
    }catch(e){
        loading.textContent='Error loading emails: '+e.message;
    }
}

async function viewAgentEmail(emailId){
    try{
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=get_agent_email_detail')?>&id='+emailId,{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok){
            alert('Error loading email: '+(json.error||'Unknown'));
            return;
        }
        
        const email=json.email;
        const modal=document.createElement('div');
        modal.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML=`
            <div style="background:#fff;border-radius:16px;max-width:800px;width:92%;max-height:90vh;overflow:auto;">
                <div style="background:linear-gradient(135deg,#0ea5e9,#0369a1);color:#fff;padding:24px;border-radius:16px 16px 0 0;">
                    <h3 style="margin:0;font-size:22px;">Email Details</h3>
                    <div style="margin-top:8px;font-size:13px;opacity:0.9;">
                        From: <strong>${email.agent_name||'Agent #'+email.agent_id}</strong> • 
                        To: <strong>${email.recipient_email}</strong>
                    </div>
                </div>
                <div style="padding:24px;">
                    <div style="margin-bottom:20px;">
                        <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Subject:</div>
                        <div style="font-size:16px;font-weight:600;color:#1f2937;">${email.subject}</div>
                    </div>
                    
                    <div style="margin-bottom:20px;">
                        <div style="font-size:13px;color:#64748b;margin-bottom:8px;">Status & Engagement:</div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <span class="badge status-${email.status}">${email.status.toUpperCase()}</span>
                            ${email.is_ai_assisted?'<span class="badge" style="background:#dbeafe;color:#1e40af;">AI Assisted</span>':''}
                            ${email.sent_at?'<span class="badge">Sent: '+new Date(email.sent_at).toLocaleString()+'</span>':''}
                            <span class="badge">${email.open_count||0} Opens</span>
                            <span class="badge">${email.click_count||0} Clicks</span>
                        </div>
                    </div>
                    
                    <div style="margin-bottom:20px;">
                        <div style="font-size:13px;color:#64748b;margin-bottom:8px;">Email Content:</div>
                        <div style="border:2px solid #e5e7eb;border-radius:8px;padding:20px;background:#f9fafb;max-height:400px;overflow:auto;">
                            ${email.body_html}
                        </div>
                    </div>
                    
                    <div style="display:flex;gap:12px;justify-content:flex-end;">
                        ${!email.admin_reviewed?'<button class="btn btn-success" onclick="markEmailReviewed('+email.id+');this.closest(\'div[style*=fixed]\').remove();">Mark Reviewed</button>':''}
                        <button class="btn btn-secondary" onclick="this.closest(\'div[style*=fixed]\').remove();">Close</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.addEventListener('click',e=>{if(e.target===modal)modal.remove();});
        
    }catch(e){
        alert('Error viewing email: '+e.message);
    }
}

async function markEmailReviewed(emailId){
    try{
        const r=await fetch('<?=outbound_path('/api/email-admin-api.php?action=mark_email_reviewed')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({email_id:emailId})
        });
        const json=await r.json();
        
        if(!json.ok){
            alert('Error: '+(json.error||'Failed'));
            return;
        }
        
        loadAgentEmails();
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

async function loadAnalytics(){
    const loading=document.getElementById('analytics-loading');
    const content=document.getElementById('analytics-content');
    
    try{
        loading.style.display='block';
        content.style.display='none';
        
        const r=await fetch('<?=outbound_path('/api/email/analytics.php')?>',{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok){
            loading.textContent='Error loading analytics: '+(json.error||'Unknown error');
            return;
        }
        
        // Update overall stats
        document.getElementById('stat-total-sent').textContent=json.overall.total_sent.toLocaleString();
        document.getElementById('stat-delivery-rate').textContent=(json.overall.delivery_rate||0)+'%';
        document.getElementById('stat-open-rate').textContent=(json.overall.open_rate||0)+'%';
        
        // By event table
        const eventTable=document.getElementById('analytics-by-event').querySelector('tbody');
        eventTable.innerHTML='';
        json.by_event.forEach(row=>{
            const tr=document.createElement('tr');
            tr.innerHTML=`
                <td><span class="badge">${row.event_key||'—'}</span></td>
                <td>${row.sent.toLocaleString()}</td>
                <td>${row.delivered.toLocaleString()}</td>
                <td>${row.opened.toLocaleString()}</td>
                <td>${row.clicked.toLocaleString()}</td>
                <td>${row.bounced.toLocaleString()}</td>
                <td><strong>${row.open_rate}%</strong></td>
                <td><strong>${row.click_rate}%</strong></td>
            `;
            eventTable.appendChild(tr);
        });
        
        // By template table
        const templateTable=document.getElementById('analytics-by-template').querySelector('tbody');
        templateTable.innerHTML='';
        json.by_template.forEach(row=>{
            const tr=document.createElement('tr');
            tr.innerHTML=`
                <td>${row.template_name||'Template #'+row.template_id}</td>
                <td>${row.sent.toLocaleString()}</td>
                <td>${row.delivered.toLocaleString()}</td>
                <td>${row.opened.toLocaleString()}</td>
                <td>${row.clicked.toLocaleString()}</td>
                <td><strong>${row.open_rate}%</strong></td>
                <td><strong>${row.click_rate}%</strong></td>
            `;
            templateTable.appendChild(tr);
        });
        
        // Recent emails table
        const recentTable=document.getElementById('recent-emails').querySelector('tbody');
        recentTable.innerHTML='';
        json.recent.forEach(email=>{
            const tr=document.createElement('tr');
            const statusClass='status-'+email.status;
            tr.innerHTML=`
                <td>${email.recipient_email}</td>
                <td style="max-width:300px;">${email.subject.substring(0,60)}</td>
                <td><span class="badge">${email.event_key||'—'}</span></td>
                <td><span class="badge ${statusClass}">${email.status.toUpperCase()}</span></td>
                <td style="font-size:0.85rem;">${email.sent_at?new Date(email.sent_at).toLocaleString():'—'}</td>
                <td>${email.open_count}</td>
                <td>${email.click_count}</td>
            `;
            recentTable.appendChild(tr);
        });
        
        loading.style.display='none';
        content.style.display='block';
        
    }catch(e){
        loading.textContent='Error loading analytics: '+e.message;
    }
}

// ============================================================================
// Workflow Management Functions
// ============================================================================

async function loadWorkflows(){
    const loading=document.getElementById('workflowsLoading');
    const content=document.getElementById('workflowsContent');
    const tbody=document.getElementById('workflowsTableBody');
    
    try{
        loading.style.display='block';
        content.style.display='none';
        
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>',{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok){
            loading.textContent='Error loading workflows: '+(json.error||'Unknown error');
            return;
        }
        
        tbody.innerHTML='';
        
        if(!json.workflows || json.workflows.length===0){
            tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:40px;color:#64748b;">No workflows configured yet</td></tr>';
        }else{
            json.workflows.forEach(w=>{
                const tr=document.createElement('tr');
                const scopeBadge=w.scope==='global'?'<span class="badge badge-platform">Global</span>':'<span class="badge badge-agent">Agent</span>';
                const aiBadge=w.allow_ai_generation?'<span class="badge" style="background:#dbeafe;color:#1e40af;">✓ AI</span>':'—';
                const activeBadge=w.is_active?'<span style="color:#16a34a;">✓ Active</span>':'<span style="color:#94a3b8;">Inactive</span>';
                
                tr.innerHTML=`
                    <td><strong>${w.name}</strong></td>
                    <td><span class="badge">${w.trigger_type.replace(/_/g,' ')}</span></td>
                    <td>${w.trigger_delay_hours}h</td>
                    <td>${scopeBadge}</td>
                    <td>${aiBadge}</td>
                    <td>${activeBadge}</td>
                    <td><button class="btn btn-sm" onclick="viewWorkflowPerformance(${w.id})">View Stats</button></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm" onclick="editWorkflow(${w.id})">Edit</button>
                        <button class="btn btn-sm ${w.is_active?'btn-danger':'btn-success'}" onclick="toggleWorkflow(${w.id},${w.is_active?0:1})">${w.is_active?'Disable':'Enable'}</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteWorkflow(${w.id})">Delete</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        loading.style.display='none';
        content.style.display='block';
        
    }catch(e){
        loading.textContent='Error loading workflows: '+e.message;
    }
}

async function loadWorkflowStats(){
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow_analytics')?>',{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok)return;
        
        const container=document.getElementById('workflowStats');
        container.innerHTML='';
        
        // Overall stats
        let totalScheduled=0,totalSent=0,totalOpened=0,totalConverted=0;
        json.analytics.forEach(w=>{
            totalScheduled+=parseInt(w.total_scheduled||0);
            totalSent+=parseInt(w.sent||0);
            totalOpened+=parseInt(w.opened||0);
            totalConverted+=parseInt(w.converted||0);
        });
        
        const statCard=(label,value,color)=>{
            return `<div style="background:#fff;padding:16px;border-radius:10px;border:1px solid #e5e7eb;">
                <div style="font-size:2rem;font-weight:700;color:${color};">${value}</div>
                <div style="font-size:0.85rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">${label}</div>
            </div>`;
        };
        
        container.innerHTML=statCard('Total Scheduled',totalScheduled,'#0369a1')+
                           statCard('Sent',totalSent,'#10b981')+
                           statCard('Opened',totalOpened,'#f59e0b')+
                           statCard('Converted',totalConverted,'#8b5cf6');
        
    }catch(e){
        console.error('Failed to load workflow stats:',e);
    }
}

async function loadScheduledFollowups(){
    const loading=document.getElementById('scheduledLoading');
    const content=document.getElementById('scheduledContent');
    const tbody=document.getElementById('scheduledTableBody');
    
    try{
        loading.style.display='block';
        content.style.display='none';
        
        const status=document.getElementById('filterScheduleStatus').value;
        let url='<?=outbound_path('/api/email-workflow-api.php?action=get_scheduled_followups')?>';
        if(status)url+='&status='+encodeURIComponent(status);
        
        const r=await fetch(url,{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok){
            loading.textContent='Error loading scheduled follow-ups: '+(json.error||'Unknown');
            return;
        }
        
        tbody.innerHTML='';
        
        if(!json.followups || json.followups.length===0){
            tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748b;">No scheduled follow-ups</td></tr>';
        }else{
            json.followups.forEach(f=>{
                const tr=document.createElement('tr');
                const statusClass='status-'+f.status;
                const statusBadge=`<span class="badge ${statusClass}">${f.status.toUpperCase()}</span>`;
                const scheduledDate=new Date(f.scheduled_for).toLocaleString().slice(0,16);
                
                tr.innerHTML=`
                    <td>${scheduledDate}</td>
                    <td>${f.workflow_name}</td>
                    <td>${f.recipient_email}</td>
                    <td>${f.parent_subject||'—'}</td>
                    <td>${f.entity_type} #${f.entity_id}</td>
                    <td>${statusBadge}</td>
                    <td>${f.status==='pending'?'<button class="btn btn-sm btn-danger" onclick="cancelFollowup('+f.id+')">Cancel</button>':'—'}</td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        loading.style.display='none';
        content.style.display='block';
        
    }catch(e){
        loading.textContent='Error loading scheduled follow-ups: '+e.message;
    }
}

async function saveWorkflow(event){
    event.preventDefault();
    
    const workflowId=document.getElementById('workflow_id').value;
    const payload={
        name:document.getElementById('workflow_name').value,
        description:document.getElementById('workflow_description').value,
        trigger_type:document.getElementById('workflow_trigger').value,
        trigger_delay_hours:parseInt(document.getElementById('workflow_delay').value),
        scope:document.getElementById('workflow_scope').value,
        template_id:parseInt(document.getElementById('workflow_template').value)||null,
        allow_ai_generation:document.getElementById('workflow_ai').checked?1:0,
        priority:parseInt(document.getElementById('workflow_priority').value)
    };
    
    if(workflowId){
        payload.id=parseInt(workflowId);
    }
    
    try{
        const action=workflowId?'update_workflow':'create_workflow';
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=')?>'+ action,{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(payload)
        });
        const json=await r.json();
        
        if(!json.ok){
            alert('Error: '+(json.error||'Failed to save workflow'));
            return false;
        }
        
        alert('Workflow saved successfully!');
        clearWorkflowForm();
        loadWorkflows();
        loadWorkflowStats();
        
    }catch(e){
        alert('Error: '+e.message);
    }
    
    return false;
}

async function editWorkflow(workflowId){
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow&id=')?>'+ workflowId,{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok||!json.workflow){
            alert('Error loading workflow');
            return;
        }
        
        const w=json.workflow;
        document.getElementById('workflow_id').value=w.id;
        document.getElementById('workflow_name').value=w.name;
        document.getElementById('workflow_description').value=w.description||'';
        document.getElementById('workflow_trigger').value=w.trigger_type;
        document.getElementById('workflow_delay').value=w.trigger_delay_hours;
        document.getElementById('workflow_scope').value=w.scope;
        document.getElementById('workflow_template').value=w.template_id||'';
        document.getElementById('workflow_ai').checked=!!w.allow_ai_generation;
        document.getElementById('workflow_priority').value=w.priority;
        
        // Scroll to form
        document.getElementById('workflowForm').scrollIntoView({behavior:'smooth'});
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

function clearWorkflowForm(){
    document.getElementById('workflow_id').value='';
    document.getElementById('workflow_name').value='';
    document.getElementById('workflow_description').value='';
    document.getElementById('workflow_trigger').value='';
    document.getElementById('workflow_delay').value='72';
    document.getElementById('workflow_scope').value='global';
    document.getElementById('workflow_template').value='';
    document.getElementById('workflow_ai').checked=true;
    document.getElementById('workflow_priority').value='50';
}

async function toggleWorkflow(workflowId,enable){
    if(!confirm('Are you sure you want to '+(enable?'enable':'disable')+' this workflow?'))return;
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=update_workflow')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:workflowId,is_active:enable})
        });
        const json=await r.json();
        
        if(!json.ok){
            alert('Error: '+(json.error||'Failed'));
            return;
        }
        
        loadWorkflows();
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

async function deleteWorkflow(workflowId){
    if(!confirm('Are you sure you want to delete this workflow? This cannot be undone.'))return;
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=delete_workflow')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:workflowId})
        });
        const json=await r.json();
        
        if(!json.ok){
            alert('Error: '+(json.error||'Failed'));
            return;
        }
        
        alert('Workflow deleted');
        loadWorkflows();
        loadWorkflowStats();
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

async function cancelFollowup(followupId){
    const reason=prompt('Reason for cancellation (optional):','No longer needed');
    if(reason===null)return;
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=cancel_followup')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:followupId,reason:reason||'Cancelled by admin'})
        });
        const json=await r.json();
        
        if(!json.ok){
            alert('Error: '+(json.error||'Failed'));
            return;
        }
        
        alert('Follow-up cancelled');
        loadScheduledFollowups();
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

async function viewWorkflowPerformance(workflowId){
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflow_analytics&workflow_id=')?>'+ workflowId,{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok||!json.analytics||json.analytics.length===0){
            alert('No performance data available yet');
            return;
        }
        
        const w=json.analytics[0];
        const msg=`Performance for: ${w.workflow_name}\n\n`+
                  `Total Scheduled: ${w.total_scheduled}\n`+
                  `Sent: ${w.sent}\n`+
                  `Failed: ${w.failed}\n`+
                  `Cancelled: ${w.cancelled}\n\n`+
                  `Opened: ${w.opened} (${w.open_rate}%)\n`+
                  `Clicked: ${w.clicked} (${w.click_rate}%)\n`+
                  `Converted: ${w.converted} (${w.conversion_rate}%)`;
        
        alert(msg);
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

// ============================================================================
// Admin AI Preview Functions
// ============================================================================

let adminCurrentPreviewGen=null;
let adminCurrentPreviewWorkflowId=null;

async function adminLoadWorkflowForPreview(){
    const select=document.getElementById('adminPreviewWorkflowSelect');
    const workflowId=parseInt(select.value);
    
    if(!workflowId){
        adminCurrentPreviewWorkflowId=null;
        document.getElementById('adminBtnSavePreview').disabled=true;
        return;
    }
    
    adminCurrentPreviewWorkflowId=workflowId;
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=get_workflow_template&workflow_id=')?>'+ workflowId,{credentials:'same-origin'});
        const json=await r.json();
        
        if(json.ok&&json.template){
            document.getElementById('adminPreviewSubject').textContent=json.template.subject_template||'No subject';
            document.getElementById('adminPreviewBody').innerHTML=json.template.body_template||'<p style="color:#64748b;">No template saved yet</p>';
            
            if(json.template.ai_prompt_used){
                document.getElementById('adminPreviewPrompt').value=json.template.ai_prompt_used;
            }
        }
    }catch(e){
        console.error('Load template error:',e);
    }
}

async function adminGeneratePreview(){
    const workflowId=adminCurrentPreviewWorkflowId;
    const prompt=document.getElementById('adminPreviewPrompt').value.trim();
    
    if(!workflowId){
        alert('Please select a workflow first');
        return;
    }
    
    if(!prompt){
        alert('Please provide AI instructions');
        return;
    }
    
    const select=document.getElementById('adminPreviewWorkflowSelect');
    const selectedOption=select.options[select.selectedIndex];
    const triggerType=selectedOption.dataset.trigger||'unopened';
    
    document.getElementById('adminPreviewBody').innerHTML='<div style="text-align:center;padding:40px;"><div style="width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div><p style="margin-top:12px;color:#64748b;">Generating...</p></div>';
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=preview_ai_template')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                workflow_id:workflowId,
                trigger_type:triggerType,
                prompt:prompt
            })
        });
        
        const json=await r.json();
        
        if(!json.ok){
            throw new Error(json.error||'Generation failed');
        }
        
        document.getElementById('adminPreviewSubject').textContent=json.subject;
        document.getElementById('adminPreviewBody').innerHTML=json.body;
        document.getElementById('adminBtnSavePreview').disabled=false;
        
        adminCurrentPreviewGen=json;
        
    }catch(e){
        alert('Generation failed: '+e.message);
        document.getElementById('adminPreviewBody').innerHTML='<p style="color:#dc2626;">Error: '+e.message+'</p>';
    }
}

async function adminSavePreviewTemplate(){
    if(!adminCurrentPreviewGen||!adminCurrentPreviewWorkflowId){
        alert('No preview to save');
        return;
    }
    
    if(!confirm('Save this as the template for this workflow?')){
        return;
    }
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=save_workflow_template')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                workflow_id:adminCurrentPreviewWorkflowId,
                subject:adminCurrentPreviewGen.subject,
                body:adminCurrentPreviewGen.body,
                generation_id:adminCurrentPreviewGen.generation_id
            })
        });
        
        const json=await r.json();
        
        if(!json.ok){
            throw new Error(json.error||'Save failed');
        }
        
        alert('✅ Template saved! This workflow will use this content.');
        
    }catch(e){
        alert('Save failed: '+e.message);
    }
}

// ============================================================================
// Admin Decision Tree Functions
// ============================================================================

async function adminLoadConditions(){
    const workflowId=parseInt(document.getElementById('adminDTWorkflowSelect').value);
    
    if(!workflowId){
        document.getElementById('adminBtnAddCondition').disabled=true;
        document.getElementById('adminConditionsList').innerHTML='';
        document.getElementById('adminDecisionTreeViz').innerHTML='<p style="text-align:center;color:#64748b;">Select workflow to view decision tree</p>';
        return;
    }
    
    document.getElementById('adminBtnAddCondition').disabled=false;
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=get_conditions&workflow_id=')?>'+ workflowId,{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok){
            throw new Error(json.error||'Failed');
        }
        
        adminRenderConditions(json.conditions||[]);
        adminRenderDecisionTree(json.conditions||[]);
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

function adminRenderConditions(conditions){
    const container=document.getElementById('adminConditionsList');
    
    if(conditions.length===0){
        container.innerHTML='<div style="text-align:center;padding:24px;color:#64748b;border:2px dashed #e5e7eb;border-radius:8px;">No conditions. Add conditions to create decision tree logic.</div>';
        return;
    }
    
    let html='';
    conditions.forEach(c=>{
        html+=`
            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                    <div>
                        <strong style="font-size:14px;">${c.condition_type.replace(/_/g,' ')} ${c.operator} "${c.condition_value||'any'}"</strong>
                        <div style="font-size:11px;color:#64748b;">Priority: ${c.priority}</div>
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="adminDeleteCondition(${c.id})">Delete</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding-top:12px;border-top:1px solid #e5e7eb;">
                    <div style="background:#dcfce7;padding:10px;border-radius:6px;">
                        <div style="font-size:11px;color:#166534;font-weight:600;">✓ TRUE: ${c.next_true_name||'End'}</div>
                    </div>
                    <div style="background:#fee2e2;padding:10px;border-radius:6px;">
                        <div style="font-size:11px;color:#991b1b;font-weight:600;">✗ FALSE: ${c.next_false_name||'End'}</div>
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML=html;
}

function adminRenderDecisionTree(conditions){
    const viz=document.getElementById('adminDecisionTreeViz');
    
    if(conditions.length===0){
        viz.innerHTML='<p style="text-align:center;color:#64748b;">No decision branches</p>';
        return;
    }
    
    let tree='<div style="font-family:monospace;font-size:12px;line-height:2;">';
    tree+='<div style="font-weight:600;margin-bottom:12px;color:#1f2937;">📧 Decision Flow:</div>';
    
    conditions.forEach((c,idx)=>{
        tree+=`
            <div style="margin-left:${idx*20}px;padding:8px;background:#f8fafc;border-left:3px solid #0369a1;margin-bottom:8px;">
                <div style="color:#0369a1;font-weight:600;">${c.condition_type}</div>
                <div style="margin-left:20px;">
                    <div style="color:#16a34a;">✓ ${c.operator} "${c.condition_value}" → ${c.next_true_name||'End'}</div>
                    <div style="color:#dc2626;">✗ Otherwise → ${c.next_false_name||'End'}</div>
                </div>
            </div>
        `;
    });
    
    tree+='</div>';
    viz.innerHTML=tree;
}

function adminShowAddCondition(){
    const workflowId=parseInt(document.getElementById('adminDTWorkflowSelect').value);
    
    if(!workflowId){
        alert('Select workflow first');
        return;
    }
    
    const type=prompt('Condition type:\n1. email_opened\n2. email_clicked\n3. conversion_type\n4. conversion_value\n5. time_elapsed\nEnter number:','1');
    if(!type)return;
    
    const typeMap={'1':'email_opened','2':'email_clicked','3':'conversion_type','4':'conversion_value','5':'time_elapsed'};
    const selectedType=typeMap[type]||'email_opened';
    
    const operator=prompt('Operator:\n1. equals\n2. not_equals\n3. greater_than\n4. less_than\nEnter number:','1');
    const opMap={'1':'equals','2':'not_equals','3':'greater_than','4':'less_than'};
    const selectedOp=opMap[operator]||'equals';
    
    const value=prompt('Value (e.g., "true", "500", "order_complete"):','');
    const nextTrue=prompt('Next workflow ID if TRUE (0 for none):','0');
    const nextFalse=prompt('Next workflow ID if FALSE (0 for none):','0');
    
    adminAddCondition(workflowId,selectedType,selectedOp,value,parseInt(nextTrue)||null,parseInt(nextFalse)||null);
}

async function adminAddCondition(workflowId,conditionType,operator,value,nextTrue,nextFalse){
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=add_condition')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                workflow_id:workflowId,
                condition_type:conditionType,
                operator:operator,
                condition_value:value,
                next_workflow_id_true:nextTrue,
                next_workflow_id_false:nextFalse
            })
        });
        
        const json=await r.json();
        
        if(!json.ok){
            throw new Error(json.error||'Failed');
        }
        
        alert('✅ Condition added!');
        adminLoadConditions();
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

async function adminDeleteCondition(conditionId){
    if(!confirm('Delete this condition?'))return;
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-builder-api.php?action=delete_condition')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({id:conditionId})
        });
        
        const json=await r.json();
        
        if(!json.ok){
            throw new Error(json.error||'Failed');
        }
        
        adminLoadConditions();
        
    }catch(e){
        alert('Error: '+e.message);
    }
}

// Load workflows for admin preview on tab switch
if(document.getElementById('tab-workflows')){
    const observer=new MutationObserver((mutations)=>{
        mutations.forEach((mutation)=>{
            if(mutation.target.classList.contains('active')&&mutation.target.id==='tab-workflows'){
                setTimeout(()=>{
                    loadWorkflows();
                    loadWorkflowStats();
                    loadScheduledFollowups();
                    adminLoadWorkflowsForPreview();
                    adminLoadWorkflowsForDT();
                },100);
            }
        });
    });
    observer.observe(document.getElementById('tab-workflows'),{attributes:true,attributeFilter:['class']});
}

async function adminLoadWorkflowsForPreview(){
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>',{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok)return;
        
        const select=document.getElementById('adminPreviewWorkflowSelect');
        select.innerHTML='<option value="">Select workflow...</option>';
        
        json.workflows.forEach(w=>{
            const opt=document.createElement('option');
            opt.value=w.id;
            opt.dataset.trigger=w.trigger_type;
            opt.textContent=w.name+' ('+(w.scope==='global'?'Global':w.scope==='agent'?'Agent':'Entity')+')';
            select.appendChild(opt);
        });
        
    }catch(e){
        console.error('Load error:',e);
    }
}

async function adminLoadWorkflowsForDT(){
    try{
        const r=await fetch('<?=outbound_path('/api/email-workflow-api.php?action=get_workflows')?>',{credentials:'same-origin'});
        const json=await r.json();
        
        if(!json.ok)return;
        
        const select=document.getElementById('adminDTWorkflowSelect');
        select.innerHTML='<option value="">Choose workflow...</option>';
        
        json.workflows.forEach(w=>{
            const opt=document.createElement('option');
            opt.value=w.id;
            opt.textContent=w.name+(w.has_conditions?' 🌳':'');
            select.appendChild(opt);
        });
        
    }catch(e){
        console.error('Load error:',e);
    }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

