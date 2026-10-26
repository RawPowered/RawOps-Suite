<?php
$SEO = [
    'title' => 'Suppliers — Product Accounting',
    'description' => 'Manage all suppliers and vendors',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Ensure user has accountant or admin role
$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    flash_set('error', 'Access denied. Accountant or Admin role required.');
    redirect('/login.php');
}

// Load CSS inline
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Suppliers']]); ?>
        <?php render_accounting_secondary_nav('suppliers-list.php'); ?>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">
                <i class="bi bi-building"></i> Suppliers & Vendors
            </h1>
            <p class="acct1015_page_subtitle">Manage supplier relationships and credentials</p>
        </div>
        <div style="display: flex; gap: 1rem;">
            <a href="suppliers.php" class="acct1015_btn_primary">
                <i class="bi bi-plus-circle"></i> Add New Supplier
            </a>
        </div>
    </div>

    <!-- Stats Widget -->
    <div class="acct1016_stats_widget" style="margin-bottom: 2rem;">
        <div class="acct1016_stat_mini">
            <div class="acct1016_stat_mini_value" id="totalSuppliersCount">0</div>
            <div class="acct1016_stat_mini_label">Active Suppliers</div>
        </div>
        <div class="acct1016_stat_mini">
            <div class="acct1016_stat_mini_value" id="pendingMatchesCount">0</div>
            <div class="acct1016_stat_mini_label">Pending Matches</div>
        </div>
        <div class="acct1016_stat_mini">
            <div class="acct1016_stat_mini_value" id="totalCredentialsCount">0</div>
            <div class="acct1016_stat_mini_label">Stored Credentials</div>
        </div>
    </div>

    <!-- Pending Matches Section -->
    <div id="pendingMatchesSection" style="display: none; margin-bottom: 2rem;">
        <div class="acct1015_card">
            <div class="acct1015_card_header">
                <h3 class="acct1015_card_title">
                    <i class="bi bi-exclamation-triangle" style="color: #d97706;"></i> 
                    Pending Supplier Matches
                </h3>
            </div>
            <div id="pendingMatchesContainer">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h3 class="acct1015_card_title">
                <i class="bi bi-list-ul"></i> All Suppliers
            </h3>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="searchSuppliers" placeholder="Search suppliers..." 
                       style="padding: 0.5rem 1rem; border: 1px solid #d1d5db; border-radius: 0.5rem; width: 300px;">
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table id="suppliersTable" class="table table-hover" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>Credentials</th>
                        <th>Expenses</th>
                        <th>YTD Spend</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated by DataTables -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/supplier-management.js"></script>
<script>
let suppliersTable;

document.addEventListener('DOMContentLoaded', function() {
    loadSupplierStats();
    loadPendingMatches();
    initSuppliersTable();
});

async function loadSupplierStats() {
    try {
        const response = await fetch('/api/accounting/supplier-api.php?action=get_supplier_stats');
        const result = await response.json();
        
        if (result.success && result.stats) {
            const stats = result.stats;
            document.getElementById('totalSuppliersCount').textContent = stats.total_active_suppliers || 0;
            document.getElementById('pendingMatchesCount').textContent = stats.pending_matches || 0;
            document.getElementById('totalCredentialsCount').textContent = stats.total_credentials || 0;
            
            if (stats.pending_matches > 0) {
                document.getElementById('pendingMatchesSection').style.display = 'block';
            }
        }
    } catch (error) {
        console.error('Error loading supplier stats:', error);
    }
}

async function loadPendingMatches() {
    try {
        const response = await fetch('/api/accounting/supplier-api.php?action=get_pending_matches&limit=10');
        const result = await response.json();
        
        if (result.success) {
            displayPendingMatches(result.pending_matches);
        }
    } catch (error) {
        console.error('Error loading pending matches:', error);
    }
}

