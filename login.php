<?php 
$SEO = [
    'title' => 'Login — Product‑It',
    'description' => 'Access wholesale ordering, training, and the Sales ACP.',
    'type' => 'website'
];

require_once __DIR__ . '/required/config.php';

// If already logged in, send to the user dashboard immediately
$existing = current_user_id();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $existing) {
    redirect(user_dashboard_path($existing));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $accountType = trim($_POST['account_type'] ?? '');
    
    $uid = login_user($email, $password);
    if ($uid) {
        // Check if password reset is required (must be done before any other redirects)
        if (!empty($_SESSION['must_reset_password'])) {
            redirect(path_with_base('/reset-required-password.php'));
        }
        
        // Verify user has the selected role if account_type was specified
        if ($accountType && !user_has_role($uid, $accountType)) {
            // Special handling for customer type (wholesale account check)
            if ($accountType === 'customer') {
                try {
                    $stmt = db()->prepare('SELECT 1 FROM wholesale_accounts WHERE user_id = ? AND approved = 1 LIMIT 1');
                    $stmt->execute([$uid]);
                    if (!$stmt->fetch()) {
                        $error = 'No approved wholesale account found for this email.';
                        session_destroy();
                        goto show_form;
                    }
                } catch (Throwable $e) {
                    $error = 'Unable to verify wholesale account.';
                    session_destroy();
                    goto show_form;
                }
            } else {
                $error = 'This account does not have ' . ucfirst(str_replace('_', ' ', $accountType)) . ' access.';
                session_destroy();
                goto show_form;
            }
        }
        // Check for legal return URL
        if (isset($_SESSION['legal_return_to'])) {
            $returnTo = $_SESSION['legal_return_to'];
            unset($_SESSION['legal_return_to']);
            redirect($returnTo);
        }
        
        redirect(user_dashboard_path($uid));
    }
    $error = 'Invalid email or password.';
    try { $pdo = db(); $ae = $pdo->prepare('INSERT INTO auth_events (user_id, email, event_type, ip_address, user_agent, metadata) VALUES (NULL, ?, "login_failed", ?, ?, NULL)'); $ae->execute([strtolower($email), $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]); } catch (Throwable $e) {}
}

show_form:

require_once __DIR__ . '/required/header.php'; 
?>

<section class="hero" aria-labelledby="login-title" style="padding-top:40px;padding-bottom:40px;">
    <div class="container grid two" style="align-items:center;gap:32px;">
        <div>
            <h1 id="login-title" style="margin-top:0;color:var(--blue);">Welcome back</h1>
            <p class="muted" style="max-width:620px">Sign in to manage wholesale orders, track commissions, and continue training. Built for healthcare‑grade reliability and sales performance.</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;">
                <span class="stat-pill">99.9% quality information Verified</span>
                <span class="stat-pill">AKA GMP Standards</span>
                <span class="stat-pill">Secure & Compliant</span>
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:12px;">
                Market Outlook: Product \$2.19B (2024) → \$7.80B (2032), 17.2% CAGR — <a href="https://www.maximizemarketresearch.com/market-report/product-market/216883/" target="_blank" rel="noopener" style="color:var(--blue-2);text-decoration:underline;">Maximize Market Research (2025)</a>.
            </div>
        </div>
        <div>
            <div class="card" style="max-width: 520px; margin: 0 auto;">
                <h2 style="margin-top:0;text-align:left;color:var(--blue)">Sign in</h2>
                <?php if ($msg = flash_get('success')): ?>
                    <p style="color:#065f46;background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo sanitize($msg); ?></p>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                    <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo sanitize($error); ?></p>
                <?php endif; ?>
                <form method="post" autocomplete="on">
                    <div class="form-grid">
                        <label style="grid-column: 1/-1;">
                            <span>Email</span>
                            <input type="email" name="email" value="<?php echo isset($email) ? sanitize($email) : ''; ?>" autocomplete="email" required>
                        </label>
                        <label style="grid-column: 1/-1;">
                            <span>Password</span>
                            <input type="password" name="password" autocomplete="current-password" required>
                        </label>
                    </div>
                    <button class="btn" type="submit" style="width: 100%; margin-top: 16px;">Sign In</button>
                    <div style="text-align:center;margin-top:12px;">
                        <a href="<?php echo outbound_path('/forgot-password.php'); ?>" style="color:var(--blue-2);text-decoration:none;font-size:14px;">Forgot your password?</a>
                    </div>
                </form>
                <div style="margin-top: 16px; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                    <a href="<?php echo outbound_path('/index.php?role=investor#hero-title'); ?>" class="btn secondary">Investor Access</a>
                    <a href="<?php echo outbound_path('/index.php#hero-title'); ?>" class="btn secondary">Apply for Access</a>
                    <a href="<?php echo outbound_path('/onboarding/trainee/index.php'); ?>" class="btn secondary">Continue Training</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/required/footer.php'; ?>