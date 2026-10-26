<?php
/**
 * Spam Detection and Prevention System
 * Validates registration attempts and detects spam patterns
 */

/**
 * Main spam detection function
 * @param array $data Registration data to validate
 * @return array ['is_spam' => bool, 'violations' => array, 'score' => int, 'data' => array]
 */
function detect_spam_registration(array $data): array {
    $violations = [];
    $score = 0;
    
    $email = $data['email'] ?? '';
    $firstName = $data['first_name'] ?? '';
    $lastName = $data['last_name'] ?? '';
    $phone = $data['phone'] ?? '';
    $businessName = $data['business_name'] ?? '';
    $ipAddress = $data['ip_address'] ?? '';
    
    // 1. Check for numbers in names (HIGH SEVERITY: +50 points)
    if (validate_name_has_numbers($firstName)) {
        $violations[] = 'First name contains numbers';
        $score += 50;
    }
    if (validate_name_has_numbers($lastName)) {
        $violations[] = 'Last name contains numbers';
        $score += 50;
    }
    
    // 2. Check email domain (HIGH SEVERITY: +100 points)
    $domainCheck = validate_email_domain($email);
    if (!$domainCheck['valid']) {
        $violations[] = 'Blocked email domain: ' . $domainCheck['domain'];
        $score += 100;
    }
    
    // 3. Check for spam keywords in all text fields
    $spamKeywords = validate_spam_keywords([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'business_name' => $businessName,
        'email' => $email
    ]);
    if (!empty($spamKeywords)) {
        foreach ($spamKeywords as $field => $keywords) {
            $violations[] = "Spam keywords in {$field}: " . implode(', ', $keywords);
            $score += count($keywords) * 30;
        }
    }
    
    // 4. Check blacklist (CRITICAL: +200 points)
    $blacklistCheck = check_blacklist_memory($email, $firstName, $lastName, $phone, $ipAddress);
    if (!empty($blacklistCheck)) {
        foreach ($blacklistCheck as $match) {
            $violations[] = "Blacklisted {$match['type']}: {$match['value']}";
            $score += 200;
        }
    }
    
    // 5. Check for random character patterns (MEDIUM SEVERITY: +40 points)
    if (is_random_characters($firstName) || is_random_characters($lastName)) {
        $violations[] = 'Random character pattern detected in name';
        $score += 40;
    }
    
    // Spam threshold: 50+ points = spam
    $isSpam = $score >= 50;
    
    return [
        'is_spam' => $isSpam,
        'violations' => $violations,
        'score' => $score,
        'data' => $data
    ];
}

/**
 * Check if name contains numbers
 */
function validate_name_has_numbers(string $name): bool {
    return preg_match('/\d/', $name) === 1;
}

/**
 * Validate email domain against blacklist
 */
function validate_email_domain(string $email): array {
    $blockedDomains = [
        '.ru', '.sx', '.mx', '.ci', '.cx', '.cn', '.br', '.ai', '.io', '.bot'
    ];
    
    $email = strtolower(trim($email));
    $domain = '';
    
    if (strpos($email, '@') !== false) {
        $parts = explode('@', $email);
        $domain = end($parts);
    }
    
    foreach ($blockedDomains as $blocked) {
        if (substr($domain, -strlen($blocked)) === $blocked) {
            return ['valid' => false, 'domain' => $domain];
        }
    }
    
    return ['valid' => true, 'domain' => $domain];
}

/**
 * Check for spam keywords in fields
 * Returns array of fields with detected keywords
 */
function validate_spam_keywords(array $fields): array {
    // Spam keyword patterns (case-insensitive word boundaries)
    $spamPatterns = [
        'credit' => '/\bcredit(?![\s-]union)\b/i', // Allow "credit union"
        'seo' => '/\bseo\b/i',
        'web services' => '/\bweb\s+services?\b/i',
        'rank on google' => '/\brank\s+on\s+google\b/i',
        'google ranking' => '/\bgoogle\s+rank(?:ing)?\b/i',
        'loan' => '/\bloan(?!s?\s+officer)\b/i', // Allow "loan officer"
        'casino' => '/\bcasino\b/i',
        'viagra' => '/\bviagra\b/i',
        'forex' => '/\bforex\b/i',
        'bitcoin' => '/\bbitcoin\b/i',
        'crypto' => '/\bcrypto(?:\s*currency)?\b/i',
        'investment' => '/\binvestment(?!s?\s+advisor)\b/i',
        'make money' => '/\bmake\s+money\b/i',
        'work from home' => '/\bwork\s+from\s+home\b/i',
        'earn money' => '/\bearn\s+money\b/i',
        'get rich' => '/\bget\s+rich\b/i',
        'mlm' => '/\bmlm\b/i',
        'multi-level' => '/\bmulti[\s-]level\b/i'
    ];
    
    $detected = [];
    
    foreach ($fields as $fieldName => $fieldValue) {
        if (empty($fieldValue)) continue;
        
        $fieldValue = strtolower(trim($fieldValue));
        $foundKeywords = [];
        
        foreach ($spamPatterns as $keyword => $pattern) {
            if (preg_match($pattern, $fieldValue)) {
                $foundKeywords[] = $keyword;
            }
        }
        
        if (!empty($foundKeywords)) {
            $detected[$fieldName] = $foundKeywords;
        }
    }
    
    return $detected;
}

