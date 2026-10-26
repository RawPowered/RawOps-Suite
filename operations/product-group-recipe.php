<?php
$SEO = [
    'title' => 'Recipe Management — Operations',
    'description' => 'Manage product group recipes and formulations.',
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

$selectedGroupId = intval($_GET['group_id'] ?? 0);

// Handle recipe actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'delete_recipe') {
            $recipeId = intval($_POST['recipe_id'] ?? 0);
            if ($recipeId <= 0) throw new Exception('Invalid recipe ID');
            
            // Get product group ID before deleting
            $stmt = $pdo->prepare("SELECT product_group_id FROM product_group_recipes WHERE id = ?");
            $stmt->execute([$recipeId]);
            $groupId = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM product_group_recipes WHERE id = ?");
            $stmt->execute([$recipeId]);
            flash_set('success', 'Recipe deleted successfully');
            header('Location: ' . path_with_base('/operations/product-group-recipe.php?group_id=' . $groupId));
            exit;
        }
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        header('Location: ' . path_with_base('/operations/product-group-recipe.php?group_id=' . $selectedGroupId));
        exit;
    }
}

// Fetch product groups with recipe system enabled
$productGroups = $pdo->query("SELECT id, name FROM product_groups WHERE active = 1 AND has_recipe_system = 1 ORDER BY name")->fetchAll();

