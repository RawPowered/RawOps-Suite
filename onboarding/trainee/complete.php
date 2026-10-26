<?php
require_once __DIR__ . '/../../required/config.php';
require_login_or_redirect('/login.php');

$uid = current_user_id();
$pdo = db();

// Check if training is actually complete
if (!is_training_complete($uid)) {
    flash_set('error', 'Please complete all required modules before proceeding.');
    redirect('/onboarding/trainee/');
}

// Check if agreements are signed
$stmt = $pdo->prepare('
    SELECT COUNT(DISTINCT at.agreement_type) as signed_count
    FROM agreement_templates at
    INNER JOIN user_agreements ua ON ua.agreement_template_id = at.id AND ua.user_id = ?
    WHERE at.is_active = 1 
    AND at.agreement_type IN ("nda", "non_compete", "ai_communications", "sms_consent", "email_consent", "phone_consent", "gmail_account")
');
$stmt->execute([$uid]);
$signedCount = $stmt->fetchColumn();

$requiredAgreements = 7; // NDA, Non-Compete, AI Comms, SMS, Email, Phone, Gmail
if ($signedCount < $requiredAgreements) {
    flash_set('error', 'Please complete all required legal agreements before certification.');
    redirect('/onboarding/trainee/agreements.php');
}

// Promote to sales agent
try {
    $pdo->beginTransaction();
    
    // Remove trainee role
    $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = 1');
    $stmt->execute([$uid]);
    
    // Add sales agent role (keep any existing roles like admin)
    $stmt = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 2)');
    $stmt->execute([$uid]);
    
    // Update user status to active
    $stmt = $pdo->prepare('UPDATE users SET status = "active" WHERE id = ?');
    $stmt->execute([$uid]);
    
    $pdo->commit();
    
    flash_set('success', 'Congratulations! You are now a certified Product Sales Agent. Welcome to the ACP!');
    redirect('/onboarding/sales-agent/');
    
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'Unable to complete certification. Please contact support.');
    redirect('/onboarding/trainee/');
}
?>