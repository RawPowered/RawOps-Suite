<?php
require_once __DIR__ . '/../../required/config.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$productId = intval($_GET['id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT id, name, sku, attribute_content, weight_grams, dimensions, low_stock_threshold, stock_quantity
        FROM products 
        WHERE id = ? AND active = 1
    ');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'product' => $product
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
