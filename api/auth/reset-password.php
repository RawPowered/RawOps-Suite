<?php
/**
 * Password Reset Verification and Completion Endpoint
 * Handles both GET (show form) and POST (process reset)
 */

require_once __DIR__ . '/../../required/config.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null;
$success = false;
$expired = false;
$user = null;

if (!$token) {
    $error = 'Invalid or missing reset token.';
} else {
    try {
        $pdo = db();
        
        // Verify token
        $stmt = $pdo->prepare('
            SELECT prt.*, u.first_name, u.email, u.status
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.token = ? AND prt.used_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $tokenData = $stmt->fetch();
        
        if (!$tokenData) {
            $error = 'Invalid or already used reset token.';
        } elseif (strtotime($tokenData['expires_at']) < time()) {
            $expired = true;
            $error = 'This reset link has expired. Please request a new password reset.';
        } elseif ($tokenData['status'] === 'suspended') {
            $error = 'This account is suspended. Please contact support.';
        } else {
            $user = $tokenData;
        }
    } catch (Throwable $e) {
        error_log('Password reset verification error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

// Process password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && !$error) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (!$newPassword || strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Hash new password
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
            
            // Update user password
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$passwordHash, $user['user_id']]);
            
            // Mark token as used
            $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?');
            $stmt->execute([$user['id']]);
            
            // Log password reset event
            try {
                $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (?, ?, "password_reset", ?, ?, ?)');
                $ae->execute([
                    $user['user_id'],
                    strtolower($user['email']),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    json_encode(['token_id' => $user['id']])
                ]);
            } catch (Throwable $e) {
                error_log('Failed to log password reset event: ' . $e->getMessage());
            }
            
            $pdo->commit();
            $success = true;
            
            // Set flash message and redirect to login
            flash_set('success', 'Your password has been reset successfully. Please log in with your new password.');
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password reset error: ' . $e->getMessage());
            $error = 'An error occurred resetting your password. Please try again.';
        }
    }
}

// If success, redirect to login
if ($success) {
    redirect('/login.php');
    exit;
}

// Show reset form
$SEO = [
    'title' => 'Reset Password — Product‑It',
    'description' => 'Reset your password',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
?>

<section class="hero" style="padding-top:40px;padding-bottom:40px;">
    <div class="container" style="max-width:600px;">
        <div class="card">
            <h1 style="margin-top:0;color:var(--blue);">Reset Your Password</h1>
            
            <?php if ($error): ?>
                <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px">
                    <?php echo sanitize($error); ?>
                </p>
                
                <?php if ($expired): ?>
                    <p style="text-align:center;">
                        <a href="<?php echo outbound_path('/login.php'); ?>" class="btn">Back to Login</a>
                    </p>
                <?php endif; ?>
                
            <?php elseif ($user): ?>
                <p class="muted">Hi <?php echo sanitize($user['first_name']); ?>, enter your new password below.</p>
                
                <form method="post" autocomplete="off">
                    <input type="hidden" name="token" value="<?php echo sanitize($token); ?>">
                    
                    <div class="form-grid">
                        <label style="grid-column: 1/-1;">
                            <span>New Password</span>
                            <input type="password" name="password" minlength="8" required autocomplete="new-password">
                            <small style="color:var(--muted);font-size:13px;">Minimum 8 characters</small>
                        </label>
                        
                        <label style="grid-column: 1/-1;">
                            <span>Confirm New Password</span>
                            <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
                        </label>
                    </div>
                    
                    <button type="submit" class="btn" style="width:100%;margin-top:16px;">Reset Password</button>
                </form>
                
                <p style="text-align:center;margin-top:16px;">
                    <a href="<?php echo outbound_path('/login.php'); ?>" style="color:var(--blue-2);">Back to Login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>



