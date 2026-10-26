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
    $targetUserId = intval($_POST['user_id'] ?? 0);
    
    if (!$targetUserId) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        exit;
    }
    
    try {
        $pdo = db();
        
        // Verify target user exists
        $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, status FROM users WHERE id = ?');
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        if ($targetUser['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => 'Cannot login as inactive user']);
            exit;
        }
        
        // Store admin info for return path
        $_SESSION['admin_impersonation'] = [
            'original_admin_id' => current_user_id(),
            'target_user_id' => $targetUserId,
            'started_at' => time()
        ];
        
        // Log the impersonation for audit trail
        $stmt = $pdo->prepare('
            INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, ip_address, user_agent) 
            VALUES (?, ?, "login_as_user", ?, ?, ?)
        ');
        $stmt->execute([
            current_user_id(),
            $targetUserId,
            "Admin logged in as user: {$targetUser['first_name']} {$targetUser['last_name']} ({$targetUser['email']})",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Switch to target user
        $_SESSION['user_id'] = $targetUserId;
        
        // Determine redirect based on user roles
        $stmt = $pdo->prepare('
            SELECT r.name 
            FROM user_roles ur 
            JOIN roles r ON r.id = ur.role_id 
            WHERE ur.user_id = ?
        ');
        $stmt->execute([$targetUserId]);
        $userRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Redirect to appropriate dashboard
        if (in_array('admin', $userRoles)) {
            $redirectUrl = '/admin/';
        } elseif (in_array('sales_agent', $userRoles)) {
            $redirectUrl = '/admin/'; // Sales agents can access admin tools
        } elseif (in_array('trainee', $userRoles)) {
            $redirectUrl = '/onboarding/trainee/';
        } elseif (in_array('customer', $userRoles)) {
            $redirectUrl = '/customer/';
        } else {
            $redirectUrl = '/';
        }
        
        echo json_encode(['success' => true, 'redirect_url' => $redirectUrl]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
