<?php
$SEO = [
    'title' => 'Legal Portal Access — Product® Legal Management',
    'description' => 'Request access to the Product Legal Management System.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/legal-case-number.php';

start_session();

// If already logged in as legal/admin, redirect to appropriate portal
$currentUser = current_user_id();
if ($currentUser) {
    if (user_has_role($currentUser, 'admin')) {
        redirect(path_with_base('/admin/legal/index.php'));
    } elseif (user_has_role($currentUser, 'legal')) {
        redirect(path_with_base('/admin/legal/portal/'));
    }
}

$pdo = db();

// Get list of cases with assigned users for "Invited by" dropdown (with privacy masking)
$casesWithTeam = $pdo->query('
    SELECT DISTINCT c.id, c.title, c.case_key, c.case_number, u.id AS user_id, u.first_name, u.last_name
    FROM legal_cases c
    JOIN legal_case_team t ON t.case_id = c.id
    JOIN users u ON u.id = t.user_id
    WHERE c.status IN ("active","draft")
    ORDER BY c.case_number, u.first_name
')->fetchAll(PDO::FETCH_ASSOC);

$inviteOptions = [];
foreach ($casesWithTeam as $row) {
    $censoredName = censor_name($row['first_name'], $row['last_name']);
    $caseLabel = $row['case_number'] ?: 'Case #' . $row['id'];
    
    $inviteOptions[] = [
        'value' => 'invited_by_' . $row['user_id'] . '_case_' . $row['id'],
        'label' => 'Invited by ' . $censoredName . ' (Case ' . $caseLabel . ')'
    ];
}

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $lawFirm = trim($_POST['law_firm'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $entityType = $_POST['entity_type'] ?? 'individual';
    $accessReason = $_POST['access_reason'] ?? '';
    $agreedToTracking = !empty($_POST['agree_tracking']);
    
    if (!$fullName || !$email || !$password || !$accessReason || !$agreedToTracking) {
        $error = 'All required fields must be completed and you must agree to security tracking.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            // Check for existing user (by email only, since username column doesn't exist)
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $error = 'Email already exists.';
            } else {
                // Check if legal tables exist
                $tablesCheck = $pdo->query("SHOW TABLES LIKE 'legal_access_requests'")->fetchColumn();
                if (!$tablesCheck) {
                    $error = 'Legal management system not initialized. Please run migrations first.';
                    throw new Exception('legal_access_requests table does not exist');
                }
                // Capture security metadata
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
                
                // Parse access reason for case/user references
                $caseId = null;
                $invitedBy = null;
                if (preg_match('/invited_by_(\d+)_case_(\d+)/', $accessReason, $matches)) {
                    $invitedBy = (int)$matches[1];
                    $caseId = (int)$matches[2];
                }
                
                // Create access request (no username needed - email is used for login)
                $stmt = $pdo->prepare('INSERT INTO legal_access_requests (case_id, requestor_name, law_firm, email, phone, desired_username, status, notes) VALUES (?, ?, ?, ?, ?, ?, "pending", ?)');
                $notes = json_encode([
                    'access_reason' => $accessReason,
                    'entity_type' => $entityType,
                    'company' => $company,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'forwarded_for' => $forwardedFor,
                    'agreed_to_tracking' => true,
                    'requested_at' => date('c')
                ]);
                $stmt->execute([$caseId, $fullName, $lawFirm, $email, $phone, null, $notes]);
                $requestId = (int)$pdo->lastInsertId();
                
                // Store password hash temporarily (will be used on approval)
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                
                // Create placeholder user (disabled until approved)
                $nameParts = explode(' ', $fullName, 2);
                $firstName = $nameParts[0];
                $lastName = $nameParts[1] ?? '';
                
                // Create user with email as login credential
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, status, created_at) VALUES (?, ?, ?, ?, "pending", NOW())');
                $stmt->execute([$email, $passwordHash, $firstName, $lastName]);
                $newUserId = (int)$pdo->lastInsertId();
                
                // Create access token
                $stmt = $pdo->prepare('INSERT INTO legal_access_tokens (user_id, case_id, token, purpose, expires_at) VALUES (?, ?, ?, "initial_access", ?)');
                $stmt->execute([$newUserId, $caseId, $token, $expiresAt]);
                
                // Log activity if case specified
                if ($caseId) {
                    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, NULL, "access_requested", ?)')->execute([
                        $caseId,
                        json_encode(['request_id' => $requestId, 'email' => $email, 'invited_by' => $invitedBy])
                    ]);
                }
                
                $success = true;
            }
        } catch (Throwable $e) {
            error_log('[legal/public-signup] error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $error = 'Database error: ' . $e->getMessage() . ' (Check logs for details)';
        }
    }
}
?>
<style>
.signup-shell {
    max-width: 720px;
    margin: 60px auto 80px auto;
    padding: 0 24px;
}
.signup-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 16px 40px rgba(15,23,42,0.12);
    padding: 40px;
    border: 1px solid #e2e8f0;
}
.signup-header {
    text-align: center;
    margin-bottom: 32px;
}
.signup-header h1 {
    margin: 0 0 8px 0;
    font-size: 2.2rem;
    color: #0f172a;
    background: linear-gradient(135deg, #1e3a8a, #7c3aed);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.signup-header p {
    margin: 0;
    color: #64748b;
    font-size: 1.05rem;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #0f172a;
    font-size: 0.95rem;
}
.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 1rem;
}
.form-group small {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 0.85rem;
}
.form-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 24px;
}
.form-section h3 {
    margin: 0 0 16px 0;
    font-size: 1.1rem;
    color: #1e40af;
}
.security-notice {
    background: #fef3c7;
    border: 2px solid #fbbf24;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 24px;
}
.security-notice h3 {
    margin: 0 0 10px 0;
    color: #92400e;
    font-size: 1.1rem;
}
.security-notice ul {
    margin: 10px 0 0 0;
    padding-left: 20px;
    color: #78350f;
}
.checkbox-group {
    display: flex;
    align-items: start;
    gap: 10px;
    margin-top: 16px;
}
.checkbox-group input[type="checkbox"] {
    width: auto;
    margin-top: 4px;
}
.checkbox-group label {
    margin: 0;
    font-weight: 500;
}
.btn {
    padding: 14px 28px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    font-size: 1.05rem;
    cursor: pointer;
    width: 100%;
}
.btn-primary {
    background: linear-gradient(135deg, #1e3a8a, #3b82f6);
    color: #fff;
    box-shadow: 0 8px 20px rgba(30,58,138,0.3);
}
.btn-primary:hover {
    box-shadow: 0 12px 28px rgba(30,58,138,0.4);
}
.alert {
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 24px;
    font-weight: 500;
}
.alert-success {
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    color: #065f46;
}
.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}
.existing-user {
    text-align: center;
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}
</style>

