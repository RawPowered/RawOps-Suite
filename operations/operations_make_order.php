<?php
$SEO = [
    'title' => 'Create Work Order — Operations',
    'description' => 'Create manufacturing work orders with batch code tracking.',
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
$message = '';
$error = '';

// Handle batch conflict resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_conflict'])) {
    $resolution = $_POST['conflict_resolution'] ?? '';
    $conflictData = $_SESSION['batch_conflict'] ?? null;
    
    if (!$conflictData) {
        $error = 'Conflict data not found';
    } else {
        try {
            if ($resolution === 'update') {
                // Update existing work order with new quantity
                $stmt = $pdo->prepare("
                    SELECT wo.id, wo.quantity_to_produce FROM work_orders wo
                    JOIN batch_codes bc ON bc.work_order_id = wo.id
                    WHERE bc.id = ?
                ");
                $stmt->execute([$conflictData['existing_batch_id']]);
                $existingWo = $stmt->fetch();
                
                if ($existingWo) {
                    $newTotal = $existingWo['quantity_to_produce'] + $conflictData['quantity_to_produce'];
                    $stmt = $pdo->prepare("UPDATE work_orders SET quantity_to_produce = ?, notes = CONCAT(notes, '\n\nUPDATED: Added ', ?, ' units on ', NOW()), updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$newTotal, $conflictData['quantity_to_produce'], $existingWo['id']]);
                    
                    flash_set('success', "Work order updated! Added {$conflictData['quantity_to_produce']} units. New total: {$newTotal} units. ⚠️ Review manufacturing adjustments.");
                }
                
            } elseif ($resolution === 'add') {
                // Add to production - same work order
                $stmt = $pdo->prepare("
                    SELECT wo.id FROM work_orders wo
                    JOIN batch_codes bc ON bc.work_order_id = wo.id
                    WHERE bc.id = ?
                ");
                $stmt->execute([$conflictData['existing_batch_id']]);
                $existingWo = $stmt->fetch();
                
                if ($existingWo) {
                    $stmt = $pdo->prepare("UPDATE work_orders SET quantity_to_produce = quantity_to_produce + ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$conflictData['quantity_to_produce'], $existingWo['id']]);
                    
                    flash_set('success', "Added {$conflictData['quantity_to_produce']} units to existing work order.");
                }
                
            } elseif ($resolution === 'increment') {
                // Create new work order with incremented batch code
                $baseCode = $conflictData['base_batch_code'];
                $increment = 1;
                
                // Find next available increment
                while (true) {
                    $newBatchCode = $baseCode . '-' . str_pad($increment, 3, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("SELECT id FROM batch_codes WHERE batch_code = ?");
                    $stmt->execute([$newBatchCode]);
                    if (!$stmt->fetch()) break;
                    $increment++;
                }
                
                // Create work order with incremented batch code
                $_SESSION['batch_conflict']['batch_code'] = $newBatchCode;
                $_SESSION['batch_conflict']['skip_conflict_check'] = true;
                
                // Redirect back to create with skip flag
                header('Location: ' . path_with_base('/operations/operations_make_order.php?create_with_increment=1'));
                exit;
                
            } elseif ($resolution === 'cancel') {
                // Cancel - just clear conflict and return
                unset($_SESSION['batch_conflict']);
                flash_set('error', 'Work order creation cancelled');
                header('Location: ' . path_with_base('/operations/operations_make_order.php'));
                exit;
            }
            
            unset($_SESSION['batch_conflict']);
            header('Location: ' . path_with_base('/operations/operations_make_order.php'));
            exit;
            
        } catch (Throwable $e) {
            $error = 'Error resolving conflict: ' . $e->getMessage();
        }
    }
}

// Handle incremented batch code creation
if (isset($_GET['create_with_increment']) && isset($_SESSION['batch_conflict'])) {
    $conflictData = $_SESSION['batch_conflict'];
    unset($_SESSION['batch_conflict']);
    
    // Create work order with incremented batch code (bypassing conflict check)
    try {
        $batchCode = $conflictData['batch_code'];
        $woPrefix = 'WO-' . date('Ymd') . '-';
        $stmt = $pdo->query("SELECT COUNT(*) FROM work_orders WHERE wo_number LIKE '{$woPrefix}%'");
        $count = $stmt->fetchColumn() + 1;
        $woNumber = $woPrefix . str_pad($count, 3, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO work_orders 
            (wo_number, product_group_id, batch_code, batch_type, status, quantity_to_produce, 
             production_date, manufacturer_id, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $woNumber, $conflictData['product_group_id'], $batchCode, $conflictData['batch_type'],
            $conflictData['quantity_to_produce'], $conflictData['production_date'], 
            $conflictData['manufacturer_id'], $conflictData['notes'], $userId
        ]);
        
        $workOrderId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("
            INSERT INTO batch_codes
            (batch_code, product_group_id, work_order_id, batch_type, sku_base, production_date, 
             quantity_produced, active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())
        ");
        $stmt->execute([
            $batchCode, $conflictData['product_group_id'], $workOrderId, $conflictData['batch_type'], 
            $conflictData['sku_base'], $conflictData['production_date']
        ]);
        
        flash_set('success', "Work order {$woNumber} created with incremented batch code {$batchCode}");
        header('Location: ' . path_with_base("/operations/operations_make_order.php?id={$workOrderId}"));
        exit;
        
    } catch (Throwable $e) {
        $error = 'Error creating work order: ' . $e->getMessage();
    }
}

// Handle work order deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_work_order'])) {
    try {
        $woId = intval($_POST['work_order_id'] ?? 0);
        $deleteReason = trim($_POST['delete_reason'] ?? '');
        $customReason = trim($_POST['custom_delete_reason'] ?? '');
        
        if ($woId <= 0) throw new Exception('Invalid work order ID');
        if ($deleteReason === '' && $customReason === '') throw new Exception('Deletion reason required');
        
        $finalReason = $deleteReason === 'custom' ? $customReason : $deleteReason;
        
        // Delete associated batch code first
        $stmt = $pdo->prepare("DELETE FROM batch_codes WHERE work_order_id = ?");
        $stmt->execute([$woId]);
        
        // Delete work order
        $stmt = $pdo->prepare("DELETE FROM work_orders WHERE id = ?");
        $stmt->execute([$woId]);
        
        flash_set('success', "Work order deleted successfully. Reason: {$finalReason}");
        header('Location: ' . path_with_base('/operations/operations_make_order.php'));
        exit;
        
    } catch (Throwable $e) {
        $error = 'Error deleting work order: ' . $e->getMessage();
    }
}

// Handle work order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_work_order'])) {
    try {
        $productGroupId = intval($_POST['product_group_id'] ?? 0);
        $productId = !empty($_POST['product_id']) ? intval($_POST['product_id']) : null;
        $batchType = $_POST['batch_type'] ?? 'production';
        $quantityToProduce = intval($_POST['quantity_to_produce'] ?? 0);
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $manufacturerId = !empty($_POST['manufacturer_id']) ? intval($_POST['manufacturer_id']) : null;
        $skuBase = trim($_POST['sku_base'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Timeline fields
        $scheduledStartDate = !empty($_POST['scheduled_start_date']) ? $_POST['scheduled_start_date'] : null;
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        
        // Team member assignments
        $teamMemberIds = $_POST['team_member_ids'] ?? [];
        
        if ($productGroupId <= 0 || $quantityToProduce <= 0 || $skuBase === '') {
            throw new Exception('Product group, quantity, and SKU base are required');
        }
        
        // Get product details if selected
        $productDetails = null;
        if ($productId) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $productDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Generate batch code: SKU-{P|T|RDT}-MMDDYYYY
        $batchPrefix = match($batchType) {
            'production' => 'P',
            'testing' => 'T',
            'rnd' => 'RDT',
            default => 'P'
        };
        $dateStr = date('mdY', strtotime($productionDate));
        $baseBatchCode = "{$skuBase}-{$batchPrefix}-{$dateStr}";
        
        // Check for duplicate batch code
        $stmt = $pdo->prepare("SELECT id FROM batch_codes WHERE batch_code = ?");
        $stmt->execute([$baseBatchCode]);
        $existingBatch = $stmt->fetch();
        
        if ($existingBatch) {
            // Batch code exists - need to handle conflict via session
            $_SESSION['batch_conflict'] = [
                'product_group_id' => $productGroupId,
                'product_id' => $productId,
                'batch_type' => $batchType,
                'quantity_to_produce' => $quantityToProduce,
                'production_date' => $productionDate,
                'manufacturer_id' => $manufacturerId,
                'sku_base' => $skuBase,
                'notes' => $notes,
                'base_batch_code' => $baseBatchCode,
                'existing_batch_id' => $existingBatch['id']
            ];
            
            // Redirect to conflict resolution
            header('Location: ' . path_with_base('/operations/operations_make_order.php?conflict=1'));
            exit;
        }
        
        $batchCode = $baseBatchCode;
        
        // Generate work order number: WO-YYYYMMDD-XXX
        $woPrefix = 'WO-' . date('Ymd') . '-';
        $stmt = $pdo->query("SELECT COUNT(*) FROM work_orders WHERE wo_number LIKE '{$woPrefix}%'");
        $count = $stmt->fetchColumn() + 1;
        $woNumber = $woPrefix . str_pad($count, 3, '0', STR_PAD_LEFT);
        
        // Enhance notes with product details
        $enhancedNotes = $notes;
        if ($productDetails) {
            $enhancedNotes = "Product: " . $productDetails['name'] . " (SKU: {$productDetails['sku']})\n";
            if ($productDetails['attribute_per_oz']) {
                $enhancedNotes .= "attribute Content: {$productDetails['attribute_per_oz']}mg/oz × {$productDetails['size_oz']}oz = {$productDetails['total_mg']}mg total\n";
            }
            if ($notes) {
                $enhancedNotes .= "\nNotes: " . $notes;
            }
        }
        
        // Calculate projected costs from recipe (if available)
        $projectedIngredientCost = 0;
        $projectedPackagingCost = 0;
        $projectedMaterialCost = 0;
        $projectedTotalCost = 0;
        
        $stmtRecipe = $pdo->prepare("
            SELECT ingredient_list, packaging_list, material_list 
            FROM product_group_recipes 
            WHERE product_group_id = ? AND active = 1 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtRecipe->execute([$productGroupId]);
        $recipe = $stmtRecipe->fetch(PDO::FETCH_ASSOC);
        
        if ($recipe) {
            // Calculate ingredient costs
            if (!empty($recipe['ingredient_list'])) {
                $ingredients = json_decode($recipe['ingredient_list'], true);
                foreach ($ingredients as $ing) {
                    if (isset($ing['storage_quantity']) && isset($ing['cost_per_unit'])) {
                        $projectedIngredientCost += $ing['storage_quantity'] * $ing['cost_per_unit'];
                    }
                }
            }
            
            // Calculate packaging costs
            if (!empty($recipe['packaging_list'])) {
                $packaging = json_decode($recipe['packaging_list'], true);
                foreach ($packaging as $pkg) {
                    if (isset($pkg['quantity']) && isset($pkg['cost_per_unit'])) {
                        $projectedPackagingCost += $pkg['quantity'] * $pkg['cost_per_unit'];
                    }
                }
            }
            
            // Calculate material costs (currently stored as equipment in material_list)
            if (!empty($recipe['material_list'])) {
                $materials = json_decode($recipe['material_list'], true);
                // Materials in recipe may not have costs yet, skip for now
            }
            
            $projectedTotalCost = $projectedIngredientCost + $projectedPackagingCost + $projectedMaterialCost;
        }
        
        // Create work order
        $stmt = $pdo->prepare("
            INSERT INTO work_orders 
            (wo_number, product_group_id, batch_code, batch_type, status, quantity_to_produce, 
             production_date, manufacturer_id, notes, created_by, created_at,
             scheduled_start_date, due_date,
             projected_ingredient_cost, projected_packaging_cost, projected_material_cost, projected_total_cost)
            VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $woNumber, $productGroupId, $batchCode, $batchType, 
            $quantityToProduce, $productionDate, $manufacturerId, $enhancedNotes, $userId,
            $scheduledStartDate, $dueDate,
            $projectedIngredientCost, $projectedPackagingCost, $projectedMaterialCost, $projectedTotalCost
        ]);
        
        $workOrderId = $pdo->lastInsertId();
        
        // Assign team members to work order
        if (!empty($teamMemberIds) && is_array($teamMemberIds)) {
            $stmtTeam = $pdo->prepare("
                INSERT INTO work_order_team_assignments (work_order_id, team_member_id, assigned_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE assigned_at = NOW()
            ");
            foreach ($teamMemberIds as $teamMemberId) {
                $teamMemberId = intval($teamMemberId);
                if ($teamMemberId > 0) {
                    $stmtTeam->execute([$workOrderId, $teamMemberId]);
                }
            }
        }
        
        // Create batch code entry
        $stmt = $pdo->prepare("
            INSERT INTO batch_codes
            (batch_code, product_group_id, work_order_id, batch_type, sku_base, production_date, 
             quantity_produced, active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())
        ");
        $stmt->execute([
            $batchCode, $productGroupId, $workOrderId, $batchType, $skuBase, $productionDate
        ]);
        
        $teamCount = count($teamMemberIds ?? []);
        $teamMsg = $teamCount > 0 ? " with {$teamCount} team member(s) assigned" : "";
        $message = "Work order {$woNumber} created successfully with batch code {$batchCode}{$teamMsg}";
        
        // Redirect to work order detail with flash message
        flash_set('success', $message);
        header('Location: ' . path_with_base("/operations/operations_make_order.php?id={$workOrderId}"));
        exit;
        
    } catch (Throwable $e) {
        $error = 'Error creating work order: ' . $e->getMessage();
    }
}

// Fetch product groups with recipe system enabled
$productGroups = $pdo->query("
    SELECT id, name, group_key, has_recipe_system, product_category, has_product, has_cannabinoids
    FROM product_groups 
    WHERE active = 1 
    ORDER BY name ASC
")->fetchAll();

// Fetch production team members (active only)
$teamMembers = $pdo->query("
    SELECT id, full_name, role_title 
    FROM production_team_members 
    WHERE active = 1 
    ORDER BY full_name
")->fetchAll();

// Fetch manufacturers (users with operations role)
$manufacturers = $pdo->query("
    SELECT DISTINCT u.id, u.first_name, u.last_name
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.name = 'operations'
    ORDER BY u.first_name, u.last_name
")->fetchAll();

// If viewing existing work order
$workOrder = null;
$workOrderId = intval($_GET['id'] ?? 0);
if ($workOrderId > 0) {
    $stmt = $pdo->prepare("
        SELECT wo.*, pg.name as group_name, pg.has_recipe_system,
               u.first_name, u.last_name
        FROM work_orders wo
        JOIN product_groups pg ON pg.id = wo.product_group_id
        LEFT JOIN users u ON u.id = wo.manufacturer_id
        WHERE wo.id = ?
    ");
    $stmt->execute([$workOrderId]);
    $workOrder = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>🏭 <?php echo $workOrder ? 'Work Order: ' . htmlspecialchars($workOrder['wo_number']) : 'Create Work Order'; ?></h1>
                <p class="muted">Manufacturing work order with batch code tracking</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
            </div>
        </div>

        <?php 
        $flashSuccess = flash_get('success');
        $flashError = flash_get('error');
        ?>
        
        <?php if ($message || $flashSuccess): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($message ?: $flashSuccess); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error || $flashError): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($error ?: $flashError); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['conflict']) && isset($_SESSION['batch_conflict'])): ?>
        <!-- Batch Code Conflict Resolution -->
        <?php $conflict = $_SESSION['batch_conflict']; ?>
        <div class="card" style="background: #fef3c7; border: 3px solid #f59e0b; margin-bottom: 24px;">
            <h2 style="color: #92400e;">⚠️ Batch Code Already Exists</h2>
            <p style="color: #78350f; margin-bottom: 16px;">
                The batch code <code style="background: #fed7aa; padding: 4px 8px; border-radius: 4px; font-weight: 600;"><?php echo htmlspecialchars($conflict['base_batch_code']); ?></code> already exists for this date and product.
            </p>
            
            <form method="post">
                <input type="hidden" name="resolve_conflict" value="1">
                
                <div style="background: white; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0;">Choose an action:</h4>
                    
                    <label style="display: block; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; cursor: pointer;">
                        <input type="radio" name="conflict_resolution" value="update" required>
                        <strong>Update Existing Work Order</strong>
                        <div style="font-size: 13px; color: #6b7280; margin-top: 4px; margin-left: 24px;">
                            Add <?php echo number_format($conflict['quantity_to_produce']); ?> units to the existing work order. This will alert all users to review manufacturing.
                        </div>
                    </label>
                    
                    <label style="display: block; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; cursor: pointer;">
                        <input type="radio" name="conflict_resolution" value="add" required>
                        <strong>Add to Production</strong>
                        <div style="font-size: 13px; color: #6b7280; margin-top: 4px; margin-left: 24px;">
                            Increase production quantity on existing work order without creating new entry.
                        </div>
                    </label>
                    
                    <label style="display: block; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; cursor: pointer;">
                        <input type="radio" name="conflict_resolution" value="increment" required>
                        <strong>Create New Work Order with Incremented Batch Code</strong>
                        <div style="font-size: 13px; color: #6b7280; margin-top: 4px; margin-left: 24px;">
                            Create separate work order with batch code: <code><?php echo htmlspecialchars($conflict['base_batch_code']); ?>-001</code>
                        </div>
                    </label>
                    
                    <label style="display: block; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer;">
                        <input type="radio" name="conflict_resolution" value="cancel" required>
                        <strong>Cancel This Work Order</strong>
                        <div style="font-size: 13px; color: #6b7280; margin-top: 4px; margin-left: 24px;">
                            Discard and return to create form.
                        </div>
                    </label>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <a href="<?php echo path_with_base('/operations/operations_make_order.php'); ?>" class="btn secondary">Cancel</a>
                    <button type="submit" class="btn" style="background: #f59e0b; color: white;">Resolve Conflict</button>
                </div>
            </form>
        </div>
        
        <?php elseif (!$workOrder): ?>
        <!-- Create New Work Order Form -->
        <div class="card">
            <h2>Create New Work Order</h2>
            <form method="post">
                <input type="hidden" name="create_work_order" value="1">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Product Group *</label>
                        <select name="product_group_id" id="product-group-select" required onchange="loadGroupProducts()" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select product group...</option>
                            <?php foreach ($productGroups as $pg): ?>
                            <option value="<?php echo $pg['id']; ?>">
                                <?php echo htmlspecialchars($pg['name']); ?> 
                                <?php if ($pg['has_recipe_system']): ?> [Recipe Enabled]<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Batch Type *</label>
                        <select name="batch_type" id="batch-type-select" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="production">Production (SKU-P-MMDDYYYY)</option>
                            <option value="testing">Testing (SKU-T-MMDDYYYY)</option>
                            <option value="rnd">R&D (SKU-RDT-MMDDYYYY)</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Product (Optional)</label>
                        <select name="product_id" id="product-select" onchange="fillProductDetails()" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select product (or enter SKU manually)...</option>
                        </select>
                        <small style="color: #6b7280;">Selecting a product auto-fills SKU and details</small>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">SKU Base *</label>
                        <input type="text" name="sku_base" id="sku-base-input" required placeholder="e.g., BLUE-10-32" 
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <small style="color: #6b7280;">Auto-filled from product selection</small>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Production Date *</label>
                        <input type="date" name="production_date" id="production-date" required value="<?php echo date('Y-m-d'); ?>"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Scheduled Start Date</label>
                        <input type="datetime-local" name="scheduled_start_date" 
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <small style="color: #6b7280;">When production should start</small>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Due Date</label>
                        <input type="datetime-local" name="due_date" 
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <small style="color: #6b7280;">When work order should be completed</small>
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Quantity to Produce *</label>
                    <input type="number" name="quantity_to_produce" min="1" required value="100"
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Production Team Members</label>
                    <select name="team_member_ids[]" multiple size="5"
                            style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <?php foreach ($teamMembers as $member): ?>
                        <option value="<?php echo $member['id']; ?>">
                            <?php echo htmlspecialchars($member['full_name']); ?>
                            <?php if ($member['role_title']): ?>
                            - <?php echo htmlspecialchars($member['role_title']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #6b7280;">Hold Ctrl (or Cmd) to select multiple team members | <a href="<?php echo path_with_base('/operations/production-team-members.php'); ?>" target="_blank">Manage Team Members</a></small>
                </div>
                
                <!-- Product Details Preview -->
                <div id="product-details-preview" style="display: none; background: #f0fdf4; padding: 16px; border-radius: 8px; margin-bottom: 16px; border-left: 4px solid #059669;">
                    <strong>Product Details:</strong>
                    <div id="product-details-content" style="margin-top: 8px; font-size: 13px; color: #14532d;"></div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Assign Manufacturer (Optional)</label>
                        <select name="manufacturer_id" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Unassigned</option>
                            <?php foreach ($manufacturers as $mfg): ?>
                            <option value="<?php echo $mfg['id']; ?>">
                                <?php echo htmlspecialchars($mfg['first_name'] . ' ' . $mfg['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="3" placeholder="Optional production notes..."
                              style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
                
                <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                    <strong>Batch Code Preview:</strong>
                    <div id="batch-code-preview" style="margin-top: 8px; font-family: monospace; font-size: 18px; color: #059669;">
                        Will be generated based on selections
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Create Work Order</button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <!-- Work Order Details -->
        <div class="card">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                <div>
                    <h3 style="margin: 0 0 16px 0;">Work Order Details</h3>
                    <div style="margin-bottom: 12px;">
                        <strong>WO Number:</strong> <?php echo htmlspecialchars($workOrder['wo_number']); ?>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <strong>Batch Code:</strong> 
                        <code style="background: #f9fafb; padding: 4px 8px; border-radius: 4px;">
                            <?php echo htmlspecialchars($workOrder['batch_code']); ?>
                        </code>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <strong>Product Group:</strong> <?php echo htmlspecialchars($workOrder['group_name']); ?>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <strong>Status:</strong> 
                        <span style="padding: 4px 12px; background: #dbeafe; color: #1e40af; border-radius: 4px; text-transform: uppercase;">
                            <?php echo htmlspecialchars($workOrder['status']); ?>
                        </span>
                    </div>
                </div>
                
                <div>
                    <h3 style="margin: 0 0 16px 0;">Production Details</h3>
                    <div style="margin-bottom: 12px;">
                        <strong>Quantity to Produce:</strong> <?php echo number_format($workOrder['quantity_to_produce']); ?> units
                    </div>
                    <div style="margin-bottom: 12px;">
                        <strong>Quantity Produced:</strong> <?php echo number_format($workOrder['quantity_produced']); ?> units
                    </div>
                    <div style="margin-bottom: 12px;">
                        <strong>Production Date:</strong> <?php echo $workOrder['production_date'] ? date('M d, Y', strtotime($workOrder['production_date'])) : 'Not set'; ?>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <strong>Manufacturer:</strong> 
                        <?php echo $workOrder['first_name'] ? htmlspecialchars($workOrder['first_name'] . ' ' . $workOrder['last_name']) : 'Unassigned'; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($workOrder['notes']): ?>
            <div style="background: #f9fafb; padding: 12px; border-radius: 8px; margin-top: 16px;">
                <strong>Notes:</strong>
                <p style="margin: 8px 0 0 0;"><?php echo nl2br(htmlspecialchars($workOrder['notes'])); ?></p>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 24px; display: flex; gap: 12px; justify-content: space-between;">
                <div style="display: flex; gap: 12px;">
                    <a href="<?php echo path_with_base('/operations/product-group-coa-batch-manager.php?batch=' . urlencode($workOrder['batch_code'])); ?>" 
                       class="btn secondary">View Batch Details</a>
                    <a href="<?php echo path_with_base('/operations/product-group-labor.php?wo=' . $workOrder['id']); ?>" 
                       class="btn secondary">Track Labor</a>
                </div>
                
                <?php if ($workOrder['status'] !== 'completed'): ?>
                <button type="button" onclick="openDeleteModal(<?php echo $workOrder['id']; ?>, '<?php echo htmlspecialchars($workOrder['wo_number'], ENT_QUOTES); ?>')" 
                        class="btn" style="background: #dc2626; color: white;">
                    🗑️ Delete Work Order
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Delete Work Order Modal -->
<div id="delete-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%;">
        <h3 style="margin: 0 0 16px 0; color: #dc2626;">🗑️ Delete Work Order</h3>
        <p style="color: #6b7280; margin-bottom: 16px;">
            This will permanently delete work order <strong id="modal-wo-number"></strong> and its associated batch code.
        </p>
        
        <form method="post" id="delete-form">
            <input type="hidden" name="delete_work_order" value="1">
            <input type="hidden" name="work_order_id" id="modal-wo-id">
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Reason for Deletion *</label>
                <select name="delete_reason" id="delete-reason-select" required onchange="toggleCustomReason()"
                        style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px;">
                    <option value="">Select reason...</option>
                    <option value="testing">Testing (not a real work order)</option>
                    <option value="order_cancelled">Order Cancelled</option>
                    <option value="make_not_needed">Make Not Needed</option>
                    <option value="inventory_inaccurate">Inventory Inaccurate - Need to Reorder</option>
                    <option value="custom">Custom Reason...</option>
                </select>
            </div>
            
            <div id="custom-reason-field" style="display: none; margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Custom Reason *</label>
                <input type="text" name="custom_delete_reason" id="custom-delete-reason" 
                       placeholder="Enter custom reason"
                       style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px;">
            </div>
            
            <div style="background: #fee2e2; padding: 12px; border-radius: 6px; margin-bottom: 16px; border-left: 4px solid #dc2626;">
                <strong style="color: #991b1b;">⚠️ Warning:</strong>
                <div style="color: #7f1d1d; font-size: 13px; margin-top: 4px;">
                    This action cannot be undone. The work order and batch code will be permanently deleted.
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="closeDeleteModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626; color: white;">Delete Work Order</button>
            </div>
        </form>
    </div>
</div>

<script>
// Delete modal functions
function openDeleteModal(woId, woNumber) {
    document.getElementById('modal-wo-id').value = woId;
    document.getElementById('modal-wo-number').textContent = woNumber;
    document.getElementById('delete-modal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('delete-modal').style.display = 'none';
    document.getElementById('delete-form').reset();
    document.getElementById('custom-reason-field').style.display = 'none';
}

function toggleCustomReason() {
    const select = document.getElementById('delete-reason-select');
    const customField = document.getElementById('custom-reason-field');
    const customInput = document.getElementById('custom-delete-reason');
    
    if (select.value === 'custom') {
        customField.style.display = 'block';
        customInput.required = true;
    } else {
        customField.style.display = 'none';
        customInput.required = false;
    }
}

// Close modal on outside click
document.getElementById('delete-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ESC key to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});

// Product data by group
let productsByGroup = {};

// Fetch all products for dynamic loading
<?php
$allProducts = $pdo->query("
    SELECT id, product_group_id, sku, name, attribute_per_oz, size_oz, total_mg, stock_quantity
    FROM products 
    WHERE active = 1 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);
?>

const allProducts = <?php echo json_encode($allProducts); ?>;

// Organize products by group
allProducts.forEach(product => {
    if (!productsByGroup[product.product_group_id]) {
        productsByGroup[product.product_group_id] = [];
    }
    productsByGroup[product.product_group_id].push(product);
});

// Load products when group is selected
function loadGroupProducts() {
    const groupId = document.getElementById('product-group-select').value;
    const productSelect = document.getElementById('product-select');
    
    // Clear existing options
    productSelect.innerHTML = '<option value="">Select product (or enter SKU manually)...</option>';
    
    if (!groupId) return;
    
    // Load products for this group
    const products = productsByGroup[groupId] || [];
    products.forEach(product => {
        const option = document.createElement('option');
        option.value = product.id;
        option.textContent = `${product.name} (${product.sku})`;
        option.dataset.sku = product.sku;
        option.dataset.name = product.name;
        option.dataset.attributePerOz = product.attribute_per_oz || '';
        option.dataset.sizeOz = product.size_oz || '';
        option.dataset.totalMg = product.total_mg || '';
        option.dataset.stock = product.stock_quantity || 0;
        productSelect.appendChild(option);
    });
}

// Fill product details when selected
function fillProductDetails() {
    const productSelect = document.getElementById('product-select');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    const skuInput = document.getElementById('sku-base-input');
    const detailsPreview = document.getElementById('product-details-preview');
    const detailsContent = document.getElementById('product-details-content');
    
    if (!selectedOption.value) {
        skuInput.value = '';
        detailsPreview.style.display = 'none';
        return;
    }
    
    // Auto-fill SKU
    skuInput.value = selectedOption.dataset.sku || '';
    
    // Show product details
    let details = `<strong>${selectedOption.dataset.name}</strong> (${selectedOption.dataset.sku})<br>`;
    if (selectedOption.dataset.attributePerOz) {
        details += `attribute: ${selectedOption.dataset.attributePerOz}mg/oz × ${selectedOption.dataset.sizeOz}oz = ${selectedOption.dataset.totalMg}mg total<br>`;
    }
    details += `Current Stock: ${selectedOption.dataset.stock} units`;
    
    detailsContent.innerHTML = details;
    detailsPreview.style.display = 'block';
    
    // Update batch code preview
    updateBatchCodePreview();
}

// Live batch code preview
document.addEventListener('DOMContentLoaded', function() {
    const skuInput = document.getElementById('sku-base-input');
    const batchTypeInput = document.getElementById('batch-type-select');
    const dateInput = document.getElementById('production-date');
    const preview = document.getElementById('batch-code-preview');
    
    function updateBatchCodePreview() {
        if (!preview) return;
        
        const sku = skuInput?.value || 'SKU';
        const batchType = batchTypeInput?.value || 'production';
        const date = dateInput?.value || new Date().toISOString().split('T')[0];
        
        const prefix = batchType === 'production' ? 'P' : (batchType === 'testing' ? 'T' : 'RDT');
        const dateObj = new Date(date);
        const month = String(dateObj.getMonth() + 1).padStart(2, '0');
        const day = String(dateObj.getDate()).padStart(2, '0');
        const year = dateObj.getFullYear();
        const dateStr = `${month}${day}${year}`;
        
        preview.textContent = `${sku}-${prefix}-${dateStr}`;
    }
    
    window.updateBatchCodePreview = updateBatchCodePreview;
    
    if (skuInput) skuInput.addEventListener('input', updateBatchCodePreview);
    if (batchTypeInput) batchTypeInput.addEventListener('change', updateBatchCodePreview);
    if (dateInput) dateInput.addEventListener('change', updateBatchCodePreview);
    
    updateBatchCodePreview();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

