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
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        exit;
    }
    
    try {
        $pdo = db();
        
        // Get user info for logging
        $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        // Begin transaction for data integrity
        $pdo->beginTransaction();
        
        // Delete training progress
        $stmt = $pdo->prepare('DELETE FROM training_progress WHERE user_id = ?');
        $stmt->execute([$userId]);
        $progressDeleted = $stmt->rowCount();
        
        // Delete quiz results
        $stmt = $pdo->prepare('DELETE FROM training_quiz_results WHERE user_id = ?');
        $stmt->execute([$userId]);
        $quizDeleted = $stmt->rowCount();
        
        // Delete training analytics
        $stmt = $pdo->prepare('DELETE FROM training_module_analytics WHERE user_id = ?');
        $stmt->execute([$userId]);
        
        // Delete active sessions
        $stmt = $pdo->prepare('DELETE FROM training_active_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        
        // Delete time tracking
        $stmt = $pdo->prepare('DELETE FROM training_time_tracking WHERE user_id = ?');
        $stmt->execute([$userId]);
        
        // Log the action
        $stmt = $pdo->prepare('
            INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, ip_address, user_agent) 
            VALUES (?, ?, "reset_training", ?, ?, ?)
        ');
        $stmt->execute([
            current_user_id(),
            $userId,
            "Reset training progress for {$user['first_name']} {$user['last_name']} - {$progressDeleted} progress records, {$quizDeleted} quiz results deleted",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "Training reset complete. {$progressDeleted} progress records and {$quizDeleted} quiz results deleted."
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
