<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email-triggers.php'; // Include the new trigger functions

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/index.php');
}

$type = $_POST['type'] ?? '';
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// Spam detection
require_once __DIR__ . '/spam-detector.php';

$spamCheck = detect_spam_registration([
    'email' => $email,
    'first_name' => $first,
    'last_name' => $last,
    'phone' => $phone,
    'business_name' => $_POST['business_name'] ?? '',
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

if ($spamCheck['is_spam']) {
    $pdo = db();
    log_spam_attempt($pdo, $spamCheck);
    flash_set('error', 'Registration could not be completed. Please contact support if you believe this is an error.');
    redirect('/index.php#hero-title');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    flash_set('error', 'Please provide a valid email and a password of at least 8 characters.');
    redirect('/index.php#hero-title');
}

$pdo = db();

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status) VALUES (?, ?, ?, ?, ?, ?)');
    $status = $type === 'trainee' ? 'pending' : 'active';
    $stmt->execute([
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $first,
        $last,
        $phone,
        $status
    ]);
    $userId = intval($pdo->lastInsertId());

    // Trigger "first_signup" email notification
    trigger_email_notification('first_signup', $userId, ['signup_type' => $type]);

    // Assign role and optional wholesale account
    if ($type === 'retail_customer') {
        // Retail customer - immediate shop access at MSRP pricing
        $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
        $roleStmt->execute(['retail_customer']);
        $roleId = $roleStmt->fetchColumn();
        if ($roleId) {
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        }
        
        // Store communications consents
        $consentEmail = !empty($_POST['consent_email']);
        $consentSms = !empty($_POST['consent_sms']);
        $consentPhone = !empty($_POST['consent_phone']);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        
        // Update user consent preferences
        $stmt = $pdo->prepare('UPDATE users SET consent_email = ?, consent_sms = ?, consent_phone = ?, consent_updated_at = NOW() WHERE id = ?');
        $stmt->execute([$consentEmail ? 1 : 0, $consentSms ? 1 : 0, $consentPhone ? 1 : 0, $userId]);
        
        // Store consent agreement records
        $consentTypes = [];
        if ($consentEmail) $consentTypes[] = 'email_consent';
        if ($consentSms) $consentTypes[] = 'sms_consent';
        if ($consentPhone) $consentTypes[] = 'phone_consent';
        
        foreach ($consentTypes as $consentType) {
            $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$consentType]);
            $template = $stmt->fetch();
            
            if ($template) {
                $stmt = $pdo->prepare('
                    INSERT INTO user_agreements 
                    (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $userId,
                    $template['id'],
                    'checkbox',
                    null,
                    $ipAddress,
                    $userAgent,
                    $template['version']
                ]);
            }
        }
        
        flash_set('success', 'Welcome! Your retail account is ready. Start shopping at retail prices.');
        $next = '/customer/';
    } elseif ($type === 'investor') {
        // Investor - requires NDA completion before platform access
        $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
        $roleStmt->execute(['investor']);
        $roleId = $roleStmt->fetchColumn();
        if ($roleId) {
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        }
        
        // Create investor profile
        $companyName = trim($_POST['company_name'] ?? '');
        $investmentInterest = trim($_POST['investment_interest'] ?? '');
        $stmt = $pdo->prepare('
            INSERT INTO investor_profiles 
            (user_id, company_name, investment_interest, nda_completed, platform_access_granted)
            VALUES (?, ?, ?, 0, 0)
        ');
        $stmt->execute([$userId, $companyName, $investmentInterest]);
        
        // Store communications consents
        $consentEmail = !empty($_POST['consent_email']);
        $consentSms = !empty($_POST['consent_sms']);
        $consentPhone = !empty($_POST['consent_phone']);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        
        // Update user consent preferences
        $stmt = $pdo->prepare('UPDATE users SET consent_email = ?, consent_sms = ?, consent_phone = ?, consent_updated_at = NOW() WHERE id = ?');
        $stmt->execute([$consentEmail ? 1 : 0, $consentSms ? 1 : 0, $consentPhone ? 1 : 0, $userId]);
        
        // Store consent agreement records
        $consentTypes = [];
        if ($consentEmail) $consentTypes[] = 'email_consent';
        if ($consentSms) $consentTypes[] = 'sms_consent';
        if ($consentPhone) $consentTypes[] = 'phone_consent';
        
        foreach ($consentTypes as $consentType) {
            $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$consentType]);
            $template = $stmt->fetch();
            
            if ($template) {
                $stmt = $pdo->prepare('
                    INSERT INTO user_agreements 
                    (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $userId,
                    $template['id'],
                    'checkbox',
                    null,
                    $ipAddress,
                    $userAgent,
                    $template['version']
                ]);
            }
        }
        
        flash_set('success', 'Welcome! Please complete your NDA to access the platform.');
        $next = '/platform.php?new_investor=1';
    } elseif ($type === 'wholesale') {
        $business = trim($_POST['business_name'] ?? '');
        $website = trim($_POST['website'] ?? '');
        
        // Check for agent attribution from business card
        $assignedAgentId = null;
        $referralSlug = $_POST['referral_agent_slug'] ?? $_SESSION['referral_agent_slug'] ?? null;
        
        if ($referralSlug) {
            try {
                require_once __DIR__ . '/business-card-functions.php';
                $agentCard = get_business_card_by_slug($referralSlug);
                if ($agentCard) {
                    $assignedAgentId = $agentCard['agent_id'];
                }
            } catch (Throwable $e) {
                // Silently continue without agent assignment
            }
        }
        
        $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, website, phone, sales_agent_id, approved) VALUES (?, ?, ?, ?, ?, 0)')
            ->execute([$userId, $business, $website, $phone, $assignedAgentId]);
        
        // Store communications consents
        $consentEmail = !empty($_POST['consent_email']);
        $consentSms = !empty($_POST['consent_sms']);
        $consentPhone = !empty($_POST['consent_phone']);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        
        // Get agreement template IDs
        $consentTypes = [];
        if ($consentEmail) $consentTypes[] = 'email_consent';
        if ($consentSms) $consentTypes[] = 'sms_consent';
        if ($consentPhone) $consentTypes[] = 'phone_consent';
        
        foreach ($consentTypes as $consentType) {
            $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$consentType]);
            $template = $stmt->fetch();
            
            if ($template) {
                $stmt = $pdo->prepare('
                    INSERT INTO user_agreements 
                    (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $userId,
                    $template['id'],
                    'checkbox',
                    null, // No signature data for checkboxes
                    $ipAddress,
                    $userAgent,
                    $template['version']
                ]);
            }
        }
        
        flash_set('success', 'Wholesale application received. Your customer dashboard is ready.');
        $next = '/customer/';
    } else {
        // trainee
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, 1)')->execute([$userId]);
        
        // Store communications consents for trainee
        $consentEmail = !empty($_POST['consent_email']);
        $consentSms = !empty($_POST['consent_sms']);
        $consentPhone = !empty($_POST['consent_phone']);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        
        // Update user consent preferences
        $stmt = $pdo->prepare('UPDATE users SET consent_email = ?, consent_sms = ?, consent_phone = ?, consent_updated_at = NOW() WHERE id = ?');
        $stmt->execute([$consentEmail ? 1 : 0, $consentSms ? 1 : 0, $consentPhone ? 1 : 0, $userId]);
        
        // Get agreement template IDs and store consent records
        $consentTypes = [];
        if ($consentEmail) $consentTypes[] = 'email_consent';
        if ($consentSms) $consentTypes[] = 'sms_consent';
        if ($consentPhone) $consentTypes[] = 'phone_consent';
        
        foreach ($consentTypes as $consentType) {
            $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$consentType]);
            $template = $stmt->fetch();
            
            if ($template) {
                $stmt = $pdo->prepare('
                    INSERT INTO user_agreements 
                    (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $userId,
                    $template['id'],
                    'checkbox',
                    null,
                    $ipAddress,
                    $userAgent,
                    $template['version']
                ]);
            }
        }
        
        // Store sales agent terms agreement if checked
        if (!empty($_POST['agree_terms'])) {
            $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
            $stmt->execute(['sales_agent_terms']);
            $template = $stmt->fetch();
            
            if ($template) {
                $stmt = $pdo->prepare('
                    INSERT INTO user_agreements 
                    (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $userId,
                    $template['id'],
                    'checkbox',
                    null,
                    $ipAddress,
                    $userAgent,
                    $template['version']
                ]);
            }
        }
        
        flash_set('success', 'Welcome! Your trainee portal has been created. Start your first module.');
        $next = '/onboarding/trainee/index.php';
    }

    $pdo->commit();

    start_session();
    $_SESSION['uid'] = $userId;
    redirect($next);
} catch (Throwable $e) {
    $pdo->rollBack();
    $code = ($e instanceof PDOException && isset($e->errorInfo[1])) ? intval($e->errorInfo[1]) : 0;
    if ($code === 1062) { // duplicate key (likely email)
        if ($type === 'wholesale') {
            flash_set('error', 'That email already has an account. Please log in or use another email.');
            redirect('/index.php#hero-title');
        }
        flash_set('error', 'That email is already registered. Please sign in.');
        redirect('/login.php');
    }
    if (PRODUCT_ENV === 'dev') { echo 'Error: ' . $e->getMessage(); exit; }
    flash_set('error', 'We could not process your request. Please try again.');
    $fallback = ($type === 'wholesale') ? '/index.php#hero-title' : '/login.php';
    redirect($fallback);
}

?>
