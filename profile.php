<?php 
$SEO = [
    'title' => 'My Profile — Product‑It',
    'description' => 'Manage your account settings and profile information.',
    'type' => 'website'
];

require_once __DIR__ . '/required/config.php';

// Require authentication
start_session();
$uid = current_user_id();
if (!$uid) {
    redirect(outbound_path('/login.php'));
}

$pdo = db();
$success = null;
$error = null;
$passwordSuccess = null;
$passwordError = null;
$consentSuccess = null;
$consentError = null;
$emailSuccess = null;
$emailError = null;

// Wrap everything in try-catch to prevent uncaught exceptions
try {
    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (!$firstName || !$lastName) {
            $error = 'First name and last name are required.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get current values
                $stmt = $pdo->prepare('SELECT first_name, last_name, phone FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $oldValues = $stmt->fetch();
                
                // Update profile
                $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$firstName, $lastName, $phone ?: null, $uid]);
                
                // Log changes
                $fields = ['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone ?: null];
                foreach ($fields as $field => $newValue) {
                    $oldValue = $oldValues[$field] ?? null;
                    if ($oldValue !== $newValue) {
                        $stmt = $pdo->prepare('
                            INSERT INTO user_profile_updates 
                            (user_id, field_name, old_value, new_value, ip_address, user_agent)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $uid,
                            $field,
                            $oldValue,
                            $newValue,
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
                        ]);
                    }
                }
                
                $pdo->commit();
                $success = 'Profile updated successfully!';
                
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Profile update error: ' . $e->getMessage());
                $error = 'Failed to update profile. Please try again.';
            }
        }
    }

    // Handle password change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (!$currentPassword || !$newPassword || !$confirmPassword) {
            $passwordError = 'All password fields are required.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'New password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordError = 'New passwords do not match.';
        } elseif ($currentPassword === $newPassword) {
            $passwordError = 'New password must be different from current password.';
        } else {
            try {
                // Get current user
                $stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $user = $stmt->fetch();
                
                // Verify current password
                if (!password_verify($currentPassword, $user['password_hash'])) {
                    $passwordError = 'Current password is incorrect.';
                } else {
                    $pdo->beginTransaction();
                    
                    // Update password
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
                    $passwordSuccess = 'Password changed successfully!';
                }
                
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Password change error: ' . $e->getMessage());
                $passwordError = 'Failed to change password. Please try again.';
            }
        }
    }

    // Check if consent columns exist (migration may not have run yet)
    $hasConsentColumns = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'consent_%'");
        $hasConsentColumns = $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('Could not check for consent columns: ' . $e->getMessage());
    }

    // Get user data
    if ($hasConsentColumns) {
        $stmt = $pdo->prepare('
            SELECT 
                id, email, first_name, last_name, phone, status, created_at, last_login_at,
                consent_sms, consent_phone, consent_email, consent_updated_at
            FROM users 
            WHERE id = ?
        ');
    } else {
        $stmt = $pdo->prepare('
            SELECT 
                id, email, first_name, last_name, phone, status, created_at, last_login_at,
                0 as consent_sms, 0 as consent_phone, 1 as consent_email, NULL as consent_updated_at
            FROM users 
            WHERE id = ?
        ');
    }
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('User not found');
    }

    // Get user roles
    $stmt = $pdo->prepare('
        SELECT r.name
        FROM user_roles ur 
        JOIN roles r ON r.id = ur.role_id 
        WHERE ur.user_id = ?
    ');
    $stmt->execute([$uid]);
    $roles = $stmt->fetchAll();

    // Get wholesale account if applicable
    $wholesaleAccount = null;
    $stmt = $pdo->prepare('
        SELECT id, business_name, approved, phone as business_phone
        FROM wholesale_accounts 
        WHERE user_id = ? 
        LIMIT 1
    ');
    $stmt->execute([$uid]);
    $wholesaleAccount = $stmt->fetch();

    // Get pending email change request (only if table exists)
    $pendingEmailChange = null;
    try {
        $stmt = $pdo->prepare('
            SELECT new_email, requested_at, expires_at 
            FROM email_change_requests 
            WHERE user_id = ? AND status = "pending" AND expires_at > NOW()
            ORDER BY requested_at DESC
            LIMIT 1
        ');
        $stmt->execute([$uid]);
        $pendingEmailChange = $stmt->fetch();
    } catch (Throwable $e) {
        // Table doesn't exist yet - migration not run
        error_log('email_change_requests table not found: ' . $e->getMessage());
    }

    // Get consent history (last 10 changes) - only if table exists
    $consentHistory = [];
    try {
        $stmt = $pdo->prepare('
            SELECT consent_type, old_value, new_value, ip_address, created_at
            FROM user_consent_history
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ');
        $stmt->execute([$uid]);
        $consentHistory = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Table doesn't exist yet - migration not run
        error_log('user_consent_history table not found: ' . $e->getMessage());
    }

    // Get signed agreements - only if table exists
    $userAgreements = [];
    try {
        $stmt = $pdo->prepare('
            SELECT 
                ua.*,
                at.title,
                at.agreement_type,
                at.content
            FROM user_agreements ua
            JOIN agreement_templates at ON at.id = ua.agreement_template_id
            WHERE ua.user_id = ?
            ORDER BY ua.signed_at DESC
        ');
        $stmt->execute([$uid]);
        $userAgreements = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Table doesn't exist yet - migration not run
        error_log('user_agreements table not found: ' . $e->getMessage());
    }

} catch (Throwable $e) {
    error_log('Profile page error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Show user-friendly error instead of crashing
    $fatalError = true;
}

// If fatal error, show error page
if (isset($fatalError) && $fatalError) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h1>Something went wrong</h1>';
    echo '<p>We\'re sorry, but there was an error loading your profile. Our team has been notified.</p>';
    echo '<p><a href="' . outbound_path('/') . '">Return to Home</a></p>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/required/header.php'; 
?>

<style>
.profile-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px;
}
.profile-section {
    background: white;
    border-radius: 12px;
    padding: 32px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.profile-section h2 {
    margin-top: 0;
    color: var(--blue);
    font-size: 22px;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--blue-light);
}
.profile-header {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 32px;
}
.profile-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-2) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 600;
    color: white;
}
.profile-info h1 {
    margin: 0 0 8px 0;
    color: var(--blue);
    font-size: 28px;
}
.profile-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.badge-role {
    background: var(--blue-light);
    color: var(--blue);
}
.badge-status {
    background: #d1fae5;
    color: #065f46;
}
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}
.info-item {
    padding: 16px;
    background: var(--gray-light);
    border-radius: 8px;
}
.info-item label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 4px;
}
.info-item .value {
    font-size: 16px;
    color: var(--text-color);
}
.consent-checkbox {
    display: flex;
    align-items: start;
    gap: 12px;
    padding: 12px;
    background: var(--gray-light);
    border-radius: 8px;
    margin-bottom: 12px;
}
.consent-checkbox input[type="checkbox"] {
    margin-top: 4px;
}
.consent-checkbox label {
    flex: 1;
    cursor: pointer;
}
.consent-checkbox .consent-label {
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 4px;
}
.consent-checkbox .consent-description {
    font-size: 13px;
    color: var(--muted);
}
.history-item {
    padding: 12px;
    background: var(--gray-light);
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 14px;
}
.history-item .history-date {
    font-weight: 600;
    color: var(--blue);
    margin-bottom: 4px;
}
.history-item .history-change {
    color: var(--text-color);
}
.history-item .history-meta {
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
}
.pending-email-notice {
    background: #fef3c7;
    border: 1px solid #f59e0b;
    color: #92400e;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
}
@media (max-width: 768px) {
    .profile-section {
        padding: 20px;
    }
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<div class="profile-container">
    <?php if (!$hasConsentColumns): ?>
    <div style="background:#fef3c7;border:2px solid #f59e0b;color:#92400e;padding:16px;border-radius:8px;margin-bottom:24px;">
        <strong>⚠️ Profile Features Limited</strong>
        <p style="margin:8px 0 0 0;">
            Some profile features are not available yet. A database migration is required to enable:
        </p>
        <ul style="margin:8px 0 0 20px;">
            <li>Email address changes with verification</li>
            <li>Contact consent management (SMS/Phone/Email)</li>
            <li>Consent history tracking</li>
        </ul>
        <p style="margin:8px 0 0 0;">
            <a href="<?php echo outbound_path('/check-profile-migration.php'); ?>" style="color:#92400e;text-decoration:underline;font-weight:600;">Run Migration Check</a>
        </p>
    </div>
    <?php endif; ?>
    
    <div class="profile-header">
        <div class="profile-avatar">
            <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
        </div>
        <div class="profile-info">
            <h1><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></h1>
            <div class="profile-badges">
                <?php foreach ($roles as $role): ?>
                    <span class="badge badge-role"><?php echo sanitize(ucwords(str_replace('_', ' ', $role['name']))); ?></span>
                <?php endforeach; ?>
                <span class="badge badge-status"><?php echo ucfirst($user['status']); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Account Information -->
    <div class="profile-section">
        <h2>Account Information</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Email</label>
                <div class="value"><?php echo sanitize($user['email']); ?></div>
            </div>
            <div class="info-item">
                <label>Member Since</label>
                <div class="value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
            </div>
            <div class="info-item">
                <label>Last Login</label>
                <div class="value"><?php echo $user['last_login_at'] ? date('F j, Y g:i A', strtotime($user['last_login_at'])) : 'Never'; ?></div>
            </div>
            <?php if ($wholesaleAccount): ?>
            <div class="info-item">
                <label>Wholesale Account</label>
                <div class="value"><?php echo sanitize($wholesaleAccount['business_name']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Edit Profile -->
    <div class="profile-section">
        <h2>Edit Profile</h2>
        <?php if ($success): ?>
            <p style="color:#065f46;background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($success); ?>
            </p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($error); ?>
            </p>
        <?php endif; ?>
        
        <form method="post" autocomplete="on">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
                <label>
                    <span>First Name *</span>
                    <input type="text" name="first_name" value="<?php echo sanitize($user['first_name']); ?>" required maxlength="100">
                </label>
                <label>
                    <span>Last Name *</span>
                    <input type="text" name="last_name" value="<?php echo sanitize($user['last_name']); ?>" required maxlength="100">
                </label>
                <label style="grid-column: 1/-1;">
                    <span>Phone Number</span>
                    <input type="tel" name="phone" value="<?php echo sanitize($user['phone'] ?? ''); ?>" maxlength="30" autocomplete="tel">
                </label>
            </div>
            <button type="submit" class="btn" style="margin-top:16px;">Save Changes</button>
        </form>
    </div>
    
    <!-- Business Card Management (Sales Agents Only) -->
    <?php 
    $isSalesAgent = false;
    foreach ($roles as $role) {
        if ($role['name'] === 'sales_agent') {
            $isSalesAgent = true;
            break;
        }
    }
    
    if ($isSalesAgent): 
    ?>
    <div class="profile-section" style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:2px solid #0369a1;">
        <h2 style="display:flex;align-items:center;gap:8px;color:#0369a1;">
            <i class="fa-solid fa-address-card"></i> My Business Card
        </h2>
        <p style="color:#075985;margin-bottom:20px;">
            Manage your digital business card, social media links, and professional profile.
        </p>
        
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
            <a href="<?php echo outbound_path('/onboarding/sales-agent/my-business-card.php'); ?>" 
               class="btn" 
               style="background:#0369a1;color:white;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                <i class="fa-solid fa-pen-to-square"></i> Edit Business Card
            </a>
            
            <?php
            // Get agent's business card
            require_once __DIR__ . '/required/business-card-functions.php';
            $agentBusinessCard = get_agent_business_card($uid);
            if ($agentBusinessCard): 
            ?>
            <a href="/agent/<?php echo urlencode($agentBusinessCard['slug']); ?>/card" 
               target="_blank"
               class="btn secondary" 
               style="display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                <i class="fa-solid fa-eye"></i> View My Card
            </a>
            <button type="button" 
                    onclick="copyBusinessCardUrl('<?php echo htmlspecialchars($agentBusinessCard['slug']); ?>')"
                    class="btn secondary" 
                    style="display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="fa-solid fa-copy"></i> Copy Card Link
            </button>
            <?php endif; ?>
        </div>
        
        <?php if ($agentBusinessCard): ?>
        <div style="margin-top:16px;padding:12px;background:white;border-radius:8px;border:1px solid #bae6fd;">
            <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">Your business card URL:</div>
            <div style="font-family:monospace;font-size:13px;color:#0369a1;word-break:break-all;">
                https://product.example/agent/<?php echo htmlspecialchars($agentBusinessCard['slug']); ?>/card
            </div>
        </div>
        <?php endif; ?>
        
        <script>
        function copyBusinessCardUrl(slug) {
            const url = 'https://product.example/agent/' + slug + '/card';
            navigator.clipboard.writeText(url).then(() => {
                alert('✓ Business card link copied to clipboard!');
            }).catch(() => {
                prompt('Copy this URL:', url);
            });
        }
        </script>
    </div>
    <?php endif; ?>
    
    <!-- Contact Preferences -->
    <?php if ($hasConsentColumns): ?>
    <div class="profile-section">
        <h2>Contact Preferences</h2>
        <?php if ($consentSuccess): ?>
            <p style="color:#065f46;background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($consentSuccess); ?>
            </p>
        <?php endif; ?>
        <?php if ($consentError): ?>
            <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($consentError); ?>
            </p>
        <?php endif; ?>
        
        <p style="color:var(--muted);margin-bottom:16px;font-size:14px;">
            Manage your communication preferences. You can update these at any time.
            <?php if ($user['consent_updated_at']): ?>
                <br>Last updated: <?php echo date('F j, Y g:i A', strtotime($user['consent_updated_at'])); ?>
            <?php endif; ?>
        </p>
        
        <form method="post" id="consentForm" action="<?php echo outbound_path('/api/user/update-consent.php'); ?>">
            <div class="consent-checkbox">
                <input type="checkbox" name="consent_sms" id="consent_sms" value="1" <?php echo $user['consent_sms'] ? 'checked' : ''; ?>>
                <label for="consent_sms">
                    <div class="consent-label">SMS Marketing</div>
                    <div class="consent-description">I agree to receive promotional text messages about products, offers, and updates.</div>
                </label>
            </div>
            
            <div class="consent-checkbox">
                <input type="checkbox" name="consent_phone" id="consent_phone" value="1" <?php echo $user['consent_phone'] ? 'checked' : ''; ?>>
                <label for="consent_phone">
                    <div class="consent-label">Phone Calls</div>
                    <div class="consent-description">I agree to receive phone calls from sales representatives and customer service.</div>
                </label>
            </div>
            
            <div class="consent-checkbox">
                <input type="checkbox" name="consent_email" id="consent_email" value="1" <?php echo $user['consent_email'] ? 'checked' : ''; ?>>
                <label for="consent_email">
                    <div class="consent-label">Email Marketing</div>
                    <div class="consent-description">I agree to receive promotional emails about products, offers, and updates.</div>
                </label>
            </div>
            
            <button type="submit" class="btn" style="margin-top:16px;">Update Preferences</button>
        </form>
        
        <div id="consentMessage" style="margin-top:12px;display:none;"></div>
        
        <?php if (!empty($consentHistory)): ?>
        <details style="margin-top:24px;">
            <summary style="cursor:pointer;font-weight:600;color:var(--blue);padding:8px 0;">
                View Consent History (<?php echo count($consentHistory); ?> recent changes)
            </summary>
            <div style="margin-top:16px;">
                <?php foreach ($consentHistory as $history): ?>
                <div class="history-item">
                    <div class="history-date"><?php echo date('F j, Y g:i A', strtotime($history['created_at'])); ?></div>
                    <div class="history-change">
                        <?php 
                        $typeLabel = ['sms' => 'SMS Marketing', 'phone' => 'Phone Calls', 'email' => 'Email Marketing'][$history['consent_type']] ?? $history['consent_type'];
                        $oldLabel = $history['old_value'] ? 'Enabled' : 'Disabled';
                        $newLabel = $history['new_value'] ? 'Enabled' : 'Disabled';
                        echo sanitize($typeLabel) . ': ' . $oldLabel . ' → <strong>' . $newLabel . '</strong>';
                        ?>
                    </div>
                    <div class="history-meta">IP: <?php echo sanitize(substr($history['ip_address'] ?? 'Unknown', 0, 15)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Change Email -->
    <?php if ($hasConsentColumns): ?>
    <div class="profile-section">
        <h2>Change Email Address</h2>
        
        <?php if ($pendingEmailChange): ?>
        <div class="pending-email-notice">
            <strong>⚠️ Email Change Pending</strong>
            <p style="margin:8px 0 0 0;">
                A verification email has been sent to <strong><?php echo sanitize($pendingEmailChange['new_email']); ?></strong>.
                Please check your inbox and click the verification link to complete the change.
                <br><small>Expires: <?php echo date('F j, Y g:i A', strtotime($pendingEmailChange['expires_at'])); ?></small>
            </p>
        </div>
        <?php endif; ?>
        
        <?php if ($emailSuccess): ?>
            <p style="color:#065f46;background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($emailSuccess); ?>
            </p>
        <?php endif; ?>
        <?php if ($emailError): ?>
            <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($emailError); ?>
            </p>
        <?php endif; ?>
        
        <p style="color:var(--muted);margin-bottom:16px;font-size:14px;">
            To change your email address, enter your new email and current password. You'll receive a verification link at the new address.
        </p>
        
        <form method="post" id="emailChangeForm" action="<?php echo outbound_path('/api/user/change-email.php'); ?>" autocomplete="off">
            <div class="form-grid">
                <label style="grid-column: 1/-1;">
                    <span>Current Email</span>
                    <input type="email" value="<?php echo sanitize($user['email']); ?>" readonly style="background:#f3f4f6;">
                </label>
                <label style="grid-column: 1/-1;">
                    <span>New Email Address *</span>
                    <input type="email" name="new_email" id="new_email" required autocomplete="email">
                </label>
                <label style="grid-column: 1/-1;">
                    <span>Current Password (for verification) *</span>
                    <input type="password" name="password" id="email_change_password" required autocomplete="current-password">
                </label>
            </div>
            <button type="submit" class="btn" style="margin-top:16px;">Send Verification Email</button>
        </form>
        
        <div id="emailMessage" style="margin-top:12px;display:none;"></div>
    </div>
    <?php endif; ?>
    
    <!-- My Agreements -->
    <?php if (!empty($userAgreements)): ?>
    <div class="profile-section">
        <h2>⚖️ My Agreements</h2>
        <p style="color:var(--muted);margin-bottom:20px;font-size:14px;">
            View and manage all legal agreements you've signed with Product.
        </p>
        
        <div style="display:grid;gap:16px;">
            <?php foreach ($userAgreements as $agreement): ?>
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;background:#f9fafb;">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                    <div>
                        <h3 style="margin:0 0 4px 0;font-size:16px;font-weight:600;color:#1f2937;">
                            <?php echo htmlspecialchars($agreement['title']); ?>
                        </h3>
                        <div style="font-size:13px;color:#6b7280;">
                            <strong>Signed:</strong> <?php echo date('F j, Y g:i A', strtotime($agreement['signed_at'])); ?>
                            <span style="margin:0 8px;">•</span>
                            <strong>Version:</strong> <?php echo $agreement['agreement_version']; ?>
                            <span style="margin:0 8px;">•</span>
                            <strong>Method:</strong> <?php echo ucfirst($agreement['signature_type']); ?>
                        </div>
                    </div>
                    <button 
                        type="button" 
                        class="btn" 
                        style="font-size:13px;padding:6px 12px;"
                        onclick="viewAgreement(<?php echo $agreement['id']; ?>)"
                    >
                        View
                    </button>
                </div>
                
                <?php if ($agreement['signature_type'] === 'typed' && $agreement['signature_data']): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #d1d5db;">
                    <span style="font-size:13px;color:#6b7280;">Signature:</span>
                    <span style="font-family:'Brush Script MT',cursive;font-size:20px;color:#1f2937;margin-left:8px;">
                        <?php echo htmlspecialchars($agreement['signature_data']); ?>
                    </span>
                </div>
                <?php elseif ($agreement['signature_type'] === 'canvas' && $agreement['signature_data']): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #d1d5db;">
                    <span style="font-size:13px;color:#6b7280;">Signature:</span>
                    <canvas 
                        id="signature-view-<?php echo $agreement['id']; ?>" 
                        width="300" 
                        height="60"
                        style="border:1px solid #d1d5db;border-radius:4px;max-width:300px;height:60px;margin-left:8px;"
                        data-signature='<?php echo htmlspecialchars($agreement['signature_data']); ?>'
                    ></canvas>
                </div>
                <?php endif; ?>
                
                <!-- Hidden content for modal -->
                <div id="agreement-content-<?php echo $agreement['id']; ?>" style="display:none;">
                    <?php echo $agreement['content']; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Change Password -->
    <div class="profile-section">
        <h2>Change Password</h2>
        <?php if ($passwordSuccess): ?>
            <p style="color:#065f46;background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($passwordSuccess); ?>
            </p>
        <?php endif; ?>
        <?php if ($passwordError): ?>
            <p style="color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px">
                <?php echo sanitize($passwordError); ?>
            </p>
        <?php endif; ?>
        
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
                <label style="grid-column: 1/-1;">
                    <span>Current Password *</span>
                    <input type="password" name="current_password" required autocomplete="current-password">
                </label>
                <label>
                    <span>New Password *</span>
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                    <small style="color:var(--muted);font-size:13px;">Minimum 8 characters</small>
                </label>
                <label>
                    <span>Confirm New Password *</span>
                    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                </label>
            </div>
            <button type="submit" class="btn" style="margin-top:16px;">Change Password</button>
        </form>
    </div>
    
    <!-- Back to Dashboard -->
    <div style="text-align:center;margin-top:32px;">
        <a href="<?php echo user_dashboard_path($uid); ?>" class="btn secondary">← Back to Dashboard</a>
    </div>
</div>

<script>
// Handle consent form submission via AJAX
document.getElementById('consentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const form = e.target;
    const messageDiv = document.getElementById('consentMessage');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    messageDiv.style.display = 'block';
    messageDiv.textContent = 'Updating preferences...';
    messageDiv.style.color = '#6b7280';
    messageDiv.style.background = '#f3f4f6';
    messageDiv.style.padding = '12px';
    messageDiv.style.borderRadius = '8px';
    
    try {
        const formData = new FormData(form);
        const response = await fetch(form.action, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            messageDiv.textContent = '✓ ' + data.message;
            messageDiv.style.color = '#065f46';
            messageDiv.style.background = '#f0fdf4';
            messageDiv.style.border = '1px solid #86efac';
            
            // Reload page after 2 seconds to show updated history
            setTimeout(() => window.location.reload(), 2000);
        } else {
            messageDiv.textContent = '✗ ' + (data.error || 'Failed to update preferences');
            messageDiv.style.color = '#991b1b';
            messageDiv.style.background = '#fef2f2';
            messageDiv.style.border = '1px solid #fecaca';
        }
    } catch (error) {
        messageDiv.textContent = '✗ Network error. Please try again.';
        messageDiv.style.color = '#991b1b';
        messageDiv.style.background = '#fef2f2';
        messageDiv.style.border = '1px solid #fecaca';
    } finally {
        submitBtn.disabled = false;
    }
});

// Handle email change form submission via AJAX
document.getElementById('emailChangeForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const form = e.target;
    const messageDiv = document.getElementById('emailMessage');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    messageDiv.style.display = 'block';
    messageDiv.textContent = 'Sending verification email...';
    messageDiv.style.color = '#6b7280';
    messageDiv.style.background = '#f3f4f6';
    messageDiv.style.padding = '12px';
    messageDiv.style.borderRadius = '8px';
    
    try {
        const formData = new FormData(form);
        const response = await fetch(form.action, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            messageDiv.textContent = '✓ ' + data.message;
            messageDiv.style.color = '#065f46';
            messageDiv.style.background = '#f0fdf4';
            messageDiv.style.border = '1px solid #86efac';
            
            // Clear form
            form.reset();
            
            // Reload page after 3 seconds to show pending notice
            setTimeout(() => window.location.reload(), 3000);
        } else {
            messageDiv.textContent = '✗ ' + (data.error || 'Failed to send verification email');
            messageDiv.style.color = '#991b1b';
            messageDiv.style.background = '#fef2f2';
            messageDiv.style.border = '1px solid #fecaca';
        }
    } catch (error) {
        messageDiv.textContent = '✗ Network error. Please try again.';
        messageDiv.style.color = '#991b1b';
        messageDiv.style.background = '#fef2f2';
        messageDiv.style.border = '1px solid #fecaca';
    } finally {
        submitBtn.disabled = false;
    }
});

// View Agreement Modal
function viewAgreement(agreementId) {
    const content = document.getElementById('agreement-content-' + agreementId);
    if (!content) return;
    
    // Create modal
    const modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';
    
    const modalContent = document.createElement('div');
    modalContent.style.cssText = 'background:white;border-radius:12px;max-width:800px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);';
    
    const modalHeader = document.createElement('div');
    modalHeader.style.cssText = 'padding:24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;';
    modalHeader.innerHTML = '<h3 style="margin:0;font-size:24px;font-weight:700;color:#1f2937;">Agreement Details</h3><button onclick="this.closest(\'.modal-wrapper\').remove()" style="background:none;border:none;font-size:24px;color:#6b7280;cursor:pointer;padding:0;width:32px;height:32px;">×</button>';
    
    const modalBody = document.createElement('div');
    modalBody.style.cssText = 'padding:32px;line-height:1.6;';
    modalBody.innerHTML = content.innerHTML;
    
    const modalFooter = document.createElement('div');
    modalFooter.style.cssText = 'padding:24px;border-top:1px solid #e5e7eb;text-align:right;';
    modalFooter.innerHTML = '<button onclick="this.closest(\'.modal-wrapper\').remove()" style="padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;background:#f3f4f6;color:#374151;border:none;cursor:pointer;">Close</button>';
    
    modalContent.appendChild(modalHeader);
    modalContent.appendChild(modalBody);
    modalContent.appendChild(modalFooter);
    modal.appendChild(modalContent);
    modal.className = 'modal-wrapper';
    
    document.body.appendChild(modal);
    
    // Close on outside click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

// Render canvas signatures
document.addEventListener('DOMContentLoaded', function() {
    const canvases = document.querySelectorAll('canvas[data-signature]');
    canvases.forEach(function(canvas) {
        try {
            const signatureData = JSON.parse(canvas.getAttribute('data-signature'));
            if (!signatureData || !signatureData.strokes) return;
            
            const ctx = canvas.getContext('2d');
            const scaleX = canvas.width / (signatureData.width || canvas.width);
            const scaleY = canvas.height / (signatureData.height || canvas.height);
            
            ctx.strokeStyle = '#000000';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            
            signatureData.strokes.forEach(function(stroke) {
                if (stroke.length === 0) return;
                
                ctx.beginPath();
                ctx.moveTo(stroke[0].x * scaleX, stroke[0].y * scaleY);
                
                for (let i = 1; i < stroke.length; i++) {
                    ctx.lineTo(stroke[i].x * scaleX, stroke[i].y * scaleY);
                }
                
                ctx.stroke();
            });
        } catch (e) {
            console.error('Failed to render signature:', e);
        }
    });
});
</script>

<?php require_once __DIR__ . '/required/footer.php'; ?>
