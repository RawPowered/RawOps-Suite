<?php
$SEO = [
    'title' => 'Recipe Details — Operations',
    'description' => 'View product group recipe details.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();

$recipeId = intval($_GET['recipe_id'] ?? 0);

if ($recipeId <= 0) {
    flash_set('error', 'Invalid recipe ID');
    redirect('/operations/product-group-recipe.php');
}

// Load recipe
$stmt = $pdo->prepare("
    SELECT r.*, pg.name as group_name
    FROM product_group_recipes r
    JOIN product_groups pg ON pg.id = r.product_group_id
    WHERE r.id = ?
");
$stmt->execute([$recipeId]);
$recipe = $stmt->fetch();

if (!$recipe) {
    flash_set('error', 'Recipe not found');
    redirect('/operations/product-group-recipe.php');
}

$ingredientList = !empty($recipe['ingredient_list']) ? json_decode($recipe['ingredient_list'], true) : [];
$packagingList = !empty($recipe['packaging_list']) ? json_decode($recipe['packaging_list'], true) : [];
$materialList = !empty($recipe['material_list']) ? json_decode($recipe['material_list'], true) : [];

// Calculate costs
$totalIngredientCost = 0;
foreach ($ingredientList as $ing) {
    if (isset($ing['cost_per_unit']) && isset($ing['storage_quantity'])) {
        $totalIngredientCost += $ing['storage_quantity'] * $ing['cost_per_unit'];
    }
}

$totalPackagingCost = 0;
foreach ($packagingList as $pkg) {
    if (isset($pkg['cost_per_unit']) && isset($pkg['quantity'])) {
        $totalPackagingCost += $pkg['quantity'] * $pkg['cost_per_unit'];
    }
}

$totalRecipeCost = $totalIngredientCost + $totalPackagingCost;
$costPerUnit = $recipe['batch_yield'] > 0 ? ($totalRecipeCost / $recipe['batch_yield']) : 0;
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1><?php echo htmlspecialchars($recipe['recipe_name']); ?></h1>
                <p class="muted"><?php echo htmlspecialchars($recipe['group_name']); ?> — Version <?php echo htmlspecialchars($recipe['recipe_version']); ?></p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/product-group-recipe-editor.php?recipe_id=' . $recipe['id']); ?>" class="recipe-view-btn recipe-view-btn-edit">✏️ Edit Recipe</a>
                <a href="<?php echo path_with_base('/operations/product-group-recipe.php?group_id=' . $recipe['product_group_id']); ?>" class="recipe-view-btn recipe-view-btn-back">← Back to Recipes</a>
            </div>
        </div>

        <?php if ($recipe['active']): ?>
            <div style="background: #dcfce7; color: #14532d; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; display: inline-block;">
                ✅ Active Recipe
            </div>
        <?php else: ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; display: inline-block;">
                ⚠️ Inactive Recipe
            </div>
        <?php endif; ?>

        <!-- Recipe Overview -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>📊 Recipe Overview</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 16px;">
                <div>
                    <div style="color: #6b7280; font-size: 13px; margin-bottom: 4px;">Batch Yield</div>
                    <div style="font-size: 24px; font-weight: 700; color: #059669;"><?php echo number_format($recipe['batch_yield']); ?> units</div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 13px; margin-bottom: 4px;">Production Time</div>
                    <div style="font-size: 24px; font-weight: 700; color: #2563eb;"><?php echo $recipe['estimated_time_minutes']; ?> min</div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 13px; margin-bottom: 4px;">Cost per Unit</div>
                    <div style="font-size: 24px; font-weight: 700; color: #dc2626;">$<?php echo number_format($costPerUnit, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Cost Breakdown -->
        <div class="card" style="background: #fef3c7; border-color: #f59e0b; margin-bottom: 24px;">
            <h2 style="color: #92400e;">💰 Cost Breakdown</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 16px;">
                <div>
                    <div style="color: #78350f; font-size: 13px; margin-bottom: 4px;">Ingredient Cost</div>
                    <div style="font-size: 20px; font-weight: 700; color: #dc2626;">$<?php echo number_format($totalIngredientCost, 2); ?></div>
                </div>
                <div>
                    <div style="color: #78350f; font-size: 13px; margin-bottom: 4px;">Packaging Cost</div>
                    <div style="font-size: 20px; font-weight: 700; color: #dc2626;">$<?php echo number_format($totalPackagingCost, 2); ?></div>
                </div>
                <div>
                    <div style="color: #78350f; font-size: 13px; margin-bottom: 4px;">Total Recipe Cost</div>
                    <div style="font-size: 20px; font-weight: 700; color: #059669;">$<?php echo number_format($totalRecipeCost, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Ingredients -->
        <?php if (!empty($ingredientList)): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h2>🧪 Ingredients Required</h2>
            <div style="margin-top: 16px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Ingredient</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Quantity</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Stock Units</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredientList as $ing): ?>
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 12px;"><?php echo htmlspecialchars($ing['ingredient_name']); ?></td>
                            <td style="padding: 12px; text-align: right; font-weight: 600;">
                                <?php echo number_format($ing['quantity'], 3); ?> <?php echo htmlspecialchars($ing['unit']); ?>
                            </td>
                            <td style="padding: 12px; text-align: right; color: #6b7280; font-size: 13px;">
                                <?php if (isset($ing['storage_quantity']) && isset($ing['storage_unit'])): ?>
                                = <?php echo number_format($ing['storage_quantity'], 3); ?> <?php echo htmlspecialchars($ing['storage_unit']); ?>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: right; color: #059669; font-weight: 600;">
                                <?php if (isset($ing['cost_per_unit']) && isset($ing['storage_quantity'])): ?>
                                $<?php echo number_format($ing['storage_quantity'] * $ing['cost_per_unit'], 2); ?>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Packaging -->
        <?php if (!empty($packagingList)): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h2>📦 Packaging Required</h2>
            <div style="margin-top: 16px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Packaging Item</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Quantity</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packagingList as $pkg): ?>
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 12px;"><?php echo htmlspecialchars($pkg['packaging_name']); ?></td>
                            <td style="padding: 12px; text-align: right; font-weight: 600;">
                                <?php echo number_format($pkg['quantity']); ?> units
                            </td>
                            <td style="padding: 12px; text-align: right; color: #059669; font-weight: 600;">
                                <?php if (isset($pkg['cost_per_unit'])): ?>
                                $<?php echo number_format($pkg['quantity'] * $pkg['cost_per_unit'], 2); ?>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Equipment & Materials -->
        <?php if (!empty($materialList)): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h2>🔧 Equipment & Materials</h2>
            <div style="margin-top: 16px;">
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($materialList as $mat): ?>
                    <li style="padding: 12px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between;">
                        <span style="font-weight: 600;"><?php echo htmlspecialchars($mat['name']); ?></span>
                        <?php if (!empty($mat['notes'])): ?>
                        <span style="color: #6b7280; font-size: 13px;"><?php echo htmlspecialchars($mat['notes']); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- Production Procedures -->
        <?php if ($recipe['recipe_steps']): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h2>📋 Production Procedures</h2>
            <div style="margin-top: 16px; line-height: 1.6;">
                <?php echo $recipe['recipe_steps']; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recipe Metadata -->
        <div class="card" style="background: #f9fafb;">
            <h3 style="margin: 0 0 12px 0; color: #6b7280; font-size: 14px;">Recipe Metadata</h3>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; font-size: 13px;">
                <div>
                    <span style="color: #6b7280;">Created:</span>
                    <span style="font-weight: 600; margin-left: 8px;"><?php echo date('M j, Y g:i A', strtotime($recipe['created_at'])); ?></span>
                </div>
                <div>
                    <span style="color: #6b7280;">Last Updated:</span>
                    <span style="font-weight: 600; margin-left: 8px;"><?php echo date('M j, Y g:i A', strtotime($recipe['updated_at'])); ?></span>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.recipe-view-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.recipe-view-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.15);
}

.recipe-view-btn:active {
    transform: translateY(0);
}

.recipe-view-btn-edit {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: white;
}

.recipe-view-btn-edit:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
}

.recipe-view-btn-back {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    color: white;
}

.recipe-view-btn-back:hover {
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
}
</style>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

