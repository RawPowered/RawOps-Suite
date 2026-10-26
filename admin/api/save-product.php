<?php
/**
 * AJAX Product Save Endpoint
 * Handles product updates with comprehensive error handling and JSON response
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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = db();
    
    // Get product ID
    $product_id = intval($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        throw new Exception('Invalid product ID');
    }
    
    // Validate product exists
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Product not found');
    }
    
    // Sanitize and validate inputs
    $sku = trim($_POST['sku'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $wholesale_price = floatval($_POST['wholesale_price'] ?? 0);
    $msrp = floatval($_POST['msrp'] ?? 0);
    $attribute_per_oz = floatval($_POST['attribute_per_oz'] ?? 0);
    $size_oz = floatval($_POST['size_oz'] ?? 0);
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    $low_stock_threshold = intval($_POST['low_stock_threshold'] ?? 10);
    $active = isset($_POST['active']) ? 1 : 0;
    
    // Validate required fields
    if (empty($sku)) {
        throw new Exception('SKU is required');
    }
    if (empty($name)) {
        throw new Exception('Product name is required');
    }
    
    // Training-specific fields
    $use_in_training = isset($_POST['use_in_training']) ? 1 : 0;
    $training_context = trim($_POST['training_context'] ?? 'all');
    $recommended_first_dose_mg = floatval($_POST['recommended_first_dose_mg'] ?? 5);
    
    // Cannabinoid fields - empty values become NULL
    $thcaMg = !empty($_POST['thca_mg_per_unit']) ? floatval($_POST['thca_mg_per_unit']) : null;
    $thcD9Mg = !empty($_POST['thc_d9_mg_per_unit']) ? floatval($_POST['thc_d9_mg_per_unit']) : null;
    $thcD8Mg = !empty($_POST['thc_d8_mg_per_unit']) ? floatval($_POST['thc_d8_mg_per_unit']) : null;
    $cbdMg = !empty($_POST['cbd_mg_per_unit']) ? floatval($_POST['cbd_mg_per_unit']) : null;
    $cbgMg = !empty($_POST['cbg_mg_per_unit']) ? floatval($_POST['cbg_mg_per_unit']) : null;
    $cbnMg = !empty($_POST['cbn_mg_per_unit']) ? floatval($_POST['cbn_mg_per_unit']) : null;
    $totalCannabinoids = !empty($_POST['total_cannabinoids_mg']) ? floatval($_POST['total_cannabinoids_mg']) : null;
    
    // Cost overrides - empty values become NULL (critical fix)
    $overrideMaterialCost = !empty($_POST['override_material_cost']) ? floatval($_POST['override_material_cost']) : null;
    $overrideBottleCost = !empty($_POST['override_bottle_cost']) ? floatval($_POST['override_bottle_cost']) : null;
    $overrideLabelCost = !empty($_POST['override_label_cost']) ? floatval($_POST['override_label_cost']) : null;
    $overrideLaborCost = !empty($_POST['override_labor_cost']) ? floatval($_POST['override_labor_cost']) : null;
    $overrideMixCost = !empty($_POST['override_mix_cost']) ? floatval($_POST['override_mix_cost']) : null;
    
    // Calculate derived fields with proper validation
    $total_mg = $attribute_per_oz * $size_oz;
    $servings_10mg = floor($total_mg / 10);
    
    // Calculate ml per 10mg serving (convert oz to ml: 1 oz = 29.5735 ml)
    $ml_per_10mg_serving = ($attribute_per_oz > 0 && $size_oz > 0) ? (10 / $attribute_per_oz) * 29.5735 : 0;
    
    // Calculate oz per 10mg serving
    $oz_per_10mg_serving = $attribute_per_oz > 0 ? (10 / $attribute_per_oz) : 0;
    
    // Calculate drinks per oz (number of 10mg servings per fluid ounce)
    $drinks_per_oz = $attribute_per_oz > 0 ? ($attribute_per_oz / 10) : 0;
    
    // Calculate cost per 10mg serving
    $cost_per_10mg_serving = $servings_10mg > 0 ? ($wholesale_price / $servings_10mg) : 0;
    
    // Training features JSON
    $training_features = [
        'consistency_advantage' => isset($_POST['feature_consistency']),
        'lab_tested' => isset($_POST['feature_lab_tested']),
        'tasteless' => isset($_POST['feature_tasteless']),
        'standardization' => isset($_POST['feature_standardization']),
        'product_grade' => isset($_POST['feature_product']),
        'beginner_friendly' => isset($_POST['feature_beginner']),
        'high_attribute' => isset($_POST['feature_high_attribute']),
        'bulk_value' => isset($_POST['feature_bulk_value'])
    ];
    
    // Log the save attempt
    error_log("Product Save Attempt - ID: $product_id, SKU: $sku, User: $userId");
    
    // Update product
    $stmt = $pdo->prepare("
        UPDATE products SET
            sku = ?,
            name = ?,
            description = ?,
            wholesale_price = ?,
            msrp = ?,
            stock_quantity = ?,
            low_stock_threshold = ?,
            active = ?,
            use_in_training = ?,
            training_context = ?,
            training_features = ?,
            attribute_per_oz = ?,
            size_oz = ?,
            ml_per_10mg_serving = ?,
            oz_per_10mg_serving = ?,
            drinks_per_oz = ?,
            cost_per_10mg_serving = ?,
            servings_10mg = ?,
            total_mg = ?,
            recommended_first_dose_mg = ?,
            thca_mg_per_unit = ?,
            thc_d9_mg_per_unit = ?,
            thc_d8_mg_per_unit = ?,
            cbd_mg_per_unit = ?,
            cbg_mg_per_unit = ?,
            cbn_mg_per_unit = ?,
            total_cannabinoids_mg = ?,
            override_material_cost = ?,
            override_bottle_cost = ?,
            override_label_cost = ?,
            override_labor_cost = ?,
            override_mix_cost = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $executeResult = $stmt->execute([
        $sku, $name, $description, $wholesale_price, $msrp,
        $stock_quantity, $low_stock_threshold, $active,
        $use_in_training, $training_context, json_encode($training_features),
        $attribute_per_oz, $size_oz, $ml_per_10mg_serving, $oz_per_10mg_serving,
        $drinks_per_oz, $cost_per_10mg_serving, $servings_10mg, $total_mg,
        $recommended_first_dose_mg,
        $thcaMg, $thcD9Mg, $thcD8Mg, $cbdMg, $cbgMg, $cbnMg, $totalCannabinoids,
        $overrideMaterialCost, $overrideBottleCost, $overrideLabelCost, $overrideLaborCost, $overrideMixCost,
        $product_id
    ]);
    
    if (!$executeResult) {
        throw new Exception('Failed to update product');
    }
    
    $rowsAffected = $stmt->rowCount();
    error_log("Product Save Success - ID: $product_id, Rows Affected: $rowsAffected");
    
    // Fetch updated product data
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $updatedProduct = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Product updated successfully! All training modules will now use these values.',
        'product' => $updatedProduct,
        'rowsAffected' => $rowsAffected
    ]);
    
} catch (PDOException $e) {
    error_log("Product Save PDO Error - " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'error_type' => 'database'
    ]);
} catch (Exception $e) {
    error_log("Product Save Error - " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => 'validation'
    ]);
}

