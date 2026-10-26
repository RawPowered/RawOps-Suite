<?php
/**
 * Business Card System Helper Functions
 * Provides utilities for managing sales agent digital business cards
 */

/**
 * Get business card data for an agent
 * @param int $agent_id User ID of the sales agent
 * @return array|null Business card data or null if not found
 */
function get_agent_business_card($agent_id) {
    $pdo = db();
    if (!$pdo) return null;
    
    try {
        $stmt = $pdo->prepare('
            SELECT 
                sabc.*,
                u.first_name, u.last_name, u.email, u.phone,
                bcqr.qr_image_path, bcqr.qr_url, bcqr.scan_count,
                bcqr.vcard_download_count, bcqr.signup_conversion_count,
                bcqr.sample_request_count
            FROM sales_agent_business_cards sabc
            JOIN users u ON u.id = sabc.agent_id
            LEFT JOIN business_card_qr_codes bcqr ON bcqr.card_id = sabc.id
            WHERE sabc.agent_id = ?
        ');
        $stmt->execute([$agent_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching business card: " . $e->getMessage());
        return null;
    }
}

/**
 * Get business card by slug
 * @param string $slug URL slug
 * @return array|null Business card data or null if not found
 */
function get_business_card_by_slug($slug) {
    $pdo = db();
    if (!$pdo) return null;
    
    try {
        $stmt = $pdo->prepare('
            SELECT 
                sabc.*,
                u.first_name, u.last_name, u.email, u.phone,
                bcqr.qr_image_path, bcqr.qr_url, bcqr.scan_count,
                bcqr.vcard_download_count, bcqr.signup_conversion_count,
                bcqr.sample_request_count, bcqr.id as qr_id
            FROM sales_agent_business_cards sabc
            JOIN users u ON u.id = sabc.agent_id
            LEFT JOIN business_card_qr_codes bcqr ON bcqr.card_id = sabc.id
            WHERE sabc.slug = ? AND sabc.is_active = 1
        ');
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching business card by slug: " . $e->getMessage());
        return null;
    }
}

/**
 * Get social links for a business card
 * @param int $card_id Business card ID
 * @return array Array of social links
 */
function get_card_social_links($card_id) {
    $pdo = db();
    if (!$pdo) return [];
    
    try {
        $stmt = $pdo->prepare('
            SELECT * FROM business_card_social_links
            WHERE card_id = ? AND is_visible = 1
            ORDER BY display_order ASC, id ASC
        ');
        $stmt->execute([$card_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching social links: " . $e->getMessage());
        return [];
    }
}

/**
 * Generate a URL-friendly slug from name
 * @param string $first_name First name
 * @param string $last_name Last name
 * @return string URL slug
 */
function generate_card_slug($first_name, $last_name) {
    $slug = strtolower(trim($first_name) . '-' . trim($last_name));
    // Remove special characters, keep only letters, numbers, and hyphens
    $slug = preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', $slug));
    // Remove multiple consecutive hyphens
    $slug = preg_replace('/-+/', '-', $slug);
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    
    return $slug;
}

/**
 * Check if a slug is already in use
 * @param string $slug Slug to check
 * @param int|null $exclude_card_id Card ID to exclude from check (for updates)
 * @return bool True if slug exists
 */
function slug_exists($slug, $exclude_card_id = null) {
    $pdo = db();
    if (!$pdo) return false;
    
    try {
        if ($exclude_card_id) {
            $stmt = $pdo->prepare('SELECT id FROM sales_agent_business_cards WHERE slug = ? AND id != ?');
            $stmt->execute([$slug, $exclude_card_id]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM sales_agent_business_cards WHERE slug = ?');
            $stmt->execute([$slug]);
        }
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Track a business card view
 * @param int $card_id Business card ID
 * @param string $action_type Type of action: view, vcard_download, signup_click, sample_click
 */
function track_card_view($card_id, $action_type = 'view') {
    $pdo = db();
    if (!$pdo) return;
    
    try {
        // Get visitor info
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $session_id = session_id() ?: null;
        
        // Log the view
        $stmt = $pdo->prepare('
            INSERT INTO business_card_views 
            (card_id, viewer_ip, viewer_user_agent, referer, action_type, session_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$card_id, $ip, $user_agent, $referer, $action_type, $session_id]);
        
        // Update QR code stats
        $qr_stmt = $pdo->prepare('
            UPDATE business_card_qr_codes
            SET 
                scan_count = scan_count + IF(? = "view", 1, 0),
                vcard_download_count = vcard_download_count + IF(? = "vcard_download", 1, 0),
                signup_conversion_count = signup_conversion_count + IF(? = "signup_click", 1, 0),
                sample_request_count = sample_request_count + IF(? = "sample_click", 1, 0),
                last_scanned_at = NOW()
            WHERE card_id = ?
        ');
        $qr_stmt->execute([$action_type, $action_type, $action_type, $action_type, $card_id]);
        
    } catch (Exception $e) {
        error_log("Error tracking card view: " . $e->getMessage());
    }
}

/**
 * Upload and process business card profile photo
 * @param array $file $_FILES array element
 * @param int $agent_id Agent user ID
 * @return array Result array with 'success' and 'path' or 'error'
 */
function upload_business_card_photo($file, $agent_id) {
    // Validate file upload
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }
    
    // Check file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large (max 5MB)'];
    }
    
    // Check file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type (use JPG, PNG, or WebP)'];
    }
    
    // Create upload directory if needed
    $upload_dir = __DIR__ . '/../uploads/business-cards/photos';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'agent-' . $agent_id . '-' . time() . '.' . $extension;
    $filepath = $upload_dir . '/' . $filename;
    $web_path = '/uploads/business-cards/photos/' . $filename;
    
    // Load image
    switch ($mime_type) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $image = imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            return ['success' => false, 'error' => 'Unsupported image format'];
    }
    
    if (!$image) {
        return ['success' => false, 'error' => 'Failed to process image'];
    }
    
    // Get original dimensions
    $orig_width = imagesx($image);
    $orig_height = imagesy($image);
    
    // Calculate new dimensions (max 800x800, maintain aspect ratio)
    $max_size = 800;
    if ($orig_width > $max_size || $orig_height > $max_size) {
        if ($orig_width > $orig_height) {
            $new_width = $max_size;
            $new_height = intval($orig_height * ($max_size / $orig_width));
        } else {
            $new_height = $max_size;
            $new_width = intval($orig_width * ($max_size / $orig_height));
        }
    } else {
        $new_width = $orig_width;
        $new_height = $orig_height;
    }
    
    // Create resized image
    $resized = imagecreatetruecolor($new_width, $new_height);
    
    // Preserve transparency for PNG
    if ($mime_type === 'image/png') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
    }
    
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
    
    // Save image
    $save_success = false;
    switch ($mime_type) {
        case 'image/jpeg':
        case 'image/jpg':
            $save_success = imagejpeg($resized, $filepath, 90);
            break;
        case 'image/png':
            $save_success = imagepng($resized, $filepath, 8);
            break;
        case 'image/webp':
            $save_success = imagewebp($resized, $filepath, 90);
            break;
    }
    
    // Clean up
    imagedestroy($image);
    imagedestroy($resized);
    
    if (!$save_success) {
        return ['success' => false, 'error' => 'Failed to save image'];
    }
    
    return ['success' => true, 'path' => $web_path];
}

/**
 * Get default icon class for a social platform
 * @param string $platform Platform name
 * @return string Font Awesome icon class
 */
function get_platform_icon($platform) {
    $icons = [
        'facebook' => 'fab fa-facebook',
        'instagram' => 'fab fa-instagram',
        'linkedin' => 'fab fa-linkedin',
        'x' => 'fab fa-x-twitter',
        'twitter' => 'fab fa-twitter',
        'tiktok' => 'fab fa-tiktok',
        'youtube' => 'fab fa-youtube',
        'website' => 'fas fa-globe',
        'custom' => 'fas fa-link'
    ];
    
    return $icons[$platform] ?? 'fas fa-link';
}

/**
 * Get platform display name
 * @param string $platform Platform slug
 * @return string Display name
 */
function get_platform_name($platform) {
    $names = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'x' => 'X (Twitter)',
        'twitter' => 'Twitter',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'website' => 'Website',
        'custom' => 'Custom Link'
    ];
    
    return $names[$platform] ?? ucfirst($platform);
}

/**
 * Create or update a business card for an agent
 * @param int $agent_id Agent user ID
 * @param array $data Card data
 * @return array Result with success status and card_id or error
 */
function save_business_card($agent_id, $data) {
    $pdo = db();
    if (!$pdo) return ['success' => false, 'error' => 'Database unavailable'];
    
    try {
        // Check if card exists
        $existing = get_agent_business_card($agent_id);
        
        if ($existing) {
            // Update existing card
            $stmt = $pdo->prepare('
                UPDATE sales_agent_business_cards
                SET job_title = ?, bio = ?, theme_color = ?, custom_message = ?,
                    show_email = ?, show_phone = ?, updated_at = NOW()
                WHERE agent_id = ?
            ');
            $stmt->execute([
                $data['job_title'] ?? 'Sales Agent',
                $data['bio'] ?? null,
                $data['theme_color'] ?? '#0369a1',
                $data['custom_message'] ?? null,
                isset($data['show_email']) ? (int)$data['show_email'] : 1,
                isset($data['show_phone']) ? (int)$data['show_phone'] : 1,
                $agent_id
            ]);
            
            return ['success' => true, 'card_id' => $existing['id']];
        } else {
            // Get agent info for slug generation
            $user_stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
            $user_stmt->execute([$agent_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return ['success' => false, 'error' => 'Agent not found'];
            }
            
            // Generate unique slug
            $slug = generate_card_slug($user['first_name'], $user['last_name']);
            $original_slug = $slug;
            $counter = 1;
            while (slug_exists($slug)) {
                $slug = $original_slug . '-' . $counter;
                $counter++;
            }
            
            // Create new card
            $stmt = $pdo->prepare('
                INSERT INTO sales_agent_business_cards
                (agent_id, slug, job_title, bio, theme_color, custom_message, show_email, show_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $agent_id,
                $slug,
                $data['job_title'] ?? 'Sales Agent',
                $data['bio'] ?? null,
                $data['theme_color'] ?? '#0369a1',
                $data['custom_message'] ?? null,
                isset($data['show_email']) ? (int)$data['show_email'] : 1,
                isset($data['show_phone']) ? (int)$data['show_phone'] : 1
            ]);
            
            $card_id = $pdo->lastInsertId();
            
            // Create QR code record
            $qr_url = 'https://product.example/agent/' . $slug . '/card';
            $qr_path = '/uploads/business-cards/qr/' . $card_id . '.png';
            
            $qr_stmt = $pdo->prepare('
                INSERT INTO business_card_qr_codes (card_id, qr_image_path, qr_url)
                VALUES (?, ?, ?)
            ');
            $qr_stmt->execute([$card_id, $qr_path, $qr_url]);
            
            return ['success' => true, 'card_id' => $card_id];
        }
    } catch (Exception $e) {
        error_log("Error saving business card: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