// Fetch recipes for selected group
$recipes = [];
if ($selectedGroupId > 0) {
    $stmt = $pdo->prepare("
        SELECT r.*, pg.name as group_name
        FROM product_group_recipes r
        JOIN product_groups pg ON pg.id = r.product_group_id
        WHERE r.product_group_id = ?
        ORDER BY r.active DESC, r.recipe_version DESC
    ");
    $stmt->execute([$selectedGroupId]);
    $recipes = $stmt->fetchAll();
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📋 Recipe Management</h1>
                <p class="muted">Manage product group recipes and formulations</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
            </div>
        </div>

        <?php
        $successMsg = flash_get('success');
        $errorMsg = flash_get('error');
        ?>
        <?php if ($successMsg): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <!-- Product Group Selector -->
        <div class="card" style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select Product Group</label>
            <select onchange="window.location.href='?group_id=' + this.value" 
                    style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                <option value="">Select a product group...</option>
                <?php foreach ($productGroups as $pg): ?>
                <option value="<?php echo $pg['id']; ?>" <?php echo $selectedGroupId == $pg['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pg['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($productGroups)): ?>
                <small style="color: #6b7280; display: block; margin-top: 8px;">
                    No product groups with recipe system enabled. Enable it in product group settings.
                </small>
            <?php endif; ?>
        </div>

        <?php if ($selectedGroupId > 0): ?>
        <!-- Create New Recipe Button -->
        <div style="margin-bottom: 24px; text-align: center;">
            <a href="<?php echo path_with_base('/operations/product-group-recipe-editor.php?group_id=' . $selectedGroupId); ?>" 
               class="btn" style="background: var(--green); color: white; padding: 16px 32px; font-size: 16px;">
                ✨ Create New Recipe
            </a>
        </div>

        <!-- Recipe List -->
        <div class="card">
            <h2>Recipes (<?php echo count($recipes); ?>)</h2>
            <?php if (empty($recipes)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No recipes yet.</p>
            <?php else: ?>
            <?php foreach ($recipes as $recipe): 
                $ingredientList = !empty($recipe['ingredient_list']) ? json_decode($recipe['ingredient_list'], true) : [];
                $packagingList = !empty($recipe['packaging_list']) ? json_decode($recipe['packaging_list'], true) : [];
                $materialList = !empty($recipe['material_list']) ? json_decode($recipe['material_list'], true) : [];
            ?>
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                    <div>
                        <h3 style="margin: 0 0 4px 0;"><?php echo htmlspecialchars($recipe['recipe_name']); ?></h3>
                        <div style="color: #6b7280; font-size: 13px;">
                            Version: <?php echo htmlspecialchars($recipe['recipe_version']); ?> | 
                            Yield: <?php echo $recipe['batch_yield']; ?> units | 
                            Time: <?php echo $recipe['estimated_time_minutes']; ?> min
                        </div>
                    </div>
                    <div>
                        <?php if ($recipe['active']): ?>
                            <span style="background: #dcfce7; color: #14532d; padding: 4px 12px; border-radius: 4px; font-size: 12px;">Active</span>
                        <?php else: ?>
                            <span style="background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 4px; font-size: 12px;">Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Ingredient List -->
                <?php if (!empty($ingredientList)): ?>
                <div style="background: #fef3c7; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                    <strong>🧪 Ingredients:</strong>
                    <div style="margin-top: 8px; font-size: 13px;">
                        <?php foreach ($ingredientList as $ing): ?>
                        <div style="padding: 4px 0;">
                            • <?php echo htmlspecialchars($ing['ingredient_name']); ?>: 
                            <strong><?php echo number_format($ing['quantity'], 3); ?> <?php echo htmlspecialchars($ing['unit']); ?></strong>
                            <?php if (isset($ing['storage_quantity']) && isset($ing['storage_unit'])): ?>
                            <span style="color: #6b7280; font-size: 12px; margin-left: 8px;">
                                (= <?php echo number_format($ing['storage_quantity'], 3); ?> <?php echo htmlspecialchars($ing['storage_unit']); ?> from stock)
                            </span>
                            <?php endif; ?>
                            <?php if (isset($ing['cost_per_unit']) && isset($ing['storage_quantity'])): ?>
                            <span style="color: #059669; margin-left: 8px;">($<?php echo number_format($ing['storage_quantity'] * $ing['cost_per_unit'], 2); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Packaging List -->
                <?php if (!empty($packagingList)): ?>
                <div style="background: #dbeafe; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                    <strong>📦 Packaging:</strong>
                    <div style="margin-top: 8px; font-size: 13px;">
                        <?php foreach ($packagingList as $pkg): ?>
                        <div style="padding: 4px 0;">
                            • <?php echo htmlspecialchars($pkg['packaging_name']); ?>: 
                            <strong><?php echo number_format($pkg['quantity']); ?> units</strong>
                            <?php if (isset($pkg['cost_per_unit'])): ?>
                            <span style="color: #059669; margin-left: 8px;">($<?php echo number_format($pkg['quantity'] * $pkg['cost_per_unit'], 2); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Equipment/Materials List -->
                <?php if (!empty($materialList)): ?>
                <div style="background: #f3e8ff; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                    <strong>🔧 Equipment & Materials:</strong>
                    <div style="margin-top: 8px; font-size: 13px;">
                        <?php foreach ($materialList as $equip): ?>
                        <div style="padding: 4px 0;">
                            • <?php echo htmlspecialchars($equip['name']); ?>
                            <?php if (!empty($equip['notes'])): ?>
                            <span style="color: #6b7280; margin-left: 8px;">(<?php echo htmlspecialchars($equip['notes']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo path_with_base('/operations/product-group-recipe-view.php?recipe_id=' . $recipe['id']); ?>" 
                       class="recipe-btn recipe-btn-view">👁️ View Details</a>
                    <button type="button" onclick="openQuickPreview(<?php echo $recipe['id']; ?>)" 
                            class="recipe-btn recipe-btn-preview">⚡ Quick Preview</button>
                    <a href="<?php echo path_with_base('/operations/product-group-recipe-editor.php?recipe_id=' . $recipe['id']); ?>" 
                       class="recipe-btn recipe-btn-edit">✏️ Edit</a>
                    <form method="post" style="display: inline;" onsubmit="return confirm('Delete this recipe?');">
                        <input type="hidden" name="action" value="delete_recipe">
                        <input type="hidden" name="recipe_id" value="<?php echo $recipe['id']; ?>">
                        <button type="submit" class="recipe-btn recipe-btn-delete">🗑️ Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.recipe-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.recipe-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.15);
}

.recipe-btn:active {
    transform: translateY(0);
}

.recipe-btn-view {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: white;
}

.recipe-btn-view:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
}

.recipe-btn-preview {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
}

.recipe-btn-preview:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
}

.recipe-btn-edit {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.recipe-btn-edit:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
}

.recipe-btn-delete {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    color: white;
}

.recipe-btn-delete:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
}
</style>

<!-- Quick Preview Modal -->
<div id="quick-preview-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 900px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="margin: 0;" id="preview-recipe-name">Recipe Preview</h2>
            <button type="button" onclick="closeQuickPreview()" style="background: #6b7280; color: white; border: none; border-radius: 4px; padding: 8px 16px; cursor: pointer;">✕ Close</button>
        </div>
        <div id="preview-content" style="line-height: 1.6;">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<script>
// Store recipe data for quick preview
const recipeData = <?php echo json_encode($recipes); ?>;

// Quick Preview Modal Functions
function openQuickPreview(recipeId) {
    const recipe = recipeData.find(r => r.id == recipeId);
    if (!recipe) {
        alert('Recipe not found');
        return;
    }
    
    document.getElementById('preview-recipe-name').textContent = recipe.recipe_name;
    
    let html = '<div style="margin-bottom: 16px;">';
    html += '<div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Version: ' + recipe.recipe_version + ' | Yield: ' + recipe.batch_yield + ' units | Time: ' + recipe.estimated_time_minutes + ' min</div>';
    html += '</div>';
    
    if (recipe.recipe_steps) {
        html += '<div style="border-top: 1px solid #e5e7eb; padding-top: 16px;">';
        html += '<h3 style="margin: 0 0 12px 0;">Production Procedures</h3>';
        html += recipe.recipe_steps;
        html += '</div>';
    } else {
        html += '<p style="color: #6b7280;">No production procedures defined.</p>';
    }
    
    document.getElementById('preview-content').innerHTML = html;
    document.getElementById('quick-preview-modal').style.display = 'flex';
}

function closeQuickPreview() {
    document.getElementById('quick-preview-modal').style.display = 'none';
}

// Close modal on outside click
document.getElementById('quick-preview-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeQuickPreview();
});

// ESC key to close modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeQuickPreview();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

