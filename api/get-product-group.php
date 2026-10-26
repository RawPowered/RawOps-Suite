<?php
// Set JSON header first to prevent any HTML output
header('Content-Type: application/json');

require_once __DIR__ . '/../required/config.php';

// Check authentication without redirects (API mode)
$userId = current_user_id();
if (!$userId || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$pdo = db();
$groupId = intval($_GET['id'] ?? 0);

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM product_groups WHERE id = ?');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        exit;
    }
    
    // Include cost data if requested (for template cloning)
    if (isset($_GET['include_costs']) && $_GET['include_costs'] === 'true') {
        // Parse JSON fields if they exist
        if ($group['custom_cost_factors']) {
            $group['custom_cost_factors_parsed'] = json_decode($group['custom_cost_factors'], true);
        }
    }
    
    echo json_encode($group);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}


