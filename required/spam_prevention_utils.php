<?php
/**
 * Spam Prevention Utilities
 * 
 * Validation and blacklist checking for spam prevention
 * Implements name validation rules and comprehensive blacklist checking
 */

/**
 * Validate a name field against spam prevention rules
 * 
 * Rules:
 * - Maximum 30 characters
 * - Maximum 3 uppercase letters
 * - Maximum 2 apostrophes
 * 
 * @param string $name The name to validate
 * @param string $field_label The field label for error messages (e.g., "First name")
 * @return array ['valid' => bool, 'errors' => array]
 */
function validate_name_field($name, $field_label = 'Name') {
    $errors = [];
    
    // Trim the name
    $name = trim($name);
    
    // Check if empty
    if (empty($name)) {
        return [
            'valid' => false,
            'errors' => ["$field_label is required."]
        ];
    }
    
    // Rule 1: Maximum 30 characters
    if (mb_strlen($name) > 30) {
        $errors[] = "$field_label cannot exceed 30 characters (currently " . mb_strlen($name) . " characters).";
    }
    
    // Rule 2: Maximum 3 uppercase letters
    $uppercase_count = preg_match_all('/[A-Z]/', $name);
    if ($uppercase_count > 3) {
        $errors[] = "$field_label cannot contain more than 3 uppercase letters (found $uppercase_count).";
    }
    
    // Rule 3: Maximum 2 apostrophes
    $apostrophe_count = substr_count($name, "'") + substr_count($name, "'") + substr_count($name, "'");
    if ($apostrophe_count > 2) {
        $errors[] = "$field_label cannot contain more than 2 apostrophes (found $apostrophe_count).";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Check if email, phone, name, or IP is in spam blacklist
 * 
 * @param PDO $pdo Database connection
 * @param string|null $email Email address to check
 * @param string|null $phone Phone number to check
 * @param string|null $firstName First name to check
 * @param string|null $lastName Last name to check
 * @param string|null $ip IP address to check
 * @return array ['is_spam' => bool, 'violations' => array, 'reasons' => array]
 */
function check_spam_blacklist($pdo, $email = null, $phone = null, $firstName = null, $lastName = null, $ip = null) {
    $violations = [];
    $reasons = [];
    
    try {
        // Check email (exact match and domain)
        if (!empty($email)) {
            $stmt = $pdo->prepare('
                SELECT entry_value, reason 
                FROM spam_blacklist 
                WHERE is_active = 1 
                AND entry_type = "email" 
                AND (entry_value = ? OR ? LIKE CONCAT("%@", entry_value))
            ');
            $stmt->execute([$email, $email]);
            if ($result = $stmt->fetch()) {
                $violations[] = 'email';
                $reasons[] = $result['reason'] ?? 'Email is blacklisted';
            }
            
            // Check domain separately
            $domain = substr(strrchr($email, "@"), 1);
            if ($domain) {
                $stmt = $pdo->prepare('
                    SELECT entry_value, reason 
                    FROM spam_blacklist 
                    WHERE is_active = 1 
                    AND entry_type = "domain" 
                    AND entry_value = ?
                ');
                $stmt->execute([$domain]);
                if ($result = $stmt->fetch()) {
                    $violations[] = 'domain';
                    $reasons[] = $result['reason'] ?? 'Email domain is blacklisted';
                }
            }
        }
        
        // Check phone number
        if (!empty($phone)) {
            $stmt = $pdo->prepare('
                SELECT entry_value, reason 
                FROM spam_blacklist 
                WHERE is_active = 1 
                AND entry_type = "phone" 
                AND entry_value = ?
            ');
            $stmt->execute([$phone]);
            if ($result = $stmt->fetch()) {
                $violations[] = 'phone';
                $reasons[] = $result['reason'] ?? 'Phone number is blacklisted';
            }
        }
        
        // Check name pattern (first + last name combined)
        if (!empty($firstName) && !empty($lastName)) {
            $fullName = trim($firstName . ' ' . $lastName);
            $stmt = $pdo->prepare('
                SELECT entry_value, reason 
                FROM spam_blacklist 
                WHERE is_active = 1 
                AND entry_type = "name_pattern" 
                AND (entry_value = ? OR entry_value = ? OR entry_value = ?)
            ');
            $stmt->execute([$fullName, $firstName, $lastName]);
            if ($result = $stmt->fetch()) {
                $violations[] = 'name_pattern';
                $reasons[] = $result['reason'] ?? 'Name pattern is blacklisted';
            }
        }
        
        // Check IP address
        if (!empty($ip)) {
            $stmt = $pdo->prepare('
                SELECT entry_value, reason 
                FROM spam_blacklist 
                WHERE is_active = 1 
                AND entry_type = "ip_address" 
                AND entry_value = ?
            ');
            $stmt->execute([$ip]);
            if ($result = $stmt->fetch()) {
                $violations[] = 'ip_address';
                $reasons[] = $result['reason'] ?? 'IP address is blacklisted';
            }
        }
        
    } catch (Exception $e) {
        error_log("Spam blacklist check error: " . $e->getMessage());
    }
    
    return [
        'is_spam' => !empty($violations),
        'violations' => array_unique($violations),
        'reasons' => $reasons
    ];
}

/**
 * Add user data to comprehensive spam blacklist
 * Blacklists email, phone, name pattern, and IP address
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID to blacklist
 * @param string $reason Reason for blacklisting
 * @param int|null $adminId Admin user ID performing the action
 * @return array ['success' => bool, 'entries_added' => int, 'details' => array, 'error' => string|null]
 */
function add_to_comprehensive_blacklist($pdo, $userId, $reason = 'Spam user', $adminId = null) {
    $entries_added = 0;
    $details = [];
    
    try {
        // Get user data
        $stmt = $pdo->prepare('
            SELECT email, first_name, last_name, phone, created_at
            FROM users 
            WHERE id = ?
        ');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return [
                'success' => false,
                'entries_added' => 0,
                'details' => [],
                'error' => 'User not found'
            ];
        }
        
        // Try to get registration IP from login_activity or user_activity_log
        $ip = null;
        $stmt = $pdo->prepare('
            SELECT ip_address 
            FROM login_activity 
            WHERE user_id = ? AND action = "login_success"
            ORDER BY created_at ASC 
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $ipResult = $stmt->fetch();
        if ($ipResult) {
            $ip = $ipResult['ip_address'];
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // 1. Blacklist email
        if (!empty($user['email'])) {
            $stmt = $pdo->prepare('
                INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
                VALUES ("email", ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason),
                    added_by_admin_id = VALUES(added_by_admin_id),
                    is_active = 1
            ');
            $stmt->execute([$user['email'], $reason, $adminId]);
            if ($stmt->rowCount() > 0) {
                $entries_added++;
                $details[] = "Email: {$user['email']}";
            }
        }
        
        // 2. Blacklist phone
        if (!empty($user['phone'])) {
            $stmt = $pdo->prepare('
                INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
                VALUES ("phone", ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason),
                    added_by_admin_id = VALUES(added_by_admin_id),
                    is_active = 1
            ');
            $stmt->execute([$user['phone'], $reason, $adminId]);
            if ($stmt->rowCount() > 0) {
                $entries_added++;
                $details[] = "Phone: {$user['phone']}";
            }
        }
        
        // 3. Blacklist name pattern (full name)
        if (!empty($user['first_name']) && !empty($user['last_name'])) {
            $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
            $stmt = $pdo->prepare('
                INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
                VALUES ("name_pattern", ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason),
                    added_by_admin_id = VALUES(added_by_admin_id),
                    is_active = 1
            ');
            $stmt->execute([$fullName, $reason, $adminId]);
            if ($stmt->rowCount() > 0) {
                $entries_added++;
                $details[] = "Name: $fullName";
            }
        }
        
        // 4. Blacklist IP address if available
        if (!empty($ip)) {
            $stmt = $pdo->prepare('
                INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
                VALUES ("ip_address", ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason),
                    added_by_admin_id = VALUES(added_by_admin_id),
                    is_active = 1
            ');
            $stmt->execute([$ip, $reason, $adminId]);
            if ($stmt->rowCount() > 0) {
                $entries_added++;
                $details[] = "IP: $ip";
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'entries_added' => $entries_added,
            'details' => $details,
            'error' => null
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Add to comprehensive blacklist error: " . $e->getMessage());
        return [
            'success' => false,
            'entries_added' => 0,
            'details' => [],
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get spam validation stats for name fields
 * Returns character count, uppercase count, apostrophe count
 * 
 * @param string $name Name to analyze
 * @return array Stats about the name
 */
function get_name_validation_stats($name) {
    $name = trim($name);
    return [
        'length' => mb_strlen($name),
        'uppercase_count' => preg_match_all('/[A-Z]/', $name),
        'apostrophe_count' => substr_count($name, "'") + substr_count($name, "'") + substr_count($name, "'"),
        'length_valid' => mb_strlen($name) <= 30,
        'uppercase_valid' => preg_match_all('/[A-Z]/', $name) <= 3,
        'apostrophe_valid' => (substr_count($name, "'") + substr_count($name, "'") + substr_count($name, "'")) <= 2
    ];
}



