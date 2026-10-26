<?php
require_once __DIR__ . '/required/config.php';

$SEO = [
    'title' => 'Request Free Beta Sample - Product™',
    'description' => 'Request a free beta sample of Product. Public beta testing program - limited availability.',
    'type' => 'website'
];

// Require login
start_session();
$userId = current_user_id();

if (!$userId) {
    // Redirect to login with return URL
    $returnUrl = urlencode('/beta-sample-request.php');
    redirect("/login.php?return={$returnUrl}");
}

$pdo = db();

// Check if user is retail customer
$stmt = $pdo->prepare('
    SELECT COUNT(*) FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ? AND r.name = "retail_customer"
');
$stmt->execute([$userId]);
$isRetailCustomer = (bool)$stmt->fetchColumn();

if (!$isRetailCustomer) {
    flash_set('error', 'Beta samples are only available to retail customers.');
    redirect('/customer/');
}

// Check if user already requested
$stmt = $pdo->prepare('
    SELECT sr.*, p.name as product_name, p.sku
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    WHERE sr.agent_id = ? AND sr.is_beta_sample = 1
    ORDER BY sr.requested_at DESC
    LIMIT 1
');
$stmt->execute([$userId]);
$existingRequest = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user info for prefilling
$stmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get beta waiver agreement
$stmt = $pdo->prepare('
    SELECT content FROM agreement_templates 
    WHERE agreement_type = "beta_sample_waiver" AND is_active = 1 
    LIMIT 1
');
$stmt->execute();
$waiverContent = $stmt->fetchColumn() ?: '';

require_once __DIR__ . '/required/header.php';
?>

<section class="section" style="background:linear-gradient(135deg,#f8fafc,#ffffff);min-height:calc(100vh - 200px);">
    <div class="container" style="max-width:900px;">
        
        <?php if ($existingRequest): ?>
            <!-- User already requested -->
            <div class="card" style="border:2px solid #fbbf24;background:#fffbeb;padding:32px;text-align:center;">
                <div style="font-size:64px;margin-bottom:16px;">✅</div>
                <h1 style="margin:0 0 16px;color:#78350f;font-size:32px;font-weight:900;">You've Already Requested a Beta Sample</h1>
                <p style="margin:0 0 24px;color:#92400e;font-size:18px;">
                    Limit: One free beta sample per customer
                </p>
                
                <div style="background:#fff;border-radius:12px;padding:24px;margin-bottom:24px;text-align:left;">
                    <h3 style="margin:0 0 16px;color:#0f172a;font-size:20px;font-weight:700;">Your Request Status:</h3>
                    <div style="display:grid;gap:12px;font-size:16px;color:#334155;">
                        <div><strong>Product:</strong> <?php echo htmlspecialchars($existingRequest['product_name']); ?></div>
                        <div><strong>Status:</strong> 
                            <span style="padding:4px 12px;border-radius:6px;font-weight:700;background:<?php 
                                echo $existingRequest['status'] === 'shipped' ? '#dcfce7;color:#166534' : 
                                    ($existingRequest['status'] === 'approved' ? '#dbeafe;color:#1e40af' : '#fef3c7;color:#92400e');
                            ?>">
                                <?php echo ucfirst($existingRequest['status']); ?>
                            </span>
                        </div>
                        <div><strong>Requested:</strong> <?php echo date('M j, Y', strtotime($existingRequest['requested_at'])); ?></div>
                        <?php if ($existingRequest['tracking_number']): ?>
                            <div><strong>Tracking:</strong> 
                                <a href="#" style="color:#0ea5e9;font-weight:600;"><?php echo htmlspecialchars($existingRequest['tracking_number']); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <a href="<?php echo outbound_path('/customer/'); ?>" class="btn" style="display:inline-block;padding:14px 32px;font-size:16px;">
                    Return to Dashboard
                </a>
            </div>
            
        <?php else: ?>
            <!-- Show request form -->
            <div class="card" style="background:#fff;border:3px solid #e2e8f0;border-radius:20px;padding:40px;box-shadow:0 24px 80px rgba(15,23,42,0.15);">
                
                <!-- Header -->
                <div style="text-align:center;margin-bottom:32px;">
                    <div style="display:inline-block;padding:8px 20px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:50px;margin-bottom:16px;">
                        <span style="color:#d97706;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                            ⚡ Limited Time Offer
                        </span>
                    </div>
                    <h1 style="margin:0 0 12px;color:#0c4a6e;font-size:36px;font-weight:900;">Request Free Beta Sample</h1>
                    <p style="margin:0;color:#475569;font-size:17px;">
                        Try Product™ catalog-defined Product for free
                    </p>
                </div>
                
                <!-- Beta Warning -->
                <div style="background:#fef2f2;border:2px solid #dc2626;border-radius:12px;padding:24px;margin-bottom:32px;">
                    <div style="display:flex;align-items:start;gap:16px;">
                        <div style="font-size:32px;flex-shrink:0;">⚠️</div>
                        <div>
                            <h3 style="margin:0 0 12px;color:#991b1b;font-size:20px;font-weight:800;">PUBLIC BETA TESTING NOTICE</h3>
                            <ul style="margin:0;padding-left:20px;color:#7f1d1d;font-size:15px;line-height:1.6;">
                                <li>This product is in <strong>active beta testing</strong></li>
                                <li>Has <strong>NOT been lab tested</strong> for final quality information verification</li>
                                <li>Formulation <strong>subject to change</strong> without notice</li>
                                <li><strong>Inherent risks</strong> associated with consumption</li>
                                <li>This is <strong>NOT a sale</strong> - free sample for review only</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Product Selection -->
                <form id="betaSampleForm" style="margin-bottom:32px;">
                    <h3 style="margin:0 0 20px;color:#0f172a;font-size:22px;font-weight:800;">Choose Your Sample</h3>
                    
                    <div style="display:grid;gap:16px;margin-bottom:32px;">
                        <!-- Product A Option -->
                        <label class="product-choice-card" style="cursor:pointer;">
                            <input type="radio" name="product_choice" value="Product A" required style="margin-right:12px;">
                            <div style="flex:1;">
                                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                                    <i class="fas fa-droplet" style="font-size:24px;color:#0284c7;"></i>
                                    <h4 style="margin:0;font-size:20px;font-weight:800;color:#0c4a6e;">Product A (product-pack Sample)</h4>
                                </div>
                                <p style="margin:0;color:#64748b;font-size:14px;">20mg attribute per fl oz - Beginner-friendly strength</p>
                            </div>
                        </label>
                        
                        <!-- Product B Option -->
                        <label class="product-choice-card" style="cursor:pointer;">
                            <input type="radio" name="product_choice" value="Product B" required style="margin-right:12px;">
                            <div style="flex:1;">
                                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                                    <i class="fas fa-droplet" style="font-size:24px;color:#d97706;"></i>
                                    <h4 style="margin:0;font-size:20px;font-weight:800;color:#78350f;">Product B (product-pack Sample)</h4>
                                </div>
                                <p style="margin:0;color:#64748b;font-size:14px;">40mg attribute per fl oz - Maximum product specification</p>
                            </div>
                        </label>
                    </div>
                    
                    <!-- Shipping Information -->
                    <h3 style="margin:0 0 20px;color:#0f172a;font-size:22px;font-weight:800;">Shipping Information</h3>
                    
                    <div style="display:grid;gap:16px;margin-bottom:32px;">
                        <label>
                            <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">Full Name *</span>
                            <input type="text" name="recipient_name" 
                                value="<?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?>" 
                                required 
                                style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                        </label>
                        
                        <label>
                            <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">Address Line 1 *</span>
                            <input type="text" name="address_line1" placeholder="Street address" required 
                                style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                        </label>
                        
                        <label>
                            <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">Address Line 2</span>
                            <input type="text" name="address_line2" placeholder="Apt, suite, etc. (optional)" 
                                style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                        </label>
                        
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <label>
                                <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">City *</span>
                                <input type="text" name="city" required 
                                    style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                            </label>
                            
                            <label>
                                <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">State *</span>
                                <input type="text" name="state" maxlength="2" placeholder="FL" required 
                                    style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                            </label>
                        </div>
                        
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <label>
                                <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">ZIP Code *</span>
                                <input type="text" name="zip" required 
                                    style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                            </label>
                            
                            <label>
                                <span style="display:block;margin-bottom:6px;font-weight:600;color:#0f172a;">Phone</span>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                    style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                            </label>
                        </div>
                        
                        <input type="hidden" name="country" value="USA">
                    </div>
                    
                    <!-- Legal Waiver -->
                    <div style="background:#f8fafc;border:2px solid #cbd5e1;border-radius:12px;padding:24px;margin-bottom:24px;">
                        <h3 style="margin:0 0 16px;color:#0f172a;font-size:18px;font-weight:800;">
                            <i class="fas fa-file-contract" style="color:#64748b;margin-right:8px;"></i>
                            Beta Testing Waiver & Agreement
                        </h3>
                        
                        <div style="max-height:400px;overflow-y:auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;margin-bottom:20px;font-size:14px;line-height:1.6;">
                            <?php echo $waiverContent; ?>
                        </div>
                        
                        <label style="display:flex;align-items:start;gap:12px;cursor:pointer;padding:16px;background:#fff;border:2px solid #e2e8f0;border-radius:8px;">
                            <input type="checkbox" name="agree_waiver" required style="margin-top:4px;width:20px;height:20px;flex-shrink:0;">
                            <span style="color:#0f172a;font-size:15px;font-weight:600;">
                                I have read and agree to the Beta Testing Waiver. I understand this product is in testing phase, 
                                has not been lab tested for quality information, and I assume all risks. I am at least 18 years old.
                            </span>
                        </label>
                    </div>
                    
                    <!-- Submit Button -->
                    <div id="submitError" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;"></div>
                    
                    <button type="submit" class="btn" style="width:100%;padding:16px;font-size:18px;font-weight:700;">
                        <i class="fas fa-check-circle" style="margin-right:8px;"></i>
                        Submit Beta Sample Request
                    </button>
                    
                    <p style="margin:16px 0 0;text-align:center;color:#64748b;font-size:13px;">
                        You'll receive an email confirmation once your request is processed
                    </p>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.product-choice-card {
    display:flex;
    align-items:center;
    padding:20px;
    border:2px solid #e5e7eb;
    border-radius:12px;
    background:#fff;
    transition:all 0.2s;
}

.product-choice-card:hover {
    border-color:#0ea5e9;
    box-shadow:0 4px 20px rgba(14,165,233,0.15);
}

.product-choice-card input[type="radio"]:checked {
    accent-color:#0ea5e9;
}

.product-choice-card:has(input:checked) {
    border-color:#0ea5e9;
    background:#f0f9ff;
}

@media (max-width: 768px) {
    .card {
        padding:24px !important;
    }
    
    h1 {
        font-size:28px !important;
    }
    
    h3 {
        font-size:18px !important;
    }
    
    .product-choice-card h4 {
        font-size:18px !important;
    }
}
</style>

<script>
document.getElementById('betaSampleForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const errorDiv = document.getElementById('submitError');
    const originalBtnText = submitBtn.innerHTML;
    
    // Disable button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Submitting...';
    errorDiv.style.display = 'none';
    
    // Collect form data
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    try {
        const response = await fetch('<?php echo outbound_path('/api/beta-sample-request.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.ok) {
            // Success - redirect to success page or show message
            window.location.href = '<?php echo outbound_path('/customer/'); ?>?beta_sample_success=1';
        } else {
            // Show error
            errorDiv.textContent = result.error || 'An error occurred. Please try again.';
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    } catch (error) {
        errorDiv.textContent = 'Network error. Please check your connection and try again.';
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
});
</script>

<?php require_once __DIR__ . '/required/footer.php'; ?>



