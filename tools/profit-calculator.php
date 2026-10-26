<?php
$SEO = [
    'title' => 'Profit Calculator — Product Sales Tool (Public)',
    'description' => 'Calculate customer profit potential with Product formulas',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

// Load dynamic product data from database
require_once __DIR__ . '/../required/product-data-dynamic.php';

// Build JavaScript-friendly product data
$productsJson = json_encode($products);
?>

<style>
.calculator-container {
    max-width: 1200px;
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
.formula-option.active { border-color: #059669; background: #f0fdf4; }
.formula-option:hover { border-color: #10b981; }
.input-group { margin-bottom: 20px; }
.input-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
.input-group input, .input-group select { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; }
.results-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 32px; }
.result-card { background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 20px; border-radius: 12px; text-align: center; }
.result-value { font-size: 32px; font-weight: bold; color: #059669; margin-bottom: 8px; }
.result-label { color: #6b7280; font-size: 14px; text-transform: uppercase; }
.profit-highlight { background: linear-gradient(135deg, #fef3c7, #fed7aa); border: 2px solid #f59e0b; padding: 24px; border-radius: 12px; margin-top: 24px; text-align: center; }
.profit-highlight h3 { color: #92400e; margin-bottom: 16px; }
.copy-button { background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin-top: 12px; }
.copy-button:hover { background: #2563eb; }
</style>

<div class="calculator-container">
    <div class="calculator-card">
        <h1 style="color: #059669; margin-bottom: 8px;">💰 Product Profit Calculator</h1>
        <p style="color: #6b7280; margin-bottom: 32px;">Public calculator — share this link with any sales rep.</p>
        
        <div class="formula-selector">
            <?php 
            // Group products by product_specification (attribute_per_oz)
            $productsByattribute = [];
            foreach ($products as $p) {
                $key = $p['mg_per_oz'];
                if (!isset($productsByattribute[$key])) {
                    $productsByattribute[$key] = [];
                }
                $productsByattribute[$key][] = $p;
            }
            ksort($productsByattribute);
            
            $first = true;
            foreach ($productsByattribute as $product_specification => $prods):
                $sku = $prods[0]['sku'];
                $activeClass = $first ? 'active' : '';
                $first = false;
                $description = $product_specification >= 60 ? 'High-product_specification commercial grade' : 'Standard product_specification formula';
            ?>
            <div class="formula-option <?php echo $activeClass; ?>" onclick="selectProduct('<?php echo $sku; ?>')" id="<?php echo $sku; ?>-option">
                <h3 style="color: #059669; margin-bottom: 8px;"><?php echo $prods[0]['name']; ?></h3>
                <p style="margin-bottom: 8px;"><?php echo number_format($product_specification); ?>mg attribute per fl oz</p>
                <p style="color: #6b7280; font-size: 14px;"><?php echo $description; ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex; justify-content:flex-end; margin:-12px 0 20px 0;">
            <a href="/tools/sales-reference.php" class="copy-button" style="background:#059669; text-decoration:none;">📋 Open Sales Reference</a>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px;">
            <div class="input-group">
                <label for="bottle-size">Bottle Size</label>
                <select id="bottle-size" onchange="updateCalculation()">
                    <option value="32">32oz</option>
                    <option value="128">128oz</option>
                </select>
            </div>
            <div class="input-group">
                <label for="drink-price">Drink Price ($)</label>
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
        
        <div class="results-grid" id="results-grid"></div>
        <div class="profit-highlight" id="profit-highlight"></div>
        
        <div style="background: #f9fafb; padding: 24px; border-radius: 12px; margin-top: 24px;">
            <h3 style="margin-bottom: 16px;">📝 Generated Sales Script</h3>
            <div id="sales-script" style="background: white; padding: 16px; border-radius: 8px; border-left: 4px solid #059669; font-family: monospace; white-space: pre-wrap;"></div>
            <button class="copy-button" onclick="copySalesScript()">Copy Script</button>
        </div>
    </div>
</div>

<script>
// Load products from PHP
const productsData = <?php echo $productsJson; ?>;

// Index products by SKU for quick lookup
const productsBySKU = {};
productsData.forEach(p => { productsBySKU[p.sku] = p; });

// Get first product as default
let currentProduct = productsData[0];

function selectProduct(sku){
  currentProduct = productsBySKU[sku];
  document.querySelectorAll('.formula-option').forEach(el => el.classList.remove('active'));
  document.getElementById(sku + '-option').classList.add('active');
  
  // Update bottle size options dynamically
  updateBottleSizeOptions();
  updateCalculation();
}

function updateBottleSizeOptions() {
  const bottleSizeSelect = document.getElementById('bottle-size');
  const currentSize = bottleSizeSelect.value;
  
  // Find all products with same product_specification
  const sameattribute = productsData.filter(p => p.mg_per_oz === currentProduct.mg_per_oz);
  
  // Clear and rebuild options
  bottleSizeSelect.innerHTML = '';
  sameattribute.forEach(p => {
    const option = document.createElement('option');
    option.value = p.sku;
    option.textContent = p.bottle_size + 'oz';
    if (p.sku === currentProduct.sku) option.selected = true;
    bottleSizeSelect.appendChild(option);
  });
}

function updateCalculation(){
  // Get selected bottle size SKU
  const selectedSKU = document.getElementById('bottle-size').value;
  const product = productsBySKU[selectedSKU] || currentProduct;
  
  const drinkPrice = parseFloat(document.getElementById('drink-price').value);
  const servingMit = parseInt(document.getElementById('serving-size').value);
  const unitSystem = document.getElementById('unit-toggle').value;
  
  // Use product data from database
  const wholesalePrice = product.wholesale_price;
  const totalAttribute = product.total_mg;
  const totalServings = Math.floor(totalAttribute / servingMit);
  const ozPerServing = servingMit / product.mg_per_oz;
  let servingVolume, volumeLabel;
  if (unitSystem === 'us') { servingVolume = ozPerServing; volumeLabel = 'fl oz'; }
  else { servingVolume = ozPerServing * 29.5735; volumeLabel = 'ml'; }
  const totalRevenue = totalServings * drinkPrice;
  const totalProfit = totalRevenue - wholesalePrice;
  const profitMargin = ((totalProfit / wholesalePrice) * 100);
  const costPerServing = wholesalePrice / totalServings;
  const profitPerServing = drinkPrice - costPerServing;
  const profitPerDollarInvested = totalProfit / wholesalePrice;
  const resultsHTML = `
    <div class="result-card"><div class="result-value">${totalServings}</div><div class="result-label">Total Drinks</div><div style="font-size:11px;color:#6b7280;margin-top:4px;">${servingMit}mg attribute each</div></div>
    <div class="result-card"><div class="result-value">${servingVolume.toFixed(2)} ${volumeLabel}</div><div class="result-label">Per Drink</div><div style="font-size:11px;color:#6b7280;margin-top:4px;">Serving size (by attribute)</div></div>
    <div class="result-card"><div class="result-value">$${totalRevenue.toLocaleString()}</div><div class="result-label">Total Revenue</div><div style="font-size:11px;color:#6b7280;margin-top:4px;">${totalServings} × $${drinkPrice}</div></div>
    <div class="result-card"><div class="result-value" style="color:#059669;">$${totalProfit.toLocaleString()}</div><div class="result-label">Your Profit</div><div style="font-size:11px;color:#059669;margin-top:4px;">After $${wholesalePrice.toFixed(2)} investment</div></div>
    <div class="result-card"><div class="result-value" style="color:#dc2626;">${profitMargin.toFixed(0)}%</div><div class="result-label">Return on Investment</div><div style="font-size:11px;color:#6b7280;margin-top:4px;">Profit margin</div></div>
    <div class="result-card"><div class="result-value">$${profitPerServing.toFixed(2)}</div><div class="result-label">Profit per Drink</div><div style="font-size:11px;color:#6b7280;margin-top:4px;">Your earnings each</div></div>`;
  document.getElementById('results-grid').innerHTML = resultsHTML;
  const profitHTML = `
    <h3>🎯 Customer Profit Breakdown</h3>
    <div style="background:white;padding:20px;border-radius:12px;margin:16px 0;border:2px solid #059669;">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:16px;">
        <div style="text-align:center;"><div style="font-size:16px;color:#6b7280;">Initial Investment</div><div style="font-size:24px;font-weight:bold;color:#dc2626;">$${wholesalePrice.toFixed(2)}</div><div style="font-size:12px;color:#6b7280;">One-time purchase</div></div>
        <div style="text-align:center;"><div style="font-size:16px;color:#6b7280;">Total Revenue</div><div style="font-size:24px;font-weight:bold;color:#3b82f6;">$${totalRevenue.toLocaleString()}</div><div style="font-size:12px;color:#6b7280;">${totalServings} drinks @ $${drinkPrice} each</div></div>
        <div style="text-align:center;"><div style="font-size:16px;color:#6b7280;">Net Profit</div><div style="font-size:24px;font-weight:bold;color:#059669;">$${totalProfit.toLocaleString()}</div><div style="font-size:12px;color:#059669;">${profitMargin.toFixed(0)}% return</div></div>
      </div>
      <div style="text-align:center;padding:16px;background:#f0fdf4;border-radius:8px;">
        <p style="margin:0;font-size:18px;color:#065f46;"><strong>Bottom Line:</strong> Every $1 invested returns $${(profitPerDollarInvested + 1).toFixed(2)}</p>
        <p style="margin:8px 0 0 0;font-size:14px;color:#6b7280;">That's $${profitPerServing.toFixed(2)} profit on every single drink you sell!</p>
      </div>
    </div>`;
  document.getElementById('profit-highlight').innerHTML = profitHTML;
  const productName = product.name;
  const bottleSizeOz = product.bottle_size;
  const script = `"Let me show you the incredible profit potential with our ${productName}:

INVESTMENT: Just $${wholesalePrice.toFixed(2)} for one ${bottleSizeOz}oz bottle

WHAT YOU GET: ${totalServings} drinks of exactly ${servingMit}mg attribute each
- Each drink uses ${servingVolume.toFixed(2)} ${volumeLabel} 
- Perfect precision every time - no waste, no guessing

YOUR REVENUE: ${totalServings} drinks × $${drinkPrice} = $${totalRevenue.toLocaleString()}

YOUR PROFIT: $${totalProfit.toLocaleString()} (that's ${profitMargin.toFixed(0)}% return!)

PROFIT PER DRINK: $${profitPerServing.toFixed(2)} every time you sell one

Bottom line: Every $1 you invest returns $${(profitPerDollarInvested + 1).toFixed(2)} back to you.

Your customers get consistent, reliable effects, and you get incredible profits. That's the power of catalog-ready precision!"`;
  document.getElementById('sales-script').textContent = script;
}

function copySalesScript(){
  const script = document.getElementById('sales-script').textContent;
  navigator.clipboard.writeText(script).then(() => {
    const button = event.target; const originalText = button.textContent; button.textContent = 'Copied!'; button.style.background = '#10b981'; setTimeout(() => { button.textContent = originalText; button.style.background = '#3b82f6'; }, 2000);
  });
}

// Initialize with first product
if (productsData.length > 0) {
  selectProduct(productsData[0].sku);
}
updateCalculation();
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


