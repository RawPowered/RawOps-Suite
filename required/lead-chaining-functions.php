<?php
/**
 * Lead Chaining Functions
 * 
 * Handles email and phone chaining for leads (multi-location stores with same contact info)
 * and tracks lead detail completion timestamps
 */

if (!function_exists('is_valid_lead_email')) {
    /**
     * Check if email is valid (not a placeholder or gibberish)
     * @param string $email Email to validate
     * @return bool True if valid, false if placeholder/gibberish
     */
    function is_valid_lead_email(?string $email): bool
    {
        if (empty($email)) {
            return false;
        }
        
        // Check for placeholder/invalid patterns
        $invalidPatterns = [
            '@unavailable.internal',
            '@noemail.invalid',
            'lead+',
            'auto+',
            'no-email-'
        ];
        
        foreach ($invalidPatterns as $pattern) {
            if (stripos($email, $pattern) !== false) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('get_or_create_email_chain')) {
    /**
     * Get existing email chain or create new one
     * @param PDO $pdo Database connection
     * @param string $email Email address to chain
     * @return int|null Chain ID or null if email is invalid
     */
    function get_or_create_email_chain(PDO $pdo, string $email): ?int
    {
        if (!is_valid_lead_email($email)) {
            return null;
        }
        
        // Check if chain exists
        $stmt = $pdo->prepare('SELECT id FROM crm_lead_email_chains WHERE shared_email = ?');
        $stmt->execute([$email]);
        $chainId = $stmt->fetchColumn();
        
        if ($chainId) {
            return (int)$chainId;
        }
        
        // Create new chain
        $stmt = $pdo->prepare('
            INSERT INTO crm_lead_email_chains (shared_email, chain_notes, created_at)
            VALUES (?, ?, NOW())
        ');
        $stmt->execute([$email, 'Email chain created']);
        
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('get_or_create_phone_chain')) {
    /**
     * Get existing phone chain or create new one
     * @param PDO $pdo Database connection
     * @param string $phone Phone number to chain
     * @return int|null Chain ID or null if phone is invalid
     */
    function get_or_create_phone_chain(PDO $pdo, string $phone): ?int
    {
        if (empty($phone)) {
            return null;
        }
        
        // Normalize phone (digits and + only)
        $normalizedPhone = preg_replace('/[^\d\+]/', '', $phone);
        if (empty($normalizedPhone)) {
            return null;
        }
        
        // Check if chain exists
        $stmt = $pdo->prepare('SELECT id FROM crm_lead_phone_chains WHERE shared_phone = ?');
        $stmt->execute([$normalizedPhone]);
        $chainId = $stmt->fetchColumn();
        
        if ($chainId) {
            return (int)$chainId;
        }
        
        // Create new chain
        $stmt = $pdo->prepare('
            INSERT INTO crm_lead_phone_chains (shared_phone, chain_notes, created_at)
            VALUES (?, ?, NOW())
        ');
        $stmt->execute([$normalizedPhone, 'Phone chain created']);
        
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('link_lead_to_email_chain')) {
    /**
     * Link a lead to an email chain
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @param string $email Email address
     * @return bool Success
     */
    function link_lead_to_email_chain(PDO $pdo, int $leadId, string $email): bool
    {
        $chainId = get_or_create_email_chain($pdo, $email);
        
        if (!$chainId) {
            return false;
        }
        
        // Update lead with chain ID and email
        $stmt = $pdo->prepare('
            UPDATE crm_leads 
            SET email = ?, email_chain_id = ?, email_not_available = 0
            WHERE id = ?
        ');
        
        return $stmt->execute([$email, $chainId, $leadId]);
    }
}

if (!function_exists('link_lead_to_phone_chain')) {
    /**
     * Link a lead to a phone chain
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @param string $phone Phone number
     * @return bool Success
     */
    function link_lead_to_phone_chain(PDO $pdo, int $leadId, string $phone): bool
    {
        $normalizedPhone = preg_replace('/[^\d\+]/', '', $phone);
        $chainId = get_or_create_phone_chain($pdo, $normalizedPhone);
        
        if (!$chainId) {
            return false;
        }
        
        // Update lead with chain ID and phone
        $stmt = $pdo->prepare('
            UPDATE crm_leads 
            SET phone = ?, phone_chain_id = ?, phone_not_available = 0
            WHERE id = ?
        ');
        
        return $stmt->execute([$normalizedPhone, $chainId, $leadId]);
    }
}

if (!function_exists('mark_email_not_available')) {
    /**
     * Mark a lead's email as not available
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @return bool Success
     */
    function mark_email_not_available(PDO $pdo, int $leadId): bool
    {
        // Generate placeholder email
        $placeholderEmail = "no-email-{$leadId}@unavailable.internal";
        
        $stmt = $pdo->prepare('
            UPDATE crm_leads 
            SET 
                email = ?,
                email_not_available = 1,
                email_chain_id = NULL,
                email_completed_at = COALESCE(email_completed_at, NOW())
            WHERE id = ?
        ');
        
        return $stmt->execute([$placeholderEmail, $leadId]);
    }
}

if (!function_exists('mark_phone_not_available')) {
    /**
     * Mark a lead's phone as not available
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @return bool Success
     */
    function mark_phone_not_available(PDO $pdo, int $leadId): bool
    {
        $stmt = $pdo->prepare('
            UPDATE crm_leads 
            SET 
                phone = NULL,
                phone_not_available = 1,
                phone_chain_id = NULL,
                phone_completed_at = COALESCE(phone_completed_at, NOW())
            WHERE id = ?
        ');
        
        return $stmt->execute([$leadId]);
    }
}

if (!function_exists('get_leads_in_email_chain')) {
    /**
     * Get all leads in an email chain
     * @param PDO $pdo Database connection
     * @param int $chainId Email chain ID
     * @return array Array of lead records
     */
    function get_leads_in_email_chain(PDO $pdo, int $chainId): array
    {
        $stmt = $pdo->prepare('
            SELECT l.*, 
                   CONCAT(l.first_name, " ", l.last_name) AS full_name,
                   c.shared_email
            FROM crm_leads l
            INNER JOIN crm_lead_email_chains c ON c.id = l.email_chain_id
            WHERE l.email_chain_id = ?
            ORDER BY l.created_at ASC
        ');
        $stmt->execute([$chainId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_leads_in_phone_chain')) {
    /**
     * Get all leads in a phone chain
     * @param PDO $pdo Database connection
     * @param int $chainId Phone chain ID
     * @return array Array of lead records
     */
    function get_leads_in_phone_chain(PDO $pdo, int $chainId): array
    {
        $stmt = $pdo->prepare('
            SELECT l.*, 
                   CONCAT(l.first_name, " ", l.last_name) AS full_name,
                   c.shared_phone
            FROM crm_leads l
            INNER JOIN crm_lead_phone_chains c ON c.id = l.phone_chain_id
            WHERE l.phone_chain_id = ?
            ORDER BY l.created_at ASC
        ');
        $stmt->execute([$chainId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('update_lead_email_with_chaining')) {
    /**
     * Update lead email with proper chaining and timestamp tracking
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @param string|null $email New email (null to keep existing)
     * @param bool $emailNotAvailable Flag if email is not available
     * @return array Result with success and details
     */
    function update_lead_email_with_chaining(PDO $pdo, int $leadId, ?string $email, bool $emailNotAvailable = false): array
    {
        // Get current lead data
        $stmt = $pdo->prepare('SELECT email, email_not_available, email_completed_at FROM crm_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lead) {
            return ['success' => false, 'error' => 'Lead not found'];
        }
        
        $wasIncomplete = empty($lead['email']) || strpos($lead['email'], '@unavailable.internal') !== false;
        
        if ($emailNotAvailable) {
            mark_email_not_available($pdo, $leadId);
            return ['success' => true, 'action' => 'marked_unavailable'];
        }
        
        if (!empty($email)) {
            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'error' => 'Invalid email format'];
            }
            
            // Link to chain
            link_lead_to_email_chain($pdo, $leadId, $email);
            
            // Set completion timestamp if this is first valid email
            if ($wasIncomplete && empty($lead['email_completed_at'])) {
                $stmt = $pdo->prepare('UPDATE crm_leads SET email_completed_at = NOW() WHERE id = ?');
                $stmt->execute([$leadId]);
            }
            
            // Update details_completed_at if both email and phone now complete
            update_details_completed_timestamp($pdo, $leadId);
            
            return ['success' => true, 'action' => 'email_added', 'chain_id' => get_or_create_email_chain($pdo, $email)];
        }
        
        return ['success' => true, 'action' => 'no_change'];
    }
}

if (!function_exists('update_lead_phone_with_chaining')) {
    /**
     * Update lead phone with proper chaining and timestamp tracking
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @param string|null $phone New phone (null to keep existing)
     * @param bool $phoneNotAvailable Flag if phone is not available
     * @return array Result with success and details
     */
    function update_lead_phone_with_chaining(PDO $pdo, int $leadId, ?string $phone, bool $phoneNotAvailable = false): array
    {
        // Get current lead data
        $stmt = $pdo->prepare('SELECT phone, phone_not_available, phone_completed_at FROM crm_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lead) {
            return ['success' => false, 'error' => 'Lead not found'];
        }
        
        $wasIncomplete = empty($lead['phone']);
        
        if ($phoneNotAvailable) {
            mark_phone_not_available($pdo, $leadId);
            return ['success' => true, 'action' => 'marked_unavailable'];
        }
        
        if (!empty($phone)) {
            // Normalize phone
            $normalizedPhone = preg_replace('/[^\d\+]/', '', $phone);
            
            if (empty($normalizedPhone)) {
                return ['success' => false, 'error' => 'Invalid phone format'];
            }
            
            // Link to chain
            link_lead_to_phone_chain($pdo, $leadId, $normalizedPhone);
            
            // Set completion timestamp if this is first valid phone
            if ($wasIncomplete && empty($lead['phone_completed_at'])) {
                $stmt = $pdo->prepare('UPDATE crm_leads SET phone_completed_at = NOW() WHERE id = ?');
                $stmt->execute([$leadId]);
            }
            
            // Update details_completed_at if both email and phone now complete
            update_details_completed_timestamp($pdo, $leadId);
            
            return ['success' => true, 'action' => 'phone_added', 'chain_id' => get_or_create_phone_chain($pdo, $normalizedPhone)];
        }
        
        return ['success' => true, 'action' => 'no_change'];
    }
}

if (!function_exists('update_details_completed_timestamp')) {
    /**
     * Update details_completed_at timestamp when both email and phone are complete
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @return bool Success
     */
    function update_details_completed_timestamp(PDO $pdo, int $leadId): bool
    {
        $stmt = $pdo->prepare('
            UPDATE crm_leads
            SET details_completed_at = GREATEST(
                COALESCE(email_completed_at, NOW()),
                COALESCE(phone_completed_at, NOW())
            )
            WHERE id = ?
              AND details_completed_at IS NULL
              AND (
                (email IS NOT NULL AND email != "" AND email NOT LIKE "sample-009@example.com") OR email_not_available = 1
              )
              AND (
                (phone IS NOT NULL AND phone != "") OR phone_not_available = 1
              )
        ');
        
        return $stmt->execute([$leadId]);
    }
}

if (!function_exists('calculate_lead_detail_metrics')) {
    /**
     * Calculate detail completion metrics for a lead
     * @param PDO $pdo Database connection
     * @param int $leadId Lead ID
     * @return array Metrics including days to complete email, phone, and both
     */
    function calculate_lead_detail_metrics(PDO $pdo, int $leadId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM crm_leads_detail_status WHERE id = ?');
        $stmt->execute([$leadId]);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$metrics) {
            return [
                'is_detailed' => false,
                'has_email' => false,
                'has_phone' => false,
                'days_to_email' => null,
                'days_to_phone' => null,
                'days_to_complete' => null
            ];
        }
        
        return [
            'is_detailed' => (bool)$metrics['is_detailed_lead'],
            'has_email' => (bool)$metrics['has_email'],
            'has_phone' => (bool)$metrics['has_phone'],
            'days_to_email' => $metrics['days_to_email'],
            'days_to_phone' => $metrics['days_to_phone'],
            'days_to_complete' => $metrics['days_to_complete'],
            'in_email_chain' => (bool)$metrics['in_email_chain'],
            'in_phone_chain' => (bool)$metrics['in_phone_chain'],
            'email_chain_id' => $metrics['email_chain_id'],
            'phone_chain_id' => $metrics['phone_chain_id']
        ];
    }
}

if (!function_exists('get_lead_detail_statistics')) {
    /**
     * Get aggregate statistics for lead detail completion
     * @param PDO $pdo Database connection
     * @param int|null $agentId Filter by agent ID (optional)
     * @return array Statistics
     */
    function get_lead_detail_statistics(PDO $pdo, ?int $agentId = null): array
    {
        $where = $agentId ? 'WHERE l.assigned_agent_id = ?' : '';
        $params = $agentId ? [$agentId] : [];
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_leads,
                SUM(CASE WHEN lds.is_detailed_lead = 1 THEN 1 ELSE 0 END) AS detailed_leads,
                SUM(CASE WHEN lds.is_detailed_lead = 0 THEN 1 ELSE 0 END) AS incomplete_leads,
                SUM(CASE WHEN lds.has_email = 0 THEN 1 ELSE 0 END) AS missing_email,
                SUM(CASE WHEN lds.has_phone = 0 THEN 1 ELSE 0 END) AS missing_phone,
                COUNT(DISTINCT l.email_chain_id) AS email_chains,
                COUNT(DISTINCT l.phone_chain_id) AS phone_chains,
                AVG(lds.days_to_email) AS avg_days_to_email,
                AVG(lds.days_to_phone) AS avg_days_to_phone,
                AVG(lds.days_to_complete) AS avg_days_to_complete,
                SUM(CASE WHEN DATE(l.details_completed_at) = CURDATE() THEN 1 ELSE 0 END) AS detailed_today,
                SUM(CASE WHEN l.details_completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS detailed_this_week,
                SUM(CASE WHEN MONTH(l.details_completed_at) = MONTH(NOW()) AND YEAR(l.details_completed_at) = YEAR(NOW()) THEN 1 ELSE 0 END) AS detailed_this_month
            FROM crm_leads l
            INNER JOIN crm_leads_detail_status lds ON lds.id = l.id
            $where
        ");
        
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

