<?php
/**
 * Sales Agent Sample Request Interface
 * 
 * Two-step wizard for requesting product samples:
 * 1. Select delivery method (shipping or drop-off)
 * 2. Select sample and complete request
 */

$SEO = [
    'title' => 'Request Sample — Sales Agent CRM — Product',
    'description' => 'Request product samples for leads and accounts',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/sample-inventory-helpers.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Check sales agent role
$pdo = db();
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isRealAdmin = current_admin_id() !== null;
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);
$hasFullAccess = $isAdminSimulation || ($isRealAdmin && $adminMode);

if (!$hasFullAccess) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try {
        $stmt->execute([$uid]);
        $hasRole = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $hasRole = false;
    }
    if (!$hasRole) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Get query parameters
$leadId = intval($_GET['lead_id'] ?? 0);
$accountId = intval($_GET['account_id'] ?? 0);
$step = intval($_GET['step'] ?? 1);

// Load lead or account details if provided
$entityName = '';
$entityAddress = [];
$entityPhone = '';
$entityEmail = '';

if ($leadId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ? AND assigned_agent_id = ? LIMIT 1');
    $stmt->execute([$leadId, $uid]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lead) {
        $entityName = $lead['company_name'] ?: trim($lead['first_name'] . ' ' . $lead['last_name']);
        $entityAddress = [
            'line1' => $lead['address_line1'] ?? '',
            'line2' => $lead['address_line2'] ?? '',
            'city' => $lead['city'] ?? '',
            'state' => $lead['state'] ?? '',
            'zip' => $lead['zip_code'] ?? '',
            'country' => $lead['country'] ?? 'USA'
        ];
        $entityPhone = $lead['phone'] ?? '';
        $entityEmail = $lead['email'] ?? '';
    }
} elseif ($accountId > 0) {
    $stmt = $pdo->prepare('SELECT wa.*, u.first_name, u.last_name, u.email, u.phone FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.id = ? AND wa.sales_agent_id = ? LIMIT 1');
    $stmt->execute([$accountId, $uid]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account) {
        $entityName = $account['business_name'] ?? trim($account['first_name'] . ' ' . $account['last_name']);
        $entityAddress = [
            'line1' => $account['address_line1'] ?? '',
            'line2' => $account['address_line2'] ?? '',
            'city' => $account['city'] ?? '',
            'state' => $account['state_region'] ?? '',
            'zip' => $account['postal_code'] ?? '',
            'country' => 'USA'
        ];
        $entityPhone = $account['phone'] ?? '';
        $entityEmail = $account['email'] ?? '';
    }
}

// Get available sample products and inventory
$sampleProducts = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.*,
            si.quantity_available,
            si.quantity_reserved,
            si.low_stock_threshold,
            (si.quantity_available - si.quantity_reserved) AS truly_available
        FROM products p
        JOIN sample_inventory si ON si.product_id = p.id
        WHERE p.is_sample = 1 AND p.active = 1
        ORDER BY p.name
    ");
    $sampleProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sampleProducts = [];
}

$sampleSuiteStepLabels = [
    'kickoff' => 'Initial Outreach',
    'offer_reminder_1' => 'Offer Reminder #1',
    'offer_reminder_2' => 'Offer Reminder #2',
    'offer_day5' => 'Day 5 Reminder',
    'rotation_a' => 'Rotation A',
    'rotation_b' => 'Rotation B',
    'rotation_c' => 'Rotation C',
    'click_probe' => 'Click Follow-up',
    'kickoff_resend_fast' => 'Kickoff Recheck (30 min)',
    'kickoff_resend_followup' => 'Kickoff Recheck (4 hr)',
    'feedback' => 'Sample Feedback',
    'human_escalation' => 'Human Escalation',
    'shipped' => 'Shipment Confirmation',
    'delivered' => 'Delivery Check-in',
    'nurture' => 'Post-Delivery Nurture',
    'last_call' => 'Final Nudge'
];
if (!function_exists('sample_suite_label_for_ui')) {
    function sample_suite_label_for_ui(string $key, array $labels): string {
        return $labels[$key] ?? ucwords(str_replace('_',' ', $key));
    }
}
$sampleSuiteStartEligibleStatuses = ['pending','approved','shipped','delivered'];
$sampleSuitePortalBase = 'https://product.example/promo/product-sample/';
$portalParams = array_filter([
    'utm_source' => 'sample-suite',
    'lead_id' => $leadId ?: null,
    'account_id' => $accountId ?: null,
    'agent_id' => current_user_id() ?: null
]);
$sampleSuitePortalUrl = $sampleSuitePortalBase . ($portalParams ? '?' . http_build_query($portalParams) : '');

