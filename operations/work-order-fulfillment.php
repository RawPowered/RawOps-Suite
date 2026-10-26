<?php
$SEO = [
    'title' => 'Work Order Fulfillment — Production',
    'description' => 'Production team work order fulfillment and reporting.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations or production_team role
$userId = current_user_id();
$isProductionTeam = user_has_role($userId, 'production_team');
$isOperations = user_has_role($userId, 'operations');

if (!$userId || (!$isProductionTeam && !$isOperations)) {
    flash_set('error', 'Access denied. Production Team or Operations role required.');
    redirect('/login.php');
}

$pdo = db();
$message = '';
$error = '';

$woId = intval($_GET['id'] ?? 0);

// Handle status update (Draft → Scheduled)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_wo_status'])) {
    try {
        $woId = intval($_POST['work_order_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'scheduled';
        
        if ($woId <= 0) throw new Exception('Invalid work order ID');
        
        $stmt = $pdo->prepare("UPDATE work_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $woId]);
        
        // If status is completed, generate accounting journal entry for COGS
        if ($newStatus === 'completed') {
            try {
                require_once __DIR__ . '/../required/expense-automation.php';
                create_journal_entry_from_work_order($woId);
            } catch (Exception $e) {
                error_log("Work order journal entry failed for WO {$woId}: " . $e->getMessage());
            }
        }
        
        $message = "Work order status updated to: " . ucfirst($newStatus);
        
    } catch (Throwable $e) {
        $error = 'Error updating status: ' . $e->getMessage();
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
        
        $message = "Work order deleted successfully. Reason: {$finalReason}";
        
    } catch (Throwable $e) {
        $error = 'Error deleting work order: ' . $e->getMessage();
    }
}

// Handle work order fulfillment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fulfill_work_order'])) {
    try {
        $woId = intval($_POST['work_order_id'] ?? 0);
        $actualQty = intval($_POST['actual_quantity_produced'] ?? 0);
        $wasteQty = intval($_POST['quantity_waste'] ?? 0);
        $wasteReason = trim($_POST['waste_reason'] ?? '');
        $varianceReason = trim($_POST['variance_reason'] ?? '');
        $fulfillmentNotes = trim($_POST['fulfillment_notes'] ?? '');
        
        if ($woId <= 0 || $actualQty < 0) {
            throw new Exception('Valid work order and production quantity required');
        }
        
        // Get work order details
        $stmt = $pdo->prepare("SELECT * FROM work_orders WHERE id = ?");
        $stmt->execute([$woId]);
        $wo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wo) throw new Exception('Work order not found');
        
        $expectedQty = $wo['quantity_to_produce'];
        $variance = $actualQty - $expectedQty;
        $requiresReview = abs($variance) > ($expectedQty * 0.05); // Flag if >5% variance
        
        // Update work order
        $stmt = $pdo->prepare("
            UPDATE work_orders SET
                actual_quantity_produced = ?,
                quantity_produced = ?,
                quantity_waste = ?,
                waste_reason = ?,
                variance_reason = ?,
                fulfillment_notes = ?,
                status = 'completed',
                completion_date = NOW(),
                completed_by = ?,
                requires_ops_review = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $actualQty, $actualQty, $wasteQty, $wasteReason, $varianceReason,
            $fulfillmentNotes, $userId, $requiresReview ? 1 : 0, $woId
        ]);
        
        // Update batch code
        $stmt = $pdo->prepare("
            UPDATE batch_codes SET
                quantity_produced = ?,
                updated_at = NOW()
            WHERE work_order_id = ?
        ");
        $stmt->execute([$actualQty, $woId]);
        
        // Process inventory usage and perform automatic deductions
        $inventoryUsage = json_decode($_POST['inventory_adjustments_json'] ?? '[]', true);
        $actualIngredientCost = 0;
        $actualPackagingCost = 0;
        $actualMaterialCost = 0;
        
        if (!empty($inventoryUsage)) {
            // Begin transaction for inventory deduction
            $pdo->beginTransaction();
            
            try {
                // Save inventory adjustments records
                $stmtAdj = $pdo->prepare("
                    INSERT INTO work_order_inventory_adjustments
                    (work_order_id, adjustment_type, item_id, item_name, quantity_expected, quantity_actual,
                     unit_of_measure, cost_per_unit, status, is_global_material, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($inventoryUsage as $usage) {
                    $usageType = $usage['type'];
                    $itemId = intval($usage['item_id'] ?? 0);
                    $actualQtyUsed = floatval($usage['actual'] ?? 0);
                    $costPerUnit = floatval($usage['cost_per_unit'] ?? 0);
                    $isGlobal = intval($usage['is_global'] ?? 0);
                    
                    // Save adjustment record
                    $stmtAdj->execute([
                        $woId,
                        $usageType,
                        $itemId,
                        $usage['item_name'] ?? '',
                        floatval($usage['expected'] ?? 0),
                        $actualQtyUsed,
                        $usage['unit'] ?? '',
                        $costPerUnit,
                        $usage['status'] ?? 'as-expected',
                        $isGlobal
                    ]);
                    
                    // Calculate actual costs
                    $itemCost = $actualQtyUsed * $costPerUnit;
                    if ($usageType === 'ingredient') {
                        $actualIngredientCost += $itemCost;
                    } elseif ($usageType === 'packaging') {
                        $actualPackagingCost += $itemCost;
                    } elseif ($usageType === 'material') {
                        $actualMaterialCost += $itemCost;
                    }
                    
                    // Automatic inventory deduction (only if item_id is valid)
                    if ($itemId > 0 && $actualQtyUsed > 0) {
                        if ($usageType === 'ingredient') {
                            $stmtDeduct = $pdo->prepare("
                                UPDATE product_group_ingredients 
                                SET stock_quantity = stock_quantity - ?, updated_at = NOW() 
                                WHERE id = ?
                            ");
                            $stmtDeduct->execute([$actualQtyUsed, $itemId]);
                        } elseif ($usageType === 'packaging') {
                            $stmtDeduct = $pdo->prepare("
                                UPDATE product_group_packaging 
                                SET stock_quantity = stock_quantity - ?, updated_at = NOW() 
                                WHERE id = ?
                            ");
                            $stmtDeduct->execute([$actualQtyUsed, $itemId]);
                        } elseif ($usageType === 'material') {
                            if ($isGlobal) {
                                $stmtDeduct = $pdo->prepare("
                                    UPDATE global_materials 
                                    SET stock_quantity = stock_quantity - ?, updated_at = NOW() 
                                    WHERE id = ?
                                ");
                            } else {
                                $stmtDeduct = $pdo->prepare("
                                    UPDATE product_group_materials 
                                    SET stock_quantity = stock_quantity - ?, updated_at = NOW() 
                                    WHERE id = ?
                                ");
                            }
                            $stmtDeduct->execute([$actualQtyUsed, $itemId]);
                        }
                    }
                }
                
                // Update work order with actual costs
                $actualTotalCost = $actualIngredientCost + $actualPackagingCost + $actualMaterialCost;
                $stmtCosts = $pdo->prepare("
                    UPDATE work_orders SET
                        actual_ingredient_cost = ?,
                        actual_packaging_cost = ?,
                        actual_material_cost = ?,
                        actual_total_cost = ?
                    WHERE id = ?
                ");
                $stmtCosts->execute([
                    $actualIngredientCost,
                    $actualPackagingCost,
                    $actualMaterialCost,
                    $actualTotalCost,
                    $woId
                ]);
                
                // Commit transaction
                $pdo->commit();
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw new Exception('Inventory deduction failed: ' . $e->getMessage());
            }
        }
        
        $costVariance = $actualIngredientCost + $actualPackagingCost + $actualMaterialCost - 
                        ($wo['projected_total_cost'] ?? 0);
        $costMsg = '';
        if (abs($costVariance) > 0.01) {
            $costMsg = ' Cost variance: ' . ($costVariance > 0 ? '+' : '') . '$' . number_format(abs($costVariance), 2) . '.';
        }
        
        $message = "Work order completed!{$costMsg} " . ($requiresReview ? '⚠️ Flagged for operations review due to variance.' : '✅ No review needed.');
        
        // Redirect back to fulfillment list
        header('Location: ' . path_with_base('/operations/work-order-fulfillment.php?message=' . urlencode($message)));
        exit;
        
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch work orders
if ($woId > 0) {
    // Viewing specific work order for fulfillment
    $stmt = $pdo->prepare("
        SELECT wo.*, pg.name as group_name, pg.has_recipe_system,
               u.first_name as creator_first, u.last_name as creator_last,
               c.first_name as completed_first, c.last_name as completed_last
        FROM work_orders wo
        JOIN product_groups pg ON pg.id = wo.product_group_id
        LEFT JOIN users u ON u.id = wo.created_by
        LEFT JOIN users c ON c.id = wo.completed_by
        WHERE wo.id = ?
    ");
    $stmt->execute([$woId]);
    $workOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$workOrder) {
        flash_set('error', 'Work order not found');
        redirect('/operations/work-order-fulfillment.php');
    }
    
    // Fetch recipe if available
    $recipe = null;
    if ($workOrder['has_recipe_system']) {
        $stmt = $pdo->prepare("
            SELECT * FROM product_group_recipes 
            WHERE product_group_id = ? AND active = 1 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$workOrder['product_group_id']]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($recipe) {
            $recipe['ingredients'] = !empty($recipe['ingredient_list']) ? json_decode($recipe['ingredient_list'], true) : [];
            $recipe['packaging'] = !empty($recipe['packaging_list']) ? json_decode($recipe['packaging_list'], true) : [];
            $recipe['materials'] = !empty($recipe['material_list']) ? json_decode($recipe['material_list'], true) : [];
        }
    }
    
} else {
    // List view - show all work orders
    $statusFilter = $_GET['status'] ?? 'all';
    
    $sql = "
        SELECT wo.*, pg.name as group_name,
               u.first_name, u.last_name,
               COALESCE(wo.requires_ops_review, 0) as requires_ops_review,
               COALESCE(wo.ops_reviewed, 0) as ops_reviewed
        FROM work_orders wo
        JOIN product_groups pg ON pg.id = wo.product_group_id
        LEFT JOIN users u ON u.id = wo.manufacturer_id
        WHERE 1=1
    ";
    
    if ($statusFilter === 'scheduled') {
        $sql .= " AND wo.status = 'scheduled'";
    } elseif ($statusFilter === 'in_progress') {
        $sql .= " AND wo.status = 'in_progress'";
    } elseif ($statusFilter === 'completed') {
        $sql .= " AND wo.status = 'completed'";
    } elseif ($statusFilter === 'needs_review') {
        $sql .= " AND COALESCE(wo.requires_ops_review, 0) = 1 AND COALESCE(wo.ops_reviewed, 0) = 0";
    } elseif ($statusFilter === 'draft') {
        $sql .= " AND wo.status = 'draft'";
    }
    // else show all
    
    $sql .= " ORDER BY wo.production_date DESC, wo.created_at DESC LIMIT 50";
    
    try {
        $workOrders = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        $workOrders = [];
        $error = 'Error loading work orders: ' . $e->getMessage();
    }
}
?>

<section class="section">
    <div class="container">
        <?php if ($woId > 0): ?>
        <!-- Work Order Fulfillment Form -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📋 Fulfill Work Order: <?php echo htmlspecialchars($workOrder['wo_number']); ?></h1>
                <p class="muted">Report production results and inventory usage</p>
            </div>
            <a href="<?php echo path_with_base('/operations/work-order-fulfillment.php'); ?>" class="btn secondary">← Back to List</a>
        </div>

        <?php if ($message): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($workOrder['status'] === 'completed'): ?>
        <!-- Show completed work order details -->
        <div class="card" style="background: #dcfce7; border: 2px solid #059669; margin-bottom: 24px;">
            <h3 style="margin: 0 0 16px 0; color: #14532d;">✅ Work Order Completed</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div>
                    <div style="color: #6b7280; font-size: 12px;">Expected Production</div>
                    <div style="font-size: 20px; font-weight: 700;"><?php echo number_format($workOrder['quantity_to_produce']); ?> units</div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 12px;">Actual Production</div>
                    <div style="font-size: 20px; font-weight: 700; color: #059669;"><?php echo number_format($workOrder['actual_quantity_produced']); ?> units</div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 12px;">Variance</div>
                    <div style="font-size: 20px; font-weight: 700; color: <?php echo ($workOrder['actual_quantity_produced'] - $workOrder['quantity_to_produce']) >= 0 ? '#059669' : '#dc2626'; ?>;">
                        <?php echo ($workOrder['actual_quantity_produced'] - $workOrder['quantity_to_produce']) > 0 ? '+' : ''; ?><?php echo number_format($workOrder['actual_quantity_produced'] - $workOrder['quantity_to_produce']); ?>
                    </div>
                </div>
            </div>
            
            <?php if ($workOrder['fulfillment_notes']): ?>
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #059669;">
                <strong>Fulfillment Notes:</strong>
                <p style="margin: 8px 0 0 0;"><?php echo nl2br(htmlspecialchars($workOrder['fulfillment_notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        
        <!-- Fulfillment Form (Work Order Not Yet Completed) -->
        
        <!-- Work Order Info Card -->
        <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, #f0f9ff, #e0f2fe);">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px;">
                <div>
                    <div style="color: #6b7280; font-size: 12px;">WO Number</div>
                    <div style="font-size: 18px; font-weight: 700;"><?php echo htmlspecialchars($workOrder['wo_number']); ?></div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 12px;">Batch Code</div>
                    <div style="font-size: 14px; font-weight: 700; font-family: monospace;"><?php echo htmlspecialchars($workOrder['batch_code']); ?></div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 12px;">Product Group</div>
                    <div style="font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($workOrder['group_name']); ?></div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 12px;">Target Quantity</div>
                    <div style="font-size: 18px; font-weight: 700; color: #3b82f6;"><?php echo number_format($workOrder['quantity_to_produce']); ?> units</div>
                </div>
            </div>
        </div>

        <!-- Production Timeline -->
        <?php
        $now = new DateTime();
        $created = new DateTime($workOrder['created_at']);
        $scheduledStart = $workOrder['scheduled_start_date'] ? new DateTime($workOrder['scheduled_start_date']) : null;
        $actualStart = $workOrder['actual_start_date'] ? new DateTime($workOrder['actual_start_date']) : null;
        $dueDate = $workOrder['due_date'] ? new DateTime($workOrder['due_date']) : null;
        $completionDate = $workOrder['completion_date'] ? new DateTime($workOrder['completion_date']) : null;
        
        $isOverdue = $dueDate && !$completionDate && $now > $dueDate;
        $timeInProduction = null;
        if ($actualStart && $completionDate) {
            $timeInProduction = $actualStart->diff($completionDate);
        } elseif ($actualStart) {
            $timeInProduction = $actualStart->diff($now);
        }
        ?>
        <div class="card" style="margin-bottom: 24px; <?php echo $isOverdue ? 'border: 2px solid #dc2626; background: #fef2f2;' : 'background: #f9fafb;'; ?>">
            <h3 style="margin: 0 0 16px 0; <?php echo $isOverdue ? 'color: #991b1b;' : 'color: #374151;'; ?>">⏱️ Production Timeline<?php echo $isOverdue ? ' - OVERDUE' : ''; ?></h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
                <div>
                    <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Created</div>
                    <div style="font-weight: 600;"><?php echo $created->format('M d, Y g:i A'); ?></div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Scheduled Start</div>
                    <div style="font-weight: 600;"><?php echo $scheduledStart ? $scheduledStart->format('M d, Y g:i A') : 'Not Set'; ?></div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Actual Start</div>
                    <div style="font-weight: 600; color: <?php echo $actualStart ? '#059669' : '#6b7280'; ?>;">
                        <?php echo $actualStart ? $actualStart->format('M d, Y g:i A') : 'Not Started'; ?>
                    </div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Due Date</div>
                    <div style="font-weight: 600; color: <?php echo $isOverdue ? '#dc2626' : '#374151'; ?>;">
                        <?php echo $dueDate ? $dueDate->format('M d, Y g:i A') : 'Not Set'; ?>
                    </div>
                </div>
                <div>
                    <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Completed</div>
                    <div style="font-weight: 600; color: <?php echo $completionDate ? '#059669' : '#6b7280'; ?>;">
                        <?php echo $completionDate ? $completionDate->format('M d, Y g:i A') : 'In Progress'; ?>
                    </div>
                </div>
                <?php if ($timeInProduction): ?>
                <div>
                    <div style="color: #6b7280; font-size: 11px; text-transform: uppercase;">Time in Production</div>
                    <div style="font-weight: 600;">
                        <?php 
                        if ($timeInProduction->days > 0) {
                            echo $timeInProduction->days . 'd ' . $timeInProduction->h . 'h';
                        } else {
                            echo $timeInProduction->h . 'h ' . $timeInProduction->i . 'm';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Projected vs Actual Costs -->
        <?php if ($workOrder['projected_total_cost'] > 0): ?>
        <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, #fef3c7, #fef9e7);">
            <h3 style="margin: 0 0 16px 0; color: #92400e;">💰 Projected vs Actual Costs</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <div>
                    <div style="color: #78350f; font-size: 11px; text-transform: uppercase;">Projected Ingredient</div>
                    <div style="font-size: 18px; font-weight: 700; color: #0369a1;">$<?php echo number_format($workOrder['projected_ingredient_cost'], 2); ?></div>
                    <?php if ($workOrder['actual_ingredient_cost'] > 0): ?>
                    <div style="font-size: 14px; color: #059669; margin-top: 4px;">Actual: $<?php echo number_format($workOrder['actual_ingredient_cost'], 2); ?></div>
                    <div style="font-size: 12px; color: <?php echo ($workOrder['actual_ingredient_cost'] - $workOrder['projected_ingredient_cost']) > 0 ? '#dc2626' : '#059669'; ?>;">
                        <?php 
                        $variance = $workOrder['actual_ingredient_cost'] - $workOrder['projected_ingredient_cost'];
                        echo ($variance > 0 ? '+' : '') . '$' . number_format($variance, 2);
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="color: #78350f; font-size: 11px; text-transform: uppercase;">Projected Packaging</div>
                    <div style="font-size: 18px; font-weight: 700; color: #0369a1;">$<?php echo number_format($workOrder['projected_packaging_cost'], 2); ?></div>
                    <?php if ($workOrder['actual_packaging_cost'] > 0): ?>
                    <div style="font-size: 14px; color: #059669; margin-top: 4px;">Actual: $<?php echo number_format($workOrder['actual_packaging_cost'], 2); ?></div>
                    <div style="font-size: 12px; color: <?php echo ($workOrder['actual_packaging_cost'] - $workOrder['projected_packaging_cost']) > 0 ? '#dc2626' : '#059669'; ?>;">
                        <?php 
                        $variance = $workOrder['actual_packaging_cost'] - $workOrder['projected_packaging_cost'];
                        echo ($variance > 0 ? '+' : '') . '$' . number_format($variance, 2);
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="color: #78350f; font-size: 11px; text-transform: uppercase;">Projected Material</div>
                    <div style="font-size: 18px; font-weight: 700; color: #0369a1;">$<?php echo number_format($workOrder['projected_material_cost'], 2); ?></div>
                    <?php if ($workOrder['actual_material_cost'] > 0): ?>
                    <div style="font-size: 14px; color: #059669; margin-top: 4px;">Actual: $<?php echo number_format($workOrder['actual_material_cost'], 2); ?></div>
                    <div style="font-size: 12px; color: <?php echo ($workOrder['actual_material_cost'] - $workOrder['projected_material_cost']) > 0 ? '#dc2626' : '#059669'; ?>;">
                        <?php 
                        $variance = $workOrder['actual_material_cost'] - $workOrder['projected_material_cost'];
                        echo ($variance > 0 ? '+' : '') . '$' . number_format($variance, 2);
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="background: white; padding: 16px; border-radius: 8px; border: 2px solid #f59e0b;">
                    <div style="color: #78350f; font-size: 11px; text-transform: uppercase;">Total Cost</div>
                    <div style="font-size: 20px; font-weight: 700; color: #0369a1;">$<?php echo number_format($workOrder['projected_total_cost'], 2); ?></div>
                    <?php if ($workOrder['actual_total_cost'] > 0): ?>
                    <div style="font-size: 16px; color: #059669; margin-top: 4px;">Actual: $<?php echo number_format($workOrder['actual_total_cost'], 2); ?></div>
                    <div style="font-size: 14px; font-weight: 700; color: <?php echo ($workOrder['actual_total_cost'] - $workOrder['projected_total_cost']) > 0 ? '#dc2626' : '#059669'; ?>;">
                        <?php 
                        $totalVariance = $workOrder['actual_total_cost'] - $workOrder['projected_total_cost'];
                        echo ($totalVariance > 0 ? '+' : '') . '$' . number_format($totalVariance, 2);
                        $variancePercent = $workOrder['projected_total_cost'] > 0 ? ($totalVariance / $workOrder['projected_total_cost']) * 100 : 0;
                        echo ' (' . ($variancePercent > 0 ? '+' : '') . number_format($variancePercent, 1) . '%)';
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recipe Reference -->
        <?php if ($recipe): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h3 style="margin: 0 0 16px 0;">📋 Recipe: <?php echo htmlspecialchars($recipe['recipe_name']); ?></h3>
            
            <!-- Ingredients -->
            <?php if (!empty($recipe['ingredients'])): ?>
            <div style="background: #fef3c7; padding: 12px; border-radius: 8px; margin-bottom: 12px;">
                <strong>🧪 Ingredients Required:</strong>
                <div style="margin-top: 8px;">
                    <?php foreach ($recipe['ingredients'] as $ing): ?>
                    <div style="padding: 4px 0; font-size: 13px;">
                        • <?php echo htmlspecialchars($ing['ingredient_name']); ?>: 
                        <strong><?php echo number_format($ing['quantity'], 3); ?> <?php echo htmlspecialchars($ing['unit']); ?></strong>
                        <?php if (isset($ing['storage_quantity'])): ?>
                        <span style="color: #6b7280; font-size: 12px;">(= <?php echo number_format($ing['storage_quantity'], 3); ?> <?php echo htmlspecialchars($ing['storage_unit']); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Packaging -->
            <?php if (!empty($recipe['packaging'])): ?>
            <div style="background: #dbeafe; padding: 12px; border-radius: 8px; margin-bottom: 12px;">
                <strong>📦 Packaging Required:</strong>
                <div style="margin-top: 8px;">
                    <?php foreach ($recipe['packaging'] as $pkg): ?>
                    <div style="padding: 4px 0; font-size: 13px;">
                        • <?php echo htmlspecialchars($pkg['packaging_name']); ?>: 
                        <strong><?php echo number_format($pkg['quantity']); ?> units</strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Equipment/Materials -->
            <?php if (!empty($recipe['materials'])): ?>
            <div style="background: #f3e8ff; padding: 12px; border-radius: 8px; margin-bottom: 12px;">
                <strong>🔧 Equipment & Materials:</strong>
                <div style="margin-top: 8px;">
                    <?php foreach ($recipe['materials'] as $mat): ?>
                    <div style="padding: 4px 0; font-size: 13px;">
                        • <?php echo htmlspecialchars($mat['name']); ?>
                        <?php if (!empty($mat['notes'])): ?>
                        <span style="color: #6b7280;">(<?php echo htmlspecialchars($mat['notes']); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Production Steps -->
            <?php if ($recipe['recipe_steps']): ?>
            <details style="margin-top: 12px;">
                <summary style="cursor: pointer; font-weight: 600; padding: 8px; background: #f9fafb; border-radius: 4px;">
                    View Full Production Steps
                </summary>
                <div style="white-space: pre-wrap; margin-top: 12px; padding: 12px; background: #f9fafb; border-radius: 6px; font-family: monospace; font-size: 12px;">
<?php echo htmlspecialchars($recipe['recipe_steps']); ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Fulfillment Form -->
        <form method="post" id="fulfillment-form">
            <input type="hidden" name="fulfill_work_order" value="1">
            <input type="hidden" name="work_order_id" value="<?php echo $woId; ?>">
            <input type="hidden" name="inventory_adjustments_json" id="inventory_adjustments_json">
            
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0;">📊 Production Results</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Actual Quantity Produced *</label>
                        <input type="number" name="actual_quantity_produced" id="actual-qty" required min="0" 
                               value="<?php echo $workOrder['quantity_to_produce']; ?>"
                               onchange="calculateVariance()"
                               style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 16px;">
                        <small style="color: #6b7280;">Expected: <?php echo number_format($workOrder['quantity_to_produce']); ?> units</small>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Quantity Wasted</label>
                        <input type="number" name="quantity_waste" min="0" value="0"
                               style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 16px;">
                        <small style="color: #6b7280;">Units that were damaged or rejected</small>
                    </div>
                </div>
                
                <div id="variance-display" style="display: none; padding: 12px; border-radius: 8px; margin-bottom: 16px;"></div>
                
                <div id="variance-reason-section" style="display: none; margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        <span id="variance-reason-label">Explain Variance *</span>
                    </label>
                    <textarea name="variance_reason" rows="3" id="variance-reason-input"
                              placeholder="Why was production different than expected?"
                              style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Waste Reason (if applicable)</label>
                    <textarea name="waste_reason" rows="2"
                              placeholder="Explain any waste (spillage, defects, quality control rejection, etc.)"
                              style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
            </div>
            
            <!-- Inventory Usage (Pre-populated from Recipe) -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0;">📦 Inventory Usage</h3>
                <p style="color: #6b7280; margin-bottom: 16px;">
                    Report actual quantities used. Pre-filled with expected quantities from recipe. Inventory will be automatically deducted based on actual quantities when work order is completed.
                </p>
                
                <?php if ($recipe): ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 10px; text-align: left;">Type</th>
                                <th style="padding: 10px; text-align: left;">Item Name</th>
                                <th style="padding: 10px; text-align: center;">Expected Qty</th>
                                <th style="padding: 10px; text-align: center;">Actual Qty Used</th>
                                <th style="padding: 10px; text-align: center;">Unit</th>
                                <th style="padding: 10px; text-align: center;">Variance</th>
                                <th style="padding: 10px; text-align: center;">Cost Impact</th>
                                <th style="padding: 10px; text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="inventory-usage-tbody">
                            <?php
                            $rowIndex = 0;
                            // Ingredients
                            if (!empty($recipe['ingredients'])) {
                                foreach ($recipe['ingredients'] as $ing) {
                                    $expectedQty = $ing['storage_quantity'] ?? 0;
                                    $unit = $ing['storage_unit'] ?? $ing['unit'] ?? '';
                                    $costPerUnit = $ing['cost_per_unit'] ?? 0;
                                    $itemName = $ing['ingredient_name'] ?? '';
                                    $itemId = $ing['ingredient_id'] ?? 0;
                                    ?>
                                    <tr class="usage-row" data-row-id="<?php echo $rowIndex; ?>" style="border-bottom: 1px solid #f3f4f6;">
                                        <td style="padding: 10px;">
                                            <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 11px;">INGREDIENT</span>
                                            <input type="hidden" class="usage-type" value="ingredient" data-row-id="<?php echo $rowIndex; ?>">
                                            <input type="hidden" class="usage-item-id" value="<?php echo $itemId; ?>" data-row-id="<?php echo $rowIndex; ?>">
                                            <input type="hidden" class="usage-is-global" value="0" data-row-id="<?php echo $rowIndex; ?>">
                                        </td>
                                        <td style="padding: 10px; font-weight: 600;"><?php echo htmlspecialchars($itemName); ?></td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-expected" data-row-id="<?php echo $rowIndex; ?>"><?php echo number_format($expectedQty, 3); ?></span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <input type="number" step="0.001" class="usage-actual" data-row-id="<?php echo $rowIndex; ?>" 
                                                   value="<?php echo $expectedQty; ?>" 
                                                   onchange="calculateRowVariance(<?php echo $rowIndex; ?>)"
                                                   style="width: 100px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; text-align: center;">
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-unit" data-row-id="<?php echo $rowIndex; ?>"><?php echo htmlspecialchars($unit); ?></span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-variance" id="variance-<?php echo $rowIndex; ?>" style="font-weight: 600;">0</span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-cost-impact" id="cost-impact-<?php echo $rowIndex; ?>" data-cost-per-unit="<?php echo $costPerUnit; ?>" style="font-weight: 600;">$0.00</span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <select class="usage-status" data-row-id="<?php echo $rowIndex; ?>" 
                                                    style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 12px;">
                                                <option value="as-expected" selected>As Expected</option>
                                                <option value="missing">Missing</option>
                                                <option value="leftover">Leftover</option>
                                                <option value="wasted">Wasted</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php
                                    $rowIndex++;
                                }
                            }
                            
                            // Packaging
                            if (!empty($recipe['packaging'])) {
                                foreach ($recipe['packaging'] as $pkg) {
                                    $expectedQty = $pkg['quantity'] ?? 0;
                                    $costPerUnit = $pkg['cost_per_unit'] ?? 0;
                                    $itemName = $pkg['packaging_name'] ?? '';
                                    $itemId = $pkg['packaging_id'] ?? 0;
                                    ?>
                                    <tr class="usage-row" data-row-id="<?php echo $rowIndex; ?>" style="border-bottom: 1px solid #f3f4f6;">
                                        <td style="padding: 10px;">
                                            <span style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 4px; font-size: 11px;">PACKAGING</span>
                                            <input type="hidden" class="usage-type" value="packaging" data-row-id="<?php echo $rowIndex; ?>">
                                            <input type="hidden" class="usage-item-id" value="<?php echo $itemId; ?>" data-row-id="<?php echo $rowIndex; ?>">
                                            <input type="hidden" class="usage-is-global" value="0" data-row-id="<?php echo $rowIndex; ?>">
                                        </td>
                                        <td style="padding: 10px; font-weight: 600;"><?php echo htmlspecialchars($itemName); ?></td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-expected" data-row-id="<?php echo $rowIndex; ?>"><?php echo number_format($expectedQty); ?></span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <input type="number" class="usage-actual" data-row-id="<?php echo $rowIndex; ?>" 
                                                   value="<?php echo $expectedQty; ?>" 
                                                   onchange="calculateRowVariance(<?php echo $rowIndex; ?>)"
                                                   style="width: 100px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; text-align: center;">
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-unit" data-row-id="<?php echo $rowIndex; ?>">units</span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-variance" id="variance-<?php echo $rowIndex; ?>" style="font-weight: 600;">0</span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <span class="usage-cost-impact" id="cost-impact-<?php echo $rowIndex; ?>" data-cost-per-unit="<?php echo $costPerUnit; ?>" style="font-weight: 600;">$0.00</span>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <select class="usage-status" data-row-id="<?php echo $rowIndex; ?>" 
                                                    style="padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 12px;">
                                                <option value="as-expected" selected>As Expected</option>
                                                <option value="missing">Missing</option>
                                                <option value="leftover">Leftover</option>
                                                <option value="wasted">Wasted</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php
                                    $rowIndex++;
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="background: #fef9e7; padding: 16px; border-radius: 8px; margin-top: 16px; border-left: 4px solid #f59e0b;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                        <div>
                            <div style="color: #78350f; font-size: 12px;">Total Cost Impact</div>
                            <div id="total-cost-impact" style="font-size: 20px; font-weight: 700; color: #059669;">$0.00</div>
                        </div>
                        <div>
                            <div style="color: #78350f; font-size: 12px;">Items with Variance</div>
                            <div id="variance-count" style="font-size: 20px; font-weight: 700; color: #3b82f6;">0</div>
                        </div>
                        <div>
                            <div style="color: #78350f; font-size: 12px;">Status</div>
                            <div id="inventory-status" style="font-size: 16px; font-weight: 600; color: #059669;">Ready</div>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div style="background: #fee2e2; padding: 16px; border-radius: 8px; text-align: center; color: #991b1b;">
                    ⚠️ No recipe available for this product group. Cannot pre-populate inventory usage.
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Additional Notes -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0;">📝 Fulfillment Notes</h3>
                <textarea name="fulfillment_notes" rows="4"
                          placeholder="Any additional notes about this production run (optional)"
                          style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px;"></textarea>
            </div>
            
            <!-- Summary & Submit -->
            <div class="card" style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #059669;">
                <h3 style="margin: 0 0 16px 0; color: #14532d;">Summary</h3>
                <div id="fulfillment-summary">
                    <div style="color: #6b7280;">Enter production results above to see summary</div>
                </div>
                
                <div style="margin-top: 24px; display: flex; gap: 12px; justify-content: flex-end;">
                    <a href="<?php echo path_with_base('/operations/work-order-fulfillment.php'); ?>" class="btn secondary">Cancel</a>
                    <button type="submit" class="btn" style="background: var(--green); color: white; font-size: 16px; padding: 12px 24px;">
                        ✅ Complete Work Order
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- Work Orders List -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>🏭 Work Order Fulfillment</h1>
                <p class="muted">Production team dashboard</p>
            </div>
            <?php if ($isOperations): ?>
            <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['message'])): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Status Filter -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="?" class="btn <?php echo $statusFilter === 'all' ? '' : 'secondary'; ?>">
                    All Work Orders
                </a>
                <a href="?status=draft" class="btn <?php echo $statusFilter === 'draft' ? '' : 'secondary'; ?>">
                    Draft
                </a>
                <a href="?status=scheduled" class="btn <?php echo $statusFilter === 'scheduled' ? '' : 'secondary'; ?>">
                    Scheduled
                </a>
                <a href="?status=in_progress" class="btn <?php echo $statusFilter === 'in_progress' ? '' : 'secondary'; ?>">
                    In Progress
                </a>
                <a href="?status=completed" class="btn <?php echo $statusFilter === 'completed' ? '' : 'secondary'; ?>">
                    Completed
                </a>
                <?php if ($isOperations): ?>
                <a href="?status=needs_review" class="btn <?php echo $statusFilter === 'needs_review' ? '' : 'secondary'; ?>" style="<?php echo $statusFilter === 'needs_review' ? 'background: #dc2626; color: white;' : ''; ?>">
                    ⚠️ Needs Review
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Work Orders Table -->
        <div class="card">
            <h2>Work Orders (<?php echo count($workOrders); ?>)</h2>
            <?php if (empty($workOrders)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px;">No work orders found</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">WO Number</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product Group</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Batch Code</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Target Qty</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Date</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workOrders as $wo): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($wo['wo_number']); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($wo['group_name']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <code style="background: #f9fafb; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo htmlspecialchars($wo['batch_code']); ?>
                                </code>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600;">
                                <?php echo number_format($wo['quantity_to_produce']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php echo $wo['production_date'] ? date('M d, Y', strtotime($wo['production_date'])) : '—'; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <span style="padding: 4px 12px; border-radius: 4px; text-transform: uppercase; font-size: 11px; <?php
                                    echo $wo['status'] === 'completed' ? 'background: #dcfce7; color: #14532d;' :
                                        ($wo['status'] === 'in_progress' ? 'background: #fef3c7; color: #92400e;' :
                                        'background: #dbeafe; color: #1e40af;');
                                ?>">
                                    <?php echo htmlspecialchars($wo['status']); ?>
                                </span>
                                <?php if ($wo['requires_ops_review'] && !$wo['ops_reviewed']): ?>
                                <div style="color: #dc2626; font-size: 11px; margin-top: 4px;">⚠️ Needs Review</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($wo['status'] === 'draft'): ?>
                                <div style="display: flex; flex-direction: column; gap: 4px; align-items: center;">
                                    <form method="post" style="width: 100%;">
                                        <input type="hidden" name="update_wo_status" value="1">
                                        <input type="hidden" name="work_order_id" value="<?php echo $wo['id']; ?>">
                                        <input type="hidden" name="new_status" value="scheduled">
                                        <button type="submit" class="btn-small" style="background: var(--green); color: white; width: 100%;">
                                            Schedule
                                        </button>
                                    </form>
                                    <a href="<?php echo path_with_base('/operations/operations_make_order.php?id=' . $wo['id']); ?>" class="btn-small secondary" style="width: 100%; text-align: center; text-decoration: none;">
                                        Edit
                                    </a>
                                    <?php if ($isOperations): ?>
                                    <button onclick="openDeleteModal(<?php echo $wo['id']; ?>, '<?php echo htmlspecialchars($wo['wo_number'], ENT_QUOTES); ?>')" 
                                            class="btn-small" style="background: #dc2626; color: white; width: 100%;">
                                        Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php elseif ($wo['status'] === 'scheduled' || $wo['status'] === 'in_progress'): ?>
                                <div style="display: flex; flex-direction: column; gap: 4px; align-items: center;">
                                    <a href="?id=<?php echo $wo['id']; ?>" class="btn-small" style="background: var(--green); color: white; width: 100%; text-align: center; text-decoration: none;">
                                        Start Fulfillment
                                    </a>
                                    <?php if ($isOperations && $wo['status'] === 'scheduled'): ?>
                                    <button onclick="openDeleteModal(<?php echo $wo['id']; ?>, '<?php echo htmlspecialchars($wo['wo_number'], ENT_QUOTES); ?>')" 
                                            class="btn-small" style="background: #dc2626; color: white; width: 100%;">
                                        Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php elseif ($wo['status'] === 'completed'): ?>
                                <a href="?id=<?php echo $wo['id']; ?>" class="btn-small secondary">
                                    View Details
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
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

// Calculate variance and show appropriate fields
function calculateVariance() {
    const expected = <?php echo $workOrder['quantity_to_produce'] ?? 0; ?>;
    const actual = parseInt(document.getElementById('actual-qty').value) || 0;
    const variance = actual - expected;
    const variancePercent = expected > 0 ? (variance / expected) * 100 : 0;
    
    const display = document.getElementById('variance-display');
    const reasonSection = document.getElementById('variance-reason-section');
    const reasonLabel = document.getElementById('variance-reason-label');
    const reasonInput = document.getElementById('variance-reason-input');
    
    if (variance === 0) {
        display.style.display = 'none';
        reasonSection.style.display = 'none';
        reasonInput.required = false;
    } else {
        const isOver = variance > 0;
        display.style.display = 'block';
        display.style.background = isOver ? '#dcfce7' : '#fee2e2';
        display.style.border = isOver ? '2px solid #059669' : '2px solid #dc2626';
        display.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong style="color: ${isOver ? '#14532d' : '#991b1b'};">
                        ${isOver ? '✅ Over Production' : '⚠️ Under Production'}
                    </strong>
                    <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                        Variance: ${variance > 0 ? '+' : ''}${variance} units (${variancePercent.toFixed(1)}%)
                    </div>
                </div>
                <div style="font-size: 32px; font-weight: 700; color: ${isOver ? '#059669' : '#dc2626'};">
                    ${variance > 0 ? '+' : ''}${variance}
                </div>
            </div>
        `;
        
        // Show reason field if variance is significant
        if (Math.abs(variancePercent) > 5) {
            reasonSection.style.display = 'block';
            reasonLabel.textContent = isOver ? 'Why was production over target? *' : 'Why was production under target? *';
            reasonInput.required = true;
        } else {
            reasonSection.style.display = 'none';
            reasonInput.required = false;
        }
    }
    
    updateSummary();
}

// Inventory Usage - Calculate variances and cost impacts
function calculateRowVariance(rowId) {
    const row = document.querySelector(`tr.usage-row[data-row-id="${rowId}"]`);
    if (!row) return;
    
    const expectedEl = row.querySelector('.usage-expected');
    const actualInput = row.querySelector('.usage-actual');
    const varianceEl = row.querySelector('.usage-variance');
    const costImpactEl = row.querySelector('.usage-cost-impact');
    
    const expected = parseFloat(expectedEl.textContent) || 0;
    const actual = parseFloat(actualInput.value) || 0;
    const costPerUnit = parseFloat(costImpactEl.dataset.costPerUnit) || 0;
    
    const variance = actual - expected;
    const costImpact = variance * costPerUnit;
    
    // Update displays
    varianceEl.textContent = variance.toFixed(3);
    varianceEl.style.color = variance > 0 ? '#dc2626' : (variance < 0 ? '#059669' : '#374151');
    
    costImpactEl.textContent = '$' + Math.abs(costImpact).toFixed(2);
    costImpactEl.style.color = costImpact > 0 ? '#dc2626' : (costImpact < 0 ? '#059669' : '#374151');
    
    // Update totals
    updateInventoryTotals();
}

function updateInventoryTotals() {
    let totalCostImpact = 0;
    let varianceCount = 0;
    
    document.querySelectorAll('.usage-row').forEach(row => {
        const expectedEl = row.querySelector('.usage-expected');
        const actualInput = row.querySelector('.usage-actual');
        const costImpactEl = row.querySelector('.usage-cost-impact');
        
        const expected = parseFloat(expectedEl.textContent) || 0;
        const actual = parseFloat(actualInput.value) || 0;
        const costPerUnit = parseFloat(costImpactEl.dataset.costPerUnit) || 0;
        
        const variance = actual - expected;
        const costImpact = variance * costPerUnit;
        
        totalCostImpact += costImpact;
        if (Math.abs(variance) > 0.01) {
            varianceCount++;
        }
    });
    
    // Update summary display
    const totalCostImpactEl = document.getElementById('total-cost-impact');
    if (totalCostImpactEl) {
        totalCostImpactEl.textContent = (totalCostImpact > 0 ? '+$' : '$') + Math.abs(totalCostImpact).toFixed(2);
        totalCostImpactEl.style.color = totalCostImpact > 0 ? '#dc2626' : (totalCostImpact < 0 ? '#059669' : '#374151');
    }
    
    const varianceCountEl = document.getElementById('variance-count');
    if (varianceCountEl) {
        varianceCountEl.textContent = varianceCount;
        varianceCountEl.style.color = varianceCount > 0 ? '#dc2626' : '#059669';
    }
    
    const statusEl = document.getElementById('inventory-status');
    if (statusEl) {
        if (varianceCount === 0) {
            statusEl.textContent = 'All as Expected';
            statusEl.style.color = '#059669';
        } else {
            statusEl.textContent = `${varianceCount} Variance(s)`;
            statusEl.style.color = '#f59e0b';
        }
    }
}

function collectInventoryUsage() {
    const usageData = [];
    
    document.querySelectorAll('.usage-row').forEach(row => {
        const rowId = row.dataset.rowId;
        const type = row.querySelector('.usage-type').value;
        const itemId = parseInt(row.querySelector('.usage-item-id').value) || 0;
        const isGlobal = parseInt(row.querySelector('.usage-is-global').value) || 0;
        const itemName = row.querySelector('td:nth-child(2)').textContent.trim();
        const expected = parseFloat(row.querySelector('.usage-expected').textContent) || 0;
        const actual = parseFloat(row.querySelector('.usage-actual').value) || 0;
        const unit = row.querySelector('.usage-unit').textContent.trim();
        const costPerUnit = parseFloat(row.querySelector('.usage-cost-impact').dataset.costPerUnit) || 0;
        const status = row.querySelector('.usage-status').value;
        
        usageData.push({
            type,
            item_id: itemId,
            item_name: itemName,
            expected,
            actual,
            unit,
            cost_per_unit: costPerUnit,
            status,
            is_global: isGlobal
        });
    });
    
    return usageData;
}

function updateSummary() {
    const actual = parseInt(document.getElementById('actual-qty').value) || 0;
    const expected = <?php echo $workOrder['quantity_to_produce'] ?? 0; ?>;
    const variance = actual - expected;
    
    const summary = document.getElementById('fulfillment-summary');
    summary.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div>
                <div style="color: #6b7280; font-size: 12px;">Expected</div>
                <div style="font-size: 20px; font-weight: 600;">${expected} units</div>
            </div>
            <div>
                <div style="color: #6b7280; font-size: 12px;">Actual</div>
                <div style="font-size: 20px; font-weight: 600; color: #059669;">${actual} units</div>
            </div>
            <div>
                <div style="color: #6b7280; font-size: 12px;">Variance</div>
                <div style="font-size: 20px; font-weight: 600; color: ${variance >= 0 ? '#059669' : '#dc2626'};">
                    ${variance > 0 ? '+' : ''}${variance}
                </div>
            </div>
        </div>
    `;
}

// Form submission
document.getElementById('fulfillment-form')?.addEventListener('submit', function(e) {
    const inventoryUsage = collectInventoryUsage();
    document.getElementById('inventory_adjustments_json').value = JSON.stringify(inventoryUsage);
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    calculateVariance();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

