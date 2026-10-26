<?php
/**
 * Quick setup script to wire default templates to events
 */

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect(path_with_base('/admin/'));

header('Content-Type: application/json');

$pdo = db();
$results = [];

try {
    // Check what templates exist
    $templates = $pdo->query("SELECT id, slug, name, category FROM email_templates WHERE is_active = 1")->fetchAll();
    $results['templates'] = $templates;
    
    // Check what events exist
    $events = $pdo->query("SELECT event_key, name, scope FROM email_events WHERE is_active = 1")->fetchAll();
    $results['events'] = $events;
    
    // Check current rules
    $rules = $pdo->query("SELECT r.event_key, r.template_id, r.is_active, t.name as template_name 
                          FROM email_notification_rules r 
                          LEFT JOIN email_templates t ON t.id = r.template_id")->fetchAll();
    $results['rules'] = $rules;
    
    // Auto-wire welcome template if exists and not already wired
    $setupActions = [];
    
    // Find welcome template
    $welcomeTemplate = null;
    foreach ($templates as $t) {
        if ($t['slug'] === 'welcome-email-template-sendgrid' || $t['slug'] === 'welcome-general-sendgrid') {
            $welcomeTemplate = $t;
            break;
        }
    }
    
    if ($welcomeTemplate) {
        // Check if new_user_registration has a rule
        $hasRule = false;
        foreach ($rules as $r) {
            if ($r['event_key'] === 'new_user_registration') {
                $hasRule = true;
                break;
            }
        }
        
        if (!$hasRule) {
            $pdo->prepare("INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at) VALUES (?, ?, 1, NOW())")
                ->execute(['new_user_registration', $welcomeTemplate['id']]);
            $setupActions[] = "Assigned '{$welcomeTemplate['name']}' to new_user_registration event";
        } else {
            $setupActions[] = "new_user_registration already has a rule configured";
        }
    } else {
        $setupActions[] = "No welcome template found - create one first";
    }
    
    $results['setup_actions'] = $setupActions;
    $results['ok'] = true;
    
} catch (Throwable $e) {
    $results['ok'] = false;
    $results['error'] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);

