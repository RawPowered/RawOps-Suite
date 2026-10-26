<?php
/**
 * User Profile API Endpoint
 * Handles GET (retrieve profile) and PUT (update profile)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../required/config.php';

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check authentication
start_session();
$uid = current_user_id();

if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$pdo = db();

// ============================================================================
// GET - Retrieve user profile
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare('
            SELECT 
                id,
                email,
                first_name,
                last_name,
                phone,
                status,
                created_at,
                last_login_at
            FROM users 
            WHERE id = ?
        ');
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }
        
        // Get user roles
        $stmt = $pdo->prepare('
            SELECT r.name, r.display_name 
            FROM user_roles ur 
            JOIN roles r ON r.id = ur.role_id 
            WHERE ur.user_id = ?
        ');
        $stmt->execute([$uid]);
        $roles = $stmt->fetchAll();
        
        // Get wholesale account info if applicable
        $wholesaleAccount = null;
        $stmt = $pdo->prepare('
            SELECT id, business_name, approved, phone as business_phone
            FROM wholesale_accounts 
            WHERE user_id = ? 
            LIMIT 1
        ');
        $stmt->execute([$uid]);
        $wholesaleAccount = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'phone' => $user['phone'],
                'status' => $user['status'],
                'created_at' => $user['created_at'],
                'last_login_at' => $user['last_login_at'],
                'roles' => $roles,
                'wholesale_account' => $wholesaleAccount
            ]
        ]);
        
    } catch (Throwable $e) {
        error_log('Profile retrieval error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve profile']);
    }
    exit;
}

// ============================================================================
// PUT - Update user profile
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Allowed fields for update
    $allowedFields = ['first_name', 'last_name', 'phone'];
    $updates = [];
    $params = [];
    
    // Validate and collect updates
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $value = $input[$field];
            
            // Validation
            if ($field === 'first_name' || $field === 'last_name') {
                $value = trim($value);
                if (strlen($value) < 1 || strlen($value) > 100) {
                    http_response_code(400);
                    echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' must be between 1 and 100 characters']);
                    exit;
                }
            }
            
            if ($field === 'phone') {
                $value = trim($value);
                if ($value !== '' && strlen($value) > 30) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Phone number must be 30 characters or less']);
                    exit;
                }
            }
            
            $updates[$field] = $value;
            $params[] = $value;
        }
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current values for audit trail
        $stmt = $pdo->prepare('SELECT first_name, last_name, phone FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $oldValues = $stmt->fetch();
        
        // Build update query
        $setClauses = array_map(fn($field) => "`$field` = ?", array_keys($updates));
        $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ', updated_at = NOW() WHERE id = ?';
        $params[] = $uid;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Log each field change
        foreach ($updates as $field => $newValue) {
            $oldValue = $oldValues[$field] ?? null;
            
            // Only log if value actually changed
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
        
        // Return updated profile
        $stmt = $pdo->prepare('
            SELECT id, email, first_name, last_name, phone, status, created_at, last_login_at
            FROM users 
            WHERE id = ?
        ');
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Profile update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update profile']);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
