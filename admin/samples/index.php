<?php
/**
 * Admin Sample Management Dashboard
 * 
 * Manage sample requests, inventory, and shipping
 */

$SEO = [
    'title' => 'Sample Management — Admin — Product',
    'description' => 'Manage sample requests and inventory',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/sample-inventory-helpers.php';

start_session();
$userId = current_user_id();
$adminId = current_admin_id();

if (!$adminId && !user_has_role($userId, 'admin')) {
    flash_set('error', 'Admin access required');
    redirect(path_with_base('/index.php'));
}

$pdo = db();

// Get inventory status
$inventory = get_all_sample_inventory($pdo);

// Get pending requests (exclude placeholders - placeholders have no address)
$stmt = $pdo->query("
    SELECT 
        sr.*,
        p.sku AS product_sku,
        p.name AS product_name,
        CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
        l.company_name AS lead_company,
        wa.business_name AS account_business
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users u ON u.id = sr.agent_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE sr.status = 'pending'
    AND sr.recipient_address_line1 IS NOT NULL 
    AND sr.recipient_address_line1 != ''
    AND sr.recipient_city IS NOT NULL
    AND sr.recipient_city != ''
    ORDER BY sr.requested_at DESC
");
$pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get approved requests (awaiting shipment) - exclude placeholders
$stmt = $pdo->query("
    SELECT 
        sr.*,
        p.sku AS product_sku,
        p.name AS product_name,
        CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
        l.company_name AS lead_company,
        wa.business_name AS account_business
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users u ON u.id = sr.agent_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE sr.status = 'approved'
    AND sr.recipient_address_line1 IS NOT NULL 
    AND sr.recipient_address_line1 != ''
    AND sr.recipient_city IS NOT NULL
    AND sr.recipient_city != ''
    ORDER BY sr.approved_at DESC
");
$approvedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get shipped requests - all shipped should have addresses
$stmt = $pdo->query("
    SELECT 
        sr.*,
        p.sku AS product_sku,
        p.name AS product_name,
        CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
        l.company_name AS lead_company,
        wa.business_name AS account_business
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users u ON u.id = sr.agent_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE sr.status IN ('shipped', 'delivered')
    ORDER BY sr.shipped_at DESC
    LIMIT 50
");
$shippedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate expense summary
$stmt = $pdo->query("
    SELECT 
        COUNT(*) AS total_shipped,
        COALESCE(SUM(shipping_cost), 0) AS total_shipping_cost,
        COALESCE(AVG(shipping_cost), 0) AS avg_shipping_cost,
        COALESCE(SUM(CASE WHEN shipped_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN shipping_cost ELSE 0 END), 0) AS mtd_shipping,
        COALESCE(SUM(CASE WHEN QUARTER(shipped_at) = QUARTER(NOW()) AND YEAR(shipped_at) = YEAR(NOW()) THEN shipping_cost ELSE 0 END), 0) AS qtd_shipping,
        COALESCE(SUM(CASE WHEN YEAR(shipped_at) = YEAR(NOW()) THEN shipping_cost ELSE 0 END), 0) AS ytd_shipping
    FROM sample_requests
    WHERE status IN ('shipped', 'delivered') AND request_type = 'shipping'
");
$expenseSummary = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<div class="container">
    <div class="page-header">
        <div>
            <h1 class="page-title">📦 Sample Management</h1>
            <div class="page-subtitle muted">Manage sample requests and inventory</div>
        </div>
        <div class="header-actions" style="display:flex;gap:12px;flex-wrap:wrap;">
            <button onclick="syncAllSampleAddresses()" class="btn secondary" style="display:flex;align-items:center;gap:6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                Sync Sample Addresses
            </button>
            <?php 
            // Check for placeholder count
            try {
                $placeholderStmt = $pdo->query("
                    SELECT COUNT(*) FROM sample_requests 
                    WHERE status = 'pending'
                    AND (agent_notes IS NULL OR agent_notes = '')
                    AND (recipient_address_line1 IS NULL OR recipient_address_line1 = '')
                ");
                $placeholderCount = intval($placeholderStmt->fetchColumn());
            } catch (Throwable $e) {
                $placeholderCount = 0;
            }
            ?>
            <?php if ($placeholderCount > 0): ?>
            <a href="cleanup-placeholders.php" class="btn secondary" style="background:#fef3c7;color:#92400e;border:1px solid #f59e0b;display:flex;align-items:center;gap:6px;">
                🧹 Cleanup Placeholders
                <span style="background:#f59e0b;color:white;padding:2px 8px;border-radius:12px;font-size:11px;">
                    <?php echo $placeholderCount; ?>
                </span>
            </a>
            <?php endif; ?>
            <a href="<?php echo outbound_path('/admin/'); ?>" class="btn secondary">← Back to Admin</a>
            <a href="inventory.php" class="btn primary">📊 Manage Inventory</a>
        </div>
    </div>

    <!-- Inventory Status Widget -->
    <div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);">
        <h3 style="margin:0 0 16px;display:flex;align-items:center;gap:8px;">
            <span>📊</span> Inventory Status
        </h3>
        
        <div class="inventory-grid">
            <?php foreach ($inventory as $inv): ?>
            <div class="inventory-card <?php echo $inv['is_low_stock'] ? 'low-stock' : ''; ?>">
                <div class="inventory-header">
                    <div class="inventory-name"><?php echo htmlspecialchars($inv['product_name']); ?></div>
                    <div class="inventory-sku"><?php echo htmlspecialchars($inv['sku']); ?></div>
                </div>
                <div class="inventory-stats">
                    <div class="stat-item">
                        <div class="stat-value <?php echo $inv['is_low_stock'] ? 'text-danger' : 'text-success'; ?>">
                            <?php echo $inv['quantity_available']; ?>
                        </div>
                        <div class="stat-label">Available</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $inv['quantity_reserved']; ?></div>
                        <div class="stat-label">Reserved</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $inv['quantity_shipped']; ?></div>
                        <div class="stat-label">Shipped</div>
                    </div>
                </div>
                <?php if ($inv['is_low_stock']): ?>
                <div class="low-stock-alert">
                    ⚠️ Low Stock - Below threshold of <?php echo $inv['low_stock_threshold']; ?> units
                </div>
                <?php endif; ?>
                <div style="margin-top:12px;">
                    <button class="btn small primary" onclick="openRestockModal(<?php echo $inv['product_id']; ?>, '<?php echo htmlspecialchars($inv['product_name']); ?>')">
                        + Restock
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Expense Summary -->
    <div class="stats-grid" style="margin-bottom:24px;">
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($expenseSummary['mtd_shipping'], 2); ?></div>
                <div class="stat-label">MTD Shipping</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📈</div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($expenseSummary['qtd_shipping'], 2); ?></div>
                <div class="stat-label">QTD Shipping</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🎯</div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($expenseSummary['ytd_shipping'], 2); ?></div>
                <div class="stat-label">YTD Shipping</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($expenseSummary['avg_shipping_cost'], 2); ?></div>
                <div class="stat-label">Avg per Sample</div>
            </div>
        </div>
    </div>

    <!-- Pending Requests -->
    <div class="card" style="margin-bottom:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                <span>⏳</span> Pending Requests
                <span class="badge" style="background:#fef3c7;color:#92400e;padding:4px 12px;border-radius:12px;font-size:12px;margin-left:8px;">
                    <?php echo count($pendingRequests); ?>
                </span>
            </h3>
            <button id="bulkDeleteBtn" class="btn" style="display:none;background:#dc2626;color:white;" onclick="bulkDeleteSamples()">
                🗑️ Delete Selected
            </button>
        </div>
        
        <?php if (empty($pendingRequests)): ?>
            <div class="empty-state">
                <div style="font-size:48px;margin-bottom:12px;">✅</div>
                <p>No pending sample requests</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">
                                <input type="checkbox" id="selectAllPending" onchange="toggleAllPending(this)">
                            </th>
                            <th>Agent</th>
                            <th>Lead/Account</th>
                            <th>Sample</th>
                            <th>Qty</th>
                            <th>Method</th>
                            <th>Destination</th>
                            <th>Requested</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $req): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="sample-checkbox" value="<?php echo $req['id']; ?>" onchange="updateBulkDeleteButton()">
                            </td>
                            <td><?php echo htmlspecialchars($req['agent_name']); ?></td>
                            <td><?php echo htmlspecialchars($req['lead_company'] ?: $req['account_business'] ?: '—'); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($req['product_name']); ?></div>
                                <div class="muted small"><?php echo htmlspecialchars($req['product_sku']); ?></div>
                            </td>
                            <td><?php echo $req['quantity']; ?></td>
                            <td>
                                <span class="badge <?php echo $req['request_type'] === 'shipping' ? 'badge-info' : 'badge-success'; ?>">
                                    <?php echo $req['request_type'] === 'shipping' ? '📮 Ship' : '🚗 Drop-off'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['request_type'] === 'shipping'): ?>
                                    <?php 
                                    $hasAddress = !empty($req['recipient_address_line1']) && !empty($req['recipient_city']) && !empty($req['recipient_zip']);
                                    ?>
                                    <div style="font-size:13px;<?php echo !$hasAddress ? 'color:#dc2626;font-weight:600;' : ''; ?>">
                                        <?php echo htmlspecialchars(($req['recipient_city'] ?: '(no city)') . ', ' . ($req['recipient_state'] ?: '(no state)')); ?>
                                        <?php if (!$hasAddress): ?>
                                            <div style="font-size:10px;color:#f59e0b;">⚠️ INCOMPLETE</div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="muted">In-person</div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y g:i A', strtotime($req['requested_at'])); ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <?php 
                                    $hasAddress = !empty($req['recipient_address_line1']) && !empty($req['recipient_city']) && !empty($req['recipient_zip']);
                                    $canSyncFromLead = !empty($req['lead_id']);
                                    $canSyncFromAccount = !empty($req['account_id']);
                                    ?>
                                    <?php if (!$hasAddress): ?>
                                        <button class="btn small" style="background:#f59e0b;color:white;" onclick="editSampleAddress(<?php echo $req['id']; ?>, <?php echo $canSyncFromLead ? $req['lead_id'] : 'null'; ?>, <?php echo $canSyncFromAccount ? $req['account_id'] : 'null'; ?>)" title="Address incomplete">
                                            📍 Fix Address
                                        </button>
                                    <?php else: ?>
                                        <button class="btn small secondary" onclick="editSampleAddress(<?php echo $req['id']; ?>, <?php echo $canSyncFromLead ? $req['lead_id'] : 'null'; ?>, <?php echo $canSyncFromAccount ? $req['account_id'] : 'null'; ?>)" title="Edit address">
                                            📝 Address
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn small success" onclick="approveRequest(<?php echo $req['id']; ?>)">✓ Approve</button>
                                    <button class="btn small danger" onclick="rejectRequest(<?php echo $req['id']; ?>)">✗ Reject</button>
                                    <button class="btn small" style="background:#6b7280;color:white;" onclick="deleteSingleSample(<?php echo $req['id']; ?>)">🗑️ Delete</button>
                                    <button class="btn small secondary" onclick="viewRequestDetail(<?php echo $req['id']; ?>)">View</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Approved Requests (Awaiting Shipment) -->
    <?php if (!empty($approvedRequests)): ?>
    <div class="card" style="margin-bottom:24px;">
        <h3 style="margin:0 0 16px;display:flex;align-items:center;gap:8px;">
            <span>📋</span> Approved - Awaiting Shipment
            <span class="badge" style="background:#dbeafe;color:#1e40af;padding:4px 12px;border-radius:12px;font-size:12px;margin-left:8px;">
                <?php echo count($approvedRequests); ?>
            </span>
        </h3>
        
        <div class="table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Sample</th>
                        <th>Recipient</th>
                        <th>Address</th>
                        <th>Approved</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvedRequests as $req): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($req['agent_name']); ?></td>
                        <td><?php echo htmlspecialchars($req['product_name']) . ' x' . $req['quantity']; ?></td>
                        <td><?php echo htmlspecialchars($req['recipient_name']); ?></td>
                        <td>
                            <?php if ($req['request_type'] === 'shipping'): ?>
                                <?php 
                                $hasAddress = !empty($req['recipient_address_line1']) && !empty($req['recipient_city']) && !empty($req['recipient_zip']);
                                ?>
                                <div <?php echo !$hasAddress ? 'style="color:#dc2626;font-weight:600;"' : ''; ?>>
                                    <?php echo htmlspecialchars($req['recipient_city'] ?: '(no city)') . ', ' . htmlspecialchars($req['recipient_state'] ?: '(no state)') . ' ' . htmlspecialchars($req['recipient_zip'] ?: '(no zip)'); ?>
                                    <?php if (!$hasAddress): ?>
                                        <div style="font-size:10px;color:#f59e0b;">⚠️ INCOMPLETE</div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="muted">Drop-off</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M j, g:i A', strtotime($req['approved_at'])); ?></td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <?php 
                                $canSyncFromLead = !empty($req['lead_id']);
                                $canSyncFromAccount = !empty($req['account_id']);
                                ?>
                                <button class="btn small secondary" onclick="editSampleAddress(<?php echo $req['id']; ?>, <?php echo $canSyncFromLead ? $req['lead_id'] : 'null'; ?>, <?php echo $canSyncFromAccount ? $req['account_id'] : 'null'; ?>)" title="Edit address">
                                    📝 Address
                                </button>
                                <?php if ($req['request_type'] === 'shipping'): ?>
                                    <button class="btn small primary" onclick="markAsShipped(<?php echo $req['id']; ?>)">
                                        ✓ Mark Shipped
                                    </button>
                                <?php else: ?>
                                    <span class="muted small">Awaiting agent drop-off</span>
                                <?php endif; ?>
                                <button class="btn small secondary" onclick="viewRequestDetail(<?php echo $req['id']; ?>)">View</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Shipped Samples -->
    <div class="card">
        <h3 style="margin:0 0 16px;display:flex;align-items:center;gap:8px;">
            <span>📫</span> Shipped Samples
            <span class="badge" style="background:#dcfce7;color:#059669;padding:4px 12px;border-radius:12px;font-size:12px;margin-left:8px;">
                <?php echo count($shippedRequests); ?>
            </span>
        </h3>
        
        <?php if (empty($shippedRequests)): ?>
            <div class="empty-state">
                <div style="font-size:48px;margin-bottom:12px;">📦</div>
                <p>No shipped samples yet</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Sample</th>
                            <th>Recipient</th>
                            <th>Carrier</th>
                            <th>Tracking</th>
                            <th>Cost</th>
                            <th>Shipped</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shippedRequests as $req): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($req['agent_name']); ?></td>
                            <td><?php echo htmlspecialchars($req['product_name']) . ' x' . $req['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($req['recipient_name']); ?></td>
                            <td><?php echo htmlspecialchars($req['shipping_carrier'] ?: '—'); ?></td>
                            <td>
                                <?php if ($req['tracking_number']): ?>
                                    <a href="<?php echo getTrackingUrl($req['shipping_carrier'], $req['tracking_number']); ?>" 
                                       target="_blank" class="link-primary">
                                        <?php echo htmlspecialchars($req['tracking_number']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['shipping_cost']): ?>
                                    $<?php echo number_format($req['shipping_cost'], 2); ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($req['shipped_at'])); ?></td>
                            <td>
                                <span class="badge badge-success">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <?php 
                                    $canSyncFromLead = !empty($req['lead_id']);
                                    $canSyncFromAccount = !empty($req['account_id']);
                                    ?>
                                    <button class="btn small secondary" onclick="editSampleAddress(<?php echo $req['id']; ?>, <?php echo $canSyncFromLead ? $req['lead_id'] : 'null'; ?>, <?php echo $canSyncFromAccount ? $req['account_id'] : 'null'; ?>)" title="Edit address">
                                        📝 Address
                                    </button>
                                    <?php if ($req['tracking_number'] && $req['request_type'] === 'shipping'): ?>
                                        <button class="btn small secondary" onclick="resendTracking(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['tracking_number']); ?>')">
                                            📧 Resend Tracking
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

<!-- Edit Sample Address Modal -->
<div id="editAddressModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;padding:24px;width:600px;max-width:90vw;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-top:0;">📍 Edit Sample Request Address</h3>
        
        <div id="addressSyncOptions" style="margin-bottom:20px;padding:16px;background:#f0f9ff;border:1px solid #0369a1;border-radius:8px;display:none;">
            <div style="font-weight:600;margin-bottom:12px;color:#0369a1;">🔄 Auto-Sync Address From:</div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button type="button" id="syncFromLeadBtn" class="btn small" style="background:#059669;color:white;" onclick="syncSampleAddressFromLead()">
                    Sync from Lead
                </button>
                <button type="button" id="syncFromAccountBtn" class="btn small" style="background:#0369a1;color:white;" onclick="syncSampleAddressFromAccount()">
                    Sync from Account
                </button>
            </div>
            <div style="font-size:12px;color:#075985;margin-top:8px;">
                Automatically copies address from linked CRM lead or wholesale account
            </div>
        </div>
        
        <form id="editAddressForm" onsubmit="return saveSampleAddress(event);">
            <input type="hidden" name="request_id" id="edit_addr_request_id">
            <input type="hidden" name="lead_id" id="edit_addr_lead_id">
            <input type="hidden" name="account_id" id="edit_addr_account_id">
            
            <div style="display:grid;gap:16px;">
                <div>
                    <label>Recipient Name *</label>
                    <input type="text" name="recipient_name" id="edit_addr_recipient_name" required class="input" style="width:100%;">
                </div>
                
                <div>
                    <label>Address Line 1 *</label>
                    <input type="text" name="recipient_address_line1" id="edit_addr_line1" required class="input" style="width:100%;">
                </div>
                
                <div>
                    <label>Address Line 2</label>
                    <input type="text" name="recipient_address_line2" id="edit_addr_line2" class="input" style="width:100%;">
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div>
                        <label>City *</label>
                        <input type="text" name="recipient_city" id="edit_addr_city" required class="input" style="width:100%;">
                    </div>
                    <div>
                        <label>State *</label>
                        <input type="text" name="recipient_state" id="edit_addr_state" required class="input" style="width:100%;">
                    </div>
                    <div>
                        <label>ZIP *</label>
                        <input type="text" name="recipient_zip" id="edit_addr_zip" required class="input" style="width:100%;">
                    </div>
                </div>
                
                <div>
                    <label>Country</label>
                    <input type="text" name="recipient_country" id="edit_addr_country" value="USA" class="input" style="width:100%;">
                </div>
                
                <div>
                    <label>Phone</label>
                    <input type="tel" name="recipient_phone" id="edit_addr_phone" class="input" style="width:100%;">
                </div>
            </div>
            
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn secondary" onclick="closeEditAddressModal()">Cancel</button>
                <button type="submit" class="btn primary">💾 Save Address</button>
            </div>
        </form>
    </div>
</div>

<!-- Restock Modal -->
<div id="restockModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;padding:24px;width:500px;max-width:90vw;">
        <h3 style="margin-top:0;">Restock Sample Inventory</h3>
        <form id="restockForm">
            <input type="hidden" name="product_id" id="restock_product_id">
            <div style="display:grid;gap:16px;">
                <div>
                    <label>Product</label>
                    <input type="text" id="restock_product_name" readonly class="input" style="width:100%;background:#f9fafb;">
                </div>
                <div>
                    <label>Quantity to Add *</label>
                    <input type="number" name="quantity" id="restock_quantity" min="1" required class="input" style="width:100%;">
                </div>
                <div>
                    <label>Reason *</label>
                    <textarea name="reason" id="restock_reason" rows="3" required class="input" style="width:100%;" placeholder="e.g., Received shipment from supplier"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn secondary" onclick="closeRestockModal()">Cancel</button>
                <button type="submit" class="btn primary">Add Inventory</button>
            </div>
        </form>
    </div>
</div>

<!-- Mark Shipped Modal -->
<div id="markShippedModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;padding:24px;width:600px;max-width:90vw;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-top:0;">Mark Sample as Shipped</h3>
        <form id="markShippedForm">
            <input type="hidden" name="request_id" id="ship_request_id">
            <div style="display:grid;gap:16px;">
                <div>
                    <label>Tracking Number *</label>
                    <input type="text" name="tracking_number" id="tracking_number" required class="input" style="width:100%;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label>Carrier *</label>
                        <select name="carrier" id="ship_carrier" required class="input" style="width:100%;">
                            <option value="">Select carrier</option>
                            <option value="USPS">USPS</option>
                            <option value="UPS">UPS</option>
                            <option value="FedEx">FedEx</option>
                        </select>
                    </div>
                    <div>
                        <label>Service</label>
                        <input type="text" name="service" id="ship_service" class="input" style="width:100%;" placeholder="e.g., Priority Mail">
                    </div>
                </div>
                <div>
                    <label>Shipping Cost *</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6b7280;">$</span>
                        <input type="number" name="shipping_cost" id="shipping_cost" step="0.01" min="0" required 
                               class="input" style="width:100%;padding-left:28px;">
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn secondary" onclick="closeMarkShippedModal()">Cancel</button>
                <button type="submit" class="btn primary">Mark as Shipped</button>
            </div>
        </form>
    </div>
</div>

<!-- Request Detail Modal -->
<div id="requestDetailModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;padding:24px;width:700px;max-width:90vw;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;">Request Details</h3>
            <button class="btn secondary small" onclick="closeRequestDetailModal()">Close</button>
        </div>
        <div id="requestDetailContent">
            <div class="muted">Loading...</div>
        </div>
    </div>
</div>

<style>
.inventory-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.inventory-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
}