$sampleSuiteSummary = [];
if ($leadId > 0 || $accountId > 0) {
    $where = $leadId > 0 ? 'sr.lead_id = ?' : 'sr.account_id = ?';
    $idParam = $leadId > 0 ? $leadId : $accountId;
    $stmt = $pdo->prepare("
        SELECT 
            sr.id,
            sr.status AS sample_status,
            sr.requested_at,
            sr.recipient_name,
            sr.suite_status,
            sr.suite_started_at,
            e.id AS enrollment_id,
            e.status AS enrollment_status,
            e.current_step_key,
            e.current_step_number,
            e.next_step_scheduled_for,
            e.attempt_count,
            e.max_attempts,
            e.rotation_cursor,
            e.sale_detected_at,
            e.unsubscribe_at,
            e.behavior_state,
            e.last_engaged_at,
            e.finalized_reason
        FROM sample_requests sr
        LEFT JOIN sample_suite_enrollments e ON e.sample_request_id = sr.id
        WHERE $where
        ORDER BY sr.requested_at DESC
        LIMIT 5
    ");
    $stmt->execute([$idParam]);
    $sampleSuiteSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<div class="container">
    <?php 
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <div class="page-header">
        <div>
            <h1 class="page-title">📦 Request Product Sample</h1>
            <div class="page-subtitle muted">Send samples to leads and accounts</div>
        </div>
        <div class="header-actions">
            <a href="<?php echo $leadId ? "leads.php?id=$leadId" : ($accountId ? "accounts.php?id=$accountId" : "index.php"); ?>" class="btn secondary">← Back</a>
        </div>
    </div>

    <?php if ($leadId > 0 || $accountId > 0): ?>
    <div class="card" id="sampleSuiteCard" style="margin-bottom: 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
            <div>
                <h3 style="margin:0;">Sample Suite Automation</h3>
                <div class="muted small">Kickoff and track the curated follow-up chain for this store.</div>
            </div>
            <button type="button" class="btn small secondary" onclick="window.location.reload()">↻ Refresh</button>
        </div>
        <?php if (empty($sampleSuiteSummary)): ?>
            <div>
                <div class="muted small">
                    No sample requests found for this lead/account yet.
                    Share the <a href="<?php echo htmlspecialchars($sampleSuitePortalUrl); ?>" target="_blank" rel="noopener">customer portal</a> so they can choose Blue, Gold, or the product format dash.
                </div>
                <?php if ($leadId > 0): ?>
                <div style="margin-top:8px;">
                    <button type="button" class="btn small" onclick="quickStartSampleSuite(<?php echo $leadId; ?>, 'lead', this)">Send Kickoff Email</button>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php foreach ($sampleSuiteSummary as $row): 
                    $suiteState = $row['suite_status'] ?: 'not_started';
                    $enrollmentId = intval($row['enrollment_id']);
                    $currentStepName = $row['current_step_key'] ? sample_suite_label_for_ui($row['current_step_key'], $sampleSuiteStepLabels) : null;
                    $stepLabel = $row['current_step_number'] ? 'Step ' . intval($row['current_step_number']) . ' · ' . $currentStepName : 'Not started';
                    $canStart = !$enrollmentId && in_array($row['sample_status'], $sampleSuiteStartEligibleStatuses, true);
                    $attemptCount = intval($row['attempt_count'] ?? 0);
                    $maxAttempts = intval($row['max_attempts'] ?? 10);
                    $behaviorState = json_decode($row['behavior_state'] ?? '[]', true);
                    $opens = intval($behaviorState['opens'] ?? 0);
                    $clicks = intval($behaviorState['clicks'] ?? 0);
                    $saleAt = $row['sale_detected_at'] ?? null;
                    $unsubscribedAt = $row['unsubscribe_at'] ?? null;
                    $canMarkSale = $enrollmentId && !$saleAt;
                ?>
                <div class="list-row" style="align-items:center;">
                    <div class="list-main">
                        <div class="list-title">Request #<?php echo $row['id']; ?></div>
                        <div class="list-sub muted">
                            Sample: <?php echo ucfirst($row['sample_status']); ?> 
                            <span class="dot">•</span>
                            Suite: <?php echo ucfirst(str_replace('_',' ', $suiteState)); ?>
                            <?php if ($enrollmentId): ?>
                                <span class="dot">•</span><?php echo htmlspecialchars($stepLabel); ?>
                                <?php if (!empty($row['next_step_scheduled_for'])): ?>
                                    <span class="dot">•</span>Next send <?php echo date('M j, g:i A', strtotime($row['next_step_scheduled_for'])); ?>
                                <?php endif; ?>
                                <span class="dot">•</span>Attempts <?php echo $attemptCount; ?>/<?php echo $maxAttempts; ?>
                                <?php if ($opens || $clicks): ?>
                                    <span class="dot">•</span><?php echo $opens; ?> opens / <?php echo $clicks; ?> clicks
                                <?php endif; ?>
                                <?php if ($saleAt): ?>
                                    <span class="dot">•</span><span class="chip chip-success">Sale logged</span>
                                <?php elseif ($unsubscribedAt): ?>
                                    <span class="dot">•</span><span class="chip chip-warning">Unsubscribed</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="list-aside" style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn small tertiary" href="<?php echo htmlspecialchars($sampleSuitePortalUrl); ?>" target="_blank" rel="noopener">Portal Link</a>
                        <?php if ($enrollmentId): ?>
                            <button type="button" class="btn small tertiary" onclick="openSuiteTimeline(<?php echo $row['id']; ?>)">View Activity</button>
                        <?php endif; ?>
                        <?php if ($canStart): ?>
                            <button type="button" class="btn small" onclick="startSampleSuite(<?php echo $row['id']; ?>, this)">Start Suite</button>
                        <?php elseif (!$enrollmentId): ?>
                            <button type="button" class="btn small secondary" disabled>Waiting for approval</button>
                        <?php else: ?>
                            <button type="button" class="btn small secondary" onclick="cancelSampleSuite(<?php echo $enrollmentId; ?>, this)">Pause Suite</button>
                            <button type="button" class="btn small tertiary" onclick="resetSampleSuite(<?php echo $row['id']; ?>, this)">Reset Suite</button>
                            <?php if ($canMarkSale): ?>
                                <button type="button" class="btn small success" onclick="markSampleSuiteSale(<?php echo $row['id']; ?>, this)">Mark Sale</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Sample Request Wizard -->
    <div class="wizard-container">
        <!-- Progress Steps -->
        <div class="wizard-steps">
            <div class="wizard-step <?php echo $step === 1 ? 'active' : ($step > 1 ? 'complete' : ''); ?>">
                <div class="step-number">1</div>
                <div class="step-label">Delivery Method</div>
            </div>
            <div class="wizard-step <?php echo $step === 2 ? 'active' : ($step > 2 ? 'complete' : ''); ?>">
                <div class="step-number">2</div>
                <div class="step-label">Sample Selection</div>
            </div>
            <div class="wizard-step <?php echo $step === 3 ? 'active' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-label">Review & Submit</div>
            </div>
        </div>

        <!-- Step 1: Delivery Method -->
        <?php if ($step === 1): ?>
        <div class="wizard-content">
            <div class="card">
                <h3 style="margin-top:0;">Choose Delivery Method</h3>
                <p class="muted">How would you like to deliver the sample?</p>
                
                <div class="delivery-options">
                    <div class="delivery-option" onclick="selectDeliveryMethod('shipping')">
                        <div class="option-icon">📮</div>
                        <div class="option-content">
                            <h4>Ship to Customer</h4>
                            <p>We'll ship the sample directly to the customer's address. Live shipping rates provided.</p>
                            <span class="option-badge">3-5 business days</span>
                        </div>
                        <div class="option-arrow">→</div>
                    </div>
                    
                    <div class="delivery-option" onclick="selectDeliveryMethod('drop_off')">
                        <div class="option-icon">🚗</div>
                        <div class="option-content">
                            <h4>Drop Off in Person</h4>
                            <p>You'll deliver the sample during your next visit. No shipping costs.</p>
                            <span class="option-badge">Same day</span>
                        </div>
                        <div class="option-arrow">→</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Step 2: Sample Selection & Details -->
        <?php if ($step === 2): ?>
        <div class="wizard-content">
            <?php if (!empty($_GET['auto']) && $_GET['auto'] === '1'): ?>
            <div class="card" style="background:#fef3c7;border:2px solid #d97706;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="font-size:32px;">🎯</div>
                    <div>
                        <div style="font-weight:700;color:#92400e;margin-bottom:4px;">
                            Creating Sample Request for Newly Added Lead
                        </div>
                        <div style="color:#78350f;font-size:14px;">
                            Complete this form to send a sample to your new lead. The initial follow-up process is automated for sample requests.
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($leadId || $accountId): ?>
            <div class="card" style="background:#e0f2fe;border:2px solid #0369a1;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="font-size:32px;">ℹ️</div>
                    <div>
                        <div style="font-weight:700;color:#0c4a6e;margin-bottom:4px;">
                            Request for: <?php echo htmlspecialchars($entityName); ?>
                        </div>
                        <div style="color:#075985;font-size:14px;">
                            <?php if (!empty($entityAddress['line1'])): ?>
                                Address and contact details have been automatically populated from the 
                                <?php echo $leadId ? 'lead' : 'account'; ?> record.
                            <?php else: ?>
                                Please enter shipping address below.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <form id="sampleRequestForm" method="post" action="">
                <input type="hidden" name="request_type" id="request_type" value="<?php echo htmlspecialchars($_GET['type'] ?? 'shipping'); ?>">
                <input type="hidden" name="lead_id" value="<?php echo $leadId; ?>">
                <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                
                <div class="card">
                    <h3 style="margin-top:0;">Select Sample Product</h3>
                    
                    <?php if (empty($sampleProducts)): ?>
                        <div class="alert alert-warning">
                            No sample products available at this time. Please contact admin.
                        </div>
                    <?php else: ?>
                        <div class="sample-grid">
                            <?php foreach ($sampleProducts as $product): ?>
                            <div class="sample-card <?php echo $product['truly_available'] < 1 ? 'out-of-stock' : ''; ?>" 
                                 data-product-id="<?php echo $product['id']; ?>"
                                 onclick="selectSample(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>', <?php echo $product['truly_available']; ?>)">
                                <div class="sample-header">
                                    <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                                    <span class="sample-sku"><?php echo htmlspecialchars($product['sku']); ?></span>
                                </div>
                                <div class="sample-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Size:</span>
                                        <span class="detail-value"><?php echo $product['size_oz']; ?>oz</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">attribute Content:</span>
                                        <span class="detail-value"><?php echo $product['attribute_per_oz']; ?>mg/oz</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Total attribute:</span>
                                        <span class="detail-value"><?php echo $product['total_mg']; ?>mg</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Available:</span>
                                        <span class="detail-value <?php echo $product['truly_available'] <= $product['low_stock_threshold'] ? 'text-warning' : 'text-success'; ?>">
                                            <?php echo $product['truly_available']; ?> units
                                            <?php if ($product['truly_available'] <= $product['low_stock_threshold']): ?>
                                                <span class="badge-mini" style="background:#fef3c7;color:#92400e;">Low Stock</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="sample-description muted small">
                                    <?php echo htmlspecialchars($product['description'] ?? ''); ?>
                                </div>
                                <?php if ($product['truly_available'] < 1): ?>
                                    <div class="sample-overlay">Out of Stock</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="sample_product_id" id="selected_sample_id" required>
                    <?php endif; ?>
                </div>
                
                <!-- Recipient Details -->
                <div class="card" style="margin-top:20px;">
                    <h3 style="margin-top:0;">Recipient Details</h3>
                    
                    <div style="display:grid;gap:16px;">
                        <div>
                            <label>Recipient Name *</label>
                            <input type="text" name="recipient_name" id="recipient_name" 
                                   value="<?php echo htmlspecialchars($entityName); ?>" 
                                   required class="input" style="width:100%;">
                        </div>
                        
                        <div id="shipping_address_section" style="<?php echo ($_GET['type'] ?? 'shipping') === 'drop_off' ? 'display:none;' : ''; ?>">
                            <h4 style="margin:0 0 12px;">Shipping Address</h4>
                            
                            <div style="display:grid;gap:12px;">
                                <div>
                                    <label>Address Line 1 *</label>
                                    <input type="text" name="address_line1" id="address_line1" 
                                           value="<?php echo htmlspecialchars($entityAddress['line1'] ?? ''); ?>" 
                                           class="input" style="width:100%;" 
                                           onchange="checkAddressComplete()">
                                </div>
                                
                                <div>
                                    <label>Address Line 2</label>
                                    <input type="text" name="address_line2" id="address_line2" 
                                           value="<?php echo htmlspecialchars($entityAddress['line2'] ?? ''); ?>" 
                                           class="input" style="width:100%;">
                                </div>
                                
                                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
                                    <div>
                                        <label>City *</label>
                                        <input type="text" name="city" id="city" 
                                               value="<?php echo htmlspecialchars($entityAddress['city'] ?? ''); ?>" 
                                               class="input" style="width:100%;" 
                                               onchange="checkAddressComplete()">
                                    </div>
                                    <div>
                                        <label>State *</label>
                                        <input type="text" name="state" id="state" 
                                               value="<?php echo htmlspecialchars($entityAddress['state'] ?? ''); ?>" 
                                               class="input" style="width:100%;" 
                                               onchange="checkAddressComplete()">
                                    </div>
                                    <div>
                                        <label>ZIP *</label>
                                        <input type="text" name="zip" id="zip" 
                                               value="<?php echo htmlspecialchars($entityAddress['zip'] ?? ''); ?>" 
                                               class="input" style="width:100%;" 
                                               onchange="checkAddressComplete()">
                                    </div>
                                </div>
                                
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div>
                                        <label>Country</label>
                                        <input type="text" name="country" id="country" 
                                               value="<?php echo htmlspecialchars($entityAddress['country'] ?? 'USA'); ?>" 
                                               class="input" style="width:100%;">
                                    </div>
                                    <div>
                                        <label>Phone</label>
                                        <input type="tel" name="phone" id="phone" 
                                               value="<?php echo htmlspecialchars($entityPhone); ?>" 
                                               class="input" style="width:100%;">
                                    </div>
                                </div>
                                
                                <?php 
                                // Check if address is pre-filled from lead/account
                                $hasPrefilledAddress = !empty($entityAddress['line1']) && 
                                                       !empty($entityAddress['city']) && 
                                                       !empty($entityAddress['state']) && 
                                                       !empty($entityAddress['zip']);
                                ?>
                                
                                <?php if ($hasPrefilledAddress): ?>
                                <div style="background:#dcfce7;color:#065f46;padding:10px 12px;border-radius:8px;font-size:13px;">
                                    ✓ Address automatically populated from <?php echo $leadId ? 'lead' : 'account'; ?> record
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Shipping Quotes Section -->
                            <div id="shipping_quotes_section" style="margin-top:20px;display:none;">
                                <h4 style="margin:0 0 12px;">Shipping Options</h4>
                                <button type="button" class="btn small secondary" onclick="fetchShippingRates()" id="fetch_rates_btn">
                                    Get Shipping Quotes
                                </button>
                                <div id="shipping_quotes_container" style="margin-top:12px;"></div>
                                <input type="hidden" name="selected_carrier" id="selected_carrier">
                                <input type="hidden" name="selected_service" id="selected_service">
                                <input type="hidden" name="selected_shipping_cost" id="selected_shipping_cost">
                            </div>
                        </div>
                        
                        <div>
                            <label>Quantity</label>
                            <input type="number" name="quantity" id="quantity" value="1" min="1" max="5" 
                                   class="input" style="width:120px;">
                            <span class="muted small" style="margin-left:8px;">Maximum 5 units per request</span>
                        </div>
                        
                        <!-- Shipping Charge Options -->
                        <div id="charge_options_section" style="<?php echo ($_GET['type'] ?? 'shipping') === 'drop_off' ? 'display:none;' : ''; ?>">
                            <h4 style="margin:16px 0 12px;">Shipping Cost Options</h4>
                            <div style="display:grid;gap:12px;background:#f9fafb;padding:16px;border-radius:8px;border:1px solid #e5e7eb;">
                                <label style="display:flex;align-items:start;gap:10px;cursor:pointer;padding:8px;background:white;border-radius:6px;border:1px solid #e5e7eb;">
                                    <input type="radio" name="shipping_charge_method" value="company" checked onchange="updateChargeMethod()">
                                    <div>
                                        <div style="font-weight:600;color:#1f2937;">Company Absorbs Cost</div>
                                        <div style="font-size:13px;color:#6b7280;">Product pays for shipping (default)</div>
                                    </div>
                                </label>
                                
                                <label style="display:flex;align-items:start;gap:10px;cursor:pointer;padding:8px;background:white;border-radius:6px;border:1px solid #e5e7eb;">
                                    <input type="radio" name="shipping_charge_method" value="agent" onchange="updateChargeMethod()">
                                    <div>
                                        <div style="font-weight:600;color:#1f2937;">Charge Me (Agent)</div>
                                        <div style="font-size:13px;color:#6b7280;">Deducted from my future commissions per my repayment terms</div>
                                    </div>
                                </label>
                                
                                <label style="display:flex;align-items:start;gap:10px;cursor:pointer;padding:8px;background:white;border-radius:6px;border:1px solid #e5e7eb;">
                                    <input type="radio" name="shipping_charge_method" value="customer" onchange="updateChargeMethod()">
                                    <div>
                                        <div style="font-weight:600;color:#1f2937;">Invoice Customer</div>
                                        <div style="font-size:13px;color:#6b7280;">Customer will be billed for shipping cost</div>
                                    </div>
                                </label>
                                
                                <input type="hidden" name="charge_agent_shipping" id="charge_agent_shipping" value="0">
                                <input type="hidden" name="invoice_customer" id="invoice_customer" value="0">
                            </div>
                        </div>
                        
                        <div>
                            <label>Notes / Special Instructions</label>
                            <textarea name="agent_notes" id="agent_notes" rows="3" 
                                      class="input" style="width:100%;" 
                                      placeholder="Any special instructions or notes..."></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:24px;">
                    <a href="?step=1<?php echo $leadId ? "&lead_id=$leadId" : ($accountId ? "&account_id=$accountId" : ""); ?>" class="btn secondary">← Back</a>
                    <button type="button" onclick="submitSampleRequest()" class="btn" style="background:#059669;">
                        Submit Request
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Step 3: Confirmation -->
        <?php if ($step === 3): ?>
        <div class="wizard-content">
            <div class="card" style="text-align:center;padding:48px 24px;">
                <div style="font-size:64px;margin-bottom:16px;">✅</div>
                <h2 style="color:#059669;margin-bottom:12px;">Request Submitted Successfully!</h2>
                <p class="muted" style="margin-bottom:24px;">
                    Your sample request has been submitted and is awaiting admin approval.<br>
                    You'll be notified once it's approved and shipped.
                </p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <?php if ($leadId): ?>
                        <a href="leads.php?id=<?php echo $leadId; ?>" class="btn primary">Back to Lead</a>
                    <?php elseif ($accountId): ?>
                        <a href="accounts.php?id=<?php echo $accountId; ?>" class="btn primary">Back to Account</a>
                    <?php endif; ?>
                    <a href="request-sample.php" class="btn secondary">Submit Another Request</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
:root {
    --primary: #0066FF;
    --success: #059669;
    --warning: #d97706;
    --danger: #dc2626;
}

.wizard-container {
    max-width: 900px;
    margin: 0 auto;
}

.wizard-steps {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    position: relative;
}

.wizard-steps::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 50px;
    right: 50px;
    height: 2px;
    background: #e5e7eb;
    z-index: 0;
}

.wizard-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    position: relative;
    z-index: 1;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f3f4f6;
    color: #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    border: 2px solid #e5e7eb;
}

