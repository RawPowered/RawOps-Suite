<?php
$SEO = [
    'title' => 'Legal Agreements — Product',
    'description' => 'Review and sign required legal agreements to complete your certification.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';
require_login_or_redirect(path_with_base('/login.php'));

$uid = current_user_id();
$pdo = db();

// Check if user should be here (either trainee with complete training OR existing sales agent)
$userRoles = user_roles($uid);
$isSalesAgent = in_array('sales_agent', $userRoles ?? []);
$isTrainee = in_array('trainee', $userRoles ?? []);

// If they're a trainee, require training completion
if ($isTrainee && !$isSalesAgent && !is_training_complete($uid)) {
    flash_set('error', 'Please complete all required training modules first.');
    redirect('/onboarding/trainee/');
}

// If they're neither trainee nor sales agent, they shouldn't be here
if (!$isTrainee && !$isSalesAgent) {
    flash_set('error', 'You do not have access to this page.');
    redirect('/');
}

// Get required agreements for sales agents
$requiredTypes = ['nda', 'non_compete', 'ai_communications', 'sms_consent', 'email_consent', 'phone_consent', 'gmail_account'];

// Check which agreements are already signed
$stmt = $pdo->prepare('
    SELECT at.agreement_type, ua.id as signed_id
    FROM agreement_templates at
    LEFT JOIN user_agreements ua ON ua.agreement_template_id = at.id AND ua.user_id = ?
    WHERE at.is_active = 1 AND at.agreement_type IN (?, ?, ?, ?, ?, ?, ?)
    ORDER BY FIELD(at.agreement_type, "nda", "non_compete", "ai_communications", "sms_consent", "email_consent", "phone_consent", "gmail_account")
');
$stmt->execute([$uid, ...$requiredTypes]);
$agreementStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$completedCount = 0;
$pendingAgreements = [];

foreach ($agreementStatus as $status) {
    if ($status['signed_id']) {
        $completedCount++;
    } else {
        $pendingAgreements[] = $status['agreement_type'];
    }
}

// If all agreements are signed, redirect appropriately
if (count($pendingAgreements) === 0) {
    // If they're already a sales agent, go to their dashboard
    if ($isSalesAgent) {
        flash_set('success', 'All agreements signed! Thank you for completing the required legal documents.');
        redirect('/onboarding/sales-agent/');
    } else {
        // If they're a trainee completing certification
        redirect('/onboarding/trainee/complete.php');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_agreement') {
    $agreementType = $_POST['agreement_type'] ?? '';
    $signatureType = $_POST['signature_type'] ?? '';
    $signatureData = $_POST['signature_data'] ?? '';
    
    // Validate
    if (!in_array($agreementType, $requiredTypes)) {
        flash_set('error', 'Invalid agreement type.');
        redirect('/onboarding/trainee/agreements.php');
    }
    
    if (!in_array($signatureType, ['typed', 'canvas'])) {
        flash_set('error', 'Invalid signature type.');
        redirect('/onboarding/trainee/agreements.php');
    }
    
    if (empty($signatureData)) {
        flash_set('error', 'Signature is required.');
        redirect('/onboarding/trainee/agreements.php');
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get the agreement template
        $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$agreementType]);
        $template = $stmt->fetch();
        
        if (!$template) {
            throw new Exception('Agreement template not found.');
        }
        
        // Insert the signed agreement
        $stmt = $pdo->prepare('
            INSERT INTO user_agreements 
            (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                signature_type = VALUES(signature_type),
                signature_data = VALUES(signature_data),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                signed_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            $uid,
            $template['id'],
            $signatureType,
            $signatureData,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            $template['version']
        ]);
        
        $pdo->commit();
        
        // Redirect to refresh and show next agreement
        flash_set('success', 'Agreement signed successfully!');
        redirect(path_with_base('/onboarding/trainee/agreements.php'));
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Agreement signing error: ' . $e->getMessage());
        flash_set('error', 'Failed to sign agreement. Please try again.');
        redirect('/onboarding/trainee/agreements.php');
    }
}

// Get current agreement to display (first pending)
$currentAgreementType = $pendingAgreements[0] ?? null;

if (!$currentAgreementType) {
    // If they're already a sales agent, go to their dashboard
    if ($isSalesAgent) {
        flash_set('success', 'All agreements signed! Thank you for completing the required legal documents.');
        redirect('/onboarding/sales-agent/');
    } else {
        // If they're a trainee completing certification
        redirect('/onboarding/trainee/complete.php');
    }
}

// Fetch the agreement template
$stmt = $pdo->prepare('SELECT * FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$currentAgreementType]);
$agreement = $stmt->fetch();

if (!$agreement) {
    flash_set('error', 'Agreement not found.');
    redirect('/onboarding/trainee/');
}

$currentStep = $completedCount + 1;
$totalSteps = count($requiredTypes);

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.agreements-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px;
}

.agreement-progress {
    margin-bottom: 40px;
    text-align: center;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 16px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    transition: width 0.3s ease;
}

.progress-text {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
}

.agreement-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    padding: 40px;
}