.inventory-card.low-stock {
    border-color: #fbbf24;
    background: #fffbeb;
}

.inventory-header {
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.inventory-name {
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 4px;
}

.inventory-sku {
    font-size: 12px;
    color: #6b7280;
    font-family: monospace;
}

.inventory-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 12px;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
}

.stat-value.text-success {
    color: #059669;
}

.stat-value.text-danger {
    color: #dc2626;
}

.stat-label {
    font-size: 11px;
    color: #6b7280;
    margin-top: 4px;
}

.low-stock-alert {
    background: #fef3c7;
    color: #92400e;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 12px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.stat-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}

.stat-icon {
    font-size: 32px;
}

.stat-content .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #0066FF;
}

.stat-content .stat-label {
    font-size: 13px;
    color: #6b7280;
    margin-top: 4px;
}

.admin-table {
    width: 100%;
    border-collapse: collapse;
}

.admin-table thead tr {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.admin-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
}

.admin-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.admin-table tbody tr:hover {
    background: #f9fafb;
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.badge-success {
    background: #dcfce7;
    color: #059669;
}
</style>

<script>
async function approveRequest(requestId) {
    if (!confirm('Approve this sample request?')) return;
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=approve', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_id: requestId })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Request approved successfully');
        location.reload();
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function rejectRequest(requestId) {
    const reason = prompt('Enter rejection reason:');
    if (!reason) return;
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=reject', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                request_id: requestId,
                admin_notes: reason
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Request rejected and inventory released');
        location.reload();
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

function markAsShipped(requestId) {
    document.getElementById('ship_request_id').value = requestId;
    document.getElementById('markShippedModal').style.display = 'block';
}

function closeMarkShippedModal() {
    document.getElementById('markShippedModal').style.display = 'none';
}

document.getElementById('markShippedForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        request_id: parseInt(formData.get('request_id')),
        tracking_number: formData.get('tracking_number'),
        carrier: formData.get('carrier'),
        service: formData.get('service'),
        shipping_cost: parseFloat(formData.get('shipping_cost'))
    };
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=mark_shipped', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Sample marked as shipped successfully');
        location.reload();
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
});

