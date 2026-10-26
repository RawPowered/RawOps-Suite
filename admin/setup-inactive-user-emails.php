<?php
/**
 * Setup script for inactive user email automation
 * Creates email notification rules linking events to templates
 */

require_once __DIR__ . '/../required/config.php';

$SEO = ['title' => 'Setup Inactive User Emails', 'description' => 'Setup inactive user email automation', 'type' => 'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$success_messages = [];
$error_messages = [];

// Run setup if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_emails'])) {
    try {
        // Template to event mappings
        $email_mappings = [
            'inactive-user-3-days' => 'user_inactive_3_days',
            'inactive-user-7-days' => 'user_inactive_7_days', 
            'inactive-user-14-days' => 'user_inactive_14_days',
            'inactive-user-30-days' => 'user_inactive_30_days',
            'inactive-user-35-days-suspension' => 'user_inactive_35_days'
        ];
        
        foreach ($email_mappings as $template_slug => $event_key) {
            // Get template ID
            $template_stmt = $pdo->prepare('SELECT id FROM email_templates WHERE slug = ?');
            $template_stmt->execute([$template_slug]);
            $template_id = $template_stmt->fetchColumn();
            
            if (!$template_id) {
                $error_messages[] = "Template '{$template_slug}' not found. Please run email automation setup first.";
                continue;
            }
            
            // Check if rule already exists
            $rule_check = $pdo->prepare('SELECT id FROM email_notification_rules WHERE event_key = ?');
            $rule_check->execute([$event_key]);
            $existing_rule = $rule_check->fetchColumn();
            
            if ($existing_rule) {
                // Update existing rule
                $update_stmt = $pdo->prepare('
                    UPDATE email_notification_rules 
                    SET template_id = ?, is_active = 1, updated_at = NOW()
                    WHERE event_key = ?
                ');
                $update_stmt->execute([$template_id, $event_key]);
                $success_messages[] = "Updated rule for event '{$event_key}' with template '{$template_slug}'";
            } else {
                // Create new rule
                $insert_stmt = $pdo->prepare('
                    INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at, updated_at)
                    VALUES (?, ?, 1, NOW(), NOW())
                ');
                $insert_stmt->execute([$event_key, $template_id]);
                $success_messages[] = "Created rule for event '{$event_key}' with template '{$template_slug}'";
            }
        }
        
        if (empty($error_messages)) {
            $success_messages[] = "All inactive user email automation rules have been set up successfully!";
        }
        
    } catch (Exception $e) {
        $error_messages[] = "Setup error: " . $e->getMessage();
    }
}

// Check current status
$status_check = [];
$email_events = [
    'user_inactive_3_days' => 'User Inactive 3 Days',
    'user_inactive_7_days' => 'User Inactive 7 Days', 
    'user_inactive_14_days' => 'User Inactive 14 Days',
    'user_inactive_30_days' => 'User Inactive 30 Days',
    'user_inactive_35_days' => 'User Inactive 35 Days - Suspension Notice'
];

foreach ($email_events as $event_key => $event_name) {
    $stmt = $pdo->prepare('
        SELECT 
            e.event_key,
            e.name as event_name,
            e.is_active as event_active,
            r.id as rule_id,
            r.is_active as rule_active,
            r.template_id,
            t.name as template_name,
            t.slug as template_slug
        FROM email_events e
        LEFT JOIN email_notification_rules r ON r.event_key = e.event_key
        LEFT JOIN email_templates t ON t.id = r.template_id
        WHERE e.event_key = ?
    ');
    $stmt->execute([$event_key]);
    $status_check[$event_key] = $stmt->fetch(PDO::FETCH_ASSOC);
}

?>

<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 24px; }
.container { max-width: 1000px; margin: 0 auto; }
.card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; overflow: hidden; }
.card-header { background: linear-gradient(135deg, #0ea5e9, #3b82f6); color: #fff; padding: 24px; }
.card-body { padding: 24px; }
.btn { background: #2563eb; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; }
.btn:hover { background: #1d4ed8; }
.btn-success { background: #16a34a; }
.btn-success:hover { background: #15803d; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f1f5f9; }
th { background: #f8fafc; font-weight: 600; }
.status-good { color: #16a34a; font-weight: 600; }
.status-missing { color: #dc2626; font-weight: 600; }
.status-inactive { color: #f59e0b; font-weight: 600; }
</style>

<div class="container">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h1 style="margin: 0;">📧 Inactive User Email Setup</h1>
            <p style="margin: 8px 0 0; opacity: 0.9;">Configure email automation for inactive users (3, 7, 14, 30, 35 days)</p>
        </div>
        
        <div class="card-body">
            <?php if (!empty($success_messages)): ?>
                <?php foreach ($success_messages as $message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($error_messages)): ?>
                <?php foreach ($error_messages as $message): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($message); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <h2>Current Status</h2>
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Event Status</th>
                        <th>Template Assigned</th>
                        <th>Rule Status</th>
                        <th>Overall Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($status_check as $event_key => $status): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($status['event_name'] ?? $email_events[$event_key]); ?></strong>
                                <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($event_key); ?></div>
                            </td>
                            <td>
                                <?php if ($status && $status['event_active']): ?>
                                    <span class="status-good">✓ Active</span>
                                <?php elseif ($status): ?>
                                    <span class="status-inactive">⚠ Inactive</span>
                                <?php else: ?>
                                    <span class="status-missing">✗ Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status && $status['template_name']): ?>
                                    <strong><?php echo htmlspecialchars($status['template_name']); ?></strong>
                                    <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($status['template_slug']); ?></div>
                                <?php else: ?>
                                    <span class="status-missing">No template assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status && $status['rule_id']): ?>
                                    <?php if ($status['rule_active']): ?>
                                        <span class="status-good">✓ Active Rule</span>
                                    <?php else: ?>
                                        <span class="status-inactive">⚠ Inactive Rule</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-missing">✗ No Rule</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $is_complete = $status && $status['event_active'] && $status['rule_id'] && $status['rule_active'] && $status['template_id'];
                                ?>
                                <?php if ($is_complete): ?>
                                    <span class="status-good">✓ Ready</span>
                                <?php else: ?>
                                    <span class="status-missing">✗ Needs Setup</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 32px;">
                <h2>Setup Actions</h2>
                <p>This will create or update email notification rules to link the inactive user events with their corresponding email templates.</p>
                
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 16px 0;">
                    <h3 style="margin: 0 0 8px 0; color: #166534;">Prerequisites</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>Email templates must be created (run Email Automation V2 setup first)</li>
                        <li>Email events must exist in the database (created by migration)</li>
                        <li>SendGrid API key must be configured</li>
                    </ul>
                </div>
                
                <form method="post">
                    <button type="submit" name="setup_emails" class="btn btn-success" style="font-size: 16px; padding: 16px 32px;">
                        🚀 Setup Inactive User Email Automation
                    </button>
                </form>
            </div>
            
            <div style="margin-top: 32px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px;">
                <h3 style="margin: 0 0 8px 0; color: #92400e;">Automation Schedule</h3>
                <p style="margin: 0 0 12px 0;">To run the automation daily, add this cron job:</p>
                <code style="background: #fff; padding: 8px 12px; border-radius: 4px; display: block; font-family: monospace;">
                    0 9 * * * /usr/bin/php <?php echo __DIR__; ?>/../required/inactive-user-automation.php
                </code>
                <p style="margin: 12px 0 0 0; font-size: 14px; color: #92400e;">
                    This will run daily at 9 AM and check for inactive users, sending appropriate emails and managing suspensions.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>











