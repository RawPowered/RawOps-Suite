<?php
$SEO = [
    'title' => 'Sample Program Dashboard — Admin',
    'description' => 'Monitor sample requests, inventory, and conversion metrics.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_login_or_redirect('/login.php');

$adminId = current_admin_id();
if (!$adminId) {
    flash_set('error', 'Admin access required.');
    redirect('/');
}

$pdo = db();

// Get sample request statistics
$stats = $pdo->query('
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = "shipped" THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected
    FROM sample_requests
')->fetch(PDO::FETCH_ASSOC);

// Get inventory levels
$inventory = $pdo->query('
    SELECT si.*, p.name, p.sku,
           (si.quantity_available - si.quantity_reserved) as truly_available
    FROM sample_inventory si
    JOIN products p ON p.id = si.product_id
    ORDER BY si.quantity_available ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Get pending approvals
$pendingApprovals = $pdo->query('
    SELECT sr.*, p.name as product_name,
           CONCAT(agent.first_name, " ", agent.last_name) as agent_name,
           COALESCE(l.company_name, wa.business_name, sr.recipient_name) as company_name
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users agent ON agent.id = sr.agent_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE sr.status = "pending"
    ORDER BY sr.requested_at ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Get shipped samples
$shippedSamples = $pdo->query('
    SELECT sr.*, p.name as product_name,
           ss.tracking_number, ss.estimated_delivery_date, ss.carrier,
           CONCAT(agent.first_name, " ", agent.last_name) as agent_name,
           COALESCE(l.company_name, wa.business_name, sr.recipient_name) as company_name
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users agent ON agent.id = sr.agent_id
    LEFT JOIN sample_shipments ss ON ss.sample_request_id = sr.id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE sr.status IN ("shipped", "delivered")
    ORDER BY sr.shipped_at DESC
    LIMIT 20
')->fetchAll(PDO::FETCH_ASSOC);

// Get conversion metrics (samples that led to orders)
$conversionMetrics = $pdo->query('
    SELECT 
        COUNT(DISTINCT sr.id) as samples_with_orders,
        COUNT(DISTINCT o.id) as total_orders_from_samples,
        SUM(o.total) as total_revenue_from_samples
    FROM sample_requests sr
    JOIN crm_leads l ON l.id = sr.lead_id
    JOIN wholesale_accounts wa ON wa.id = l.converted_to_account_id
    JOIN orders o ON o.wholesale_account_id = wa.id
    WHERE sr.status IN ("shipped", "delivered")
    AND o.created_at >= sr.shipped_at
')->fetch(PDO::FETCH_ASSOC);

// Get agent shipping charges
$agentCharges = $pdo->query('
    SELECT 
        CONCAT(u.first_name, " ", u.last_name) as agent_name,
        COUNT(*) as charge_count,
        SUM(charge_amount) as total_charged,
        SUM(CASE WHEN charge_status = "pending" THEN charge_amount ELSE 0 END) as pending_charges
    FROM agent_expense_charges aec
    JOIN users u ON u.id = aec.agent_id
    GROUP BY aec.agent_id
    ORDER BY total_charged DESC
')->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
.stat-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; text-align: center; }
.stat-value { font-size: 36px; font-weight: 700; margin-bottom: 8px; }
.stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; }
.sample-table { width: 100%; border-collapse: collapse; }
.sample-table th, .sample-table td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
.sample-table th { background: #f9fafb; font-weight: 600; }
</style>

<div class="container">
    <?php include __DIR__ . '/../admin-menu.php'; ?>
    
    <div style="margin-bottom: 32px;">
        <h1 style="margin: 0; font-size: 32px; color: #1f2937;">🧪 Sample Program Dashboard</h1>
        <p style="color: #6b7280; margin: 8px 0 0;">Monitor sample requests, inventory, and conversion metrics</p>
    </div>

    <!-- Statistics Overview -->
    <div class="dashboard-grid" style="margin-bottom: 32px;">
        <div class="stat-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a);">
            <div class="stat-value" style="color: #d97706;"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="stat-label">Pending Approval</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe);">
            <div class="stat-value" style="color: #0369a1;"><?php echo $stats['shipped'] ?? 0; ?></div>
            <div class="stat-label">In Transit</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0);">
            <div class="stat-value" style="color: #059669;"><?php echo $stats['delivered'] ?? 0; ?></div>
            <div class="stat-label">Delivered</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #3b82f6;"><?php echo $stats['total_requests'] ?? 0; ?></div>
            <div class="stat-label">Total Requests</div>
        </div>
    </div>

    <!-- Conversion Metrics -->
    <div class="card" style="background: #f0fdf4; border: 2px solid #059669; margin-bottom: 24px;">
        <h2 style="margin-top: 0; color: #047857;">📈 Conversion Metrics</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #059669;">
                    <?php echo $conversionMetrics['samples_with_orders'] ?? 0; ?>
                </div>
                <div style="font-size: 13px; color: #065f46;">Samples → Orders</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #059669;">
                    <?php 
                    $conversionRate = ($stats['delivered'] > 0) 
                        ? (($conversionMetrics['samples_with_orders'] ?? 0) / $stats['delivered']) * 100 
                        : 0;
                    echo number_format($conversionRate, 1);
                    ?>%
                </div>
                <div style="font-size: 13px; color: #065f46;">Conversion Rate</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #059669;">
                    $<?php echo number_format($conversionMetrics['total_revenue_from_samples'] ?? 0, 0); ?>
                </div>
                <div style="font-size: 13px; color: #065f46;">Revenue Generated</div>
            </div>
        </div>
    </div>

    <!-- Pending Approvals -->
    <?php if (!empty($pendingApprovals)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-top: 0; color: #d97706;">⏳ Pending Approvals (<?php echo count($pendingApprovals); ?>)</h2>
        <table class="sample-table">
            <thead>
                <tr>
                    <th>Request #</th>
                    <th>Date</th>
                    <th>Agent</th>
                    <th>Company</th>
                    <th>Product</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingApprovals as $req): ?>
                <tr>
                    <td><strong>#<?php echo $req['id']; ?></strong></td>
                    <td><?php echo date('M j, Y', strtotime($req['requested_at'])); ?></td>
                    <td><?php echo htmlspecialchars($req['agent_name']); ?></td>
                    <td><?php echo htmlspecialchars($req['company_name']); ?></td>
                    <td><?php echo htmlspecialchars($req['product_name']); ?></td>
                    <td>
                        <button onclick="approveRequest(<?php echo $req['id']; ?>)" class="btn" style="font-size: 11px; padding: 4px 8px; background: #059669;">
                            ✓ Approve
                        </button>
                        <button onclick="rejectRequest(<?php echo $req['id']; ?>)" class="btn" style="font-size: 11px; padding: 4px 8px; background: #dc2626;">
                            ✗ Reject
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Inventory Levels -->
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-top: 0;">📦 Sample Inventory</h2>
        <table class="sample-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Available</th>
                    <th>Reserved</th>
                    <th>Shipped</th>
                    <th>Truly Available</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory as $inv): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($inv['name']); ?></strong></td>
                    <td style="font-family: monospace; font-size: 11px;"><?php echo htmlspecialchars($inv['sku']); ?></td>
                    <td><?php echo $inv['quantity_available']; ?></td>
                    <td><?php echo $inv['quantity_reserved']; ?></td>
                    <td><?php echo $inv['quantity_shipped']; ?></td>
                    <td style="font-weight: 600; color: <?php echo $inv['truly_available'] <= $inv['low_stock_threshold'] ? '#dc2626' : '#059669'; ?>;">
                        <?php echo $inv['truly_available']; ?>
                    </td>
                    <td>
                        <?php if ($inv['truly_available'] <= $inv['low_stock_threshold']): ?>
                            <span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                LOW STOCK
                            </span>
                        <?php else: ?>
                            <span style="color: #6b7280; font-size: 11px;">In Stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Agent Shipping Charges -->
    <?php if (!empty($agentCharges)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <h2 style="margin-top: 0;">💸 Agent Shipping Charges</h2>
        <table class="sample-table">
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Total Charges</th>
                    <th>Charge Count</th>
                    <th>Pending</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agentCharges as $charge): ?>
                <tr>
                    <td><?php echo htmlspecialchars($charge['agent_name']); ?></td>
                    <td style="font-weight: 600;">$<?php echo number_format($charge['total_charged'], 2); ?></td>
                    <td><?php echo $charge['charge_count']; ?></td>
                    <td style="color: #d97706;">$<?php echo number_format($charge['pending_charges'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Recent Shipments -->
    <div class="card">
        <h2 style="margin-top: 0;">🚚 Recent Shipments</h2>
        <?php if (empty($shippedSamples)): ?>
            <p style="text-align: center; color: #6b7280; padding: 40px;">No shipped samples yet.</p>
        <?php else: ?>
            <table class="sample-table">
                <thead>
                    <tr>
                        <th>Request #</th>
                        <th>Company</th>
                        <th>Product</th>
                        <th>Agent</th>
                        <th>Carrier</th>
                        <th>Tracking</th>
                        <th>Est. Delivery</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shippedSamples as $sample): ?>
                    <tr>
                        <td><strong>#<?php echo $sample['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($sample['company_name']); ?></td>
                        <td><?php echo htmlspecialchars($sample['product_name']); ?></td>
                        <td style="font-size: 12px;"><?php echo htmlspecialchars($sample['agent_name']); ?></td>
                        <td style="font-size: 12px;"><?php echo htmlspecialchars($sample['carrier'] ?? '—'); ?></td>
                        <td style="font-family: monospace; font-size: 11px;">
                            <?php echo htmlspecialchars($sample['tracking_number'] ?? '—'); ?>
                        </td>
                        <td><?php echo $sample['estimated_delivery_date'] ? date('M j', strtotime($sample['estimated_delivery_date'])) : '—'; ?></td>
                        <td>
                            <span style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; 
                                         background: <?php echo $sample['status'] === 'delivered' ? '#dcfce7' : '#dbeafe'; ?>; 
                                         color: <?php echo $sample['status'] === 'delivered' ? '#14532d' : '#1e40af'; ?>;">
                                <?php echo ucfirst($sample['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function approveRequest(requestId) {
    if (confirm('Approve this sample request?')) {
        fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=approve', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_id: requestId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                alert('Sample request approved! Customer has been notified.');
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
}

function rejectRequest(requestId) {
    const reason = prompt('Enter rejection reason:');
    if (reason) {
        fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=reject', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_id: requestId, admin_notes: reason })
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                alert('Sample request rejected.');
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