function openRestockModal(productId, productName) {
    document.getElementById('restock_product_id').value = productId;
    document.getElementById('restock_product_name').value = productName;
    document.getElementById('restockModal').style.display = 'block';
}

function closeRestockModal() {
    document.getElementById('restockModal').style.display = 'none';
    document.getElementById('restockForm').reset();
}

document.getElementById('restockForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const productId = parseInt(formData.get('product_id'));
    const quantity = parseInt(formData.get('quantity'));
    const reason = formData.get('reason');
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=restock', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                product_id: productId,
                quantity: quantity,
                reason: reason
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Inventory restocked successfully');
        location.reload();
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
});

async function viewRequestDetail(requestId) {
    const modal = document.getElementById('requestDetailModal');
    const content = document.getElementById('requestDetailContent');
    
    modal.style.display = 'block';
    content.innerHTML = '<div class="muted">Loading...</div>';
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=get&id=' + requestId);
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        const req = result.request;
        let html = `
            <div style="display:grid;gap:16px;">
                <div><strong>Agent:</strong> ${escapeHtml(req.agent_name)}</div>
                <div><strong>Sample:</strong> ${escapeHtml(req.product_name)} (${escapeHtml(req.product_sku)})</div>
                <div><strong>Quantity:</strong> ${req.quantity}</div>
                <div><strong>Method:</strong> ${req.request_type === 'shipping' ? '📮 Shipping' : '🚗 Drop-off'}</div>
                <div><strong>Recipient:</strong> ${escapeHtml(req.recipient_name)}</div>
        `;
        
        if (req.request_type === 'shipping') {
            html += `
                <div><strong>Address:</strong><br>
                    ${escapeHtml(req.recipient_address_line1 || '')}<br>
                    ${req.recipient_address_line2 ? escapeHtml(req.recipient_address_line2) + '<br>' : ''}
                    ${escapeHtml(req.recipient_city || '')}, ${escapeHtml(req.recipient_state || '')} ${escapeHtml(req.recipient_zip || '')}
                </div>
            `;
        }
        
        if (req.agent_notes) {
            html += `<div><strong>Agent Notes:</strong><br>${escapeHtml(req.agent_notes)}</div>`;
        }
        
        html += `
                <div><strong>Requested:</strong> ${new Date(req.requested_at).toLocaleString()}</div>
                <div><strong>Status:</strong> <span class="badge badge-info">${req.status}</span></div>
            </div>
        `;
        
        content.innerHTML = html;
        
    } catch (error) {
        content.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
    }
}

