<?php
$SEO = [
    'title' => 'My 1099 Information — Sales Agent',
    'description' => 'View your 1099 tax information and download earnings summary',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Wrap in output buffering to catch errors
ob_start();

// Ensure user can view their own 1099
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

if (!can_view_own_1099($userId)) {
    flash_set('error', 'Access denied.');
    redirect('/');
}

$pdo = db();

// Get user info
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Get current year and last 3 years
$currentYear = date('Y');
$years = range($currentYear, $currentYear - 3);

// Get earnings by year
$earningsByYear = [];
$threshold = floatval(get_accounting_setting('1099_threshold', 600));

foreach ($years as $year) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as commission_count,
            COALESCE(SUM(amount), 0) as total_compensation
        FROM commissions
        WHERE agent_id = ?
        AND YEAR(created_at) = ?
    ");
    $stmt->execute([$userId, $year]);
    $earningsByYear[$year] = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if info is complete
$taxInfoComplete = !empty($user['tax_id_last4']) && !empty($user['tax_address_line1']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tax_info'])) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET tax_id_type = ?,
                tax_id_last4 = ?,
                tax_address_line1 = ?,
                tax_address_line2 = ?,
                tax_address_city = ?,
                tax_address_state = ?,
                tax_address_zip = ?,
                tax_address_country = ?,
                is_1099_contractor = 1
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['tax_id_type'],
            substr(preg_replace('/[^0-9]/', '', $_POST['tax_id']), -4), // Last 4 only
            $_POST['address_line1'],
            $_POST['address_line2'] ?? null,
            $_POST['city'],
            $_POST['state'],
            $_POST['zip'],
            $_POST['country'] ?? 'USA',
            $userId
        ]);
        
        $pdo->commit();
        flash_set('success', 'Tax information updated successfully.');
        redirect($_SERVER['REQUEST_URI']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('error', 'Failed to update tax information: ' . $e->getMessage());
        redirect($_SERVER['REQUEST_URI']);
    }
}
?>

