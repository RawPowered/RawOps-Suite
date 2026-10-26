<?php
$SEO = [
    'title' => 'Edit Product — Admin',
    'description' => 'Edit product details and training module integration',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';
$product_id = intval($_GET['id'] ?? 0);

// Load training helpers first (required dependency)
require_once __DIR__ . '/../required/training-helpers.php';

// Load sync checker for preview/validation
require_once __DIR__ . '/../required/training-sync-checker.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    try {
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
        
        // NEW: Product type and classification
        $product_type = trim($_POST['product_type'] ?? 'manufactured_liquid');
        $is_liquid = isset($_POST['is_liquid']) ? 1 : 0;
        $is_dropship = isset($_POST['is_dropship']) ? 1 : 0;
        $track_inventory = isset($_POST['track_inventory']) ? 1 : 0;
        $display_unit = trim($_POST['display_unit'] ?? 'oz');
        
        // NEW: Measurements - Weight (for solids)
        $weight_grams = !empty($_POST['weight_grams']) ? floatval($_POST['weight_grams']) : null;
        $weight_oz = !empty($_POST['weight_oz']) ? floatval($_POST['weight_oz']) : null;
        $weight_kg = !empty($_POST['weight_kg']) ? floatval($_POST['weight_kg']) : null;
        $weight_mg = !empty($_POST['weight_mg']) ? floatval($_POST['weight_mg']) : null;
        
        // NEW: Measurements - Volume (for liquids)
        $volume_ml = !empty($_POST['volume_ml']) ? floatval($_POST['volume_ml']) : null;
        $volume_liters = !empty($_POST['volume_liters']) ? floatval($_POST['volume_liters']) : null;
        $volume_fl_oz = !empty($_POST['volume_fl_oz']) ? floatval($_POST['volume_fl_oz']) : null;
        
        // NEW: Dropship / external fulfillment
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $supplier_sku = trim($_POST['supplier_sku'] ?? '');
        $supplier_url = trim($_POST['supplier_url'] ?? '');
        $supplier_lead_time_days = !empty($_POST['supplier_lead_time_days']) ? intval($_POST['supplier_lead_time_days']) : null;
        $fulfillment_notes = trim($_POST['fulfillment_notes'] ?? '');
        
        // NEW: Simple unit cost (for non-manufactured products)
        $unit_cost = !empty($_POST['unit_cost']) ? floatval($_POST['unit_cost']) : null;
        
        // Product Group Assignment
        $product_group_id = !empty($_POST['product_group_id']) ? intval($_POST['product_group_id']) : null;
        
        // NEW: Product images
        $product_image = trim($_POST['product_image'] ?? '');
        $image_alt_text = trim($_POST['image_alt_text'] ?? '');
        
        // Gallery images (comma-separated or JSON)
        $gallery_input = trim($_POST['product_gallery'] ?? '');
        $product_gallery = null;
        if (!empty($gallery_input)) {
            // If it's already JSON, use it; otherwise convert comma-separated to JSON
            $gallery_array = json_decode($gallery_input);
            if (json_last_error() === JSON_ERROR_NONE) {
                $product_gallery = $gallery_input;
            } else {
                // Convert comma-separated URLs to JSON array
                $urls = array_map('trim', explode(',', $gallery_input));
                $urls = array_filter($urls); // Remove empty
                $product_gallery = json_encode($urls);
            }
        }
        
        // Training-specific fields
        $use_in_training = isset($_POST['use_in_training']) ? 1 : 0;
        $training_context = trim($_POST['training_context'] ?? 'all');
        $recommended_first_dose_mg = floatval($_POST['recommended_first_dose_mg'] ?? 5);
        
        // NEW: Cannabinoid fields
        $thcaMg = !empty($_POST['thca_mg_per_unit']) ? floatval($_POST['thca_mg_per_unit']) : null;
        $thcD9Mg = !empty($_POST['thc_d9_mg_per_unit']) ? floatval($_POST['thc_d9_mg_per_unit']) : null;
        $thcD8Mg = !empty($_POST['thc_d8_mg_per_unit']) ? floatval($_POST['thc_d8_mg_per_unit']) : null;
        $cbdMg = !empty($_POST['cbd_mg_per_unit']) ? floatval($_POST['cbd_mg_per_unit']) : null;
        $cbgMg = !empty($_POST['cbg_mg_per_unit']) ? floatval($_POST['cbg_mg_per_unit']) : null;
        $cbnMg = !empty($_POST['cbn_mg_per_unit']) ? floatval($_POST['cbn_mg_per_unit']) : null;
        $totalCannabinoids = !empty($_POST['total_cannabinoids_mg']) ? floatval($_POST['total_cannabinoids_mg']) : null;
        
        // NEW: Cost overrides
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
        $drinks_per_oz = $attribute_per_oz / 10;
        
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
        
        // Validate required fields
        if (empty($sku)) {
            throw new Exception('SKU is required');
        }
        if (empty($name)) {
            throw new Exception('Product name is required');
        }
        if ($wholesale_price <= 0) {
            throw new Exception('Wholesale price must be greater than 0');
        }
        
        if ($product_id > 0) {
            // Update existing product
            $stmt = $pdo->prepare("
                UPDATE products SET
                    product_group_id = ?,
                    product_type = ?,
                    is_liquid = ?,
                    is_dropship = ?,
                    track_inventory = ?,
                    display_unit = ?,
                    product_image = ?,
                    product_gallery = ?,
                    image_alt_text = ?,
                    sku = ?,
                    name = ?,
                    description = ?,
                    wholesale_price = ?,
                    msrp = ?,
                    stock_quantity = ?,
                    low_stock_threshold = ?,
                    active = ?,
                    weight_grams = ?,
                    weight_oz = ?,
                    weight_kg = ?,
                    weight_mg = ?,
                    volume_ml = ?,
                    volume_liters = ?,
                    volume_fl_oz = ?,
                    unit_cost = ?,
                    supplier_name = ?,
                    supplier_sku = ?,
                    supplier_url = ?,
                    supplier_lead_time_days = ?,
                    fulfillment_notes = ?,
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
            
            $stmt->execute([
                $product_group_id,
                $product_type, $is_liquid, $is_dropship, $track_inventory, $display_unit,
                $product_image ?: null, $product_gallery, $image_alt_text ?: null,
                $sku, $name, $description, $wholesale_price, $msrp,
                $stock_quantity, $low_stock_threshold, $active,
                $weight_grams, $weight_oz, $weight_kg, $weight_mg,
                $volume_ml, $volume_liters, $volume_fl_oz,
                $unit_cost,
                $supplier_name ?: null, $supplier_sku ?: null, $supplier_url ?: null, 
                $supplier_lead_time_days, $fulfillment_notes ?: null,
                $use_in_training, $training_context, json_encode($training_features),
                $attribute_per_oz, $size_oz, $ml_per_10mg_serving, $oz_per_10mg_serving,
                $drinks_per_oz, $cost_per_10mg_serving, $servings_10mg, $total_mg,
                $recommended_first_dose_mg,
                $thcaMg, $thcD9Mg, $thcD8Mg, $cbdMg, $cbgMg, $cbnMg, $totalCannabinoids,
                $overrideMaterialCost, $overrideBottleCost, $overrideLabelCost, $overrideLaborCost, $overrideMixCost,
                $product_id
            ]);
            
            $message = 'Product updated successfully! All training modules will now use these values.';
            
            // Check if user wants to create another or return to list
            if (isset($_POST['save_and_new'])) {
                flash_set('success', $message);
                redirect(path_with_base('/admin/edit-product.php?id=0'));
                exit;
            } elseif (isset($_POST['save_and_close'])) {
                flash_set('success', $message);
                redirect(path_with_base('/admin/products.php'));
                exit;
            } else {
                // Default: reload current product edit page
                flash_set('success', $message);
                redirect(path_with_base('/admin/edit-product.php?id=' . $product_id));
                exit;
            }
        } else {
            // Insert new product
            $stmt = $pdo->prepare("
                INSERT INTO products (
                    product_group_id,
                    product_type, is_liquid, is_dropship, track_inventory, display_unit,
                    product_image, product_gallery, image_alt_text,
                    sku, name, description, wholesale_price, msrp,
                    stock_quantity, low_stock_threshold, active,
                    weight_grams, weight_oz, weight_kg, weight_mg,
                    volume_ml, volume_liters, volume_fl_oz,
                    unit_cost,
                    supplier_name, supplier_sku, supplier_url, supplier_lead_time_days, fulfillment_notes,
                    use_in_training, training_context, training_features,
                    attribute_per_oz, size_oz, ml_per_10mg_serving, oz_per_10mg_serving,
                    drinks_per_oz, cost_per_10mg_serving, servings_10mg, total_mg,
                    recommended_first_dose_mg,
                    thca_mg_per_unit, thc_d9_mg_per_unit, thc_d8_mg_per_unit,
                    cbd_mg_per_unit, cbg_mg_per_unit, cbn_mg_per_unit,
                    total_cannabinoids_mg,
                    override_material_cost, override_bottle_cost, override_label_cost,
                    override_labor_cost, override_mix_cost,
                    created_at
                ) VALUES (
                    ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?,
                    ?, ?, ?,
                    ?, ?,
                    NOW()
                )
            ");
            
            $stmt->execute([
                $product_group_id,
                $product_type, $is_liquid, $is_dropship, $track_inventory, $display_unit,
                $product_image ?: null, $product_gallery, $image_alt_text ?: null,
                $sku, $name, $description, $wholesale_price, $msrp,
                $stock_quantity, $low_stock_threshold, $active,
                $weight_grams, $weight_oz, $weight_kg, $weight_mg,
                $volume_ml, $volume_liters, $volume_fl_oz,
                $unit_cost,
                $supplier_name ?: null, $supplier_sku ?: null, $supplier_url ?: null, 
                $supplier_lead_time_days, $fulfillment_notes ?: null,
                $use_in_training, $training_context, json_encode($training_features),
                $attribute_per_oz, $size_oz, $ml_per_10mg_serving, $oz_per_10mg_serving,
                $drinks_per_oz, $cost_per_10mg_serving, $servings_10mg, $total_mg,
                $recommended_first_dose_mg,
                $thcaMg, $thcD9Mg, $thcD8Mg, $cbdMg, $cbgMg, $cbnMg, $totalCannabinoids,
                $overrideMaterialCost, $overrideBottleCost, $overrideLabelCost, $overrideLaborCost, $overrideMixCost
            ]);
            
            $newProductId = $pdo->lastInsertId();
            $message = 'Product created successfully!';
            
            // Check if user wants to create another or return to list
            if (isset($_POST['save_and_new'])) {
                flash_set('success', $message . ' Create another product:');
                redirect(path_with_base('/admin/edit-product.php?id=0'));
                exit;
            } elseif (isset($_POST['save_and_close'])) {
                flash_set('success', $message);
                redirect(path_with_base('/admin/products.php'));
                exit;
            } else {
                // Default: redirect to edit the newly created product
                flash_set('success', $message . ' You can continue editing below.');
                redirect(path_with_base('/admin/edit-product.php?id=' . $newProductId));
                exit;
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error saving product: ' . $e->getMessage();
        error_log('Product save error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    }
}

// Fetch product data
$product = null;
$productGroup = null;
$isNewProduct = ($product_id === 0);

if ($product_id > 0) {
    // Editing existing product
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        flash_set('error', 'Product not found');
        redirect(path_with_base('/admin/products.php'));
    }
    
    // Fetch product group data if product is in a group
    if ($product['product_group_id']) {
        $stmt = $pdo->prepare("SELECT * FROM product_groups WHERE id = ?");
        $stmt->execute([$product['product_group_id']]);
        $productGroup = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} else {
    // Creating new product - set defaults
    $product = [
        'id' => 0,
        'product_type' => 'manufactured_liquid',
        'is_liquid' => 1,
        'is_dropship' => 0,
        'track_inventory' => 1,
        'display_unit' => 'fl_oz',
        'product_image' => '',
        'product_gallery' => null,
        'image_alt_text' => '',
        'sku' => '',
        'name' => '',
        'description' => '',
        'wholesale_price' => 0,
        'msrp' => 0,
        'attribute_per_oz' => 20,
        'size_oz' => 32,
        'stock_quantity' => 0,
        'low_stock_threshold' => 10,
        'active' => 1,
        'weight_grams' => null,
        'weight_oz' => null,
        'weight_kg' => null,
        'weight_mg' => null,
        'volume_ml' => null,
        'volume_liters' => null,
        'volume_fl_oz' => null,
        'unit_cost' => null,
        'supplier_name' => '',
        'supplier_sku' => '',
        'supplier_url' => '',
        'supplier_lead_time_days' => null,
        'fulfillment_notes' => '',
        'use_in_training' => 0,
        'training_context' => 'all',
        'training_features' => '{}',
        'recommended_first_dose_mg' => 5,
        'product_group_id' => null,
        'thca_mg_per_unit' => null,
        'thc_d9_mg_per_unit' => null,
        'thc_d8_mg_per_unit' => null,
        'cbd_mg_per_unit' => null,
        'cbg_mg_per_unit' => null,
        'cbn_mg_per_unit' => null,
        'total_cannabinoids_mg' => null,
        'override_material_cost' => null,
        'override_bottle_cost' => null,
        'override_label_cost' => null,
        'override_labor_cost' => null,
        'override_mix_cost' => null,
    ];
}

// Parse training features
$features = [];
if (!empty($product['training_features'])) {
    $features = json_decode($product['training_features'], true) ?? [];
}

// Fetch all product groups for dropdown
$productGroupsQuery = $pdo->query("
    SELECT id, name, group_key, display_order 
    FROM product_groups 
    WHERE active = 1 
    ORDER BY display_order ASC, name ASC
");
$allProductGroups = $productGroupsQuery->fetchAll(PDO::FETCH_ASSOC);

// Get comprehensive training module usage with code previews
$module_usage = find_product_usage_in_modules($product['sku']);

// Run sync verification
$sync_check = verify_product_sync($product['sku']);

// Check for conflicts
$quiz_conflicts = detect_quiz_conflicts();
$product_quiz_questions = get_product_quiz_questions($product['sku']);
$overuse_warnings = check_product_overuse($product['sku']);

// Check training balance
$training_balance = analyze_training_balance();
?>

<style>
.edit-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}
.edit-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
}
.edit-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}
.card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
}
.form-group input[type="text"],
.form-group input[type="number"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}
.form-group textarea {
    min-height: 80px;
    resize: vertical;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.checkbox-group {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    padding: 16px;
    background: #f9fafb;
    border-radius: 8px;
}
.checkbox-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: normal !important;
    margin-bottom: 0 !important;
}
.stat-box {
    background: #f0f9ff;
    border: 2px solid #3b82f6;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}
.stat-box h4 {
    margin: 0 0 12px 0;
    color: #1e40af;
    font-size: 16px;
}
.stat-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #dbeafe;
}
.stat-row:last-child {
    border-bottom: none;
}
.stat-label {
    color: #6b7280;
    font-size: 14px;
}
.stat-value {
    font-weight: 600;
    color: #1f2937;
    font-size: 14px;
}
.usage-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.usage-item {
    padding: 12px;
    background: #f9fafb;
    border-left: 4px solid #3b82f6;
    margin-bottom: 8px;
    border-radius: 4px;
}
.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-success {
    background: #dcfce7;
    color: #14532d;
    border: 1px solid #16a34a;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #dc2626;
}
.btn-group {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}
.section-header {
    background: #f9fafb;
    padding: 12px 16px;
    border-radius: 6px;
    margin: 24px 0 16px 0;
    border-left: 4px solid #3b82f6;
}
.section-header h3 {
    margin: 0;
    color: #1e40af;
    font-size: 18px;
}
.help-text {
    font-size: 13px;
    color: #6b7280;
    margin-top: 4px;
}
.warning-box {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.tab-nav {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    border-bottom: 2px solid #e5e7eb;
}
.tab-btn {
    padding: 12px 24px;
    background: #f9fafb;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: #6b7280;
    transition: all 0.2s;
}
.tab-btn:hover {
    background: #f3f4f6;
    color: #374151;
}
.tab-btn.active {
    background: white;
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
.code-preview {
    background: #1f2937;
    color: #e5e7eb;
    padding: 16px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    overflow-x: auto;
    white-space: pre;
    line-height: 1.5;
}
.code-highlight {
    background: #fbbf24;
    color: #1f2937;
    padding: 2px 4px;
}
.sync-status-good { color: #16a34a; font-weight: 600; }
.sync-status-warning { color: #f59e0b; font-weight: 600; }
.sync-status-error { color: #dc2626; font-weight: 600; }
.conflict-badge {
    display: inline-block;
    padding: 4px 12px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 8px;
}
.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 16px 24px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    animation: slideIn 0.3s ease;
    min-width: 300px;
    font-weight: 600;
}
.toast.success {
    background: #dcfce7;
    color: #14532d;
    border-left: 4px solid #16a34a;
}
.toast.error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #dc2626;
}
@keyframes slideIn {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
.saving-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.saving-spinner {
    background: white;
    padding: 24px 32px;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    font-size: 18px;
    font-weight: 600;
}
</style>

<div class="edit-container">
    <div class="edit-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <?php if ($isNewProduct): ?>
                <h1 style="margin: 0 0 8px 0;">✨ Create New Product</h1>
                <p style="margin: 0; opacity: 0.9;">Add a new product to your inventory</p>
                <?php else: ?>
                <h1 style="margin: 0 0 8px 0;">Edit Product: <?php echo htmlspecialchars($product['name']); ?></h1>
                <p style="margin: 0; opacity: 0.9;">SKU: <?php echo htmlspecialchars($product['sku']); ?></p>
                <?php endif; ?>
            </div>
            <a href="<?php echo path_with_base('/admin/products.php'); ?>" class="btn secondary">
                ← Back to Products
            </a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($product['use_in_training'] && !$isNewProduct): ?>
        <div class="warning-box">
            <strong>⚠️ Training Module Integration Active</strong>
            <p style="margin: 8px 0 0 0;">This product is used in training modules. Changes to pricing, servings, or attribute content will automatically update all course materials and quiz answers.</p>
        </div>
    <?php endif; ?>
    
    <!-- Tab Navigation -->
    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('edit')">
            📝 <?php echo $isNewProduct ? 'Create' : 'Edit'; ?> Product
        </button>
        <?php if (!$isNewProduct): ?>
        <button class="tab-btn" onclick="switchTab('quizzes')">
            ❓ Quiz Questions
            <?php 
            // Get quiz questions for this product
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM training_quiz_questions WHERE product_id = ? AND is_active = 1");
            $stmt->execute([$product_id]);
            $quizCount = $stmt->fetchColumn();
            if ($quizCount > 0):
            ?>
            <span style="background:#7c3aed;color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:6px;">
                <?php echo $quizCount; ?>
            </span>
            <?php endif; ?>
        </button>
        <button class="tab-btn" onclick="switchTab('preview')">
            👁️ Module Preview
            <?php if (!empty($module_usage)): ?>
            <span style="background:#3b82f6;color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:6px;">
                <?php echo count($module_usage); ?> modules
            </span>
            <?php endif; ?>
        </button>
        <button class="tab-btn" onclick="switchTab('sync')">
            🔄 Sync Check
            <?php if ($sync_check['status'] === 'good'): ?>
            <span style="background:#16a34a;color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:6px;">✓</span>
            <?php elseif ($sync_check['status'] === 'warning'): ?>
            <span style="background:#f59e0b;color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:6px;">!</span>
            <?php else: ?>
            <span style="background:#dc2626;color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:6px;">×</span>
            <?php endif; ?>
        </button>
        <?php endif; ?>
    </div>
    
    <!-- Tab: Edit Product -->
    <div class="tab-content active" id="tab-edit">
    <form method="post" id="product-form">
        <input type="hidden" name="save_product" value="1">
        
        <div class="edit-grid">
            <!-- Left Column: Main Product Details -->
            <div>
                <div class="card">
                    <div class="section-header">
                        <h3>📦 Basic Product Information</h3>
                    </div>
                    
                    <div class="form-group">
                        <label>SKU *</label>
                        <input type="text" name="sku" value="<?php echo htmlspecialchars($product['sku']); ?>" required>
                        <div class="help-text">Unique product identifier (e.g., BLEND-32-20)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                        <div class="help-text">Display name for training modules and sales materials</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description 
                            <span style="color: #6b7280; font-weight: normal; font-size: 12px;">(Supports shortcodes for dynamic content)</span>
                        </label>
                        
                        <!-- Shortcode Quick Insert -->
                        <div style="background: #fef3c7; border: 2px solid #f59e0b; padding: 12px; border-radius: 6px; margin-bottom: 8px;">
                            <div style="font-weight: 600; color: #92400e; margin-bottom: 8px; font-size: 13px;">⚡ Insert Shortcode:</div>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <button type="button" class="btn small" style="background: #fff7ed; color: #92400e; padding: 4px 10px; font-size: 11px;" onclick="insertIntoDescription('[product_servings id=&quot;<?php echo $product_id; ?>&quot;]')">📊 Servings</button>
                                <button type="button" class="btn small" style="background: #fff7ed; color: #92400e; padding: 4px 10px; font-size: 11px;" onclick="insertIntoDescription('[product_attribute id=&quot;<?php echo $product_id; ?>&quot; type=&quot;total&quot;]')">🌿 Total attribute</button>
                                <button type="button" class="btn small" style="background: #fff7ed; color: #92400e; padding: 4px 10px; font-size: 11px;" onclick="insertIntoDescription('[product_cost_per_serving id=&quot;<?php echo $product_id; ?>&quot;]')">💰 Cost/Serving</button>
                                <button type="button" class="btn small" style="background: #fff7ed; color: #92400e; padding: 4px 10px; font-size: 11px;" onclick="insertIntoDescription('[profit_calc id=&quot;<?php echo $product_id; ?>&quot; drink_price=&quot;12&quot;]')">📈 Profit</button>
                                <a href="<?php echo path_with_base('/admin/shortcode-builder.php'); ?>" target="_blank" class="btn small" style="background: #f59e0b; color: white; padding: 4px 10px; font-size: 11px; text-decoration: none;">🛠️ Builder</a>
                            </div>
                        </div>
                        
                        <!-- Editor Mode Toggle -->
                        <div style="margin-bottom: 8px;">
                            <button type="button" id="desc-visual-btn" onclick="switchDescriptionMode('visual')" class="btn small" style="background: #7c3aed; color: white; padding: 6px 12px; font-size: 12px;">
                                👁️ Visual
                            </button>
                            <button type="button" id="desc-code-btn" onclick="switchDescriptionMode('code')" class="btn small secondary" style="padding: 6px 12px; font-size: 12px;">
                                💻 Code
                            </button>
                        </div>
                        
                        <!-- Hidden field for form submission -->
                        <textarea name="description" id="description-source" style="display: none;"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                        
                        <!-- Visual Editor (WYSIWYG) -->
                        <div id="description-visual" contenteditable="true" style="
                            min-height: 150px;
                            max-height: 400px;
                            overflow-y: auto;
                            padding: 16px;
                            background: white;
                            border: 2px solid #d1d5db;
                            border-radius: 6px;
                            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                            line-height: 1.6;
                            font-size: 14px;
                        "><?php echo $product['description'] ?? ''; ?></div>
                        
                        <!-- Code Editor (Hidden by default) -->
                        <textarea id="description-code" style="
                            display: none;
                            width: 100%;
                            min-height: 150px;
                            padding: 12px;
                            border: 2px solid #d1d5db;
                            border-radius: 6px;
                            font-family: 'Courier New', monospace;
                            font-size: 13px;
                            line-height: 1.5;
                        "><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                        
                        <div class="help-text">Use shortcodes for dynamic content that auto-updates. Click Visual/Code to switch modes.</div>
                    </div>
                </div>
                
                <!-- NEW: Product Type & Measurements Section -->
                <div class="card" style="margin-top: 24px;">
                    <div class="section-header">
                        <h3>🏷️ Product Type & Measurements</h3>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Type *</label>
                        <select name="product_type" id="product_type" onchange="toggleProductTypeFields()">
                            <option value="manufactured_liquid" <?php echo ($product['product_type'] ?? 'manufactured_liquid') === 'manufactured_liquid' ? 'selected' : ''; ?>>
                                🧪 Manufactured Liquid (Product extracts with complex formulation)
                            </option>
                            <option value="manufactured_solid" <?php echo ($product['product_type'] ?? '') === 'manufactured_solid' ? 'selected' : ''; ?>>
                                ⚗️ Manufactured Solid (product format, capsules, tablets)
                            </option>
                            <option value="dropship" <?php echo ($product['product_type'] ?? '') === 'dropship' ? 'selected' : ''; ?>>
                                📦 Dropship (Externally fulfilled, not produced here)
                            </option>
                            <option value="simple" <?php echo ($product['product_type'] ?? '') === 'simple' ? 'selected' : ''; ?>>
                                📋 Simple Product (Basic inventory item)
                            </option>
                        </select>
                        <div class="help-text">Select product category - affects what fields are displayed</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Group</label>
                        <select name="product_group_id" id="product_group_id">
                            <option value="">-- No Group (Standalone Product) --</option>
                            <?php foreach ($allProductGroups as $group): ?>
                            <option value="<?php echo $group['id']; ?>" 
                                <?php echo ($product['product_group_id'] ?? 0) == $group['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($group['name']); ?>
                                <?php if ($group['group_key']): ?>
                                    (<?php echo htmlspecialchars($group['group_key']); ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">
                            Assign this product to a group for shared manufacturing costs, ingredients, and bulk pricing tiers.
                            <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" target="_blank" style="color: var(--blue);">→ Manage Product Groups</a>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="is_liquid" id="is_liquid" value="1" 
                                    <?php echo !empty($product['is_liquid']) ? 'checked' : ''; ?>>
                                Is Liquid Product
                            </label>
                            <div class="help-text">Check if product is a liquid (show volume) vs solid (show weight)</div>
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="track_inventory" id="track_inventory" value="1" 
                                    <?php echo !empty($product['track_inventory']) ? 'checked' : ''; ?>>
                                Track Physical Inventory
                            </label>
                            <div class="help-text">Uncheck for infinite/dropship items</div>
                        </div>
                    </div>
                    
                    <!-- Weight Measurements (for Solids) -->
                    <div id="weight_fields" style="display: none;">
                        <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 16px; margin: 16px 0; border-radius: 6px;">
                            <h4 style="margin: 0 0 12px 0; color: #14532d;">⚖️ Weight Measurements (Solids)</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Grams</label>
                                    <input type="number" step="0.001" name="weight_grams" id="weight_grams" 
                                        value="<?php echo $product['weight_grams'] ?? ''; ?>" 
                                        onchange="convertWeight('grams')">
                                    <div class="help-text">Primary weight unit</div>
                                </div>
                                <div class="form-group">
                                    <label>Ounces (oz)</label>
                                    <input type="number" step="0.0001" name="weight_oz" id="weight_oz" 
                                        value="<?php echo $product['weight_oz'] ?? ''; ?>" 
                                        onchange="convertWeight('oz')">
                                    <div class="help-text">1 oz = 28.3495 g</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Kilograms (kg)</label>
                                    <input type="number" step="0.0001" name="weight_kg" id="weight_kg" 
                                        value="<?php echo $product['weight_kg'] ?? ''; ?>" 
                                        onchange="convertWeight('kg')">
                                    <div class="help-text">1 kg = 1000 g</div>
                                </div>
                                <div class="form-group">
                                    <label>Milligrams (mg)</label>
                                    <input type="number" step="0.001" name="weight_mg" id="weight_mg" 
                                        value="<?php echo $product['weight_mg'] ?? ''; ?>" 
                                        onchange="convertWeight('mg')">
                                    <div class="help-text">1 g = 1000 mg</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Volume Measurements (for Liquids) -->
                    <div id="volume_fields">
                        <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 16px 0; border-radius: 6px;">
                            <h4 style="margin: 0 0 12px 0; color: #1e40af;">💧 Volume Measurements (Liquids)</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Milliliters (mL)</label>
                                    <input type="number" step="0.001" name="volume_ml" id="volume_ml" 
                                        value="<?php echo $product['volume_ml'] ?? ''; ?>" 
                                        onchange="convertVolume('ml')">
                                    <div class="help-text">Primary volume unit</div>
                                </div>
                                <div class="form-group">
                                    <label>Fluid Ounces (fl oz)</label>
                                    <input type="number" step="0.0001" name="volume_fl_oz" id="volume_fl_oz" 
                                        value="<?php echo $product['volume_fl_oz'] ?? ''; ?>" 
                                        onchange="convertVolume('fl_oz')">
                                    <div class="help-text">1 fl oz = 29.5735 mL</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Liters (L)</label>
                                    <input type="number" step="0.0001" name="volume_liters" id="volume_liters" 
                                        value="<?php echo $product['volume_liters'] ?? ''; ?>" 
                                        onchange="convertVolume('liters')">
                                    <div class="help-text">1 L = 1000 mL</div>
                                </div>
                                <div class="form-group">
                                    <label>Display Unit</label>
                                    <select name="display_unit" id="display_unit">
                                        <option value="ml" <?php echo ($product['display_unit'] ?? '') === 'ml' ? 'selected' : ''; ?>>mL</option>
                                        <option value="fl_oz" <?php echo ($product['display_unit'] ?? 'fl_oz') === 'fl_oz' ? 'selected' : ''; ?>>fl oz</option>
                                        <option value="L" <?php echo ($product['display_unit'] ?? '') === 'L' ? 'selected' : ''; ?>>L</option>
                                        <option value="oz" <?php echo ($product['display_unit'] ?? '') === 'oz' ? 'selected' : ''; ?>>oz (weight)</option>
                                        <option value="g" <?php echo ($product['display_unit'] ?? '') === 'g' ? 'selected' : ''; ?>>g (weight)</option>
                                        <option value="kg" <?php echo ($product['display_unit'] ?? '') === 'kg' ? 'selected' : ''; ?>>kg (weight)</option>
                                    </select>
                                    <div class="help-text">How to show size to customers</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dropship/External Fulfillment Fields -->
                    <div id="dropship_fields" style="display: none;">
                        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; margin: 16px 0; border-radius: 6px;">
                            <h4 style="margin: 0 0 12px 0; color: #92400e;">📦 Supplier & Fulfillment</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="is_dropship" id="is_dropship" value="1" 
                                            <?php echo !empty($product['is_dropship']) ? 'checked' : ''; ?>>
                                        Dropship Product
                                    </label>
                                    <div class="help-text">Externally fulfilled</div>
                                </div>
                                <div class="form-group">
                                    <label>Supplier Name</label>
                                    <input type="text" name="supplier_name" id="supplier_name" 
                                        value="<?php echo htmlspecialchars($product['supplier_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Supplier SKU</label>
                                    <input type="text" name="supplier_sku" id="supplier_sku" 
                                        value="<?php echo htmlspecialchars($product['supplier_sku'] ?? ''); ?>">
                                    <div class="help-text">Supplier's product code</div>
                                </div>
                                <div class="form-group">
                                    <label>Lead Time (days)</label>
                                    <input type="number" name="supplier_lead_time_days" id="supplier_lead_time_days" 
                                        value="<?php echo $product['supplier_lead_time_days'] ?? ''; ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Supplier URL</label>
                                <input type="url" name="supplier_url" id="supplier_url" 
                                    value="<?php echo htmlspecialchars($product['supplier_url'] ?? ''); ?>">
                                <div class="help-text">Link to supplier product page or ordering system</div>
                            </div>
                            <div class="form-group">
                                <label>Fulfillment Notes</label>
                                <textarea name="fulfillment_notes" id="fulfillment_notes" rows="3"><?php echo htmlspecialchars($product['fulfillment_notes'] ?? ''); ?></textarea>
                                <div class="help-text">Special handling, shipping, or ordering instructions</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Simple Cost Field (for non-manufactured products) -->
                    <div id="simple_cost_field" style="display: none;">
                        <div class="form-group">
                            <label>Unit Cost</label>
                            <input type="number" step="0.01" name="unit_cost" id="unit_cost" 
                                value="<?php echo $product['unit_cost'] ?? ''; ?>">
                            <div class="help-text">Simple per-unit cost (for products without complex manufacturing costs)</div>
                        </div>
                    </div>
                </div>
                
                <!-- NEW: Product Images Section -->
                <div class="card" style="margin-top: 24px;">
                    <div class="section-header">
                        <h3>🖼️ Product Images</h3>
                    </div>
                    
                    <div class="form-group">
                        <label>Primary Product Image</label>
                        <input type="url" name="product_image" id="product_image" 
                            value="<?php echo htmlspecialchars($product['product_image'] ?? ''); ?>" 
                            placeholder="https://example.com/images/product.jpg">
                        <div class="help-text">Full URL to primary product image</div>
                        
                        <?php if (!empty($product['product_image'])): ?>
                        <div style="margin-top: 12px;">
                            <img src="<?php echo htmlspecialchars($product['product_image']); ?>" 
                                alt="Product preview" 
                                style="max-width: 200px; max-height: 200px; border: 2px solid #d1d5db; border-radius: 8px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Image Alt Text (SEO)</label>
                        <input type="text" name="image_alt_text" id="image_alt_text" 
                            value="<?php echo htmlspecialchars($product['image_alt_text'] ?? ''); ?>" 
                            placeholder="32oz Product Extract Bottle">
                        <div class="help-text">Describes image for search engines and accessibility</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Gallery (Additional Images)</label>
                        <textarea name="product_gallery" id="product_gallery" rows="3" 
                            placeholder="https://example.com/img1.jpg, https://example.com/img2.jpg, https://example.com/img3.jpg"><?php 
                            $gallery = $product['product_gallery'] ?? null;
                            if ($gallery) {
                                $decoded = json_decode($gallery);
                                if (is_array($decoded)) {
                                    echo htmlspecialchars(implode(', ', $decoded));
                                } else {
                                    echo htmlspecialchars($gallery);
                                }
                            }
                        ?></textarea>
                        <div class="help-text">Comma-separated URLs for additional product images (automatically converted to JSON)</div>
                        
                        <?php 
                        if (!empty($product['product_gallery'])) {
                            $galleryImages = json_decode($product['product_gallery']);
                            if (is_array($galleryImages) && count($galleryImages) > 0): 
                        ?>
                        <div style="margin-top: 12px; display: flex; gap: 12px; flex-wrap: wrap;">
                            <?php foreach (array_slice($galleryImages, 0, 5) as $imgUrl): ?>
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" 
                                alt="Gallery image" 
                                style="max-width: 120px; max-height: 120px; border: 2px solid #d1d5db; border-radius: 6px; object-fit: contain;">
                            <?php endforeach; ?>
                            <?php if (count($galleryImages) > 5): ?>
                            <div style="padding: 40px 20px; background: #f3f4f6; border-radius: 6px; text-align: center; font-size: 12px; color: #6b7280;">
                                +<?php echo count($galleryImages) - 5; ?> more
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php 
                            endif;
                        }
                        ?>
                    </div>
                </div>
                
                <div class="card" style="margin-top: 24px;">
                    <div class="section-header">
                        <h3>💰 Pricing & Inventory</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>attribute Content per oz *</label>
                            <input type="number" step="0.01" name="attribute_per_oz" value="<?php echo $product['attribute_per_oz'] ?? 0; ?>" required>
                            <div class="help-text">Milligrams of attribute per fluid ounce</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Bottle Size (oz) *</label>
                            <input type="number" step="0.01" name="size_oz" value="<?php echo $product['size_oz'] ?? 0; ?>" required>
                            <div class="help-text">Total bottle size in fluid ounces</div>
                        </div>
                    </div>
                    
                    <div class="section-header">
                        <h3>💰 Pricing & Inventory</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Wholesale Price (Your Cost) *</label>
                            <input type="number" step="0.01" name="wholesale_price" value="<?php echo $product['wholesale_price'] ?? 0; ?>" required>
                            <div class="help-text">What sales agents pay</div>
                        </div>
                        
                        <div class="form-group">
                            <label>MSRP (Retail Price) *</label>
                            <input type="number" step="0.01" name="msrp" value="<?php echo $product['msrp'] ?? 0; ?>" required>
                            <div class="help-text">Suggested retail price for end customers</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock Quantity</label>
                            <input type="number" name="stock_quantity" value="<?php echo $product['stock_quantity'] ?? 0; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Low Stock Threshold</label>
                            <input type="number" name="low_stock_threshold" value="<?php echo $product['low_stock_threshold'] ?? 10; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="active" <?php echo $product['active'] ? 'checked' : ''; ?>>
                            Product Active (available for sale)
                        </label>
                    </div>
                    
                    <!-- Live Calculation Preview -->
                    <div id="calculation-preview"></div>
                </div>
                
                <!-- Cannabinoid Profile (if group has_cannabinoids) -->
                <?php if ($productGroup && $productGroup['has_cannabinoids']): ?>
                <div class="card" style="margin-top: 24px;">
                    <div class="section-header">
                        <h3>🌿 Cannabinoid Profile</h3>
                    </div>
                    <p style="color: #6b7280; margin-bottom: 16px;">Specify cannabinoid content for this product (leave blank to use group defaults)</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label>THCA (mg per unit)</label>
                            <input type="number" name="thca_mg_per_unit" step="0.01" value="<?php echo $product['thca_mg_per_unit'] ?? ''; ?>"
                                   onchange="updateTotalCannabinoids()">
                        </div>
                        <div class="form-group">
                            <label>Delta-9 THC (mg per unit)</label>
                            <input type="number" name="thc_d9_mg_per_unit" step="0.01" value="<?php echo $product['thc_d9_mg_per_unit'] ?? ''; ?>"
                                   onchange="updateTotalCannabinoids()">
                        </div>
                        <div class="form-group">
                            <label>Delta-8 THC (mg per unit)</label>
                            <input type="number" name="thc_d8_mg_per_unit" step="0.01" value="<?php echo $product['thc_d8_mg_per_unit'] ?? ''; ?>"
                                   onchange="updateTotalCannabinoids()">
                        </div>
                        <div class="form-group">
                            <label>product (mg per unit)</label>
                            <input type="number" name="cbd_mg_per_unit" step="0.01" value="<?php echo $product['cbd_mg_per_unit'] ?? ''; ?>"
                                   onchange="updateTotalCannabinoids()">
                        </div>
                        <div class="form-group">
                            <label>CBG (mg per unit)</label>
                            <input type="number" name="cbg_mg_per_unit" step="0.01" value="<?php echo $product['cbg_mg_per_unit'] ?? ''; ?>"
                                   onchange="updateTotalCannabinoids()">
                        </div>
                        <div class="form-group">
                            <label>CBN (mg per unit)</label>
                            <input type="number" name="cbn_mg_per_unit" step="0.01" value="<?php echo $product['cbn_mg_per_unit'] ?? ''; ?>"
                                   onchange="updateTotalCannabinoids()">
                        </div>
                    </div>
                    
                    <div style="margin-top: 12px; padding: 12px; background: #f9fafb; border-radius: 6px;">
                        <strong>Total Cannabinoids:</strong> <span id="total-cannabinoids-display">0 mg</span>
                        <input type="hidden" name="total_cannabinoids_mg" id="total_cannabinoids_mg">
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Cost Overrides (collapsible) -->
                <div class="card" style="margin-top: 24px;" id="manufacturing_cost_fields">
                    <div class="section-header" onclick="toggleSection('cost-overrides')" style="cursor: pointer;">
                        <h3>⚙️ Cost Overrides (Optional) <span id="cost-override-indicator" style="color: #6b7280; font-size: 14px; font-weight: normal;"></span></h3>
                    </div>
                    <div id="cost-overrides" style="display: none;">
                        <p style="color: #6b7280; margin-bottom: 16px;">
                            By default, this product uses costs from its product group "<?php echo htmlspecialchars($productGroup['name'] ?? 'N/A'); ?>". 
                            Override specific costs here if this product has different requirements.
                        </p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label>Material Cost Override</label>
                                <input type="number" name="override_material_cost" step="0.01" 
                                       value="<?php echo $product['override_material_cost'] ?? ''; ?>"
                                       placeholder="Use group default">
                            </div>
                            
                            <div class="form-group">
                                <label>Container/Bottle Cost Override</label>
                                <input type="number" name="override_bottle_cost" step="0.01"
                                       value="<?php echo $product['override_bottle_cost'] ?? ''; ?>"
                                       placeholder="Use group default">
                            </div>
                            
                            <div class="form-group">
                                <label>Label/Packaging Cost Override</label>
                                <input type="number" name="override_label_cost" step="0.01"
                                       value="<?php echo $product['override_label_cost'] ?? ''; ?>"
                                       placeholder="Use group default">
                            </div>
                            
                            <div class="form-group">
                                <label>Labor Cost Override</label>
                                <input type="number" name="override_labor_cost" step="0.01"
                                       value="<?php echo $product['override_labor_cost'] ?? ''; ?>"
                                       placeholder="Use group default">
                            </div>
                            
                            <div class="form-group">
                                <label>Mix/Prep Cost Override</label>
                                <input type="number" name="override_mix_cost" step="0.01"
                                       value="<?php echo $product['override_mix_cost'] ?? ''; ?>"
                                       placeholder="Use group default">
                            </div>
                        </div>
                        
                        <button type="button" class="btn secondary" onclick="clearOverrides()" style="margin-top: 12px;">
                            Clear All Overrides
                        </button>
                    </div>
                </div>
                
                <!-- Training Module Integration -->
                <div class="card" style="margin-top: 24px;">
                    <div class="section-header">
                        <h3>🎓 Training Module Integration</h3>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="use_in_training" id="use_in_training" 
                                   <?php echo $product['use_in_training'] ? 'checked' : ''; ?>
                                   onchange="document.getElementById('training-options').style.display = this.checked ? 'block' : 'none'">
                            <strong>Use this product in training modules</strong>
                        </label>
                        <div class="help-text">Enable to make this product available for training examples, quizzes, and calculations</div>
                    </div>
                    
                    <div id="training-options" style="display: <?php echo $product['use_in_training'] ? 'block' : 'none'; ?>; margin-top: 20px;">
                        <div class="form-group">
                            <label>Training Context</label>
                            <select name="training_context">
                                <option value="all" <?php echo ($product['training_context'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Levels</option>
                                <option value="beginner" <?php echo ($product['training_context'] ?? '') === 'beginner' ? 'selected' : ''; ?>>Beginner Examples</option>
                                <option value="intermediate" <?php echo ($product['training_context'] ?? '') === 'intermediate' ? 'selected' : ''; ?>>Intermediate Examples</option>
                                <option value="advanced" <?php echo ($product['training_context'] ?? '') === 'advanced' ? 'selected' : ''; ?>>Advanced Examples</option>
                                <option value="premium" <?php echo ($product['training_context'] ?? '') === 'premium' ? 'selected' : ''; ?>>Premium/High-Volume Examples</option>
                            </select>
                            <div class="help-text">When should this product be recommended in training scenarios?</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Recommended First Dose (mg attribute)</label>
                            <input type="number" step="0.1" name="recommended_first_dose_mg" 
                                   value="<?php echo $product['recommended_first_dose_mg'] ?? 5; ?>">
                            <div class="help-text">For beginner recommendations (typically 5-10mg)</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Product Features for Training (select all that apply)</label>
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" name="feature_consistency" <?php echo !empty($features['consistency_advantage']) ? 'checked' : ''; ?>>
                                    Consistency Advantage
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_lab_tested" <?php echo !empty($features['lab_tested']) ? 'checked' : ''; ?>>
                                    Lab Tested
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_tasteless" <?php echo !empty($features['tasteless']) ? 'checked' : ''; ?>>
                                    Tasteless
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_standardization" <?php echo !empty($features['standardization']) ? 'checked' : ''; ?>>
                                    attribute Standardization
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_product" <?php echo !empty($features['product_grade']) ? 'checked' : ''; ?>>
                                    catalog-ready
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_beginner" <?php echo !empty($features['beginner_friendly']) ? 'checked' : ''; ?>>
                                    Beginner Friendly
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_high_attribute" <?php echo !empty($features['high_attribute']) ? 'checked' : ''; ?>>
                                    High product specification
                                </label>
                                <label>
                                    <input type="checkbox" name="feature_bulk_value" <?php echo !empty($features['bulk_value']) ? 'checked' : ''; ?>>
                                    Bulk Value
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Calculated Values & Usage -->
            <div>
                <div class="card">
                    <h3 style="margin: 0 0 16px 0;">📊 Calculated Training Values</h3>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">
                        These values are automatically calculated and used throughout training modules:
                    </p>
                    
                    <div class="stat-box">
                        <h4>Per-Serving Metrics (10mg attribute)</h4>
                        <div class="stat-row">
                            <span class="stat-label">ML per 10mg serving</span>
                            <span class="stat-value"><?php echo number_format($product['ml_per_10mg_serving'] ?? 0, 3); ?> ml</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Oz per 10mg serving</span>
                            <span class="stat-value"><?php echo number_format($product['oz_per_10mg_serving'] ?? 0, 4); ?> oz</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Cost per 10mg serving</span>
                            <span class="stat-value">$<?php echo number_format($product['cost_per_10mg_serving'] ?? 0, 2); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Drinks per oz</span>
                            <span class="stat-value"><?php echo number_format($product['drinks_per_oz'] ?? 0, 1); ?> drinks</span>
                        </div>
                    </div>
                    
                    <div class="stat-box">
                        <h4>Bottle Totals</h4>
                        <div class="stat-row">
                            <span class="stat-label">Total attribute Content</span>
                            <span class="stat-value"><?php echo number_format($product['total_mg'] ?? 0, 0); ?> mg</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Total 10mg Servings</span>
                            <span class="stat-value"><?php echo number_format($product['servings_10mg'] ?? 0, 0); ?> drinks</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Drinks per oz</span>
                            <span class="stat-value"><?php echo number_format($product['drinks_per_oz'] ?? 0, 1); ?></span>
                        </div>
                    </div>
                    
                    <!-- Pricing Model Breakdown -->
                    <?php if ($product['material_cost'] || $product['total_production_cost']): ?>
                    <div class="stat-box" style="background: #fef3c7; border-color: #f59e0b;">
                        <h4 style="color: #92400e;">💰 Pricing Breakdown</h4>
                        <div class="stat-row">
                            <span class="stat-label">Material Cost (per bottle)</span>
                            <span class="stat-value">$<?php echo number_format($product['material_cost'] ?? 0, 2); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Total Production Cost</span>
                            <span class="stat-value">$<?php echo number_format($product['total_production_cost'] ?? 0, 2); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Profit Margin (after 20% comm.)</span>
                            <span class="stat-value" style="color: #059669;"><?php echo number_format($product['profit_margin_percent'] ?? 0, 0); ?>%</span>
                        </div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f59e0b;">
                            <a href="<?php echo path_with_base('/admin/pricing-manager.php?action=recalculate_single&product_id=' . $product['id']); ?>" 
                               class="btn secondary" 
                               style="width: 100%; font-size: 13px; padding: 8px; display: block; text-align: center; text-decoration: none;"
                               onclick="return confirm('Recalculate pricing for this product?');">
                                🔄 Recalculate Pricing
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="stat-box">
                        <h4>Profit Scenarios (@$12/drink)</h4>
                        <?php 
                        $servings = $product['servings_10mg'] ?? 0;
                        $cost = $product['wholesale_price'] ?? 0;
                        $drink_price = 12;
                        $revenue = $servings * $drink_price;
                        $gross_profit = $revenue - $cost;
                        $base_cost = $servings * 3; // $3 base cost per drink
                        $net_profit = $gross_profit - $base_cost;
                        ?>
                        <div class="stat-row">
                            <span class="stat-label">Total Revenue</span>
                            <span class="stat-value" style="color: #059669;">$<?php echo number_format($revenue, 2); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Gross Profit (product only)</span>
                            <span class="stat-value" style="color: #059669;">$<?php echo number_format($gross_profit, 2); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Net Profit (after $3/drink)</span>
                            <span class="stat-value" style="color: #059669; font-weight: 700;">$<?php echo number_format($net_profit, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Pricing Information -->
                <?php 
                try {
                    $stmt = $pdo->prepare("SELECT * FROM bulk_pricing WHERE sku = ? AND active = 1 ORDER BY quantity_min ASC");
                    $stmt->execute([$product['sku']]);
                    $bulk_pricing = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $bulk_pricing = [];
                }
                ?>
                
                <?php if (!empty($bulk_pricing)): ?>
                <div class="card" style="margin-top: 24px;">
                    <h3 style="margin: 0 0 16px 0;">💰 Bulk Pricing Tiers</h3>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">
                        Volume pricing for <?php echo htmlspecialchars($product['sku']); ?>:
                    </p>
                    <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f3f4f6;">
                                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 13px;">Quantity</th>
                                    <th style="padding: 8px; text-align: right; border-bottom: 1px solid #e5e7eb; font-size: 13px;">Price</th>
                                    <th style="padding: 8px; text-align: right; border-bottom: 1px solid #e5e7eb; font-size: 13px;">Discount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bulk_pricing as $tier): ?>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #f3f4f6; font-size: 13px;">
                                        <?php echo htmlspecialchars($tier['quantity_tier_name']); ?> (<?php echo $tier['quantity_min']; ?>+)
                                    </td>
                                    <td style="padding: 8px; text-align: right; border-bottom: 1px solid #f3f4f6; font-size: 13px; font-weight: 600;">
                                        $<?php echo number_format($tier['unit_price'], 2); ?>
                                    </td>
                                    <td style="padding: 8px; text-align: right; border-bottom: 1px solid #f3f4f6; font-size: 13px;">
                                        <?php echo $tier['discount_percent']; ?>%
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($product['use_in_training'] && !empty($module_usage)): ?>
                <div class="card" style="margin-top: 24px;">
                    <h3 style="margin: 0 0 16px 0;">📚 Used In Training Modules</h3>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">
                        This product is referenced in <?php echo count($module_usage); ?> training module(s):
                    </p>
                    <ul class="usage-list">
                        <?php foreach ($module_usage as $usage): ?>
                        <li class="usage-item">
                            <strong><?php echo htmlspecialchars($usage['module_name']); ?></strong>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                <?php echo $usage['match_count']; ?> reference(s) | File: <?php echo htmlspecialchars($usage['file']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="background: #f0f9ff; padding: 12px; border-radius: 6px; margin-top: 12px; font-size: 13px;">
                        💡 <strong>Tip:</strong> Click "Module Preview" tab to see exact locations and code snippets.
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="btn-group" style="display: flex; gap: 12px; flex-wrap: wrap;">
                <button type="submit" name="save" class="btn" style="background: var(--green); color: white; flex: 1; min-width: 200px;">
                    💾 Save & Continue Editing
                </button>
                <button type="submit" name="save_and_new" class="btn" style="background: #3b82f6; color: white; flex: 1; min-width: 200px;">
                    ✨ Save & Create New
                </button>
                <button type="submit" name="save_and_close" class="btn" style="background: #f59e0b; color: white; flex: 1; min-width: 200px;">
                    📋 Save & Back to List
                </button>
                <a href="<?php echo path_with_base('/admin/products.php'); ?>" class="btn secondary">
                    Cancel
                </a>
            </div>
            <div style="margin-top: 12px; padding: 12px; background: #f3f4f6; border-radius: 6px; font-size: 13px; color: #6b7280;">
                <strong>💡 Tip:</strong> 
                <span style="color: var(--green);">Save & Continue</span> to keep editing this product, 
                <span style="color: #3b82f6;">Save & Create New</span> to add another product immediately, or 
                <span style="color: #f59e0b;">Save & Back to List</span> to return to product management.
            </div>
        </div>
    </form>
    </div><!-- End tab-edit -->
    
    <!-- Tab: Quiz Questions -->
    <div class="tab-content" id="tab-quizzes">
        <div class="card">
            <h2 style="margin: 0 0 16px 0;">❓ Quiz Questions for <?php echo htmlspecialchars($product['name']); ?></h2>
            <p style="color: #6b7280; margin-bottom: 24px;">
                Manage quiz questions that reference or use this product. Questions will dynamically update when product data changes.
            </p>
            
            <?php
            // Get quiz questions for this product
            require_once __DIR__ . '/../required/quiz-renderer.php';
            $productQuizzes = $pdo->prepare("
                SELECT 
                    tqq.*,
                    tm.title as module_title,
                    tm.slug as module_slug
                FROM training_quiz_questions tqq
                JOIN training_modules tm ON tm.id = tqq.module_id
                WHERE tqq.product_id = ?
                  AND tqq.is_active = 1
                ORDER BY tm.sort_order, tqq.quiz_position, tqq.sort_order
            ");
            $productQuizzes->execute([$product_id]);
            $quizzes = $productQuizzes->fetchAll(PDO::FETCH_ASSOC);
            
            // DEBUG: Log what we found
            error_log("Product Quiz Query - Product ID: $product_id, Found: " . count($quizzes) . " quizzes");
            if (empty($quizzes)) {
                error_log("Product Quiz Debug - No quizzes found for product_id=$product_id, checking database...");
                // Check if ANY quizzes exist for this product (including inactive)
                $debugStmt = $pdo->prepare("SELECT id, question_text, is_active FROM training_quiz_questions WHERE product_id = ?");
                $debugStmt->execute([$product_id]);
                $allQuizzes = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Product Quiz Debug - Total quizzes (including inactive): " . count($allQuizzes));
                if (!empty($allQuizzes)) {
                    foreach ($allQuizzes as $q) {
                        error_log("  - Quiz ID {$q['id']}: is_active={$q['is_active']}, question=" . substr($q['question_text'], 0, 50));
                    }
                }
            }
            
            // Check for potential orphaned quizzes (mentions this product but not linked)
            $sku = $product['sku'];
            $name = $product['name'];
            $orphanedQuizzes = $pdo->prepare("
                SELECT 
                    tqq.id,
                    tqq.question_text,
                    tqq.product_id,
                    tm.title as module_title
                FROM training_quiz_questions tqq
                JOIN training_modules tm ON tm.id = tqq.module_id
                WHERE tqq.product_id IS NULL
                  AND tqq.is_active = 1
                  AND (tqq.question_text LIKE ? OR tqq.question_text LIKE ?)
                LIMIT 10
            ");
            $orphanedQuizzes->execute(["%$sku%", "%$name%"]);
            $orphaned = $orphanedQuizzes->fetchAll(PDO::FETCH_ASSOC);
            
            // DEBUG: Log orphaned quizzes found
            if (!empty($orphaned)) {
                error_log("Product Quiz Debug - Found " . count($orphaned) . " orphaned quizzes mentioning this product:");
                foreach ($orphaned as $o) {
                    error_log("  - Orphan ID {$o['id']}: " . substr($o['question_text'], 0, 50));
                }
            }
            ?>
            
            <?php if (empty($quizzes)): ?>
                <div style="background: #f9fafb; padding: 40px; text-align: center; border-radius: 8px;">
                    <div style="font-size: 48px; margin-bottom: 16px;">❓</div>
                    <p style="color: #6b7280; margin: 0 0 8px 0;">
                        No quiz questions linked to this product yet.
                    </p>
                    <p style="color: #6b7280; margin: 0 0 4px 0; font-size: 13px;">
                        Product ID: <?php echo $product_id; ?> • SKU: <?php echo htmlspecialchars($product['sku']); ?>
                    </p>
                    <p style="color: #dc2626; margin: 0; font-size: 12px; font-family: monospace;">
                        DEBUG: Check error_app.log for quiz query results
                    </p>
                    <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?return=product&product_id=' . $product_id); ?>" class="btn" style="margin-top: 16px; background: #7c3aed; color: white; text-decoration: none; display: inline-block;">
                        Create Quiz Question
                    </a>
                    
                    <?php if (!empty($orphaned)): ?>
                    <div style="background: #fef3c7; padding: 16px; border-radius: 8px; margin-top: 24px; text-align: left; border-left: 4px solid #f59e0b;">
                        <h4 style="margin: 0 0 12px 0; color: #92400e;">⚠️ Potential Orphaned Quizzes Found</h4>
                        <p style="margin: 0 0 12px 0; color: #6b7280; font-size: 13px;">
                            These quizzes mention "<?php echo htmlspecialchars($product['name']); ?>" but aren't linked to this product:
                        </p>
                        <?php foreach ($orphaned as $orph): ?>
                        <div style="background: white; padding: 12px; border-radius: 6px; margin-bottom: 8px; font-size: 13px;">
                            <strong>ID <?php echo $orph['id']; ?>:</strong> <?php echo htmlspecialchars($orph['question_text']); ?>
                            <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                Module: <?php echo htmlspecialchars($orph['module_title']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <p style="margin: 12px 0 0 0; font-size: 12px; color: #78350f;">
                            <strong>Fix:</strong> Edit these quizzes and select this product from the dropdown, or run:
                            <code style="background: white; padding: 2px 6px; border-radius: 3px;">
                                UPDATE training_quiz_questions SET product_id = <?php echo $product_id; ?> WHERE id IN (<?php echo implode(',', array_column($orphaned, 'id')); ?>);
                            </code>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 20px;">
                    <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?return=product&product_id=' . $product_id); ?>" class="btn" style="background: #7c3aed; color: white; text-decoration: none; display: inline-block;">
                        ➕ Add New Question
                    </a>
                </div>
                
                <?php foreach ($quizzes as $quiz): ?>
                    <?php
                    $options = json_decode($quiz['options'], true);
                    $positionColors = [
                        'intro' => '#3b82f6',
                        'early' => '#059669',
                        'mid' => '#f59e0b',
                        'late' => '#dc2626',
                        'final' => '#7c3aed'
                    ];
                    $positionColor = $positionColors[$quiz['quiz_position']] ?? '#6b7280';
                    ?>
                    <div style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div>
                                <h4 style="margin: 0 0 8px 0; color: #1f2937;">
                                    <?php echo htmlspecialchars($quiz['module_title']); ?>
                                </h4>
                                <div style="font-size: 13px; color: #6b7280;">
                                    Position: <span style="background: <?php echo $positionColor; ?>; color: white; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                        <?php echo ucfirst($quiz['quiz_position']); ?>
                                    </span>
                                    <?php if ($quiz['rotation_enabled']): ?>
                                        • <span style="color: #7c3aed;">🔄 Rotation Enabled</span>
                                    <?php endif; ?>
                                    <?php if ($quiz['requires_calculation']): ?>
                                        • <span style="color: #f59e0b;">🧮 Dynamic Calculation</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                                            <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?id=' . $quiz['id'] . '&return=product&product_id=' . $product_id); ?>" class="btn small secondary" style="text-decoration: none;">
                                                Edit
                                            </a>
                        </div>
                        
                        <div style="background: #f9fafb; padding: 16px; border-radius: 6px; margin-bottom: 12px;">
                            <strong style="color: #374151;">Question:</strong>
                            <p style="margin: 8px 0 0 0; color: #6b7280;"><?php echo nl2br(htmlspecialchars($quiz['question_text'])); ?></p>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                            <?php foreach ($options as $key => $optionText): ?>
                                <?php 
                                $isCorrect = $key === $quiz['correct_answer'];
                                $bgColor = $isCorrect ? '#dcfce7' : '#f3f4f6';
                                $textColor = $isCorrect ? '#14532d' : '#374151';
                                ?>
                                <div style="padding: 8px 12px; background: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>; border-radius: 4px; font-size: 13px;">
                                    <strong><?php echo strtoupper($key); ?>:</strong> <?php echo htmlspecialchars($optionText); ?>
                                    <?php if ($isCorrect): ?> ✓<?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (!empty($quiz['explanation'])): ?>
                            <div style="background: #fef3c7; padding: 12px; border-radius: 6px; border-left: 4px solid #f59e0b;">
                                <strong style="color: #92400e;">Explanation:</strong>
                                <span style="color: #78350f;"><?php echo htmlspecialchars($quiz['explanation']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <!-- Debug section - show orphaned quizzes if any -->
                <?php if (!empty($orphaned)): ?>
                <div style="background: #fef3c7; padding: 20px; border-radius: 12px; margin-top: 24px; border-left: 4px solid #f59e0b;">
                    <h4 style="margin: 0 0 12px 0; color: #92400e;">⚠️ Orphaned Quizzes Detected</h4>
                    <p style="margin: 0 0 12px 0; color: #6b7280; font-size: 13px;">
                        These quizzes mention this product in the question text but aren't linked via `product_id`:
                    </p>
                    <?php foreach ($orphaned as $orph): ?>
                    <div style="background: white; padding: 12px; border-radius: 6px; margin-bottom: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <strong>ID <?php echo $orph['id']; ?>:</strong> <?php echo htmlspecialchars($orph['question_text']); ?>
                                <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                    Module: <?php echo htmlspecialchars($orph['module_title']); ?>
                                </div>
                            </div>
                            <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?id=' . $orph['id'] . '&return=product&product_id=' . $product_id); ?>" 
                               class="btn small" style="background: #f59e0b; color: white; text-decoration: none; margin-left: 12px;">
                                Link to Product
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div style="background: white; padding: 12px; border-radius: 6px; margin-top: 12px; font-family: monospace; font-size: 12px;">
                        <strong>Quick Fix SQL:</strong><br>
                        <code style="color: #059669;">
                            UPDATE training_quiz_questions SET product_id = <?php echo $product_id; ?> WHERE id IN (<?php echo implode(',', array_column($orphaned, 'id')); ?>);
                        </code>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div><!-- End tab-quizzes -->
    
    <!-- Tab: Module Preview -->
    <div class="tab-content" id="tab-preview">
        <div class="card">
            <h2 style="margin: 0 0 16px 0;">👁️ Module Usage Preview</h2>
            <p style="color: #6b7280; margin-bottom: 24px;">
                This shows exactly where "<strong><?php echo htmlspecialchars($product['sku']); ?></strong>" is referenced in training modules with surrounding context.
            </p>
            
            <?php if (empty($module_usage)): ?>
                <div style="background: #f9fafb; padding: 32px; text-align: center; border-radius: 8px;">
                    <p style="color: #6b7280;">This product is not currently used in any training modules.</p>
                    <p style="font-size: 13px; margin-top: 8px;">
                        Enable "Use this product in training modules" in the Edit tab to make it available.
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($module_usage as $module): ?>
                <div style="background: #f9fafb; padding: 20px; border-radius: 12px; margin-bottom: 24px; border-left: 4px solid #3b82f6;">
                    <h3 style="margin: 0 0 12px 0; color: #1e40af;">
                        <?php echo htmlspecialchars($module['module_name']); ?>
                    </h3>
                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">
                        File: <code><?php echo htmlspecialchars($module['file']); ?></code> • 
                        <?php echo $module['match_count']; ?> reference(s) found
                    </div>
                    
                    <?php foreach ($module['matches'] as $idx => $match): ?>
                    <div style="background: white; padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">
                            <strong>Line <?php echo $match['line_number']; ?></strong> 
                            (context: lines <?php echo $match['context_start']; ?>-<?php echo $match['context_end']; ?>)
                        </div>
                        <div class="code-preview"><?php 
                            // Highlight the SKU in the context
                            $highlighted = str_replace(
                                [$product['sku'], str_replace('BLEND-', '', $product['sku'])],
                                ['<span class="code-highlight">' . $product['sku'] . '</span>', 
                                 '<span class="code-highlight">' . str_replace('BLEND-', '', $product['sku']) . '</span>'],
                                htmlspecialchars($match['context'])
                            );
                            echo $highlighted;
                        ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div><!-- End tab-preview -->
    
    <!-- Tab: Sync Check -->
    <div class="tab-content" id="tab-sync">
        <div class="card">
            <h2 style="margin: 0 0 16px 0;">🔄 Training Data Sync Verification</h2>
            
            <!-- Overall Sync Status -->
            <div style="background: <?php 
                echo $sync_check['status'] === 'good' ? '#dcfce7' : 
                     ($sync_check['status'] === 'warning' ? '#fef3c7' : '#fee2e2'); 
            ?>; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="font-size: 48px;">
                        <?php echo $sync_check['status'] === 'good' ? '✅' : 
                                   ($sync_check['status'] === 'warning' ? '⚠️' : '❌'); ?>
                    </div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0;" class="sync-status-<?php echo $sync_check['status']; ?>">
                            Sync Status: <?php echo ucfirst($sync_check['status']); ?> 
                            (<?php echo $sync_check['sync_percent']; ?>%)
                        </h3>
                        <p style="margin: 0; font-size: 14px;">
                            <?php echo $sync_check['checks_passed']; ?> of <?php echo $sync_check['total_checks']; ?> checks passed
                        </p>
                    </div>
                    <button type="button" class="btn secondary" onclick="location.reload();">
                        🔄 Re-check Sync
                    </button>
                </div>
            </div>
            
            <!-- Issues Found -->
            <?php if (!empty($sync_check['issues'])): ?>
            <div style="background: #fee2e2; border: 2px solid #dc2626; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; color: #991b1b;">Issues Detected:</h4>
                <ul style="margin: 0; color: #7f1d1d;">
                    <?php foreach ($sync_check['issues'] as $issue): ?>
                    <li><?php echo htmlspecialchars($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Overuse Warnings -->
            <?php if (!empty($overuse_warnings)): ?>
            <div style="background: #fef3c7; border: 2px solid #f59e0b; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; color: #92400e;">Assignment Warnings:</h4>
                <ul style="margin: 0; color: #78350f;">
                    <?php foreach ($overuse_warnings as $warning): ?>
                    <li><?php echo htmlspecialchars($warning); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Quiz Question Conflicts -->
            <?php if (!empty($quiz_conflicts)): ?>
            <div style="background: #fee2e2; border: 2px solid #dc2626; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; color: #991b1b;">⚠️ Quiz Conflicts Detected!</h4>
                <p style="margin: 0 0 12px 0; color: #7f1d1d;">
                    Multiple products are assigned to the same quiz question. This can cause answer conflicts.
                </p>
                <div style="background: white; padding: 16px; border-radius: 8px;">
                    <?php foreach ($quiz_conflicts as $conflict): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <strong><?php echo htmlspecialchars($conflict['module_name']); ?></strong> - 
                        Question <?php echo $conflict['quiz_question_num']; ?>
                        <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                            Assigned to: <?php echo htmlspecialchars($conflict['products']); ?>
                            <span class="conflict-badge"><?php echo $conflict['product_count']; ?> conflicts</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Quiz Questions Using This Product -->
            <?php if (!empty($product_quiz_questions)): ?>
            <div style="background: #eff6ff; border: 2px solid #3b82f6; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; color: #1e40af;">📝 Quiz Questions Using This Product</h4>
                <div style="background: white; padding: 16px; border-radius: 8px;">
                    <?php foreach ($product_quiz_questions as $quiz): ?>
                    <div style="padding: 12px 0; border-bottom: 1px solid #dbeafe;">
                        <strong><?php echo htmlspecialchars($quiz['module_name']); ?></strong>
                        <div style="font-size: 13px; margin-top: 4px;">
                            Question <?php echo $quiz['quiz_question_num']; ?> 
                            <?php if ($quiz['correct_answer_key']): ?>
                            | Correct Answer: <strong><?php echo strtoupper($quiz['correct_answer_key']); ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php if ($quiz['notes']): ?>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            <?php echo htmlspecialchars($quiz['notes']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Training Balance Analysis -->
            <div style="background: #f0fdf4; border: 2px solid #16a34a; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; color: #166534;">📊 Training Assignment Balance</h4>
                <p style="font-size: 13px; color: #065f46; margin-bottom: 12px;">
                    Distribution of products across training contexts:
                </p>
                <div style="background: white; padding: 16px; border-radius: 8px;">
                    <?php foreach ($training_balance['balance'] as $balance): ?>
                    <div style="padding: 8px 0; border-bottom: 1px solid #d1fae5;">
                        <div style="font-weight: 600; color: #374151;">
                            <?php echo ucfirst($balance['training_context']); ?> Context: 
                            <span style="color: #16a34a;"><?php echo $balance['product_count']; ?> product(s)</span>
                        </div>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            <?php echo htmlspecialchars($balance['products']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!empty($training_balance['recommendations'])): ?>
                <div style="background: #fef3c7; padding: 12px; border-radius: 6px; margin-top: 12px;">
                    <strong style="color: #92400e;">Recommendations:</strong>
                    <ul style="margin: 8px 0 0 0; color: #78350f;">
                        <?php foreach ($training_balance['recommendations'] as $rec): ?>
                        <li><?php echo htmlspecialchars($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Detailed Sync Checks -->
            <div class="card" style="background: #f9fafb;">
                <h4 style="margin: 0 0 16px 0;">Detailed Checks:</h4>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f3f4f6;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Check</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">Training Enabled</td>
                            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #e5e7eb;">
                                <?php echo $product['use_in_training'] ? '✅' : '❌'; ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                <?php echo $product['use_in_training'] ? 'Enabled' : 'Disabled - not available for training'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">Calculated Fields</td>
                            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #e5e7eb;">
                                <?php echo ($product['total_mg'] && $product['servings_10mg']) ? '✅' : '❌'; ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                <?php echo ($product['total_mg'] && $product['servings_10mg']) ? 
                                    number_format($product['total_mg']) . 'mg total, ' . $product['servings_10mg'] . ' servings' : 
                                    'Missing attribute or size data'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">Module References</td>
                            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #e5e7eb;">
                                <?php echo !empty($module_usage) ? '✅' : '❌'; ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                Found in <?php echo count($module_usage); ?> module(s)
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">Bulk Pricing</td>
                            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #e5e7eb;">
                                <?php 
                                try {
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bulk_pricing WHERE sku = ? AND active = 1");
                                    $stmt->execute([$product['sku']]);
                                    $bp_count = $stmt->fetchColumn();
                                    echo $bp_count >= 4 ? '✅' : '⚠️';
                                } catch (Exception $e) {
                                    echo '❓';
                                }
                                ?>
                            </td>
                            <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                <?php 
                                try {
                                    echo $bp_count . ' pricing tier(s) configured';
                                } catch (Exception $e) {
                                    echo 'Unable to check';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 12px;">Quiz Assignments</td>
                            <td style="padding: 12px; text-align: center;">
                                <?php echo !empty($product_quiz_questions) ? '✅' : '-'; ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo count($product_quiz_questions); ?> quiz question(s)
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- End tab-sync -->
</div><!-- End edit-container -->

<script>
// Toast notification system
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(400px)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Saving overlay
function showSavingOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'saving-overlay';
    overlay.id = 'saving-overlay';
    overlay.innerHTML = '<div class="saving-spinner">💾 Saving product...</div>';
    document.body.appendChild(overlay);
}

function hideSavingOverlay() {
    const overlay = document.getElementById('saving-overlay');
    if (overlay) {
        overlay.remove();
    }
}

// Tab switching functionality
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Deactivate all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    const selectedTab = document.getElementById('tab-' + tabName);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }
    
    // Activate button
    event.target.closest('.tab-btn').classList.add('active');
    
    // Save current tab to session storage
    sessionStorage.setItem('productEditActiveTab', tabName);
}

// Traditional form submission with confirmation
document.addEventListener('DOMContentLoaded', function() {
    const productForm = document.getElementById('product-form');
    if (!productForm) {
        console.error('Product form not found!');
        return;
    }
    
    console.log('Product form found and handler attached');
    
    productForm.addEventListener('submit', function(e) {
        console.log('Form submit triggered');
        
        const useInTraining = document.getElementById('use_in_training')?.checked;
        
        // Show confirmation for training products
        if (useInTraining) {
            const confirmed = confirm(
                'This product is used in training modules.\n\n' +
                'Saving will update all related:\n' +
                '• Pricing examples\n' +
                '• Profit calculations\n' +
                '• Quiz answers\n' +
                '• Commission scenarios\n\n' +
                'Continue?'
            );
            
            if (!confirmed) {
                e.preventDefault();
                console.log('User cancelled save');
                return false;
            }
        }
        
        // Save current tab before page reloads
        const activeTab = sessionStorage.getItem('productEditActiveTab') || 'edit';
        sessionStorage.setItem('productEditActiveTabBeforeSave', activeTab);
        
        // Show saving overlay
        showSavingOverlay();
        
        // Disable submit button to prevent double-submit
        const submitBtn = productForm.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = '💾 Saving...';
        }
        
        console.log('Form submitting via traditional POST...');
        // Let the form submit normally (page will reload)
        return true;
    });
});

// Refresh product data after save
async function refreshProductData(productId) {
    try {
        const response = await fetch(window.__BASE_PREFIX + '/admin/api/refresh-product-data.php?id=' + productId);
        const result = await response.json();
        
        if (result.success) {
            // Update calculated values display
            updateCalculatedValuesDisplay(result.product, result.profitScenario);
        }
    } catch (error) {
        console.error('Failed to refresh product data:', error);
    }
}

// Update calculated values in the UI
function updateCalculatedValuesDisplay(product, profitScenario) {
    // Update all displayed values
    const updates = {
        'ml_per_10mg_serving': (product.ml_per_10mg_serving || 0).toFixed(3) + ' ml',
        'oz_per_10mg_serving': (product.oz_per_10mg_serving || 0).toFixed(4) + ' oz',
        'cost_per_10mg_serving': '$' + (product.cost_per_10mg_serving || 0).toFixed(2),
        'drinks_per_oz': (product.drinks_per_oz || 0).toFixed(1) + ' drinks',
        'total_mg': (product.total_mg || 0).toLocaleString() + ' mg',
        'servings_10mg': (product.servings_10mg || 0).toLocaleString() + ' drinks'
    };
    
    // Update each stat value element
    document.querySelectorAll('.stat-value').forEach(elem => {
        const label = elem.previousElementSibling?.textContent;
        if (label) {
            if (label.includes('ML per 10mg')) elem.textContent = updates.ml_per_10mg_serving;
            else if (label.includes('Oz per 10mg')) elem.textContent = updates.oz_per_10mg_serving;
            else if (label.includes('Cost per 10mg')) elem.textContent = updates.cost_per_10mg_serving;
            else if (label.includes('Drinks per oz') && !label.includes('Total')) elem.textContent = updates.drinks_per_oz;
            else if (label.includes('Total attribute')) elem.textContent = updates.total_mg;
            else if (label.includes('Total 10mg')) elem.textContent = updates.servings_10mg;
        }
    });
    
    // Update profit scenario
    if (profitScenario) {
        const profitElements = document.querySelectorAll('.stat-box');
        profitElements.forEach(box => {
            if (box.innerHTML.includes('Profit Scenarios')) {
                const values = box.querySelectorAll('.stat-value');
                if (values.length >= 3) {
                    values[0].textContent = '$' + profitScenario.revenue.toFixed(2);
                    values[1].textContent = '$' + profitScenario.grossProfit.toFixed(2);
                    values[2].textContent = '$' + profitScenario.netProfit.toFixed(2);
                }
            }
        });
    }
}

// Restore active tab on page load and initialize cannabinoid calculator
document.addEventListener('DOMContentLoaded', function() {
    // Check if URL has a hash (e.g., #tab-quizzes)
    const urlHash = window.location.hash;
    let tabToActivate = null;
    
    if (urlHash === '#tab-quizzes' || urlHash === '#quizzes') {
        tabToActivate = 'quizzes';
    } else if (urlHash === '#tab-preview' || urlHash === '#preview') {
        tabToActivate = 'preview';
    } else if (urlHash === '#tab-sync' || urlHash === '#sync') {
        tabToActivate = 'sync';
    } else {
        // Restore tab state from session storage
        const savedTab = sessionStorage.getItem('productEditActiveTabBeforeSave') || sessionStorage.getItem('productEditActiveTab');
        
        // Clear the "before save" flag
        if (sessionStorage.getItem('productEditActiveTabBeforeSave')) {
            sessionStorage.removeItem('productEditActiveTabBeforeSave');
        }
        
        tabToActivate = savedTab;
    }
    
    // Activate the tab
    if (tabToActivate && tabToActivate !== 'edit') {
        const tabBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => 
            btn.textContent.includes(tabToActivate === 'quizzes' ? 'Quiz Questions' :
                                   tabToActivate === 'preview' ? 'Module Preview' : 
                                   tabToActivate === 'sync' ? 'Sync Check' : 'Edit Product')
        );
        if (tabBtn) {
            setTimeout(() => tabBtn.click(), 100);
        }
    }
    
    // Initialize cannabinoid total calculator
    updateTotalCannabinoids();
    
    console.log('Page loaded, tab restored to:', tabToActivate || 'edit');
    if (urlHash) console.log('URL hash detected:', urlHash);
});

// Auto-calculate preview values as user types
function updateCalculations() {
    const attributePerOz = parseFloat(document.querySelector('[name="attribute_per_oz"]').value) || 0;
    const sizeOz = parseFloat(document.querySelector('[name="size_oz"]').value) || 0;
    const wholesalePrice = parseFloat(document.querySelector('[name="wholesale_price"]').value) || 0;
    
    if (attributePerOz > 0 && sizeOz > 0) {
        // Calculate all derived values
        const totalMg = attributePerOz * sizeOz;
        const servings10mg = Math.floor(totalMg / 10);
        const mlPer10mg = (10 / attributePerOz) * 29.5735; // Convert oz to ml
        const ozPer10mg = 10 / attributePerOz;
        const drinksPerOz = attributePerOz / 10;
        const costPerServing = servings10mg > 0 ? (wholesalePrice / servings10mg) : 0;
        
        // Update display values in real-time
        const previewDiv = document.getElementById('calculation-preview');
        if (previewDiv) {
            previewDiv.innerHTML = `
                <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin-top: 16px; border: 2px solid #3b82f6;">
                    <h4 style="margin: 0 0 12px 0; color: #1e40af;">📊 Live Calculation Preview</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                        <div><strong>Total attribute:</strong> ${totalMg.toFixed(0)} mg</div>
                        <div><strong>10mg Servings:</strong> ${servings10mg} drinks</div>
                        <div><strong>ML per 10mg:</strong> ${mlPer10mg.toFixed(3)} ml</div>
                        <div><strong>Oz per 10mg:</strong> ${ozPer10mg.toFixed(4)} oz</div>
                        <div><strong>Drinks per oz:</strong> ${drinksPerOz.toFixed(1)} drinks</div>
                        <div><strong>Cost per serving:</strong> $${costPerServing.toFixed(2)}</div>
                    </div>
                </div>
            `;
        }
        
        console.log('Updated calculations:', {
            totalMg,
            servings10mg,
            mlPer10mg: mlPer10mg.toFixed(3),
            ozPer10mg: ozPer10mg.toFixed(4),
            drinksPerOz: drinksPerOz.toFixed(1),
            costPerServing: costPerServing.toFixed(2)
        });
    }
}

document.querySelectorAll('[name="attribute_per_oz"], [name="size_oz"], [name="wholesale_price"]').forEach(input => {
    input.addEventListener('input', updateCalculations);
});

// NEW: Calculate total cannabinoids
function updateTotalCannabinoids() {
    const thca = parseFloat(document.querySelector('[name="thca_mg_per_unit"]')?.value) || 0;
    const thcD9 = parseFloat(document.querySelector('[name="thc_d9_mg_per_unit"]')?.value) || 0;
    const thcD8 = parseFloat(document.querySelector('[name="thc_d8_mg_per_unit"]')?.value) || 0;
    const product = parseFloat(document.querySelector('[name="cbd_mg_per_unit"]')?.value) || 0;
    const cbg = parseFloat(document.querySelector('[name="cbg_mg_per_unit"]')?.value) || 0;
    const cbn = parseFloat(document.querySelector('[name="cbn_mg_per_unit"]')?.value) || 0;
    
    const total = thca + thcD9 + thcD8 + product + cbg + cbn;
    const displayElem = document.getElementById('total-cannabinoids-display');
    const hiddenElem = document.getElementById('total_cannabinoids_mg');
    
    if (displayElem) displayElem.textContent = total.toFixed(2) + ' mg';
    if (hiddenElem) hiddenElem.value = total.toFixed(2);
}


// NEW: Toggle section visibility
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }
}

// NEW: Clear all cost overrides
function clearOverrides() {
    if (confirm('Clear all cost overrides and use group defaults?')) {
        document.querySelectorAll('[name^="override_"]').forEach(input => {
            input.value = '';
        });
    }
}

// WYSIWYG Description Editor Functions
let currentDescriptionMode = 'visual';

function switchDescriptionMode(mode) {
    const visualEditor = document.getElementById('description-visual');
    const codeEditor = document.getElementById('description-code');
    const visualBtn = document.getElementById('desc-visual-btn');
    const codeBtn = document.getElementById('desc-code-btn');
    const sourceField = document.getElementById('description-source');
    
    if (mode === 'visual') {
        // Switching TO visual - update visual from code
        if (currentDescriptionMode === 'code') {
            visualEditor.innerHTML = codeEditor.value;
            sourceField.value = codeEditor.value;
        }
        
        visualEditor.style.display = 'block';
        codeEditor.style.display = 'none';
        visualBtn.style.background = '#7c3aed';
        visualBtn.style.color = 'white';
        codeBtn.style.background = '';
        codeBtn.classList.add('secondary');
        currentDescriptionMode = 'visual';
    } else {
        // Switching TO code - update code from visual
        if (currentDescriptionMode === 'visual') {
            codeEditor.value = visualEditor.innerHTML;
            sourceField.value = visualEditor.innerHTML;
        }
        
        visualEditor.style.display = 'none';
        codeEditor.style.display = 'block';
        codeBtn.style.background = '#7c3aed';
        codeBtn.style.color = 'white';
        codeBtn.classList.remove('secondary');
        visualBtn.style.background = '';
        visualBtn.classList.add('secondary');
        currentDescriptionMode = 'code';
    }
}

function insertIntoDescription(shortcode) {
    const visualEditor = document.getElementById('description-visual');
    const codeEditor = document.getElementById('description-code');
    const sourceField = document.getElementById('description-source');
    
    if (currentDescriptionMode === 'visual') {
        // Insert at cursor in visual editor
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            const textNode = document.createTextNode(shortcode);
            range.deleteContents();
            range.insertNode(textNode);
        } else {
            visualEditor.innerHTML += shortcode;
        }
        sourceField.value = visualEditor.innerHTML;
    } else {
        // Insert in code editor at cursor
        const start = codeEditor.selectionStart;
        const end = codeEditor.selectionEnd;
        codeEditor.value = codeEditor.value.substring(0, start) + shortcode + codeEditor.value.substring(end);
        codeEditor.focus();
        codeEditor.setSelectionRange(start + shortcode.length, start + shortcode.length);
        sourceField.value = codeEditor.value;
    }
    
    // Show success toast
    const toast = document.createElement('div');
    toast.innerHTML = '✅ Shortcode inserted!';
    toast.style.cssText = 'position: fixed; top: 80px; right: 20px; background: #10b981; color: white; padding: 10px 16px; border-radius: 6px; z-index: 10000; font-size: 13px; font-weight: 600;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
}

// Sync content on input
document.getElementById('description-visual')?.addEventListener('input', function() {
    document.getElementById('description-source').value = this.innerHTML;
});

document.getElementById('description-code')?.addEventListener('input', function() {
    document.getElementById('description-source').value = this.value;
});

// Sync before form submit
const productForm = document.getElementById('product-form');
if (productForm) {
    const originalSubmitHandler = productForm.onsubmit;
    productForm.addEventListener('submit', function(e) {
        // Sync description content before submitting
        const visualEditor = document.getElementById('description-visual');
        const codeEditor = document.getElementById('description-code');
        const sourceField = document.getElementById('description-source');
        
        if (currentDescriptionMode === 'visual') {
            sourceField.value = visualEditor.innerHTML;
        } else {
            sourceField.value = codeEditor.value;
        }
        
        console.log('Description synced before submit:', sourceField.value.length + ' characters');
    }, {capture: true}); // Use capture to run before other handlers
}
</script>

<script>
//================================================================================
// PRODUCT TYPE & MEASUREMENTS - Unit Conversions and Field Toggling
//================================================================================

// Unit conversion constants
const CONVERSIONS = {
    // Weight conversions
    GRAMS_TO_OZ: 0.03527396195,
    OZ_TO_GRAMS: 28.3495,
    GRAMS_TO_KG: 0.001,
    KG_TO_GRAMS: 1000,
    GRAMS_TO_MG: 1000,
    MG_TO_GRAMS: 0.001,
    
    // Volume conversions
    ML_TO_FL_OZ: 0.033814022558919,
    FL_OZ_TO_ML: 29.5735,
    ML_TO_LITERS: 0.001,
    LITERS_TO_ML: 1000
};

// Convert weight between units
function convertWeight(fromUnit) {
    const grams = parseFloat(document.getElementById('weight_grams').value) || 0;
    const oz = parseFloat(document.getElementById('weight_oz').value) || 0;
    const kg = parseFloat(document.getElementById('weight_kg').value) || 0;
    const mg = parseFloat(document.getElementById('weight_mg').value) || 0;
    
    let baseGrams = 0;
    
    // Convert to grams (base unit)
    switch(fromUnit) {
        case 'grams':
            baseGrams = grams;
            break;
        case 'oz':
            baseGrams = oz * CONVERSIONS.OZ_TO_GRAMS;
            break;
        case 'kg':
            baseGrams = kg * CONVERSIONS.KG_TO_GRAMS;
            break;
        case 'mg':
            baseGrams = mg * CONVERSIONS.MG_TO_GRAMS;
            break;
    }
    
    // Update all other fields
    if (fromUnit !== 'grams') document.getElementById('weight_grams').value = baseGrams.toFixed(3);
    if (fromUnit !== 'oz') document.getElementById('weight_oz').value = (baseGrams * CONVERSIONS.GRAMS_TO_OZ).toFixed(4);
    if (fromUnit !== 'kg') document.getElementById('weight_kg').value = (baseGrams * CONVERSIONS.GRAMS_TO_KG).toFixed(4);
    if (fromUnit !== 'mg') document.getElementById('weight_mg').value = (baseGrams * CONVERSIONS.GRAMS_TO_MG).toFixed(3);
}

// Convert volume between units
function convertVolume(fromUnit) {
    const ml = parseFloat(document.getElementById('volume_ml').value) || 0;
    const fl_oz = parseFloat(document.getElementById('volume_fl_oz').value) || 0;
    const liters = parseFloat(document.getElementById('volume_liters').value) || 0;
    
    let baseMl = 0;
    
    // Convert to mL (base unit)
    switch(fromUnit) {
        case 'ml':
            baseMl = ml;
            break;
        case 'fl_oz':
            baseMl = fl_oz * CONVERSIONS.FL_OZ_TO_ML;
            break;
        case 'liters':
            baseMl = liters * CONVERSIONS.LITERS_TO_ML;
            break;
    }
    
    // Update all other fields
    if (fromUnit !== 'ml') document.getElementById('volume_ml').value = baseMl.toFixed(3);
    if (fromUnit !== 'fl_oz') document.getElementById('volume_fl_oz').value = (baseMl * CONVERSIONS.ML_TO_FL_OZ).toFixed(4);
    if (fromUnit !== 'liters') document.getElementById('volume_liters').value = (baseMl * CONVERSIONS.ML_TO_LITERS).toFixed(4);
}

// Toggle field visibility based on product type and liquid checkbox
function toggleProductTypeFields() {
    const productType = document.getElementById('product_type').value;
    const isLiquid = document.getElementById('is_liquid').checked;
    
    const weightFields = document.getElementById('weight_fields');
    const volumeFields = document.getElementById('volume_fields');
    const dropshipFields = document.getElementById('dropship_fields');
    const simpleCostField = document.getElementById('simple_cost_field');
    const manufacturingCostFields = document.getElementById('manufacturing_cost_fields');
    
    // Show/hide weight vs volume based on is_liquid checkbox
    weightFields.style.display = !isLiquid ? 'block' : 'none';
    volumeFields.style.display = isLiquid ? 'block' : 'none';
    
    // Show dropship fields for dropship products
    dropshipFields.style.display = (productType === 'dropship') ? 'block' : 'none';
    
    // Show simple cost field for simple/dropship products
    simpleCostField.style.display = (productType === 'simple' || productType === 'dropship') ? 'block' : 'none';
    
    // Hide manufacturing cost fields for simple/dropship products
    if (manufacturingCostFields) {
        manufacturingCostFields.style.display = (productType === 'simple' || productType === 'dropship') ? 'none' : 'block';
    }
    
    // Auto-update is_liquid checkbox based on product type
    if (productType === 'manufactured_liquid') {
        document.getElementById('is_liquid').checked = true;
        weightFields.style.display = 'none';
        volumeFields.style.display = 'block';
    } else if (productType === 'manufactured_solid') {
        document.getElementById('is_liquid').checked = false;
        weightFields.style.display = 'block';
        volumeFields.style.display = 'none';
    }
}

// Auto-update fields when is_liquid checkbox changes
document.getElementById('is_liquid')?.addEventListener('change', function() {
    toggleProductTypeFields();
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleProductTypeFields();
    
    console.log('✅ Product Type & Measurements JS initialized');
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

