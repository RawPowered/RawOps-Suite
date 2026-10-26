<?php
/**
 * Quick Add Inventory API
 * AJAX endpoint for real-time inventory creation from recipe designer
 * Returns JSON responses
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';

// Ensure user is logged in with operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    echo json_encode(['success' => false, 'error' => 'Access denied. Operations role required.']);
    exit;
}

$pdo = db();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'add_ingredient') {
        // Add ingredient to product group inventory
        $groupId = intval($_POST['product_group_id'] ?? 0);
        $name = trim($_POST['ingredient_name'] ?? '');
        $type = trim($_POST['ingredient_type'] ?? '');
        $unit = trim($_POST['unit_of_measure'] ?? 'grams');
        $stockQty = floatval($_POST['stock_quantity'] ?? 0);
        $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
        $lowStockThreshold = floatval($_POST['low_stock_threshold'] ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');
        
        if ($groupId <= 0 || $name === '') {
            throw new Exception('Product group and ingredient name are required');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO product_group_ingredients
            (product_group_id, ingredient_name, ingredient_type, stock_quantity, 
             unit_of_measure, cost_per_unit, low_stock_threshold, supplier, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$groupId, $name, $type, $stockQty, $unit, $costPerUnit, $lowStockThreshold, $supplier]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'id' => $newId,
            'name' => $name,
            'type' => $type,
            'unit' => $unit,
            'cost_per_unit' => $costPerUnit,
            'message' => 'Ingredient added successfully'
        ]);
        
    } elseif ($action === 'add_packaging') {
        // Add packaging to product group inventory
        $groupId = intval($_POST['product_group_id'] ?? 0);
        $item = trim($_POST['packaging_item'] ?? '');
        $type = trim($_POST['packaging_type'] ?? '');
        $stockQty = intval($_POST['stock_quantity'] ?? 0);
        $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
        $lowStockThreshold = intval($_POST['low_stock_threshold'] ?? 10);
        $supplier = trim($_POST['supplier'] ?? '');
        
        if ($groupId <= 0 || $item === '') {
            throw new Exception('Product group and packaging item are required');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO product_group_packaging
            (product_group_id, packaging_item, packaging_type, stock_quantity,
             cost_per_unit, low_stock_threshold, supplier, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$groupId, $item, $type, $stockQty, $costPerUnit, $lowStockThreshold, $supplier]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'id' => $newId,
            'name' => $item,
            'type' => $type,
            'cost_per_unit' => $costPerUnit,
            'message' => 'Packaging added successfully'
        ]);
        
    } elseif ($action === 'add_material') {
        // Add material to product group inventory
        $groupId = intval($_POST['product_group_id'] ?? 0);
        $name = trim($_POST['material_name'] ?? '');
        $type = trim($_POST['material_type'] ?? '');
        $unit = trim($_POST['unit_of_measure'] ?? 'units');
        $stockQty = floatval($_POST['stock_quantity'] ?? 0);
        $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
        $lowStockThreshold = floatval($_POST['low_stock_threshold'] ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');
        
        if ($groupId <= 0 || $name === '') {
            throw new Exception('Product group and material name are required');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO product_group_materials
            (product_group_id, material_name, material_type, stock_quantity, 
             unit_of_measure, cost_per_unit, low_stock_threshold, supplier, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$groupId, $name, $type, $stockQty, $unit, $costPerUnit, $lowStockThreshold, $supplier]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'id' => $newId,
            'name' => $name,
            'type' => $type,
            'unit' => $unit,
            'cost_per_unit' => $costPerUnit,
            'is_global' => false,
            'message' => 'Material added successfully'
        ]);
        
    } elseif ($action === 'add_global_material') {
        // Add material to global inventory (shared across all product groups)
        $name = trim($_POST['material_name'] ?? '');
        $type = trim($_POST['material_type'] ?? '');
        $unit = trim($_POST['unit_of_measure'] ?? 'units');
        $stockQty = floatval($_POST['stock_quantity'] ?? 0);
        $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
        $lowStockThreshold = floatval($_POST['low_stock_threshold'] ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');
        
        if ($name === '') {
            throw new Exception('Material name is required');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO global_materials
            (material_name, material_type, stock_quantity, unit_of_measure,
             cost_per_unit, low_stock_threshold, supplier, is_global, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$name, $type, $stockQty, $unit, $costPerUnit, $lowStockThreshold, $supplier]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'id' => $newId,
            'name' => $name,
            'type' => $type,
            'unit' => $unit,
            'cost_per_unit' => $costPerUnit,
            'is_global' => true,
            'message' => 'Global material added successfully'
        ]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


