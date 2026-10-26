<?php
$SEO = [
    'title' => 'Data Sync — Product Accounting',
    'description' => 'Manage and monitor accounting data synchronization',
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

require_accounting_permission($userId, 'run_data_sync', 'can_run_data_sync');

$pdo = db();

// Get migration status
$statusStmt = $pdo->query("
    SELECT 
        sync_type,
        COUNT(*) as total_runs,
        SUM(CASE WHEN sync_status = 'completed' THEN 1 ELSE 0 END) as completed_runs,
        SUM(records_processed) as total_processed,
        SUM(records_created) as total_created,
        SUM(records_failed) as total_failed,
        MAX(completed_at) as last_run
    FROM accounting_sync_log
    GROUP BY sync_type
    ORDER BY sync_type
");
$syncStatus = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unmigrated counts
$unmigratedOrders = $pdo->query("
    SELECT COUNT(*) FROM orders 
    WHERE payment_status = 'paid' 
    AND (journal_entry_id IS NULL OR journal_entry_id = 0)
")->fetchColumn();

$unmigratedCommissions = $pdo->query("
    SELECT COUNT(*) FROM commissions 
    WHERE (journal_entry_id IS NULL OR journal_entry_id = 0)
")->fetchColumn();

$unmigratedApiMonths = $pdo->query("
    SELECT COUNT(DISTINCT DATE_FORMAT(usage_date, '%Y-%m')) 
    FROM api_usage_tracking 
    WHERE expense_created = 0
")->fetchColumn();

// Get recent sync logs
$recentLogsStmt = $pdo->query("
    SELECT s.*, u.first_name, u.last_name
    FROM accounting_sync_log s
    LEFT JOIN users u ON u.id = s.initiated_by
    ORDER BY s.started_at DESC
    LIMIT 20
");
$recentLogs = $recentLogsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get accounting settings
$autoPostOrders = get_accounting_setting('auto_post_order_entries', false);
$autoPostCommissions = get_accounting_setting('auto_post_commission_entries', false);
$requireApproval = get_accounting_setting('require_expense_approval', true);
?>

<link rel="stylesheet" href="assets/accounting-styles.css">

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Data Sync']]); ?>
        <?php render_accounting_secondary_nav('sync-controller.php'); ?>
    <?php endif; ?>
    
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">Data Sync</h1>
            <p class="acct1015_page_subtitle">Migrate historical transactions into accounting system</p>
        </div>
        <a href="index.php" class="acct1015_btn_secondary">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Sync Status Overview -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card sync-card border-primary" onclick="triggerSync('orders')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1" style="font-size: 0.75rem;">Orders</h6>
                            <h2 class="mb-0 text-primary"><?= number_format($unmigratedOrders) ?></h2>
                            <small class="text-muted">Unmigrated</small>
                        </div>
                        <i class="bi bi-cart-check text-primary" style="font-size: 2rem; opacity: 0.3;"></i>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); triggerSync('orders')">
                            <i class="bi bi-play-fill"></i> Sync Orders
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card sync-card border-warning" onclick="triggerSync('commissions')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1" style="font-size: 0.75rem;">Commissions</h6>
                            <h2 class="mb-0 text-warning"><?= number_format($unmigratedCommissions) ?></h2>
                            <small class="text-muted">Unmigrated</small>
                        </div>
                        <i class="bi bi-cash-coin text-warning" style="font-size: 2rem; opacity: 0.3;"></i>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-warning btn-sm" onclick="event.stopPropagation(); triggerSync('commissions')">
                            <i class="bi bi-play-fill"></i> Sync Commissions
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card sync-card border-info" onclick="triggerSync('api_usage')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1" style="font-size: 0.75rem;">API Usage</h6>
                            <h2 class="mb-0 text-info"><?= number_format($unmigratedApiMonths) ?></h2>
                            <small class="text-muted">Months Pending</small>
                        </div>
                        <i class="bi bi-cloud text-info" style="font-size: 2rem; opacity: 0.3;"></i>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-info btn-sm" onclick="event.stopPropagation(); triggerSync('api_usage')">
                            <i class="bi bi-play-fill"></i> Sync API Usage
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card sync-card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1" style="font-size: 0.75rem;">Full Sync</h6>
                            <h2 class="mb-0 text-success">All</h2>
                            <small class="text-muted">Data Sources</small>
                        </div>
                        <i class="bi bi-check-circle text-success" style="font-size: 2rem; opacity: 0.3;"></i>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-success btn-sm" onclick="triggerFullSync()">
                            <i class="bi bi-lightning-fill"></i> Full Sync
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-gear"></i> Automation Settings</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoPostOrders" 
                               <?= $autoPostOrders ? 'checked' : '' ?>
                               onchange="updateSetting('auto_post_order_entries', this.checked)">
                        <label class="form-check-label" for="autoPostOrders">
                            Auto-post Order Entries
                        </label>
                    </div>
                    <small class="text-muted">Automatically post journal entries for paid orders</small>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoPostCommissions"
                               <?= $autoPostCommissions ? 'checked' : '' ?>
                               onchange="updateSetting('auto_post_commission_entries', this.checked)">
                        <label class="form-check-label" for="autoPostCommissions">
                            Auto-post Commission Entries
                        </label>
                    </div>
                    <small class="text-muted">Automatically post commission expense entries</small>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="requireApproval"
                               <?= $requireApproval ? 'checked' : '' ?>
                               onchange="updateSetting('require_expense_approval', this.checked)">
                        <label class="form-check-label" for="requireApproval">
                            Require Expense Approval
                        </label>
                    </div>
                    <small class="text-muted">Expenses must be approved before posting</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Sync History -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Sync Operations</h5>
        </div>
        <div class="card-body">
            <div id="syncHistory">
                <?php if (count($recentLogs) > 0): ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <?php
                        $statusClass = [
                            'completed' => 'success',
                            'failed' => 'failed',
                            'running' => 'running',
                            'cancelled' => 'secondary'
                        ][$log['sync_status']] ?? 'secondary';
                        
                        $statusIcon = [
                            'completed' => 'check-circle-fill',
                            'failed' => 'x-circle-fill',
                            'running' => 'arrow-repeat',
                            'cancelled' => 'slash-circle'
                        ][$log['sync_status']] ?? 'circle';
                        ?>
                        <div class="log-entry <?= $statusClass ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>
                                        <i class="bi bi-<?= $statusIcon ?>"></i>
                                        <?= ucwords(str_replace('_', ' ', $log['sync_type'])) ?>
                                    </strong>
                                    <span class="badge bg-<?= $statusClass ?> ms-2"><?= ucfirst($log['sync_status']) ?></span>
                                    <br>
                                    <small class="text-muted">
                                        <?= $log['records_processed'] ?> processed, 
                                        <?= $log['records_created'] ?> created, 
                                        <?= $log['records_failed'] ?> failed
                                        — by <?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?>
                                        at <?= date('M j, Y g:i A', strtotime($log['started_at'])) ?>
                                    </small>
                                </div>
                                <?php if ($log['sync_status'] === 'completed' && $log['records_failed'] > 0): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="viewErrors(<?= $log['id'] ?>)">
                                        <i class="bi bi-exclamation-triangle"></i> View Errors
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No sync operations yet. Click a sync button above to start.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Progress Modal -->
    <div class="modal fade" id="syncProgressModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        Synchronizing Data...
                    </h5>
                </div>
                <div class="modal-body">
                    <div id="syncProgressText" class="mb-3">Preparing sync operation...</div>
                    <div class="sync-progress mb-2">
                        <div class="sync-progress-bar bg-primary" id="syncProgressBar" style="width: 0%"></div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <small id="syncProgressStats" class="text-muted">0 / 0 records</small>
                        <small id="syncProgressPercent" class="text-muted">0%</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let syncModal;
let currentSyncType = '';

document.addEventListener('DOMContentLoaded', function() {
    syncModal = new bootstrap.Modal(document.getElementById('syncProgressModal'));
});

async function triggerSync(syncType) {
    currentSyncType = syncType;
    syncModal.show();
    
    const startDate = '2020-01-01';
    const endDate = new Date().toISOString().split('T')[0];
    const batchSize = 100;
    
    let totalProcessed = 0;
    let totalCreated = 0;
    let hasMore = true;
    
    updateProgress(`Syncing ${syncType}...`, 0, 0, 0);
    
    try {
        while (hasMore) {
            let endpoint = '';
            let payload = { start_date: startDate, end_date: endDate, limit: batchSize };
            
            switch(syncType) {
                case 'orders':
                    endpoint = '/api/accounting/migration-api.php?action=migrate_orders';
                    break;
                case 'commissions':
                    endpoint = '/api/accounting/migration-api.php?action=migrate_commissions';
                    break;
                case 'api_usage':
                    endpoint = '/api/accounting/migration-api.php?action=migrate_api_usage';
                    payload = { month: new Date().toISOString().slice(0, 7) };
                    break;
            }
            
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Sync failed');
            }
            
            totalProcessed += result.processed || 0;
            totalCreated += result.created || 0;
            hasMore = result.has_more || false;
            
            // Update progress
            const progress = hasMore ? 50 : 100;
            updateProgress(
                `Synced ${totalProcessed} ${syncType}...`,
                progress,
                totalProcessed,
                totalCreated
            );
            
            if (!hasMore || syncType === 'api_usage') {
                break;
            }
        }
        
        setTimeout(() => {
            syncModal.hide();
            window.location.reload();
        }, 1500);
        
    } catch (error) {
        updateProgress(`Error: ${error.message}`, 100, 0, 0);
        setTimeout(() => syncModal.hide(), 3000);
    }
}

async function triggerFullSync() {
    if (!confirm('This will sync ALL data sources. This may take several minutes. Continue?')) {
        return;
    }
    
    for (const syncType of ['orders', 'commissions', 'api_usage']) {
        await triggerSync(syncType);
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
}

function updateProgress(text, percent, processed, created) {
    document.getElementById('syncProgressText').textContent = text;
    document.getElementById('syncProgressBar').style.width = percent + '%';
    document.getElementById('syncProgressStats').textContent = 
        `${processed} processed, ${created} created`;
    document.getElementById('syncProgressPercent').textContent = Math.round(percent) + '%';
}

async function updateSetting(key, value) {
    try {
        const response = await fetch('/api/accounting/unified-api.php?action=update_setting', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ setting_key: key, setting_value: value ? '1' : '0' })
        });
        
        const result = await response.json();
        if (result.success) {
            // Show success toast
            console.log('Setting updated');
        }
    } catch (error) {
        console.error('Failed to update setting:', error);
        alert('Failed to update setting');
    }
}

function viewErrors(syncLogId) {
    window.location.href = `reconciliation.php?sync_log_id=${syncLogId}`;
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

