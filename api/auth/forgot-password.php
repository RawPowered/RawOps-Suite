<?php
/**
 * Forgot Password Request Endpoint
 * Generates a secure token and sends password reset email via SendGrid
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../sendgrid.php';

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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim($input['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid email address required']);
    exit;
}

try {
    $pdo = db();
    
    // Find user by email (case-insensitive)
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, status FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    // Always return success to prevent email enumeration attacks
    // But only send email if user exists
    if ($user) {
        // Check if user is suspended
        if ($user['status'] === 'suspended') {
            // Log the attempt but don't reveal user is suspended
            try {
                $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (?, ?, "forgot_password", ?, ?, ?)');
                $ae->execute([
                    $user['id'],
                    strtolower($email),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    json_encode(['status' => 'suspended_account'])
                ]);
            } catch (Throwable $e) {
                error_log('Failed to log suspended account password reset attempt: ' . $e->getMessage());
            }
            
            // Return success but don't send email
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'If an account exists with that email, a password reset link has been sent.'
            ]);
            exit;
        }
        
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        
        // Store token
        $stmt = $pdo->prepare('
            INSERT INTO password_reset_tokens (user_id, email, token, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['id'],
            strtolower($email),
            $token,
            $expiresAt,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
        
        // Log the event
        try {
            $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (?, ?, "forgot_password", ?, ?, ?)');
            $ae->execute([
                $user['id'],
                strtolower($email),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                json_encode(['token_id' => $pdo->lastInsertId()])
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log forgot password event: ' . $e->getMessage());
        }
        
        // Generate reset URL
        $resetUrl = site_base_url() . '/api/auth/reset-password.php?token=' . urlencode($token);
        
        // Send email via SendGrid
        $emailResult = sendgrid_send([
            'to' => $email,
            'to_name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'from' => 'sample-006@example.com',
            'from_name' => 'Product',
            'subject' => 'Password Reset Request',
            'template' => 'password-reset',
            'first_name' => $user['first_name'],
            'reset_url' => $resetUrl,
            'expires_minutes' => 60,
            'base_url' => site_base_url()
        ]);
        
        if (!$emailResult['ok']) {
            error_log('Failed to send password reset email: ' . ($emailResult['error'] ?? 'Unknown error'));
            // Don't reveal email sending failure to user (security)
        }
    } else {
        // User not found - log generic failed attempt
        try {
            $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (NULL, ?, "forgot_password", ?, ?, ?)');
            $ae->execute([
                strtolower($email),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                json_encode(['status' => 'email_not_found'])
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log failed password reset attempt: ' . $e->getMessage());
        }
    }
    
    // Always return success message (prevent email enumeration)
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'If an account exists with that email, a password reset link has been sent.'
    ]);
    
} catch (Throwable $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred processing your request']);
}