.wizard-step.active .step-number {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.wizard-step.complete .step-number {
    background: var(--success);
    color: white;
    border-color: var(--success);
}

.step-label {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
}

.wizard-step.active .step-label {
    color: var(--primary);
    font-weight: 600;
}

.delivery-options {
    display: grid;
    gap: 16px;
    margin-top: 24px;
}

.delivery-option {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s;
    background: white;
}

.delivery-option:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(0,102,255,0.15);
    transform: translateY(-2px);
}

.option-icon {
    font-size: 48px;
}

.option-content {
    flex: 1;
}

.option-content h4 {
    margin: 0 0 8px 0;
    color: #1f2937;
}

.option-content p {
    margin: 0;
    color: #6b7280;
    font-size: 14px;
}

.option-badge {
    display: inline-block;
    margin-top: 8px;
    padding: 4px 12px;
    background: #f0fdf4;
    color: var(--success);
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.option-arrow {
    font-size: 32px;
    color: var(--primary);
}

.sample-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sample-card {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    background: white;
}

.sample-card:hover:not(.out-of-stock) {
    border-color: var(--success);
    box-shadow: 0 4px 12px rgba(5,150,105,0.15);
    transform: translateY(-2px);
}

.sample-card.selected {
    border-color: var(--success);
    background: #f0fdf4;
}

.sample-card.out-of-stock {
    opacity: 0.5;
    cursor: not-allowed;
}

.sample-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(220, 38, 38, 0.95);
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
}

