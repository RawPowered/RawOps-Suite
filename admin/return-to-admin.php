<?php
require_once __DIR__ . '/../required/config.php';
start_session();

// Check if admin impersonation is active
if (!isset($_SESSION['admin_impersonation'])) {
    flash_set('error', 'No impersonation session found.');
    redirect('/admin/');
}

$impersonation = $_SESSION['admin_impersonation'];
$originalAdminId = $impersonation['original_admin_id'];
$targetUserId = $impersonation['target_user_id'];

try {
    $pdo = db();
    
    // Log the return action
    $stmt = $pdo->prepare('
        INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, ip_address, user_agent) 
        VALUES (?, ?, "return_from_impersonation", ?, ?, ?)
    ');
    $stmt->execute([
        $originalAdminId,
        $targetUserId,
        "Admin returned from impersonating user ID: {$targetUserId}",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    // Restore admin session
    $_SESSION['user_id'] = $originalAdminId;
    unset($_SESSION['admin_impersonation']);
    
    flash_set('success', 'Returned to admin account successfully.');
    redirect('/admin/user-detail.php?id=' . $targetUserId);
    
} catch (Exception $e) {
    flash_set('error', 'Error returning to admin: ' . $e->getMessage());
    redirect('/admin/');
}
?>
