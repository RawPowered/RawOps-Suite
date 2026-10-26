<?php
$SEO = [
    'title' => 'Add Expense — Product Accounting',
    'description' => 'Record a new expense',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
@include_once __DIR__ . '/../../required/accounting-breadcrumbs.php';

// Ensure user has accountant, admin, or operations role
$userId = current_user_id();
$roles = get_user_roles($userId);
$canManageExpenses = in_array('accountant', $roles) || in_array('admin', $roles) || in_array('operations', $roles);

if (!$userId || !$canManageExpenses) {
    flash_set('error', 'Access denied.');
    redirect('/login.php');
}

$pdo = db();

// Get expense categories
$categoriesStmt = $pdo->query("SELECT id, category_name, description FROM expense_categories WHERE is_active = 1 ORDER BY category_name ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-select supplier if passed
$preSelectedSupplierId = $_GET['supplier_id'] ?? null;
$preSelectedSupplier = null;

if ($preSelectedSupplierId) {
    $supplierStmt = $pdo->prepare("SELECT id, supplier_name FROM suppliers WHERE id = ? AND is_active = 1");
    $supplierStmt->execute([$preSelectedSupplierId]);
    $preSelectedSupplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
}

// Load CSS inline
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Add Expense']]); ?>
        <?php render_accounting_secondary_nav('add-expense.php'); ?>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">
                <i class="bi bi-receipt-cutoff"></i> Add New Expense
            </h1>
            <p class="acct1015_page_subtitle">Record an expense with receipt upload and supplier linking</p>
        </div>
        <div>
            <a href="index.php" class="acct1015_btn_secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Main Form Grid -->
    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem;">
        
        <!-- Left Column: Expense Details -->
        <div>
            <div class="acct1015_card">
                <div class="acct1015_card_header">
                    <h3 class="acct1015_card_title">
                        <i class="bi bi-info-circle"></i> Expense Information
                    </h3>
                </div>

                <form id="expenseForm" enctype="multipart/form-data">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Expense Date *</label>
                            <input type="date" class="acct1016_form_input" id="expense_date" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Amount *</label>
                            <div style="position: relative;">
                                <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #6b7280; font-weight: 600;">$</span>
                                <input type="number" class="acct1016_form_input" id="amount" 
                                       step="0.01" min="0" required 
                                       style="padding-left: 2rem;" placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <div class="acct1016_form_group" style="margin-bottom: 1.5rem;">
                        <label class="acct1016_form_label">Vendor / Supplier *</label>
                        <div class="acct1016_autocomplete">
                            <input type="text" class="acct1016_form_input" id="vendor_name" 
                                   value="<?= htmlspecialchars($preSelectedSupplier['supplier_name'] ?? '') ?>"
                                   placeholder="Start typing supplier name..." 
                                   oninput="searchSuppliersForExpense(this.value)" 
                                   autocomplete="off" required>
                            <input type="hidden" id="supplier_id" value="<?= $preSelectedSupplierId ?? '' ?>">
                            <div id="supplierAutocomplete" class="acct1016_autocomplete_dropdown" style="display: none;"></div>
                        </div>
                        <small style="color: #6b7280; font-size: 0.8125rem; margin-top: 0.5rem; display: block;">
                            <i class="bi bi-lightbulb"></i> System will auto-suggest existing suppliers or you can enter a new one
                        </small>
                    </div>

                    <div class="acct1016_form_group" style="margin-bottom: 1.5rem;">
                        <label class="acct1016_form_label">Description / What was purchased? *</label>
                        <textarea class="acct1016_form_input" id="description" rows="4" 
                                  placeholder="Describe what this expense was for..." required></textarea>
                    </div>

                    <div class="acct1016_form_group" style="margin-bottom: 1.5rem;">
                        <label class="acct1016_form_label">Expense Category *</label>
                        <select class="acct1016_form_select" id="expense_category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" title="<?= htmlspecialchars($cat['description'] ?? '') ?>">
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Payment Status</label>
                            <select class="acct1016_form_select" id="payment_status">
                                <option value="unpaid">Unpaid</option>
                                <option value="paid">Paid</option>
                                <option value="partial">Partial</option>
                            </select>
                        </div>
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Payment Date</label>
                            <input type="date" class="acct1016_form_input" id="payment_date">
                        </div>
                    </div>

                    <div class="acct1016_form_group" style="margin-bottom: 1.5rem;">
                        <label class="acct1016_form_label">Payment Method</label>
                        <input type="text" class="acct1016_form_input" id="payment_method" 
                               placeholder="e.g., Credit Card, Check, Bank Transfer">
                    </div>
                </form>
            </div>

            <!-- Success Message Area -->
            <div id="successMessage" style="display: none; margin-top: 1rem;" class="acct1015_card">
                <div style="background: #d1fae5; border: 2px solid #6ee7b7; border-radius: 0.75rem; padding: 1.5rem;">
                    <h4 style="color: #065f46; margin: 0 0 0.5rem 0; font-size: 1.125rem;">
                        <i class="bi bi-check-circle-fill"></i> Expense Logged Successfully!
                    </h4>
                    <p style="color: #047857; margin: 0 0 1rem 0;">
                        Your expense has been recorded and is pending accountant approval.
                    </p>
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="button" class="acct1015_btn_primary" onclick="resetForm()">
                            <i class="bi bi-plus-circle"></i> Add Another Expense
                        </button>
                        <a href="index.php" class="acct1015_btn_secondary">
                            <i class="bi bi-speedometer2"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Receipt & Actions -->
        <div>
            <div class="acct1015_card">
                <div class="acct1015_card_header">
                    <h3 class="acct1015_card_title">
                        <i class="bi bi-file-earmark-pdf"></i> Receipt Upload
                    </h3>
                </div>

                <div class="acct1016_form_group">
                    <label class="acct1016_form_label">Upload Receipt (PDF) *</label>
                    <div id="uploadArea" 
                         style="border: 2px dashed #d1d5db; border-radius: 0.75rem; padding: 3rem 2rem; text-align: center; background: #f9fafb; cursor: pointer; transition: all 0.2s;" 
                         onclick="document.getElementById('receipt_file').click()"
                         ondragover="event.preventDefault(); this.style.borderColor='#d97706'; this.style.background='#fffbeb';"
                         ondragleave="this.style.borderColor='#d1d5db'; this.style.background='#f9fafb';"
                         ondrop="handleDrop(event)">
                        <i class="bi bi-cloud-upload" style="font-size: 4rem; color: #9ca3af; display: block; margin-bottom: 1rem;"></i>
                        <div id="uploadText">
                            <strong style="color: #111827; font-size: 1rem;">Click to upload</strong> or drag and drop<br>
                            <small style="color: #6b7280;">PDF files only (Max 10MB)</small>
                        </div>
                        <input type="file" id="receipt_file" accept=".pdf" style="display: none;" onchange="handleFileSelect(event)">
                        <div id="selectedFile" style="margin-top: 1.5rem; display: none;">
                            <div style="background: white; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 1rem; display: inline-flex; align-items: center; gap: 0.75rem;">
                                <i class="bi bi-file-pdf-fill" style="font-size: 1.5rem; color: #dc2626;"></i>
                                <div style="text-align: left;">
                                    <div id="fileName" style="font-weight: 600; color: #111827;"></div>
                                    <div id="fileSize" style="font-size: 0.875rem; color: #6b7280;"></div>
                                </div>
                                <button type="button" onclick="clearFile(); event.stopPropagation();" 
                                        style="background: none; border: none; color: #dc2626; cursor: pointer; padding: 0.25rem;">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                    <button type="button" id="submitBtn" class="acct1015_btn_primary" 
                            onclick="submitExpense()" 
                            style="width: 100%; padding: 1rem; font-size: 1.0625rem;">
                        <i class="bi bi-check-circle"></i> Log Expense
                    </button>
                </div>
            </div>

            <!-- Help Card -->
            <div class="acct1015_card" style="margin-top: 1.5rem; background: #eff6ff;">
                <h4 style="font-size: 0.9375rem; font-weight: 600; margin: 0 0 1rem 0; color: #1e40af;">
                    <i class="bi bi-info-circle"></i> What Happens Next?
                </h4>
                <ul style="margin: 0; padding-left: 1.25rem; color: #1e3a8a; font-size: 0.875rem; line-height: 1.8;">
                    <li>Expense logged with "pending approval" status</li>
                    <li>Accountant reviews and approves/rejects</li>
                    <li>Journal entry automatically created when approved</li>
                    <li>Receipt stored securely for audits</li>
                    <li>Supplier automatically linked if matched</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script src="assets/supplier-management.js"></script>
<script>
let selectedFile = null;
let searchTimeout = null;

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validate PDF
    if (file.type !== 'application/pdf') {
        alert('Please select a PDF file');
        event.target.value = '';
        return;
    }
    
    // Validate size (10MB)
    if (file.size > 10 * 1024 * 1024) {
        alert('File size must be less than 10MB');
        event.target.value = '';
        return;
    }
    
    selectedFile = file;
    
    // Update UI
    document.getElementById('uploadText').style.display = 'none';
    document.getElementById('selectedFile').style.display = 'block';
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = formatFileSize(file.size);
    document.getElementById('uploadArea').style.borderColor = '#10b981';
    document.getElementById('uploadArea').style.background = '#d1fae5';
}

function handleDrop(event) {
    event.preventDefault();
    const file = event.dataTransfer.files[0];
    
    if (file && file.type === 'application/pdf') {
        document.getElementById('receipt_file').files = event.dataTransfer.files;
        handleFileSelect({ target: document.getElementById('receipt_file') });
    } else {
        alert('Please drop a PDF file');
    }
    
    event.target.style.borderColor = '#d1d5db';
    event.target.style.background = '#f9fafb';
}

function clearFile() {
    selectedFile = null;
    document.getElementById('receipt_file').value = '';
    document.getElementById('uploadText').style.display = 'block';
    document.getElementById('selectedFile').style.display = 'none';
    document.getElementById('uploadArea').style.borderColor = '#d1d5db';
    document.getElementById('uploadArea').style.background = '#f9fafb';
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function searchSuppliersForExpense(query) {
    const dropdown = document.getElementById('supplierAutocomplete');
    
    if (query.length < 2) {
        dropdown.style.display = 'none';
        return;
    }
    
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        try {
            dropdown.innerHTML = '<div class="acct1016_autocomplete_loading">Searching...</div>';
            dropdown.style.display = 'block';
            
            const response = await fetch(`/api/accounting/supplier-api.php?action=search_suppliers&q=${encodeURIComponent(query)}&limit=10`);
            const result = await response.json();
            
            if (result.success && result.results.length > 0) {
                dropdown.innerHTML = result.results.map(supplier => `
                    <div class="acct1016_autocomplete_item" onclick="selectSupplierForExpense(${supplier.id}, '${escapeHtml(supplier.supplier_name)}')">
                        <div>
                            <div class="acct1016_autocomplete_item_name">${supplier.supplier_name}</div>
                            <div class="acct1016_autocomplete_item_meta">${supplier.supplier_type}</div>
                        </div>
                    </div>
                `).join('') + `
                    <div class="acct1016_autocomplete_item acct1016_autocomplete_add_new" onclick="window.location.href='suppliers.php?prefill=' + encodeURIComponent('${escapeHtml(query)}')">
                        <i class="bi bi-plus-circle"></i> Add "${query}" as New Supplier
                    </div>
                `;
            } else {
                dropdown.innerHTML = `
                    <div class="acct1016_autocomplete_empty">No suppliers found</div>
                    <div class="acct1016_autocomplete_item acct1016_autocomplete_add_new" onclick="window.location.href='suppliers.php?prefill=' + encodeURIComponent('${escapeHtml(query)}')">
                        <i class="bi bi-plus-circle"></i> Add "${query}" as New Supplier
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error searching:', error);
        }
    }, 300);
}

function selectSupplierForExpense(supplierId, supplierName) {
    document.getElementById('vendor_name').value = supplierName;
    document.getElementById('supplier_id').value = supplierId;
    document.getElementById('supplierAutocomplete').style.display = 'none';
}

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text.replace(/[&<>"']/g, m => map[m]);
}

async function submitExpense() {
    // Validate
    const date = document.getElementById('expense_date').value;
    const amount = document.getElementById('amount').value;
    const vendor = document.getElementById('vendor_name').value;
    const description = document.getElementById('description').value;
    const categoryId = document.getElementById('expense_category_id').value;
    
    if (!date || !amount || !vendor || !description || !categoryId) {
        alert('Please fill in all required fields');
        return;
    }
    
    if (!selectedFile) {
        alert('Please upload a receipt PDF');
        return;
    }
    
    // Show loading
    const btn = document.getElementById('submitBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('expense_date', date);
        formData.append('amount', amount);
        formData.append('vendor_name', vendor);
        formData.append('description', description);
        formData.append('expense_category_id', categoryId);
        formData.append('receipt_file', selectedFile);
        formData.append('payment_status', document.getElementById('payment_status').value);
        formData.append('payment_date', document.getElementById('payment_date').value);
        formData.append('payment_method', document.getElementById('payment_method').value);
        
        const supplierId = document.getElementById('supplier_id').value;
        if (supplierId) {
            formData.append('supplier_id', supplierId);
        }
        
        const response = await fetch('/api/accounting/expense-api.php?action=quick_log_expense', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success message
            document.getElementById('successMessage').style.display = 'block';
            document.getElementById('expenseForm').style.opacity = '0.5';
            btn.style.display = 'none';
            
            // Scroll to success message
            document.getElementById('successMessage').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            alert('Error: ' + (result.error || 'Failed to log expense'));
        }
    } catch (error) {
        alert('An error occurred: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function resetForm() {
    document.getElementById('expenseForm').reset();
    document.getElementById('expense_date').value = '<?= date('Y-m-d') ?>';
    document.getElementById('supplier_id').value = '';
    clearFile();
    document.getElementById('successMessage').style.display = 'none';
    document.getElementById('expenseForm').style.opacity = '1';
    document.getElementById('submitBtn').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('supplierAutocomplete');
    const input = document.getElementById('vendor_name');
    
    if (event.target !== input && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>











