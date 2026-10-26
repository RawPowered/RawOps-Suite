<?php
$SEO = [
    'title' => 'Report Designer — Accounting',
    'description' => 'Build custom financial reports with dynamic filters',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';

// Ensure user has permission
$userId = current_user_id();
if (!$userId) {
    flash_set('error', 'Authentication required.');
    redirect('/login.php');
}

require_accounting_permission($userId, 'view_accounting', 'can_view_accounting');

$pdo = db();

// Get all account types for filtering
$accountTypesStmt = $pdo->query("
    SELECT DISTINCT account_type 
    FROM chart_of_accounts 
    WHERE is_active = 1
    ORDER BY account_type
");
$accountTypes = $accountTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get all accounts
$accountsStmt = $pdo->query("
    SELECT id, account_code, account_name, account_type 
    FROM chart_of_accounts 
    WHERE is_active = 1
    ORDER BY account_code
");
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.report-template-card { transition: all 0.3s; cursor: pointer; height: 100%; }
.report-template-card:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.15); }
.account-select-box { border: 1px solid #dee2e6; padding: 1rem; border-radius: 0.375rem; max-height: 400px; overflow-y: auto; }
.account-item { padding: 0.5rem; border-bottom: 1px solid #f0f0f0; cursor: pointer; }
.account-item:hover { background: #f8f9fa; }
.account-item.selected { background: #e7f3ff; border-left: 3px solid #007bff; }
.preview-section { background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 0.375rem; padding: 2rem; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-file-earmark-bar-graph text-primary"></i> Report Designer</h1>
            <p class="text-muted">Build custom financial reports with dynamic filters and export options</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Standard Report Templates -->
    <div class="mb-4">
        <h5 class="mb-3"><i class="bi bi-journal-bookmark"></i> Standard Reports</h5>
        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="card report-template-card shadow-sm border-primary" onclick="loadTemplate('profit_loss')">
                    <div class="card-body text-center">
                        <i class="bi bi-graph-up display-4 text-primary mb-3"></i>
                        <h5>Profit & Loss</h5>
                        <p class="text-muted small">Income statement with revenue, COGS, and expenses</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card report-template-card shadow-sm border-success" onclick="loadTemplate('balance_sheet')">
                    <div class="card-body text-center">
                        <i class="bi bi-clipboard-data display-4 text-success mb-3"></i>
                        <h5>Balance Sheet</h5>
                        <p class="text-muted small">Assets, liabilities, and equity snapshot</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card report-template-card shadow-sm border-info" onclick="loadTemplate('cash_flow')">
                    <div class="card-body text-center">
                        <i class="bi bi-cash-stack display-4 text-info mb-3"></i>
                        <h5>Cash Flow</h5>
                        <p class="text-muted small">Cash inflows and outflows by period</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card report-template-card shadow-sm border-secondary" onclick="loadTemplate('trial_balance')">
                    <div class="card-body text-center">
                        <i class="bi bi-list-check display-4 text-secondary mb-3"></i>
                        <h5>Trial Balance</h5>
                        <p class="text-muted small">All account balances for period</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Report Builder -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-tools"></i> Custom Report Builder</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- Configuration Panel -->
                <div class="col-md-4">
                    <h6 class="fw-bold mb-3">Report Configuration</h6>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Report Name</label>
                        <input type="text" class="form-control" id="reportName" placeholder="My Custom Report">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Date Range</label>
                        <div class="input-group input-group-sm mb-2">
                            <span class="input-group-text">From</span>
                            <input type="date" class="form-control" id="startDate" value="<?= date('Y-m-01') ?>">
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">To</span>
                            <input type="date" class="form-control" id="endDate" value="<?= date('Y-m-t') ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Account Types</label>
                        <?php foreach ($accountTypes as $type): ?>
                            <div class="form-check">
                                <input class="form-check-input account-type-check" type="checkbox" 
                                       value="<?= $type ?>" id="type_<?= $type ?>">
                                <label class="form-check-label" for="type_<?= $type ?>">
                                    <?= ucwords(str_replace('_', ' ', $type)) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Group By</label>
                        <select class="form-select" id="groupBy">
                            <option value="account">Account</option>
                            <option value="type">Account Type</option>
                            <option value="month">Month</option>
                            <option value="quarter">Quarter</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Include</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="includeZeroBalance" checked>
                            <label class="form-check-label" for="includeZeroBalance">
                                Accounts with zero balance
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="includeSubtotals" checked>
                            <label class="form-check-label" for="includeSubtotals">
                                Subtotals by type
                            </label>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <button class="btn btn-primary w-100 mb-2" onclick="generateReport()">
                        <i class="bi bi-play-circle"></i> Generate Report
                    </button>
                    
                    <button class="btn btn-outline-secondary w-100" onclick="saveTemplate()">
                        <i class="bi bi-save"></i> Save as Template
                    </button>
                </div>
                
                <!-- Preview Panel -->
                <div class="col-md-8">
                    <h6 class="fw-bold mb-3">Report Preview</h6>
                    <div id="reportPreview" class="preview-section">
                        <p class="text-center text-muted mb-0">
                            <i class="bi bi-eye display-4 d-block mb-3 opacity-25"></i>
                            Configure your report and click "Generate Report" to preview
                        </p>
                    </div>
                    
                    <div class="mt-3" id="exportButtons" style="display: none;">
                        <h6 class="fw-bold mb-2">Export Options</h6>
                        <div class="btn-group">
                            <button class="btn btn-outline-primary" onclick="exportReport('csv')">
                                <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                            </button>
                            <button class="btn btn-outline-success" onclick="exportReport('xlsx')">
                                <i class="bi bi-file-earmark-excel"></i> Excel
                            </button>
                            <button class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentReportData = null;

async function loadTemplate(templateName) {
    // Set defaults for standard templates
    const templates = {
        profit_loss: {
            name: 'Profit & Loss Statement',
            types: ['revenue', 'cogs', 'expense'],
            groupBy: 'type',
            includeZeroBalance: false
        },
        balance_sheet: {
            name: 'Balance Sheet',
            types: ['asset', 'liability', 'equity'],
            groupBy: 'type',
            includeZeroBalance: true
        },
        cash_flow: {
            name: 'Cash Flow Statement',
            types: ['asset'],
            groupBy: 'month',
            includeZeroBalance: false
        },
        trial_balance: {
            name: 'Trial Balance',
            types: ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'],
            groupBy: 'account',
            includeZeroBalance: false
        }
    };
    
    const template = templates[templateName];
    if (!template) return;
    
    document.getElementById('reportName').value = template.name;
    document.getElementById('groupBy').value = template.groupBy;
    document.getElementById('includeZeroBalance').checked = template.includeZeroBalance;
    
    // Check appropriate account types
    document.querySelectorAll('.account-type-check').forEach(checkbox => {
        checkbox.checked = template.types.includes(checkbox.value);
    });
    
    // Auto-generate report
    setTimeout(() => generateReport(), 300);
}

async function generateReport() {
    const reportName = document.getElementById('reportName').value || 'Custom Report';
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const groupBy = document.getElementById('groupBy').value;
    const includeZeroBalance = document.getElementById('includeZeroBalance').checked;
    const includeSubtotals = document.getElementById('includeSubtotals').checked;
    
    const selectedTypes = [];
    document.querySelectorAll('.account-type-check:checked').forEach(checkbox => {
        selectedTypes.push(checkbox.value);
    });
    
    if (selectedTypes.length === 0) {
        alert('Please select at least one account type');
        return;
    }
    
    const preview = document.getElementById('reportPreview');
    preview.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Generating report...</p></div>';
    
    try {
        const response = await fetch('/api/accounting/report-api.php?action=generate_custom_report', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                report_name: reportName,
                start_date: startDate,
                end_date: endDate,
                account_types: selectedTypes,
                group_by: groupBy,
                include_zero_balance: includeZeroBalance,
                include_subtotals: includeSubtotals
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentReportData = result;
            renderReportPreview(result);
            document.getElementById('exportButtons').style.display = 'block';
        } else {
            preview.innerHTML = `<p class="text-danger text-center">Error: ${result.error}</p>`;
        }
    } catch (error) {
        preview.innerHTML = `<p class="text-danger text-center">Failed to generate report: ${error.message}</p>`;
    }
}

function renderReportPreview(data) {
    const preview = document.getElementById('reportPreview');
    
    let html = `
        <div class="mb-3 text-center">
            <h4>${data.report_name}</h4>
            <p class="text-muted">
                Period: ${new Date(data.start_date).toLocaleDateString()} - ${new Date(data.end_date).toLocaleDateString()}
            </p>
        </div>
        
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Account</th>
                        <th class="text-end">Debits</th>
                        <th class="text-end">Credits</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    if (data.rows && data.rows.length > 0) {
        data.rows.forEach(row => {
            const rowClass = row.is_subtotal ? 'table-active fw-bold' : '';
            html += `
                <tr class="${rowClass}">
                    <td>${row.is_subtotal ? '<strong>' : ''}${row.account_name}${row.is_subtotal ? '</strong>' : ''}</td>
                    <td class="text-end">${row.total_debits ? '$' + parseFloat(row.total_debits).toFixed(2) : '-'}</td>
                    <td class="text-end">${row.total_credits ? '$' + parseFloat(row.total_credits).toFixed(2) : '-'}</td>
                    <td class="text-end ${row.is_subtotal ? 'fw-bold' : ''}">${row.balance ? '$' + parseFloat(row.balance).toFixed(2) : '$0.00'}</td>
                </tr>
            `;
        });
    } else {
        html += '<tr><td colspan="4" class="text-center text-muted">No data found for selected criteria</td></tr>';
    }
    
    html += '</tbody></table></div>';
    
    preview.innerHTML = html;
}

async function exportReport(format) {
    if (!currentReportData) {
        alert('Please generate a report first');
        return;
    }
    
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportName = document.getElementById('reportName').value || 'Custom Report';
    
    const selectedTypes = [];
    document.querySelectorAll('.account-type-check:checked').forEach(checkbox => {
        selectedTypes.push(checkbox.value);
    });
    
    const url = `/api/accounting/report-api.php?action=export_custom_report&format=${format}&start_date=${startDate}&end_date=${endDate}&account_types=${selectedTypes.join(',')}&report_name=${encodeURIComponent(reportName)}`;
    window.location.href = url;
}

async function saveTemplate() {
    const name = prompt('Enter template name:');
    if (!name) return;
    
    const selectedTypes = [];
    document.querySelectorAll('.account-type-check:checked').forEach(checkbox => {
        selectedTypes.push(checkbox.value);
    });
    
    const template = {
        name: name,
        report_name: document.getElementById('reportName').value,
        account_types: selectedTypes,
        group_by: document.getElementById('groupBy').value,
        include_zero_balance: document.getElementById('includeZeroBalance').checked,
        include_subtotals: document.getElementById('includeSubtotals').checked
    };
    
    localStorage.setItem('report_template_' + name.toLowerCase().replace(/\s/g, '_'), JSON.stringify(template));
    alert('Template saved successfully!');
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

