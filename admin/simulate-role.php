<?php
require_once __DIR__ . '/../required/config.php';
start_session();

// Check if user is admin
if (!in_array('admin', user_roles(current_user_id()) ?? [])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    $targetUserId = intval($_POST['user_id'] ?? 0);
    
    if (!in_array($role, ['admin', 'sales_agent', 'trainee', 'customer'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid role']);
        exit;
    }
    
    // Store simulation data in session
    $_SESSION['admin_simulation'] = [
        'original_user_id' => current_user_id(),
        'simulated_role' => $role,
        'target_user_id' => $targetUserId,
        'started_at' => time()
    ];
    
    echo json_encode(['success' => true, 'role' => $role]);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