.sample-header {
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.sample-header h4 {
    margin: 0 0 4px 0;
    color: #1f2937;
}

.sample-sku {
    font-size: 12px;
    color: #6b7280;
    font-family: monospace;
}

.sample-details {
    display: grid;
    gap: 8px;
    margin-bottom: 12px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
}

.detail-label {
    color: #6b7280;
    font-weight: 500;
}

.detail-value {
    color: #1f2937;
    font-weight: 600;
}

.text-success {
    color: var(--success);
}

.text-warning {
    color: var(--warning);
}

.sample-description {
    font-size: 13px;
    line-height: 1.5;
}

.shipping-quote-option {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.shipping-quote-option:hover {
    border-color: var(--primary);
    background: #f0f9ff;
}

.shipping-quote-option.selected {
    border-color: var(--primary);
    background: #dbeafe;
}

.quote-carrier {
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 4px;
}

.quote-service {
    font-size: 14px;
    color: #6b7280;
}

.quote-delivery {
    font-size: 12px;
    color: #9ca3af;
}

.quote-cost {
    font-size: 24px;
    font-weight: 700;
    color: var(--primary);
}

@media (max-width: 640px) {
    .wizard-steps::before {
        display: none;
    }
    
    .delivery-option {
        flex-direction: column;
        text-align: center;
    }
    
    .sample-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div id="suiteTimelineModal" class="suite-timeline-modal" onclick="if(event.target === this) closeSuiteTimeline();">
    <div class="suite-timeline-panel">
        <div class="suite-timeline-panel-header">
            <strong>Sample Suite Activity</strong>
            <button type="button" class="btn tiny tertiary" onclick="closeSuiteTimeline()">×</button>
        </div>
        <div id="suiteTimelineBody" class="suite-timeline-body">
            <div class="muted small">Select a sample request to view its automation timeline.</div>
        </div>
    </div>
</div>

<style>
.suite-timeline-modal {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.45);
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px;
    z-index: 12000;
}
.suite-timeline-modal.open { display: flex; }
.suite-timeline-panel {
    background: #fff;
    width: min(720px, 100%);
    border-radius: 16px;
    box-shadow: 0 30px 80px rgba(15,23,42,0.25);
    max-height: 90vh;
    overflow-y: auto;
}
.suite-timeline-panel-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:18px 24px;
    border-bottom:1px solid #e2e8f0;
    font-size:18px;
}
.suite-timeline-body {
    padding:24px;
    font-size:14px;
    color:#0f172a;
}
.suite-timeline-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:16px;
}
.suite-metric {
    background:#f8fafc;
    border-radius:12px;
    padding:12px 16px;
}
.suite-metric-label {
    font-size:12px;
    text-transform:uppercase;
    color:#64748b;
    letter-spacing:0.05em;
}
.suite-metric-value {
    font-size:18px;
    font-weight:600;
    color:#0f172a;
}
.suite-next-action {
    margin:20px 0;
    padding:16px 18px;
    border-radius:12px;
    background:#eef2ff;
    color:#312e81;
    font-weight:500;
}
.suite-step-list {
    margin-top:12px;
    border-top:1px solid #e2e8f0;
}
.suite-step-row {
    padding:14px 0;
    border-bottom:1px solid #e2e8f0;
    display:flex;
    flex-direction:column;
    gap:4px;
}
.suite-step-title {
    font-weight:600;
}
.suite-step-meta {
    font-size:13px;
    color:#475569;
}
.suite-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:600;
}
.suite-badge.success { background:#dcfce7; color:#166534; }
.suite-badge.warn { background:#fee2e2; color:#b91c1c; }
.suite-badge.info { background:#e0e7ff; color:#3730a3; }
</style>

<script>
let selectedSampleId = null;
let selectedSampleName = '';
let addressPreFilled = <?php echo json_encode($hasPrefilledAddress ?? false); ?>;

function selectDeliveryMethod(type) {
    const params = new URLSearchParams(window.location.search);
    params.set('step', '2');
    params.set('type', type);
    window.location.href = '?' + params.toString();
}

function updateChargeMethod() {
    const method = document.querySelector('input[name="shipping_charge_method"]:checked')?.value || 'company';
    
    document.getElementById('charge_agent_shipping').value = (method === 'agent') ? '1' : '0';
    document.getElementById('invoice_customer').value = (method === 'customer') ? '1' : '0';
}

// Check if address is complete and show shipping quotes section
function checkAddressComplete() {
    const requestType = document.getElementById('request_type')?.value;
    if (requestType !== 'shipping') return;
    
    const address1 = document.getElementById('address_line1')?.value?.trim();
    const city = document.getElementById('city')?.value?.trim();
    const state = document.getElementById('state')?.value?.trim();
    const zip = document.getElementById('zip')?.value?.trim();
    
    const isComplete = address1 && city && state && zip;
    
    if (isComplete) {
        const quotesSection = document.getElementById('shipping_quotes_section');
        if (quotesSection) {
            quotesSection.style.display = 'block';
        }
    }
}

// On page load, check if address is pre-filled and auto-show shipping quotes
document.addEventListener('DOMContentLoaded', function() {
    const requestType = document.getElementById('request_type')?.value;
    
    // If on step 2 with shipping method and address is pre-filled
    if (requestType === 'shipping' && addressPreFilled) {
        // Auto-show shipping quotes section
        setTimeout(function() {
            const quotesSection = document.getElementById('shipping_quotes_section');
            if (quotesSection) {
                quotesSection.style.display = 'block';
                
                // Optionally auto-fetch rates if we have a selected sample
                // Uncomment below to auto-fetch on load:
                // if (selectedSampleId) {
                //     fetchShippingRates();
                // }
            }
        }, 100);
    }
});

function selectSample(productId, productName, available) {
    if (available < 1) {
        alert('This sample is currently out of stock. Please select another.');
        return;
    }
    
    // Remove previous selection
    document.querySelectorAll('.sample-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Mark as selected
    event.currentTarget.classList.add('selected');
    
    selectedSampleId = productId;
    selectedSampleName = productName;
    document.getElementById('selected_sample_id').value = productId;
    
    // Show shipping quotes section if shipping method
    const requestType = document.getElementById('request_type').value;
    if (requestType === 'shipping') {
        document.getElementById('shipping_quotes_section').style.display = 'block';
    }
}

async function fetchShippingRates() {
    const btn = document.getElementById('fetch_rates_btn');
    const container = document.getElementById('shipping_quotes_container');
    
    if (!selectedSampleId) {
        alert('Please select a sample product first');
        return;
    }
    
    // Validate address fields
    const address1 = document.getElementById('address_line1').value.trim();
    const city = document.getElementById('city').value.trim();
    const state = document.getElementById('state').value.trim();
    const zip = document.getElementById('zip').value.trim();
    
    if (!address1 || !city || !state || !zip) {
        alert('Please complete the shipping address to get quotes');
        return;
    }
    
    btn.disabled = true;
    btn.textContent = 'Fetching Quotes...';
    container.innerHTML = '<div class="muted">Loading shipping rates...</div>';
    
    try {
        const response = await fetch('<?php echo api_url('/api/shipping-rate-api.php'); ?>?action=get_rates', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                recipient_name: document.getElementById('recipient_name').value,
                address_line1: address1,
                address_line2: document.getElementById('address_line2').value,
                city: city,
                state: state,
                zip: zip,
                country: document.getElementById('country').value || 'USA',
                phone: document.getElementById('phone').value,
                weight_oz: 2.5
            })
        });
        
        const data = await response.json();
        
        if (!data.ok) {
            throw new Error(data.error || 'Failed to fetch shipping rates');
        }
        
        if (data.rates.length === 0) {
            container.innerHTML = '<div class="alert alert-warning">No shipping rates available for this address.</div>';
            return;
        }
        
        // Display mode notice
        let html = '';
        if (data.mode === 'flat_rate' || data.mode === 'flat_rate_fallback') {
            html += `
                <div style="background:#fef3c7;color:#92400e;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;">
                    ℹ️ ${data.notice || 'Using flat rate pricing'}
                </div>
            `;
        }
        
        // Display shipping options
        html += '<div style="display:grid;gap:12px;">';
        data.rates.forEach((rate, index) => {
            const isRecommended = index === 0; // Cheapest is recommended
            const flatRateBadge = rate.is_flat_rate ? '<span class="badge-mini" style="background:#e0e7ff;color:#4338ca;margin-left:6px;">Flat Rate</span>' : '';
            html += `
                <div class="shipping-quote-option" onclick="selectShippingQuote('${rate.carrier}', '${rate.service_name}', ${rate.cost}, this)">
                    <div>
                        <div class="quote-carrier">${rate.carrier} ${isRecommended ? '<span class="badge-mini" style="background:#dcfce7;color:#059669;">Recommended</span>' : ''} ${flatRateBadge}</div>
                        <div class="quote-service">${rate.service_name}</div>
                        ${rate.delivery_days ? `<div class="quote-delivery">Estimated delivery: ${rate.delivery_days} business days</div>` : ''}
                    </div>
                    <div class="quote-cost">$${rate.cost.toFixed(2)}</div>
                </div>
            `;
        });
        html += '</div>';
        
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Shipping quote error:', error);
        container.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Refresh Quotes';
    }
}

function selectShippingQuote(carrier, service, cost, element) {
    // Remove previous selection
    document.querySelectorAll('.shipping-quote-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    
    // Mark as selected
    element.classList.add('selected');
    
    // Store selection
    document.getElementById('selected_carrier').value = carrier;
    document.getElementById('selected_service').value = service;
    document.getElementById('selected_shipping_cost').value = cost;
}

async function submitSampleRequest() {
    if (!selectedSampleId) {
        alert('Please select a sample product');
        return;
    }
    
    const requestType = document.getElementById('request_type').value;
    
    // For shipping, validate address and shipping selection
    if (requestType === 'shipping') {
        const address1 = document.getElementById('address_line1').value.trim();
        const city = document.getElementById('city').value.trim();
        const state = document.getElementById('state').value.trim();
        const zip = document.getElementById('zip').value.trim();
        
        if (!address1 || !city || !state || !zip) {
            alert('Please complete the shipping address');
            return;
        }
        
        const carrier = document.getElementById('selected_carrier').value;
        if (!carrier) {
            alert('Please select a shipping option by clicking "Get Shipping Quotes" and choosing a carrier');
            return;
        }
    }
    
    // Prepare request data
    const formData = {
        sample_product_id: selectedSampleId,
        request_type: requestType,
        quantity: parseInt(document.getElementById('quantity').value) || 1,
        recipient_name: document.getElementById('recipient_name').value,
        lead_id: <?php echo $leadId ?: 'null'; ?>,
        account_id: <?php echo $accountId ?: 'null'; ?>,
        agent_notes: document.getElementById('agent_notes').value,
        charge_agent_shipping: parseInt(document.getElementById('charge_agent_shipping').value) || 0,
        invoice_customer: parseInt(document.getElementById('invoice_customer').value) || 0
    };
    
    if (requestType === 'shipping') {
        formData.address_line1 = document.getElementById('address_line1').value;
        formData.address_line2 = document.getElementById('address_line2').value;
        formData.city = document.getElementById('city').value;
        formData.state = document.getElementById('state').value;
        formData.zip = document.getElementById('zip').value;
        formData.country = document.getElementById('country').value || 'USA';
        formData.phone = document.getElementById('phone').value;
    }
    
    try {
        const response = await fetch('<?php echo api_url('/api/sample-request-api.php'); ?>?action=create', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to submit request');
        }
        
        // Redirect to success step
        const params = new URLSearchParams(window.location.search);
        params.set('step', '3');
        window.location.href = '?' + params.toString();
        
    } catch (error) {
        console.error('Submit error:', error);
        alert('Error submitting request: ' + error.message);
    }
}

async function startSampleSuite(requestId, btn) {
    if (!requestId) return;
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Starting...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=start'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sample_request_id: requestId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to start automation');
        }
        alert('Sample Suite launched successfully.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to start Sample Suite: ' + err.message);
    }
}

