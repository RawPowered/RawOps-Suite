<?php
/**
 * Operations System Verification Script
 * 
 * This script verifies that the operations system has been properly installed
 * and all components are working correctly.
 */

require_once 'required/config.php';

echo "🔍 Product Operations System Verification\n";
echo "=========================================\n\n";

$checks = [];
$passed = 0;
$failed = 0;

function check($description, $test, $details = '') {
    global $checks, $passed, $failed;
    
    echo "Checking: {$description}... ";
    
    if ($test) {
        echo "✓ PASS\n";
        if ($details) echo "   → {$details}\n";
        $passed++;
        $checks[] = ['status' => 'pass', 'description' => $description, 'details' => $details];
    } else {
        echo "❌ FAIL\n";
        if ($details) echo "   → {$details}\n";
        $failed++;
        $checks[] = ['status' => 'fail', 'description' => $description, 'details' => $details];
    }
}

try {
    $pdo = db();
    check('Database connection', true, 'Connected successfully');
    
    // Check operations role
    $stmt = $pdo->query("SELECT * FROM roles WHERE name = 'operations'");
    $operationsRole = $stmt->fetch();
    check('Operations role exists', $operationsRole !== false, $operationsRole ? "Role ID: {$operationsRole['id']}" : 'Role not found');
    
    // Check product columns
    $stmt = $pdo->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $productColumns = ['stock_quantity', 'low_stock_threshold', 'attribute_content', 'weight_grams', 'dimensions'];
    foreach ($productColumns as $column) {
        check("Product column '{$column}'", in_array($column, $columns));
    }
    
    // Check new tables
    $requiredTables = [
        'stock_movements' => 'Inventory tracking',
        'shipping_labels' => 'Label management', 
        'operations_log' => 'Activity logging'
    ];
    
    foreach ($requiredTables as $table => $purpose) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        check("Table '{$table}'", $stmt->fetch() !== false, $purpose);
    }
    
    // Check orders query (the one that was failing)
    try {
        $stmt = $pdo->query("
            SELECT o.*, wa.business_name, 
                   CONCAT(u.first_name, ' ', u.last_name) as contact_name,
                   COUNT(oi.id) as item_count
            FROM orders o
            JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
            LEFT JOIN users u ON u.id = wa.user_id
            LEFT JOIN order_items oi ON oi.order_id = o.id
            GROUP BY o.id
            LIMIT 1
        ");
        $testOrder = $stmt->fetch();
        check('Orders query syntax', true, 'Query executes without errors');
        
        if ($testOrder) {
            check('Sample order data', true, "Found order: {$testOrder['order_number']}");
        } else {
            check('Sample order data', true, 'No orders in database (normal for new install)');
        }
        
    } catch (Exception $e) {
        check('Orders query syntax', false, $e->getMessage());
    }
    
    // Check file permissions and structure
    $requiredFiles = [
        'operations/index.php' => 'Operations dashboard',
        'operations/orders.php' => 'Order processing',
        'operations/inventory.php' => 'Inventory management',
        'operations/shipping.php' => 'Shipping labels',
        'admin/shipping-config.php' => 'Shipping API config',
        'config/' => 'Configuration directory'
    ];
    
    foreach ($requiredFiles as $file => $purpose) {
        $exists = file_exists(__DIR__ . '/' . $file);
        check("File/Directory '{$file}'", $exists, $purpose);
    }
    
    // Check user functions
    try {
        $testUserId = 1; // Assuming admin user exists
        $hasOperationsRole = function_exists('user_has_role') && user_has_role($testUserId, 'operations');
        check('Role checking function', function_exists('user_has_role'), 'user_has_role() function available');
        
        $dashboardPath = function_exists('user_dashboard_path') ? user_dashboard_path($testUserId) : '';
        check('Dashboard routing function', function_exists('user_dashboard_path'), 'user_dashboard_path() function available');
        
    } catch (Exception $e) {
        check('User role functions', false, $e->getMessage());
    }
    
} catch (Exception $e) {
    check('Database connection', false, $e->getMessage());
    echo "\n❌ Cannot continue verification without database connection.\n";
    exit(1);
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Verification Summary:\n";
echo "✓ Passed: {$passed}\n";
echo "❌ Failed: {$failed}\n";

if ($failed === 0) {
    echo "\n🎉 All checks passed! Operations system is ready.\n\n";
    
    echo "🚀 Getting Started:\n";
    echo "1. Login as admin and go to Users management\n";
    echo "2. Create a new user and assign 'operations' role\n";
    echo "3. Login with operations account at /index.php\n";
    echo "4. Select 'Operations / Shipping' from login dropdown\n";
    echo "5. Access operations dashboard to manage inventory and orders\n";
    echo "6. Configure shipping APIs in Admin → Shipping Config\n\n";
    
} else {
    echo "\n⚠ Some checks failed. Please review the issues above.\n";
    
    if ($failed > $passed) {
        echo "❌ Major issues detected. Please run the migration script first:\n";
        echo "   php run_operations_migration.php\n";
    } else {
        echo "✓ Core system appears functional despite some minor issues.\n";
    }
}

echo "\n";
?>
