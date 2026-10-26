<?php
/**
 * Business Card QR Code Generator
 * Generates QR codes for business card URLs using multiple reliable methods
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/business-card-functions.php';
require_once __DIR__ . '/../required/simple-qrcode.php';

start_session();

$pdo = db();

// Check authentication
if (!isset($_SESSION['uid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$uid = intval($_SESSION['uid']);

// Check if user is admin or sales agent
$is_admin = false;
$role_stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
$role_stmt->execute([$uid]);
$roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
if (in_array(3, $roles)) $is_admin = true;

$card_id = isset($_GET['card_id']) ? intval($_GET['card_id']) : 0;
$agent_id = isset($_GET['agent_id']) ? intval($_GET['agent_id']) : $uid;

if ($card_id === 0) {
    // Get card by agent ID
    $card = get_agent_business_card($agent_id);
    if (!$card) {
        echo json_encode(['success' => false, 'error' => 'Business card not found']);
        exit;
    }
    $card_id = $card['id'];
} else {
    // Get card by card ID
    $stmt = $pdo->prepare('SELECT * FROM sales_agent_business_cards WHERE id = ?');
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        echo json_encode(['success' => false, 'error' => 'Business card not found']);
        exit;
    }
    $agent_id = $card['agent_id'];
}

// Permission check
if (!$is_admin && $agent_id !== $uid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Get QR code record
$qr_stmt = $pdo->prepare('SELECT * FROM business_card_qr_codes WHERE card_id = ?');
$qr_stmt->execute([$card_id]);
$qr_record = $qr_stmt->fetch(PDO::FETCH_ASSOC);

if (!$qr_record) {
    // Create QR record if it doesn't exist
    $qr_url = 'https://product.example/agent/' . $card['slug'] . '/card';
    $qr_path = '/uploads/business-cards/qr/' . $card_id . '.png';
    
    $insert_stmt = $pdo->prepare('
        INSERT INTO business_card_qr_codes (card_id, qr_image_path, qr_url)
        VALUES (?, ?, ?)
    ');
    $insert_stmt->execute([$card_id, $qr_path, $qr_url]);
    
    $qr_record = [
        'card_id' => $card_id,
        'qr_image_path' => $qr_path,
        'qr_url' => $qr_url
    ];
}

$qr_url = $qr_record['qr_url'];
$qr_file_path = __DIR__ . '/..' . $qr_record['qr_image_path'];

// Create directories if they don't exist
$qr_dir = dirname($qr_file_path);
if (!is_dir($qr_dir)) {
    if (!mkdir($qr_dir, 0755, true)) {
        error_log("Failed to create QR directory: " . $qr_dir);
        echo json_encode(['success' => false, 'error' => 'Failed to create QR directory']);
        exit;
    }
}

// Check if QR code image already exists
if (file_exists($qr_file_path)) {
    // Return existing QR code
    $return_data = isset($_GET['return']) && $_GET['return'] === 'image' ? 'image' : 'json';
    
    if ($return_data === 'image') {
        header('Content-Type: image/png');
        readfile($qr_file_path);
    } else {
        echo json_encode([
            'success' => true,
            'qr_path' => $qr_record['qr_image_path'],
            'qr_url' => $qr_url,
            'cached' => true
        ]);
    }
    exit;
}

// Generate new QR code using SimpleQRCode library
try {
    $qrcode = new SimpleQRCode($qr_url, 'M');
    $qr_generated = $qrcode->save($qr_file_path, 10, 4);
    
    if (!$qr_generated) {
        throw new Exception('QR code generation failed');
    }
} catch (Exception $e) {
    error_log("QR generation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to generate QR code: ' . $e->getMessage()]);
    exit;
}

// Return result
$return_data = isset($_GET['return']) && $_GET['return'] === 'image' ? 'image' : 'json';

if ($return_data === 'image') {
    header('Content-Type: image/png');
    readfile($qr_file_path);
} else {
    echo json_encode([
        'success' => true,
        'qr_path' => $qr_record['qr_image_path'],
        'qr_url' => $qr_url,
        'cached' => false
    ]);
}