async function cancelSampleSuite(enrollmentId, btn) {
    if (!enrollmentId) return;
    if (!confirm('Pause Sample Suite for this request?')) {
        return;
    }
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Pausing...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=cancel'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({enrollment_id: enrollmentId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to pause automation');
        }
        alert('Sample Suite paused.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to pause Sample Suite: ' + err.message);
    }
}

async function resetSampleSuite(requestId, btn) {
    if (!requestId) return;
    if (!confirm('Stop and reset the Sample Suite for this request?')) {
        return;
    }
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Resetting...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=reset'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sample_request_id: requestId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to reset automation');
        }
        alert('Sample Suite reset. You can launch it again anytime.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to reset Sample Suite: ' + err.message);
    }
}

async function markSampleSuiteSale(requestId, btn) {
    if (!requestId) return;
    if (!confirm('Mark this sample request as converted to a sale?')) {
        return;
    }
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=mark_sale'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sample_request_id: requestId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || (data.result && data.result.error) || 'Unable to mark sale');
        }
        alert('Sale recorded and automation completed.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to mark sale: ' + err.message);
    }
}

async function quickStartSampleSuite(entityId, entityType, btn) {
    if (!entityId) return;
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Sending...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=quick_start'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({entity_type: entityType, entity_id: entityId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to send kickoff');
        }
        alert('Kickoff email queued successfully.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to send kickoff: ' + err.message);
    }
}

