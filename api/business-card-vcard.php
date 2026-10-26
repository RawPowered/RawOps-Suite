<?php
/**
 * Business Card vCard Generator
 * Generates downloadable vCard (.vcf) files for sales agents
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/business-card-functions.php';

start_session();

// Get slug or card ID
$slug = $_GET['slug'] ?? '';
$card_id = isset($_GET['card_id']) ? intval($_GET['card_id']) : 0;

if (empty($slug) && $card_id === 0) {
    http_response_code(400);
    echo "Missing card identifier";
    exit;
}

// Get business card
if (!empty($slug)) {
    $card = get_business_card_by_slug($slug);
} else {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT 
            sabc.*,
            u.first_name, u.last_name, u.email, u.phone
        FROM sales_agent_business_cards sabc
        JOIN users u ON u.id = sabc.agent_id
        WHERE sabc.id = ?
    ');
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$card) {
    http_response_code(404);
    echo "Business card not found";
    exit;
}

// Track download
track_card_view($card['id'], 'vcard_download');

// Generate vCard content
$vcard = "BEGIN:VCARD\r\n";
$vcard .= "VERSION:3.0\r\n";
$vcard .= "FN:" . $card['first_name'] . " " . $card['last_name'] . "\r\n";
$vcard .= "N:" . $card['last_name'] . ";" . $card['first_name'] . ";;;\r\n";
$vcard .= "ORG:Product\r\n";
$vcard .= "TITLE:" . ($card['job_title'] ?? 'Sales Agent') . "\r\n";

// Add email if visible
if ($card['show_email'] && !empty($card['email'])) {
    $vcard .= "EMAIL;TYPE=WORK:" . $card['email'] . "\r\n";
}

// Add phone if visible
if ($card['show_phone'] && !empty($card['phone'])) {
    // Clean phone number
    $phone = preg_replace('/[^0-9+]/', '', $card['phone']);
    $vcard .= "TEL;TYPE=WORK,VOICE:" . $phone . "\r\n";
}

// Add business card URL
$card_url = "https://product.example/agent/" . $card['slug'] . "/card";
$vcard .= "URL:" . $card_url . "\r\n";

// Add photo if available
if (!empty($card['profile_photo_path'])) {
    $photo_path = __DIR__ . '/..' . $card['profile_photo_path'];
    if (file_exists($photo_path)) {
        $photo_data = file_get_contents($photo_path);
        $photo_base64 = base64_encode($photo_data);
        
        // Determine photo type
        $photo_type = 'JPEG';
        $extension = strtolower(pathinfo($card['profile_photo_path'], PATHINFO_EXTENSION));
        if ($extension === 'png') {
            $photo_type = 'PNG';
        } elseif ($extension === 'webp') {
            $photo_type = 'WEBP';
        }
        
        $vcard .= "PHOTO;ENCODING=b;TYPE=" . $photo_type . ":" . $photo_base64 . "\r\n";
    }
}

// Add social links as URLs
$social_links = get_card_social_links($card['id']);
foreach ($social_links as $link) {
    $label = $link['display_label'] ?? get_platform_name($link['platform']);
    $vcard .= "URL;TYPE=" . strtoupper($link['platform']) . ":" . $link['url'] . "\r\n";
}

// Add note/bio if available
if (!empty($card['bio'])) {
    $vcard .= "NOTE:" . str_replace(["\r\n", "\n"], "\\n", $card['bio']) . "\r\n";
}

$vcard .= "REV:" . date('Y-m-d\TH:i:s\Z') . "\r\n";
$vcard .= "END:VCARD\r\n";

// Set headers for download
$filename = strtolower($card['first_name'] . '-' . $card['last_name']) . '.vcf';
$filename = preg_replace('/[^a-z0-9-]/', '', $filename);

header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($vcard));
header('Cache-Control: no-cache');

echo $vcard;