function closeRequestDetailModal() {
    document.getElementById('requestDetailModal').style.display = 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

async function resendTracking(requestId, trackingNumber) {
    if (!confirm(`Resend tracking information for tracking number ${trackingNumber}?`)) return;
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=resend_tracking', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_id: requestId })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Tracking information resent successfully');
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

// Edit Sample Address Functions
async function editSampleAddress(requestId, leadId, accountId) {
    try {
        // Fetch current sample request data
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=get&id=' + requestId);
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        const req = result.request;
        
        // Populate form
        document.getElementById('edit_addr_request_id').value = requestId;
        document.getElementById('edit_addr_lead_id').value = leadId || '';
        document.getElementById('edit_addr_account_id').value = accountId || '';
        document.getElementById('edit_addr_recipient_name').value = req.recipient_name || '';
        document.getElementById('edit_addr_line1').value = req.recipient_address_line1 || '';
        document.getElementById('edit_addr_line2').value = req.recipient_address_line2 || '';
        document.getElementById('edit_addr_city').value = req.recipient_city || '';
        document.getElementById('edit_addr_state').value = req.recipient_state || '';
        document.getElementById('edit_addr_zip').value = req.recipient_zip || '';
        document.getElementById('edit_addr_country').value = req.recipient_country || 'USA';
        document.getElementById('edit_addr_phone').value = req.recipient_phone || '';
        
        // Show/hide sync options based on linked entities
        const syncOptions = document.getElementById('addressSyncOptions');
        const syncLeadBtn = document.getElementById('syncFromLeadBtn');
        const syncAccountBtn = document.getElementById('syncFromAccountBtn');
        
        if (leadId || accountId) {
            syncOptions.style.display = 'block';
            if (syncLeadBtn) syncLeadBtn.style.display = leadId ? 'inline-block' : 'none';
            if (syncAccountBtn) syncAccountBtn.style.display = accountId ? 'inline-block' : 'none';
        } else {
            syncOptions.style.display = 'none';
        }
        
        // Open modal
        document.getElementById('editAddressModal').style.display = 'block';
        
    } catch (error) {
        alert('Error loading sample request: ' + error.message);
    }
}

function closeEditAddressModal() {
    document.getElementById('editAddressModal').style.display = 'none';
}

async function syncSampleAddressFromLead() {
    try {
        const leadId = document.getElementById('edit_addr_lead_id').value;
        if (!leadId) {
            alert('No lead linked to this sample request');
            return;
        }
        
        // Fetch lead address
        const response = await fetch('<?php echo outbound_path('/api/sample-request-api.php'); ?>?action=get_lead_address&lead_id=' + leadId);
        const result = await response.json();
        
        if (!result.ok) {
            alert('Error fetching lead address: ' + (result.error || 'Unknown error'));
            return;
        }
        
        // Populate form with lead data
        if (result.recipient_name) document.getElementById('edit_addr_recipient_name').value = result.recipient_name;
        if (result.address_line1) document.getElementById('edit_addr_line1').value = result.address_line1;
        if (result.address_line2) document.getElementById('edit_addr_line2').value = result.address_line2 || '';
        if (result.city) document.getElementById('edit_addr_city').value = result.city;
        if (result.state) document.getElementById('edit_addr_state').value = result.state;
        if (result.zip) document.getElementById('edit_addr_zip').value = result.zip;
        if (result.country) document.getElementById('edit_addr_country').value = result.country;
        if (result.phone) document.getElementById('edit_addr_phone').value = result.phone;
        
        // Flash success
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '✓ Synced!';
        btn.style.background = '#10b981';
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.style.background = '#059669';
        }, 2000);
        
    } catch (error) {
        alert('Error syncing from lead: ' + error.message);
    }
}

