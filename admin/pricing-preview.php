<?php
$SEO = [
    'title' => 'Price Change Preview — Admin',
    'description' => 'Preview how pricing changes will affect all products',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();

// Get preview values from query string or current settings
$currentSettings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM pricing_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $currentSettings[$row['setting_key']] = floatval($row['setting_value']);
    }
} catch (Exception $e) {
    $currentSettings = [
        'ingredient_cost_per_gram' => 3.40,
        'attribute_percentage' => 62.0,
        'bottle_cost' => 2.50,
        'label_cost' => 2.50,
        'labor_cost' => 5.00,
        'profit_multiplier' => 5.0,
        'mix_cost' => 1.50
    ];
}

// Preview values (from form or current)
$previewIngredientCost = floatval($_GET['preview_ingredient_cost'] ?? $currentSettings['ingredient_cost_per_gram']);
$previewMitPercent = floatval($_GET['preview_attribute_percent'] ?? $currentSettings['attribute_percentage']);
$previewMultiplier = floatval($_GET['preview_multiplier'] ?? $currentSettings['profit_multiplier']);
$previewBottleCost = floatval($_GET['preview_bottle_cost'] ?? $currentSettings['bottle_cost']);
$previewLabelCost = floatval($_GET['preview_label_cost'] ?? $currentSettings['label_cost']);
$previewLaborCost = floatval($_GET['preview_labor_cost'] ?? $currentSettings['labor_cost']);
$previewMixCost = floatval($_GET['preview_mix_cost'] ?? $currentSettings['mix_cost']);

