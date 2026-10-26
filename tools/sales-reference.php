<?php
$SEO = [
    'title' => 'Sales Reference — Product (Public)',
    'description' => 'Quick reference for product pricing, bulk discounts, and specifications',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

// Handle public order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_public_order') {
    $pdo = db();
    $successMsg = '';
    $errorMsg = '';
    try {
        $itemsJson = trim($_POST['items_json'] ?? '');
        $customer = [
            'business_name' => trim($_POST['business_name'] ?? ''),
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'address_line1' => trim($_POST['address_line1'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state_region' => trim($_POST['state_region'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? ''),
            'country' => trim($_POST['country'] ?? 'USA'),
        ];
        $agent = [
            'first_name' => trim($_POST['agent_first_name'] ?? ''),
            'last_name' => trim($_POST['agent_last_name'] ?? ''),
            'email' => trim($_POST['agent_email'] ?? ''),
            'phone' => trim($_POST['agent_phone'] ?? ''),
        ];
        if ($itemsJson === '' || empty($customer['email'])) { throw new Exception('Missing order items or customer email'); }
        $items = json_decode($itemsJson, true);
        if (!is_array($items) || count($items) === 0) { throw new Exception('No items provided'); }

        $pdo->beginTransaction();

        // Find or create customer user
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$customer['email']]);
        $userId = $stmt->fetchColumn();
        if (!$userId) {
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
            $stmt->execute([$customer['email'], password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), $customer['first_name'], $customer['last_name'], $customer['phone']]);
            $userId = $pdo->lastInsertId();
            // Assign customer role (id 4)
            $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 4)')->execute([$userId]);
        }

        // Find or create wholesale account
        $stmt = $pdo->prepare('SELECT id FROM wholesale_accounts WHERE user_id = ?');
        $stmt->execute([$userId]);
        $waId = $stmt->fetchColumn();
        if (!$waId) {
            $stmt = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, phone, address_line1, city, state_region, postal_code, country, approved, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())');
            $stmt->execute([$userId, $customer['business_name'] ?: ($customer['first_name'] . ' ' . $customer['last_name']), $customer['phone'], $customer['address_line1'], $customer['city'], $customer['state_region'], $customer['postal_code'], $customer['country']]);
            $waId = $pdo->lastInsertId();
        } else {
            // Update basic details
            $pdo->prepare('UPDATE wholesale_accounts SET business_name = COALESCE(NULLIF(?, ""), business_name), phone = COALESCE(NULLIF(?, ""), phone), address_line1 = COALESCE(NULLIF(?, ""), address_line1), city = COALESCE(NULLIF(?, ""), city), state_region = COALESCE(NULLIF(?, ""), state_region), postal_code = COALESCE(NULLIF(?, ""), postal_code), country = COALESCE(NULLIF(?, ""), country), approved = 1 WHERE id = ?')
                ->execute([$customer['business_name'], $customer['phone'], $customer['address_line1'], $customer['city'], $customer['state_region'], $customer['postal_code'], $customer['country'], $waId]);
        }

        // Find or create sales agent user
        $salesAgentId = null;
        if (!empty($agent['email'])) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$agent['email']]);
            $salesAgentId = $stmt->fetchColumn();
            if (!$salesAgentId) {
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                $stmt->execute([$agent['email'], password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), $agent['first_name'], $agent['last_name'], $agent['phone']]);
                $salesAgentId = $pdo->lastInsertId();
                // Assign sales_agent role (id 2)
                $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 2)')->execute([$salesAgentId]);
            }
        }

        // Build order items from SKUs in DB
        $subtotal = 0.0; $dbItems = []; $totalQty = 0;
        foreach ($items as $it) {
            $sku = $it['sku'] ?? ''; $qty = max(0, intval($it['qty'] ?? 0));
            if ($sku === '' || $qty <= 0) continue;
            $stmt = $pdo->prepare('SELECT id, name, wholesale_price FROM products WHERE sku = ? AND active = 1');
            $stmt->execute([$sku]);
            $p = $stmt->fetch();
            if ($p) {
                $lineTotal = $p['wholesale_price'] * $qty;
                $subtotal += $lineTotal; $totalQty += $qty;
                $dbItems[] = ['product_id' => intval($p['id']), 'product_name' => $p['name'], 'product_sku' => $sku, 'quantity' => $qty, 'unit_price' => (float)$p['wholesale_price'], 'line_total' => (float)$lineTotal];
            }
        }
        if (count($dbItems) === 0) { throw new Exception('No valid items'); }

        // Get commission tier for total quantity (uses our dynamic pricing model)
        $commissionInfo = get_commission_for_quantity($totalQty, $dbItems[0]['product_id'] ?? null);
        $agentRate = $commissionInfo['commission_percent'];
        $bulkDiscount = $commissionInfo['discount_percent'];
        
        // Apply bulk discount
        $discount = $subtotal * ($bulkDiscount / 100);
        $subtotalAfterDiscount = $subtotal - $discount;
        
        // Calculate shipping (free over $500 after discount)
        $shipping = $subtotalAfterDiscount >= 500 ? 0 : 25;
        
        // Calculate tax on discounted subtotal
        $taxRate = 0.08;
        $tax = $subtotalAfterDiscount * $taxRate;
        
        // Calculate total
        $total = $subtotalAfterDiscount + $shipping + $tax;

        // Create order
        $orderNumber = 'PT-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('INSERT INTO orders (order_number, user_id, wholesale_account_id, sales_agent_id, status, payment_status, payment_method, shipping_method, notes, subtotal, tax, shipping, total, commission_rate, created_at) VALUES (?, ?, ?, ?, "submitted", "pending", "Public Tool", "Standard", ?, ?, ?, ?, ?, ?, NOW())');
        $notes = 'Public tool order. Agent: ' . ($agent['first_name'] . ' ' . $agent['last_name'] . ' ' . $agent['email']);
        $stmt->execute([$orderNumber, $userId, $waId, $salesAgentId, $notes, $subtotal, $tax, $shipping, $total, $agentRate]);
        $orderId = $pdo->lastInsertId();

        // Insert items
        $stmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($dbItems as $di) {
            $stmt->execute([$orderId, $di['product_id'], $di['product_name'], $di['product_sku'], $di['quantity'], $di['unit_price'], $di['line_total']]);
        }

        // Commissions (developer + agent) - using our dynamic tier system
        $stmt = $pdo->prepare('INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status) VALUES (?, ?, ?, ?, ?, ?)');
        $developerUserId = 1; 
        $stmt->execute([$orderId, $developerUserId, 'developer', $subtotalAfterDiscount * 0.002, 0.2, 'pending']);
        
        // Sales agent commission based on our tier system
        if ($salesAgentId) { 
            $commissionAmount = $subtotalAfterDiscount * ($agentRate / 100);
            $stmt->execute([$orderId, $salesAgentId, 'sales_agent', $commissionAmount, $agentRate, 'pending']); 
        }

        $pdo->commit();
        $successMsg = 'Order submitted! Reference: ' . htmlspecialchars($orderNumber);
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $errorMsg = 'Error submitting order: ' . $e->getMessage();
    }
}