async function syncSampleAddressFromAccount() {
    try {
        const accountId = document.getElementById('edit_addr_account_id').value;
        if (!accountId) {
            alert('No account linked to this sample request');
            return;
        }
        
        // Fetch account address
        const response = await fetch('<?php echo outbound_path('/api/sample-request-api.php'); ?>?action=get_account_address&account_id=' + accountId);
        const result = await response.json();
        
        if (!result.ok) {
            alert('Error fetching account address: ' + (result.error || 'Unknown error'));
            return;
        }
        
        // Populate form with account data
        if (result.recipient_name) document.getElementById('edit_addr_recipient_name').value = result.recipient_name;
        if (result.address_line1) document.getElementById('edit_addr_line1').value = result.address_line1;
        if (result.address_line2) document.getElementById('edit_addr_line2').value = result.address_line2 || '';
        if (result.city) document.getElementById('edit_addr_city').value = result.city;
        if (result.state) document.getElementById('edit_addr_state').value = result.state;
        if (result.zip) document.getElementById('edit_addr_zip').value = result.zip;
        if (result.country) document.getElementById('edit_addr_country').value = result.country;
        if (result.phone) document.getElementById('edit_addr_phone').value = result.phone;
        
        // Flash success
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '✓ Synced!';
        btn.style.background = '#10b981';
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.style.background = '#0369a1';
        }, 2000);
        
    } catch (error) {
        alert('Error syncing from account: ' + error.message);
    }
}

