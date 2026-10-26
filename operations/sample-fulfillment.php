<?php
$SEO = [
    'title' => 'Sample Fulfillment — Operations',
    'description' => 'Manage sample requests, approvals, and shipping.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/sample-email-triggers.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'approve_sample') {
            $requestId = intval($_POST['request_id'] ?? 0);
            
            if ($requestId <= 0) {
                throw new Exception('Invalid request ID');
            }
            
            $pdo->beginTransaction();
            
            // Update sample request status
            $stmt = $pdo->prepare('UPDATE sample_requests SET status = "approved", approved_by = ?, approved_at = NOW() WHERE id = ?');
            $stmt->execute([$userId, $requestId]);
            
            // Get request details for CRM logging
            $stmt = $pdo->prepare('SELECT lead_id, recipient_name FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            
            // Log CRM activity
            if ($request && $request['lead_id']) {
                log_sample_crm_activity(
                    $pdo,
                    $request['lead_id'],
                    'sample_approved',
                    'Sample request approved by operations',
                    ['sample_request_id' => $requestId]
                );
            }
            
            // Send approval email (check if beta sample)
            $stmt = $pdo->prepare('SELECT is_beta_sample FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $isBetaSample = (bool)$stmt->fetchColumn();
            
            if ($isBetaSample) {
                require_once __DIR__ . '/../required/sendgrid-beta-sample-emails.php';
                // Get user details for email
                $stmt = $pdo->prepare('
                    SELECT u.email, u.first_name, u.last_name, p.name as product_name
                    FROM sample_requests sr
                    JOIN users u ON u.id = sr.agent_id
                    JOIN products p ON p.id = sr.sample_product_id
                    WHERE sr.id = ?
                ');
                $stmt->execute([$requestId]);
                $emailData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($emailData) {
                    send_beta_sample_email('approved', [
                        'to' => $emailData['email'],
                        'name' => trim($emailData['first_name'] . ' ' . $emailData['last_name']),
                        'product_name' => $emailData['product_name']
                    ]);
                }
            } else {
                send_sample_email_notification($pdo, 'approved', $requestId);
            }
            
            $pdo->commit();
            
            $message = "Sample request approved for {$request['recipient_name']}. Customer notified via email.";
            
        } elseif ($action === 'reject_sample') {
            $requestId = intval($_POST['request_id'] ?? 0);
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');
            
            if ($requestId <= 0) {
                throw new Exception('Invalid request ID');
            }
            
            $pdo->beginTransaction();
            
            // Update sample request status
            $stmt = $pdo->prepare('UPDATE sample_requests SET status = "rejected", notes = CONCAT(IFNULL(notes, ""), "\nRejection reason: ", ?), updated_at = NOW() WHERE id = ?');
            $stmt->execute([$rejectionReason, $requestId]);
            
            // Get request details
            $stmt = $pdo->prepare('SELECT lead_id FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $leadId = $stmt->fetchColumn();
            
            // Log CRM activity
            if ($leadId) {
                log_sample_crm_activity(
                    $pdo,
                    $leadId,
                    'sample_rejected',
                    'Sample request rejected',
                    ['sample_request_id' => $requestId, 'reason' => $rejectionReason]
                );
            }
            
            $pdo->commit();
            
            $message = "Sample request rejected.";
            
        } elseif ($action === 'create_shipment') {
            $requestId = intval($_POST['request_id'] ?? 0);
            $carrier = $_POST['carrier'] ?? 'USPS';
            $serviceType = $_POST['service_type'] ?? 'Priority Mail';
            $trackingNumber = trim($_POST['tracking_number'] ?? '');
            
            if ($requestId <= 0 || empty($trackingNumber)) {
                throw new Exception('Request ID and tracking number required');
            }
            
            $pdo->beginTransaction();
            
            // Update sample request with shipping info
            $stmt = $pdo->prepare('
                UPDATE sample_requests 
                SET tracking_number = ?, carrier = ?, service_type = ?, 
                    shipped_at = NOW(), status = "shipped"
                WHERE id = ?
            ');
            $stmt->execute([$trackingNumber, $carrier, $serviceType, $requestId]);
            
            // Get request details
            $stmt = $pdo->prepare('SELECT lead_id, recipient_name FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            
            // Log CRM activity
            if ($request && $request['lead_id']) {
                log_sample_crm_activity(
                    $pdo,
                    $request['lead_id'],
                    'sample_shipped',
                    "Sample shipped via {$carrier} - Tracking: {$trackingNumber}",
                    [
                        'sample_request_id' => $requestId,
                        'tracking_number' => $trackingNumber,
                        'carrier' => $carrier
                    ]
                );
            }
            
            // Send shipped emails to customer and agent (check if beta sample)
            $stmt = $pdo->prepare('SELECT is_beta_sample FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $isBetaSample = (bool)$stmt->fetchColumn();
            
            if ($isBetaSample) {
                require_once __DIR__ . '/../required/sendgrid-beta-sample-emails.php';
                // Get user details for email
                $stmt = $pdo->prepare('
                    SELECT u.email, u.first_name, u.last_name, p.name as product_name,
                           sr.tracking_number, sr.shipping_carrier
                    FROM sample_requests sr
                    JOIN users u ON u.id = sr.agent_id
                    JOIN products p ON p.id = sr.sample_product_id
                    WHERE sr.id = ?
                ');
                $stmt->execute([$requestId]);
                $emailData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($emailData) {
                    $trackingUrl = get_tracking_url($carrier, $trackingNumber);
                    send_beta_sample_email('shipped', [
                        'to' => $emailData['email'],
                        'name' => trim($emailData['first_name'] . ' ' . $emailData['last_name']),
                        'product_name' => $emailData['product_name'],
                        'tracking_number' => $emailData['tracking_number'],
                        'carrier' => $emailData['shipping_carrier'],
                        'tracking_url' => $trackingUrl
                    ]);
                }
            } else {
                send_sample_email_notification($pdo, 'shipped', $requestId);
            }
            
            // Update inventory
            $stmt = $pdo->prepare('
                UPDATE sample_inventory si
                JOIN sample_requests sr ON sr.sample_product_id = si.product_id
                SET si.quantity_shipped = si.quantity_shipped + sr.quantity,
                    si.quantity_reserved = si.quantity_reserved - sr.quantity
                WHERE sr.id = ?
            ');
            $stmt->execute([$requestId]);
            
            // Log inventory movement
            $stmt = $pdo->prepare('SELECT sample_product_id, quantity FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $sr = $stmt->fetch();
            
            $stmt = $pdo->prepare('SELECT quantity_available FROM sample_inventory WHERE product_id = ?');
            $stmt->execute([$sr['sample_product_id']]);
            $qtyAfter = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare('
                INSERT INTO sample_inventory_movements (
                    product_id, movement_type, quantity_change, quantity_after,
                    reference_type, reference_id, performed_by, notes
                ) VALUES (?, "shipment", ?, ?, "sample_request", ?, ?, ?)
            ');
            $stmt->execute([
                $sr['sample_product_id'],
                -$sr['quantity'],
                $qtyAfter,
                $requestId,
                $userId,
                "Shipped to {$request['recipient_name']}"
            ]);
            
            $pdo->commit();
            
            $message = "Sample shipped successfully. Tracking: {$trackingNumber}";
            
        } elseif ($action === 'mark_delivered') {
            $requestId = intval($_POST['request_id'] ?? 0);
            
            if ($requestId <= 0) {
                throw new Exception('Invalid request ID');
            }
            
            $pdo->beginTransaction();
            
            // Update sample request status
            $stmt = $pdo->prepare('UPDATE sample_requests SET status = "delivered", delivered_at = NOW() WHERE id = ?');
            $stmt->execute([$requestId]);
            
            // Get request details
            $stmt = $pdo->prepare('SELECT lead_id FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            $leadId = $stmt->fetchColumn();
            
            // Log CRM activity
            if ($leadId) {
                log_sample_crm_activity(
                    $pdo,
                    $leadId,
                    'sample_delivered',
                    'Sample delivered to customer',
                    ['sample_request_id' => $requestId]
                );
            }
            
            // Send delivered email
            send_sample_email_notification($pdo, 'delivered', $requestId);
            
            $pdo->commit();
            
            $message = "Sample marked as delivered. Customer notified.";
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
    
    // Redirect to prevent form resubmission (PRG pattern)
    if ($message || $error) {
        if ($message) flash_set('success', $message);
        if ($error) flash_set('error', $error);
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'pending';
$sourceFilter = $_GET['source'] ?? 'all';
$betaFilter = $_GET['beta'] ?? 'all';

// Build query based on filters
$whereConditions = ['1=1'];
$params = [];

if ($statusFilter !== 'all') {
    $whereConditions[] = 'sr.status = ?';
    $params[] = $statusFilter;
}

if ($sourceFilter !== 'all') {
    $whereConditions[] = 'sr.promo_source = ?';
    $params[] = $sourceFilter;
}

if ($betaFilter === 'beta_only') {
    $whereConditions[] = 'sr.is_beta_sample = 1';
} elseif ($betaFilter === 'non_beta') {
    $whereConditions[] = 'sr.is_beta_sample = 0';
}

$whereClause = implode(' AND ', $whereConditions);

// Get sample requests
$stmt = $pdo->prepare("
    SELECT 
        sr.*,
        p.name as product_name,
        p.sku as product_sku,
        CONCAT(agent.first_name, ' ', agent.last_name) as agent_name,
        COALESCE(l.company_name, wa.business_name, sr.recipient_name) as company_name,
        l.email as lead_email
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    JOIN users agent ON agent.id = sr.agent_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    WHERE {$whereClause}
    ORDER BY sr.requested_at DESC
    LIMIT 100
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Get summary counts
$statusCounts = $pdo->query('
    SELECT status, COUNT(*) as count 
    FROM sample_requests 
    GROUP BY status
')->fetchAll(PDO::FETCH_KEY_PAIR);

$sourceCounts = $pdo->query('
    SELECT COALESCE(promo_source, "other") as source, COUNT(*) as count 
    FROM sample_requests 
    GROUP BY promo_source
')->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📦 Sample Fulfillment</h1>
                <p class="muted">Manage sample requests, approvals, and shipping</p>
            </div>
            <a href="/operations/" class="btn secondary">← Back to Operations</a>
        </div>

        <?php if ($message): ?>
        <div class="alert success" style="margin-bottom: 20px;">
            <strong>✓</strong> <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert error" style="margin-bottom: 20px;">
            <strong>✗</strong> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div class="card" style="padding: 20px; border-left: 4px solid #f59e0b;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Pending Approval</div>
                <div style="font-size: 32px; font-weight: 700; color: #f59e0b;"><?= $statusCounts['pending'] ?? 0 ?></div>
            </div>
            
            <div class="card" style="padding: 20px; border-left: 4px solid #3b82f6;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Approved</div>
                <div style="font-size: 32px; font-weight: 700; color: #3b82f6;"><?= $statusCounts['approved'] ?? 0 ?></div>
            </div>
            
            <div class="card" style="padding: 20px; border-left: 4px solid #8b5cf6;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Shipped</div>
                <div style="font-size: 32px; font-weight: 700; color: #8b5cf6;"><?= $statusCounts['shipped'] ?? 0 ?></div>
            </div>
            
            <div class="card" style="padding: 20px; border-left: 4px solid #059669;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Delivered</div>
                <div style="font-size: 32px; font-weight: 700; color: #059669;"><?= $statusCounts['delivered'] ?? 0 ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card" style="margin-bottom: 20px;">
            <form method="get" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                <div>
                    <label style="font-size: 12px; color: #64748b; display: block; margin-bottom: 4px;">Status</label>
                    <select name="status" class="input" style="padding: 8px 12px;">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="shipped" <?= $statusFilter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                        <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                
                <div>
                    <label style="font-size: 12px; color: #64748b; display: block; margin-bottom: 4px;">Type</label>
                    <select name="beta" class="input" style="padding: 8px 12px;">
                        <option value="all" <?= $betaFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="beta_only" <?= $betaFilter === 'beta_only' ? 'selected' : '' ?>>🧪 Beta Samples Only</option>
                        <option value="non_beta" <?= $betaFilter === 'non_beta' ? 'selected' : '' ?>>Regular Samples</option>
                    </select>
                </div>
                
                <div>
                    <label style="font-size: 12px; color: #64748b; display: block; margin-bottom: 4px;">Source</label>
                    <select name="source" class="input" style="padding: 8px 12px;">
                        <option value="all" <?= $sourceFilter === 'all' ? 'selected' : '' ?>>All Sources</option>
                        <option value="product-sample" <?= $sourceFilter === 'product-sample' ? 'selected' : '' ?>>product-pack Trial (<?= $sourceCounts['product-sample'] ?? 0 ?>)</option>
                        <option value="manual" <?= $sourceFilter === 'manual' ? 'selected' : '' ?>>Manual (<?= $sourceCounts['manual'] ?? 0 ?>)</option>
                    </select>
                </div>
                
                <button type="submit" class="btn primary" style="margin-top: 20px;">Filter</button>
            </form>
        </div>

        <!-- Sample Requests Table -->
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Agent</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                            No sample requests found for the selected filters.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><code>#<?= $req['id'] ?></code></td>
                        <td>
                            <div style="font-weight: 600;"><?= htmlspecialchars($req['company_name']) ?></div>
                            <div style="font-size: 12px; color: #64748b;"><?= htmlspecialchars($req['recipient_email']) ?></div>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($req['product_name']) ?></div>
                            <div style="font-size: 12px; color: #64748b;">Qty: <?= $req['quantity'] ?></div>
                        </td>
                        <td><?= htmlspecialchars($req['agent_name']) ?></td>
                        <td>
                            <?php if ($req['promo_source'] === 'product-sample'): ?>
                            <span class="badge" style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 11px;">product-pack Trial</span>
                            <?php else: ?>
                            <span class="badge" style="background: #e5e7eb; color: #1f2937; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusColors = [
                                'pending' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                                'approved' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
                                'shipped' => ['bg' => '#e9d5ff', 'text' => '#6b21a8'],
                                'delivered' => ['bg' => '#d1fae5', 'text' => '#065f46'],
                                'rejected' => ['bg' => '#fee2e2', 'text' => '#991b1b']
                            ];
                            $color = $statusColors[$req['status']] ?? ['bg' => '#f3f4f6', 'text' => '#111827'];
                            ?>
                            <span style="background: <?= $color['bg'] ?>; color: <?= $color['text'] ?>; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                <?= htmlspecialchars($req['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div><?= date('M j, Y', strtotime($req['requested_at'])) ?></div>
                            <div style="font-size: 12px; color: #64748b;"><?= date('g:i A', strtotime($req['requested_at'])) ?></div>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <?php if ($req['status'] === 'pending'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="approve_sample">
                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                    <button type="submit" class="btn primary" style="padding: 4px 12px; font-size: 12px;">✓ Approve</button>
                                </form>
                                <button onclick="showRejectModal(<?= $req['id'] ?>, '<?= htmlspecialchars($req['company_name'], ENT_QUOTES) ?>')" class="btn secondary" style="padding: 4px 12px; font-size: 12px;">✗ Reject</button>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                <button onclick="showShipModal(<?= $req['id'] ?>, '<?= htmlspecialchars($req['company_name'], ENT_QUOTES) ?>')" class="btn primary" style="padding: 4px 12px; font-size: 12px;">🚚 Ship</button>
                                <?php elseif ($req['status'] === 'shipped'): ?>
                                <div style="font-size: 11px; color: #64748b;">
                                    <strong>Tracking:</strong> <?= htmlspecialchars($req['tracking_number']) ?>
                                </div>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="mark_delivered">
                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                    <button type="submit" class="btn secondary" style="padding: 4px 12px; font-size: 12px;">✓ Mark Delivered</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Reject Modal -->
<div id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; padding: 20px;">
    <div style="max-width: 500px; margin: 100px auto; background: white; border-radius: 12px; padding: 24px;">
        <h2 style="margin-top: 0;">Reject Sample Request</h2>
        <p>Rejecting sample for: <strong id="rejectCustomerName"></strong></p>
        
        <form method="post">
            <input type="hidden" name="action" value="reject_sample">
            <input type="hidden" name="request_id" id="rejectRequestId">
            
            <label>Rejection Reason</label>
            <textarea name="rejection_reason" class="input" rows="3" required placeholder="Why is this request being rejected?"></textarea>
            
            <div style="display: flex; gap: 12px; margin-top: 16px;">
                <button type="submit" class="btn primary">Confirm Rejection</button>
                <button type="button" onclick="closeRejectModal()" class="btn secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Ship Modal -->
<div id="shipModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; padding: 20px;">
    <div style="max-width: 500px; margin: 100px auto; background: white; border-radius: 12px; padding: 24px;">
        <h2 style="margin-top: 0;">Create Shipment</h2>
        <p>Shipping sample to: <strong id="shipCustomerName"></strong></p>
        
        <form method="post">
            <input type="hidden" name="action" value="create_shipment">
            <input type="hidden" name="request_id" id="shipRequestId">
            
            <label>Carrier</label>
            <select name="carrier" class="input" required>
                <option value="USPS">USPS</option>
                <option value="FedEx">FedEx</option>
                <option value="UPS">UPS</option>
            </select>
            
            <label>Service Type</label>
            <select name="service_type" class="input" required>
                <option value="Priority Mail">Priority Mail</option>
                <option value="First Class">First Class</option>
                <option value="Ground">Ground</option>
                <option value="Express">Express</option>
            </select>
            
            <label>Tracking Number</label>
            <input type="text" name="tracking_number" class="input" required placeholder="Enter tracking number">
            
            <div style="display: flex; gap: 12px; margin-top: 16px;">
                <button type="submit" class="btn primary">Create Shipment</button>
                <button type="button" onclick="closeShipModal()" class="btn secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(requestId, customerName) {
    document.getElementById('rejectRequestId').value = requestId;
    document.getElementById('rejectCustomerName').textContent = customerName;
    document.getElementById('rejectModal').style.display = 'block';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

function showShipModal(requestId, customerName) {
    document.getElementById('shipRequestId').value = requestId;
    document.getElementById('shipCustomerName').textContent = customerName;
    document.getElementById('shipModal').style.display = 'block';
}

function closeShipModal() {
    document.getElementById('shipModal').style.display = 'none';
}

// Close modal on background click
document.getElementById('rejectModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

document.getElementById('shipModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeShipModal();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


