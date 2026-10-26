<?php
/**
 * Change Password API Endpoint (for logged-in users)
 * Requires current password verification
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../required/config.php';

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check authentication
start_session();
$uid = current_user_id();

if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

// Validation
if (!$currentPassword || !$newPassword || !$confirmPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'All password fields are required']);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'New password must be at least 8 characters long']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'New passwords do not match']);
    exit;
}

if ($currentPassword === $newPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'New password must be different from current password']);
    exit;
}

try {
    $pdo = db();
    
    // Get current user
    $stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    // Verify current password
    if (!password_verify($currentPassword, $user['password_hash'])) {
        // Log failed attempt
        try {
            $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (?, ?, "password_reset", ?, ?, ?)');
            $ae->execute([
                $uid,
                strtolower($user['email']),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                json_encode(['status' => 'failed', 'reason' => 'incorrect_current_password'])
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log password change attempt: ' . $e->getMessage());
        }
        
        http_response_code(400);
        echo json_encode(['error' => 'Current password is incorrect']);
        exit;
    }
    
    // Update password
    $pdo->beginTransaction();
    
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
    
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$passwordHash, $uid]);
    
    // Log password change
    $stmt = $pdo->prepare('
        INSERT INTO user_profile_updates 
        (user_id, field_name, old_value, new_value, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $uid,
        'password_hash',
        '[REDACTED]',
        '[REDACTED]',
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
    
    // Log auth event
    try {
        $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (?, ?, "password_reset", ?, ?, ?)');
        $ae->execute([
            $uid,
            strtolower($user['email']),
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            json_encode(['status' => 'success', 'method' => 'logged_in_change'])
        ]);
    } catch (Throwable $e) {
        error_log('Failed to log password change event: ' . $e->getMessage());
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully'
    ]);
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Change password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to change password']);
}