<style>
.year-card { transition: all 0.3s; cursor: pointer; }
.year-card:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.15); }
.year-card.meets-threshold { border-left: 4px solid #28a745; }
.year-card.below-threshold { border-left: 4px solid #6c757d; }
.tax-info-card { background: #f8f9fa; }
.info-complete { color: #28a745; }
.info-incomplete { color: #dc3545; }
</style>

<div class="container-fluid mt-4">
    <?php if (function_exists('render_agent_financial_nav')): ?>
        <?php render_agent_financial_nav('my-1099.php'); ?>
    <?php endif; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-file-earmark-text text-info"></i> My 1099 Information</h1>
            <p class="text-muted">View your annual earnings and 1099 tax data</p>
        </div>
        <div>
            <a href="my-financials.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-graph-up"></i> Financials
            </a>
            <a href="books-detail.php" class="btn btn-outline-secondary">
                <i class="bi bi-book"></i> Books
            </a>
        </div>
    </div>

    <!-- Tax Info Status Alert -->
    <div class="alert alert-<?= $taxInfoComplete ? 'success' : 'warning' ?> alert-dismissible fade show">
        <h5 class="alert-heading">
            <i class="bi bi-<?= $taxInfoComplete ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            Tax Information Status: <?= $taxInfoComplete ? 'Complete' : 'Incomplete' ?>
        </h5>
        <?php if ($taxInfoComplete): ?>
            <p class="mb-0">Your tax information is on file. You will receive a 1099 form for years where earnings exceed $<?= number_format($threshold) ?>.</p>
        <?php else: ?>
            <p class="mb-2">Please complete your tax information below to ensure you receive your 1099 form(s).</p>
            <a href="#update-info" class="btn btn-warning btn-sm">
                <i class="bi bi-pencil"></i> Update Tax Information
            </a>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Year-over-Year Earnings -->
    <div class="row mb-4">
        <?php foreach ($years as $year): ?>
            <?php 
            $earnings = $earningsByYear[$year];
            $meetsThreshold = $earnings['total_compensation'] >= $threshold;
            ?>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm year-card <?= $meetsThreshold ? 'meets-threshold' : 'below-threshold' ?>">
                    <div class="card-body text-center">
                        <h4 class="mb-3"><?= $year ?></h4>
                        <h2 class="mb-1 <?= $meetsThreshold ? 'text-success' : 'text-muted' ?>">
                            $<?= number_format($earnings['total_compensation'], 2) ?>
                        </h2>
                        <small class="text-muted d-block mb-3"><?= $earnings['commission_count'] ?> commissions</small>
                        
                        <?php if ($meetsThreshold): ?>
                            <span class="badge bg-success w-100 mb-2">
                                <i class="bi bi-check-circle"></i> 1099 Required
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary w-100 mb-2">
                                Below Threshold
                            </span>
                        <?php endif; ?>
                        
                        <button class="btn btn-sm btn-outline-primary w-100" 
                                onclick="downloadSummary(<?= $year ?>)"
                                <?= !$meetsThreshold ? 'disabled' : '' ?>>
                            <i class="bi bi-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Tax Information Card -->
    <div class="card shadow-sm mb-4 tax-info-card" id="update-info">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-shield-lock"></i> Your Tax Information
                <?php if ($taxInfoComplete): ?>
                    <span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> Complete</span>
                <?php else: ?>
                    <span class="badge bg-warning ms-2"><i class="bi bi-exclamation-triangle"></i> Incomplete</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if ($taxInfoComplete && !isset($_GET['edit'])): ?>
                <!-- Display mode -->
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Tax ID Type:</strong> <?= ucfirst($user['tax_id_type']) ?></p>
                        <p><strong>Tax ID:</strong> ***-**-<?= htmlspecialchars($user['tax_id_last4']) ?></p>
                        <?php if ($user['tax_id_verified']): ?>
                            <p class="text-success"><i class="bi bi-check-circle"></i> Verified</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Address:</strong></p>
                        <p class="mb-1"><?= htmlspecialchars($user['tax_address_line1']) ?></p>
                        <?php if ($user['tax_address_line2']): ?>
                            <p class="mb-1"><?= htmlspecialchars($user['tax_address_line2']) ?></p>
                        <?php endif; ?>
                        <p><?= htmlspecialchars($user['tax_address_city'] . ', ' . $user['tax_address_state'] . ' ' . $user['tax_address_zip']) ?></p>
                        <p><?= htmlspecialchars($user['tax_address_country'] ?? 'USA') ?></p>
                    </div>
                </div>
                <hr>
                <a href="?edit=1#update-info" class="btn btn-outline-primary">
                    <i class="bi bi-pencil"></i> Edit Tax Information
                </a>
            <?php else: ?>
                <!-- Edit mode -->
                <form method="POST" onsubmit="return confirmUpdate()">
                    <input type="hidden" name="update_tax_info" value="1">
                    
                    <div class="alert alert-info">
                        <small><i class="bi bi-info-circle"></i> <strong>Security:</strong> We only store the last 4 digits of your Tax ID for verification purposes. Your full Tax ID is never stored in our system.</small>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tax ID Type <span class="text-danger">*</span></label>
                            <select name="tax_id_type" class="form-select" required>
                                <option value="ssn" <?= ($user['tax_id_type'] ?? '') === 'ssn' ? 'selected' : '' ?>>Social Security Number (SSN)</option>
                                <option value="ein" <?= ($user['tax_id_type'] ?? '') === 'ein' ? 'selected' : '' ?>>Employer Identification Number (EIN)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tax ID (Last 4 Digits) <span class="text-danger">*</span></label>
                            <input type="text" name="tax_id" class="form-control" 
                                   pattern="[0-9]{4}" maxlength="4" 
                                   placeholder="####" 
                                   value="<?= htmlspecialchars($user['tax_id_last4'] ?? '') ?>"
                                   required>
                            <small class="text-muted">Last 4 digits only</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Address Line 1 <span class="text-danger">*</span></label>
                            <input type="text" name="address_line1" class="form-control" 
                                   value="<?= htmlspecialchars($user['tax_address_line1'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Address Line 2</label>
                            <input type="text" name="address_line2" class="form-control" 
                                   value="<?= htmlspecialchars($user['tax_address_line2'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" class="form-control" 
                                   value="<?= htmlspecialchars($user['tax_address_city'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">State <span class="text-danger">*</span></label>
                            <input type="text" name="state" class="form-control" 
                                   value="<?= htmlspecialchars($user['tax_address_state'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">ZIP Code <span class="text-danger">*</span></label>
                            <input type="text" name="zip" class="form-control" 
                                   value="<?= htmlspecialchars($user['tax_address_zip'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Country</label>
                            <input type="text" name="country" class="form-control" 
                                   value="<?= htmlspecialchars($user['tax_address_country'] ?? 'USA') ?>">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Save Tax Information
                        </button>
                        <?php if ($taxInfoComplete): ?>
                            <a href="?" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Information Cards -->
    <div class="row">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary bg-opacity-10">
                    <h6 class="mb-0 text-primary"><i class="bi bi-question-circle"></i> What is a 1099 Form?</h6>
                </div>
                <div class="card-body">
                    <p class="small">A 1099-NEC (Nonemployee Compensation) form reports income you earned as an independent contractor. You'll receive this form if you earned $<?= number_format($threshold) ?> or more in a calendar year.</p>
                    <p class="small mb-0">You'll need this form to file your annual income tax return. Keep it with your tax records.</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-warning bg-opacity-10">
                    <h6 class="mb-0 text-warning"><i class="bi bi-calculator"></i> Tax Responsibilities</h6>
                </div>
                <div class="card-body">
                    <p class="small">As an independent contractor, you're responsible for:</p>
                    <ul class="small mb-0">
                        <li>Paying self-employment tax (15.3%)</li>
                        <li>Making quarterly estimated tax payments</li>
                        <li>Filing Schedule C with your tax return</li>
                        <li>Keeping records of business expenses</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function downloadSummary(year) {
    window.location.href = `/api/accounting/unified-api.php?action=download_1099_summary&agent_id=<?= $userId ?>&year=${year}`;
}

function confirmUpdate() {
    return confirm('Are you sure you want to update your tax information? Ensure all information is accurate.');
}
</script>

<?php 
ob_end_flush();
require_once __DIR__ . '/../../required/footer.php'; 
?>

