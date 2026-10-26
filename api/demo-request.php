<?php
/**
 * Demo Request API
 * Handle demo kit requests by mail for stores not yet in system
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/demo-functions.php';

// Set JSON response headers
header('Content-Type: application/json');

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'lead_id' => null,
    'user_id' => null
];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Method not allowed';
    echo json_encode($response);
    exit;
}

try {
    start_session();
    $pdo = db();
    
    // Get and validate input
    $store_name = trim($_POST['store_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $postal = trim($_POST['postal'] ?? '');
    $country = trim($_POST['country'] ?? 'USA');
    
    $demo_type = $_POST['demo_type'] ?? 'DEMO-product-pack-32-20';
    $source_id = intval($_POST['source_id'] ?? 0);
    
    // Validation
    if (empty($store_name) || empty($first_name) || empty($last_name) || empty($email)) {
        $response['message'] = 'Please fill in all required fields';
        echo json_encode($response);
        exit;
    }
    
    if (strlen($password) < 8) {
        $response['message'] = 'Password must be at least 8 characters';
        echo json_encode($response);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Check if user already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing_user_id = $stmt->fetchColumn();
    
    if ($existing_user_id) {
        $pdo->rollBack();
        $response['message'] = 'An account with this email already exists';
        echo json_encode($response);
        exit;
    }
    
    // Create user account
    $stmt = $pdo->prepare('
        INSERT INTO users (first_name, last_name, email, password, phone, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $first_name,
        $last_name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $phone ?: null
    ]);
    $user_id = $pdo->lastInsertId();
    
    // Assign wholesale role
    $stmt = $pdo->prepare('
        INSERT INTO user_roles (user_id, role_id)
        SELECT ?, id FROM roles WHERE name = "wholesale_customer"
    ');
    $stmt->execute([$user_id]);
    
    // Create wholesale account
    $stmt = $pdo->prepare('
        INSERT INTO wholesale_accounts (
            user_id, business_name, ein_vat, website, 
            billing_address, billing_city, billing_state, 
            billing_postal_code, billing_country,
            approved_at, approval_status
        ) VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, NOW(), "approved")
    ');
    $stmt->execute([
        $user_id,
        $store_name,
        $address ?: null,
        $city ?: null,
        $state ?: null,
        $postal ?: null,
        $country
    ]);
    
    // Create CRM lead
    $lead_data = [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'phone' => $phone,
        'store_name' => $store_name,
        'agent_id' => null // Will be assigned by CRM rules
    ];
    
    $lead_id = create_lead_from_demo($pdo, $lead_data);
    
    // Create demo request record in promo_demo_leads
    if ($source_id > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO promo_demo_leads (
                source_id, store_name, contact_first_name, 
                contact_last_name, contact_email, contact_phone,
                address_line1, city, state_region, postal_code, country,
                crm_lead_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $source_id,
            $store_name,
            $first_name,
            $last_name,
            $email,
            $phone ?: null,
            $address ?: null,
            $city ?: null,
            $state ?: null,
            $postal ?: null,
            $country,
            $lead_id
        ]);
    }
    
    // Log the user in
    $_SESSION['uid'] = $user_id;
    $_SESSION['email'] = $email;
    $_SESSION['demo_request'] = true;
    
    $pdo->commit();
    
    $response['success'] = true;
    $response['message'] = 'Account created successfully. Demo kit will be sent to your address.';
    $response['lead_id'] = $lead_id;
    $response['user_id'] = $user_id;
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Demo request error: ' . $e->getMessage());
    $response['message'] = 'An error occurred. Please try again.';
    http_response_code(500);
}

echo json_encode($response);