async function saveSampleAddress(event) {
    event.preventDefault();
    
    try {
        const form = document.getElementById('editAddressForm');
        const formData = new FormData(form);
        
        const data = {
            request_id: formData.get('request_id'),
            recipient_name: formData.get('recipient_name'),
            recipient_address_line1: formData.get('recipient_address_line1'),
            recipient_address_line2: formData.get('recipient_address_line2'),
            recipient_city: formData.get('recipient_city'),
            recipient_state: formData.get('recipient_state'),
            recipient_zip: formData.get('recipient_zip'),
            recipient_country: formData.get('recipient_country'),
            recipient_phone: formData.get('recipient_phone')
        };
        
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=update_address', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to update address');
        }
        
        alert('✓ Address updated successfully!');
        closeEditAddressModal();
        location.reload();
        
    } catch (error) {
        alert('Error saving address: ' + error.message);
    }
    
    return false;
}

// Close modal on backdrop click
document.addEventListener('click', function(e) {
    if (e.target.id === 'editAddressModal') {
        closeEditAddressModal();
    }
});

// Bulk sync all sample addresses
async function syncAllSampleAddresses() {
    try {
        if (!confirm('Sync addresses for ALL sample requests from their linked leads/accounts?\n\n⚠️ This will:\n• Fill missing address fields from linked CRM leads\n• Fill missing address fields from linked accounts\n• Skip samples with no lead or account link\n\nContinue?')) {
            return;
        }
        
        const btn = event.target.closest('button');
        const originalHtml = btn ? btn.innerHTML : null;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⚙️</span> Syncing...';
        }
        
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=bulk_sync_addresses', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'}
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert(`✓ Sample Address Sync Complete!\n\n` +
              `Synced from Leads: ${result.synced_from_leads}\n` +
              `Synced from Accounts: ${result.synced_from_accounts}\n` +
              `Skipped (no link): ${result.skipped}\n` +
              `Failed: ${result.failed}\n` +
              `Total Processed: ${result.total}`);
        
        location.reload();
        
    } catch (error) {
        alert('Error syncing addresses: ' + error.message);
        if (btn && originalHtml) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

// Checkbox management
function toggleAllPending(checkbox) {
    const checkboxes = document.querySelectorAll('.sample-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkDeleteButton();
}

function updateBulkDeleteButton() {
    const checkedBoxes = document.querySelectorAll('.sample-checkbox:checked');
    const bulkBtn = document.getElementById('bulkDeleteBtn');
    if (bulkBtn) {
        bulkBtn.style.display = checkedBoxes.length > 0 ? 'block' : 'none';
        bulkBtn.textContent = `🗑️ Delete Selected (${checkedBoxes.length})`;
    }
}

// Delete single sample
async function deleteSingleSample(requestId) {
    if (!confirm('Permanently delete this sample request?\n\nThis cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=delete_request', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_id: requestId })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert('Sample request deleted');
        location.reload();
        
    } catch (error) {
        alert('Error deleting sample: ' + error.message);
    }
}

// Bulk delete samples
async function bulkDeleteSamples() {
    const checkedBoxes = document.querySelectorAll('.sample-checkbox:checked');
    const requestIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
    
    if (requestIds.length === 0) {
        alert('No samples selected');
        return;
    }
    
    if (!confirm(`Permanently delete ${requestIds.length} sample request(s)?\n\nThis cannot be undone.`)) {
        return;
    }
    
    const btn = document.getElementById('bulkDeleteBtn');
    const originalText = btn ? btn.innerHTML : null;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⚙️</span> Deleting...';
    }
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=bulk_delete_requests', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ request_ids: requestIds })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error);
        }
        
        alert(`✓ Deleted ${result.deleted} sample request(s)`);
        location.reload();
        
    } catch (error) {
        alert('Error deleting samples: ' + error.message);
        if (btn && originalText) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}
</script>

<!-- Additional Styles -->
<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>

<?php
function getTrackingUrl($carrier, $trackingNumber) {
    $carrier = strtolower($carrier ?? '');
    $trackingNumber = urlencode($trackingNumber);
    
    switch ($carrier) {
        case 'usps':
            return "https://tools.usps.com/go/TrackConfirmAction?tLabels={$trackingNumber}";
        case 'ups':
            return "https://www.ups.com/track?tracknum={$trackingNumber}";
        case 'fedex':
            return "https://www.fedex.com/fedextrack/?trknbr={$trackingNumber}";
        default:
            return '#';
    }
}

require_once __DIR__ . '/../../required/footer.php';
?>