// Load dynamic product data from database
require_once __DIR__ . '/../required/product-data-dynamic.php';
?>

<style>
.pwa-prompt { background:#ecfeff; border:1px solid #a5f3fc; color:#0e7490; padding:12px 16px; border-radius:8px; margin-bottom:16px; display:none; }
.sales-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.reference-card { background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); margin-bottom: 24px; }
.card-header { background: linear-gradient(135deg, #059669, #10b981); color: white; padding: 20px 24px; border-radius: 12px 12px 0 0; }
.products-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 32px; }
.product-card { background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; }
.product-name { font-size: 20px; font-weight: bold; color: #1f2937; margin-bottom: 12px; }
.product-specs { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.spec-item { text-align: center; padding: 8px; background: white; border-radius: 6px; }
.spec-label { font-size: 11px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
.spec-value { font-size: 16px; font-weight: bold; color: #059669; }
.bulk-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
.bulk-table th, .bulk-table td { padding: 12px; text-align: center; border: 1px solid #e5e7eb; }
.bulk-table th { background: #f9fafb; font-weight: 600; color: #374151; }
.discount-badge { background: #dc2626; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.ppu-value { color: #059669; font-weight: 600; }
</style>

<div class="sales-container">
    <div class="pwa-prompt" id="pwaPrompt" style="display:none;">Add this page to your home screen for one‑tap access. Tap Share → Add to Home Screen.</div>
    <?php if (!empty($successMsg)): ?>
    <div class="reference-card" style="border-left:4px solid #059669;">
        <div class="card-header" style="background:linear-gradient(135deg,#16a34a,#059669);">
            <h2 style="margin:0;">✅ <?php echo $successMsg; ?></h2>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
    <div class="reference-card" style="border-left:4px solid #dc2626;">
        <div class="card-header" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
            <h2 style="margin:0;">❌ <?php echo htmlspecialchars($errorMsg); ?></h2>
        </div>
    </div>
    <?php endif; ?>
  <div class="reference-card">
    <div class="card-header">
      <h1 style="margin:0; font-size:28px;">📋 Sales Reference (Public)</h1>
      <p style="margin: 8px 0 0 0; opacity: 0.9;">Pricing, bulk discounts, and product specifications</p>
    </div>
  </div>

  <div style="display:flex; justify-content:flex-end; margin:-8px 0 16px 0;">
    <a href="/tools/profit-calculator.php" class="copy-button" style="background:#059669; text-decoration:none;">💰 Open Profit Calculator</a>
  </div>

  <div class="products-grid">
    <?php foreach ($products as $product): ?>
    <div class="product-card">
      <div class="product-name"><?php echo $product['name']; ?></div>
      <div class="product-specs">
        <div class="spec-item"><div class="spec-label">Total attribute</div><div class="spec-value"><?php echo number_format($product['total_mg']); ?>mg</div></div>
        <div class="spec-item"><div class="spec-label">Per Ounce</div><div class="spec-value"><?php echo $product['mg_per_oz']; ?>mg/oz</div></div>
        <div class="spec-item"><div class="spec-label">Bottle Size</div><div class="spec-value"><?php echo $product['bottle_size']; ?>oz</div></div>
        <div class="spec-item"><div class="spec-label">SKU</div><div class="spec-value" style="font-size:12px;"><?php echo $product['sku']; ?></div></div>
      </div>
      <div class="pricing-section">
        <div class="price-row"><span>MSRP:</span><span style="color:#6b7280; text-decoration:line-through;">$<?php echo number_format($product['msrp'],2); ?></span></div>
        <div class="price-row"><span><strong>Your Price:</strong></span><span style="font-size:24px; font-weight:bold; color:#dc2626;">$<?php echo number_format($product['sale_price'],2); ?></span></div>
        <div class="price-row"><span>Per MG:</span><span class="ppu-value">$<?php echo number_format($product['sale_price'] / $product['total_mg'], 4); ?></span></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="reference-card">
    <div class="card-header">
      <h2 style="margin:0;">💰 Bulk Discount Structure</h2>
      <p style="margin: 8px 0 0 0; opacity: 0.9;">Volume discounts and per-unit pricing</p>
    </div>
    <div class="card-body" style="padding:24px;">
      <table class="bulk-table">
        <thead><tr><th>Product</th><th>Bottle Size</th><th>Base Price</th><th>X5 (5% off)</th><th>X10 (8% off)</th><th>X15 (11% off)</th><th>X20 (15% off)</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
          <tr>
            <td><strong><?php echo $product['name']; ?></strong></td>
            <td><?php echo $product['bottle_size']; ?>oz</td>
            <td>$<?php echo number_format($product['sale_price'], 2); ?></td>
            <?php foreach ($bulk_discounts as $discount): $discounted = $product['sale_price'] * (1 - $discount['discount']/100); ?>
              <td>
                <div style="font-weight:bold;">$<?php echo number_format($discounted, 2); ?></div>
                <div class="discount-badge">-<?php echo $discount['discount']; ?>%</div>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;">PPU: $<?php echo number_format($discounted, 2); ?></div>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Public Order Builder -->
  <div class="reference-card" id="order-builder">
    <div class="card-header">
      <h2 style="margin:0;">🧾 Quick Invoice Builder</h2>
      <p style="margin:8px 0 0 0; opacity:.9;">Add items, enter customer & agent details, and submit a wholesale order.</p>
    </div>
    <div class="card-body" style="padding:24px;">
      <form method="post" id="public-order-form">
        <input type="hidden" name="action" value="create_public_order">
        <input type="hidden" name="items_json" id="items_json">
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:24px;">
          <div>
            <h3 style="margin:0 0 12px 0;">Items</h3>
            <?php foreach ($products as $p): ?>
            <div style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin-bottom:8px;">
              <div>
                <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                <div style="color:#6b7280; font-size:12px;">SKU: <?php echo $p['sku']; ?> • $<?php echo number_format($p['sale_price'],2); ?></div>
              </div>
              <div>
                <input type="number" min="0" step="1" value="0" data-sku="<?php echo $p['sku']; ?>" class="qty-input" style="width:90px; padding:6px; border:1px solid #e5e7eb; border-radius:6px;">
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div>
            <h3 style="margin:0 0 12px 0;">Customer</h3>
            <input name="business_name" placeholder="Business Name" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
              <input name="first_name" placeholder="First Name" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
              <input name="last_name" placeholder="Last Name" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
            </div>
            <input name="email" type="email" placeholder="Email" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin:8px 0;" required>
            <input name="phone" placeholder="Phone" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px;">
            <input name="address_line1" placeholder="Address" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
              <input name="city" placeholder="City" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
              <input name="state_region" placeholder="State/Region" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:8px 0;">
              <input name="postal_code" placeholder="Postal Code" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
              <input name="country" placeholder="Country" value="USA" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
            </div>
            <h3 style="margin:12px 0 8px 0;">Sales Agent</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
              <input name="agent_first_name" placeholder="First Name" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
              <input name="agent_last_name" placeholder="Last Name" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
            </div>
            <input name="agent_email" type="email" placeholder="Agent Email" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin:8px 0;">
            <input name="agent_phone" placeholder="Agent Phone" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
            <button class="copy-button" type="submit" style="width:100%; margin-top:12px; background:#059669;">Submit Order</button>
            <div style="color:#6b7280; font-size:12px; margin-top:6px;">Orders appear in Admin → Orders with status Submitted.</div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

<script>
// Register manifest & show simple PWA prompt for mobile
(function(){
  const link = document.createElement('link');
  link.rel = 'manifest';
  link.href = '/tools/manifest.webmanifest';
  document.head.appendChild(link);
  const ua = navigator.userAgent.toLowerCase();
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  const isiOS = /iphone|ipad|ipod/.test(ua);
  if (!isStandalone && (isiOS || 'getInstalledRelatedApps' in navigator)) {
    const el = document.getElementById('pwaPrompt'); if (el) el.style.display = 'block';
  }
})();
// Build items JSON on submit
document.getElementById('public-order-form')?.addEventListener('submit', function(){
  const items = [];
  document.querySelectorAll('.qty-input').forEach(inp => {
    const qty = parseInt(inp.value || '0');
    if (qty > 0) items.push({ sku: inp.getAttribute('data-sku'), qty });
  });
  document.getElementById('items_json').value = JSON.stringify(items);
});
</script>


