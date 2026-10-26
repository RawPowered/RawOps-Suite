<?php
/**
 * Email Automation Settings Manager
 * Configure global and per-entity automation rules
 */

require_once __DIR__ . '/../required/config.php';

$SEO = ['title'=>'Email Automation Settings','description'=>'Manage automation rules','type'=>'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_global') {
            $automationEnabled = isset($_POST['automation_enabled']) ? 1 : 0;
            $allowAiEmails = isset($_POST['allow_ai_emails']) ? 1 : 0;
            $allowScheduled = isset($_POST['allow_scheduled_emails']) ? 1 : 0;
            $maxPerWeek = intval($_POST['max_emails_per_week'] ?? 3);
            $notes = trim($_POST['notes'] ?? '');
            
            // Update or insert global settings
            $stmt = $pdo->prepare('SELECT id FROM email_automation_settings WHERE entity_type = "global" AND entity_id IS NULL');
            $stmt->execute();
            $existing = $stmt->fetch();
            
            if ($existing) {
                $stmt = $pdo->prepare('UPDATE email_automation_settings SET automation_enabled = ?, allow_ai_emails = ?, allow_scheduled_emails = ?, max_emails_per_week = ?, notes = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$automationEnabled, $allowAiEmails, $allowScheduled, $maxPerWeek, $notes, $existing['id']]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO email_automation_settings (entity_type, entity_id, automation_enabled, allow_ai_emails, allow_scheduled_emails, max_emails_per_week, notes, created_at) VALUES ("global", NULL, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$automationEnabled, $allowAiEmails, $allowScheduled, $maxPerWeek, $notes]);
            }
            
            $message = 'Global automation settings updated successfully';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get global settings
$globalSettings = [
    'automation_enabled' => 0,
    'allow_ai_emails' => 0,
    'allow_scheduled_emails' => 1,
    'max_emails_per_week' => 3,
    'notes' => ''
];

try {
    $stmt = $pdo->prepare('SELECT * FROM email_automation_settings WHERE entity_type = "global" AND entity_id IS NULL LIMIT 1');
    $stmt->execute();
    $settings = $stmt->fetch();
    if ($settings) {
        $globalSettings = array_merge($globalSettings, $settings);
    }
} catch (Exception $e) {
    $error = 'Could not load settings: ' . $e->getMessage();
}

// Get stats
$stats = [
    'total_emails' => 0,
    'ai_assisted' => 0,
    'scheduled' => 0,
    'sent_today' => 0,
    'pending_review' => 0
];

try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM agent_emails');
    $stats['total_emails'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM agent_emails WHERE is_ai_assisted = 1');
    $stats['ai_assisted'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM email_send_queue WHERE status = "pending"');
    $stats['scheduled'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM agent_emails WHERE DATE(sent_at) = CURDATE()');
    $stats['sent_today'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM agent_emails WHERE admin_reviewed = 0 AND status = "sent"');
    $stats['pending_review'] = $stmt->fetchColumn();
} catch (Exception $e) {}
?>

<style>
.settings-page{max-width:1200px;margin:0 auto;padding:24px;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:32px;}
.stat-card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);text-align:center;}
.stat-value{font-size:36px;font-weight:700;color:#0ea5e9;margin-bottom:4px;}
.stat-label{font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;}
.settings-card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);margin-bottom:20px;}
.form-group{margin-bottom:20px;}
.form-group label{display:block;font-weight:600;color:#374151;margin-bottom:8px;}
.form-group input[type="number"]{width:120px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;}
.toggle-switch{display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;}
.toggle-switch input[type="checkbox"]{width:20px;height:20px;}
.warning-box{background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:16px;border-radius:8px;margin-bottom:20px;}
</style>

<div class="settings-page">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h1>⚙️ Email Automation Settings</h1>
            <p>Configure global automation behavior and monitor system status</p>
        </div>
    </div>
    
    <?php if($message):?><div class="notice"><?=htmlspecialchars($message)?></div><?php endif?>
    <?php if($error):?><div class="error">Error: <?=htmlspecialchars($error)?></div><?php endif?>
    
    <!-- Stats Dashboard -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['total_emails']); ?></div>
            <div class="stat-label">Total Emails</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#3b82f6;"><?php echo number_format($stats['ai_assisted']); ?></div>
            <div class="stat-label">AI-Assisted</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#f59e0b;"><?php echo number_format($stats['scheduled']); ?></div>
            <div class="stat-label">Scheduled/Queued</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#10b981;"><?php echo number_format($stats['sent_today']); ?></div>
            <div class="stat-label">Sent Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#dc2626;"><?php echo number_format($stats['pending_review']); ?></div>
            <div class="stat-label">Needs Review</div>
        </div>
    </div>
    
    <?php if (!$globalSettings['automation_enabled']): ?>
    <div class="warning-box">
        <strong>⚠️ Automation Currently Disabled</strong><br>
        Email automation is globally disabled per deployment mandate. Enable below after completing testing phase.
    </div>
    <?php endif; ?>
    
    <!-- Global Settings Form -->
    <div class="settings-card">
        <h2 style="margin-top:0;">Global Automation Settings</h2>
        <p style="color:#64748b;margin-bottom:24px;">These settings apply to all leads and accounts unless overridden individually.</p>
        
        <form method="post">
            <input type="hidden" name="action" value="update_global">
            
            <div class="form-group">
                <div class="toggle-switch">
                    <input type="checkbox" name="automation_enabled" id="automation_enabled" <?php echo $globalSettings['automation_enabled'] ? 'checked' : ''; ?>>
                    <label for="automation_enabled" style="margin:0;font-size:16px;">
                        <strong>Enable Email Automation</strong><br>
                        <span style="font-size:13px;color:#64748b;font-weight:400;">Master switch for all automated email features</span>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="toggle-switch">
                    <input type="checkbox" name="allow_ai_emails" id="allow_ai_emails" <?php echo $globalSettings['allow_ai_emails'] ? 'checked' : ''; ?>>
                    <label for="allow_ai_emails" style="margin:0;font-size:16px;">
                        <strong>Allow AI-Generated Emails</strong><br>
                        <span style="font-size:13px;color:#64748b;font-weight:400;">Permit agents to use AI assistant for email composition</span>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="toggle-switch">
                    <input type="checkbox" name="allow_scheduled_emails" id="allow_scheduled_emails" <?php echo $globalSettings['allow_scheduled_emails'] ? 'checked' : ''; ?>>
                    <label for="allow_scheduled_emails" style="margin:0;font-size:16px;">
                        <strong>Allow Scheduled Emails</strong><br>
                        <span style="font-size:13px;color:#64748b;font-weight:400;">Enable scheduling emails for future delivery</span>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Maximum Emails Per Week (per customer)</label>
                <input type="number" name="max_emails_per_week" min="0" max="20" value="<?php echo $globalSettings['max_emails_per_week']; ?>">
                <p style="font-size:13px;color:#64748b;margin-top:4px;">Rate limiting to prevent email fatigue (0 = unlimited)</p>
            </div>
            
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="notes" rows="3" style="width:100%;max-width:600px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;"><?php echo htmlspecialchars($globalSettings['notes']); ?></textarea>
            </div>
            
            <button type="submit" class="btn" style="background:#059669;">Save Global Settings</button>
        </form>
    </div>
    
    <!-- Quick Actions -->
    <div class="settings-card">
        <h3 style="margin-top:0;">Quick Actions</h3>
        
        <div style="display:grid;gap:12px;max-width:600px;">
            <a href="<?php echo outbound_path('/admin/email-automation-v2.php'); ?>" class="btn" style="display:inline-flex;align-items:center;gap:8px;">
                <span>📧</span> View Agent Emails Dashboard
            </a>
            
            <a href="<?php echo outbound_path('/admin/test-sendgrid-webhook.php'); ?>" class="btn" style="display:inline-flex;align-items:center;gap:8px;background:#f59e0b;">
                <span>🔍</span> Test SendGrid Webhook
            </a>
            
            <button type="button" class="btn btn-secondary" onclick="runQueueProcessor()">
                <span>🔄</span> Process Email Queue Now
            </button>
            
            <a href="<?php echo outbound_path('/admin/email-automation-v2.php'); ?>#tab-templates" class="btn btn-secondary">
                <span>📝</span> Manage Email Templates
            </a>
        </div>
    </div>
    
    <!-- System Information -->
    <div class="settings-card">
        <h3 style="margin-top:0;">System Information</h3>
        
        <table style="width:100%;max-width:600px;">
            <tr>
                <td style="padding:8px 0;color:#64748b;width:40%;">Queue Processor Status:</td>
                <td style="padding:8px 0;font-weight:600;">
                    <span style="color:#f59e0b;">⏸️ Manual (Cron not configured)</span>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">Gemini AI Status:</td>
                <td style="padding:8px 0;font-weight:600;">
                    <?php echo !empty($gemini_api_key) ? '<span style="color:#10b981;">✅ Configured</span>' : '<span style="color:#dc2626;">❌ Not Configured</span>'; ?>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">SendGrid Status:</td>
                <td style="padding:8px 0;font-weight:600;">
                    <?php 
                    $cfg = site_read_config();
                    echo !empty($cfg['sendgrid_api_key']) ? '<span style="color:#10b981;">✅ Configured</span>' : '<span style="color:#dc2626;">❌ Not Configured</span>'; 
                    ?>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">Webhook Tracking:</td>
                <td style="padding:8px 0;font-weight:600;">
                    <?php 
                    try {
                        $webhookLog = __DIR__ . '/../required/logs/sendgrid-webhook.log';
                        $hasWebhook = file_exists($webhookLog) && filesize($webhookLog) > 0;
                        echo $hasWebhook ? '<span style="color:#10b981;">✅ Active</span>' : '<span style="color:#f59e0b;">⏸️ Not Configured</span>';
                    } catch (Exception $e) {
                        echo '<span style="color:#f59e0b;">⏸️ Not Configured</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">Last Queue Run:</td>
                <td style="padding:8px 0;font-weight:600;">
                    <?php 
                    try {
                        $stmt = $pdo->query('SELECT created_at FROM operations_log WHERE action = "email_queue_process" ORDER BY created_at DESC LIMIT 1');
                        $lastRun = $stmt->fetchColumn();
                        echo $lastRun ? date('M j, Y g:i A', strtotime($lastRun)) : 'Never';
                    } catch (Exception $e) {
                        echo 'Unknown';
                    }
                    ?>
                </td>
            </tr>
        </table>
        
        <div style="margin-top:20px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
            <h4 style="margin:0 0 8px 0;color:#0369a1;">Cron Setup Instructions</h4>
            <p style="margin:0 0 8px 0;font-size:13px;color:#0c4a6e;">Add this line to your server crontab to enable automatic queue processing:</p>
            <code style="display:block;padding:8px;background:#1e293b;color:#e2e8f0;border-radius:4px;font-family:monospace;font-size:12px;">
                0 * * * * /usr/bin/php <?php echo realpath(__DIR__ . '/../api/email-queue-processor.php'); ?>
            </code>
        </div>
        
        <div style="margin-top:20px;padding:16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;">
            <h4 style="margin:0 0 8px 0;color:#92400e;">📡 SendGrid Webhook Configuration</h4>
            <p style="margin:0 0 12px 0;font-size:13px;color:#78350f;">Configure SendGrid to track opens, clicks, and bounces:</p>
            
            <ol style="margin:0 0 12px 0;font-size:13px;color:#78350f;line-height:1.6;">
                <li>Login to <a href="https://app.sendgrid.com/" target="_blank" style="color:#92400e;font-weight:600;">SendGrid Dashboard</a></li>
                <li>Navigate to: <strong>Settings → Mail Settings → Event Webhook</strong></li>
                <li>Enable the Event Webhook</li>
                <li>Set HTTP Post URL to:</li>
            </ol>
            
            <code style="display:block;padding:8px;background:#1e293b;color:#e2e8f0;border-radius:4px;font-family:monospace;font-size:12px;margin-bottom:12px;">
                <?php echo site_origin(); ?>/api/sendgrid-webhook.php
            </code>
            
            <p style="margin:0 0 8px 0;font-size:13px;color:#78350f;"><strong>Enable these events:</strong></p>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:12px;color:#78350f;">
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" checked disabled> Delivered</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" checked disabled> Opened</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" checked disabled> Clicked</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" checked disabled> Bounced</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" checked disabled> Dropped</label>
                <label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" checked disabled> Spam Report</label>
            </div>
            
            <p style="margin:12px 0 0;font-size:12px;color:#92400e;">
                ℹ️ After saving, SendGrid will send real-time events to track email engagement automatically.
            </p>
        </div>
    </div>
</div>

<script>
async function runQueueProcessor(){
    if(!confirm('Process all pending scheduled emails now?')){
        return;
    }
    
    const btn=event.target;
    btn.disabled=true;
    btn.textContent='Processing...';
    
    try{
        const r=await fetch('<?=outbound_path('/api/email-queue-processor.php?manual=1')?>',{credentials:'same-origin'});
        const text=await r.text();
        
        alert('Queue processed!\n\nOutput:\n' + text.substring(0, 500));
        location.reload();
        
    }catch(e){
        alert('Error: '+e.message);
        btn.disabled=false;
        btn.textContent='Process Email Queue Now';
    }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

