<?php
$SEO = [
    'title' => 'Inventory Value Report — Accounting',
    'description' => 'Inventory valuation and manufacturing cost analysis',
    'type' => 'website'
];
require_once __DIR__ . '/../../../required/header.php';

$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}

$pdo = db();

// Get parameters
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

// Calculate inventory values
$ingredientValue = 0;
$packagingValue = 0;
$materialValue = 0;
$finishedGoodsValue = 0;

// Ingredients inventory value
try {
    $ingredientStmt = $pdo->query("
        SELECT 
            pg.name as product_group_name,
            pgi.ingredient_name,
            pgi.stock_quantity,
            pgi.unit_of_measure,
            pgi.cost_per_unit,
            (pgi.stock_quantity * pgi.cost_per_unit) as total_value
        FROM product_group_ingredients pgi
        JOIN product_groups pg ON pg.id = pgi.product_group_id
        WHERE pgi.stock_quantity > 0
        ORDER BY total_value DESC
    ");
    $ingredients = $ingredientStmt->fetchAll(PDO::FETCH_ASSOC);
    $ingredientValue = array_sum(array_column($ingredients, 'total_value'));
} catch (Exception $e) {
    $ingredients = [];
}

// Packaging inventory value
try {
    $packagingStmt = $pdo->query("
        SELECT 
            pg.name as product_group_name,
            pgp.packaging_item,
            pgp.stock_quantity,
            pgp.cost_per_unit,
            (pgp.stock_quantity * pgp.cost_per_unit) as total_value
        FROM product_group_packaging pgp
        JOIN product_groups pg ON pg.id = pgp.product_group_id
        WHERE pgp.stock_quantity > 0
        ORDER BY total_value DESC
    ");
    $packaging = $packagingStmt->fetchAll(PDO::FETCH_ASSOC);
    $packagingValue = array_sum(array_column($packaging, 'total_value'));
} catch (Exception $e) {
    $packaging = [];
}

// Materials inventory value
try {
    $materialStmt = $pdo->query("
        SELECT 
            pg.name as product_group_name,
            pgm.material_name,
            pgm.stock_quantity,
            pgm.unit_of_measure,
            pgm.cost_per_unit,
            (pgm.stock_quantity * pgm.cost_per_unit) as total_value
        FROM product_group_materials pgm
        JOIN product_groups pg ON pg.id = pgm.product_group_id
        WHERE pgm.stock_quantity > 0
        ORDER BY total_value DESC
    ");
    $materials = $materialStmt->fetchAll(PDO::FETCH_ASSOC);
    $materialValue = array_sum(array_column($materials, 'total_value'));
} catch (Exception $e) {
    $materials = [];
}

// Finished goods inventory value
$finishedGoodsStmt = $pdo->query("
    SELECT 
        p.sku,
        p.name,
        p.stock_quantity,
        COALESCE(p.total_production_cost, p.wholesale_price * 0.4) as unit_cost,
        (p.stock_quantity * COALESCE(p.total_production_cost, p.wholesale_price * 0.4)) as total_value,
        pg.name as product_group_name
    FROM products p
    LEFT JOIN product_groups pg ON pg.id = p.product_group_id
    WHERE p.stock_quantity > 0
    ORDER BY total_value DESC
");
$finishedGoods = $finishedGoodsStmt->fetchAll(PDO::FETCH_ASSOC);
$finishedGoodsValue = array_sum(array_column($finishedGoods, 'total_value'));

// Total inventory value
$totalInventoryValue = $ingredientValue + $packagingValue + $materialValue + $finishedGoodsValue;

// Get COGS by product group (last 30 days)
$cogsStmt = $pdo->query("
    SELECT 
        pg.name as product_group_name,
        COUNT(wo.id) as work_order_count,
        COALESCE(SUM(wo.actual_ingredient_cost + wo.actual_packaging_cost + wo.actual_material_cost), 0) as total_cogs,
        COALESCE(AVG(wo.actual_ingredient_cost + wo.actual_packaging_cost + wo.actual_material_cost), 0) as avg_cogs
    FROM work_orders wo
    JOIN product_groups pg ON pg.id = wo.product_group_id
    WHERE wo.status = 'completed'
    AND wo.completion_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY pg.name
    ORDER BY total_cogs DESC
");
$cogsByGroup = $cogsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-box-seam text-info"></i> Inventory Value Report</h1>
            <p class="text-muted">Manufacturing cost analysis as of <?= date('M j, Y', strtotime($asOfDate)) ?></p>
        </div>
        <div>
            <a href="../export_handler.php?report_type=inventory&format=csv" class="btn btn-outline-primary">
                <i class="bi bi-download"></i> Export
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-primary">
                <div class="card-body text-center">
                    <small class="text-muted">Raw Materials</small>
                    <h4 class="text-primary mb-0">$<?= number_format($ingredientValue, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center">
                    <small class="text-muted">Packaging</small>
                    <h4 class="text-warning mb-0">$<?= number_format($packagingValue, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-secondary">
                <div class="card-body text-center">
                    <small class="text-muted">Materials</small>
                    <h4 class="text-secondary mb-0">$<?= number_format($materialValue, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-success">
                <div class="card-body text-center">
                    <small class="text-muted">Finished Goods</small>
                    <h4 class="text-success mb-0">$<?= number_format($finishedGoodsValue, 2) ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Inventory Value -->
    <div class="alert alert-info">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-0"><i class="bi bi-stack"></i> Total Inventory Value</h5>
            </div>
            <div class="col-md-6 text-end">
                <h3 class="mb-0 text-primary">$<?= number_format($totalInventoryValue, 2) ?></h3>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- COGS by Product Group -->
        <div class="col-md-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-bar-chart"></i> COGS by Product Group (Last 30 Days)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (count($cogsByGroup) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product Group</th>
                                <th class="text-center">Work Orders</th>
                                <th class="text-end">Total COGS</th>
                                <th class="text-end">Avg per WO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cogsByGroup as $group): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($group['product_group_name']) ?></strong></td>
                                <td class="text-center"><?= $group['work_order_count'] ?></td>
                                <td class="text-end">$<?= number_format($group['total_cogs'], 2) ?></td>
                                <td class="text-end">$<?= number_format($group['avg_cogs'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <p>No completed work orders in the last 30 days</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Finished Goods by Value -->
        <div class="col-md-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-box-seam-fill"></i> Finished Goods Inventory (Top 20)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (count($finishedGoods) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Group</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Unit Cost</th>
                                <th class="text-end">Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($finishedGoods, 0, 20) as $product): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($product['sku']) ?></code></td>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td><small class="text-muted"><?= htmlspecialchars($product['product_group_name'] ?? 'N/A') ?></small></td>
                                <td class="text-center"><?= $product['stock_quantity'] ?></td>
                                <td class="text-end">$<?= number_format($product['unit_cost'], 2) ?></td>
                                <td class="text-end fw-bold">$<?= number_format($product['total_value'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <p>No finished goods inventory</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <a href="../index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
        <a href="./" class="btn btn-outline-primary ms-2">
            <i class="bi bi-folder2-open"></i> All Reports
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../../required/footer.php'; ?>
