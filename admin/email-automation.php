<?php
$SEO = [
    'title' => 'Email Automation — Admin',
    'description' => 'Manage email templates and automation rules',
    'type' => 'website'
];
require_once __DIR__ . '/../required/config.php';
// Defer header include until after early JSON endpoints
require_once __DIR__ . '/../required/email_triggers_templates.php';
// SendGrid is now our mail transport for automations
require_once __DIR__ . '/../api/sendgrid.php';

// JSON inline endpoint (kept for backward compat) - but now use separate API
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'Deprecated endpoint. Use /api/email-admin-api.php']);
    exit;
}

// Normal page flow: render HTML
require_admin_or_redirect(path_with_base('/admin/'));
$pdo = db();

$notice = null; $error = null; $editorTheme = $_GET['theme'] ?? 'light';
// TinyMCE API key (from env or fallback provided)
$TINYMCE_API_KEY = getenv('TINYMCE_API_KEY') ?: 'v6glunkjndmnp8hpneevug9l1cq7jqvd0rjjvqy360clkafv';

// Create/update template
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_template') {
            $id = intval($_POST['id'] ?? 0);
            $slug = trim($_POST['slug'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $html = (string)($_POST['html_body'] ?? '');
            $text = (string)($_POST['text_body'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $scope = $_POST['scope'] ?? 'platform';
            $owner = ($tmp = $_POST['owner_user_id'] ?? '') !== '' ? intval($tmp) : null;
            if ($text === '' && $html !== '') {
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
            }
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($slug === '' || $name === '' || $subject === '') throw new Exception('Slug, name, and subject are required');
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE email_templates SET slug = ?, name = ?, subject = ?, html_body = ?, text_body = ?, category = ?, scope = ?, owner_user_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $st->execute([$slug,$name,$subject,$html,$text,$category ?: null,$scope,$owner,$active,$id]);
                $notice = 'Template updated';
            } else {
                $st = $pdo->prepare('INSERT INTO email_templates (slug, name, subject, html_body, text_body, category, scope, owner_user_id, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
                $st->execute([$slug,$name,$subject,$html,$text,$category ?: null,$scope,$owner,$active]);
                $notice = 'Template created';
            }
        } elseif ($action === 'save_rule') {
            $id = intval($_POST['id'] ?? 0);
            $event = trim($_POST['event_key'] ?? '');
            $templateId = intval($_POST['template_id'] ?? 0);
            $cond = (string)($_POST['condition_sql'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (!$event || !$templateId) throw new Exception('Event and template are required');
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE email_notification_rules SET event_key = ?, template_id = ?, condition_sql = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $st->execute([$event,$templateId,$cond,$active,$id]);
                $notice = 'Rule updated';
            } else {
                $st = $pdo->prepare('INSERT INTO email_notification_rules (event_key, template_id, condition_sql, is_active, created_at) VALUES (?,?,?,?,NOW())');
                $st->execute([$event,$templateId,$cond,$active]);
                $notice = 'Rule created';
            }
        } elseif ($action === 'delete_template') {
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
            $notice = 'Template deleted';
        } elseif ($action === 'delete_rule') {
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM email_notification_rules WHERE id = ?')->execute([$id]);
            $notice = 'Rule deleted';
        } elseif ($action === 'test_send') {
            // Admin test-send now uses SendGrid
            $to = trim($_POST['to'] ?? '');
            $tid = intval($_POST['template_id'] ?? 0);
            if ($to === '' || $tid <= 0) throw new Exception('To and template required');
            $t = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE id = ?');
            $t->execute([$tid]);
            $tpl = $t->fetch();
            if (!$tpl) throw new Exception('Template not found');
            $res = sendgrid_send([
                'to' => $to,
                'from' => 'sample-006@example.com',
                'from_name' => 'Product',
                'subject' => $tpl['subject'],
                'html' => $tpl['html_body'],
                'text' => $tpl['text_body'] ?: trim(preg_replace('/\s+/', ' ', strip_tags($tpl['html_body'])))
            ]);
            if (!($res['ok'] ?? false)) throw new Exception('SendGrid test failed: HTTP ' . ($res['status'] ?? 0));
            $notice = 'Test email sent via SendGrid';
        } elseif ($action === 'toggle_event') {
            $eventKey = trim($_POST['event_key'] ?? '');
            $enable = intval($_POST['enable'] ?? 0) === 1 ? 1 : 0;
            $templateId = intval($_POST['template_id'] ?? 0) ?: null;
            if ($eventKey === '') throw new Exception('Missing event key');
            // Upsert rule by event key
            $r = $pdo->prepare('SELECT id FROM email_notification_rules WHERE event_key = ? LIMIT 1');
            $r->execute([$eventKey]);
            $row = $r->fetch();
            if ($row) {
                $st = $pdo->prepare('UPDATE email_notification_rules SET is_active = ?, template_id = IFNULL(?, template_id), updated_at = NOW() WHERE id = ?');
                $st->execute([$enable, $templateId, $row['id']]);
            } else {
                if (!$templateId) {
                    // Try event catalog default template slug
                    $t = $pdo->prepare('SELECT default_template_slug FROM email_events WHERE event_key = ?');
                    $t->execute([$eventKey]);
                    $slug = ($t->fetch()['default_template_slug'] ?? null);
                    if ($slug) {
                        $q = $pdo->prepare('SELECT id FROM email_templates WHERE slug = ? LIMIT 1');
                        $q->execute([$slug]);
                        $templateId = $q->fetch()['id'] ?? null;
                    }
                }
                $st = $pdo->prepare('INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at) VALUES (?,?,?,NOW())');
                $st->execute([$eventKey, $templateId, $enable]);
            }
            $notice = 'Event updated';
        } elseif ($action === 'save_event') {
            $eventKey = trim($_POST['event_key'] ?? '');
            $templateId = intval($_POST['template_id'] ?? 0) ?: null;
            if ($eventKey === '') throw new Exception('Missing event key');
            $r = $pdo->prepare('SELECT id FROM email_notification_rules WHERE event_key = ? LIMIT 1');
            $r->execute([$eventKey]);
            $row = $r->fetch();
            if ($row) {
                $st = $pdo->prepare('UPDATE email_notification_rules SET template_id = ?, updated_at = NOW() WHERE id = ?');
                $st->execute([$templateId, $row['id']]);
            } else {
                $st = $pdo->prepare('INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at) VALUES (?,?,1,NOW())');
                $st->execute([$eventKey, $templateId]);
            }
            $notice = 'Event template saved';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

// Only render the page after handling JSON endpoints
require_once __DIR__ . '/../required/header.php';

// Load data
$templates = $pdo->query('SELECT * FROM email_templates ORDER BY updated_at DESC')->fetchAll();
$rules = $pdo->query('SELECT r.*, t.name AS template_name FROM email_notification_rules r JOIN email_templates t ON t.id = r.template_id ORDER BY r.updated_at DESC')->fetchAll();

$queueStatsRows = $pdo->query('SELECT status, COUNT(*) AS total FROM email_send_queue GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$queueStats = [];
foreach ($queueStatsRows as $row) {
    $queueStats[$row['status']] = $row['total'];
}
$queueNext = $pdo->query('SELECT MIN(scheduled_for) FROM email_send_queue WHERE status = "pending"')->fetchColumn();
$followupStatsRows = $pdo->query('SELECT status, COUNT(*) AS total FROM email_followup_schedule GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$followupStats = [];
foreach ($followupStatsRows as $row) {
    $followupStats[$row['status']] = $row['total'];
}
$sampleSuiteStatsRows = $pdo->query("
    SELECT suite_status, COUNT(*) AS total
    FROM sample_requests
    WHERE suite_status IS NOT NULL AND suite_status <> 'not_started'
    GROUP BY suite_status
")->fetchAll(PDO::FETCH_ASSOC);
$sampleSuiteStats = [];
foreach ($sampleSuiteStatsRows as $row) {
    $sampleSuiteStats[$row['suite_status']] = $row['total'];
}
$sampleSuiteActive = $pdo->query("SELECT COUNT(*) FROM sample_suite_enrollments WHERE status IN ('pending','active')")->fetchColumn();
$sampleSuitePending = $pdo->query("SELECT COUNT(*) FROM email_followup_schedule WHERE chain_id IS NOT NULL AND status = 'pending'")->fetchColumn();
$recentQueue = $pdo->query('SELECT id, agent_email_id, scheduled_for, status, last_error FROM email_send_queue ORDER BY created_at DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
$recentFollowups = $pdo->query('SELECT id, workflow_id, parent_email_id, status, scheduled_for, processed_at FROM email_followup_schedule ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);

// Events overview with current rule state
$events_overview = $pdo->query('SELECT e.event_key, e.name, e.scope, e.default_template_slug,
    r.id AS rule_id, r.is_active, r.template_id
  FROM email_events e
  LEFT JOIN email_notification_rules r ON r.event_key = e.event_key
  ORDER BY e.name')->fetchAll();

// Agent templates list
$agent_templates = $pdo->query("SELECT * FROM email_templates WHERE scope = 'sales_agent' ORDER BY updated_at DESC")->fetchAll();

// Centralize the definitions for all available email trigger events
$available_events = [];
// Prefer to load from catalog if present
try {
    $ev = $pdo->query('SELECT event_key, name FROM email_events WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
    if ($ev) {
        foreach ($ev as $row) { $available_events[$row['event_key']] = $row['name']; }
    }
} catch (Throwable $e) { /* fallback below */ }
if (!$available_events) {
    $available_events = [
        'first_signup' => 'First Sign Up',
        'new_user_registration' => 'New user registration',
        'new_lead' => 'New lead',
        'new_account' => 'New account',
        'new_invoice_admin' => 'New invoice (platform admin)',
        'new_invoice_customer' => 'New invoice (customer)',
        'new_invoice_agent' => 'New invoice (sales agent)',
        'weekly_sales_report_admin' => 'Weekly sales report (platform admin)',
        'weekly_sales_report_agent' => 'Weekly sales report (sales agent)',
        'monthly_purchase_review_customer' => 'Monthly purchase review (customer)',
        'weekly_purchase_followup' => 'Weekly purchase follow-up'
    ];
}

?>

<div class="container">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    <div class="reference-card" style="background:white; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,0.1);">
        <div class="card-header" style="background:linear-gradient(135deg,#14b8a6,#0ea5e9); color:white; padding:20px 24px; border-radius:12px 12px 0 0;">
            <h1 style="margin:0;">📧 Email Automation</h1>
            <p style="margin:8px 0 0 0; opacity:.9;">Manage templates and conditional notification rules. Now powered by SendGrid delivery.</p>
        </div>
        <div class="card-body" style="padding:24px;">
            <?php if ($notice): ?><div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="card" role="alert" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;">Error: <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <h3>Templates</h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div class="card">
                    <form method="post" id="templateForm">
                        <input type="hidden" name="action" value="save_template">
                        <input type="hidden" name="id" id="tpl_id" value="">
                        <label>Slug<input name="slug" id="tpl_slug" required></label>
                        <label>Name<input name="name" id="tpl_name" required></label>
                        <label>Subject<input name="subject" id="tpl_subject" required></label>
                        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px;">
                            <label>Category<input name="category" id="tpl_category" placeholder="welcome, newsletter, invoice"></label>
                            <label>Scope
                                <select name="scope" id="tpl_scope">
                                    <option value="platform">platform</option>
                                    <option value="sales_agent">sales_agent</option>
                                    <option value="customer">customer</option>
                                </select>
                            </label>
                            <label>Owner (User ID)<input type="number" name="owner_user_id" id="tpl_owner"></label>
                        </div>
                        <label>
                            HTML Body
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:stretch;">
                                <div>
                                    <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                                        <select id="varSelector">
                                            <option value="">Insert variable…</option>
                                            <option value="{{first_name}}">first_name</option>
                                            <option value="{{last_name}}">last_name</option>
                                            <option value="{{email}}">email</option>
                                            <option value="{{login_url}}">login_url</option>
                                        </select>
                                        <button type="button" class="btn small" onclick="insertVariable()">Insert</button>
                                    </div>
                                    <textarea name="html_body" id="tpl_html" style="min-height:460px;border:1px solid #e5e7eb;border-radius:8px;padding:12px;outline:0;background:#fff;"></textarea>
                                </div>
                                <div>
                                    <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Live Preview</div>
                                    <iframe id="tpl_preview" style="width:100%; height:460px; border:1px solid #e5e7eb; border-radius:8px; background:white;"></iframe>
                                </div>
                            </div>
                        </label>
                        <!-- Text Body removed (auto-generated from HTML if omitted) -->
                        <label class="checkbox"><input type="checkbox" name="is_active" id="tpl_active" checked> Active</label>
                        <div class="actions">
                            <button class="btn" type="submit">Save</button>
                            <button class="btn secondary" type="button" onclick="clearTemplateForm()">New</button>
                            <select id="presetSelector" onchange="applyPreset()">
                                <option value="">Insert preset…</option>
                                <?php foreach ($AVAILABLE_EMAIL_TEMPLATES as $key => $meta): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($meta['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn" type="button" onclick="syncFromDisk()">Sync from HTML directory</button>
                        </div>
                    </form>
                </div>
                <div class="card">
                    <table class="bulk-table" style="width:100%;font-size:13px;">
                        <thead><tr><th>ID</th><th>Slug</th><th>Name</th><th>Category</th><th>Scope</th><th>Active</th><th>Updated</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($templates as $t): ?>
                            <tr>
                                <td><?php echo $t['id']; ?></td>
                                <td><?php echo htmlspecialchars($t['slug']); ?></td>
                                <td><?php echo htmlspecialchars($t['name']); ?></td>
                                <td><?php echo htmlspecialchars($t['category'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($t['scope'] ?? ''); ?></td>
                                <td><?php echo $t['is_active'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars($t['updated_at']); ?></td>
                                <td style="white-space:nowrap;">
                                    <button class="btn small" onclick='loadTemplate(<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'>Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this template?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_template">
                                        <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                        <button class="btn small" style="background:#dc2626;color:white;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <hr>
                    <form method="post" style="display:flex;gap:8px;align-items:end;" onsubmit="return sendTemplateTest(event)">
                        <input type="hidden" name="action" value="test_send">
                        <label style="flex:1"><span style="font-size:12px;color:#6b7280;">Send test to</span><input id="tpl_test_to" name="to" placeholder="sample-001@example.com" required></label>
                        <label style="flex:1"><span style="font-size:12px;color:#6b7280;">Template</span>
                            <select id="tpl_test_template" name="template_id" required>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="btn" type="submit">Send Test</button>
                    </form>
                    <pre id="tplTestOutput" style="white-space:pre-wrap;overflow:auto;max-height:200px;background:#0b1b30;color:#d1e7ff;padding:8px;border-radius:6px;margin-top:8px;display:none;"></pre>
                </div>
            </div>

            <h3 style="margin-top:20px;">Automation Rules</h3>
            <div class="card">
                <form method="post" style="display:grid; grid-template-columns: 1fr 1fr 2fr auto; gap:8px; align-items:end;">
                    <input type="hidden" name="action" value="save_rule">
                    <input type="hidden" name="id" value="">
                    <label>
                        <span style="font-size:12px;color:#6b7280;">Event</span>
                        <select name="event_key" required>
                            <?php foreach ($available_events as $key => $name): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span style="font-size:12px;color:#6b7280;">Template</span>
                        <select name="template_id" required>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span style="font-size:12px;color:#6b7280;">Audience</span>
                        <select name="audience_role">
                            <option value="">(inherit)</option>
                            <option value="platform_admin">platform_admin</option>
                            <option value="sales_agent">sales_agent</option>
                            <option value="customer">customer</option>
                        </select>
                    </label>
                    <label>
                        <span style="font-size:12px;color:#6b7280;">Schedule (cron)</span>
                        <input name="schedule_cron" placeholder="0 8 * * MON">
                    </label>
                    <label>
                        <span style="font-size:12px;color:#6b7280;">SQL Condition (WHERE ...)</span>
                        <input name="condition_sql" placeholder="lead_status = 'new'">
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="is_active" checked> Active</label>
                    <button class="btn" type="submit" style="background:#2563eb;color:white;">Add Rule</button>
                </form>

                <table class="bulk-table" style="width:100%;margin-top:12px;">
                    <thead><tr><th>ID</th><th>Event</th><th>Template</th><th>Active</th><th>Updated</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($rules as $r): ?>
                            <tr>
                                <td><?php echo $r['id']; ?></td>
                                <td><?php echo htmlspecialchars($r['event_key']); ?></td>
                                <td><?php echo htmlspecialchars($r['template_name']); ?></td>
                                <td><?php echo $r['is_active'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars($r['updated_at']); ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete this rule?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_rule">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button class="btn small" style="background:#dc2626;color:white;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="container" style="margin-top:16px;">
    <details class="card" open>
        <summary style="font-weight:600; cursor:pointer;">Diagnostics / Debug Logs</summary>
        <div style="padding:12px;">
            <form id="diagForm" onsubmit="return runEmailDiagnostics(event)" style="display:flex; gap:8px; align-items:end;">
                <label style="flex:1">
                    <span style="display:block; font-size:12px; color:#6b7280;">Send test to</span>
                    <input id="diag_to" placeholder="sample-001@example.com" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                </label>
                <button class="btn" type="submit">Run SendGrid Send Test</button>
            </form>
            <pre id="diagOutput" style="white-space:pre-wrap; overflow:auto; max-height:320px; background:#0b1b30; color:#d1e7ff; padding:12px; border-radius:6px; margin-top:12px;">Ready.</pre>
            <button class="btn small" onclick="copyDiagLogs()">Copy logs</button>
        </div>
    </details>

    <details class="card" open>
        <summary style="font-weight:600; cursor:pointer;">Active Events</summary>
        <div style="padding:12px;">
            <table class="bulk-table" style="width:100%;">
                <thead><tr><th>Event</th><th>Scope</th><th>Template</th><th>Enabled</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($events_overview as $ev): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ev['name']); ?><div style="font-size:12px;color:#6b7280;">(<?php echo htmlspecialchars($ev['event_key']); ?>)</div></td>
                        <td><?php echo htmlspecialchars($ev['scope']); ?></td>
                        <td>
                            <form method="post" style="display:flex; gap:6px; align-items:center;">
                                <input type="hidden" name="action" value="save_event">
                                <input type="hidden" name="event_key" value="<?php echo htmlspecialchars($ev['event_key']); ?>">
                                <select name="template_id">
                                    <option value="">— Select template —</option>
                                    <?php foreach ($templates as $t): ?>
                                        <option value="<?php echo $t['id']; ?>" <?php echo intval($ev['template_id']) === intval($t['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn small" type="submit">Save</button>
                            </form>
                        </td>
                        <td><?php echo intval($ev['is_active']) === 1 ? 'Yes' : 'No'; ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_event">
                                <input type="hidden" name="event_key" value="<?php echo htmlspecialchars($ev['event_key']); ?>">
                                <input type="hidden" name="template_id" value="<?php echo intval($ev['template_id']); ?>">
                                <?php if (intval($ev['is_active']) === 1): ?>
                                    <input type="hidden" name="enable" value="0">
                                    <button class="btn small" style="background:#dc2626;color:white;">Disable</button>
                                <?php else: ?>
                                    <input type="hidden" name="enable" value="1">
                                    <button class="btn small" style="background:#16a34a;color:white;">Enable</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>

    <details class="card" open>
        <summary style="font-weight:600; cursor:pointer;">Agent Templates</summary>
        <div style="padding:12px;">
            <p style="margin:0 0 8px 0; color:#475569;">Templates with scope = sales_agent. Owners can be set in the editor above. Agents will only see templates they own.</p>
            <table class="bulk-table" style="width:100%;">
                <thead><tr><th>ID</th><th>Name</th><th>Owner</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ($agent_templates as $at): ?>
                    <tr>
                        <td><?php echo $at['id']; ?></td>
                        <td><?php echo htmlspecialchars($at['name']); ?></td>
                        <td><?php echo htmlspecialchars($at['owner_user_id'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($at['updated_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    
    <details class="card" open>
        <summary style="font-weight:600; cursor:pointer;">Automation Monitor</summary>
        <div style="padding:12px; display:grid; gap:16px;">
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="card" style="flex:1; min-width:220px;">
                    <h4 style="margin-top:0;">Send Queue</h4>
                    <ul style="list-style:none; padding:0; margin:0;">
                        <?php foreach (['pending','processing','sent','failed','cancelled'] as $status): ?>
                            <li style="display:flex; justify-content:space-between;">
                                <span style="text-transform:capitalize;"><?php echo $status; ?></span>
                                <strong><?php echo intval($queueStats[$status] ?? 0); ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top:8px; font-size:12px; color:#64748b;">
                        Next scheduled send: <?php echo $queueNext ? htmlspecialchars($queueNext) : '—'; ?>
                    </div>
                </div>
                <div class="card" style="flex:1; min-width:220px;">
                    <h4 style="margin-top:0;">Follow-up Pipeline</h4>
                    <ul style="list-style:none; padding:0; margin:0;">
                        <?php foreach (['pending','processing','sent','failed','cancelled'] as $status): ?>
                            <li style="display:flex; justify-content:space-between;">
                                <span style="text-transform:capitalize;"><?php echo $status; ?></span>
                                <strong><?php echo intval($followupStats[$status] ?? 0); ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card" style="flex:1; min-width:220px;">
                    <h4 style="margin-top:0;">Sample Suite</h4>
                    <ul style="list-style:none; padding:0; margin:0;">
                        <?php foreach (['pending','in_transit','post_delivery','completed','cancelled'] as $state): ?>
                            <li style="display:flex; justify-content:space-between;">
                                <span style="text-transform:capitalize;"><?php echo str_replace('_',' ', $state); ?></span>
                                <strong><?php echo intval($sampleSuiteStats[$state] ?? 0); ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top:8px; font-size:12px; color:#64748b;">
                        Active enrollments: <?php echo intval($sampleSuiteActive); ?><br>
                        Pending suite sends: <?php echo intval($sampleSuitePending); ?>
                    </div>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px;">
                <div class="card">
                    <h4 style="margin-top:0;">Recent Queue Items</h4>
                    <table class="bulk-table">
                        <thead><tr><th>ID</th><th>Email</th><th>Status</th><th>Scheduled</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentQueue as $queueRow): ?>
                                <tr>
                                    <td>#<?php echo $queueRow['id']; ?></td>
                                    <td><?php echo htmlspecialchars($queueRow['agent_email_id']); ?></td>
                                    <td><?php echo htmlspecialchars($queueRow['status']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($queueRow['scheduled_for']); ?>
                                        <?php if (!empty($queueRow['last_error'])): ?>
                                            <div style="font-size:11px;color:#b91c1c;"><?php echo htmlspecialchars($queueRow['last_error']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h4 style="margin-top:0;">Recent Follow-ups</h4>
                    <table class="bulk-table">
                        <thead><tr><th>ID</th><th>Workflow</th><th>Status</th><th>Scheduled</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentFollowups as $followRow): ?>
                                <tr>
                                    <td>#<?php echo $followRow['id']; ?></td>
                                    <td><?php echo htmlspecialchars($followRow['workflow_id']); ?></td>
                                    <td><?php echo htmlspecialchars($followRow['status']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($followRow['scheduled_for']); ?>
                                        <?php if (!empty($followRow['processed_at'])): ?>
                                            <div style="font-size:11px;color:#6b7280;">Processed: <?php echo htmlspecialchars($followRow['processed_at']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </details>
</div>

<script>
const HTML_PRESETS = <?php echo json_encode($AVAILABLE_EMAIL_TEMPLATES, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;

function applyPreset(){
    var sel = document.getElementById('presetSelector');
    var key = sel.value; if (!key || !HTML_PRESETS[key]) return;
    var html = HTML_PRESETS[key].html || '';
    var text = HTML_PRESETS[key].text || '';
    if (window.tinymce && tinymce.get('tpl_html')) { tinymce.get('tpl_html').setContent(html); tinymce.get('tpl_html').save(); }
    var ta = document.getElementById('tpl_html'); if (ta) ta.value = html;
    document.getElementById('tpl_text').value = text;
    sel.value = '';
}

function loadTemplate(t){
    document.getElementById('tpl_id').value = t.id;
    document.getElementById('tpl_slug').value = t.slug;
    document.getElementById('tpl_name').value = t.name;
    document.getElementById('tpl_subject').value = t.subject;
    document.getElementById('tpl_category').value = t.category || '';
    document.getElementById('tpl_scope').value = t.scope || 'platform';
    document.getElementById('tpl_owner').value = t.owner_user_id || '';
    if (window.tinymce && tinymce.get('tpl_html')) { tinymce.get('tpl_html').setContent(t.html_body || ''); tinymce.get('tpl_html').save(); }
    document.getElementById('tpl_html').value = t.html_body || '';
    document.getElementById('tpl_active').checked = parseInt(t.is_active, 10) === 1;
    renderPreview();
}
function clearTemplateForm(){
    document.getElementById('tpl_id').value='';
    document.getElementById('tpl_slug').value='';
    document.getElementById('tpl_name').value='';
    document.getElementById('tpl_subject').value='';
    document.getElementById('tpl_category').value='';
    document.getElementById('tpl_scope').value='platform';
    document.getElementById('tpl_owner').value='';
    if (window.tinymce && tinymce.get('tpl_html')) { tinymce.get('tpl_html').setContent(''); tinymce.get('tpl_html').save(); }
    document.getElementById('tpl_html').value='';
    document.getElementById('tpl_active').checked=true;
    renderPreview();
}

// Progressive enhancement: try TinyMCE CDN, then local fallback
function initTinyMCE() {
    if (!window.tinymce) return;
    // Initialize TinyMCE on the textarea directly
    tinymce.init({
        selector: '#tpl_html',
        height: 460,
        menubar: 'file edit view insert format tools table help',
        plugins: 'preview importcss searchreplace autolink directionality visualblocks visualchars fullscreen image link media template codesample table charmap pagebreak nonbreaking anchor advlist lists wordcount help emoticons code',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | link image media | template | code preview',
        content_style: 'body { font-family: "Inter", sans-serif; color: #1f2937; line-height: 1.7; }',
        templates: Object.keys(HTML_PRESETS).map(key => ({
            title: HTML_PRESETS[key].label,
            description: HTML_PRESETS[key].description,
            content: HTML_PRESETS[key].html
        })),
        setup(editor) {
            editor.on('change', () => { editor.save(); renderPreview(); });
            editor.on('keyup', () => { editor.save(); renderPreview(); });
        }
    });
}
function tryLoadScript(src, onload, onerror){
    var s = document.createElement('script');
    s.src = src; s.defer = false; s.async = true; s.referrerPolicy = 'origin';
    s.onload = onload; s.onerror = onerror;
    document.head.appendChild(s);
}
(function(){
    // Try CDN first (with API key)
    tryLoadScript('https://cdn.tiny.cloud/1/<?php echo $TINYMCE_API_KEY; ?>/tinymce/6/tinymce.min.js', initTinyMCE, function(){
        // Fallback: local path (if present)
        tryLoadScript('<?php echo outbound_path('/assets/vendor/tinymce/tinymce.min.js'); ?>', initTinyMCE, function(){ /* keep fallback editor */ });
    });
})();

async function sendTemplateTest(ev){
    ev.preventDefault();
    if (window.tinymce && tinymce.get('tpl_html')) { tinymce.get('tpl_html').save(); }
    const to = document.getElementById('tpl_test_to').value.trim();
    const templateId = document.getElementById('tpl_test_template').value;
    const out = document.getElementById('tplTestOutput');
    out.style.display='block';
    out.textContent = 'Sending...';
    try {
        // Pull the template from the table via POST back to this page to keep logic unified
        const form = new FormData();
        form.append('action','test_send');
        form.append('to', to);
        form.append('template_id', templateId);
        const res = await fetch(window.location.href, { method:'POST', body: form, headers: {'X-Requested-With':'fetch'} });
        const text = await res.text();
        // The PHP path does full page redirect; for AJAX we rely on the server test path instead
        // Fallback: call diagnostics endpoint directly with fetched template
        if (!res.ok || text.indexOf('Test email queued') === -1) {
            // Fetch the template via lightweight endpoint (reuse diagnostics to send)
            const tpl = <?php echo json_encode(array_column($templates, null, 'id')); ?>;
            const t = tpl[templateId];
            const payload = { to, subject: t.subject, html: t.html_body, text: t.text_body };
            const r2 = await fetch('<?php echo path_with_base('/required/api/gmail-send.php'); ?>', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
            const j2 = await r2.json();
            out.textContent = JSON.stringify({ http: r2.status, ok: r2.ok, response: j2 }, null, 2);
        } else {
            out.textContent = 'Queued via server-side form';
        }
    } catch(e){ out.textContent = 'Error: ' + (e.message||String(e)); }
    return false;
}

async function runEmailDiagnostics(ev){
    ev.preventDefault();
    const to = document.getElementById('diag_to').value.trim();
    const out = document.getElementById('diagOutput');
    out.textContent = 'Running diagnostics...';
    try {
        // Use lightweight endpoint by calling SendGrid via this page (no server endpoint needed)
        const payload = { to: to || 'sample-007@example.com', from: 'sample-008@example.com', from_name: 'Product', subject: 'Product Email Diagnostics', html: '<p>This is a diagnostic email from Email Automation admin.</p>', text: 'Diagnostic email from Email Automation admin.' };
        const r = await fetch(window.location.href + '?json=1', { method:'POST', headers: { 'Content-Type':'application/json', 'X-Action':'sendgrid_inline' }, credentials: 'same-origin', body: JSON.stringify({ action:'sendgrid_inline', payload }) });
        const j = await r.json();
        out.textContent = JSON.stringify(j, null, 2);
    } catch(e){
        out.textContent = 'Diagnostics error: ' + (e && e.message ? e.message : String(e));
    }
    return false;
}
function copyDiagLogs(){ var t=document.getElementById('diagOutput'); if(!t) return; navigator.clipboard.writeText(t.textContent).then(()=>alert('Copied')); }

document.addEventListener('DOMContentLoaded', function(){ /* editor init handled above */ });
// Inline handler to support diagnostics via SendGrid from the UI

async function syncFromDisk(){
    const btns = document.querySelectorAll('button');
    btns.forEach(b => b.disabled = true);
    try {
        const res = await fetch('<?php echo api_url('/api/email-admin-api.php'); ?>?action=sync_templates_from_disk', { 
            method:'POST', 
            headers:{'Content-Type':'application/json'}, 
            credentials: 'same-origin', 
            body: JSON.stringify({ action: 'sync_templates_from_disk' }) 
        });
        const j = await res.json();
        if (!j.ok) {
            throw new Error(j.error || 'Sync failed');
        }
        alert('✓ Sample Suite Templates Synced!\n\nInserted: ' + (j.inserted||0) + '\nUpdated: ' + (j.updated||0) + '\nSkipped: ' + (j.skipped||0) + (j.errors && j.errors.length ? ('\n\nErrors:\n' + j.errors.join('\n')) : ''));
        location.reload();
    } catch(e) {
        alert('Sync failed: ' + (e && e.message ? e.message : String(e)));
    } finally {
        btns.forEach(b => b.disabled = false);
    }
}

function insertVariable(){
    var sel = document.getElementById('varSelector');
    var val = sel.value; if (!val) return;
    if (window.tinymce && tinymce.get('tpl_html')) {
        tinymce.get('tpl_html').execCommand('mceInsertContent', false, val);
        tinymce.get('tpl_html').save();
        renderPreview();
    } else {
        var ta = document.getElementById('tpl_html');
        ta.value += val;
        renderPreview();
    }
    sel.value = '';
}

function renderPreview(){
    try {
        var html = document.getElementById('tpl_html').value || '';
        var iframe = document.getElementById('tpl_preview');
        if (!iframe) return;
        var doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();
    } catch(e) { /* ignore preview errors */ }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>



