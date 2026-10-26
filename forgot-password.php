<?php 
$SEO = [
    'title' => 'Forgot Password — Product‑It',
    'description' => 'Reset your password to access your account.',
    'type' => 'website'
];

require_once __DIR__ . '/required/config.php';

// If already logged in, redirect to dashboard
$existing = current_user_id();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $existing) {
    redirect(user_dashboard_path($existing));
}

$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Call the API endpoint
        $apiUrl = site_base_url() . '/api/auth/forgot-password.php';
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['email' => $email]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $success = true;
        } else {
            $error = 'An error occurred. Please try again.';
        }
    }
}

require_once __DIR__ . '/required/header.php'; 
?>

<section class="hero" aria-labelledby="forgot-password-title" style="padding-top:40px;padding-bottom:40px;">
    <div class="container" style="max-width:600px;">
        <div class="card">
            <h1 id="forgot-password-title" style="margin-top:0;color:var(--blue);">Forgot Password?</h1>
            
            <?php if ($success): ?>
                <p style="color:#065f46;background:#f0fdf4;border:1px solid #86efac;padding:16px;border-radius:8px;margin-bottom:20px">
                    <strong>Check your email!</strong><br>
                    If an account exists with that email address, we've sent you a password reset link. Please check your inbox and spam folder.
                </p>
                <p style="text-align:center;">
                    <a href="<?php echo outbound_path('/login.php'); ?>" class="btn">Back to Login</a>
                </p>
            <?php else: ?>
                <p class="muted">Enter your email address and we'll send you a link to reset your password.</p>
                
                <?php if ($error): ?>
                    <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px">
                        <?php echo sanitize($error); ?>
                    </p>
                <?php endif; ?>
                
                <form method="post" autocomplete="on">
                    <div class="form-grid">
                        <label style="grid-column: 1/-1;">
                            <span>Email Address</span>
                            <input type="email" name="email" value="<?php echo isset($email) ? sanitize($email) : ''; ?>" autocomplete="email" required autofocus>
                        </label>
                    </div>
                    
                    <button class="btn" type="submit" style="width: 100%; margin-top: 16px;">Send Reset Link</button>
                </form>
                
                <p style="text-align:center;margin-top:16px;">
                    <a href="<?php echo outbound_path('/login.php'); ?>" style="color:var(--blue-2);">Back to Login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/required/footer.php'; ?>