.agreement-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 24px;
    color: #1f2937;
}

.agreement-content {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 32px;
    max-height: 400px;
    overflow-y: auto;
    margin-bottom: 32px;
    line-height: 1.6;
}

.agreement-content h2 {
    font-size: 22px;
    margin-top: 0;
    margin-bottom: 16px;
    color: #1f2937;
}

.agreement-content h3 {
    font-size: 18px;
    margin-top: 24px;
    margin-bottom: 12px;
    color: #374151;
}

.agreement-content ul {
    margin-left: 20px;
    margin-bottom: 16px;
}

.agreement-content li {
    margin-bottom: 8px;
}

.signature-section {
    margin-bottom: 32px;
}

.signature-type-selector {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
}

.signature-type-btn {
    flex: 1;
    padding: 12px 24px;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    color: #6b7280;
    transition: all 0.2s;
}

.signature-type-btn.active {
    border-color: #10b981;
    background: #ecfdf5;
    color: #10b981;
}

.signature-input-area {
    display: none;
}

.signature-input-area.active {
    display: block;
}

.typed-signature-input {
    width: 100%;
    padding: 16px;
    font-size: 24px;
    font-family: 'Brush Script MT', cursive;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    text-align: center;
}

.canvas-signature-wrapper {
    position: relative;
}

#signature-canvas {
    width: 100%;
    height: 200px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    cursor: crosshair;
    background: white;
    touch-action: none;
}

.canvas-instructions {
    text-align: center;
    margin-bottom: 12px;
    color: #6b7280;
    font-size: 14px;
}

.clear-signature-btn {
    margin-top: 12px;
    padding: 8px 16px;
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}

.clear-signature-btn:hover {
    background: #dc2626;
}

.agreement-actions {
    display: flex;
    gap: 16px;
    justify-content: space-between;
}

