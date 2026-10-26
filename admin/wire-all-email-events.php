<?php
/**
 * Complete Email Event Setup
 * Wires all critical events to their appropriate templates
 */

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect(path_with_base('/admin/'));

header('Content-Type: application/json');

$pdo = db();
$results = ['setup' => [], 'errors' => []];

try {
    // Get available templates
    $templates = $pdo->query("SELECT id, slug, name FROM email_templates WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $templatesBySlug = [];
    foreach ($templates as $t) {
        $templatesBySlug[$t['slug']] = $t;
    }
    
    // Define event → template mappings
    $eventMappings = [
        'new_user_registration' => 'welcome-general-sendgrid',
        'first_signup' => 'welcome-general-sendgrid',  // Code uses this
        'new_account' => 'welcome-general-sendgrid',
        'first_purchase' => 'first-purchase',
        'new_invoice' => 'new-invoice',  // Code uses this
        'new_invoice_customer' => 'new-invoice',
        'new_invoice_admin' => 'new-invoice',
    ];
    
    foreach ($eventMappings as $eventKey => $templateSlug) {
        // Check if template exists
        if (!isset($templatesBySlug[$templateSlug])) {
            $results['errors'][] = "Template '$templateSlug' not found for event '$eventKey'";
            continue;
        }
        
        $templateId = $templatesBySlug[$templateSlug]['id'];
        
        // Check if rule already exists
        $check = $pdo->prepare("SELECT id, is_active FROM email_notification_rules WHERE event_key = ?");
        $check->execute([$eventKey]);
        $existing = $check->fetch();
        
        if ($existing) {
            // Update existing rule
            $pdo->prepare("UPDATE email_notification_rules SET template_id = ?, is_active = 1, updated_at = NOW() WHERE id = ?")
                ->execute([$templateId, $existing['id']]);
            $results['setup'][] = "Updated '$eventKey' → '{$templatesBySlug[$templateSlug]['name']}'";
        } else {
            // Create new rule
            $pdo->prepare("INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at) VALUES (?, ?, 1, NOW())")
                ->execute([$eventKey, $templateId]);
            $results['setup'][] = "Created '$eventKey' → '{$templatesBySlug[$templateSlug]['name']}'";
        }
    }
    
    // Summary
    $totalRules = $pdo->query("SELECT COUNT(*) FROM email_notification_rules WHERE is_active = 1")->fetchColumn();
    $results['summary'] = [
        'active_rules' => $totalRules,
        'templates_available' => count($templates),
        'events_available' => $pdo->query("SELECT COUNT(*) FROM email_events WHERE is_active = 1")->fetchColumn()
    ];
    
    $results['ok'] = true;
    
} catch (Throwable $e) {
    $results['ok'] = false;
    $results['error'] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>

