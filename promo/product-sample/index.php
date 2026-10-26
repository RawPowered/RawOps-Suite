<?php
// Robust config loader
$loaded = false;
$candidates = [
    __DIR__ . '/../../required/config.php',
    __DIR__ . '/../../../required/config.php',
    __DIR__ . '/../../../../required/config.php'
];
foreach ($candidates as $p) { 
    if (is_file($p)) { 
        require_once $p; 
        require_once dirname($p) . '/demo-functions.php';
        require_once dirname($p) . '/sample-email-triggers.php';
        $loaded = true; 
        break; 
    } 
}
if (!$loaded) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    function db() { return null; }
    function start_session() { if (session_status() !== PHP_SESSION_ACTIVE) @session_start(); }
}

start_session();
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }

// System integrated with Sample Request System
$showDeprecationNotice = false;

// Get or create source
$sourceId = 0;
try {
    if ($pdo) {
        $slug = 'product-pack-trial';
        $stmt = $pdo->prepare('SELECT id FROM promo_demo_sources WHERE slug = ?');
        $stmt->execute([$slug]);
        $sourceId = intval($stmt->fetchColumn());
        if ($sourceId <= 0) {
            $pdo->prepare('INSERT INTO promo_demo_sources (slug, name, description) VALUES (?, ?, ?)')
                ->execute([$slug, 'Product product-pack Trial (Product B)', 'Landing page for product-pack demo bottle recipients']);
            $sourceId = intval($pdo->lastInsertId());
        }
    }
} catch (Throwable $e) { $sourceId = 0; }

// Session tracking
if (empty($_SESSION['promo_demo_session'])) {
    $_SESSION['promo_demo_session'] = bin2hex(random_bytes(16));
}
$sessionId = $_SESSION['promo_demo_session'];

// Constants
$mlPerOz = 29.5735;
$quarterOzMl = round($mlPerOz * 0.25, 1);
$halfOzMl = round($mlPerOz * 0.5, 1);

// Check if user is already logged in
$currentUserId = isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;
$currentUserEmail = $_SESSION['email'] ?? '';

// Detect which product user received (Product B or Product A)
$receivedProduct = null;
$hasSampleRequest = false;

if ($currentUserId && $pdo) {
    // Check if user has a sample_request linked to this promo
    try {
        $stmt = $pdo->prepare('
            SELECT sr.id, p.sku, p.name, sr.sample_product_id
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            LEFT JOIN promo_demo_leads pdl ON pdl.sample_request_id = sr.id
            WHERE (sr.lead_id IN (SELECT id FROM crm_leads WHERE email = ?)
                OR sr.account_id IN (SELECT wa.id FROM wholesale_accounts wa 
                                     JOIN users u ON u.id = wa.user_id WHERE u.email = ?))
            AND p.is_sample = 1 AND p.size_oz = 3
            ORDER BY sr.created_at DESC LIMIT 1
        ');
        $stmt->execute([$currentUserEmail, $currentUserEmail]);
        $sampleReq = $stmt->fetch();
        
        if ($sampleReq) {
            $hasSampleRequest = true;
            // Detect product type from SKU or name
            if (stripos($sampleReq['sku'], 'gold') !== false || stripos($sampleReq['sku'], '40') !== false || stripos($sampleReq['name'], 'gold') !== false || stripos($sampleReq['name'], '40') !== false) {
                $receivedProduct = 'gold_40';
            } elseif (stripos($sampleReq['sku'], 'blue') !== false || stripos($sampleReq['sku'], '20') !== false || stripos($sampleReq['name'], 'blue') !== false || stripos($sampleReq['name'], '20') !== false) {
                $receivedProduct = 'blue_20';
            }
        }
    } catch (Throwable $e) {
        // Silently fail - user can still select product manually
    }
}

// Get agent business card from sample request OR from URL parameter
$agentCard = null;
if ($pdo) {
    require_once __DIR__ . '/../../required/business-card-functions.php';
    
    // Try to get from sample request first
    if ($hasSampleRequest) {
        try {
            $stmt = $pdo->prepare('SELECT agent_id FROM sample_requests WHERE id = ?');
            $stmt->execute([$sampleReq['id']]);
            $agentId = $stmt->fetchColumn();
            
            if ($agentId) {
                $agentCard = get_agent_business_card($agentId);
            }
        } catch (Throwable $e) {
            // Silently fail
        }
    }
    
    // If no card yet and agent parameter in URL, try to get from slug
    if (!$agentCard && !empty($_GET['agent'])) {
        try {
            $agentSlug = $_GET['agent'];
            $agentCard = get_business_card_by_slug($agentSlug);
        } catch (Throwable $e) {
            // Silently fail
        }
    }
}

// State management
$step = $_GET['step'] ?? 'user_type';
$foundStore = null;
$searchError = '';
$userType = $_SESSION['selected_user_type'] ?? ($_GET['user_type'] ?? null);
$createAccountError = '';

// Handle user type selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_user_type') {
    $selectedType = trim($_POST['user_type'] ?? '');
    if (in_array($selectedType, ['retail', 'business'])) {
        $_SESSION['selected_user_type'] = $selectedType;
        $userType = $selectedType;
        header('Location: ?step=search&user_type=' . $selectedType);
        exit;
    } else {
        $searchError = 'Please select a user type.';
        $step = 'user_type';
    }
}

// Handle store search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'search_store') {
    $searchTerm = trim($_POST['search_term'] ?? '');
    if (strlen($searchTerm) >= 2) {
        $results = search_stores($pdo, $searchTerm, 10);
        if (!empty($results)) {
            // Store results in session
            $_SESSION['demo_search_results'] = $results;
            $step = 'select';
        } else {
            $searchError = 'No stores found. Would you like to create an account?';
            $step = 'not_found';
        }
    } else {
        $searchError = 'Please enter at least 2 characters to search.';
    }
}

// Handle store selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'select_store') {
    $selectedId = intval($_POST['selected_store_id']);
    $results = $_SESSION['demo_search_results'] ?? [];
    foreach ($results as $result) {
        if ($result['id'] == $selectedId) {
            $foundStore = $result;
            $_SESSION['demo_selected_store'] = $foundStore;
            
            // Check if user has an account
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$foundStore['email']]);
            $existingUserId = $stmt->fetchColumn();
            
            if ($existingUserId) {
                // User exists, prompt for login
                $step = 'login';
            } elseif (empty($foundStore['wholesale_account_id']) && !$currentUserId) {
                // No account, need to create one
                $step = 'create_account';
            } else {
                $step = 'demo_content';
            }
            break;
        }
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['uid'] = $user['id'];
        $_SESSION['email'] = $email;
        $currentUserId = $user['id'];
        $currentUserEmail = $email;
        
        // Update lead status to drop_successful if they have a demo
        $foundStore = $_SESSION['demo_selected_store'] ?? null;
        if ($foundStore) {
            // Update CRM lead status
            $stmt = $pdo->prepare('
                UPDATE crm_leads 
                SET lead_status = "drop_successful", updated_at = NOW()
                WHERE email = ? 
                AND lead_status IN ("info_obtained_demo_dropped", "info_obtained_demo_pending")
            ');
            $stmt->execute([$email]);
            
            // Get the CRM lead ID
            $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE email = ?');
            $stmt->execute([$email]);
            $leadId = $stmt->fetchColumn();
            
            // Update demo drop-off records
            if ($leadId) {
                $stmt = $pdo->prepare('
                    UPDATE crm_demo_dropoffs 
                    SET notes = CONCAT(IFNULL(notes, ""), IF(notes != "", "\n", ""), "Customer logged in - Drop successful"),
                        updated_at = NOW()
                    WHERE lead_id = ? 
                    AND demo_status = "dropped"
                    AND notes NOT LIKE "%Customer logged in%"
                ');
                $stmt->execute([$leadId]);
            }
        }
        
        // Check if product needs to be selected
        if (!$receivedProduct) {
            $step = 'select_product';
        } else {
            $step = 'demo_content';
        }
    } else {
        $loginError = 'Invalid email or password.';
        $step = 'login';
    }
}

// Handle product selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'select_product') {
    $selectedProduct = trim($_POST['product_type'] ?? '');
    if (in_array($selectedProduct, ['gold_40', 'blue_20', 'product format_dash'])) {
        $_SESSION['selected_product_type'] = $selectedProduct;
        $receivedProduct = $selectedProduct;
        $step = 'demo_content';
    } else {
        $step = 'select_product';
    }
}

// Handle retail account creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_retail_account') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zipCode = trim($_POST['zip_code'] ?? '');
    
    $errors = [];
    if (empty($firstName) || empty($lastName)) {
        $errors[] = 'First and last name are required.';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (empty($phone)) {
        $errors[] = 'Phone number is required.';
    }
    if (empty($address) || empty($city) || empty($state) || empty($zipCode)) {
        $errors[] = 'Complete shipping address is required.';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Check if user exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $existingUserId = $stmt->fetchColumn();
            
            if ($existingUserId) {
                $errors[] = 'An account with this email already exists. Please login instead.';
                $pdo->rollBack();
                $step = 'retail_signup';
            } else {
                // Create retail customer user
                $stmt = $pdo->prepare('
                    INSERT INTO users (first_name, last_name, email, password_hash, phone, status, created_at)
                    VALUES (?, ?, ?, ?, ?, "active", NOW())
                ');
                $stmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $phone
                ]);
                $userId = $pdo->lastInsertId();
                
                // Assign retail_customer role
                $stmt = $pdo->prepare('
                    INSERT INTO user_roles (user_id, role_id)
                    SELECT ?, id FROM roles WHERE name = "retail_customer"
                ');
                $stmt->execute([$userId]);
                
                // Store email consent
                $stmt = $pdo->prepare('UPDATE users SET consent_email = 1, consent_updated_at = NOW() WHERE id = ?');
                $stmt->execute([$userId]);
                
                $pdo->commit();
                
                // Log them in
                $_SESSION['uid'] = $userId;
                $_SESSION['email'] = $email;
                $currentUserId = $userId;
                $currentUserEmail = $email;
                
                // Set user type
                $_SESSION['selected_user_type'] = 'retail';
                
                // Redirect to product selection
                header('Location: ?step=select_product');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Account creation failed: ' . $e->getMessage();
            $step = 'retail_signup';
        }
    } else {
        $step = 'retail_signup';
    }
}