// Fetch current products
$products = [];
try {
    $stmt = $pdo->query("
        SELECT 
            id, sku, name, attribute_per_oz, size_oz, 
            wholesale_price AS current_wholesale,
            total_production_cost AS current_cost,
            material_cost AS current_material_cost
        FROM products 
        WHERE active = 1 AND attribute_per_oz IS NOT NULL AND size_oz IS NOT NULL
        ORDER BY attribute_per_oz ASC, size_oz ASC
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

// Calculate preview prices for each product
foreach ($products as &$product) {
    $attributePerOz = $product['attribute_per_oz'];
    $sizeOz = $product['size_oz'];
    $totalMg = $attributePerOz * $sizeOz;
    
    // Calculate new material cost
    // Formula: GRAMS NEEDED = TOTAL attribute / attribute PER GRAM
    // attribute per gram = 620 (which is attribute_percentage * 10, so 62% * 10 = 620mg/gram)
    $mitPerGram = $previewMitPercent * 10; // Convert 62% to 620mg/gram
    $gramsNeeded = $totalMg / $mitPerGram;
    $newMaterialCost = $gramsNeeded * $previewIngredientCost;
    
    // Calculate new total cost (Bottle/Label is COMBINED $2.50, not separate)
    // Formula: COST = COST attribute + BOTTLE/LABEL + LABOR
    // Note: Mix cost is NOT added separately - it's part of production overhead
    $bottleLabelCombined = 2.50; // Fixed combined cost from spreadsheet
    $newTotalCost = $newMaterialCost + $bottleLabelCombined + $previewLaborCost;
    
    // Calculate new wholesale price
    $newWholesale = $newTotalCost * $previewMultiplier;
    
    // Calculate changes
    $product['new_material_cost'] = $newMaterialCost;
    $product['new_total_cost'] = $newTotalCost;
    $product['new_wholesale'] = $newWholesale;
    $product['wholesale_change'] = $newWholesale - $product['current_wholesale'];
    $product['wholesale_change_percent'] = (($newWholesale - $product['current_wholesale']) / $product['current_wholesale']) * 100;
    
    // Calculate new bulk pricing
    $product['new_tier_1_5'] = $newWholesale * 1.00; // 0% discount
    $product['new_tier_6_10'] = $newWholesale * 0.92; // 8% discount
    $product['new_tier_15_plus'] = $newWholesale * 0.88; // 12% discount
}
unset($product);
?>

<style>
.preview-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.preview-header { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; padding: 30px; margin-bottom: 24px; border-radius: 12px; }
.changes-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); margin-bottom: 24px; }
.settings-comparison { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.setting-box { padding: 16px; border-radius: 8px; border: 2px solid #e5e7eb; }
.setting-box.current { background: #f9fafb; }
.setting-box.preview { background: #eff6ff; border-color: #3b82f6; }
.preview-table { width: 100%; border-collapse: collapse; }
.preview-table th { background: #f9fafb; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e5e7eb; }
.preview-table td { padding: 12px; border-bottom: 1px solid #f3f4f6; }
.change-arrow { font-size: 20px; margin: 0 8px; }
.increase { color: #dc2626; }
.decrease { color: #059669; }
.no-change { color: #6b7280; }
</style>

<div class="preview-container">
    <div class="preview-header">
        <h1 style="margin: 0 0 8px 0;">👁️ Price Change Preview</h1>
        <p style="margin: 0; opacity: 0.9;">Compare current vs. proposed pricing across all products</p>
    </div>
    
    <!-- Settings Comparison -->
    <div class="settings-comparison">
        <div class="setting-box current">
            <h3 style="margin: 0 0 16px 0; color: #6b7280;">Current Settings</h3>
            <div style="display: grid; gap: 8px; font-size: 14px;">
                <div><strong>Ingredient Cost:</strong> $<?php echo number_format($currentSettings['ingredient_cost_per_gram'], 2); ?>/g</div>
                <div><strong>attribute %:</strong> <?php echo number_format($currentSettings['attribute_percentage'], 1); ?>%</div>
                <div><strong>Profit Multiplier:</strong> <?php echo number_format($currentSettings['profit_multiplier'], 1); ?>x</div>
                <div><strong>Bottle Cost:</strong> $<?php echo number_format($currentSettings['bottle_cost'], 2); ?></div>
                <div><strong>Label Cost:</strong> $<?php echo number_format($currentSettings['label_cost'], 2); ?></div>
                <div><strong>Labor Cost:</strong> $<?php echo number_format($currentSettings['labor_cost'], 2); ?></div>
                <div><strong>Mix Cost:</strong> $<?php echo number_format($currentSettings['mix_cost'], 2); ?></div>
            </div>
        </div>
        
        <div class="setting-box preview">
            <h3 style="margin: 0 0 16px 0; color: #3b82f6;">Preview Settings</h3>
            <div style="display: grid; gap: 8px; font-size: 14px;">
                <div><strong>Ingredient Cost:</strong> $<?php echo number_format($previewIngredientCost, 2); ?>/g 
                    <?php if ($previewIngredientCost != $currentSettings['ingredient_cost_per_gram']): ?>
                        <span style="color: <?php echo $previewIngredientCost > $currentSettings['ingredient_cost_per_gram'] ? '#dc2626' : '#059669'; ?>">
                            (<?php echo $previewIngredientCost > $currentSettings['ingredient_cost_per_gram'] ? '↑' : '↓'; ?> 
                            <?php echo number_format(abs($previewIngredientCost - $currentSettings['ingredient_cost_per_gram']), 2); ?>)
                        </span>
                    <?php endif; ?>
                </div>
                <div><strong>attribute %:</strong> <?php echo number_format($previewMitPercent, 1); ?>%
                    <?php if ($previewMitPercent != $currentSettings['attribute_percentage']): ?>
                        <span style="color: <?php echo $previewMitPercent > $currentSettings['attribute_percentage'] ? '#059669' : '#dc2626'; ?>">
                            (<?php echo $previewMitPercent > $currentSettings['attribute_percentage'] ? '↑' : '↓'; ?> 
                            <?php echo number_format(abs($previewMitPercent - $currentSettings['attribute_percentage']), 1); ?>)
                        </span>
                    <?php endif; ?>
                </div>
                <div><strong>Profit Multiplier:</strong> <?php echo number_format($previewMultiplier, 1); ?>x</div>
                <div><strong>Bottle Cost:</strong> $<?php echo number_format($previewBottleCost, 2); ?></div>
                <div><strong>Label Cost:</strong> $<?php echo number_format($previewLabelCost, 2); ?></div>
                <div><strong>Labor Cost:</strong> $<?php echo number_format($previewLaborCost, 2); ?></div>
                <div><strong>Mix Cost:</strong> $<?php echo number_format($previewMixCost, 2); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Product Price Changes -->
    <div class="changes-card">
        <h2 style="margin-bottom: 20px;">📊 Product-by-Product Impact</h2>
        
        <div style="overflow-x: auto;">
            <table class="preview-table" style="min-width: 1200px;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Current Cost</th>
                        <th>New Cost</th>
                        <th>Current Wholesale</th>
                        <th>New Wholesale</th>
                        <th>Change</th>
                        <th>New Tier 1-5</th>
                        <th>New Tier 6-10</th>
                        <th>New Tier 15+</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): 
                        $changeClass = $product['wholesale_change'] > 0 ? 'increase' : ($product['wholesale_change'] < 0 ? 'decrease' : 'no-change');
                        $arrow = $product['wholesale_change'] > 0 ? '↑' : ($product['wholesale_change'] < 0 ? '↓' : '→');
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            <br><small style="color: #6b7280;"><?php echo $product['sku']; ?></small>
                            <br><small style="color: #6b7280;"><?php echo number_format($product['attribute_per_oz'], 0); ?>mg/oz × <?php echo number_format($product['size_oz'], 0); ?>oz</small>
                        </td>
                        <td>
                            <div style="font-size: 12px; color: #6b7280;">Material: $<?php echo number_format($product['current_material_cost'], 2); ?></div>
                            <div style="font-weight: 600;">Total: $<?php echo number_format($product['current_cost'], 2); ?></div>
                        </td>
                        <td>
                            <div style="font-size: 12px; color: #6b7280;">Material: $<?php echo number_format($product['new_material_cost'], 2); ?></div>
                            <div style="font-weight: 600;">Total: $<?php echo number_format($product['new_total_cost'], 2); ?></div>
                        </td>
                        <td style="font-weight: 600; font-size: 16px;">$<?php echo number_format($product['current_wholesale'], 2); ?></td>
                        <td style="font-weight: 600; font-size: 16px; color: #3b82f6;">$<?php echo number_format($product['new_wholesale'], 2); ?></td>
                        <td>
                            <div class="<?php echo $changeClass; ?>" style="font-weight: 600;">
                                <?php echo $arrow; ?> $<?php echo number_format(abs($product['wholesale_change']), 2); ?>
                            </div>
                            <div class="<?php echo $changeClass; ?>" style="font-size: 12px;">
                                (<?php echo number_format($product['wholesale_change_percent'], 1); ?>%)
                            </div>
                        </td>
                        <td style="background: #f0fdf4;">$<?php echo number_format($product['new_tier_1_5'], 2); ?></td>
                        <td style="background: #fef3c7;">$<?php echo number_format($product['new_tier_6_10'], 2); ?></td>
                        <td style="background: #fee2e2;">$<?php echo number_format($product['new_tier_15_plus'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Summary -->
    <div class="changes-card" style="background: #f0fdf4; border: 2px solid #059669;">
        <h3 style="margin-bottom: 16px; color: #065f46;">Summary</h3>
        <?php
        $increases = array_filter($products, fn($p) => $p['wholesale_change'] > 0);
        $decreases = array_filter($products, fn($p) => $p['wholesale_change'] < 0);
        $unchanged = array_filter($products, fn($p) => $p['wholesale_change'] == 0);
        $avgChange = count($products) > 0 ? array_sum(array_column($products, 'wholesale_change')) / count($products) : 0;
        ?>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
            <div>
                <div style="font-size: 28px; font-weight: bold; color: #dc2626;"><?php echo count($increases); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Price Increases</div>
            </div>
            <div>
                <div style="font-size: 28px; font-weight: bold; color: #059669;"><?php echo count($decreases); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Price Decreases</div>
            </div>
            <div>
                <div style="font-size: 28px; font-weight: bold; color: #6b7280;"><?php echo count($unchanged); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Unchanged</div>
            </div>
            <div>
                <div style="font-size: 28px; font-weight: bold; color: #3b82f6;">$<?php echo number_format(abs($avgChange), 2); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Avg Change</div>
            </div>
        </div>
    </div>
    
    <div style="text-align: center; margin: 32px 0;">
        <button onclick="window.close()" style="padding: 14px 32px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer;">
            Close Preview
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

