<?php
$SEO = [
    'title' => 'Profit Calculator — Product Customer Portal',
    'description' => 'Calculate your profit potential with Product formulas',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

// Check if user is logged in
$uid = current_user_id();
if (!$uid) {
    flash_set('error', 'Please log in to access the customer portal.');
    redirect('/login.php');
}

// Check if user has wholesale account
$pdo = db();
$stmt = $pdo->prepare('
    SELECT wa.*, u.first_name, u.last_name 
    FROM wholesale_accounts wa 
    JOIN users u ON u.id = wa.user_id 
    WHERE wa.user_id = ? AND wa.approved = 1
');
$stmt->execute([$uid]);
$wholesaleAccount = $stmt->fetch();

if (!$wholesaleAccount) {
    flash_set('error', 'Wholesale account required to access this tool.');
    redirect('/customer/');
}
?>

<style>
.calculator-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
}
.calculator-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 32px;
    margin-bottom: 24px;
}
.formula-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 32px;
}
.formula-option {
    padding: 20px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
}
.formula-option.active {
    border-color: #059669;
    background: #f0fdf4;
}
.formula-option:hover {
    border-color: #10b981;
}
.input-group {
    margin-bottom: 20px;
}
.input-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
}
.input-group input, .input-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 16px;
}
.results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 32px;
}
.result-card {
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    padding: 20px;
    border-radius: 12px;
    text-align: center;
}
.result-value {
    font-size: 28px;
    font-weight: bold;
    color: #059669;
    margin-bottom: 8px;
}
.result-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
}
.profit-highlight {
    background: linear-gradient(135deg, #fef3c7, #fed7aa);
    border: 2px solid #f59e0b;
    padding: 24px;
    border-radius: 12px;
    margin-top: 24px;
    text-align: center;
}
.profit-highlight h3 {
    color: #92400e;
    margin-bottom: 16px;
}
.order-button {
    background: linear-gradient(135deg, #059669, #10b981);
    color: white;
    border: none;
    padding: 16px 32px;
    border-radius: 8px;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 20px;
    text-decoration: none;
    display: inline-block;
}
.order-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(5, 150, 105, 0.3);
}
</style>

<div class="calculator-container">
    <div class="calculator-card">
        <h1 style="color: #059669; margin-bottom: 8px;">💰 Your Profit Calculator</h1>
        <p style="color: #6b7280; margin-bottom: 32px;">Calculate your exact profit potential with Product formulas</p>
        
        <!-- Welcome Message -->
        <div style="background: #f0f9ff; padding: 20px; border-radius: 12px; margin-bottom: 32px; border-left: 4px solid #3b82f6;">
            <h3 style="color: #1e40af; margin-bottom: 8px;">Welcome, <?php echo htmlspecialchars($wholesaleAccount['business_name']); ?>!</h3>
            <p style="color: #6b7280;">Use this calculator to see exactly how much profit you can make with our product formulas.</p>
        </div>
        
        <!-- Formula Selection -->
        <div class="formula-selector">
            <div class="formula-option active" onclick="selectFormula('blend-75')" id="blend-75-option">
                <h3 style="color: #059669; margin-bottom: 8px;">75mg Blend Series</h3>
                <p style="margin-bottom: 8px;">75mg attribute per fl oz</p>
                <p style="color: #6b7280; font-size: 14px;">High-product specification commercial grade</p>
                <p style="font-weight: 600; color: #059669;">Maximum efficiency</p>
            </div>
            <div class="formula-option" onclick="selectFormula('blend-20')" id="blend-20-option">
                <h3 style="color: #059669; margin-bottom: 8px;">20mg Blend Series</h3>
                <p style="margin-bottom: 8px;">20mg attribute per fl oz</p>
                <p style="color: #6b7280; font-size: 14px;">Standard product specification formula</p>
                <p style="font-weight: 600; color: #059669;">Great value</p>
            </div>
        </div>
        
        <!-- Calculator Inputs -->
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px;">
            <div class="input-group">
                <label for="bottle-size">Bottle Size</label>
                <select id="bottle-size" onchange="updateCalculation()">
                    <option value="32">32oz</option>
                    <option value="128">128oz</option>
                </select>
            </div>
            <div class="input-group">
                <label for="drink-price">Your Drink Price ($)</label>
                <input type="number" id="drink-price" value="12" step="0.50" min="1" onchange="updateCalculation()">
            </div>
            <div class="input-group">
                <label for="serving-size">attribute per Serving (mg)</label>
                <input type="number" id="serving-size" value="10" step="1" min="5" max="30" onchange="updateCalculation()">
            </div>
            <div class="input-group">
                <label for="unit-toggle">Measurement Units</label>
                <select id="unit-toggle" onchange="updateCalculation()">
                    <option value="us">🇺🇸 US (Fluid Ounces)</option>
                    <option value="metric">🌍 Metric (Milliliters)</option>
                </select>
            </div>
        </div>
        
        <!-- Results -->
        <div class="results-grid" id="results-grid">
            <!-- Results will be populated by JavaScript -->
        </div>
        
        <!-- Profit Highlight -->
        <div class="profit-highlight" id="profit-highlight">
            <!-- Profit summary will be populated by JavaScript -->
            <a href="<?php echo path_with_base('/shop/'); ?>" class="order-button">Order Now & Start Profiting</a>
        </div>
        
        <!-- Comparison Table -->
        <div style="background: #f9fafb; padding: 24px; border-radius: 12px; margin-top: 24px;">
            <h3 style="margin-bottom: 16px;">📊 Quick Comparison: Traditional vs Product</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #374151; color: white;">
                            <th style="padding: 12px; text-align: left;">Method</th>
                            <th style="padding: 12px; text-align: center;">Consistency</th>
                            <th style="padding: 12px; text-align: center;">Prep Time</th>
                            <th style="padding: 12px; text-align: center;">Waste</th>
                            <th style="padding: 12px; text-align: center;">Customer Satisfaction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #fee2e2;">
                            <td style="padding: 12px; font-weight: 600;">Traditional product format</td>
                            <td style="padding: 12px; text-align: center;">❌ Variable</td>
                            <td style="padding: 12px; text-align: center;">❌ 2-3 minutes</td>
                            <td style="padding: 12px; text-align: center;">❌ High</td>
                            <td style="padding: 12px; text-align: center;">❌ Inconsistent</td>
                        </tr>
                        <tr style="background: #dcfce7;">
                            <td style="padding: 12px; font-weight: 600;">Product</td>
                            <td style="padding: 12px; text-align: center;">✅ Perfect</td>
                            <td style="padding: 12px; text-align: center;">✅ 30 seconds</td>
                            <td style="padding: 12px; text-align: center;">✅ Zero</td>
                            <td style="padding: 12px; text-align: center;">✅ Guaranteed</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let currentFormula = 'blend-75';

// Formula data matching spreadsheet exactly
const formulas = {
    'blend-75': {
        attributePerOz: 75,
        totalMg: {32: 2400, 128: 9600},
        prices: {32: 169.95, 128: 519.95},
        servings: {32: 240, 128: 960},
        ozPerServing: {32: 0.50, 128: 0.25}
    },
    'blend-20': {
        attributePerOz: 20,
        totalMg: {32: 640, 128: 2560},
        prices: {32: 79.95, 128: 179.95},
        servings: {32: 64, 128: 256},
        ozPerServing: {32: 0.5, 128: 0.25}
    }
};

function selectFormula(formula) {
    currentFormula = formula;
    
    // Update visual selection
    document.querySelectorAll('.formula-option').forEach(el => el.classList.remove('active'));
    document.getElementById(formula + '-option').classList.add('active');
    
    updateCalculation();
}

function updateCalculation() {
    const bottleSize = parseInt(document.getElementById('bottle-size').value);
    const drinkPrice = parseFloat(document.getElementById('drink-price').value);
    const servingMit = parseInt(document.getElementById('serving-size').value);
    const unitSystem = document.getElementById('unit-toggle').value;
    
    const formula = formulas[currentFormula];
    const wholesalePrice = formula.prices[bottleSize];
    const totalAttribute = formula.totalMg[bottleSize];
    
    // Calculate based on actual attribute content (matching spreadsheet)
    const totalServings = Math.floor(totalAttribute / servingMit);
    const ozPerServing = formula.ozPerServing[bottleSize];
    
    // Convert to appropriate units
    let servingVolume, volumeLabel;
    if (unitSystem === 'us') {
        servingVolume = ozPerServing;
        volumeLabel = 'fl oz';
    } else {
        servingVolume = ozPerServing * 29.5735; // Convert to ml
        volumeLabel = 'ml';
    }
    
    // Financial calculations
    const totalRevenue = totalServings * drinkPrice;
    const totalProfit = totalRevenue - wholesalePrice;
    const profitMargin = ((totalProfit / wholesalePrice) * 100);
    const revenuePerOz = (totalRevenue / bottleSize);
    const costPerServing = wholesalePrice / totalServings;
    const profitPerServing = drinkPrice - costPerServing;
    const profitPerDollarInvested = totalProfit / wholesalePrice;
    
    const resultsHTML = `
        <div class="result-card">
            <div class="result-value">${totalServings}</div>
            <div class="result-label">Total Drinks</div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">${servingMit}mg attribute each</div>
        </div>
        <div class="result-card">
            <div class="result-value">${servingVolume.toFixed(2)} ${volumeLabel}</div>
            <div class="result-label">Per Drink</div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Serving size</div>
        </div>
        <div class="result-card">
            <div class="result-value">$${totalRevenue.toLocaleString()}</div>
            <div class="result-label">Total Revenue</div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">${totalServings} × $${drinkPrice}</div>
        </div>
        <div class="result-card">
            <div class="result-value" style="color: #059669;">$${totalProfit.toLocaleString()}</div>
            <div class="result-label">Your Profit</div>
            <div style="font-size: 11px; color: #059669; margin-top: 4px;">After $${wholesalePrice.toFixed(2)} investment</div>
        </div>
        <div class="result-card">
            <div class="result-value" style="color: #dc2626;">${profitMargin.toFixed(0)}%</div>
            <div class="result-label">Return on Investment</div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Profit margin</div>
        </div>
        <div class="result-card">
            <div class="result-value">$${profitPerServing.toFixed(2)}</div>
            <div class="result-label">Profit per Drink</div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Your earnings each</div>
        </div>
    `;
    
    document.getElementById('results-grid').innerHTML = resultsHTML;
    
    // Update profit highlight with clearer breakdown
    const profitHTML = `
        <h3>🎯 Your Profit Breakdown</h3>
        <div style="background: white; padding: 20px; border-radius: 12px; margin: 16px 0; border: 2px solid #059669;">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 16px;">
                <div style="text-align: center;">
                    <div style="font-size: 16px; color: #6b7280;">Initial Investment</div>
                    <div style="font-size: 24px; font-weight: bold; color: #dc2626;">$${wholesalePrice.toFixed(2)}</div>
                    <div style="font-size: 12px; color: #6b7280;">One-time purchase</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 16px; color: #6b7280;">Total Revenue</div>
                    <div style="font-size: 24px; font-weight: bold; color: #3b82f6;">$${totalRevenue.toLocaleString()}</div>
                    <div style="font-size: 12px; color: #6b7280;">${totalServings} drinks @ $${drinkPrice} each</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 16px; color: #6b7280;">Net Profit</div>
                    <div style="font-size: 24px; font-weight: bold; color: #059669;">$${totalProfit.toLocaleString()}</div>
                    <div style="font-size: 12px; color: #059669;">${profitMargin.toFixed(0)}% return</div>
                </div>
            </div>
            
            <div style="text-align: center; padding: 16px; background: #f0fdf4; border-radius: 8px;">
                <p style="margin: 0; font-size: 18px; color: #065f46;">
                    <strong>Bottom Line:</strong> Every $1 invested returns $${(profitPerDollarInvested + 1).toFixed(2)}
                </p>
                <p style="margin: 8px 0 0 0; font-size: 14px; color: #6b7280;">
                    That's $${profitPerServing.toFixed(2)} profit on every single drink you sell!
                </p>
            </div>
        </div>
        <a href="/shop/" class="order-button">Order Now & Start Profiting</a>
    `;
    
    document.getElementById('profit-highlight').innerHTML = profitHTML;
}

// Initialize calculation
updateCalculation();
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
