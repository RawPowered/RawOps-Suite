<?php
/**
 * API Restriction Checker
 * Enforces lead detail completion requirements for API access
 * 
 * Rules:
 * - First 10 leads per agent are exempt
 * - Google Places API requires 80% lead detail completion
 * - AI Email API requires 70% lead detail completion
 * - Admins can grant temporary exemptions
 * 
 * @author Product Development Team
 * @date 2025-10-20
 */

if (!function_exists('check_api_access')) {
    /**
     * Check if user has access to a specific API based on restriction rules
     * 
     * @param int $userId User ID to check
     * @param string $restrictionType 'google_api' or 'ai_email'
     * @return array [
     *   'allowed' => bool,
     *   'reason' => string,
     *   'stats' => array,
     *   'needs_details_count' => int,
     *   'exemption_active' => bool
     * ]
     */
    function check_api_access(int $userId, string $restrictionType = 'google_api'): array
    {
        if (!in_array($restrictionType, ['google_api', 'ai_email'])) {
            return [
                'allowed' => false,
                'reason' => 'Invalid restriction type',
                'stats' => [],
                'needs_details_count' => 0,
                'exemption_active' => false
            ];
        }

        try {
            $pdo = db();
            
            // Check if user is admin - admins always have access
            $adminCheck = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "admin" LIMIT 1');
            $adminCheck->execute([$userId]);
            if ($adminCheck->fetch()) {
                return [
                    'allowed' => true,
                    'reason' => 'Admin access',
                    'stats' => [],
                    'needs_details_count' => 0,
                    'exemption_active' => false
                ];
            }

            // Check for active exemption
            $exemptionCheck = is_agent_exempt($userId, $restrictionType);
            if ($exemptionCheck['exempt']) {
                return [
                    'allowed' => true,
                    'reason' => $exemptionCheck['reason'],
                    'stats' => [],
                    'needs_details_count' => 0,
                    'exemption_active' => true,
                    'exemption_until' => $exemptionCheck['until'] ?? null
                ];
            }

            // Get agent statistics
            $statsStmt = $pdo->prepare('SELECT * FROM agent_lead_detail_statistics WHERE agent_id = ?');
            $statsStmt->execute([$userId]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            if (!$stats) {
                // No leads yet - allow access (first 10 exempt)
                return [
                    'allowed' => true,
                    'reason' => 'No leads yet (first 10 exempt)',
                    'stats' => [
                        'total_leads' => 0,
                        'detailed_leads' => 0,
                        'detail_completion_rate' => 0,
                        'leads_exempt_remaining' => 10
                    ],
                    'needs_details_count' => 0,
                    'exemption_active' => false
                ];
            }

            // Get restriction settings
            $settings = get_agent_restriction_settings($userId, $restrictionType);
            
            // Check if within first N leads exemption
            if ($stats['total_leads'] <= $settings['first_n_leads_exempt']) {
                $remaining = $settings['first_n_leads_exempt'] - $stats['total_leads'];
                return [
                    'allowed' => true,
                    'reason' => "First {$settings['first_n_leads_exempt']} leads exempt ({$remaining} remaining)",
                    'stats' => $stats,
                    'needs_details_count' => 0,
                    'exemption_active' => false
                ];
            }

            // Check completion rate against threshold
            $completionRate = (float)($stats['detail_completion_rate'] ?? 0);
            $threshold = (float)$settings['min_detail_percentage'];

            if ($completionRate >= $threshold) {
                return [
                    'allowed' => true,
                    'reason' => "Meets {$threshold}% threshold",
                    'stats' => $stats,
                    'needs_details_count' => 0,
                    'exemption_active' => false
                ];
            }

            // Calculate how many more leads need details
            $needsDetailsCount = calculate_detail_shortage($userId, $threshold);

            // Access denied
            $apiName = $restrictionType === 'google_api' ? 'Google Places API' : 'AI Email Composer';
            return [
                'allowed' => false,
                'reason' => "Lead detail completion rate ({$completionRate}%) is below required {$threshold}% for {$apiName}",
                'stats' => $stats,
                'needs_details_count' => $needsDetailsCount,
                'exemption_active' => false,
                'threshold' => $threshold
            ];

        } catch (Throwable $e) {
            error_log("API Restriction Check Error: " . $e->getMessage());
            // Fail open for safety
            return [
                'allowed' => true,
                'reason' => 'Error checking restrictions (failed open)',
                'stats' => [],
                'needs_details_count' => 0,
                'exemption_active' => false
            ];
        }
    }
}

if (!function_exists('get_agent_restriction_settings')) {
    /**
     * Get effective restriction settings for an agent
     * Checks per-agent override first, falls back to global defaults
     * 
     * @param int $userId User ID
     * @param string $restrictionType 'google_api' or 'ai_email'
     * @return array Settings array
     */
    function get_agent_restriction_settings(int $userId, string $restrictionType): array
    {
        try {
            $pdo = db();
            
            // Check for per-agent override
            $stmt = $pdo->prepare('
                SELECT * FROM agent_api_restriction_settings 
                WHERE agent_id = ? AND restriction_type = ? AND is_active = 1
                LIMIT 1
            ');
            $stmt->execute([$userId, $restrictionType]);
            $agentSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($agentSettings) {
                return $agentSettings;
            }

            // Fall back to global defaults
            $stmt = $pdo->prepare('
                SELECT * FROM agent_api_restriction_settings 
                WHERE agent_id IS NULL AND restriction_type = ? AND is_active = 1
                LIMIT 1
            ');
            $stmt->execute([$restrictionType]);
            $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($globalSettings) {
                return $globalSettings;
            }

            // Hardcoded fallback if nothing in database
            return [
                'restriction_type' => $restrictionType,
                'min_detail_percentage' => $restrictionType === 'google_api' ? 80.00 : 70.00,
                'first_n_leads_exempt' => 10,
                'exemption_days' => null,
                'exemption_until' => null,
                'is_active' => 1
            ];

        } catch (Throwable $e) {
            error_log("Error getting restriction settings: " . $e->getMessage());
            return [
                'restriction_type' => $restrictionType,
                'min_detail_percentage' => $restrictionType === 'google_api' ? 80.00 : 70.00,
                'first_n_leads_exempt' => 10,
                'exemption_days' => null,
                'exemption_until' => null,
                'is_active' => 1
            ];
        }
    }
}

if (!function_exists('is_agent_exempt')) {
    /**
     * Check if agent has an active exemption
     * 
     * @param int $userId User ID
     * @param string $restrictionType 'google_api' or 'ai_email' or NULL for any
     * @return array ['exempt' => bool, 'reason' => string, 'until' => datetime|null]
     */
    function is_agent_exempt(int $userId, ?string $restrictionType = null): array
    {
        try {
            $pdo = db();
            
            $sql = '
                SELECT 
                    exemption_until,
                    exemption_days,
                    exemption_reason,
                    restriction_type,
                    created_at
                FROM agent_api_restriction_settings 
                WHERE agent_id = ? AND is_active = 1
            ';
            
            $params = [$userId];
            
            if ($restrictionType !== null) {
                $sql .= ' AND restriction_type = ?';
                $params[] = $restrictionType;
            }
            
            $sql .= ' ORDER BY created_at DESC LIMIT 1';
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $exemption = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$exemption) {
                return ['exempt' => false, 'reason' => '', 'until' => null];
            }

            // Check date-based exemption
            if ($exemption['exemption_until']) {
                $until = new DateTime($exemption['exemption_until']);
                $now = new DateTime();
                
                if ($now < $until) {
                    return [
                        'exempt' => true,
                        'reason' => 'Temporary exemption until ' . $until->format('Y-m-d H:i'),
                        'until' => $exemption['exemption_until'],
                        'exemption_reason' => $exemption['exemption_reason']
                    ];
                }
            }

            // Check day-count exemption
            if ($exemption['exemption_days']) {
                $createdAt = new DateTime($exemption['created_at']);
                $expiresAt = $createdAt->modify('+' . $exemption['exemption_days'] . ' days');
                $now = new DateTime();
                
                if ($now < $expiresAt) {
                    return [
                        'exempt' => true,
                        'reason' => 'Temporary exemption (' . $exemption['exemption_days'] . ' days)',
                        'until' => $expiresAt->format('Y-m-d H:i:s'),
                        'exemption_reason' => $exemption['exemption_reason']
                    ];
                }
            }

            return ['exempt' => false, 'reason' => '', 'until' => null];

        } catch (Throwable $e) {
            error_log("Error checking exemption: " . $e->getMessage());
            return ['exempt' => false, 'reason' => '', 'until' => null];
        }
    }
}

if (!function_exists('calculate_detail_shortage')) {
    /**
     * Calculate how many more leads need to be completed to reach threshold
     * 
     * @param int $userId User ID
     * @param float $targetPercentage Target percentage (e.g., 80)
     * @return int Number of additional leads that need details
     */
    function calculate_detail_shortage(int $userId, float $targetPercentage): int
    {
        try {
            $pdo = db();
            
            $stmt = $pdo->prepare('SELECT * FROM agent_lead_detail_statistics WHERE agent_id = ?');
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stats || $stats['total_leads'] == 0) {
                return 0;
            }

            $totalLeads = (int)$stats['total_leads'];
            $detailedLeads = (int)$stats['detailed_leads'];
            
            // Calculate how many detailed leads needed for target percentage
            $neededDetailed = ceil($totalLeads * ($targetPercentage / 100));
            
            // Calculate shortage
            $shortage = max(0, $neededDetailed - $detailedLeads);

            return (int)$shortage;

        } catch (Throwable $e) {
            error_log("Error calculating detail shortage: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('grant_api_exemption')) {
    /**
     * Grant temporary API access exemption to an agent
     * 
     * @param int $userId User ID to grant exemption
     * @param string $restrictionType 'google_api' or 'ai_email' or 'both'
     * @param int|null $days Number of days for exemption
     * @param string|null $untilDate Exemption until specific date
     * @param string $reason Reason for exemption
     * @param int $grantedBy Admin user ID granting exemption
     * @return bool Success
     */
    function grant_api_exemption(
        int $userId, 
        string $restrictionType, 
        ?int $days = null, 
        ?string $untilDate = null,
        string $reason = '',
        int $grantedBy = 0
    ): bool {
        try {
            $pdo = db();
            
            $types = $restrictionType === 'both' ? ['google_api', 'ai_email'] : [$restrictionType];
            
            foreach ($types as $type) {
                $stmt = $pdo->prepare('
                    INSERT INTO agent_api_restriction_settings 
                        (agent_id, restriction_type, min_detail_percentage, first_n_leads_exempt, 
                         exemption_days, exemption_until, exemption_reason, is_active, created_by)
                    VALUES (?, ?, 0, 0, ?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        exemption_days = VALUES(exemption_days),
                        exemption_until = VALUES(exemption_until),
                        exemption_reason = VALUES(exemption_reason),
                        is_active = 1,
                        created_by = VALUES(created_by),
                        created_at = CURRENT_TIMESTAMP
                ');
                
                $stmt->execute([
                    $userId,
                    $type,
                    $days,
                    $untilDate,
                    $reason,
                    $grantedBy
                ]);
            }
            
            return true;

        } catch (Throwable $e) {
            error_log("Error granting exemption: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('revoke_api_exemption')) {
    /**
     * Revoke API exemption for an agent
     * 
     * @param int $userId User ID
     * @param string|null $restrictionType Specific type or null for all
     * @return bool Success
     */
    function revoke_api_exemption(int $userId, ?string $restrictionType = null): bool
    {
        try {
            $pdo = db();
            
            if ($restrictionType) {
                $stmt = $pdo->prepare('
                    UPDATE agent_api_restriction_settings 
                    SET exemption_days = NULL, 
                        exemption_until = NULL, 
                        exemption_reason = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE agent_id = ? AND restriction_type = ?
                ');
                $stmt->execute([$userId, $restrictionType]);
            } else {
                $stmt = $pdo->prepare('
                    UPDATE agent_api_restriction_settings 
                    SET exemption_days = NULL, 
                        exemption_until = NULL, 
                        exemption_reason = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE agent_id = ?
                ');
                $stmt->execute([$userId]);
            }
            
            return true;

        } catch (Throwable $e) {
            error_log("Error revoking exemption: " . $e->getMessage());
            return false;
        }
    }
}









