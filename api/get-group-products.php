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
    $stmt = $pdo->prepare('SELECT id, sku, name, wholesale_price FROM products WHERE product_group_id = ? AND active = 1 ORDER BY name');
    $stmt->execute([$groupId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($products);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}



