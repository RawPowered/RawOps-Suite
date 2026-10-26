<?php
/**
 * Social Media Links Helper Functions
 * Manage social media links for leads
 */

/**
 * Get platform icon and color mapping
 */
function get_social_platform_config($platform) {
    $platforms = [
        'facebook' => ['icon' => 'fa-brands fa-facebook', 'color' => '#1877F2', 'label' => 'Facebook'],
        'instagram' => ['icon' => 'fa-brands fa-instagram', 'color' => '#E4405F', 'label' => 'Instagram'],
        'linkedin' => ['icon' => 'fa-brands fa-linkedin', 'color' => '#0A66C2', 'label' => 'LinkedIn'],
        'x' => ['icon' => 'fa-brands fa-x-twitter', 'color' => '#000000', 'label' => 'X (Twitter)'],
        'twitter' => ['icon' => 'fa-brands fa-x-twitter', 'color' => '#000000', 'label' => 'X (Twitter)'],
        'reddit' => ['icon' => 'fa-brands fa-reddit', 'color' => '#FF4500', 'label' => 'Reddit'],
        'youtube' => ['icon' => 'fa-brands fa-youtube', 'color' => '#FF0000', 'label' => 'YouTube'],
        'linktree' => ['icon' => 'fa-solid fa-tree', 'color' => '#43E55E', 'label' => 'LinkTree'],
        'beacons' => ['icon' => 'fa-solid fa-link', 'color' => '#7C3AED', 'label' => 'Beacons'],
        'twitch' => ['icon' => 'fa-brands fa-twitch', 'color' => '#9146FF', 'label' => 'Twitch'],
        'kick' => ['icon' => 'fa-solid fa-k', 'color' => '#53FC18', 'label' => 'Kick'],
        'tiktok' => ['icon' => 'fa-brands fa-tiktok', 'color' => '#000000', 'label' => 'TikTok'],
        'custom' => ['icon' => 'fa-solid fa-link', 'color' => '#6B7280', 'label' => 'Custom Link']
    ];

    $platformLower = strtolower($platform);
    return $platforms[$platformLower] ?? $platforms['custom'];
}

/**
 * Get all social links for a lead
 */
function get_lead_social_links($pdo, $leadId) {
    try {
        $stmt = $pdo->prepare('
            SELECT * FROM crm_lead_social_links 
            WHERE lead_id = ? 
            ORDER BY display_order ASC, created_at ASC
        ');
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Add social link to lead
 */
function add_lead_social_link($pdo, $leadId, $platform, $url, $displayLabel = null, $createdBy = null) {
    try {
        // Get platform config
        $config = get_social_platform_config($platform);
        
        // Get next display order
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM crm_lead_social_links WHERE lead_id = ?');
        $stmt->execute([$leadId]);
        $nextOrder = (int)$stmt->fetchColumn();
        
        // Insert
        $stmt = $pdo->prepare('
            INSERT INTO crm_lead_social_links (
                lead_id, platform, url, display_label, icon_class, display_order, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                url = VALUES(url),
                display_label = VALUES(display_label),
                updated_at = NOW()
        ');
        
        $stmt->execute([
            $leadId,
            $platform,
            $url,
            $displayLabel ?: $config['label'],
            $config['icon'],
            $nextOrder,
            $createdBy
        ]);
        
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Error adding social link: " . $e->getMessage());
        return false;
    }
}

/**
 * Update social link
 */
function update_lead_social_link($pdo, $linkId, $url, $displayLabel = null) {
    try {
        $stmt = $pdo->prepare('
            UPDATE crm_lead_social_links 
            SET url = ?, display_label = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$url, $displayLabel, $linkId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Delete social link
 */
function delete_lead_social_link($pdo, $linkId, $leadId = null) {
    try {
        if ($leadId) {
            $stmt = $pdo->prepare('DELETE FROM crm_lead_social_links WHERE id = ? AND lead_id = ?');
            $stmt->execute([$linkId, $leadId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM crm_lead_social_links WHERE id = ?');
            $stmt->execute([$linkId]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if lead has adequate contact methods
 * Returns true if lead is contactable via email, phone, or social media
 */
function lead_has_contact_methods($pdo, $lead) {
    // Has email?
    $hasEmail = !empty($lead['email']) || !empty($lead['email_not_available']);
    
    // Has phone?
    $hasPhone = !empty($lead['phone']) || !empty($lead['phone_not_available']);
    
    // If both are marked unavailable, check for social links
    if (!empty($lead['email_not_available']) && !empty($lead['phone_not_available'])) {
        // Must have at least one social media link
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_lead_social_links WHERE lead_id = ?');
        $stmt->execute([$lead['id']]);
        $socialCount = (int)$stmt->fetchColumn();
        
        return $socialCount > 0;
    }
    
    // At least one traditional contact method available
    return $hasEmail && $hasPhone;
}

/**
 * Auto-detect platform from URL
 */
function detect_social_platform($url) {
    $url = strtolower($url);
    
    if (strpos($url, 'facebook.com') !== false || strpos($url, 'fb.com') !== false) return 'facebook';
    if (strpos($url, 'instagram.com') !== false) return 'instagram';
    if (strpos($url, 'linkedin.com') !== false) return 'linkedin';
    if (strpos($url, 'twitter.com') !== false || strpos($url, 'x.com') !== false) return 'x';
    if (strpos($url, 'reddit.com') !== false) return 'reddit';
    if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) return 'youtube';
    if (strpos($url, 'linktree') !== false || strpos($url, 'linktr.ee') !== false) return 'linktree';
    if (strpos($url, 'beacons') !== false) return 'beacons';
    if (strpos($url, 'twitch.tv') !== false) return 'twitch';
    if (strpos($url, 'kick.com') !== false) return 'kick';
    if (strpos($url, 'tiktok.com') !== false) return 'tiktok';
    
    return 'custom';
}

/**
 * Get available platform options for dropdown
 */
function get_social_platform_options() {
    return [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'x' => 'X (Twitter)',
        'reddit' => 'Reddit',
        'youtube' => 'YouTube',
        'linktree' => 'LinkTree',
        'beacons' => 'Beacons',
        'twitch' => 'Twitch',
        'kick' => 'Kick',
        'tiktok' => 'TikTok',
        'custom' => 'Custom Link'
    ];
}

/**
 * Get social interactions for a lead
 */
function get_lead_social_interactions($pdo, $leadId, $limit = 50) {
    try {
        $stmt = $pdo->prepare('
            SELECT si.*, CONCAT(u.first_name, " ", u.last_name) as agent_name
            FROM crm_social_interactions si
            LEFT JOIN users u ON u.id = si.agent_id
            WHERE si.lead_id = ?
            ORDER BY si.interaction_date DESC, si.created_at DESC
            LIMIT ?
        ');
        $stmt->execute([$leadId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if lead qualifies as social media exclusive detailed lead
 */
function is_social_media_detailed_lead($pdo, $lead) {
    if (empty($lead['is_social_media_lead'])) {
        return false;
    }
    
    // Must have name
    if (empty($lead['company_name']) && empty($lead['first_name'])) {
        return false;
    }
    
    // Must have major platform link
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM crm_lead_social_links 
        WHERE lead_id = ? 
        AND platform IN ("facebook", "instagram", "linkedin", "x", "twitter")
    ');
    $stmt->execute([$lead['id']]);
    
    return (int)$stmt->fetchColumn() > 0;
}

