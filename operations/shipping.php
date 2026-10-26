<?php
$SEO = [
    'title' => 'Shipping Labels — Operations',
    'description' => 'Manage shipping labels and tracking for orders.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();
$message = '';
$error = '';

// Load shipping API configuration
$configFile = __DIR__ . '/../config/shipping_apis.json';
$shippingConfig = [];
if (file_exists($configFile)) {
    $shippingConfig = json_decode(file_get_contents($configFile), true) ?? [];
}

$stampsEnabled = $shippingConfig['stamps_com']['enabled'] ?? false;
$shipstationEnabled = $shippingConfig['shipstation_com']['enabled'] ?? false;

// Handle shipping actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create_shipping_label') {
            $orderId = intval($_POST['order_id'] ?? 0);
            $carrier = $_POST['carrier'] ?? 'USPS';
            $serviceType = $_POST['service_type'] ?? 'Ground';
            $weightOz = floatval($_POST['weight_oz'] ?? 16);
            
            if ($orderId <= 0) {
                throw new Exception('Invalid order ID');
            }
            
            // Get order details
            $stmt = $pdo->prepare('
                SELECT o.*, wa.business_name, wa.address_line1, wa.address_line2, 
                       wa.city, wa.state_region as state, wa.postal_code, wa.country,
                       CONCAT(u.first_name, " ", u.last_name) as contact_name
                FROM orders o
                JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
                LEFT JOIN users u ON u.id = wa.user_id
                WHERE o.id = ?
            ');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            // For now, create a placeholder shipping label record
            $trackingNumber = 'TRK' . date('Ymd') . str_pad($orderId, 6, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare('
                INSERT INTO shipping_labels (order_id, tracking_number, carrier, service_type, weight_oz, status, created_by) 
                VALUES (?, ?, ?, ?, ?, "pending", ?)
            ');
            $stmt->execute([$orderId, $trackingNumber, $carrier, $serviceType, $weightOz, $userId]);
            
            // Update order shipping status
            $stmt = $pdo->prepare('UPDATE orders SET shipping_status = "processing", tracking_number = ? WHERE id = ?');
            $stmt->execute([$trackingNumber, $orderId]);
            
            // Log operations activity
            $stmt = $pdo->prepare('
                INSERT INTO operations_log (user_id, action, target_type, target_id, details, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $details = json_encode([
                'order_number' => $order['order_number'],
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
                'service_type' => $serviceType
            ]);
            $stmt->execute([$userId, 'shipping_label_created', 'order', $orderId, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $message = "Shipping label created successfully. Tracking: {$trackingNumber}";
            
        } elseif ($action === 'print_label') {
            $labelId = intval($_POST['label_id'] ?? 0);
            
            if ($labelId <= 0) {
                throw new Exception('Invalid label ID');
            }
            
            // Update label status to printed
            $stmt = $pdo->prepare('UPDATE shipping_labels SET status = "printed" WHERE id = ?');
            $stmt->execute([$labelId]);
            
            $message = 'Label marked as printed. In production, this would send to printer or download PDF.';
            
        } elseif ($action === 'mark_shipped') {
            $labelId = intval($_POST['label_id'] ?? 0);
            
            if ($labelId <= 0) {
                throw new Exception('Invalid label ID');
            }
            
            $pdo->beginTransaction();
            
            // Get shipping label and order info
            $stmt = $pdo->prepare('
                SELECT sl.*, o.order_number 
                FROM shipping_labels sl 
                JOIN orders o ON o.id = sl.order_id 
                WHERE sl.id = ?
            ');
            $stmt->execute([$labelId]);
            $label = $stmt->fetch();
            
            if (!$label) {
                throw new Exception('Shipping label not found');
            }
            
            // Update label and order status
            $stmt = $pdo->prepare('UPDATE shipping_labels SET status = "shipped", shipped_at = NOW() WHERE id = ?');
            $stmt->execute([$labelId]);
            
            $stmt = $pdo->prepare('UPDATE orders SET shipping_status = "shipped" WHERE id = ?');
            $stmt->execute([$label['order_id']]);
            
            $pdo->commit();
            
            $message = "Order {$label['order_number']} marked as shipped.";
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get orders ready for shipping
$ordersForShipping = $pdo->query('
    SELECT o.*, wa.business_name, CONCAT(u.first_name, " ", u.last_name) as contact_name,
           COUNT(oi.id) as item_count,
           SUM(oi.quantity * COALESCE(p.weight_grams, 100)) as estimated_weight_grams
    FROM orders o
    JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
    LEFT JOIN users u ON u.id = wa.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.shipping_status IN ("processing")
    AND NOT EXISTS (SELECT 1 FROM shipping_labels sl WHERE sl.order_id = o.id)
    GROUP BY o.id
    ORDER BY o.created_at ASC
')->fetchAll();

// Get sample requests ready for shipping
$samplesForShipping = $pdo->query('
    SELECT sr.id, sr.requested_at, sr.recipient_name, sr.recipient_address_line1,
           sr.recipient_city, sr.recipient_state, sr.recipient_zip,
           p.name as product_name, p.weight_grams,
           CONCAT(agent.first_name, " ", agent.last_name) as agent_name,
           COALESCE(l.company_name, wa.business_name, sr.recipient_name) as company_name
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users agent ON agent.id = sr.agent_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE sr.status = "approved"
    AND sr.request_type = "shipping"
    AND sr.shipped_at IS NULL
    AND NOT EXISTS (SELECT 1 FROM sample_shipments ss WHERE ss.sample_request_id = sr.id AND ss.delivery_status != "pending")
    ORDER BY sr.requested_at ASC
')->fetchAll();

// Get existing shipping labels
$shippingLabels = $pdo->query('
    SELECT sl.*, o.order_number, wa.business_name
    FROM shipping_labels sl
    JOIN orders o ON o.id = sl.order_id
    JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
    ORDER BY sl.created_at DESC
    LIMIT 50
')->fetchAll();
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>🏷️ Shipping Labels</h1>
                <p class="muted">Create and manage shipping labels for orders</p>
            </div>
            <a href="/operations/" class="btn secondary">← Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- API Configuration Status -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>📡 Shipping API Status</h2>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: <?php echo $stampsEnabled ? '#f0fdf4' : '#fef2f2'; ?>; border-radius: 6px;">
                    <div>
                        <strong>📮 STAMPS.COM</strong>
                        <div style="font-size: 12px; color: #6b7280;">Label printing service</div>
                    </div>
                    <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; <?php echo $stampsEnabled ? 'background: #059669; color: white;' : 'background: #dc2626; color: white;'; ?>">
                        <?php echo $stampsEnabled ? '✅ Enabled' : '❌ Disabled'; ?>
                    </span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: <?php echo $shipstationEnabled ? '#f0fdf4' : '#fef2f2'; ?>; border-radius: 6px;">
                    <div>
                        <strong>📦 ShipStation</strong>
                        <div style="font-size: 12px; color: #6b7280;">Multi-carrier platform</div>
                    </div>
                    <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; <?php echo $shipstationEnabled ? 'background: #059669; color: white;' : 'background: #dc2626; color: white;'; ?>">
                        <?php echo $shipstationEnabled ? '✅ Enabled' : '❌ Disabled'; ?>
                    </span>
                </div>
            </div>
            <?php if (!$stampsEnabled && !$shipstationEnabled): ?>
                <div style="margin-top: 16px; padding: 12px; background: #fffbeb; border: 1px solid #fed7aa; border-radius: 6px;">
                    <p style="margin: 0; color: #92400e;">
                        ⚠️ No shipping APIs configured. <a href="/admin/shipping-config.php" style="color: #7c2d12;">Configure shipping APIs</a> to enable automated label creation.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Samples Ready for Shipping -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>🧪 Sample Requests Ready to Ship</h2>
            <?php if (empty($samplesForShipping)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🧪</div>
                    <p>No sample requests ready for shipping.</p>
                    <p style="font-size: 14px;">Approved sample requests will appear here.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Request</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Company</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Agent</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Address</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($samplesForShipping as $sample): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong>#<?php echo $sample['id']; ?></strong>
                                            <div style="font-size: 11px; color: #6b7280;">
                                                <?php echo date('M j, Y', strtotime($sample['requested_at'])); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($sample['company_name']); ?></strong>
                                            <div style="font-size: 12px; color: #6b7280;">
                                                <?php echo htmlspecialchars($sample['recipient_name']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php echo htmlspecialchars($sample['product_name']); ?>
                                    </td>
                                    <td style="padding: 12px; font-size: 12px;">
                                        <?php echo htmlspecialchars($sample['agent_name']); ?>
                                    </td>
                                    <td style="padding: 12px; font-size: 12px;">
                                        <?php echo htmlspecialchars($sample['recipient_city'] . ', ' . $sample['recipient_state']); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <button onclick="shipSample(<?php echo $sample['id']; ?>, '<?php echo htmlspecialchars($sample['company_name'], ENT_QUOTES); ?>')" 
                                                class="btn" style="font-size: 12px; padding: 6px 12px; background: #059669;">
                                            🚚 Ship Sample
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Orders Ready for Shipping -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>📦 Orders Ready for Shipping</h2>
            <?php if (empty($ordersForShipping)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">📦</div>
                    <p>No orders ready for shipping labels.</p>
                    <p style="font-size: 14px;">Orders appear here when they are processed and ready to ship.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Order</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Customer</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Items</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Est. Weight</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordersForShipping as $order): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                            <div style="font-size: 11px; color: #6b7280;">ID: <?php echo $order['id']; ?></div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($order['business_name']); ?></strong>
                                            <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($order['contact_name']); ?></div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <strong><?php echo intval($order['item_count']); ?></strong>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php 
                                        $weightOz = round($order['estimated_weight_grams'] / 28.35, 1);
                                        echo $weightOz; ?> oz
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <button onclick="createShippingLabel(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES); ?>', <?php echo $weightOz; ?>)" 
                                                class="btn" style="font-size: 12px; padding: 6px 12px;">
                                            🏷️ Create Label
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Existing Shipping Labels -->
        <div class="card">
            <h2>🏷️ Recent Shipping Labels</h2>
            <?php if (empty($shippingLabels)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🏷️</div>
                    <p>No shipping labels created yet.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Order</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Customer</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Tracking</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Carrier</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shippingLabels as $label): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px;">
                                        <strong><?php echo htmlspecialchars($label['order_number']); ?></strong>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php echo htmlspecialchars($label['business_name']); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center; font-family: monospace;">
                                        <?php echo htmlspecialchars($label['tracking_number']); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php echo htmlspecialchars($label['carrier'] . ' ' . $label['service_type']); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php
                                        $statusColors = [
                                            'pending' => ['bg' => '#fef3c7', 'text' => '#d97706'],
                                            'printed' => ['bg' => '#dbeafe', 'text' => '#2563eb'],
                                            'shipped' => ['bg' => '#f0fdf4', 'text' => '#059669'],
                                            'delivered' => ['bg' => '#f0fdf4', 'text' => '#059669'],
                                            'exception' => ['bg' => '#fef2f2', 'text' => '#dc2626']
                                        ];
                                        $colors = $statusColors[$label['status']] ?? $statusColors['pending'];
                                        ?>
                                        <span style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['text']; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($label['status']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <div style="display: flex; flex-direction: column; gap: 4px; align-items: center;">
                                            <?php if ($label['status'] === 'pending'): ?>
                                                <button onclick="printLabel(<?php echo $label['id']; ?>)" 
                                                        class="btn secondary" style="font-size: 11px; padding: 4px 8px;">
                                                    🖨️ Print
                                                </button>
                                            <?php endif; ?>
                                            <?php if (in_array($label['status'], ['pending', 'printed'])): ?>
                                                <button onclick="markShipped(<?php echo $label['id']; ?>)" 
                                                        class="btn" style="font-size: 11px; padding: 4px 8px;">
                                                    🚚 Ship
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Create Shipping Label Modal -->
<div id="label-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 24px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Create Shipping Label</h3>
        <form method="post" id="label-form">
            <input type="hidden" name="action" value="create_shipping_label">
            <input type="hidden" name="order_id" id="label-order-id">
            
            <div style="margin-bottom: 16px;">
                <strong>Order: <span id="label-order-number"></span></strong>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <label>
                    <span>Carrier</span>
                    <select name="carrier">
                        <option value="USPS">USPS</option>
                        <option value="UPS">UPS</option>
                        <option value="FedEx">FedEx</option>
                        <option value="DHL">DHL</option>
                    </select>
                </label>
                <label>
                    <span>Service Type</span>
                    <select name="service_type">
                        <option value="Ground">Ground</option>
                        <option value="Express">Express</option>
                        <option value="Priority">Priority</option>
                        <option value="Overnight">Overnight</option>
                    </select>
                </label>
            </div>
            
            <label style="margin-bottom: 16px; display: block;">
                <span>Package Weight (oz)</span>
                <input type="number" name="weight_oz" id="label-weight" step="0.1" min="0.1" required>
            </label>
            
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 16px; margin-bottom: 16px;">
                <p style="margin: 0; font-size: 14px; color: #0c4a6e;">
                    <strong>📋 Note:</strong> This will create a placeholder label. In production, this would integrate with your configured shipping API to generate actual shipping labels.
                </p>
            </div>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeLabelModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn">Create Label</button>
            </div>
        </form>
    </div>
</div>

<script>
function createShippingLabel(orderId, orderNumber, estimatedWeight) {
    document.getElementById('label-order-id').value = orderId;
    document.getElementById('label-order-number').textContent = orderNumber;
    document.getElementById('label-weight').value = estimatedWeight;
    document.getElementById('label-modal').style.display = 'block';
}

function closeLabelModal() {
    document.getElementById('label-modal').style.display = 'none';
    document.getElementById('label-form').reset();
}

function printLabel(labelId) {
    if (confirm('Mark this label as printed?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="print_label">
            <input type="hidden" name="label_id" value="${labelId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function markShipped(labelId) {
    if (confirm('Mark this order as shipped?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="mark_shipped">
            <input type="hidden" name="label_id" value="${labelId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
document.getElementById('label-modal').addEventListener('click', function(e) {
    if (e.target === this) closeLabelModal();
});

// ESC key to close modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLabelModal();
    }
});

// Ship sample function
function shipSample(sampleId, companyName) {
    const carrier = prompt('Enter carrier (USPS, UPS, FedEx):');
    if (!carrier) return;
    
    const service = prompt('Enter service type (Ground, Priority, Express):');
    if (!service) return;
    
    const tracking = prompt('Enter tracking number:');
    if (!tracking) return;
    
    const cost = prompt('Enter shipping cost (e.g. 12.50):');
    if (!cost) return;
    
    if (confirm(`Ship sample #${sampleId} to ${companyName}?\n\nCarrier: ${carrier}\nService: ${service}\nTracking: ${tracking}\nCost: $${cost}`)) {
        fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=mark_shipped', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                request_id: sampleId,
                carrier: carrier,
                service: service,
                tracking_number: tracking,
                shipping_cost: parseFloat(cost)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                alert('Sample shipped successfully! Customer and agent have been notified.');
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error shipping sample: ' + error.message);
        });
    }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
