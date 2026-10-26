<?php
/**
 * Shortcode Builder UI
 * Interactive tool for building and testing shortcodes
 */

$SEO = [
    'title' => 'Shortcode Builder — Admin',
    'description' => 'Build and test dynamic shortcodes',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));
require_once __DIR__ . '/../required/shortcode-engine.php';

$pdo = db();

// Get data for dropdowns
$products = $pdo->query("SELECT id, sku, name FROM products WHERE active = 1 ORDER BY name")->fetchAll();
$modules = $pdo->query("SELECT id, title FROM training_modules WHERE sort_order < 100 ORDER BY sort_order")->fetchAll();
?>

<style>
.builder-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}
.builder-header {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
}
.builder-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
.builder-panel {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.shortcode-category {
    background: #f9fafb;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 16px;
}
.shortcode-btn {
    display: block;
    width: 100%;
    padding: 12px;
    margin-bottom: 8px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    text-align: left;
    cursor: pointer;
    transition: all 0.2s;
}
.shortcode-btn:hover {
    border-color: #3b82f6;
    background: #f0f9ff;
}
.preview-box {
    background: #f9fafb;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    min-height: 200px;
}
.code-box {
    background: #1f2937;
    color: #e5e7eb;
    padding: 16px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    margin-top: 12px;
}
</style>

<div class="builder-container">
    <div class="builder-header">
        <h1 style="margin: 0 0 8px 0;">🎨 Shortcode Builder</h1>
        <p style="margin: 0; opacity: 0.9;">Build dynamic, database-synced content with shortcodes</p>
    </div>
    
    <div class="builder-grid">
        <!-- Left: Shortcode Library -->
        <div class="builder-panel">
            <h2 style="margin: 0 0 20px 0;">📚 Shortcode Library</h2>
            
            <div class="shortcode-category">
                <h3 style="margin: 0 0 12px 0; color: #1f2937; font-size: 16px;">💰 Product Data</h3>
                <button class="shortcode-btn" onclick="insertShortcode('[product_price sku=&quot;BLEND-10-32&quot;]')">
                    <strong>product_price</strong> - Product price<br>
                    <small style="color: #6b7280;">Example: $79.95</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_name sku=&quot;BLEND-10-32&quot;]')">
                    <strong>product_name</strong> - Product name<br>
                    <small style="color: #6b7280;">Example: Blue 10 Blend - 32oz</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_servings sku=&quot;BLEND-10-32&quot;]')">
                    <strong>product_servings</strong> - 10mg servings<br>
                    <small style="color: #6b7280;">Example: 64</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_attribute sku=&quot;BLEND-10-32&quot; type=&quot;per_oz&quot;]')">
                    <strong>product_attribute</strong> - attribute content<br>
                    <small style="color: #6b7280;">Types: per_oz, total, per_serving</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_cost_per_serving sku=&quot;BLEND-10-32&quot;]')">
                    <strong>product_cost_per_serving</strong> - Cost per 10mg<br>
                    <small style="color: #6b7280;">Example: $1.25</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_sku id=&quot;11&quot;]')">
                    <strong>product_sku</strong> - Product SKU<br>
                    <small style="color: #6b7280;">Example: BLEND-10-32</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_drinks_per_oz sku=&quot;BLEND-10-32&quot;]')">
                    <strong>product_drinks_per_oz</strong> - Drinks per oz<br>
                    <small style="color: #6b7280;">Example: 2.0</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_total_revenue sku=&quot;BLEND-10-32&quot; drink_price=&quot;12&quot;]')">
                    <strong>product_total_revenue</strong> - Total revenue<br>
                    <small style="color: #6b7280;">Example: $1,536.00</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_group_name sku=&quot;BLEND-10-32&quot;]')">
                    <strong>product_group_name</strong> - Product group<br>
                    <small style="color: #6b7280;">Example: Products</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_description sku=&quot;BLEND-10-32&quot; length=&quot;100&quot;]')">
                    <strong>product_description</strong> - Description<br>
                    <small style="color: #6b7280;">With optional length limit</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_link sku=&quot;BLEND-10-32&quot; text=&quot;View Product&quot;]')">
                    <strong>product_link</strong> - Product link<br>
                    <small style="color: #6b7280;">Clickable link to product</small>
                </button>
            </div>
            
            <div class="shortcode-category">
                <h3 style="margin: 0 0 12px 0; color: #1f2937; font-size: 16px;">📊 Calculations</h3>
                <button class="shortcode-btn" onclick="insertShortcode('[profit_calc sku=&quot;BLEND-10-32&quot; drink_price=&quot;12&quot; base_cost=&quot;3&quot;]')">
                    <strong>profit_calc</strong> - Profit calculator<br>
                    <small style="color: #6b7280;">Calculate revenue, gross, net profit</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[serving_calc sku=&quot;BLEND-10-32&quot; customer_dose=&quot;15&quot;]')">
                    <strong>serving_calc</strong> - Serving calculator<br>
                    <small style="color: #6b7280;">Calculate ml/oz for custom dose</small>
                </button>
            </div>
            
            <div class="shortcode-category">
                <h3 style="margin: 0 0 12px 0; color: #1f2937; font-size: 16px;">❓ Quizzes</h3>
                <button class="shortcode-btn" onclick="showQuizBuilder('by_id')">
                    <strong>quiz (by ID)</strong> - Specific quiz<br>
                    <small style="color: #6b7280;">Example: [quiz id="25"]</small>
                </button>
                <button class="shortcode-btn" onclick="showQuizBuilder('by_product')">
                    <strong>quiz (by product)</strong> - Auto-select quiz<br>
                    <small style="color: #6b7280;">Example: [quiz sku="BLEND-10-32" position="mid"]</small>
                </button>
            </div>
            
            <div class="shortcode-category">
                <h3 style="margin: 0 0 12px 0; color: #1f2937; font-size: 16px;">🎨 Content Blocks</h3>
                <button class="shortcode-btn" onclick="insertShortcode('[highlight type=&quot;info&quot; content=&quot;Important information here&quot;]')">
                    <strong>highlight</strong> - Highlighted box<br>
                    <small style="color: #6b7280;">Types: info, success, warning, error</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_comparison sku1=&quot;BLEND-10-32&quot; sku2=&quot;BLEND-40-32&quot;]')">
                    <strong>product_comparison</strong> - Side-by-side<br>
                    <small style="color: #6b7280;">Compare two products</small>
                </button>
                <button class="shortcode-btn" onclick="insertShortcode('[product_table skus=&quot;BLEND-10-32,BLEND-40-32,BLEND-80-32&quot;]')">
                    <strong>product_table</strong> - Product table<br>
                    <small style="color: #6b7280;">Multi-product comparison table</small>
                </button>
            </div>
        </div>
        
        <!-- Right: Builder & Preview -->
        <div>
            <div class="builder-panel">
                <h2 style="margin: 0 0 20px 0;">🛠️ Build Shortcode</h2>
                
                <div id="builder-form">
                    <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                        <p style="margin: 0; color: #1e40af;">
                            👈 Click a shortcode from the left to start building, or type directly in the text area below.
                        </p>
                    </div>
                    
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select Product:</label>
                    <select id="product-selector" onchange="updateShortcodeSku()" style="width: 100%; padding: 12px; border: 2px solid #d1d5db; border-radius: 6px; margin-bottom: 16px;">
                        <option value="">Select a product...</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['sku']); ?>" data-id="<?php echo $p['id']; ?>">
                            <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['sku']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Shortcode:</label>
                    <textarea id="shortcode-input" style="width: 100%; padding: 12px; border: 2px solid #d1d5db; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 14px; min-height: 100px;">[product_price sku=""]</textarea>
                    
                    <button onclick="previewShortcode()" class="btn" style="margin-top: 12px; background: #3b82f6; color: white; width: 100%;">
                        👁️ Preview Shortcode
                    </button>
                    
                    <button onclick="copyShortcode()" class="btn secondary" style="margin-top: 8px; width: 100%;">
                        📋 Copy to Clipboard
                    </button>
                </div>
            </div>
            
            <div class="builder-panel" style="margin-top: 24px;">
                <h3 style="margin: 0 0 16px 0;">👁️ Live Preview</h3>
                <div id="preview-output" class="preview-box">
                    <p style="color: #6b7280; text-align: center;">Preview will appear here</p>
                </div>
                
                <div id="preview-code" class="code-box" style="display: none;">
                    <strong style="color: #f59e0b;">Rendered HTML:</strong>
                    <pre id="html-output" style="margin-top: 8px; color: #e5e7eb; overflow-x: auto;"></pre>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Product Selector Modal -->
    <div id="product-selector-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 12px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
            <h3 style="margin: 0 0 20px 0;">Select Product</h3>
            
            <input type="text" id="product-search" placeholder="Search products..." style="width: 100%; padding: 12px; border: 2px solid #d1d5db; border-radius: 6px; margin-bottom: 16px;" onkeyup="filterProducts()">
            
            <div id="product-list" style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($products as $p): ?>
                <div class="product-item" data-id="<?php echo $p['id']; ?>" data-sku="<?php echo htmlspecialchars($p['sku']); ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" onclick="selectProduct(this)" style="padding: 12px; border: 2px solid #e5e7eb; border-radius: 6px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s;">
                    <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                    <div style="font-size: 12px; color: #6b7280;">SKU: <?php echo htmlspecialchars($p['sku']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button onclick="closeModal()" class="btn secondary" style="margin-top: 16px; width: 100%;">Cancel</button>
        </div>
    </div>
</div>

<script>
let currentShortcodeType = '';

function insertShortcode(shortcode) {
    document.getElementById('shortcode-input').value = shortcode;
    previewShortcode();
}

function showQuizBuilder(type) {
    if (type === 'by_id') {
        const quizId = prompt('Enter Quiz ID:');
        if (quizId) {
            insertShortcode('[quiz id="' + quizId + '"]');
        }
    } else {
        currentShortcodeType = 'quiz_by_product';
        document.getElementById('product-selector-modal').style.display = 'block';
    }
}

function selectProduct(elem) {
    const sku = elem.getAttribute('data-sku');
    const name = elem.getAttribute('data-name');
    
    if (currentShortcodeType === 'quiz_by_product') {
        const position = prompt('Enter quiz position (intro, early, mid, late, final):', 'mid');
        if (position) {
            insertShortcode('[quiz sku="' + sku + '" position="' + position + '"]');
        }
    } else {
        insertShortcode('[product_price sku="' + sku + '"]');
    }
    
    closeModal();
}

function filterProducts() {
    const search = document.getElementById('product-search').value.toLowerCase();
    const items = document.querySelectorAll('.product-item');
    
    items.forEach(item => {
        const name = item.getAttribute('data-name').toLowerCase();
        const sku = item.getAttribute('data-sku').toLowerCase();
        
        if (name.includes(search) || sku.includes(search)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function closeModal() {
    document.getElementById('product-selector-modal').style.display = 'none';
    document.getElementById('product-search').value = '';
    filterProducts();
}

async function previewShortcode() {
    const shortcode = document.getElementById('shortcode-input').value;
    const previewOutput = document.getElementById('preview-output');
    const previewCode = document.getElementById('preview-code');
    const htmlOutput = document.getElementById('html-output');
    
    previewOutput.innerHTML = '<p style="color: #6b7280; text-align: center;">⏳ Rendering...</p>';
    
    try {
        const response = await fetch(window.__BASE_PREFIX + '/admin/api/preview-shortcode.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'shortcode=' + encodeURIComponent(shortcode)
        });
        
        const result = await response.json();
        
        if (result.success) {
            previewOutput.innerHTML = result.html;
            htmlOutput.textContent = result.html;
            previewCode.style.display = 'block';
        } else {
            previewOutput.innerHTML = '<div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px;">' + result.error + '</div>';
            previewCode.style.display = 'none';
        }
    } catch (error) {
        previewOutput.innerHTML = '<div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px;">Error: ' + error.message + '</div>';
    }
}

function copyShortcode() {
    const shortcode = document.getElementById('shortcode-input').value;
    navigator.clipboard.writeText(shortcode).then(() => {
        alert('✅ Shortcode copied to clipboard!');
    });
}

// Update shortcode SKU when product selected
function updateShortcodeSku() {
    const selector = document.getElementById('product-selector');
    const sku = selector.value;
    const input = document.getElementById('shortcode-input');
    
    if (sku && input.value) {
        // Replace SKU in current shortcode
        input.value = input.value.replace(/sku="[^"]*"/, 'sku="' + sku + '"');
        input.value = input.value.replace(/sku=""/, 'sku="' + sku + '"');
        previewShortcode();
    }
}

// Auto-preview on page load
document.addEventListener('DOMContentLoaded', function() {
    // Select first product by default
    const selector = document.getElementById('product-selector');
    if (selector.options.length > 1) {
        selector.selectedIndex = 1;
        updateShortcodeSku();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

