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
    $userId = intval($_POST['user_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if (!$userId || empty($message)) {
        echo json_encode(['success' => false, 'error' => 'User ID and message required']);
        exit;
    }
    
    try {
        $pdo = db();
        
        // Create admin alert/message for the user
        $stmt = $pdo->prepare('
            INSERT INTO training_admin_alerts (user_id, alert_type, alert_message, severity) 
            VALUES (?, "admin_message", ?, "medium")
        ');
        $stmt->execute([$userId, $message]);
        
        // Log the action
        $stmt = $pdo->prepare('
            INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, ip_address, user_agent) 
            VALUES (?, ?, "send_message", ?, ?, ?)
        ');
        $stmt->execute([
            current_user_id(),
            $userId,
            "Sent message: " . substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
