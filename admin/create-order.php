<?php
$SEO = [
    'title' => 'Create Order — Admin',
    'description' => 'Manually create a new wholesale order.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wholesale_account_id = intval($_POST['wholesale_account_id'] ?? 0);
    $sales_agent_id = intval($_POST['sales_agent_id'] ?? 0);
    $status = $_POST['status'] ?? 'pending';
    $payment_status = $_POST['payment_status'] ?? 'pending';
    $payment_method = $_POST['payment_method'] ?? '';
    $shipping_method = $_POST['shipping_method'] ?? 'Standard';
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];
    
    if ($wholesale_account_id <= 0) {
        $error = 'Please select a wholesale account.';
    } elseif (empty($items)) {
        $error = 'Please add at least one item to the order.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Calculate totals
            $subtotal = 0;
            $validItems = [];
            
            // Get product prices for calculation
            $productPrices = [];
            $stmt = $pdo->query("SELECT id, wholesale_price FROM products WHERE active = 1");
            while ($row = $stmt->fetch()) {
                $productPrices[$row['id']] = $row['wholesale_price'];
            }
            
            foreach ($items as $item) {
                $product_id = intval($item['product_id'] ?? 0);
                $quantity = intval($item['quantity'] ?? 0);
                
                if ($product_id > 0 && $quantity > 0 && isset($productPrices[$product_id])) {
                    $unit_price = $productPrices[$product_id];
                    $line_total = $unit_price * $quantity;
                    $subtotal += $line_total;
                    
                    $validItems[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'unit_price' => $unit_price,
                        'line_total' => $line_total
                    ];
                }
            }
            
            if (empty($validItems)) {
                throw new Exception('No valid items found.');
            }
            
            // Calculate shipping and tax
            $shippingCost = $subtotal >= 500 ? 0 : 25;
            $taxRate = 0.08;
            $taxAmount = $subtotal * $taxRate;
            $total = $subtotal + $shippingCost + $taxAmount;
            
            // Generate order number
            $orderNumber = 'ORD-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Create order
            $stmt = $pdo->prepare('
                INSERT INTO orders (
                    order_number, user_id, wholesale_account_id, sales_agent_id,
                    subtotal, shipping, tax, total, status, payment_status,
                    payment_method, shipping_method, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            // Get user_id from wholesale account
            $stmt2 = $pdo->prepare("SELECT user_id FROM wholesale_accounts WHERE id = ?");
            $stmt2->execute([$wholesale_account_id]);
            $user_id = $stmt2->fetchColumn();
            
            $stmt->execute([
                $orderNumber,
                $user_id,
                $wholesale_account_id,
                $sales_agent_id > 0 ? $sales_agent_id : null,
                $subtotal,
                $shippingCost,
                $taxAmount,
                $total,
                $status,
                $payment_status,
                $payment_method,
                $shipping_method,
                $notes
            ]);
            
            $orderId = $pdo->lastInsertId();
            
            // Add order items - check if product_name and product_sku columns exist
            $columns = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'product_name'")->fetch();
            $hasProductNameColumn = !empty($columns);
            
            if ($hasProductNameColumn) {
                // Use the full schema with product_name and product_sku
                $stmt = $pdo->prepare('
                    INSERT INTO order_items (
                        order_id, product_id, product_name, product_sku,
                        quantity, unit_price, line_total
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                
                // Get product details for items
                $productDetails = [];
                $stmt2 = $pdo->query("SELECT id, name, sku FROM products WHERE active = 1");
                while ($row = $stmt2->fetch()) {
                    $productDetails[$row['id']] = $row;
                }
                
                foreach ($validItems as $item) {
                    $product = $productDetails[$item['product_id']];
                    $stmt->execute([
                        $orderId,
                        $item['product_id'],
                        $product['name'],
                        $product['sku'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['line_total']
                    ]);
                }
            } else {
                // Use the basic schema without product_name and product_sku
                $stmt = $pdo->prepare('
                    INSERT INTO order_items (
                        order_id, product_id, quantity, unit_price, line_total
                    ) VALUES (?, ?, ?, ?, ?)
                ');
                
                foreach ($validItems as $item) {
                    $stmt->execute([
                        $orderId,
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['line_total']
                    ]);
                }
            }
            
            // Calculate and create commissions
            if ($sales_agent_id > 0) {
                // Developer commission (5%)
                $developerRate = 5.0;
                $stmt = $pdo->query("SELECT setting_value FROM commission_settings WHERE setting_key = 'developer_commission'");
                $result = $stmt->fetch();
                if ($result) $developerRate = floatval($result['setting_value']);
                
                $developerUserId = 1; // Portfolio Developer - update with correct ID
                $developerCommission = $subtotal * ($developerRate / 100);
                
                $stmt = $pdo->prepare('
                    INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$orderId, $developerUserId, 'developer', $developerCommission, $developerRate, 'pending']);
                
                // Sales agent commission (10% default, or based on tier)
                $agentRate = 10.0;
                
                // Check agent's tier
                $stmt = $pdo->prepare('
                    SELECT COALESCE(SUM(o.total), 0) as total_sales
                    FROM orders o
                    WHERE o.sales_agent_id = ? AND o.status != "cancelled"
                ');
                $stmt->execute([$sales_agent_id]);
                $agentSales = $stmt->fetch();
                
                // Get appropriate tier
                $stmt = $pdo->prepare('
                    SELECT commission_rate FROM commission_tiers
                    WHERE min_sales <= ?
                    ORDER BY min_sales DESC
                    LIMIT 1
                ');
                $stmt->execute([$agentSales['total_sales']]);
                $tier = $stmt->fetch();
                if ($tier) $agentRate = floatval($tier['commission_rate']);
                
                $agentCommission = $subtotal * ($agentRate / 100);
                $stmt = $pdo->prepare('
                    INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$orderId, $sales_agent_id, 'sales_agent', $agentCommission, $agentRate, 'pending']);
            }
            
            $pdo->commit();
            
            // Generate accounting journal entry for revenue recognition
            try {
                require_once __DIR__ . '/../required/expense-automation.php';
                create_journal_entry_from_order($orderId, 'accrual');
            } catch (Exception $e) {
                // Continue even if journal entry fails
                error_log("Journal entry creation failed for order {$orderId}: " . $e->getMessage());
            }
            
            flash_set('success', "Order $orderNumber created successfully!");
            redirect(path_with_base('/admin/order-details.php') . "?id=$orderId");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating order: " . $e->getMessage();
        }
    }
}

// Fetch wholesale accounts
$stmt = $pdo->query("
    SELECT wa.id, wa.business_name, u.first_name, u.last_name, u.email
    FROM wholesale_accounts wa
    JOIN users u ON u.id = wa.user_id
    WHERE wa.approved = 1
    ORDER BY wa.business_name
");
$wholesaleAccounts = $stmt->fetchAll();

// Fetch sales agents
$stmt = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.name = 'sales_agent' AND u.status = 'active'
    ORDER BY u.first_name, u.last_name
");
$salesAgents = $stmt->fetchAll();

// Fetch products
$stmt = $pdo->query("
    SELECT id, sku, name, wholesale_price, msrp
    FROM products
    WHERE active = 1
    ORDER BY name
");
$products = $stmt->fetchAll();
?>

<style>
.create-order-header {
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.form-container {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    max-width: 1000px;
    margin: 0 auto;
}
.form-section {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid #e5e7eb;
}
.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}
.section-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #111827;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 4px;
    color: #374151;
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
}
.items-section {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}
.item-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto;
    gap: 12px;
    align-items: end;
    margin-bottom: 16px;
    padding: 16px;
    background: #f9fafb;
    border-radius: 8px;
}
.item-row:last-child {
    margin-bottom: 0;
}
.add-item-btn {
    background: #059669;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    margin-bottom: 16px;
}
.remove-item-btn {
    background: #dc2626;
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
}
.order-summary {
    background: #f0fdf4;
    border: 2px solid #86efac;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #d1fae5;
}
.summary-row:last-child {
    border-bottom: none;
    font-size: 18px;
    font-weight: 600;
    padding-top: 12px;
}
.create-btn {
    background: #059669;
    color: white;
    border: none;
    padding: 16px 32px;
    border-radius: 8px;
    font-size: 18px;
    cursor: pointer;
    width: 100%;
}
</style>

<div class="create-order-header">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 36px; margin-bottom: 8px;">Create New Order</h1>
                <p style="font-size: 18px; opacity: 0.9;">Manually create a wholesale order</p>
            </div>
            <div>
                <a href="<?php echo path_with_base('/admin/orders.php'); ?>" class="btn secondary">← Back to Orders</a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <form method="post" id="createOrderForm">
        <div class="form-container">
            <!-- Customer Selection -->
            <div class="form-section">
                <h2 class="section-title">Customer Information</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="wholesale_account_id">Wholesale Account *</label>
                        <select name="wholesale_account_id" id="wholesale_account_id" required>
                            <option value="">Select wholesale account...</option>
                            <?php foreach ($wholesaleAccounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>" <?php echo (intval($_POST['wholesale_account_id'] ?? 0) === $account['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['business_name'] . ' (' . $account['first_name'] . ' ' . $account['last_name'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="sales_agent_id">Sales Agent</label>
                        <select name="sales_agent_id" id="sales_agent_id">
                            <option value="">No agent assigned</option>
                            <?php foreach ($salesAgents as $agent): ?>
                            <option value="<?php echo $agent['id']; ?>" <?php echo (intval($_POST['sales_agent_id'] ?? 0) === $agent['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Order Details -->
            <div class="form-section">
                <h2 class="section-title">Order Details</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="status">Order Status</label>
                        <select name="status" id="status">
                            <option value="pending" <?php echo ($_POST['status'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="submitted" <?php echo ($_POST['status'] ?? '') === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                            <option value="paid" <?php echo ($_POST['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="fulfilled" <?php echo ($_POST['status'] ?? '') === 'fulfilled' ? 'selected' : ''; ?>>Fulfilled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_status">Payment Status</label>
                        <select name="payment_status" id="payment_status">
                            <option value="pending" <?php echo ($_POST['payment_status'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="paid" <?php echo ($_POST['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="failed" <?php echo ($_POST['payment_status'] ?? '') === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <input type="text" name="payment_method" id="payment_method" 
                               value="<?php echo htmlspecialchars($_POST['payment_method'] ?? 'Net Terms'); ?>" 
                               placeholder="Net Terms, Credit Card, etc.">
                    </div>
                    <div class="form-group">
                        <label for="shipping_method">Shipping Method</label>
                        <select name="shipping_method" id="shipping_method">
                            <option value="Standard" <?php echo ($_POST['shipping_method'] ?? 'Standard') === 'Standard' ? 'selected' : ''; ?>>Standard</option>
                            <option value="Express" <?php echo ($_POST['shipping_method'] ?? '') === 'Express' ? 'selected' : ''; ?>>Express</option>
                            <option value="Priority" <?php echo ($_POST['shipping_method'] ?? '') === 'Priority' ? 'selected' : ''; ?>>Priority</option>
                            <option value="Free Shipping" <?php echo ($_POST['shipping_method'] ?? '') === 'Free Shipping' ? 'selected' : ''; ?>>Free Shipping</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="notes">Order Notes</label>
                    <textarea name="notes" id="notes" rows="3" placeholder="Internal notes about this order..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <!-- Order Items -->
            <div class="form-section">
                <h2 class="section-title">Order Items</h2>
                <div class="items-section">
                    <button type="button" class="add-item-btn" onclick="addItem()">+ Add Item</button>
                    <div id="items-container">
                        <!-- Items will be added here dynamically -->
                    </div>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div class="order-summary" id="orderSummary" style="display: none;">
                <h3 style="margin-bottom: 16px;">Order Summary</h3>
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="subtotalAmount">$0.00</span>
                </div>
                <div class="summary-row">
                    <span>Shipping</span>
                    <span id="shippingAmount">$25.00</span>
                </div>
                <div class="summary-row">
                    <span>Tax (8%)</span>
                    <span id="taxAmount">$0.00</span>
                </div>
                <div class="summary-row">
                    <span>Total</span>
                    <span id="totalAmount">$0.00</span>
                </div>
            </div>
            
            <button type="submit" class="create-btn">Create Order</button>
        </div>
    </form>
</div>

<script>
let itemCount = 0;
const products = <?php echo json_encode($products); ?>;

function addItem() {
    itemCount++;
    const container = document.getElementById('items-container');
    const itemHtml = `
        <div class="item-row" id="item-${itemCount}">
            <div>
                <label>Product</label>
                <select name="items[${itemCount}][product_id]" onchange="updateCalculations()" required>
                    <option value="">Select product...</option>
                    ${products.map(p => `<option value="${p.id}" data-price="${p.wholesale_price}">${p.name} - $${p.wholesale_price}</option>`).join('')}
                </select>
            </div>
            <div>
                <label>Quantity</label>
                <input type="number" name="items[${itemCount}][quantity]" min="1" value="1" onchange="updateCalculations()" required>
            </div>
            <div>
                <label>Unit Price</label>
                <input type="text" readonly class="unit-price" value="$0.00">
            </div>
            <div>
                <label>Line Total</label>
                <input type="text" readonly class="line-total" value="$0.00">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="button" class="remove-item-btn" onclick="removeItem(${itemCount})">Remove</button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', itemHtml);
    updateCalculations();
}

function removeItem(itemId) {
    document.getElementById(`item-${itemId}`).remove();
    updateCalculations();
}

function updateCalculations() {
    let subtotal = 0;
    const itemRows = document.querySelectorAll('.item-row');
    
    itemRows.forEach(row => {
        const productSelect = row.querySelector('select[name*="[product_id]"]');
        const quantityInput = row.querySelector('input[name*="[quantity]"]');
        const unitPriceInput = row.querySelector('.unit-price');
        const lineTotalInput = row.querySelector('.line-total');
        
        if (productSelect && quantityInput) {
            const selectedOption = productSelect.selectedOptions[0];
            const quantity = parseInt(quantityInput.value) || 0;
            
            if (selectedOption && selectedOption.dataset.price) {
                const unitPrice = parseFloat(selectedOption.dataset.price);
                const lineTotal = unitPrice * quantity;
                
                unitPriceInput.value = `$${unitPrice.toFixed(2)}`;
                lineTotalInput.value = `$${lineTotal.toFixed(2)}`;
                subtotal += lineTotal;
            } else {
                unitPriceInput.value = '$0.00';
                lineTotalInput.value = '$0.00';
            }
        }
    });
    
    const shipping = subtotal >= 500 ? 0 : 25;
    const tax = subtotal * 0.08;
    const total = subtotal + shipping + tax;
    
    document.getElementById('subtotalAmount').textContent = `$${subtotal.toFixed(2)}`;
    document.getElementById('shippingAmount').textContent = shipping > 0 ? `$${shipping.toFixed(2)}` : 'FREE';
    document.getElementById('taxAmount').textContent = `$${tax.toFixed(2)}`;
    document.getElementById('totalAmount').textContent = `$${total.toFixed(2)}`;
    
    const summary = document.getElementById('orderSummary');
    if (subtotal > 0) {
        summary.style.display = 'block';
    } else {
        summary.style.display = 'none';
    }
}

// Add first item on page load
document.addEventListener('DOMContentLoaded', function() {
    addItem();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
