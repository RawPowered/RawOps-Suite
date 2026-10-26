<?php
$SEO = [
    'title' => 'Journal Entries — Product Accounting',
    'description' => 'Manage journal entries and double-entry bookkeeping',
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

$pdo = db();

// Initialize variables
$entries = [];
$stats = ['total_entries' => 0, 'draft_count' => 0, 'posted_count' => 0, 'void_count' => 0];
$migrationNeeded = false;

// Get filter parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$status = $_GET['status'] ?? '';
$referenceType = $_GET['reference_type'] ?? '';

try {
    $testStmt = $pdo->query("SELECT COUNT(*) FROM journal_entries LIMIT 1");
    if ($testStmt) {
        // Build query
        $sql = "
            SELECT je.*,
                   creator.first_name as creator_first_name, creator.last_name as creator_last_name,
                   (SELECT SUM(debit_amount) FROM journal_entry_lines WHERE journal_entry_id = je.id) as total_amount,
                   (SELECT COUNT(*) FROM journal_entry_lines WHERE journal_entry_id = je.id) as line_count
            FROM journal_entries je
            LEFT JOIN users creator ON creator.id = je.created_by
            WHERE je.entry_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];

        if ($status) {
            $sql .= " AND je.status = ?";
            $params[] = $status;
        }

        if ($referenceType) {
            $sql .= " AND je.reference_type = ?";
            $params[] = $referenceType;
        }

        $sql .= " ORDER BY je.entry_date DESC, je.id DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get stats
        $statsStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_entries,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted_count,
                SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) as void_count
            FROM journal_entries
            WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ");
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $migrationNeeded = true;
    }
} catch (Exception $e) {
    error_log("Journal page error: " . $e->getMessage());
    $migrationNeeded = true;
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
        <?php render_accounting_breadcrumbs([['label' => 'Journal Entries']]); ?>
        <?php render_accounting_secondary_nav('journal.php'); ?>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">Journal Entries</h1>
            <p class="acct1015_page_subtitle">Double-entry bookkeeping transactions</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <button class="acct1015_btn_primary" data-bs-toggle="modal" data-bs-target="#createEntryModal">
                <i class="bi bi-plus-circle"></i>
                <span>New Entry</span>
            </button>
            <a href="index.php" class="acct1015_btn_secondary">
                <i class="bi bi-arrow-left"></i>
                <span>Dashboard</span>
            </a>
        </div>
    </div>

    <?php if ($migrationNeeded): ?>
    <div class="acct1015_card" style="background: #fffbeb; border-color: #fbbf24;">
        <h5 style="color: #d97706; margin-bottom: 1rem;"><i class="bi bi-exclamation-triangle"></i> Database Migration Required</h5>
        <p style="color: #92400e; margin-bottom: 1rem;">The accounting tables have not been created yet. Please run the database migration first:</p>
        <pre style="background: #1f2937; color: #f9fafb; padding: 1rem; border-radius: 0.5rem; overflow-x: auto;"><code>-- In MySQL/PHPMyAdmin, execute:
source migrations/2025-10-11-accounting-suite.sql;</code></pre>
        <a href="index.php" class="acct1015_btn_primary" style="margin-top: 1rem;">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    <?php else: ?>

    <!-- Stats -->
    <div class="acct1015_stat_grid">
        <div class="acct1015_stat_card" style="--stat-color: #3b82f6;">
            <div class="acct1015_stat_label">Total Entries</div>
            <div class="acct1015_stat_value"><?= number_format($stats['total_entries']) ?></div>
            <div class="acct1015_stat_subtext">Last 90 days</div>
        </div>
        
        <div class="acct1015_stat_card" style="--stat-color: #fbbf24;">
            <div class="acct1015_stat_label">Draft</div>
            <div class="acct1015_stat_value"><?= number_format($stats['draft_count']) ?></div>
            <div class="acct1015_stat_subtext">Awaiting posting</div>
        </div>
        
        <div class="acct1015_stat_card" style="--stat-color: #10b981;">
            <div class="acct1015_stat_label">Posted</div>
            <div class="acct1015_stat_value"><?= number_format($stats['posted_count']) ?></div>
            <div class="acct1015_stat_subtext">Finalized</div>
        </div>
        
        <div class="acct1015_stat_card" style="--stat-color: #dc2626;">
            <div class="acct1015_stat_label">Void</div>
            <div class="acct1015_stat_value"><?= number_format($stats['void_count']) ?></div>
            <div class="acct1015_stat_subtext">Cancelled</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="acct1015_filters">
        <form method="GET" class="acct1015_filter_row">
            <div>
                <label class="acct1015_form_label">Start Date</label>
                <input type="date" name="start_date" class="acct1015_form_input" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div>
                <label class="acct1015_form_label">End Date</label>
                <input type="date" name="end_date" class="acct1015_form_input" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div>
                <label class="acct1015_form_label">Status</label>
                <select name="status" class="acct1015_form_input">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>Posted</option>
                    <option value="void" <?= $status === 'void' ? 'selected' : '' ?>>Void</option>
                </select>
            </div>
            <div>
                <label class="acct1015_form_label">Type</label>
                <select name="reference_type" class="acct1015_form_input">
                    <option value="">All Types</option>
                    <option value="order" <?= $referenceType === 'order' ? 'selected' : '' ?>>Order</option>
                    <option value="commission" <?= $referenceType === 'commission' ? 'selected' : '' ?>>Commission</option>
                    <option value="expense" <?= $referenceType === 'expense' ? 'selected' : '' ?>>Expense</option>
                    <option value="manual" <?= $referenceType === 'manual' ? 'selected' : '' ?>>Manual</option>
                </select>
            </div>
            <div>
                <button type="submit" class="acct1015_btn_primary" style="width: 100%; margin-top: 1.65rem;">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Entries Table -->
    <div class="acct1015_card">
        <div class="acct1015_card_header">
            <h2 class="acct1015_card_title">
                <i class="bi bi-journal-text" style="color: #3b82f6;"></i>
                Journal Entries
            </h2>
            <div style="display: flex; gap: 0.75rem;">
                <button class="acct1015_btn_secondary" onclick="bulkPostDrafts()">
                    <i class="bi bi-check-all"></i> Post All Drafts
                </button>
            </div>
        </div>

        <?php if (count($entries) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="acct1015_table" id="journalEntriesTable">
                <thead class="acct1015_table_header">
                    <tr>
                        <th>Date</th>
                        <th>Entry Number</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th style="text-align: right;">Amount</th>
                        <th style="text-align: center;">Lines</th>
                        <th>Status</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                    <tr class="acct1015_table_row" style="cursor: pointer;" onclick="viewEntry(<?= $entry['id'] ?>)">
                        <td><?= date('M j, Y', strtotime($entry['entry_date'])) ?></td>
                        <td><code style="background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem;"><?= htmlspecialchars($entry['entry_number']) ?></code></td>
                        <td style="font-weight: 500;"><?= htmlspecialchars(substr($entry['description'], 0, 50)) ?><?= strlen($entry['description']) > 50 ? '...' : '' ?></td>
                        <td>
                            <span class="acct1015_badge acct1015_badge_neutral"><?= htmlspecialchars($entry['entry_type']) ?></span>
                        </td>
                        <td>
                            <?php if ($entry['reference_type']): ?>
                                <span class="acct1015_badge acct1015_badge_info" style="font-size: 0.75rem;">
                                    <?= htmlspecialchars($entry['reference_type']) ?><?= $entry['reference_id'] ? ' #' . $entry['reference_id'] : '' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: #111827;">$<?= number_format($entry['total_amount'], 2) ?></td>
                        <td style="text-align: center;">
                            <span class="acct1015_badge acct1015_badge_neutral"><?= $entry['line_count'] ?></span>
                        </td>
                        <td>
                            <?php
                            $statusClasses = [
                                'draft' => 'acct1015_badge_warning',
                                'posted' => 'acct1015_badge_success',
                                'void' => 'acct1015_badge_danger'
                            ];
                            ?>
                            <span class="acct1015_badge <?= $statusClasses[$entry['status']] ?? 'acct1015_badge_neutral' ?>">
                                <?= ucfirst($entry['status']) ?>
                            </span>
                        </td>
                        <td style="font-size: 0.875rem; color: #6b7280;">
                            <?= htmlspecialchars($entry['creator_first_name'] . ' ' . $entry['creator_last_name']) ?>
                            <?php if ($entry['is_auto_generated']): ?>
                                <i class="bi bi-robot" style="color: #10b981;" title="Auto-generated"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="acct1015_empty">
            <i class="bi bi-inbox acct1015_empty_icon"></i>
            <div class="acct1015_empty_title">No journal entries found</div>
            <p class="acct1015_empty_text">Try adjusting your date range or filters</p>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<!-- Create Entry Modal -->
<div class="modal fade" id="createEntryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: 1rem; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <h5 class="modal-title" style="font-weight: 600; color: #111827;">
                    <i class="bi bi-plus-circle" style="color: #d97706;"></i> Create Journal Entry
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <form id="createEntryForm">
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <label class="acct1015_form_label">Entry Date</label>
                            <input type="date" class="acct1015_form_input" id="entryDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label class="acct1015_form_label">Entry Type</label>
                            <select class="acct1015_form_input" id="entryType">
                                <option value="standard">Standard</option>
                                <option value="adjusting">Adjusting</option>
                                <option value="closing">Closing</option>
                                <option value="reversing">Reversing</option>
                            </select>
                        </div>
                        <div>
                            <label class="acct1015_form_label">Entry Number</label>
                            <input type="text" class="acct1015_form_input" id="entryNumber" placeholder="Auto-generated" readonly style="background: #f9fafb;">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label class="acct1015_form_label">Description</label>
                        <input type="text" class="acct1015_form_input" id="entryDescription" placeholder="What is this entry for?" required>
                    </div>
                    
                    <div style="background: #f9fafb; padding: 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h6 style="margin: 0; font-weight: 600; color: #374151;">Entry Lines</h6>
                            <button type="button" class="acct1015_btn_secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;" onclick="addEntryLine()">
                                <i class="bi bi-plus"></i> Add Line
                            </button>
                        </div>
                        
                        <div id="entryLinesContainer"></div>
                        
                        <div style="background: white; padding: 1rem; border-radius: 0.5rem; margin-top: 1rem; border: 2px solid #e5e7eb;">
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; text-align: center;">
                                <div>
                                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Total Debits</div>
                                    <div style="font-size: 1.25rem; font-weight: 700; color: #111827;" id="totalDebits">$0.00</div>
                                </div>
                                <div>
                                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Total Credits</div>
                                    <div style="font-size: 1.25rem; font-weight: 700; color: #111827;" id="totalCredits">$0.00</div>
                                </div>
                                <div>
                                    <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Difference</div>
                                    <div style="font-size: 1.25rem; font-weight: 700;" id="balanceDiff">$0.00</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <button type="button" class="acct1015_btn_secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="acct1015_btn_primary" onclick="saveJournalEntry()">
                    <i class="bi bi-save"></i> Save Entry
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Entry Detail Modal -->
<div class="modal fade" id="entryDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 1rem; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <h5 class="modal-title" style="font-weight: 600; color: #111827;">
                    <i class="bi bi-eye" style="color: #3b82f6;"></i> Journal Entry Detail
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;" id="entryDetailContent">
                <div style="text-align: center; padding: 3rem;">
                    <div class="spinner-border" style="color: #d97706;"></div>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #f3f4f6; padding: 1.5rem 2rem;">
                <button type="button" class="acct1015_btn_secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="acct1015_btn_primary" id="postEntryBtn" onclick="postEntry()" style="display: none;">
                    <i class="bi bi-check-circle"></i> Post Entry
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
let journalTable;
let accounts = [];
let currentEntryId = null;
let createEntryModal, detailModal;

document.addEventListener('DOMContentLoaded', function() {
    createEntryModal = new bootstrap.Modal(document.getElementById('createEntryModal'));
    detailModal = new bootstrap.Modal(document.getElementById('entryDetailModal'));
    
    if ($('#journalEntriesTable').length) {
        journalTable = $('#journalEntriesTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            language: {
                search: "Search entries:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    }
    
    loadAccounts();
    addEntryLine();
    addEntryLine();
});

async function loadAccounts() {
    try {
        const response = await fetch('/api/accounting/unified-api.php?action=get_accounts');
        const result = await response.json();
        if (result.success) {
            accounts = result.accounts;
        }
    } catch (error) {
        console.error('Failed to load accounts:', error);
    }
}

function addEntryLine() {
    const container = document.getElementById('entryLinesContainer');
    const lineNumber = container.children.length + 1;
    
    const lineHtml = `
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 0.75rem; margin-bottom: 0.75rem;" class="entry-line">
            <select class="acct1015_form_input account-select" required style="font-size: 0.875rem;">
                <option value="">Select Account...</option>
                ${accounts.map(a => `<option value="${a.id}">${a.account_code} - ${a.account_name}</option>`).join('')}
            </select>
            <input type="number" class="acct1015_form_input debit-input" placeholder="Debit" step="0.01" min="0" onchange="updateBalanceIndicator()">
            <input type="number" class="acct1015_form_input credit-input" placeholder="Credit" step="0.01" min="0" onchange="updateBalanceIndicator()">
            <button type="button" class="acct1015_btn_secondary" style="padding: 0.75rem;" onclick="removeEntryLine(this)" title="Remove line">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', lineHtml);
}

function removeEntryLine(btn) {
    btn.closest('.entry-line').remove();
    updateBalanceIndicator();
}

function updateBalanceIndicator() {
    let totalDebits = 0;
    let totalCredits = 0;
    
    document.querySelectorAll('.debit-input').forEach(input => {
        totalDebits += parseFloat(input.value || 0);
    });
    
    document.querySelectorAll('.credit-input').forEach(input => {
        totalCredits += parseFloat(input.value || 0);
    });
    
    document.getElementById('totalDebits').textContent = '$' + totalDebits.toFixed(2);
    document.getElementById('totalCredits').textContent = '$' + totalCredits.toFixed(2);
    
    const diff = Math.abs(totalDebits - totalCredits);
    const diffElem = document.getElementById('balanceDiff');
    diffElem.textContent = '$' + diff.toFixed(2);
    diffElem.style.color = diff < 0.01 ? '#10b981' : '#dc2626';
}

async function saveJournalEntry() {
    const entryDate = document.getElementById('entryDate').value;
    const entryType = document.getElementById('entryType').value;
    const description = document.getElementById('entryDescription').value;
    
    if (!description) {
        alert('Please enter a description');
        return;
    }
    
    const lines = [];
    document.querySelectorAll('.entry-line').forEach(line => {
        const accountId = line.querySelector('.account-select').value;
        const debit = parseFloat(line.querySelector('.debit-input').value || 0);
        const credit = parseFloat(line.querySelector('.credit-input').value || 0);
        
        if (accountId && (debit > 0 || credit > 0)) {
            lines.push({
                account_id: accountId,
                debit_amount: debit,
                credit_amount: credit
            });
        }
    });
    
    if (lines.length < 2) {
        alert('Journal entry must have at least 2 lines');
        return;
    }
    
    try {
        const response = await fetch('/api/accounting/journal-api.php?action=create_entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                entry_date: entryDate,
                entry_type: entryType,
                description: description,
                lines: lines
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            createEntryModal.hide();
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to create entry: ' + error.message);
    }
}

async function viewEntry(entryId) {
    currentEntryId = entryId;
    detailModal.show();
    
    try {
        const response = await fetch(`/api/accounting/unified-api.php?action=get_entry_summary&entry_id=${entryId}`);
        const result = await response.json();
        
        if (result.success) {
            const entry = result.entry;
            const lines = result.lines;
            
            let html = `
                <div style="margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Entry Number</div>
                            <code style="background: #f3f4f6; padding: 0.5rem; border-radius: 0.5rem; display: inline-block;">${entry.entry_number}</code>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Date</div>
                            <div style="font-weight: 600;">${new Date(entry.entry_date).toLocaleDateString()}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Type</div>
                            <span class="acct1015_badge acct1015_badge_neutral">${entry.entry_type}</span>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Status</div>
                            <span class="acct1015_badge acct1015_badge_${entry.status === 'posted' ? 'success' : entry.status === 'draft' ? 'warning' : 'danger'}">${entry.status}</span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.25rem;">Description</div>
                        <div style="font-weight: 500;">${entry.description}</div>
                    </div>
                </div>
                
                <table class="acct1015_table">
                    <thead class="acct1015_table_header">
                        <tr>
                            <th>Account</th>
                            <th style="text-align: right;">Debit</th>
                            <th style="text-align: right;">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            lines.forEach(line => {
                html += `
                    <tr class="acct1015_table_row">
                        <td><strong>${line.account_code}</strong> - ${line.account_name}</td>
                        <td style="text-align: right; font-weight: 600;">${line.debit_amount > 0 ? '$' + parseFloat(line.debit_amount).toFixed(2) : '-'}</td>
                        <td style="text-align: right; font-weight: 600;">${line.credit_amount > 0 ? '$' + parseFloat(line.credit_amount).toFixed(2) : '-'}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            
            document.getElementById('entryDetailContent').innerHTML = html;
            document.getElementById('postEntryBtn').style.display = entry.status === 'draft' ? 'block' : 'none';
        }
    } catch (error) {
        document.getElementById('entryDetailContent').innerHTML = '<p style="color: #dc2626; text-align: center;">Failed to load entry details</p>';
    }
}

async function postEntry() {
    if (!confirm('Post this journal entry? This action cannot be undone.')) return;
    
    try {
        const response = await fetch('/api/accounting/journal-api.php?action=post_entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ entry_id: currentEntryId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            detailModal.hide();
            window.location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to post entry: ' + error.message);
    }
}

function bulkPostDrafts() {
    alert('Bulk post feature: This will post all draft entries. Implementation ready in API.');
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