function displayPendingMatches(matches) {
    const container = document.getElementById('pendingMatchesContainer');
    
    if (matches.length === 0) {
        document.getElementById('pendingMatchesSection').style.display = 'none';
        return;
    }
    
    container.innerHTML = matches.map(match => `
        <div class="acct1016_pending_match">
            <div class="acct1016_pending_match_info">
                <div class="acct1016_pending_match_vendor">
                    "${match.original_vendor_name}" → ${match.suggested_supplier_name}
                </div>
                <div class="acct1016_pending_match_suggestion">
                    $${parseFloat(match.amount).toFixed(2)} • ${match.expense_date} • 
                    <span class="acct1016_match_badge acct1016_match_badge_${getConfidenceLevel(match.match_confidence)}">
                        ${match.match_confidence}% match
                    </span>
                </div>
            </div>
            <div class="acct1016_pending_match_actions">
                <button class="acct1016_btn_approve" onclick="confirmMatch(${match.link_id})">
                    <i class="bi bi-check"></i> Confirm
                </button>
                <button class="acct1016_btn_reject" onclick="rejectMatch(${match.link_id})">
                    <i class="bi bi-x"></i> Reject
                </button>
            </div>
        </div>
    `).join('');
}

function getConfidenceLevel(confidence) {
    if (confidence >= 85) return 'high';
    if (confidence >= 70) return 'medium';
    return 'low';
}

async function confirmMatch(linkId) {
    try {
        const response = await fetch('/api/accounting/supplier-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'confirm_supplier_match',
                link_id: linkId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Match confirmed successfully');
            loadPendingMatches();
            loadSupplierStats();
            suppliersTable.ajax.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to confirm match'));
        }
    } catch (error) {
        alert('An error occurred: ' + error.message);
    }
}

async function rejectMatch(linkId) {
    const reason = prompt('Rejection reason (optional):');
    
    try {
        const response = await fetch('/api/accounting/supplier-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'reject_supplier_match',
                link_id: linkId,
                rejection_reason: reason
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Match rejected');
            loadPendingMatches();
            loadSupplierStats();
        } else {
            alert('Error: ' + (result.error || 'Failed to reject match'));
        }
    } catch (error) {
        alert('An error occurred: ' + error.message);
    }
}

function initSuppliersTable() {
    suppliersTable = $('#suppliersTable').DataTable({
        ajax: {
            url: '/api/accounting/supplier-api.php?action=get_suppliers&limit=500',
            dataSrc: 'suppliers'
        },
        columns: [
            { 
                data: null,
                render: function(data, type, row) {
                    return `
                        <div>
                            <strong style="font-size: 0.9375rem;">${row.supplier_name}</strong>
                            ${row.supplier_code ? `<br><small style="color: #6b7280; font-family: monospace;">${row.supplier_code}</small>` : ''}
                        </div>
                    `;
                }
            },
            { 
                data: 'supplier_type',
                render: function(data) {
                    return `<span class="acct1016_supplier_type_badge acct1016_supplier_type_${data}">${data}</span>`;
                }
            },
            { 
                data: null,
                render: function(data, type, row) {
                    let html = '';
                    if (row.contact_email) html += `<div><i class="bi bi-envelope"></i> ${row.contact_email}</div>`;
                    if (row.contact_phone) html += `<div><i class="bi bi-telephone"></i> ${row.contact_phone}</div>`;
                    return html || '<span style="color: #9ca3af;">—</span>';
                }
            },
            { 
                data: 'credential_count',
                render: function(data) {
                    return data > 0 ? `<span class="badge bg-success">${data}</span>` : '<span style="color: #9ca3af;">—</span>';
                }
            },
            { 
                data: 'expense_count',
                render: function(data) {
                    return data > 0 ? data : '<span style="color: #9ca3af;">0</span>';
                }
            },
            { 
                data: 'ytd_spend',
                render: function(data) {
                    return data > 0 ? `$${parseFloat(data).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}` : '<span style="color: #9ca3af;">$0.00</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    return `
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="suppliers.php?id=${row.id}" class="btn btn-sm btn-primary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <a href="add-expense.php?supplier_id=${row.id}" class="btn btn-sm btn-success">
                                <i class="bi bi-receipt"></i>
                            </a>
                        </div>
                    `;
                }
            }
        ],
        order: [[0, 'asc']],
        pageLength: 25,
        language: {
            emptyTable: 'No suppliers found. Click "Add New Supplier" to get started.'
        }
    });
    
    // Search box integration
    $('#searchSuppliers').on('keyup', function() {
        suppliersTable.search(this.value).draw();
    });
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>











