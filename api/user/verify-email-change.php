<?php
/**
 * Verify Email Change API
 * Processes email change verification token
 */

require_once __DIR__ . '/../../required/config.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Invalid Link</title></head><body>';
    echo '<h1>Invalid Verification Link</h1>';
    echo '<p>The verification link is invalid or incomplete.</p>';
    echo '<p><a href="' . outbound_path('/profile.php') . '">Return to Profile</a></p>';
    echo '</body></html>';
    exit;
}

try {
    $pdo = db();
    
    // Find the email change request
    $stmt = $pdo->prepare('
        SELECT * FROM email_change_requests 
        WHERE verification_token = ? 
        AND status = "pending"
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $request = $stmt->fetch();
    
    if (!$request) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>Invalid Token</title></head><body>';
        echo '<h1>Invalid or Expired Link</h1>';
        echo '<p>This verification link is invalid, has already been used, or has expired.</p>';
        echo '<p><a href="' . outbound_path('/profile.php') . '">Return to Profile</a></p>';
        echo '</body></html>';
        exit;
    }
    
    // Check if token has expired
    if (strtotime($request['expires_at']) < time()) {
        // Mark as expired
        $stmt = $pdo->prepare('UPDATE email_change_requests SET status = "expired" WHERE id = ?');
        $stmt->execute([$request['id']]);
        
        http_response_code(410);
        echo '<!DOCTYPE html><html><head><title>Link Expired</title></head><body>';
        echo '<h1>Verification Link Expired</h1>';
        echo '<p>This verification link has expired. Please request a new email change from your profile.</p>';
        echo '<p><a href="' . outbound_path('/profile.php') . '">Return to Profile</a></p>';
        echo '</body></html>';
        exit;
    }
    
    // Verify the new email is still available
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?');
    $stmt->execute([$request['new_email'], $request['user_id']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo '<!DOCTYPE html><html><head><title>Email Unavailable</title></head><body>';
        echo '<h1>Email Address Unavailable</h1>';
        echo '<p>The email address is no longer available. Please request a different email change.</p>';
        echo '<p><a href="' . outbound_path('/profile.php') . '">Return to Profile</a></p>';
        echo '</body></html>';
        exit;
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Update user email
        $stmt = $pdo->prepare('UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$request['new_email'], $request['user_id']]);
        
        // Log the change in user_profile_updates
        $stmt = $pdo->prepare('
            INSERT INTO user_profile_updates 
            (user_id, field_name, old_value, new_value, ip_address, user_agent)
            VALUES (?, "email", ?, ?, ?, ?)
        ');
        $stmt->execute([
            $request['user_id'],
            $request['old_email'],
            $request['new_email'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
        
        // Mark request as verified
        $stmt = $pdo->prepare('UPDATE email_change_requests SET status = "verified", verified_at = NOW() WHERE id = ?');
        $stmt->execute([$request['id']]);
        
        // Log auth event
        $stmt = $pdo->prepare('
            INSERT INTO auth_events 
            (user_id, email, event_type, ip_address, user_agent, metadata) 
            VALUES (?, ?, "email_changed", ?, ?, ?)
        ');
        $stmt->execute([
            $request['user_id'],
            $request['new_email'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            json_encode(['old_email' => $request['old_email'], 'new_email' => $request['new_email']])
        ]);
        
        $pdo->commit();
        
        // Send notification to OLD email
        $notificationSubject = 'Email Address Changed — Product';
        $notificationBody = '
            <h2>Your Email Address Was Changed</h2>
            <p>This is a confirmation that your Product account email address was changed from <strong>' . htmlspecialchars($request['old_email']) . '</strong> to <strong>' . htmlspecialchars($request['new_email']) . '</strong>.</p>
            <p>Date: ' . date('F j, Y g:i A') . '</p>
            <p>IP Address: ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . '</p>
            <p>If you did not make this change, please contact support immediately.</p>
        ';
        
        try {
            require_once __DIR__ . '/../sendgrid.php';
            $result = sendgrid_send([
                'to' => $request['old_email'],
                'subject' => $notificationSubject,
                'html' => $notificationBody
            ]);
            if (!$result['ok']) {
                error_log('Failed to send email change notification: ' . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Throwable $e) {
            error_log('Failed to send email change notification: ' . $e->getMessage());
        }
        
        // Update session with new email
        start_session();
        $_SESSION['email'] = $request['new_email'];
        
        // Success page
        echo '<!DOCTYPE html><html><head><title>Email Changed Successfully</title><style>
        body{font-family:system-ui,sans-serif;max-width:600px;margin:80px auto;padding:20px;text-align:center}
        h1{color:#059669}
        .btn{display:inline-block;padding:12px 24px;background:#1e40af;color:white;text-decoration:none;border-radius:6px;font-weight:600;margin-top:20px}
        </style></head><body>';
        echo '<h1>✓ Email Changed Successfully</h1>';
        echo '<p>Your email address has been successfully changed to:</p>';
        echo '<p style="font-size:18px;font-weight:600">' . htmlspecialchars($request['new_email']) . '</p>';
        echo '<p>A confirmation email has been sent to your old email address.</p>';
        echo '<a href="' . outbound_path('/profile.php') . '" class="btn">Return to Profile</a>';
        echo '</body></html>';
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('Email verification error: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h1>Something Went Wrong</h1>';
    echo '<p>An error occurred while processing your request. Please try again or contact support.</p>';
    echo '<p><a href="' . outbound_path('/profile.php') . '">Return to Profile</a></p>';
    echo '</body></html>';
}

