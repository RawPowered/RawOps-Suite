<?php
$SEO = [
    'title' => 'Sales Reference — Product Sales Tools',
    'description' => 'Quick reference for product pricing, bulk discounts, and specifications',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

// Check if user is logged in and is admin/sales agent
$uid = current_user_id();
if (!$uid) {
    flash_set('error', 'Please log in to access this tool.');
    redirect('/login.php');
}

// Check user role
$pdo = db();
$stmt = $pdo->prepare('
    SELECT r.name as role_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE u.id = ?
');
$stmt->execute([$uid]);
$roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('admin', $roles) && !in_array('sales_agent', $roles)) {
    flash_set('error', 'Access denied. Sales agents and admins only.');
    redirect('/');
}

// Load dynamic product data from database
require_once __DIR__ . '/../required/product-data-dynamic.php';
?>

<style>
.sales-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.reference-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}

.card-header {
    background: linear-gradient(135deg, #059669, #10b981);
    color: white;
    padding: 20px 24px;
    border-radius: 12px 12px 0 0;
}

.card-body {
    padding: 24px;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.product-card {
    background: #f9fafb;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s;
}

.product-card:hover {
    border-color: #059669;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.15);
}

.product-name {
    font-size: 20px;
    font-weight: bold;
    color: #1f2937;
    margin-bottom: 12px;
}

.product-specs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}

.spec-item {
    text-align: center;
    padding: 8px;
    background: white;
    border-radius: 6px;
}

.spec-label {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.spec-value {
    font-size: 16px;
    font-weight: bold;
    color: #059669;
}

.pricing-section {
    border-top: 1px solid #e5e7eb;
    padding-top: 16px;
}

.price-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.msrp {
    color: #6b7280;
    text-decoration: line-through;
}

.sale-price {
    font-size: 24px;
    font-weight: bold;
    color: #dc2626;
}

.bulk-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.bulk-table th,
.bulk-table td {
    padding: 12px;
    text-align: center;
    border: 1px solid #e5e7eb;
}

.bulk-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
}

.discount-badge {
    background: #dc2626;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.ppu-value {
    color: #059669;
    font-weight: 600;
}

.quick-calc {
    background: linear-gradient(135deg, #fef3c7, #fed7aa);
    padding: 20px;
    border-radius: 12px;
    margin-top: 24px;
}

.calc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.calc-item {
    background: white;
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}

.calc-value {
    font-size: 20px;
    font-weight: bold;
    color: #059669;
    margin-bottom: 4px;
}

.calc-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
}

.copy-button {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    margin-left: 12px;
    font-size: 12px;
}

.copy-button:hover {
    background: #2563eb;
}

.product specification-indicator {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
}

.product specification-standard {
    background: #dbeafe;
    color: #1e40af;
}

.product specification-high {
    background: #fef2f2;
    color: #dc2626;
}
</style>

<div class="sales-container">
    <div class="reference-card">
        <div class="card-header">
            <h1 style="margin: 0; font-size: 28px;">📋 Sales Reference Dashboard</h1>
            <p style="margin: 8px 0 0 0; opacity: 0.9;">Quick access to product specs, pricing, and bulk discounts</p>
        </div>
    </div>
    
    <!-- Product Grid -->
    <div class="products-grid">
        <?php foreach ($products as $product): ?>
            <div class="product-card">
                <div class="product-name">
                    <?php echo $product['name']; ?>
                    <span class="product specification-indicator <?php echo $product['mg_per_oz'] >= 50 ? 'product specification-high' : 'product specification-standard'; ?>">
                        <?php echo $product['mg_per_oz'] >= 50 ? 'HIGH product specification' : 'STANDARD'; ?>
                    </span>
                </div>
                
                <div class="product-specs">
                    <div class="spec-item">
                        <div class="spec-label">Total attribute</div>
                        <div class="spec-value"><?php echo number_format($product['total_mg']); ?>mg</div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-label">Per Ounce</div>
                        <div class="spec-value"><?php echo $product['mg_per_oz']; ?>mg/oz</div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-label">Bottle Size</div>
                        <div class="spec-value"><?php echo $product['bottle_size']; ?>oz</div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-label">SKU</div>
                        <div class="spec-value" style="font-size: 12px;"><?php echo $product['sku']; ?></div>
                    </div>
                </div>
                
                <div class="pricing-section">
                    <div class="price-row">
                        <span>MSRP:</span>
                        <span class="msrp">$<?php echo number_format($product['msrp'], 2); ?></span>
                    </div>
                    <div class="price-row">
                        <span><strong>Your Price:</strong></span>
                        <span class="sale-price">$<?php echo number_format($product['sale_price'], 2); ?></span>
                    </div>
                    <div class="price-row">
                        <span>Per MG:</span>
                        <span class="ppu-value">$<?php echo number_format($product['sale_price'] / $product['total_mg'], 4); ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Bulk Pricing Table -->
    <div class="reference-card">
        <div class="card-header">
            <h2 style="margin: 0;">💰 Bulk Discount Structure</h2>
            <p style="margin: 8px 0 0 0; opacity: 0.9;">Volume discounts and per-unit pricing</p>
        </div>
        <div class="card-body">
            <table class="bulk-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Bottle Size</th>
                        <th>Base Price</th>
                        <th>X5 (5% off)</th>
                        <th>X10 (8% off)</th>
                        <th>X15 (11% off)</th>
                        <th>X20 (15% off)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><strong><?php echo $product['name']; ?></strong></td>
                            <td><?php echo $product['bottle_size']; ?>oz</td>
                            <td>$<?php echo number_format($product['sale_price'], 2); ?></td>
                            <?php foreach ($bulk_discounts as $discount): ?>
                                <?php 
                                $discounted_price = $product['sale_price'] * (1 - $discount['discount'] / 100);
                                ?>
                                <td>
                                    <div style="font-weight: bold;">$<?php echo number_format($discounted_price, 2); ?></div>
                                    <div class="discount-badge">-<?php echo $discount['discount']; ?>%</div>
                                    <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                                        PPU: $<?php echo number_format($discounted_price, 2); ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Quick Calculator -->
    <div class="quick-calc">
        <h3 style="margin: 0 0 16px 0; color: #92400e;">🧮 Quick Order Calculator</h3>
        <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
            <select id="calc-product" style="padding: 8px 12px; border-radius: 6px;">
                <option value="">Select Product</option>
                <?php foreach ($products as $i => $product): ?>
                    <option value="<?php echo $i; ?>"><?php echo $product['name']; ?> - $<?php echo number_format($product['sale_price'], 2); ?></option>
                <?php endforeach; ?>
            </select>
            
            <input type="number" id="calc-quantity" placeholder="Quantity" min="1" style="padding: 8px 12px; border-radius: 6px; width: 100px;">
            
            <button onclick="calculateOrder()" style="padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; cursor: pointer;">Calculate</button>
            
            <button onclick="copyCalculation()" class="copy-button" style="display: none;" id="copy-calc">Copy Result</button>
        </div>
        
        <div id="calc-results" class="calc-grid" style="display: none;">
            <!-- Results populated by JavaScript -->
        </div>
    </div>
    
    <!-- Sales Scripts -->
    <div class="reference-card">
        <div class="card-header">
            <h2 style="margin: 0;">💬 Quick Sales Scripts</h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h4 style="color: #059669;">🎯 Opening Script</h4>
                    <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border-left: 4px solid #059669;">
                        <p style="margin: 0; font-style: italic;">
                            "I have the perfect product solution for your bar. Our catalog-ready blends give you consistent product specification every time - no more guessing with product format. Would you like to see how much profit you could make?"
                        </p>
                        <button onclick="copyScript('opening')" class="copy-button">Copy</button>
                    </div>
                </div>
                
                <div>
                    <h4 style="color: #059669;">💰 Value Proposition</h4>
                    <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border-left: 4px solid #059669;">
                        <p style="margin: 0; font-style: italic;">
                            "Our blends eliminate waste and ensure every drink is perfect. Plus, with our bulk pricing, you save up to 15% on larger orders. Your customers get consistent effects, you get reliable profits."
                        </p>
                        <button onclick="copyScript('value')" class="copy-button">Copy</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const products = <?php echo json_encode($products); ?>;
const bulkDiscounts = <?php echo json_encode($bulk_discounts); ?>;

function calculateOrder() {
    const productIndex = document.getElementById('calc-product').value;
    const quantity = parseInt(document.getElementById('calc-quantity').value);
    
    if (productIndex === '' || !quantity) {
        alert('Please select a product and enter quantity');
        return;
    }
    
    const product = products[productIndex];
    let discount = 0;
    let discountLabel = '';
    
    // Determine bulk discount
    if (quantity >= 20) {
        discount = 15;
        discountLabel = 'X20 Discount (15% off)';
    } else if (quantity >= 15) {
        discount = 11;
        discountLabel = 'X15 Discount (11% off)';
    } else if (quantity >= 10) {
        discount = 8;
        discountLabel = 'X10 Discount (8% off)';
    } else if (quantity >= 5) {
        discount = 5;
        discountLabel = 'X5 Discount (5% off)';
    }
    
    const basePrice = product.sale_price;
    const discountedPrice = basePrice * (1 - discount / 100);
    const totalPrice = discountedPrice * quantity;
    const savings = (basePrice - discountedPrice) * quantity;
    
    const resultsHTML = `
        <div class="calc-item">
            <div class="calc-value">${quantity}</div>
            <div class="calc-label">Bottles</div>
        </div>
        <div class="calc-item">
            <div class="calc-value">$${discountedPrice.toFixed(2)}</div>
            <div class="calc-label">Per Unit</div>
        </div>
        <div class="calc-item">
            <div class="calc-value">$${totalPrice.toFixed(2)}</div>
            <div class="calc-label">Total Price</div>
        </div>
        ${savings > 0 ? `
        <div class="calc-item">
            <div class="calc-value" style="color: #dc2626;">$${savings.toFixed(2)}</div>
            <div class="calc-label">You Save</div>
        </div>
        ` : ''}
        ${discountLabel ? `
        <div class="calc-item">
            <div class="calc-value" style="color: #059669;">${discount}%</div>
            <div class="calc-label">${discountLabel}</div>
        </div>
        ` : ''}
    `;
    
    document.getElementById('calc-results').innerHTML = resultsHTML;
    document.getElementById('calc-results').style.display = 'grid';
    document.getElementById('copy-calc').style.display = 'inline-block';
    
    // Store result for copying
    window.lastCalculation = {
        product: product.name,
        quantity: quantity,
        unitPrice: discountedPrice.toFixed(2),
        totalPrice: totalPrice.toFixed(2),
        savings: savings.toFixed(2),
        discount: discountLabel
    };
}

function copyCalculation() {
    const calc = window.lastCalculation;
    const text = `${calc.product} Quote:
${calc.quantity} bottles × $${calc.unitPrice} = $${calc.totalPrice}
${calc.savings > 0 ? `Savings: $${calc.savings} (${calc.discount})` : 'Regular pricing'}
Total attribute: ${(products[document.getElementById('calc-product').value].total_mg * calc.quantity).toLocaleString()}mg`;
    
    navigator.clipboard.writeText(text).then(() => {
        const button = document.getElementById('copy-calc');
        const originalText = button.textContent;
        button.textContent = 'Copied!';
        button.style.background = '#10b981';
        setTimeout(() => {
            button.textContent = originalText;
            button.style.background = '#3b82f6';
        }, 2000);
    });
}

function copyScript(type) {
    const scripts = {
        opening: "I have the perfect product solution for your bar. Our catalog-ready blends give you consistent product specification every time - no more guessing with product format. Would you like to see how much profit you could make?",
        value: "Our blends eliminate waste and ensure every drink is perfect. Plus, with our bulk pricing, you save up to 15% on larger orders. Your customers get consistent effects, you get reliable profits."
    };
    
    navigator.clipboard.writeText(scripts[type]).then(() => {
        const button = event.target;
        const originalText = button.textContent;
        button.textContent = 'Copied!';
        button.style.background = '#10b981';
        setTimeout(() => {
            button.textContent = originalText;
            button.style.background = '#3b82f6';
        }, 2000);
    });
}

// Auto-refresh pricing data every 5 minutes
setInterval(() => {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 300000);
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>