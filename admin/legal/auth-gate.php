<?php
$SEO = [
    'title' => 'Legal Portal Access — Product® Legal Management',
    'description' => 'Sign in or request access to the legal management system.',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';

start_session();

// Store the intended destination
$returnTo = $_GET['return'] ?? $_SERVER['REQUEST_URI'] ?? path_with_base('/admin/legal/');
$_SESSION['legal_return_to'] = $returnTo;

// If already logged in, redirect to appropriate portal
$currentUser = current_user_id();
if ($currentUser) {
    if (user_has_role($currentUser, 'admin')) {
        redirect(path_with_base('/admin/legal/index.php'));
    } elseif (user_has_role($currentUser, 'legal')) {
        redirect(path_with_base('/admin/legal/portal/'));
    } else {
        // Logged in but no legal access
        $showNoAccess = true;
    }
}
?>
<style>
.auth-gate-shell {
    max-width: 680px;
    margin: 80px auto;
    padding: 0 24px;
}
.auth-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 20px 48px rgba(15,23,42,0.15);
    overflow: hidden;
}
.auth-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 100%);
    color: #fff;
    padding: 48px 40px;
    text-align: center;
}
.auth-header h1 {
    margin: 0 0 12px 0;
    font-size: 2.2rem;
    font-weight: 800;
}
.auth-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 1.05rem;
}
.auth-body {
    padding: 40px;
}
.auth-options {
    display: grid;
    gap: 20px;
}
.auth-option {
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    padding: 28px;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
}
.auth-option:hover {
    border-color: #1d4ed8;
    box-shadow: 0 8px 20px rgba(29,78,216,0.15);
    transform: translateY(-2px);
}
.auth-option-icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.auth-option h2 {
    margin: 0 0 10px 0;
    font-size: 1.5rem;
    color: #0f172a;
}
.auth-option p {
    margin: 0 0 20px 0;
    color: #64748b;
    line-height: 1.6;
}
.btn {
    display: inline-block;
    padding: 14px 32px;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 1.05rem;
    text-decoration: none;
    transition: all 0.2s;
}
.btn-primary {
    background: #0d47a1;
    color: #fff;
}
.btn-primary:hover {
    background: #1e40af;
    box-shadow: 0 8px 20px rgba(13,71,161,0.3);
}
.btn-success {
    background: #10b981;
    color: #fff;
}
.btn-success:hover {
    background: #059669;
    box-shadow: 0 8px 20px rgba(16,185,129,0.3);
}
.no-access-notice {
    background: #fef3c7;
    border: 2px solid #fbbf24;
    border-radius: 12px;
    padding: 24px;
    text-align: center;
}
.no-access-notice h2 {
    margin: 0 0 12px 0;
    color: #92400e;
}
.no-access-notice p {
    margin: 0;
    color: #78350f;
    line-height: 1.7;
}
</style>

<div class="auth-gate-shell">
    <div class="auth-card">
        <div class="auth-header">
            <h1>Product<sup style="font-size:0.5em;">®</sup> Legal Management</h1>
            <p>Secure access to legal case management and evidence systems</p>
        </div>
        
        <div class="auth-body">
            <?php if (isset($showNoAccess)): ?>
                <div class="no-access-notice">
                    <h2>⚠️ Access Restricted</h2>
                    <p>You are logged in but do not have legal portal access. Please contact an administrator to request access to the legal management system.</p>
                    <div style="margin-top:20px;">
                        <a href="<?php echo path_with_base('/admin/legal/public-signup.php'); ?>" class="btn btn-success">Request Legal Access</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="auth-options">
                    <div class="auth-option" onclick="window.location.href='<?php echo path_with_base('/login.php?return=' . urlencode($returnTo)); ?>'">
                        <div class="auth-option-icon">🔐</div>
                        <h2>Sign In</h2>
                        <p>Already have legal portal credentials? Sign in to access your assigned cases and evidence.</p>
                        <a href="<?php echo path_with_base('/login.php?return=' . urlencode($returnTo)); ?>" class="btn btn-primary">Sign In</a>
                    </div>
                    
                    <div class="auth-option" onclick="window.location.href='<?php echo path_with_base('/admin/legal/public-signup.php'); ?>'">
                        <div class="auth-option-icon">📝</div>
                        <h2>Request Access</h2>
                        <p>New to the system? Submit an access request. An administrator will review and approve your application.</p>
                        <a href="<?php echo path_with_base('/admin/legal/public-signup.php'); ?>" class="btn btn-success">Request Access</a>
                    </div>
                </div>
                
                <div style="margin-top:32px;padding-top:32px;border-top:1px solid #e2e8f0;text-align:center;">
                    <p style="margin:0;color:#64748b;font-size:0.95rem;">
                        Access is granted on an invitation-only basis. If you have been provided this link, please sign in or request access above.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php';






