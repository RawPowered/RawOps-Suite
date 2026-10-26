<?php
$SEO = [
    'title' => 'Create Legal User — Admin',
    'description' => 'Create a new legal team member account',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_once __DIR__ . '/../../required/legal-email-service.php';

require_login_or_redirect('/login.php');
require_legal_or_admin(path_with_base('/admin/'));

$pdo = db();
$currentUser = current_user_id();

// Only admins can create legal users
if (!user_has_role($currentUser, 'admin')) {
    flash_set('error', 'Admin access required to create legal users.');
    redirect(path_with_base('/admin/legal/'));
}

$error = '';
$success = false;

// Get all active cases for dropdown
$casesStmt = $pdo->query('
    SELECT id, case_number, title 
    FROM legal_cases 
    WHERE status IN ("active", "draft") 
    ORDER BY case_number DESC, created_at DESC
');
$cases = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tempPassword = $_POST['temp_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $lawFirm = trim($_POST['law_firm'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $caseId = !empty($_POST['case_id']) ? intval($_POST['case_id']) : null;
    $caseRole = $_POST['case_role'] ?? 'observer';
    
    // Validation
    if (!$firstName || !$lastName || !$email || !$tempPassword) {
        $error = 'First name, last name, email, and temporary password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($tempPassword) < 8) {
        $error = 'Temporary password must be at least 8 characters long.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if user already exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                throw new Exception('A user with this email already exists.');
            }
            
            // Create user with force_password_reset flag
            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('
                INSERT INTO users (email, password_hash, first_name, last_name, phone, status, force_password_reset, created_at) 
                VALUES (?, ?, ?, ?, ?, "active", 1, NOW())
            ');
            $stmt->execute([$email, $passwordHash, $firstName, $lastName, $phone]);
            $newUserId = $pdo->lastInsertId();
            
            // Assign legal role
            $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = "legal" LIMIT 1');
            $roleStmt->execute();
            $legalRoleId = $roleStmt->fetchColumn();
            
            if ($legalRoleId) {
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$newUserId, $legalRoleId]);
            } else {
                throw new Exception('Legal role not found in database. Please run migrations.');
            }
            
            // Add to case team if case selected
            $caseNumber = null;
            $caseTitle = null;
            if ($caseId) {
                // Verify case exists
                $caseStmt = $pdo->prepare('SELECT case_number, title FROM legal_cases WHERE id = ? LIMIT 1');
                $caseStmt->execute([$caseId]);
                $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($case) {
                    $caseNumber = $case['case_number'];
                    $caseTitle = $case['title'];
                    
                    // Add to case team
                    // Map simple roles to legal_case_team enum values
                    $roleMapping = [
                        'observer' => 'observer',
                        'contributor' => 'paralegal',
                        'lead' => 'lead_counsel'
                    ];
                    $assignedRole = $roleMapping[$caseRole] ?? 'observer';
                    
                    $pdo->prepare('INSERT INTO legal_case_team (case_id, user_id, role, added_by) VALUES (?, ?, ?, ?)')
                        ->execute([$caseId, $newUserId, $assignedRole, $currentUser]);
                    
                    // Log activity
                    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload, created_at) VALUES (?, ?, "team_member_added", ?, NOW())')
                        ->execute([
                            $caseId,
                            $currentUser,
                            json_encode([
                                'new_member_id' => $newUserId,
                                'new_member_name' => "$firstName $lastName",
                                'role' => $assignedRole,
                                'added_by_admin' => true
                            ])
                        ]);
                }
            }
            
            // Send email notification with credentials
            $loginUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                        '://' . $_SERVER['HTTP_HOST'] . path_with_base('/login.php');
            
            $emailResult = send_legal_user_created(
                $email,
                $firstName,
                $tempPassword,
                $loginUrl,
                $caseNumber,
                $caseTitle
            );
            
            if (!($emailResult['ok'] ?? false)) {
                error_log('Failed to send legal user creation email: ' . ($emailResult['error'] ?? 'unknown error'));
                // Don't fail the transaction, just log it
            }
            
            $pdo->commit();
            $success = true;
            
            flash_set('success', "Legal user account created successfully for $firstName $lastName. Credentials have been sent to $email.");
            
            // Redirect based on context
            if ($caseId) {
                redirect(path_with_base('/admin/legal/case-editor.php?id=' . $caseId));
            } else {
                redirect(path_with_base('/admin/legal/index.php'));
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
            error_log('[legal/create-user] Error creating user: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.create-user-container {
    max-width: 800px;
    margin: 40px auto;
    padding: 0 24px;
}

.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
}

.page-subtitle {
    font-size: 16px;
    color: #6b7280;
    margin: 0;
}

.form-card {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.form-section {
    margin-bottom: 32px;
    padding-bottom: 32px;
    border-bottom: 1px solid #e5e7eb;
}

.form-section:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 20px 0;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
    font-size: 14px;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group small {
    display: block;
    margin-top: 6px;
    color: #6b7280;
    font-size: 13px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: 14px;
}

.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.security-notice {
    background: #fef3c7;
    border: 2px solid #fbbf24;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.security-notice h4 {
    margin: 0 0 8px 0;
    color: #92400e;
    font-size: 14px;
}

.security-notice p {
    margin: 0;
    color: #78350f;
    font-size: 13px;
    line-height: 1.6;
}

.btn-group {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}

.btn {
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(30, 58, 138, 0.4);
}

.btn-secondary {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.password-generator {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.password-generator button {
    padding: 8px 16px;
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}

.password-generator button:hover {
    background: #e5e7eb;
}
</style>

<div class="create-user-container">
    <div class="page-header">
        <h1 class="page-title">👤 Create Legal User</h1>
        <p class="page-subtitle">Create a new legal team member account with temporary credentials</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="post" action="">
            <div class="form-section">
                <h3 class="section-title">Personal Information</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    <small>This will be used as their login username</small>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-section">
                <h3 class="section-title">Account Credentials</h3>
                
                <div class="security-notice">
                    <h4>🔒 Temporary Password Security</h4>
                    <p>The user will be required to change this password on first login. Create a strong temporary password (min. 8 characters). This password will be sent via email.</p>
                </div>
                
                <div class="form-group">
                    <label for="temp_password">Temporary Password *</label>
                    <input type="text" id="temp_password" name="temp_password" required minlength="8" value="<?php echo htmlspecialchars($_POST['temp_password'] ?? ''); ?>">
                    <small>Minimum 8 characters. User must change on first login.</small>
                    <div class="password-generator">
                        <button type="button" onclick="generatePassword()">🔄 Generate Strong Password</button>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3 class="section-title">Professional Affiliation</h3>
                
                <div class="form-group">
                    <label for="law_firm">Law Firm</label>
                    <input type="text" id="law_firm" name="law_firm" value="<?php echo htmlspecialchars($_POST['law_firm'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="company">Company/Organization</label>
                    <input type="text" id="company" name="company" value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-section">
                <h3 class="section-title">Case Assignment</h3>
                
                <div class="form-group">
                    <label for="case_id">Assign to Case (Optional)</label>
                    <select id="case_id" name="case_id">
                        <option value="">No case assignment</option>
                        <?php foreach ($cases as $case): ?>
                        <option value="<?php echo $case['id']; ?>" <?php echo (isset($_POST['case_id']) && $_POST['case_id'] == $case['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(($case['case_number'] ?: 'Case #' . $case['id']) . ' — ' . $case['title']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Select a case to automatically add this user to the case team</small>
                </div>
                
                <div class="form-group">
                    <label for="case_role">Role in Case</label>
                    <select id="case_role" name="case_role">
                        <option value="observer" <?php echo (($_POST['case_role'] ?? 'observer') === 'observer') ? 'selected' : ''; ?>>Observer (Read-only)</option>
                        <option value="contributor" <?php echo (($_POST['case_role'] ?? '') === 'contributor') ? 'selected' : ''; ?>>Contributor (Can add notes/evidence)</option>
                        <option value="lead" <?php echo (($_POST['case_role'] ?? '') === 'lead') ? 'selected' : ''; ?>>Lead (Full access)</option>
                    </select>
                </div>
            </div>
            
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">
                    ✉️ Create User & Send Credentials
                </button>
                <a href="<?php echo path_with_base('/admin/legal/index.php'); ?>" class="btn btn-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
function generatePassword() {
    const length = 16;
    const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    let password = '';
    
    // Ensure at least one of each type
    password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)];
    password += 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)];
    password += '0123456789'[Math.floor(Math.random() * 10)];
    password += '!@#$%^&*'[Math.floor(Math.random() * 8)];
    
    // Fill the rest
    for (let i = password.length; i < length; i++) {
        password += charset[Math.floor(Math.random() * charset.length)];
    }
    
    // Shuffle
    password = password.split('').sort(() => Math.random() - 0.5).join('');
    
    document.getElementById('temp_password').value = password;
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