.btn {
    padding: 14px 28px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.btn-primary {
    background: #10b981;
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: #059669;
}

.btn-primary:disabled {
    background: #d1d5db;
    cursor: not-allowed;
}

.btn-secondary {
    background: white;
    color: #6b7280;
    border: 2px solid #e5e7eb;
}

.btn-secondary:hover {
    background: #f9fafb;
}
</style>

<div class="agreements-container">
    <div class="agreement-progress">
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo round(($completedCount / $totalSteps) * 100); ?>%;"></div>
        </div>
        <div class="progress-text">Agreement <?php echo $currentStep; ?> of <?php echo $totalSteps; ?></div>
    </div>
    
    <div class="agreement-card">
        <h1 class="agreement-title"><?php echo htmlspecialchars($agreement['title']); ?></h1>
        
        <div class="agreement-content">
            <?php echo $agreement['content']; ?>
        </div>
        
        <form method="POST" id="agreement-form">
            <input type="hidden" name="action" value="sign_agreement">
            <input type="hidden" name="agreement_type" value="<?php echo htmlspecialchars($currentAgreementType); ?>">
            <input type="hidden" name="signature_type" id="signature-type-input" value="typed">
            <input type="hidden" name="signature_data" id="signature-data-input" value="">
            
            <div class="signature-section">
                <h3>Your Signature</h3>
                
                <div class="signature-type-selector">
                    <button type="button" class="signature-type-btn active" data-type="typed">
                        ✍️ Type Name
                    </button>
                    <button type="button" class="signature-type-btn" data-type="canvas">
                        🖊️ Draw Signature
                    </button>
                </div>
                
                <div class="signature-input-area active" id="typed-signature-area">
                    <input 
                        type="text" 
                        class="typed-signature-input" 
                        id="typed-signature" 
                        placeholder="Type your full legal name"
                        maxlength="255"
                    >
                </div>
                
                <div class="signature-input-area" id="canvas-signature-area">
                    <div class="canvas-instructions">
                        Draw your signature below using your mouse or touchscreen
                    </div>
                    <div class="canvas-signature-wrapper">
                        <canvas id="signature-canvas" width="800" height="200"></canvas>
                    </div>
                    <button type="button" class="clear-signature-btn" id="clear-canvas-btn">
                        Clear Signature
                    </button>
                </div>
            </div>
            
            <div class="agreement-actions">
                <?php if ($isSalesAgent && !$isTrainee): ?>
                    <!-- Existing sales agents must complete agreements - no back button -->
                    <div style="flex: 1; text-align: left; color: #6b7280; font-size: 14px;">
                        <em>⚠️ Required: All agreements must be signed to access the platform</em>
                    </div>
                <?php else: ?>
                    <a href="/onboarding/trainee/" class="btn btn-secondary">
                        ← Back to Training
                    </a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" id="submit-btn" disabled>
                    Sign & Continue →
                </button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo outbound_path('/assets/signature-canvas.js'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('agreement-form');
    const signatureTypeInput = document.getElementById('signature-type-input');
    const signatureDataInput = document.getElementById('signature-data-input');
    const submitBtn = document.getElementById('submit-btn');
    
    // Signature type buttons
    const typeButtons = document.querySelectorAll('.signature-type-btn');
    const typedArea = document.getElementById('typed-signature-area');
    const canvasArea = document.getElementById('canvas-signature-area');
    const typedInput = document.getElementById('typed-signature');
    
    // Initialize canvas
    const signatureCanvas = new SignatureCanvas('signature-canvas');
    const clearCanvasBtn = document.getElementById('clear-canvas-btn');
    
    let currentType = 'typed';
    
    // Handle signature type switching
    typeButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const type = this.dataset.type;
            
            // Update button states
            typeButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Update input areas
            if (type === 'typed') {
                typedArea.classList.add('active');
                canvasArea.classList.remove('active');
                currentType = 'typed';
            } else {
                typedArea.classList.remove('active');
                canvasArea.classList.add('active');
                currentType = 'canvas';
            }
            
            signatureTypeInput.value = type;
            checkSignatureComplete();
        });
    });
    
    // Handle typed signature
    typedInput.addEventListener('input', checkSignatureComplete);
    
    // Handle canvas signature
    document.getElementById('signature-canvas').addEventListener('signature-changed', checkSignatureComplete);
    
    clearCanvasBtn.addEventListener('click', function() {
        signatureCanvas.clear();
        checkSignatureComplete();
    });
    
    function checkSignatureComplete() {
        let isComplete = false;
        
        if (currentType === 'typed') {
            const typedValue = typedInput.value.trim();
            isComplete = typedValue.length >= 3;
            signatureDataInput.value = typedValue;
        } else {
            const canvasData = signatureCanvas.getSignatureData();
            isComplete = canvasData !== null;
            signatureDataInput.value = canvasData || '';
        }
        
        submitBtn.disabled = !isComplete;
    }
    
    // Validate before submit
    form.addEventListener('submit', function(e) {
        if (submitBtn.disabled) {
            e.preventDefault();
            alert('Please provide your signature before continuing.');
            return false;
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