// Handle account creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create_account') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $storeName = trim($_POST['store_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zipCode = trim($_POST['zip_code'] ?? '');
    
    $errors = [];
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (empty($phone)) {
        $errors[] = 'Phone number is required.';
    }
    if (empty($storeName)) {
        $errors[] = ($userType === 'retail' ? 'Full name is required.' : 'Store name is required.');
    }
    if (empty($address) || empty($city) || empty($state) || empty($zipCode)) {
        $errors[] = 'Complete shipping address is required.';
    }
    
    if (empty($errors)) {
        $foundStore = $_SESSION['demo_selected_store'];
        // Preserve user type selection
        if (!isset($_SESSION['selected_user_type']) && isset($userType)) {
            $_SESSION['selected_user_type'] = $userType;
        }
        try {
            $pdo->beginTransaction();
            
            // Check if user exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$foundStore['email']]);
            $existingUserId = $stmt->fetchColumn();
            
            if (!$existingUserId) {
                // Create user with complete information
                $stmt = $pdo->prepare('
                    INSERT INTO users (first_name, last_name, email, password_hash, phone, status, created_at)
                    VALUES (?, ?, ?, ?, ?, "active", NOW())
                ');
                $stmt->execute([
                    $foundStore['first_name'],
                    $foundStore['last_name'],
                    $foundStore['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $phone
                ]);
                $userId = $pdo->lastInsertId();
                
                // Assign role based on user type
                $roleToAssign = ($userType === 'retail') ? 'retail_customer' : 'customer';
                $stmt = $pdo->prepare('
                    INSERT INTO user_roles (user_id, role_id)
                    SELECT ?, id FROM roles WHERE name = ?
                ');
                $stmt->execute([$userId, $roleToAssign]);
                
                // Create wholesale account only for business users
                if ($userType === 'business') {
                    $stmt = $pdo->prepare('
                        INSERT INTO wholesale_accounts (
                            user_id, business_name, address_line1, city, 
                            state_region, postal_code, country, approved, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, "USA", 1, NOW())
                    ');
                    $stmt->execute([$userId, $storeName, $address, $city, $state, $zipCode]);
                }
                
                // Update CRM lead with complete information
                $stmt = $pdo->prepare('
                    UPDATE crm_leads 
                    SET company_name = ?, phone = ?, address = ?, city = ?, 
                        state = ?, postal_code = ?, lead_status = "drop_successful", 
                        updated_at = NOW()
                    WHERE email = ?
                ');
                $stmt->execute([$storeName, $phone, $address, $city, $state, $zipCode, $foundStore['email']]);
                
                // Get the CRM lead ID and update demo drop-off
                $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE email = ?');
                $stmt->execute([$foundStore['email']]);
                $leadId = $stmt->fetchColumn();
                
                if ($leadId) {
                    $stmt = $pdo->prepare('
                        UPDATE crm_demo_dropoffs 
                        SET notes = CONCAT(IFNULL(notes, ""), IF(notes != "", "\n", ""), "Customer created account - Drop successful"),
                            updated_at = NOW()
                        WHERE lead_id = ? 
                        AND demo_status = "dropped"
                        AND notes NOT LIKE "%Customer created account%"
                    ');
                    $stmt->execute([$leadId]);
                }
                
                // Log them in
                $_SESSION['uid'] = $userId;
                $_SESSION['email'] = $foundStore['email'];
                $currentUserId = $userId;
                $currentUserEmail = $foundStore['email'];
            }
            
            // Create sample request for this customer
            $recipientData = [
                'name' => $storeName,
                'email' => $foundStore['email'],
                'phone' => $phone,
                'address_line1' => $address,
                'address_line2' => '',
                'city' => $city,
                'state' => $state,
                'zip' => $zipCode,
                'country' => 'USA'
            ];
            
            $sampleRequestId = create_product_pack_trial_sample_request(
                $pdo, 
                $agentId ?: 0, 
                $leadId, 
                null, // account_id will be linked later
                $recipientData
            );
            
            // Link sample request to demo lead if created
            if ($sampleRequestId && $demoLeadId) {
                $stmt = $pdo->prepare('UPDATE promo_demo_leads SET sample_request_id = ? WHERE id = ?');
                $stmt->execute([$sampleRequestId, $demoLeadId]);
            }
            
            // Check for existing placeholder and upgrade it instead of creating duplicate
            try {
                if ($leadId) {
                    // Check if there's a placeholder sample request for this lead
                    $placeholderStmt = $pdo->prepare("
                        SELECT id FROM sample_requests
                        WHERE lead_id = ? 
                        AND status = 'pending'
                        AND (agent_notes IS NULL OR agent_notes = '')
                        AND (recipient_address_line1 IS NULL OR recipient_address_line1 = '')
                        ORDER BY requested_at DESC
                        LIMIT 1
                    ");
                    $placeholderStmt->execute([$leadId]);
                    $placeholderId = $placeholderStmt->fetchColumn();
                    
                    if ($placeholderId) {
                        // Update placeholder with real data from promo portal instead of creating new
                        $pdo->prepare("
                            UPDATE sample_requests 
                            SET recipient_name = ?,
                                recipient_email = ?,
                                recipient_phone = ?,
                                recipient_address_line1 = ?,
                                recipient_address_line2 = ?,
                                recipient_city = ?,
                                recipient_state = ?,
                                recipient_zip = ?,
                                recipient_country = ?,
                                promo_source = 'product-pack-trial',
                                agent_notes = 'Customer completed promo portal signup',
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([
                            $recipientData['name'],
                            $recipientData['email'],
                            $recipientData['phone'],
                            $recipientData['address_line1'],
                            $recipientData['address_line2'],
                            $recipientData['city'],
                            $recipientData['state'],
                            $recipientData['zip'],
                            $recipientData['country'],
                            $placeholderId
                        ]);
                        
                        // Use the upgraded placeholder instead of the newly created request
                        $sampleRequestId = $placeholderId;
                        
                        error_log("Promo portal: Upgraded placeholder #{$placeholderId} with real data for lead #{$leadId}");
                    }
                }
            } catch (Throwable $e) {
                error_log("Promo portal: Failed to check/upgrade placeholder: " . $e->getMessage());
                // Non-blocking - will use newly created sample_request
            }
            
            // Log CRM activity
            if ($leadId && $sampleRequestId) {
                log_sample_crm_activity(
                    $pdo,
                    $leadId,
                    'sample_request_created',
                    'Customer created account and requested product-pack sample',
                    [
                        'sample_request_id' => $sampleRequestId,
                        'source' => 'product-pack-trial',
                        'store_name' => $storeName
                    ]
                );
            }
            
            // Send email notifications
            if ($sampleRequestId) {
                send_sample_email_notification($pdo, 'account_created', $sampleRequestId, [
                    'recipient_email' => $foundStore['email'],
                    'recipient_name' => $firstName . ' ' . $lastName,
                    'customer_phone' => $phone
                ]);
            }
            
            $pdo->commit();
            $step = 'demo_content';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $createAccountError = 'Error creating account: ' . $e->getMessage();
            $step = 'create_account';
        }
    } else {
        $createAccountError = implode('<br>', $errors);
        $step = 'create_account';
    }
}

// Get agents list for review form
$agents = [];
try {
    if ($pdo && $step === 'demo_content') {
        $agents = $pdo->query("
            SELECT u.id, CONCAT(u.first_name,' ',u.last_name) AS name 
            FROM users u 
            JOIN user_roles ur ON ur.user_id=u.id 
            JOIN roles r ON r.id=ur.role_id 
            WHERE r.name='sales_agent' 
            ORDER BY name
        ")->fetchAll();
    }
} catch (Throwable $e) { $agents = []; }

// Get available sample products from database with inventory
$sampleProducts = [];
$hasInventory = false;
try {
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT 
                p.*,
                si.quantity_available,
                si.quantity_reserved,
                (si.quantity_available - si.quantity_reserved) AS truly_available,
                pg.name AS group_name,
                pgc.commission_percent
            FROM products p
            LEFT JOIN sample_inventory si ON si.product_id = p.id
            LEFT JOIN product_groups pg ON pg.id = p.product_group_id
            LEFT JOIN product_group_commissions pgc ON pgc.product_group_id = p.product_group_id AND pgc.tier_name = 'Tier 1'
            WHERE p.is_sample = 1 AND p.active = 1
            ORDER BY p.attribute_per_oz ASC
        ");
        $sampleProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasInventory = !empty($sampleProducts) && array_sum(array_column($sampleProducts, 'truly_available')) > 0;
    }
} catch (Throwable $e) { 
    $sampleProducts = []; 
    $hasInventory = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product product-pack Demo — Product B product</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --blue:#1e40af; --blue-2:#3b82f6; --green:#059669; --gold:#f59e0b;
            --text:#0f172a; --muted:#64748b; --border:#e5e7eb; --bg:#f8fafc; --card:#ffffff;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%);color:var(--text)}
        .container{max-width:1100px;margin:0 auto;padding:20px}
        .card{background:var(--card);border:2px solid var(--border);border-radius:16px;box-shadow:0 25px 70px rgba(2,6,23,.06);padding:24px}
        h1{font-size:42px;line-height:1.1;margin:0 0 10px 0;color:var(--blue)}
        h2{font-size:28px;color:var(--blue);margin:0 0 8px 0}
        .subtitle{color:#334155;font-size:18px;margin-bottom:16px}
        .btn{appearance:none;border:0;border-radius:10px;padding:12px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
        .btn-primary{background:linear-gradient(135deg, var(--blue-2), var(--blue));color:#fff}
        .btn-ghost{background:#fff;color:var(--blue);border:1px solid #bfdbfe}
        .btn-secondary{background:#f3f4f6;color:var(--text);border:1px solid var(--border)}
        .input, select, textarea{width:100%;padding:12px;border-radius:10px;border:1px solid var(--border);background:#fff;font-size:16px}
        .input:focus, select:focus, textarea:focus{outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.2);border-color:#93c5fd}
        label{display:block;font-weight:600;margin:10px 0 6px}
        .error{background:#fee2e2;border:1px solid #fecaca;color:#dc2626;padding:12px;border-radius:8px;margin-bottom:16px}
        .note{font-size:12px;color:var(--muted)}
        
        /* Search interface */
        .search-container{max-width:600px;margin:0 auto;text-align:center}
        .search-box{position:relative;margin:24px 0}
        .search-input{font-size:18px;padding:16px 20px;padding-right:60px}
        .search-btn{position:absolute;right:4px;top:50%;transform:translateY(-50%);padding:12px 24px}
        
        /* Store results */
        .store-results{margin:24px 0}
        .store-option{padding:16px;background:#f9fafb;border:2px solid transparent;border-radius:8px;margin-bottom:12px;cursor:pointer;transition:all 0.2s}
        .store-option:hover{border-color:var(--blue);background:#f0f9ff}
        .store-option.selected{border-color:var(--blue);background:#f0f9ff}
        
        /* Loading state */
        .loading{display:none;text-align:center;padding:24px;color:var(--muted)}
        .loading.active{display:block}
        
        @media (max-width: 600px){
            h1{font-size:32px}
            .search-input{font-size:16px;padding:12px 16px;padding-right:50px}
            .search-btn{padding:10px 16px;font-size:14px}
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Deprecation Notice -->
    <?php if ($showDeprecationNotice): ?>
    <div class="card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #d97706;padding:20px;margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="font-size:48px;">⚠️</div>
            <div style="flex:1;">
                <h3 style="margin:0 0 8px;color:#92400e;">System Update Notice</h3>
                <p style="margin:0 0 12px;color:#78350f;">
                    This product-pack trial system is being phased out. Sales agents should use the new 
                    <strong>Sample Request System</strong> for all sample distribution going forward.
                </p>
                <a href="/onboarding/sales-agent/request-sample.php" 
                   style="display:inline-block;background:#059669;color:white;padding:8px 16px;border-radius:6px;text-decoration:none;font-weight:600;">
                    → Use New Sample Request System
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <div class="card" style="padding:14px; display:flex; align-items:center; gap:14px; margin-bottom:18px;">
        <img src="/assets/logo.svg" alt="Product" width="120" height="36" style="display:block">
        <div style="margin-left:auto; display:flex; gap:8px; flex-wrap:wrap">
            <?php if ($step === 'demo_content'): ?>
            <a class="btn btn-ghost" href="#dosing">Quick Dosing</a>
            <a class="btn btn-ghost" href="#facts">Nutrition Facts</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($step === 'user_type'): ?>
    <!-- Step 0: Select User Type -->
    <div class="search-container">
        <div class="card" style="max-width:700px;margin:0 auto;">
            <h1>Welcome to Product™ product-pack Trial</h1>
            <p class="subtitle">Are you a retail customer or a business?</p>
            
            <form method="post" style="margin-top:32px;">
                <input type="hidden" name="action" value="select_user_type">
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
                    <!-- Retail Customer -->
                    <label class="user-type-card" onclick="this.querySelector('input').checked=true;" style="cursor:pointer;padding:32px 24px;border:3px solid #e5e7eb;border-radius:16px;background:#fff;transition:all 0.3s;text-align:center;">
                        <input type="radio" name="user_type" value="retail" required style="display:none;">
                        <div style="font-size:48px;margin-bottom:16px;">🛍️</div>
                        <h3 style="margin:0 0 12px;font-size:22px;color:#1e40af;">Retail Customer</h3>
                        <p style="margin:0;font-size:14px;color:#64748b;line-height:1.5;">
                            I'm shopping for personal use and want to try Product
                        </p>
                    </label>
                    
                    <!-- Business -->
                    <label class="user-type-card" onclick="this.querySelector('input').checked=true;" style="cursor:pointer;padding:32px 24px;border:3px solid #e5e7eb;border-radius:16px;background:#fff;transition:all 0.3s;text-align:center;">
                        <input type="radio" name="user_type" value="business" required style="display:none;">
                        <div style="font-size:48px;margin-bottom:16px;">🏪</div>
                        <h3 style="margin:0 0 12px;font-size:22px;color:#059669;">Business</h3>
                        <p style="margin:0;font-size:14px;color:#64748b;line-height:1.5;">
                            I own or manage a business and want to carry Products
                        </p>
                    </label>
                </div>
                
                <?php if ($searchError): ?>
                <div class="error"><?= htmlspecialchars($searchError) ?></div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary" style="width:100%;padding:16px;font-size:18px;">
                    Continue
                </button>
            </form>
            
            <p class="note" style="text-align:center;margin-top:20px;">
                Both retail customers and businesses can request samples and try our products
            </p>
        </div>
    </div>
    
    <style>
    .user-type-card:hover {
        border-color: #3b82f6 !important;
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(59,130,246,0.15);
    }
    .user-type-card:has(input:checked) {
        border-color: #3b82f6 !important;
        background: #f0f9ff !important;
    }
    @media (max-width: 600px) {
        .card > form > div {
            grid-template-columns: 1fr !important;
        }
    }
    </style>

    <?php elseif ($step === 'search'): ?>
    <!-- Step 1: Store Search -->
    <div class="search-container">
        <div class="card">
            <h1>Welcome to Your Product Demo</h1>
            <p class="subtitle">
                <?php if ($userType === 'retail'): ?>
                    Let's get you started with your product-pack sample experience.
                <?php else: ?>
                    Let's find your store to get started with your product-pack demo experience.
                <?php endif; ?>
            </p>
            
            <form method="post" onsubmit="showLoading()">
                <input type="hidden" name="action" value="search_store">
                <div class="search-box">
                    <input type="text" name="search_term" class="input search-input" 
                           placeholder="Search by store name, email, or phone..." 
                           required minlength="2" autofocus>
                    <button type="submit" class="btn btn-primary search-btn">Search</button>
                </div>
            </form>
            
            <?php if ($searchError): ?>
            <div class="error"><?= htmlspecialchars($searchError) ?></div>
            <?php endif; ?>
            
            <div class="loading" id="loading">
                <p>Searching for your store...</p>
            </div>
            
            <p class="note">
                <?php if ($userType === 'retail'): ?>
                    Enter your email to check if you have an existing account, or create a new one.
                <?php else: ?>
                    Enter your store name, email address, or phone number to continue.
                    If we can't find your store, you'll be able to create a new account.
                <?php endif; ?>
            </p>
            
            <?php if ($userType === 'retail'): ?>
            <div style="text-align:center;margin-top:20px;">
                <p style="color:#64748b;font-size:14px;margin-bottom:12px;">— OR —</p>
                <a href="?step=retail_signup" class="btn btn-primary">Create New Retail Account</a>
            </div>
            <?php endif; ?>
            
            <div style="text-align:center;margin-top:20px;">
                <a href="?step=user_type" class="btn btn-secondary">← Back to User Type</a>
            </div>
        </div>
    </div>

    <?php elseif ($step === 'retail_signup'): ?>
    <!-- Retail Customer Direct Signup -->
    <div class="search-container">
        <div class="card" style="max-width:700px;margin:0 auto">
            <h2>Create Your Retail Account</h2>
            <p class="subtitle">Get your free product-pack sample and start enjoying Product!</p>
            
            <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $err): ?>
                    • <?= htmlspecialchars($err) ?><br>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <form method="post" style="text-align:left">
                <input type="hidden" name="action" value="create_retail_account">
                
                <h4 style="margin:0 0 16px 0;color:#0369a1">Personal Information</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div>
                        <label>First Name *</label>
                        <input type="text" name="first_name" class="input" required placeholder="John">
                    </div>
                    <div>
                        <label>Last Name *</label>
                        <input type="text" name="last_name" class="input" required placeholder="Doe">
                    </div>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
                    <div>
                        <label>Email *</label>
                        <input type="email" name="email" class="input" required placeholder="sample-001@example.com">
                    </div>
                    <div>
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" class="input" required placeholder="XXX-XXX-XXXX">
                    </div>
                </div>
                
                <h4 style="margin:24px 0 12px 0;color:#0369a1">Shipping Address</h4>
                <div>
                    <label>Street Address *</label>
                    <input type="text" name="address" class="input" required placeholder="Street address">
                </div>
                
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-top:16px">
                    <div>
                        <label>City *</label>
                        <input type="text" name="city" class="input" required placeholder="City">
                    </div>
                    <div>
                        <label>State *</label>
                        <select name="state" class="input" required>
                            <option value="">Select State</option>
                            <?php 
                            $states = ['AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming'];
                            foreach ($states as $code => $name): ?>
                                <option value="<?= $code ?>"><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>ZIP Code *</label>
                        <input type="text" name="zip_code" class="input" required placeholder="12345">
                    </div>
                </div>
                
                <h4 style="margin:24px 0 12px 0;color:#0369a1">Account Security</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div>
                        <label>Password * (min 8 characters)</label>
                        <input type="password" name="password" class="input" required minlength="8">
                    </div>
                    <div>
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" class="input" required minlength="8">
                    </div>
                </div>
                
                <div style="background:#ecfdf5;border:1px solid #a7f3d0;padding:16px;border-radius:8px;margin-top:20px;">
                    <label style="display:flex;align-items:start;gap:8px;cursor:pointer;margin:0;">
                        <input type="checkbox" name="consent_email" value="1" checked required style="margin-top:2px;">
                        <span style="color:#14532d;font-size:13px;">I consent to receive email communications from Product</span>
                    </label>
                </div>
                
                <div style="display:flex;gap:12px;margin-top:24px">
                    <a href="?step=search" class="btn btn-secondary">← Back</a>
                    <button type="submit" class="btn btn-primary">Create Account & Get Sample</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($step === 'select'): ?>
    <!-- Step 2: Select Store -->
    <div class="search-container">
        <div class="card">
            <h2>Select Your Store</h2>
            <p class="subtitle">We found the following matches. Please select your store:</p>
            
            <form method="post">
                <input type="hidden" name="action" value="select_store">
                <div class="store-results">
                    <?php foreach ($_SESSION['demo_search_results'] ?? [] as $result): ?>
                    <label class="store-option">
                        <input type="radio" name="selected_store_id" value="<?= $result['id'] ?>" 
                               style="display:none" onchange="this.parentElement.classList.add('selected')">
                        <div style="font-weight:600"><?= htmlspecialchars($result['store_name'] ?? $result['owner_name']) ?></div>
                        <div style="font-size:14px;color:var(--muted);margin-top:4px">
                            <?= htmlspecialchars($result['email']) ?>
                            <?php if ($result['phone']): ?> • <?= htmlspecialchars($result['phone']) ?><?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                
                <div style="display:flex;gap:12px;margin-top:16px">
                    <a href="?step=search" class="btn btn-secondary">← Back to Search</a>
                    <button type="submit" class="btn btn-primary">Continue</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($step === 'not_found'): ?>
    <!-- Step 2b: Not Found -->
    <div class="search-container">
        <div class="card">
            <h2>Store Not Found</h2>
            <p class="subtitle">We couldn't find your store in our system. Would you like to create a new account?</p>
            
            <div style="display:flex;gap:12px;justify-content:center;margin-top:24px">
                <a href="?step=search" class="btn btn-secondary">← Try Another Search</a>
                <a href="/api/demo-request.php?new=1" class="btn btn-primary">Create Account & Request Demo</a>
            </div>
            
            <p class="note" style="margin-top:24px">
                If you received a demo bottle from your sales agent, they may not have entered your information yet.
                You can create an account now and we'll link it when they update their records.
            </p>
        </div>
    </div>

    <?php elseif ($step === 'login'): ?>
    <!-- Step 3a: Login -->
    <div class="search-container">
        <div class="card">
            <h2>Welcome Back!</h2>
            <p class="subtitle">We found your account. Please login to continue to your demo review.</p>
            
            <?php if (isset($loginError)): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            
            <form method="post" style="text-align:left">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['demo_selected_store']['email']) ?>">
                
                <div style="background:#f0f9ff;padding:12px;border-radius:8px;margin-bottom:16px">
                    <div style="font-weight:600"><?= htmlspecialchars($_SESSION['demo_selected_store']['store_name'] ?? $_SESSION['demo_selected_store']['first_name'] . ' ' . $_SESSION['demo_selected_store']['last_name']) ?></div>
                    <div style="font-size:14px;color:#6b7280;margin-top:4px"><?= htmlspecialchars($_SESSION['demo_selected_store']['email']) ?></div>
                </div>
                
                <label>Password</label>
                <input type="password" name="password" class="input" required autofocus>
                
                <div style="display:flex;gap:12px;margin-top:24px">
                    <a href="?step=search" class="btn btn-secondary">← Back</a>
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
                
                <p class="note" style="margin-top:16px">
                    <a href="/login.php" style="color:var(--blue)">Forgot your password?</a>
                </p>
            </form>
        </div>
    </div>
    
    <?php elseif ($step === 'create_account'): ?>
    <!-- Step 3b: Create Complete Account -->
    <div class="search-container">
        <div class="card" style="max-width:700px;margin:0 auto">
            <h2><?php echo $userType === 'retail' ? 'Create Your Account' : 'Complete Your Wholesale Account'; ?></h2>
            <p class="subtitle">Welcome, <?= htmlspecialchars($_SESSION['demo_selected_store']['first_name'] ?? '') ?>! 
                Please provide the following information to create your account.</p>
            
            <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $err): ?>
                    • <?= htmlspecialchars($err) ?><br>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php 
            $store = $_SESSION['demo_selected_store'];
            ?>
            
            <form method="post" style="text-align:left">
                <input type="hidden" name="action" value="create_account">
                
                <div style="background:#f0f9ff;padding:16px;border-radius:8px;margin-bottom:20px">
                    <h4 style="margin:0 0 12px 0;color:#0369a1">Contact Information</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div>
                            <strong>Name:</strong> <?= htmlspecialchars($store['first_name'] . ' ' . $store['last_name']) ?>
                        </div>
                        <div>
                            <strong>Email:</strong> <?= htmlspecialchars($store['email']) ?>
                        </div>
                    </div>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div>
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" class="input" required 
                               value="<?= htmlspecialchars($store['phone'] ?? '') ?>"
                               placeholder="XXX-XXX-XXXX">
                    </div>
                    <div>
                        <label><?php echo $userType === 'retail' ? 'Full Name *' : 'Store/Business Name *'; ?></label>
                        <input type="text" name="store_name" class="input" required
                               value="<?= htmlspecialchars($store['store_name'] ?? ($store['first_name'] . ' ' . $store['last_name'])) ?>"
                               placeholder="<?php echo $userType === 'retail' ? 'Your Full Name' : 'Your Store Name'; ?>">
                    </div>
                </div>
                
                <h4 style="margin:24px 0 12px 0;color:#0369a1">Shipping Address</h4>
                <div>
                    <label>Street Address *</label>
                    <input type="text" name="address" class="input" required
                           value="<?= htmlspecialchars($store['address'] ?? '') ?>"
                           placeholder="Street address">
                </div>
                
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-top:16px">
                    <div>
                        <label>City *</label>
                        <input type="text" name="city" class="input" required
                               value="<?= htmlspecialchars($store['city'] ?? '') ?>"
                               placeholder="City">
                    </div>
                    <div>
                        <label>State *</label>
                        <select name="state" class="input" required>
                            <option value="">Select State</option>
                            <?php 
                            $states = ['AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming'];
                            foreach ($states as $code => $name): ?>
                                <option value="<?= $code ?>" <?= ($store['state'] ?? '') === $code ? 'selected' : '' ?>><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>ZIP Code *</label>
                        <input type="text" name="zip_code" class="input" required
                               value="<?= htmlspecialchars($store['postal_code'] ?? $store['zip_code'] ?? '') ?>"
                               placeholder="12345">
                    </div>
                </div>
                
                <h4 style="margin:24px 0 12px 0;color:#0369a1">Account Security</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div>
                        <label>Password * (min 8 characters)</label>
                        <input type="password" name="password" class="input" required minlength="8">
                    </div>
                    <div>
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" class="input" required minlength="8">
                    </div>
                </div>
                
                <div style="display:flex;gap:12px;margin-top:24px">
                    <a href="?step=search" class="btn btn-secondary">← Back</a>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $userType === 'retail' ? 'Create Account & Get Sample' : 'Create Account & Continue'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($step === 'select_product'): ?>
    <!-- Step: Select Product -->
    <div class="search-container">
        <div class="card" style="max-width:900px;margin:0 auto;">
            <h2>Which Sample Did You Receive?</h2>
            <p class="subtitle">Pick the product bottle or the premium product format dash you tried so we can load the right guide.</p>
            
            <form method="post">
                <input type="hidden" name="action" value="select_product">
                
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin:24px 0;">
                    <label class="product-select-card" style="cursor:pointer;padding:20px;border:3px solid transparent;border-radius:12px;background:#fef3c7;transition:all 0.2s;">
                        <input type="radio" name="product_type" value="gold_40" required onchange="this.closest('.product-select-card').style.borderColor='#3b82f6'">
                        <img src="/assets/img/product-placeholder.svg" style="max-width:100%;height:200px;object-fit:contain;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <h3 style="color:#92400e;margin:8px 0;text-align:center;">Product B</h3>
                        <p style="color:#78350f;margin:4px 0;text-align:center;">40mg/oz • 120mg total</p>
                    </label>
                    
                    <label class="product-select-card" style="cursor:pointer;padding:20px;border:3px solid transparent;border-radius:12px;background:#dbeafe;transition:all 0.2s;">
                        <input type="radio" name="product_type" value="blue_20" required onchange="this.closest('.product-select-card').style.borderColor='#3b82f6'">
                        <img src="/assets/img/product-placeholder.svg" style="max-width:100%;height:200px;object-fit:contain;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <h3 style="color:#1e40af;margin:8px 0;text-align:center;">Product A</h3>
                        <p style="color:#1e3a8a;margin:4px 0;text-align:center;">20mg/oz • 60mg total</p>
                    </label>

                    <label class="product-select-card" style="cursor:pointer;padding:20px;border:3px solid transparent;border-radius:12px;background:#ecfccb;transition:all 0.2s;">
                        <input type="radio" name="product_type" value="product format_dash" required onchange="this.closest('.product-select-card').style.borderColor='#3b82f6'">
                        <img src="/assets/img/product-placeholder.svg" style="max-width:100%;height:200px;object-fit:contain;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <h3 style="color:#3f6212;margin:8px 0;text-align:center;">Premium product format Dash</h3>
                        <p style="color:#365314;margin:4px 0;text-align:center;">1g ultra-clean product format</p>
                    </label>
                </div>
                
                <div style="text-align:center;margin-top:16px;">
                    <p class="note" style="margin-bottom:16px;">Haven't received a sample yet?</p>
                    <button type="submit" class="btn btn-primary">Continue to Review</button>
                    <a href="?step=request_sample" class="btn btn-secondary" style="margin-left:12px;">Request a Sample</a>
                </div>
            </form>
        </div>
    </div>
    
    <style>
    .product-select-card:hover {border-color: #93c5fd !important;}
    </style>

    <?php elseif ($step === 'request_sample'): ?>
    <!-- Step: Request Sample -->
    <div class="search-container">
        <div class="card" style="max-width:900px;margin:0 auto;">
            <h2>Request Your Sample</h2>
            <p class="subtitle">Pick Blue 60, Gold 120, or the 1g premium product format dash. Your sales agent is notified instantly.</p>
            
            <form method="post" id="requestSampleForm">
                <input type="hidden" name="action" value="request_sample">
                
                <!-- Product Selection -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin:24px 0;">
                    <label class="product-select-card" style="cursor:pointer;padding:20px;border:3px solid transparent;border-radius:12px;background:#fef3c7;transition:all 0.2s;">
                        <input type="radio" name="product_type" value="gold_40" required onchange="this.closest('.product-select-card').style.borderColor='#3b82f6'">
                        <img src="/assets/img/product-placeholder.svg" style="max-width:100%;height:200px;object-fit:contain;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <h3 style="color:#92400e;margin:8px 0;text-align:center;">Product B</h3>
                        <p style="color:#78350f;margin:4px 0;text-align:center;">40mg/oz • 120mg total</p>
                    </label>
                    
                    <label class="product-select-card" style="cursor:pointer;padding:20px;border:3px solid transparent;border-radius:12px;background:#dbeafe;transition:all 0.2s;">
                        <input type="radio" name="product_type" value="blue_20" required onchange="this.closest('.product-select-card').style.borderColor='#3b82f6'">
                        <img src="/assets/img/product-placeholder.svg" style="max-width:100%;height:200px;object-fit:contain;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <h3 style="color:#1e40af;margin:8px 0;text-align:center;">Product A</h3>
                        <p style="color:#1e3a8a;margin:4px 0;text-align:center;">20mg/oz • 60mg total</p>
                    </label>

                    <label class="product-select-card" style="cursor:pointer;padding:20px;border:3px solid transparent;border-radius:12px;background:#ecfccb;transition:all 0.2s;">
                        <input type="radio" name="product_type" value="product format_dash" required onchange="this.closest('.product-select-card').style.borderColor='#3b82f6'">
                        <img src="/assets/img/product-placeholder.svg" style="max-width:100%;height:200px;object-fit:contain;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <h3 style="color:#3f6212;margin:8px 0;text-align:center;">Premium product format Dash</h3>
                        <p style="color:#365314;margin:4px 0;text-align:center;">1g ultra-clean product format</p>
                    </label>
                </div>
                
                <!-- Agent Selection -->
                <label>Your Sales Agent *</label>
                <select name="sales_agent_id" class="input" required>
                    <option value="">Select your agent</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= intval($a['id']) ?>"><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <div id="requestMessage" style="margin-top:16px;"></div>
                
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:20px;">Request Sample</button>
                <a href="?step=search" class="btn btn-secondary" style="width:100%;margin-top:12px;display:block;text-align:center;">← Back</a>
            </form>
        </div>
    </div>
    
    <script>
    document.getElementById('requestSampleForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = document.getElementById('requestMessage');
        const submitBtn = e.target.querySelector('button[type="submit"]');
        
        msg.textContent = 'Submitting request...';
        msg.style.color = '#6b7280';
        submitBtn.disabled = true;
        
        const data = new FormData(e.target);
        
        try {
            const res = await fetch('/api/promo-product-pack-submit.php', {
                method: 'POST',
                body: data
            });
            const json = await res.json();
            
            if (json.success) {
                msg.textContent = json.message || 'Sample request submitted! Your sales agent will contact you shortly.';
                msg.style.color = '#059669';
                msg.style.background = '#d1fae5';
                msg.style.padding = '12px';
                msg.style.borderRadius = '8px';
                setTimeout(() => {
                    window.location.href = '?step=demo_content';
                }, 2000);
            } else {
                msg.textContent = 'Error: ' + (json.error || 'Please try again.');
                msg.style.color = '#dc2626';
                msg.style.background = '#fee2e2';
                msg.style.padding = '12px';
                msg.style.borderRadius = '8px';
                submitBtn.disabled = false;
            }
        } catch (err) {
            msg.textContent = 'Network error. Please try again.';
            msg.style.color = '#dc2626';
            msg.style.background = '#fee2e2';
            msg.style.padding = '12px';
            msg.style.borderRadius = '8px';
            submitBtn.disabled = false;
        }
    });
    </script>

    <?php elseif ($step === 'demo_content'): ?>
    <!-- Main Demo Content -->
    <?php 
    // Get current user info if logged in
    $userInfo = null;
    if ($currentUserId) {
        try {
            $stmt = $pdo->prepare('
                SELECT u.*, wa.business_name 
                FROM users u 
                LEFT JOIN wholesale_accounts wa ON wa.user_id = u.id 
                WHERE u.id = ?
            ');
            $stmt->execute([$currentUserId]);
            $userInfo = $stmt->fetch();
        } catch (Exception $e) {}
    }
    
    // Check for existing review (most recent by email)
    $existingReview = null;
    $canEditReview = false;
    $reviewEmail = $userInfo['email'] ?? $_SESSION['demo_selected_store']['email'] ?? null;
    
    if ($reviewEmail) {
        try {
            // Only return if a review exists; choose the most recent for this email
            $stmt = $pdo->prepare('
                SELECT r.*, pdl.id AS demo_lead_id,
                       DATEDIFF(NOW(), r.created_at) AS days_old
                FROM promo_demo_reviews r
                JOIN promo_demo_leads pdl ON pdl.id = r.demo_lead_id
                WHERE pdl.contact_email = ?
                ORDER BY r.created_at DESC
                LIMIT 1
            ');
            $stmt->execute([$reviewEmail]);
            $result = $stmt->fetch();
            
            if ($result && $result['rating']) {
                $existingReview = $result;
                $canEditReview = ($result['days_old'] <= 3);
            }
        } catch (Exception $e) {}
    }
    ?>
    
    <!-- Hero Section -->
    <div class="card" style="margin-bottom:16px;">
        <div style="text-align:center;margin-bottom:24px;">
            <h1 style="margin-bottom:12px;">Product product-pack Demo — Premium Product</h1>
            <p class="subtitle">Available in two concentrations: Product B (120mg total) and Product A (60mg total)</p>
            
            <?php if ($userInfo): ?>
            <div style="background:<?php echo $isRetailCustomer ? '#ecfdf5' : '#f0f9ff'; ?>;padding:12px 16px;border-radius:8px;margin:16px auto;max-width:500px;">
                <div style="font-weight:600;color:<?php echo $isRetailCustomer ? '#059669' : '#0369a1'; ?>">
                    Welcome back, <?= htmlspecialchars($userInfo['first_name']) ?>!
                    <span style="font-size:12px;padding:2px 8px;border-radius:12px;margin-left:8px;background:<?php echo $isRetailCustomer ? '#d1fae5' : '#dbeafe'; ?>;color:<?php echo $isRetailCustomer ? '#065f46' : '#1e40af'; ?>">
                        <?php echo $isRetailCustomer ? '🛍️ Retail Customer' : '🏪 Business'; ?>
                    </span>
                </div>
                <div style="font-size:14px;color:<?php echo $isRetailCustomer ? '#047857' : '#0c4a6e'; ?>;margin-top:4px">
                    <?= htmlspecialchars($userInfo['business_name'] ?: $userInfo['email']) ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($agentCard): ?>
            <!-- Agent Business Card Widget -->
            <div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:2px solid #0369a1;border-radius:12px;padding:20px;margin:20px auto;max-width:500px;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                <div style="text-align:center;color:#0c4a6e;font-weight:700;font-size:14px;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:16px;">
                    👤 Your Product Representative
                </div>
                <div style="display:flex;align-items:center;gap:16px;">
                    <?php if (!empty($agentCard['profile_photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($agentCard['profile_photo_path']); ?>" 
                             style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.15);" 
                             alt="<?php echo htmlspecialchars($agentCard['first_name'] . ' ' . $agentCard['last_name']); ?>">
                    <?php else: ?>
                        <div style="width:80px;height:80px;border-radius:50%;background:#0369a1;color:white;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                            <?php echo strtoupper(substr($agentCard['first_name'], 0, 1) . substr($agentCard['last_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:18px;color:#0c4a6e;margin-bottom:4px;">
                            <?php echo htmlspecialchars($agentCard['first_name'] . ' ' . $agentCard['last_name']); ?>
                        </div>
                        <div style="font-size:14px;color:#075985;margin-bottom:8px;">
                            <?php echo htmlspecialchars($agentCard['job_title'] ?? 'Sales Agent'); ?>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <?php if ($agentCard['show_phone'] && !empty($agentCard['phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($agentCard['phone']); ?>" 
                                   style="background:white;color:#0369a1;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid #0369a1;">
                                    📞 Call
                                </a>
                            <?php endif; ?>
                            <?php if ($agentCard['show_email'] && !empty($agentCard['email'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($agentCard['email']); ?>" 
                                   style="background:white;color:#0369a1;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid #0369a1;">
                                    ✉️ Email
                                </a>
                            <?php endif; ?>
                            <a href="/agent/<?php echo urlencode($agentCard['slug']); ?>/card" 
                               target="_blank"
                               style="background:#0369a1;color:white;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;">
                                👤 Full Card
                            </a>
                            <a href="/api/business-card-vcard.php?slug=<?php echo urlencode($agentCard['slug']); ?>" 
                               download
                               style="background:#059669;color:white;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;">
                                <i class="fa-solid fa-address-card"></i> Save Contact
                            </a>
                        </div>
                    </div>
                </div>
                <?php 
                // Get social links for the agent
                $agentSocialLinks = get_card_social_links($agentCard['id']);
                if (!empty($agentSocialLinks)): 
                ?>
                <div style="display:flex;gap:8px;justify-content:center;margin-top:12px;flex-wrap:wrap;">
                    <?php foreach ($agentSocialLinks as $link): ?>
                        <a href="<?php echo htmlspecialchars($link['url']); ?>" 
                           target="_blank" 
                           rel="noopener"
                           title="<?php echo htmlspecialchars($link['display_label']); ?>"
                           style="width:36px;height:36px;border-radius:50%;background:white;color:<?php echo htmlspecialchars($link['color'] ?? '#0369a1'); ?>;display:flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid #bae6fd;transition:all 0.2s;">
                            <i class="<?php echo htmlspecialchars($link['icon_class']); ?>"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="badges" style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0;justify-content:center;">
                <span class="badge" style="background:#eff6ff;color:var(--blue);border:1px solid #bfdbfe;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600">quality records Process</span>
                <span class="badge" style="background:#eff6ff;color:var(--blue);border:1px solid #bfdbfe;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600">catalog-ready</span>
                <span class="badge" style="background:#eff6ff;color:var(--blue);border:1px solid #bfdbfe;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600">Third-Party Lab Verified</span>
                <span class="badge" style="background:#eff6ff;color:var(--blue);border:1px solid #bfdbfe;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600">THC-Free</span>
            </div>
            
            <div style="display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;justify-content:center;">
                <a class="btn btn-primary" href="#review">Leave Your Product Review</a>
                <a class="btn btn-ghost" href="#how">How to Mix</a>
            </div>
        </div>
        
        <!-- Two Product Display -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;margin-top:24px;">
            <!-- Product B -->
            <div style="text-align:center;padding:20px;background:#fef3c7;border:2px solid #f59e0b;border-radius:12px;">
                <img src="/assets/img/product-placeholder.svg" 
                     alt="Product product-pack Product B product" 
                     style="max-width:100%; height:auto; max-height:300px; display:block; margin:0 auto 16px;">
                <h3 style="margin:0 0 8px 0;color:#92400e;font-size:24px;">Product B</h3>
                <p style="color:#78350f;font-size:16px;margin:0 0 16px;">40mg per oz • 120mg total</p>
                <div style="background:#fff;border:2px solid #f59e0b;border-radius:10px;padding:14px;">
                    <div style="font-weight:700;color:#92400e;margin-bottom:10px;">Quick Dosing</div>
                    <div style="margin:6px 0;color:#78350f;"><strong>0.25 oz</strong> (~<?= $quarterOzMl ?> mL) = 10mg</div>
                    <div style="margin:6px 0;color:#78350f;"><strong>0.5 oz</strong> (~<?= $halfOzMl ?> mL) = 20mg</div>
                    <div style="margin:6px 0;color:#78350f;"><strong>0.75 oz</strong> (~<?= round($mlPerOz * 0.75, 1) ?> mL) = 30mg</div>
                </div>
            </div>
            
            <!-- Product A -->
            <div style="text-align:center;padding:20px;background:#dbeafe;border:2px solid #3b82f6;border-radius:12px;">
                <img src="/assets/img/product-placeholder.svg" 
                     alt="Product product-pack Product A product" 
                     style="max-width:100%; height:auto; max-height:300px; display:block; margin:0 auto 16px;">
                <h3 style="margin:0 0 8px 0;color:#1e40af;font-size:24px;">Product A</h3>
                <p style="color:#1e3a8a;font-size:16px;margin:0 0 16px;">20mg per oz • 60mg total</p>
                <div style="background:#fff;border:2px solid #3b82f6;border-radius:10px;padding:14px;">
                    <div style="font-weight:700;color:#1e40af;margin-bottom:10px;">Quick Dosing</div>
                    <div style="margin:6px 0;color:#1e3a8a;"><strong>0.25 oz</strong> (~<?= $quarterOzMl ?> mL) = 5mg</div>
                    <div style="margin:6px 0;color:#1e3a8a;"><strong>0.5 oz</strong> (~<?= $halfOzMl ?> mL) = 10mg</div>
                    <div style="margin:6px 0;color:#1e3a8a;"><strong>1.0 oz</strong> (~<?= round($mlPerOz, 1) ?> mL) = 20mg</div>
                </div>
            </div>
        </div>
        
        <p class="note" style="text-align:center;margin-top:16px;">Use a bar jigger or marked cup for accuracy. Both products are product-pack bottles.</p>
    </div>

    <!-- Available Samples Section -->
    <?php if (!empty($sampleProducts)): ?>
    <div class="card" style="margin-bottom:16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #059669;">
        <h2 style="color:#059669;margin:0 0 12px;">🧪 Available Sample Products</h2>
        <p style="color:#065f46;margin-bottom:20px;">Premium Product samples available for your store</p>
        
        <?php if (!$hasInventory): ?>
        <div style="background:#fef3c7;color:#92400e;padding:12px;border-radius:8px;margin-bottom:16px;">
            ⚠️ Limited availability - Contact your sales representative for current stock
        </div>
        <?php endif; ?>
        
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
            <?php foreach ($sampleProducts as $sample): ?>
            <div class="sample-showcase" style="background:white;border:2px solid #d1fae5;border-radius:12px;padding:18px;">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                    <h3 style="margin:0;color:#1f2937;font-size:20px;"><?php echo htmlspecialchars($sample['name']); ?></h3>
                    <?php if ($sample['truly_available'] > 0): ?>
                    <span style="background:#dcfce7;color:#059669;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;">
                        ✓ In Stock
                    </span>
                    <?php else: ?>
                    <span style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;">
                        Out of Stock
                    </span>
                    <?php endif; ?>
                </div>
                
                <div style="color:#6b7280;font-size:14px;line-height:1.6;margin-bottom:14px;">
                    <?php echo htmlspecialchars($sample['description'] ?? ''); ?>
                </div>
                
                <div style="display:grid;gap:10px;margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;padding:8px;background:#f9fafb;border-radius:6px;">
                        <span style="color:#6b7280;font-weight:600;">Size:</span>
                        <span style="color:#1f2937;font-weight:700;"><?php echo $sample['size_oz']; ?>oz</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px;background:#f9fafb;border-radius:6px;">
                        <span style="color:#6b7280;font-weight:600;">attribute Content:</span>
                        <span style="color:#1f2937;font-weight:700;"><?php echo $sample['attribute_per_oz']; ?>mg/oz</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px;background:#f9fafb;border-radius:6px;">
                        <span style="color:#6b7280;font-weight:600;">Total attribute:</span>
                        <span style="color:#1f2937;font-weight:700;"><?php echo $sample['total_mg']; ?>mg</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px;background:#f9fafb;border-radius:6px;">
                        <span style="color:#6b7280;font-weight:600;">Servings:</span>
                        <span style="color:#1f2937;font-weight:700;"><?php echo $sample['servings_10mg']; ?> (10mg each)</span>
                    </div>
                </div>
                
                <?php 
                // Calculate profit potential - Get full product pricing from product group
                $fullProductWholesale = 169.95; // Default fallback
                $fullProductMSRP = 199.95; // Default fallback
                $commissionRate = floatval($sample['commission_percent'] ?? 15);
                
                // Try to get actual pricing from a full-size product in the same group
                try {
                    $stmt = $pdo->prepare("
                        SELECT wholesale_price, msrp 
                        FROM products 
                        WHERE product_group_id = ? AND is_sample = 0 AND active = 1 
                        ORDER BY size_oz DESC LIMIT 1
                    ");
                    $stmt->execute([$sample['product_group_id']]);
                    $fullProduct = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($fullProduct && $fullProduct['wholesale_price'] > 0) {
                        $fullProductWholesale = floatval($fullProduct['wholesale_price']);
                        $fullProductMSRP = floatval($fullProduct['msrp']);
                    }
                } catch (Throwable $e) {}
                
                $profitPerBottle = $fullProductMSRP - $fullProductWholesale;
                $commissionPerBottle = $fullProductWholesale * ($commissionRate / 100);
                $profit10Bottles = $profitPerBottle * 10;
                $profit50Bottles = $profitPerBottle * 50;
                ?>
                
                <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px;margin-bottom:12px;">
                    <div style="font-weight:700;color:#1e40af;margin-bottom:8px;">💰 Profit Potential (Full Size)</div>
                    <div style="display:grid;gap:6px;font-size:13px;">
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:#475569;">Profit per bottle:</span>
                            <span style="color:#059669;font-weight:700;">$<?php echo number_format($profitPerBottle, 2); ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:#475569;">10 bottles/month:</span>
                            <span style="color:#059669;font-weight:700;">$<?php echo number_format($profit10Bottles, 2); ?>/mo</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:#475569;">50 bottles/month:</span>
                            <span style="color:#059669;font-weight:700;">$<?php echo number_format($profit50Bottles, 2); ?>/mo</span>
                        </div>
                        <?php if ($commissionRate > 0): ?>
                        <div style="display:flex;justify-content:space-between;padding-top:6px;border-top:1px solid #bfdbfe;">
                            <span style="color:#475569;">Your commission:</span>
                            <span style="color:#1e40af;font-weight:700;"><?php echo number_format($commissionRate, 1); ?>% = $<?php echo number_format($commissionPerBottle, 2); ?>/bottle</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="font-size:12px;color:#6b7280;text-align:center;font-style:italic;">
                    Sample sizes shown. Full product pricing: $<?php echo number_format($fullProductWholesale, 2); ?> wholesale, $<?php echo number_format($fullProductMSRP, 2); ?> MSRP
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!$hasInventory): ?>
        <div style="margin-top:16px;padding:12px;background:#fef3c7;color:#92400e;border-radius:8px;text-align:center;">
            <strong>Note:</strong> Sample inventory is currently limited. Contact your sales representative to check availability.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- How to Mix -->
    <div id="how" class="card" style="margin-bottom:16px;">
        <h2>How Product‑It Works</h2>
        <p style="color:#475569">Designed for consistent onset, clean feel, and reliable experience.</p>
        <ol style="padding-left:24px;color:var(--text)">
            <li style="margin:8px 0"><strong>Measure your dose:</strong> Use dosing guide above based on your product (Product B or Product A).</li>
            <li style="margin:8px 0"><strong>Mix thoroughly:</strong> Add to 8–16 oz beverage. Stir or shake vigorously.</li>
            <li style="margin:8px 0"><strong>Wait and assess:</strong> Allow 20–30 minutes. Adjust next dose as needed.</li>
        </ol>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;background:#f8fafc;padding:16px;border-radius:8px;">
            <div>
                <div style="font-weight:700;color:#92400e;margin-bottom:6px;">Product B Recommendations:</div>
                <p class="note" style="margin:0;">Start with 10-20mg. Advanced users may use up to 30mg per serving.</p>
            </div>
            <div>
                <div style="font-weight:700;color:#1e40af;margin-bottom:6px;">Product A Recommendations:</div>
                <p class="note" style="margin:0;">Start with 5-10mg. Ideal for lighter dosing or those new to Products.</p>
            </div>
        </div>
    </div>

    <?php 
    // Check if user is a retail customer
    $isRetailCustomer = false;
    if ($currentUserId && $pdo) {
        try {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = ? AND r.name = "retail_customer"
            ');
            $stmt->execute([$currentUserId]);
            $isRetailCustomer = (bool)$stmt->fetchColumn();
        } catch (Exception $e) {}
    }
    ?>

    <?php if (!$isRetailCustomer): ?>
    <!-- Business Tools Section (Hidden from Retail Customers) -->
    <div class="card" style="margin-bottom:16px;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:2px solid #059669;">
        <h2 style="color:#059669;margin:0 0 16px;">💼 Business Tools & Profit Calculator</h2>
        <p style="color:#065f46;margin-bottom:24px;">Exclusive wholesale business resources and profit analysis tools</p>
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
            <!-- Revenue Calculator -->
            <div style="background:#fff;padding:20px;border-radius:12px;border:2px solid #d1fae5;">
                <h3 style="margin:0 0 12px;color:#059669;font-size:18px;">📊 Revenue Calculator</h3>
                <p style="margin:0 0 16px;color:#334155;font-size:14px;">Calculate potential earnings per bottle based on your pricing</p>
                <a href="/customer/profit-calculator.php" class="btn" style="background:#059669;color:#fff;width:100%;text-align:center;text-decoration:none;display:block;">
                    Open Calculator
                </a>
            </div>
            
            <!-- Wholesale Pricing -->
            <div style="background:#fff;padding:20px;border-radius:12px;border:2px solid #d1fae5;">
                <h3 style="margin:0 0 12px;color:#059669;font-size:18px;">💰 Wholesale Pricing</h3>
                <p style="margin:0 0 16px;color:#334155;font-size:14px;">View current wholesale pricing and volume discounts</p>
                <a href="/shop/" class="btn" style="background:#059669;color:#fff;width:100%;text-align:center;text-decoration:none;display:block;">
                    View Pricing
                </a>
            </div>
            
            <!-- Business Resources -->
            <div style="background:#fff;padding:20px;border-radius:12px;border:2px solid #d1fae5;">
                <h3 style="margin:0 0 12px;color:#059669;font-size:18px;">📚 Business Resources</h3>
                <p style="margin:0 0 16px;color:#334155;font-size:14px;">Marketing materials, menu templates, and sales guides</p>
                <a href="/customer/dashboard.php" class="btn" style="background:#059669;color:#fff;width:100%;text-align:center;text-decoration:none;display:block;">
                    Access Resources
                </a>
            </div>
        </div>
        
        <div style="background:#fff;padding:20px;border-radius:12px;margin-top:20px;">
            <h3 style="margin:0 0 12px;color:#059669;font-size:18px;">💡 Profit Breakdown Example</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                <div>
                    <div style="font-weight:600;color:#065f46;margin-bottom:8px;">Per product-pack Bottle (Product B):</div>
                    <div style="font-size:14px;color:#334155;line-height:1.8;">
                        • Wholesale Cost: $XX.XX<br>
                        • Suggested Retail: $XX.XX<br>
                        • Your Profit: $XX.XX<br>
                        • Margin: XX%
                    </div>
                </div>
                <div>
                    <div style="font-weight:600;color:#065f46;margin-bottom:8px;">Per Serving (10mg):</div>
                    <div style="font-size:14px;color:#334155;line-height:1.8;">
                        • Cost Per Serving: $X.XX<br>
                        • Suggested Price: $X.XX<br>
                        • Profit Per Serving: $X.XX<br>
                        • 12 servings total
                    </div>
                </div>
            </div>
            <p style="margin:16px 0 0;color:#64748b;font-size:13px;">
                *Actual wholesale pricing available in your account. Contact your sales agent for volume discounts.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Nutrition Facts (Available to All Users) -->
    <details id="facts" style="margin-bottom:16px;">
        <summary style="padding:16px; background:#f8fafc; border:2px solid var(--border); border-radius:12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:18px; font-weight:600; color:var(--text)">Nutrition Facts</span>
            <span style="color:#334155; font-weight:600;">Tap to expand</span>
        </summary>
        <div class="card" style="padding:0; overflow:hidden; border:0; margin-top:8px;">
            <div style="padding:18px; border-bottom:2px solid #000; background:#fff;">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <h2 style="margin:0; font-size:28px; color:#000; letter-spacing:.5px;">NUTRITION FACTS</h2>
                    <img src="/assets/logo.svg" alt="Product" width="110" height="32" style="opacity:.85">
                </div>
                <div style="margin-top:4px; color:#000; font-weight:700">Product B: Serving Size 1/4 oz (7.4 mL) — [10 MG] • 12 servings</div>
                <div style="color:#000; font-weight:700">Product A: Serving Size 1/4 oz (7.4 mL) — [5 MG] • 12 servings</div>
            </div>
            <div style="border-top:6px solid #000; border-bottom:6px solid #000; background:#fff;">
                <div style="display:grid; grid-template-columns:1fr 120px; gap:12px; padding:16px; align-items:center;">
                    <div style="font-size:40px; font-weight:800; color:#000;">Calories</div>
                    <div style="text-align:right; font-size:40px; font-weight:800; color:#000;">0</div>
                </div>
            </div>
            <div style="background:#fff; color:#000;">
                <table style="width:100%; border-collapse:collapse; font-size:14px;">
                    <tbody>
                        <tr><td style="border-bottom:2px solid #000; padding:10px 16px; font-weight:700;">Amount Per Serving</td><td style="border-bottom:2px solid #000; padding:10px 16px; text-align:right; font-weight:700;">%DV</td></tr>
                        <tr><td style="border-bottom:1px solid #000; padding:8px 16px;">Total Fat 0g</td><td style="border-bottom:1px solid #000; padding:8px 16px; text-align:right;">0%</td></tr>
                        <tr><td style="padding:8px 16px;">Sodium 5mg</td><td style="padding:8px 16px; text-align:right;">&lt;1%</td></tr>
                        <tr><td style="border-top:1px solid #000; padding:8px 16px;">Total Carbohydrate 5mg</td><td style="border-top:1px solid #000; padding:8px 16px; text-align:right;">&lt;1%</td></tr>
                        <tr><td style="padding:8px 16px;">Protein 0g</td><td style="padding:8px 16px; text-align:right;">0%</td></tr>
                    </tbody>
                </table>
                <div style="border-top:6px solid #000; padding:10px 16px; font-weight:700;">product attribute Speciosa: Product B = 120mg per bottle • Product A = 60mg per bottle</div>
                <div style="padding:10px 16px; font-size:12px; line-height:1.5;">
                    *Percent Daily Values (DV) are based on a 2,000 calorie diet. **Daily Value Not Established. **This product is not regulated by the compliance. This product is not meant to treat, diagnose, cure, heal, or otherwise facilitate any medical or nutritional value.
                </div>
            </div>
        </div>
    </details>

    <!-- Review Form -->
    <div class="card" id="review" style="background:linear-gradient(135deg,#1e3a8a,#1e40af 45%,#3b82f6);color:#fff;">
        <div style="height:6px;background:linear-gradient(90deg,#f59e0b,#fbbf24,#f59e0b);margin:-24px -24px 16px -24px;border-radius:14px 14px 0 0"></div>
        
        <?php if ($existingReview): ?>
            <?php if ($canEditReview): ?>
                <h2 style="color:#fff">Update Your Review</h2>
                <p style="color:#e0f2fe">You can update your review for <?= 3 - $existingReview['days_old'] ?> more day<?= (3 - $existingReview['days_old']) != 1 ? 's' : '' ?>.</p>
            <?php else: ?>
                <h2 style="color:#fff">Your Review</h2>
                <p style="color:#e0f2fe">Thank you for your feedback! The review period has ended.</p>
            <?php endif; ?>
        <?php else: ?>
            <h2 style="color:#fff">Rate & Review Your Demo</h2>
            <p style="color:#e0f2fe">Share your experience with your Product product-pack trial product.</p>
        <?php endif; ?>
        
        <form id="demoForm" method="post" action="/api/promo-product-pack-submit.php">
            <input type="hidden" name="source_id" value="<?= htmlspecialchars($sourceId) ?>">
            <input type="hidden" name="session_id" value="<?= htmlspecialchars($sessionId) ?>">
            <input type="hidden" name="product_type" value="<?= htmlspecialchars($receivedProduct ?? $_SESSION['selected_product_type'] ?? '') ?>">
            
            <?php
            // Get assigned agent if user is linked to a lead
            $assignedAgentId = null;
            if ($userInfo && $userInfo['email']) {
                try {
                    $stmt = $pdo->prepare('SELECT assigned_agent_id FROM crm_leads WHERE email = ? AND assigned_agent_id IS NOT NULL ORDER BY created_at DESC LIMIT 1');
                    $stmt->execute([$userInfo['email']]);
                    $assignedAgentId = $stmt->fetchColumn();
                } catch (Exception $e) {}
            }
            ?>
            
            <?php if ($userInfo): ?>
            <!-- Pre-filled info for logged-in users -->
            <div style="background:rgba(255,255,255,0.1);padding:16px;border-radius:8px;margin-bottom:16px">
                <div style="font-weight:600;margin-bottom:8px">Your Information:</div>
                <div><?= htmlspecialchars($userInfo['first_name'] . ' ' . $userInfo['last_name']) ?></div>
                <div style="font-size:14px;opacity:0.9"><?= htmlspecialchars($userInfo['email']) ?></div>
                <?php if ($userInfo['business_name']): ?>
                <div style="font-size:14px;opacity:0.9"><?= htmlspecialchars($userInfo['business_name']) ?></div>
                <?php endif; ?>
            </div>
            
            <input type="hidden" name="store_name" value="<?= htmlspecialchars($userInfo['business_name'] ?? '') ?>">
            <input type="hidden" name="first_name" value="<?= htmlspecialchars($userInfo['first_name']) ?>">
            <input type="hidden" name="last_name" value="<?= htmlspecialchars($userInfo['last_name']) ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($userInfo['email']) ?>">
            <input type="hidden" name="phone" value="<?= htmlspecialchars($userInfo['phone'] ?? '') ?>">
            <?php else: ?>
            <!-- Manual entry for non-logged in users -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div>
                    <label style="color:#fff">First Name *</label>
                    <input type="text" name="first_name" class="input" required>
                </div>
                <div>
                    <label style="color:#fff">Last Name *</label>
                    <input type="text" name="last_name" class="input" required>
                </div>
            </div>
            <label style="color:#fff">Email *</label>
            <input type="email" name="email" class="input" required>
            <label style="color:#fff">Phone</label>
            <input type="tel" name="phone" class="input">
            <label style="color:#fff">Store Name</label>
            <input type="text" name="store_name" class="input">
            <?php endif; ?>
            
            <label style="color:#fff">Your Sales Agent</label>
            <select name="sales_agent_id" class="input" required>
                <option value="">Select your agent</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= intval($a['id']) ?>" <?= $assignedAgentId == $a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <!-- Star Rating -->
            <label style="color:#fff">Your Rating</label>
            <div id="ratingStars" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                <input type="hidden" name="rating" id="ratingInput" value="<?= $existingReview ? intval($existingReview['rating']) : '' ?>">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" class="star-btn" data-value="<?= $i ?>" aria-label="Rate <?= $i ?> star" style="background:none;border:0;cursor:pointer;font-size:28px;line-height:1;filter:drop-shadow(0 1px 1px rgba(0,0,0,.15))" <?= $existingReview && !$canEditReview ? 'disabled' : '' ?>>
                        <?= ($existingReview && intval($existingReview['rating']) >= $i) ? '★' : '☆' ?>
                    </button>
                <?php endfor; ?>
                <span id="ratingLabel" style="margin-left:6px;opacity:.9;font-size:14px;">Select</span>
            </div>
            
            <label style="color:#fff">Review (optional)</label>
            <textarea name="review_text" rows="5" class="input" 
                      placeholder="Tell us about your experience with the product. What did you like? How did it work for you? Would you recommend it to others?" 
                      style="font-family:inherit;resize:vertical;min-height:120px;line-height:1.6;"
                      <?= $existingReview && !$canEditReview ? 'disabled' : '' ?>><?= $existingReview ? htmlspecialchars($existingReview['review_text'] ?? '') : '' ?></textarea>
            
            <div style="margin-top:16px">
                <?php if ($existingReview && !$canEditReview): ?>
                    <div style="background: rgba(255,255,255,0.2); padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-weight: 600; margin-bottom: 8px;">Review Submitted on <?= date('M j, Y', strtotime($existingReview['created_at'])) ?></div>
                        <div style="font-size: 14px;">Thank you for your feedback! Your sales agent has received your review.</div>
                    </div>
                <?php else: ?>
                    <button class="btn" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#000;width:100%;font-size:16px;padding:14px;font-weight:700;" type="submit">
                        <?= $existingReview ? 'Update Your Review' : 'Submit Your Review' ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>
        
        <div id="formMsg" class="note" style="margin-top:10px;color:#e0f2fe"></div>
    </div>
    
    <?php endif; ?>

    <div class="footer" style="color:#94a3b8;font-size:12px;text-align:center;margin:30px 0">
        © <?= date('Y') ?> Product. All rights reserved.
    </div>
</div>

<script>
function showLoading() {
    document.getElementById('loading')?.classList.add('active');
}

// Handle form submission
const form = document.getElementById('demoForm');
if (form) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = document.getElementById('formMsg');
        const submitBtn = form.querySelector('button[type="submit"]');
        const ratingInput = document.getElementById('ratingInput');
        
        // Validate rating selected (1-5)
        const ratingVal = parseInt(ratingInput?.value || '');
        if (!(ratingVal >= 1 && ratingVal <= 5)) {
            msg.textContent = 'Please select a rating (1-5).';
            msg.style.color = '#fca5a5';
            return;
        }

        msg.textContent = 'Submitting...';
        msg.style.color = '#e0f2fe';
        submitBtn.disabled = true;
        
        const data = new FormData(form);
        
        try {
            const res = await fetch('/api/promo-product-pack-submit.php', {
                method: 'POST',
                body: data
            });
            const raw = await res.text();
            let json;
            try {
                json = JSON.parse(raw);
            } catch (e) {
                throw new Error(raw || 'Invalid server response');
            }
            
            if (json.success) {
                // Fire tracking event
                fire('submit', {demo_lead_id: json.demo_lead_id});
                
                // Show success message with navigation options
                const isUpdate = <?= $existingReview ? 'true' : 'false' ?>;
                const defaultMessage = isUpdate ? 
                    'Your review has been updated successfully!' : 
                    'Thank you! Your review has been submitted successfully. Your sales agent has received your feedback.';
                
                <?php if ($userInfo): ?>
                msg.innerHTML = `
                    <div style="margin-bottom:12px">${json.message || defaultMessage}</div>
                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.2)">
                        <div style="font-weight:600;margin-bottom:12px">What would you like to do next?</div>
                        <div style="display:flex;gap:12px;flex-wrap:wrap">
                            <a href="/shop/" class="btn" style="background:linear-gradient(135deg,#10b981,#059669);color:white;text-decoration:none;padding:10px 20px;border-radius:8px;display:inline-block">
                                🛍️ Visit Shop
                            </a>
                            <a href="/customer/" class="btn" style="background:rgba(255,255,255,0.2);color:white;text-decoration:none;padding:10px 20px;border-radius:8px;display:inline-block;border:1px solid rgba(255,255,255,0.4)">
                                📊 Customer Dashboard
                            </a>
                        </div>
                    </div>
                `;
                msg.style.color = '#86efac';
                
                // Reload page after update to show the review is locked
                if (isUpdate) {
                    setTimeout(() => location.reload(), 3000);
                }
                <?php else: ?>
                msg.textContent = json.message || defaultMessage;
                msg.style.color = '#86efac';
                if (!isUpdate) form.reset();
                <?php endif; ?>
            } else {
                msg.textContent = 'Error: ' + (json.error || 'Please try again.');
                msg.style.color = '#fca5a5';
            }
        } catch (err) {
            msg.textContent = 'Network error. ' + (err?.message ? String(err.message).slice(0, 300) : 'Please try again.');
            msg.style.color = '#fca5a5';
        } finally {
            submitBtn.disabled = false;
        }
    });
}

// Tracking beacon
(function() {
    const sessionId = <?= json_encode($sessionId) ?>;
    const sourceId = <?= json_encode($sourceId) ?>;
    const beaconUrl = '/api/tracking-beacon.php';
    
    const fire = (type, extra) => {
        try {
            navigator.sendBeacon(beaconUrl, new Blob([JSON.stringify({
                source_id: sourceId,
                session_id: sessionId,
                event_type: type,
                duration_ms: Math.round(performance.now()),
                extra: extra || null
            })], {type: 'application/json'}));
        } catch(e) {}
    };
    // Expose globally so other scripts (e.g., form handler) can call it
    window.fire = fire;
    
    fire('view');
    window.addEventListener('beforeunload', () => fire('engage'));
})();
</script>

<script>
// Star rating behavior
(function(){
    const container = document.getElementById('ratingStars');
    if (!container) return;
    const input = document.getElementById('ratingInput');
    const label = document.getElementById('ratingLabel');
    const stars = Array.from(container.querySelectorAll('.star-btn'));
    const locked = stars.every(btn => btn.disabled);
    const setDisplay = (val) => {
        stars.forEach((btn) => {
            const v = parseInt(btn.getAttribute('data-value'));
            btn.textContent = (val && v <= val) ? '★' : '☆';
        });
        if (label) label.textContent = val ? (val + '/5') : 'Select';
    };
    // initialize
    setDisplay(parseInt(input?.value || ''));
    if (locked) return;
    stars.forEach((btn) => {
        btn.addEventListener('mouseenter', () => setDisplay(parseInt(btn.getAttribute('data-value'))));
        btn.addEventListener('focus', () => setDisplay(parseInt(btn.getAttribute('data-value'))));
        btn.addEventListener('mouseleave', () => setDisplay(parseInt(input.value || '')));
        btn.addEventListener('blur', () => setDisplay(parseInt(input.value || '')));
        btn.addEventListener('click', () => {
            const val = parseInt(btn.getAttribute('data-value'));
            input.value = String(val);
            setDisplay(val);
        });
    });
})();
</script>
</body>
</html>