const suiteStatusEndpoint = '<?php echo api_url('/api/sample-suite.php'); ?>';
const suiteStepLabelMap = <?php echo json_encode($sampleSuiteStepLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

async function openSuiteTimeline(sampleRequestId) {
    if (!sampleRequestId) return;
    const modal = document.getElementById('suiteTimelineModal');
    const body = document.getElementById('suiteTimelineBody');
    modal.classList.add('open');
    body.innerHTML = '<div class="muted small">Loading automation history…</div>';
    try {
        const response = await fetch(`${suiteStatusEndpoint}?action=status&sample_request_id=${sampleRequestId}`);
        const data = await response.json();
        if (!data.ok || !Array.isArray(data.items) || !data.items.length) {
            throw new Error(data.error || 'No automation data available yet.');
        }
        body.innerHTML = buildSuiteTimelineHtml(data.items[0]);
    } catch (err) {
        body.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
    }
}

function closeSuiteTimeline() {
    const modal = document.getElementById('suiteTimelineModal');
    if (modal) {
        modal.classList.remove('open');
    }
}

function buildSuiteTimelineHtml(record) {
    const engagement = record.behavior_state || {};
    const attempts = `${record.attempt_count || 0} / ${record.max_attempts || 10}`;
    const opens = engagement.opens || 0;
    const clicks = engagement.clicks || 0;
    const badge = buildSuiteStatusBadge(record);

    return `
        <div class="suite-timeline-grid">
            <div class="suite-metric">
                <div class="suite-metric-label">Status</div>
                <div class="suite-metric-value">${badge}</div>
            </div>
            <div class="suite-metric">
                <div class="suite-metric-label">Attempts</div>
                <div class="suite-metric-value">${attempts}</div>
            </div>
            <div class="suite-metric">
                <div class="suite-metric-label">Opens</div>
                <div class="suite-metric-value">${opens}</div>
            </div>
            <div class="suite-metric">
                <div class="suite-metric-label">Clicks</div>
                <div class="suite-metric-value">${clicks}</div>
            </div>
        </div>
        <div class="suite-next-action">${computeSuiteNextAction(record)}</div>
        <div class="suite-step-list">
            ${renderSuiteSteps(record.steps)}
        </div>
    `;
}

function buildSuiteStatusBadge(record) {
    if (record.sale_detected_at) {
        return `<span class="suite-badge success">Sale Logged (${formatSuiteDate(record.sale_detected_at)})</span>`;
    }
    if (record.unsubscribe_at) {
        return `<span class="suite-badge warn">Unsubscribed (${formatSuiteDate(record.unsubscribe_at)})</span>`;
    }
    const status = record.enrollment_status || record.suite_status || 'pending';
    return `<span class="suite-badge info">${status.replace(/_/g, ' ')}</span>`;
}

function computeSuiteNextAction(record) {
    if (record.sale_detected_at) {
        return `Cycle completed. A sale was recorded on ${formatSuiteDate(record.sale_detected_at)}.`;
    }
    if (record.unsubscribe_at) {
        return `Automation paused because the contact unsubscribed on ${formatSuiteDate(record.unsubscribe_at)}. Follow up manually if appropriate.`;
    }
    const futureSteps = (record.steps || []).filter(step => step.status === 'scheduled');
    if (futureSteps.length) {
        futureSteps.sort((a, b) => new Date(a.scheduled_for) - new Date(b.scheduled_for));
        const next = futureSteps[0];
        const label = suiteStepLabelMap[next.step_key] || next.step_key;
        return `Next automated step <strong>${label}</strong> is scheduled for ${formatSuiteDate(next.scheduled_for)}.`;
    }
    if ((record.attempt_count || 0) >= (record.max_attempts || 10)) {
        return 'Automation has reached its attempt limit. A human follow-up is recommended.';
    }
    return 'No upcoming sends are scheduled. Share the portal link or follow up manually to keep the conversation moving.';
}

function renderSuiteSteps(steps) {
    if (!Array.isArray(steps) || !steps.length) {
        return '<div class="muted small" style="padding:16px 0;">No workflow events have been recorded yet.</div>';
    }
    return steps.map(step => {
        const label = suiteStepLabelMap[step.step_key] || step.step_key;
        const status = step.status ? step.status.replace('_', ' ') : 'scheduled';
        const timing = step.sent_at
            ? `Sent ${formatSuiteDate(step.sent_at)}`
            : step.scheduled_for
                ? `Scheduled ${formatSuiteDate(step.scheduled_for)}`
                : '';
        return `
            <div class="suite-step-row">
                <div class="suite-step-title">${label}</div>
                <div class="suite-step-meta">${status}${timing ? ` · ${timing}` : ''}</div>
            </div>
        `;
    }).join('');
}

function formatSuiteDate(value) {
    if (!value) return '—';
    const normalized = value.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleString();
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeSuiteTimeline();
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

