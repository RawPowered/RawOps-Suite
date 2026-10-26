<?php
/**
 * Business Card API
 * Handles CRUD operations for sales agent business cards
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/business-card-functions.php';

header('Content-Type: application/json');
start_session();

// Check authentication
if (!isset($_SESSION['uid'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$uid = intval($_SESSION['uid']);
$pdo = db();

// Check if user is admin or sales agent
$is_admin = false;
$is_sales_agent = false;

$role_stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
$role_stmt->execute([$uid]);
$roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);

if (in_array(3, $roles)) $is_admin = true; // Admin role
if (in_array(2, $roles)) $is_sales_agent = true; // Sales Agent role

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Get business card
if ($action === 'get') {
    $agent_id = isset($_GET['agent_id']) ? intval($_GET['agent_id']) : $uid;
    
    // Permission check
    if (!$is_admin && $agent_id !== $uid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $card = get_agent_business_card($agent_id);
    if (!$card) {
        echo json_encode(['success' => false, 'error' => 'Card not found']);
        exit;
    }
    
    $social_links = get_card_social_links($card['id']);
    $card['social_links'] = $social_links;
    
    echo json_encode(['success' => true, 'card' => $card]);
    exit;
}

// Save business card
if ($action === 'save') {
    $card_id = isset($_POST['card_id']) ? intval($_POST['card_id']) : 0;
    $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
    
    // If card_id is provided (editing existing card), get agent_id from database
    if ($card_id > 0 && $agent_id === 0) {
        $card_stmt = $pdo->prepare('SELECT agent_id FROM sales_agent_business_cards WHERE id = ?');
        $card_stmt->execute([$card_id]);
        $agent_id = intval($card_stmt->fetchColumn());
    }
    
    // Fallback to current user if still no agent_id
    if ($agent_id === 0) {
        $agent_id = $uid;
    }
    
    // Permission check
    if (!$is_admin && $agent_id !== $uid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $data = [
        'job_title' => $_POST['job_title'] ?? 'Sales Agent',
        'bio' => $_POST['bio'] ?? null,
        'theme_color' => $_POST['theme_color'] ?? '#0369a1',
        'custom_message' => $_POST['custom_message'] ?? null,
        'show_email' => isset($_POST['show_email']) ? 1 : 0,
        'show_phone' => isset($_POST['show_phone']) ? 1 : 0
    ];
    
    $result = save_business_card($agent_id, $data);
    
    if ($result['success'] && isset($_POST['profile_photo_updated']) && $_POST['profile_photo_updated'] === '1') {
        // Update profile photo path
        $photo_path = $_POST['profile_photo_path'] ?? null;
        if ($photo_path) {
            $photo_stmt = $pdo->prepare('
                UPDATE sales_agent_business_cards
                SET profile_photo_path = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $photo_stmt->execute([$photo_path, $result['card_id']]);
        }
    }
    
    echo json_encode($result);
    exit;
}

// Upload profile photo
if ($action === 'upload_photo') {
    $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : $uid;
    
    // Permission check
    if (!$is_admin && $agent_id !== $uid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    if (!isset($_FILES['photo'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit;
    }
    
    $result = upload_business_card_photo($_FILES['photo'], $agent_id);
    
    if ($result['success']) {
        // Update card with new photo path
        $card = get_agent_business_card($agent_id);
        if ($card) {
            $stmt = $pdo->prepare('
                UPDATE sales_agent_business_cards
                SET profile_photo_path = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$result['path'], $card['id']]);
        }
    }
    
    echo json_encode($result);
    exit;
}

// Manage social links
if ($action === 'add_social_link') {
    $card_id = isset($_POST['card_id']) ? intval($_POST['card_id']) : 0;
    
    // Get card to check ownership
    $card_stmt = $pdo->prepare('SELECT agent_id FROM sales_agent_business_cards WHERE id = ?');
    $card_stmt->execute([$card_id]);
    $card = $card_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card) {
        echo json_encode(['success' => false, 'error' => 'Card not found']);
        exit;
    }
    
    // Permission check
    if (!$is_admin && intval($card['agent_id']) !== $uid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $platform = $_POST['platform'] ?? 'custom';
    $url = $_POST['url'] ?? '';
    $display_label = $_POST['display_label'] ?? null;
    $icon_class = $_POST['icon_class'] ?? get_platform_icon($platform);
    $display_order = isset($_POST['display_order']) ? intval($_POST['display_order']) : 0;
    
    if (empty($url)) {
        echo json_encode(['success' => false, 'error' => 'URL is required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('
            INSERT INTO business_card_social_links
            (card_id, platform, url, display_label, icon_class, display_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$card_id, $platform, $url, $display_label, $icon_class, $display_order]);
        
        echo json_encode(['success' => true, 'link_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

if ($action === 'update_social_link') {
    $link_id = isset($_POST['link_id']) ? intval($_POST['link_id']) : 0;
    
    // Get link to check ownership
    $link_stmt = $pdo->prepare('
        SELECT bcsl.*, sabc.agent_id
        FROM business_card_social_links bcsl
        JOIN sales_agent_business_cards sabc ON sabc.id = bcsl.card_id
        WHERE bcsl.id = ?
    ');
    $link_stmt->execute([$link_id]);
    $link = $link_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        echo json_encode(['success' => false, 'error' => 'Link not found']);
        exit;
    }
    
    // Permission check
    if (!$is_admin && intval($link['agent_id']) !== $uid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $url = $_POST['url'] ?? $link['url'];
    $display_label = $_POST['display_label'] ?? $link['display_label'];
    $icon_class = $_POST['icon_class'] ?? $link['icon_class'];
    $display_order = isset($_POST['display_order']) ? intval($_POST['display_order']) : $link['display_order'];
    $is_visible = isset($_POST['is_visible']) ? intval($_POST['is_visible']) : $link['is_visible'];
    
    try {
        $stmt = $pdo->prepare('
            UPDATE business_card_social_links
            SET url = ?, display_label = ?, icon_class = ?, display_order = ?, is_visible = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$url, $display_label, $icon_class, $display_order, $is_visible, $link_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

if ($action === 'delete_social_link') {
    $link_id = isset($_POST['link_id']) ? intval($_POST['link_id']) : 0;
    
    // Get link to check ownership
    $link_stmt = $pdo->prepare('
        SELECT bcsl.*, sabc.agent_id
        FROM business_card_social_links bcsl
        JOIN sales_agent_business_cards sabc ON sabc.id = bcsl.card_id
        WHERE bcsl.id = ?
    ');
    $link_stmt->execute([$link_id]);
    $link = $link_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        echo json_encode(['success' => false, 'error' => 'Link not found']);
        exit;
    }
    
    // Permission check
    if (!$is_admin && intval($link['agent_id']) !== $uid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('DELETE FROM business_card_social_links WHERE id = ?');
        $stmt->execute([$link_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Get all social links for a card
if ($action === 'get_social_links') {
    $card_id = isset($_GET['card_id']) ? intval($_GET['card_id']) : 0;
    
    $links = get_card_social_links($card_id);
    echo json_encode(['success' => true, 'links' => $links]);
    exit;
}

// List all business cards (admin only)
if ($action === 'list') {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    try {
        $stmt = $pdo->query('
            SELECT 
                sabc.*,
                u.first_name, u.last_name, u.email,
                bcqr.scan_count, bcqr.signup_conversion_count, bcqr.sample_request_count
            FROM sales_agent_business_cards sabc
            JOIN users u ON u.id = sabc.agent_id
            LEFT JOIN business_card_qr_codes bcqr ON bcqr.card_id = sabc.id
            ORDER BY u.last_name, u.first_name
        ');
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'cards' => $cards]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Toggle card active status (admin only)
if ($action === 'toggle_active') {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $card_id = isset($_POST['card_id']) ? intval($_POST['card_id']) : 0;
    
    try {
        $stmt = $pdo->prepare('
            UPDATE sales_agent_business_cards
            SET is_active = NOT is_active, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$card_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);

