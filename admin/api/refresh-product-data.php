<?php
/**
 * AJAX Product Data Refresh Endpoint
 * Fetches updated product data and calculated values after save
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/header-functions.php';

// Check authentication
start_session();
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = db();
    
    // Get product ID
    $product_id = intval($_GET['id'] ?? 0);
    if ($product_id <= 0) {
        throw new Exception('Invalid product ID');
    }
    
    // Fetch product data
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('Product not found');
    }
    
    // Fetch bulk pricing
    $stmt = $pdo->prepare("SELECT * FROM bulk_pricing WHERE sku = ? AND active = 1 ORDER BY quantity_min ASC");
    $stmt->execute([$product['sku']]);
    $bulkPricing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch quiz count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM training_quiz_questions WHERE product_id = ? AND is_active = 1");
    $stmt->execute([$product_id]);
    $quizCount = $stmt->fetchColumn();
    
    // Calculate profit scenarios
    $servings = $product['servings_10mg'] ?? 0;
    $cost = $product['wholesale_price'] ?? 0;
    $drink_price = 12;
    $revenue = $servings * $drink_price;
    $gross_profit = $revenue - $cost;
    $base_cost = $servings * 3; // $3 base cost per drink
    $net_profit = $gross_profit - $base_cost;
    
    echo json_encode([
        'success' => true,
        'product' => $product,
        'bulkPricing' => $bulkPricing,
        'quizCount' => $quizCount,
        'profitScenario' => [
            'revenue' => $revenue,
            'grossProfit' => $gross_profit,
            'netProfit' => $net_profit
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Product Refresh Error - " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