/**
 * Check database blacklist (in-memory cache to avoid multiple DB calls)
 */
function check_blacklist_memory(string $email, string $firstName, string $lastName, string $phone, string $ipAddress): array {
    static $blacklistCache = null;
    
    if ($blacklistCache === null) {
        try {
            $pdo = db();
            $stmt = $pdo->query('SELECT entry_type, entry_value FROM spam_blacklist WHERE is_active = 1');
            $blacklistCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $blacklistCache = [];
        }
    }
    
    $matches = [];
    $email = strtolower(trim($email));
    $firstName = strtolower(trim($firstName));
    $lastName = strtolower(trim($lastName));
    $phone = preg_replace('/\D/', '', $phone); // Strip non-digits
    
    foreach ($blacklistCache as $entry) {
        $type = $entry['entry_type'];
        $value = strtolower($entry['entry_value']);
        
        switch ($type) {
            case 'email':
                if ($email === $value) {
                    $matches[] = ['type' => 'email', 'value' => $email];
                }
                break;
                
            case 'domain':
                if (strpos($email, '@') !== false) {
                    $domain = explode('@', $email)[1];
                    if (substr($domain, -strlen($value)) === $value) {
                        $matches[] = ['type' => 'domain', 'value' => $value];
                    }
                }
                break;
                
            case 'name_pattern':
                if (strpos($firstName, $value) !== false || strpos($lastName, $value) !== false) {
                    $matches[] = ['type' => 'name_pattern', 'value' => $value];
                }
                break;
                
            case 'phone':
                if (!empty($phone) && strpos($phone, $value) !== false) {
                    $matches[] = ['type' => 'phone', 'value' => $value];
                }
                break;
                
            case 'ip_address':
                if ($ipAddress === $value) {
                    $matches[] = ['type' => 'ip_address', 'value' => $ipAddress];
                }
                break;
        }
    }
    
    return $matches;
}

/**
 * Detect random character patterns (likely bot-generated names)
 */
function is_random_characters(string $text): bool {
    if (strlen($text) < 5) return false;
    
    // Count consecutive consonants and mixed case without spaces
    $consonantPattern = '/[bcdfghjklmnpqrstvwxyz]{4,}/i';
    $mixedCasePattern = '/([A-Z][a-z]*[A-Z])|([a-z][A-Z])/';
    
    // Random names often have 4+ consecutive consonants
    if (preg_match($consonantPattern, $text)) {
        // And unusual capitalization patterns
        if (preg_match($mixedCasePattern, $text)) {
            return true;
        }
    }
    
    // Check for very high consonant-to-vowel ratio
    $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyz]/i', $text);
    $vowels = preg_match_all('/[aeiou]/i', $text);
    
    if ($vowels > 0 && ($consonants / $vowels) > 4) {
        return true;
    }
    
    return false;
}

/**
 * Log spam attempt to database
 */
function log_spam_attempt(PDO $pdo, array $spamCheck): void {
    try {
        $data = $spamCheck['data'];
        
        $stmt = $pdo->prepare('
            INSERT INTO spam_registration_attempts 
            (email, first_name, last_name, phone_number, business_name, ip_address, user_agent, detected_violations, form_data, spam_score)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['email'] ?? null,
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['phone'] ?? null,
            $data['business_name'] ?? null,
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            json_encode($spamCheck['violations']),
            json_encode($data),
            $spamCheck['score']
        ]);
    } catch (Exception $e) {
        // Silently fail - don't break registration flow
        error_log('Failed to log spam attempt: ' . $e->getMessage());
    }
}

/**
 * Add entry to blacklist
 */
function add_to_blacklist(PDO $pdo, string $entryType, string $entryValue, string $reason, ?int $adminId = null): bool {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE is_active = 1, reason = VALUES(reason)
        ');
        
        return $stmt->execute([$entryType, $entryValue, $reason, $adminId]);
    } catch (Exception $e) {
        error_log('Failed to add blacklist entry: ' . $e->getMessage());
        return false;
    }
}

