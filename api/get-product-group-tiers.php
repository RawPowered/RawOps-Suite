<?php
require_once __DIR__ . '/../required/config.php';
require_login_or_redirect('/login.php');
require_admin();

$pdo = db();
$groupId = intval($_GET['id'] ?? 0);

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT tier_name as name, quantity_min as min, quantity_max as max, bulk_discount_percent as discount, commission_percent as commission FROM product_group_commissions WHERE product_group_id = ? ORDER BY quantity_min ASC');
    $stmt->execute([$groupId]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert null max to empty string for UI
    foreach ($tiers as &$tier) {
        $tier['max'] = $tier['max'] ?? '';
    }
    
    echo json_encode($tiers);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}



