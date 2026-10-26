<?php
/**
 * Change Email Request API
 * Initiates email change with verification token
 */

require_once __DIR__ . '/../../required/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

start_session();
$uid = current_user_id();

if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $newEmail = trim($_POST['new_email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate inputs
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit;
    }
    
    if (empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Password is required']);
        exit;
    }
    
    $pdo = db();
    
    // Get current user info
    $stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        exit;
    }
    
    // Check if new email is the same as current
    if (strtolower($newEmail) === strtolower($user['email'])) {
        echo json_encode(['success' => false, 'error' => 'New email must be different from current email']);
        exit;
    }
    
    // Check if new email is already in use
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?');
    $stmt->execute([$newEmail, $uid]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'This email address is already in use']);
        exit;
    }
    
    // Rate limit: Check for recent requests (within last hour)
    $stmt = $pdo->prepare('
        SELECT COUNT(*) as recent_count 
        FROM email_change_requests 
        WHERE user_id = ? 
        AND requested_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND status = "pending"
    ');
    $stmt->execute([$uid]);
    $result = $stmt->fetch();
    if ($result['recent_count'] > 0) {
        echo json_encode(['success' => false, 'error' => 'Please wait before requesting another email change. Check your inbox for the previous verification email.']);
        exit;
    }
    
    // Cancel any pending email change requests for this user
    $stmt = $pdo->prepare('
        UPDATE email_change_requests 
        SET status = "cancelled" 
        WHERE user_id = ? AND status = "pending"
    ');
    $stmt->execute([$uid]);
    
    // Generate secure verification token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Insert email change request
    $stmt = $pdo->prepare('
        INSERT INTO email_change_requests 
        (user_id, old_email, new_email, verification_token, expires_at, ip_address, user_agent, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, "pending")
    ');
    $stmt->execute([
        $uid,
        $user['email'],
        $newEmail,
        $token,
        $expiresAt,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
    
    // Send verification email to NEW address
    $verifyUrl = site_origin() . outbound_path('/api/user/verify-email-change.php?token=' . urlencode($token));
    
    $emailSubject = 'Verify Your Email Change — Product';
    $emailBody = '
        <h2>Verify Your Email Change</h2>
        <p>You requested to change your email address from <strong>' . htmlspecialchars($user['email']) . '</strong> to <strong>' . htmlspecialchars($newEmail) . '</strong>.</p>
        <p>To complete this change, please click the link below:</p>
        <p><a href="' . htmlspecialchars($verifyUrl) . '" style="display:inline-block;padding:12px 24px;background:#1e40af;color:white;text-decoration:none;border-radius:6px;font-weight:600;">Verify Email Change</a></p>
        <p>Or copy this link into your browser:<br>' . htmlspecialchars($verifyUrl) . '</p>
        <p><strong>This link will expire in 24 hours.</strong></p>
        <p>If you did not request this change, please ignore this email and your email address will remain unchanged.</p>
    ';
    
    // Send email using SendGrid
    try {
        require_once __DIR__ . '/../sendgrid.php';
        $result = sendgrid_send([
            'to' => $newEmail,
            'subject' => $emailSubject,
            'html' => $emailBody
        ]);
        if (!$result['ok']) {
            error_log('Failed to send email change verification: ' . ($result['error'] ?? 'Unknown error'));
        }
    } catch (Throwable $e) {
        error_log('Failed to send email change verification: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Verification email sent to ' . $newEmail . '. Please check your inbox and click the verification link to complete the change.'
    ]);
    
} catch (Throwable $e) {
    error_log('Email change request error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}