<div class="signup-shell">
    <div class="signup-card">
        <div class="signup-header">
            <h1>Legal Portal Access</h1>
            <p>Request access to Product Legal Management Systems</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Request Submitted Successfully!</strong>
                <p style="margin:8px 0 0 0;">Your access request has been received. An administrator will review your application and contact you via email within 24-48 hours.</p>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-section">
                    <h3>Personal Information</h3>
                    <div class="form-group">
                        <label for="full_name">Full Legal Name *</label>
                        <input type="text" id="full_name" name="full_name" required value="<?php echo sanitize($_POST['full_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required value="<?php echo sanitize($_POST['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo sanitize($_POST['phone'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Account Credentials</h3>
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <small>Minimum 8 characters. You'll use your email address to log in.</small>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Professional Affiliation</h3>
                    <div class="form-group">
                        <label for="entity_type">I am requesting access as: *</label>
                        <select id="entity_type" name="entity_type" required>
                            <option value="individual" <?php echo ($_POST['entity_type'] ?? 'individual') === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="law_firm" <?php echo ($_POST['entity_type'] ?? '') === 'law_firm' ? 'selected' : ''; ?>>Law Firm</option>
                            <option value="company" <?php echo ($_POST['entity_type'] ?? '') === 'company' ? 'selected' : ''; ?>>Company/Corporation</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="law_firm">Law Firm Name (if applicable)</label>
                        <input type="text" id="law_firm" name="law_firm" value="<?php echo sanitize($_POST['law_firm'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="company">Company/Organization Name (if applicable)</label>
                        <input type="text" id="company" name="company" value="<?php echo sanitize($_POST['company'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Access Request Details</h3>
                    <div class="form-group">
                        <label for="access_reason">Reason for Access Request *</label>
                        <select id="access_reason" name="access_reason" required>
                            <option value="">Select reason...</option>
                            <optgroup label="Invited Access">
                                <?php foreach ($inviteOptions as $opt): ?>
                                    <option value="<?php echo sanitize($opt['value']); ?>"><?php echo sanitize($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="General Access">
                                <option value="invited_general">Invited (General - No Specific Case)</option>
                                <option value="found_case">Found Case, Interested in Details</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
                
                <div class="security-notice">
                    <h3>⚠️ Security & Confidentiality Notice</h3>
                    <p style="margin:0 0 10px 0;color:#78350f;line-height:1.6;">
                        By submitting this request, you acknowledge and consent to the collection of the following information for security and audit purposes:
                    </p>
                    <ul style="line-height:1.7;">
                        <li><strong>IP Address:</strong> <?php echo sanitize($_SERVER['REMOTE_ADDR'] ?? 'Unknown'); ?></li>
                        <li><strong>Device Information:</strong> <?php echo sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 100)); ?></li>
                        <li><strong>Request Timestamp:</strong> <?php echo date('F j, Y g:i:s A T'); ?></li>
                        <?php if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])): ?>
                            <li><strong>Proxy/Forwarded IP:</strong> <?php echo sanitize($_SERVER['HTTP_X_FORWARDED_FOR']); ?></li>
                        <?php endif; ?>
                    </ul>
                    <p style="margin:10px 0 0 0;color:#78350f;line-height:1.6;">
                        This information is collected to maintain case confidentiality, prevent unauthorized access, and ensure compliance with legal and regulatory standards. All data is encrypted and stored securely.
                    </p>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="agree_tracking" name="agree_tracking" required>
                        <label for="agree_tracking">I understand and consent to the collection of this security information. I acknowledge that access to legal case materials requires the highest standards of confidentiality and integrity. *</label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Submit Access Request</button>
                
                <div class="existing-user">
                    <p style="color:#64748b;margin:0;">Already have an account? <a href="<?php echo path_with_base('/login.php'); ?>" style="color:#1d4ed8;font-weight:600;">Sign In</a></p>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php';
