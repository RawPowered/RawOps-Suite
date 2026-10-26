<?php
$SEO = [
    'title' => '1099 Management — Product Accounting',
    'description' => 'Manage 1099 contractor reporting',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Ensure user has permission
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

require_accounting_permission($userId, 'generate_1099', 'can_generate_1099');

$pdo = db();

// Get year from request
$year = intval($_GET['year'] ?? date('Y'));
$threshold = floatval(get_accounting_setting('1099_threshold', 600));

// Get all contractors exceeding threshold
$contractorsStmt = $pdo->prepare("
    SELECT 
        c.user_id as agent_id,
        u.first_name,
        u.last_name,
        u.email,
        u.tax_id_type,
        u.tax_id_last4,
        u.tax_id_verified,
        u.tax_address_line1,
        u.tax_address_line2,
        u.tax_address_city,
        u.tax_address_state,
        u.tax_address_zip,
        u.is_1099_contractor,
        COUNT(c.id) as commission_count,
        COALESCE(SUM(c.amount), 0) as total_compensation,
        MAX(c.created_at) as last_commission_date
    FROM commissions c
    LEFT JOIN users u ON u.id = c.user_id
    WHERE YEAR(c.created_at) = ?
    GROUP BY c.user_id
    HAVING total_compensation >= ?
    ORDER BY total_compensation DESC
");
$contractorsStmt->execute([$year, $threshold]);
$contractors = $contractorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get 1099 form records
$formsStmt = $pdo->prepare("
    SELECT f.*, u.first_name, u.last_name
    FROM form_1099_records f
    LEFT JOIN users u ON u.id = f.contractor_user_id
    WHERE f.tax_year = ?
    ORDER BY f.total_compensation DESC
");
$formsStmt->execute([$year]);
$forms = $formsStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$totalContractors = count($contractors);
$totalCompensation = array_sum(array_column($contractors, 'total_compensation'));
$missingInfo = count(array_filter($contractors, fn($c) => empty($c['tax_id_last4']) || empty($c['tax_address_line1'])));
$ready = $totalContractors - $missingInfo;
?>

<link rel="stylesheet" href="assets/accounting-styles.css">

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => '1099 Management']]); ?>
        <?php render_accounting_secondary_nav('1099-management.php'); ?>
    <?php endif; ?>
    
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">1099 Management</h1>
            <p class="acct1015_page_subtitle">Contractor earnings and tax reporting for <?= $year ?></p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <select class="form-select d-inline-block me-2" onchange="window.location.href='?year='+this.value" style="width: 120px;">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-primary">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-primary"><?= $totalContractors ?></h2>
                    <small class="text-muted text-uppercase">Total Contractors</small>
                    <div class="mt-2">
                        <small class="text-muted">Above $<?= number_format($threshold) ?> threshold</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-success">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-success"><?= $ready ?></h2>
                    <small class="text-muted text-uppercase">Ready to File</small>
                    <div class="mt-2">
                        <small class="text-success"><i class="bi bi-check-circle"></i> Complete info</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-warning"><?= $missingInfo ?></h2>
                    <small class="text-muted text-uppercase">Incomplete Info</small>
                    <div class="mt-2">
                        <small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Needs attention</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-info">
                <div class="card-body text-center">
                    <h2 class="mb-0 text-info">$<?= number_format($totalCompensation, 2) ?></h2>
                    <small class="text-muted text-uppercase">Total Compensation</small>
                    <div class="mt-2">
                        <small class="text-muted">All contractors</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" onclick="exportAll1099Data()">
                    <i class="bi bi-download"></i> Export All to CSV
                </button>
                <button class="btn btn-success" onclick="finalizeAll1099s()">
                    <i class="bi bi-check-circle"></i> Finalize All Ready Forms
                </button>
                <button class="btn btn-info" onclick="sendReminders()">
                    <i class="bi bi-envelope"></i> Send Info Reminders
                </button>
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Summary
                </button>
            </div>
        </div>
    </div>

    <!-- Contractors List -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-people"></i> Contractors Requiring 1099</h5>
        </div>
        <div class="card-body p-0">
            <?php if (count($contractors) > 0): ?>
                <?php foreach ($contractors as $contractor): ?>
                    <?php
                    $hasInfo = !empty($contractor['tax_id_last4']) && !empty($contractor['tax_address_line1']);
                    $isVerified = $contractor['tax_id_verified'];
                    $cardClass = $hasInfo ? ($isVerified ? 'ready' : 'incomplete') : 'issue';
                    ?>
                    <div class="contractor-card <?= $cardClass ?> p-3 border-bottom">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <h6 class="mb-1">
                                    <?= htmlspecialchars($contractor['first_name'] . ' ' . $contractor['last_name']) ?>
                                    <?php if (!$hasInfo): ?>
                                        <i class="bi bi-exclamation-circle text-danger" title="Missing tax information"></i>
                                    <?php elseif (!$isVerified): ?>
                                        <i class="bi bi-info-circle text-warning" title="Tax ID not verified"></i>
                                    <?php else: ?>
                                        <i class="bi bi-check-circle text-success" title="Ready to file"></i>
                                    <?php endif; ?>
                                </h6>
                                <small class="text-muted"><?= htmlspecialchars($contractor['email']) ?></small>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="fw-bold text-success">$<?= number_format($contractor['total_compensation'], 2) ?></div>
                                    <small class="text-muted"><?= $contractor['commission_count'] ?> commissions</small>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <?php if ($hasInfo): ?>
                                    <small class="d-block">
                                        <strong>Tax ID:</strong> ***-**-<?= htmlspecialchars($contractor['tax_id_last4']) ?>
                                        <?= $contractor['tax_id_type'] === 'ein' ? '(EIN)' : '(SSN)' ?>
                                    </small>
                                    <small class="d-block text-truncate">
                                        <strong>Address:</strong> <?= htmlspecialchars($contractor['tax_address_city'] . ', ' . $contractor['tax_address_state']) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-danger">
                                        <i class="bi bi-exclamation-triangle"></i> Tax information incomplete
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-2">
                                <small class="text-muted d-block">Last Commission:</small>
                                <small class="fw-bold"><?= date('M j, Y', strtotime($contractor['last_commission_date'])) ?></small>
                            </div>
                            
                            <div class="col-md-2 text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="/onboarding/sales-agent/my-financials.php?user_id=<?= $contractor['agent_id'] ?>&year=<?= $year ?>" 
                                       class="btn btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button class="btn btn-outline-success" 
                                            onclick="generate1099(<?= $contractor['agent_id'] ?>, <?= $year ?>)" 
                                            title="Generate 1099"
                                            <?= !$hasInfo ? 'disabled' : '' ?>>
                                        <i class="bi bi-file-earmark-text"></i>
                                    </button>
                                    <button class="btn btn-outline-info" 
                                            onclick="sendReminder(<?= $contractor['agent_id'] ?>)" 
                                            title="Send Reminder">
                                        <i class="bi bi-envelope"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>
                    <p>No contractors exceed the $<?= number_format($threshold) ?> threshold for <?= $year ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filed 1099 Forms -->
    <?php if (count($forms) > 0): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-check2-square"></i> Filed 1099 Forms</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Contractor</th>
                            <th class="text-end">Compensation</th>
                            <th>Status</th>
                            <th>Finalized</th>
                            <th>Filed Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forms as $form): ?>
                        <tr>
                            <td><?= htmlspecialchars($form['first_name'] . ' ' . $form['last_name']) ?></td>
                            <td class="text-end fw-bold">$<?= number_format($form['total_compensation'], 2) ?></td>
                            <td>
                                <?php
                                $statusColors = ['draft' => 'secondary', 'finalized' => 'info', 'filed' => 'success', 'corrected' => 'warning'];
                                $statusColor = $statusColors[$form['form_status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $statusColor ?>"><?= ucfirst($form['form_status']) ?></span>
                            </td>
                            <td><?= $form['finalized_at'] ? date('M j, Y', strtotime($form['finalized_at'])) : '-' ?></td>
                            <td><?= $form['filed_at'] ? date('M j, Y', strtotime($form['filed_at'])) : '-' ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="view1099(<?= $form['id'] ?>)">
                                    <i class="bi bi-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
async function exportAll1099Data() {
    window.location.href = `/api/accounting/unified-api.php?action=calculate_1099_totals&year=<?= $year ?>&format=csv`;
}

async function generate1099(agentId, year) {
    if (!confirm('Generate 1099 form for this contractor?')) return;
    
    try {
        const response = await fetch('/api/accounting/unified-api.php?action=generate_1099_form', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_id: agentId, year: year })
        });
        
        const result = await response.json();
        if (result.success) {
            alert('1099 form generated successfully');
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to generate 1099: ' + error.message);
    }
}

async function sendReminder(agentId) {
    if (!confirm('Send tax information reminder email to this contractor?')) return;
    
    try {
        const response = await fetch('/api/accounting/unified-api.php?action=send_tax_info_reminder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_id: agentId })
        });
        
        const result = await response.json();
        if (result.success) {
            alert('Reminder sent successfully');
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to send reminder: ' + error.message);
    }
}

async function sendReminders() {
    if (!confirm('Send tax information reminders to all contractors with incomplete information?')) return;
    
    alert('This feature will be implemented to batch send reminder emails.');
}

async function finalizeAll1099s() {
    if (!confirm('Finalize all 1099 forms that are ready? This action cannot be undone.')) return;
    
    alert('This feature will batch finalize all ready 1099 forms.');
}

function view1099(formId) {
    window.location.href = `/admin/accounting/1099-detail.php?form_id=${formId}`;
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

