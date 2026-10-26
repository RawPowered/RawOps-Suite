<?php
$SEO = [
    'title' => 'Share Signup Link — Legal Management',
    'description' => 'Generate and share secure signup links for legal access.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_legal_or_admin(path_with_base('/admin/'));

// Only admins can share signup links
start_session();
$currentUser = current_user_id();
if (!user_has_role($currentUser, 'admin')) {
    redirect(path_with_base('/admin/legal/portal/'));
}

$pdo = db();

$signupUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . path_with_base('/admin/legal/public-signup.php');

$breadcrumbs = [
    ['label' => 'Share Signup Link', 'url' => null]
];
?>
<?php include __DIR__ . '/partials/breadcrumb.php'; ?>

<style>
.share-shell {
    max-width: 900px;
    margin: 40px auto 80px auto;
    padding: 0 24px;
}
.share-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 28px rgba(15,23,42,0.08);
    padding: 32px;
}
.share-card h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
    color: #0f172a;
}
.share-card .subtitle {
    margin: 0 0 32px 0;
    color: #64748b;
}
.link-box {
    background: #f8fafc;
    border: 2px solid #cbd5e1;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}
.link-box input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-family: monospace;
    font-size: 0.95rem;
    background: #fff;
}
.btn {
    padding: 12px 24px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    font-size: 1rem;
}
.btn-primary {
    background: #0d47a1;
    color: #fff;
}
.btn-success {
    background: #10b981;
    color: #fff;
}
.info-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 18px;
    margin-top: 24px;
}
.info-box h3 {
    margin: 0 0 10px 0;
    color: #1e40af;
    font-size: 1.1rem;
}
.info-box ul {
    margin: 10px 0 0 0;
    padding-left: 20px;
    color: #1e40af;
}
</style>

<div class="share-shell">
    <div class="share-card">
        <h1>Share Legal Access Signup Link</h1>
        <p class="subtitle">Provide this secure link to external counsel, legal team members, or authorized parties to request access to the legal management system.</p>
        
        <div class="link-box">
            <label style="display:block;font-weight:600;margin-bottom:10px;color:#0f172a;">Signup URL</label>
            <input type="text" id="signup-url" value="<?php echo sanitize($signupUrl); ?>" readonly onclick="this.select();">
        </div>
        
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <button class="btn btn-primary" id="copy-btn">📋 Copy Link</button>
            <button class="btn btn-success" id="email-btn">📧 Email Link</button>
            <a href="<?php echo sanitize($signupUrl); ?>" target="_blank" class="btn" style="background:#f1f5f9;color:#0f172a;text-decoration:none;">🔗 Open in New Tab</a>
        </div>
        
        <div class="info-box">
            <h3>What Happens Next?</h3>
            <ul>
                <li>Recipient fills out secure registration form</li>
                <li>System captures IP, device info, and consent for security audit</li>
                <li>Request appears in your <a href="<?php echo path_with_base('/admin/legal/access-requests.php'); ?>" style="color:#1d4ed8;font-weight:600;">Access Requests queue</a></li>
                <li>You review and approve/deny the request</li>
                <li>Upon approval, user can log in and access assigned cases</li>
            </ul>
        </div>
        
        <div style="margin-top:32px;padding-top:32px;border-top:1px solid #e2e8f0;">
            <h3 style="margin:0 0 16px 0;color:#0f172a;">Quick Actions</h3>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="<?php echo path_with_base('/admin/legal/access-requests.php'); ?>" class="btn" style="background:#f1f5f9;color:#0f172a;text-decoration:none;">View Pending Requests</a>
                <a href="<?php echo path_with_base('/admin/legal/index.php'); ?>" class="btn" style="background:#f1f5f9;color:#0f172a;text-decoration:none;">Back to Dashboard</a>
            </div>
        </div>
    </div>
</div>

<script>
const copyBtn = document.getElementById('copy-btn');
const emailBtn = document.getElementById('email-btn');
const signupUrl = document.getElementById('signup-url');

copyBtn?.addEventListener('click', () => {
    signupUrl.select();
    document.execCommand('copy');
    copyBtn.textContent = '✓ Copied!';
    setTimeout(() => {
        copyBtn.textContent = '📋 Copy Link';
    }, 2000);
});

emailBtn?.addEventListener('click', () => {
    const subject = encodeURIComponent('Legal Portal Access - Product® Legal Management Systems');
    const body = encodeURIComponent(`You have been invited to request access to the Product® Legal Management System.\n\nPlease visit the following link to submit your access request:\n\n${signupUrl.value}\n\nYou will need to provide:\n- Your full legal name and contact information\n- Desired username and password\n- Professional affiliation (law firm, company, or individual)\n- Reason for access request\n\nYou will also be asked to consent to security tracking (IP address, device information) for audit and confidentiality purposes.\n\nIf you have any questions, please contact the administrator who provided this link.\n\nThank you,\nProduct® Legal Management Team`);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php';
