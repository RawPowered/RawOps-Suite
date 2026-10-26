<?php
$SEO = [
    'title' => 'Supplier Management — Product Accounting',
    'description' => 'Manage suppliers and vendor credentials',
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

// Get supplier ID if editing
$supplierId = $_GET['id'] ?? null;
$isEdit = $supplierId ? true : false;
$supplier = null;
$credentials = [];

if ($isEdit) {
    // Load supplier details
    $stmt = $pdo->prepare("
        SELECT s.*,
               ec.category_name as default_category_name,
               (SELECT COUNT(*) FROM supplier_expenses_link WHERE supplier_id = s.id AND verification_status = 'confirmed') as expense_count
        FROM suppliers s
        LEFT JOIN expense_categories ec ON ec.id = s.default_expense_category_id
        WHERE s.id = ?
    ");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($supplier) {
        // Get credentials (decrypt passwords)
        $credStmt = $pdo->prepare("
            SELECT id, credential_name, login_url, login_type, username,
                   CAST(AES_DECRYPT(encrypted_password, ?) AS CHAR) as decrypted_password,
                   password_hint, two_factor_method, account_number, additional_info,
                   last_verified_date, is_active, created_at
            FROM supplier_credentials
            WHERE supplier_id = ?
            ORDER BY is_active DESC, credential_name ASC
        ");
        $encKey = getenv('SUPPLIER_CREDENTIAL_KEY') ?: 'Product2025SecureKey!';
        $credStmt->execute([$encKey, $supplierId]);
        $credentials = $credStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get expense categories for dropdown
$categoriesStmt = $pdo->query("SELECT id, category_name FROM expense_categories WHERE is_active = 1 ORDER BY category_name ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get login types
$loginTypesStmt = $pdo->query("SELECT * FROM supplier_login_types WHERE is_active = 1 ORDER BY sort_order ASC");
$loginTypes = $loginTypesStmt->fetchAll(PDO::FETCH_ASSOC);

// Load CSS inline
$cssFile = __DIR__ . '/assets/accounting-styles.css';
$cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
?>

<style>
<?= $cssContent ?>
</style>

<div class="acct1015_container">
    <?php if (function_exists('render_accounting_breadcrumbs')): ?>
        <?php render_accounting_breadcrumbs([['label' => 'Suppliers', 'url' => 'suppliers.php'], ['label' => $isEdit ? 'Edit Supplier' : 'New Supplier']]); ?>
        <?php render_accounting_secondary_nav('suppliers.php'); ?>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="acct1015_page_header">
        <div>
            <h1 class="acct1015_page_title">
                <i class="bi bi-building"></i>
                <?= $isEdit ? 'Edit Supplier' : 'New Supplier' ?>
            </h1>
            <p class="acct1015_page_subtitle">
                <?= $isEdit ? 'Update supplier information and credentials' : 'Add a new supplier or vendor to the system' ?>
            </p>
        </div>
        <div style="display: flex; gap: 1rem;">
            <a href="suppliers.php" class="acct1015_btn_secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <?php if ($isEdit): ?>
            <button type="button" class="acct1015_btn_danger" onclick="if(confirm('Are you sure you want to deactivate this supplier?')) deactivateSupplier()">
                <i class="bi bi-trash"></i> Deactivate
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isEdit && $supplier): ?>
    <!-- Supplier Stats -->
    <div class="acct1016_stats_widget" style="margin-bottom: 2rem;">
        <div class="acct1016_stat_mini">
            <div class="acct1016_stat_mini_value"><?= count($credentials) ?></div>
            <div class="acct1016_stat_mini_label">Login Credentials</div>
        </div>
        <div class="acct1016_stat_mini">
            <div class="acct1016_stat_mini_value"><?= $supplier['expense_count'] ?></div>
            <div class="acct1016_stat_mini_label">Linked Expenses</div>
        </div>
        <div class="acct1016_stat_mini">
            <div class="acct1016_stat_mini_value">
                <span class="acct1016_supplier_type_badge acct1016_supplier_type_<?= $supplier['supplier_type'] ?>">
                    <?= ucfirst($supplier['supplier_type']) ?>
                </span>
            </div>
            <div class="acct1016_stat_mini_label">Supplier Type</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Content Grid -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        
        <!-- Left Column: Supplier Information -->
        <div>
            <div class="acct1015_card">
                <div class="acct1015_card_header">
                    <h3 class="acct1015_card_title">
                        <i class="bi bi-info-circle"></i> Supplier Information
                    </h3>
                </div>

                <form id="supplierForm">
                    <input type="hidden" id="supplier_id" value="<?= $supplier['id'] ?? '' ?>">
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Supplier Name *</label>
                            <input type="text" class="acct1016_form_input" id="supplier_name" 
                                   value="<?= htmlspecialchars($supplier['supplier_name'] ?? '') ?>" required>
                        </div>
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Supplier Code</label>
                            <input type="text" class="acct1016_form_input" id="supplier_code" 
                                   value="<?= htmlspecialchars($supplier['supplier_code'] ?? '') ?>" 
                                   placeholder="Optional internal code">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Contact Name</label>
                            <input type="text" class="acct1016_form_input" id="contact_name" 
                                   value="<?= htmlspecialchars($supplier['contact_name'] ?? '') ?>">
                        </div>
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Contact Email</label>
                            <input type="email" class="acct1016_form_input" id="contact_email" 
                                   value="<?= htmlspecialchars($supplier['contact_email'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Contact Phone</label>
                            <input type="tel" class="acct1016_form_input" id="contact_phone" 
                                   value="<?= htmlspecialchars($supplier['contact_phone'] ?? '') ?>">
                        </div>
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Website URL</label>
                            <input type="url" class="acct1016_form_input" id="website_url" 
                                   value="<?= htmlspecialchars($supplier['website_url'] ?? '') ?>" 
                                   placeholder="https://">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Supplier Type</label>
                            <select class="acct1016_form_select" id="supplier_type">
                                <option value="product" <?= ($supplier['supplier_type'] ?? '') === 'product' ? 'selected' : '' ?>>Product</option>
                                <option value="service" <?= ($supplier['supplier_type'] ?? '') === 'service' ? 'selected' : '' ?>>Service</option>
                                <option value="utility" <?= ($supplier['supplier_type'] ?? '') === 'utility' ? 'selected' : '' ?>>Utility</option>
                                <option value="subscription" <?= ($supplier['supplier_type'] ?? '') === 'subscription' ? 'selected' : '' ?>>Subscription</option>
                                <option value="other" <?= ($supplier['supplier_type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="acct1016_form_group">
                            <label class="acct1016_form_label">Default Expense Category</label>
                            <select class="acct1016_form_select" id="default_expense_category_id">
                                <option value="">-- None --</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($supplier['default_expense_category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="acct1016_form_group" style="margin-bottom: 1.5rem;">
                        <label class="acct1016_form_label">Payment Terms</label>
                        <input type="text" class="acct1016_form_input" id="payment_terms" 
                               value="<?= htmlspecialchars($supplier['payment_terms'] ?? '') ?>" 
                               placeholder="e.g., Net 30, Due on Receipt">
                    </div>

                    <div class="acct1016_form_group" style="margin-bottom: 1.5rem;">
                        <label class="acct1016_form_label">Notes</label>
                        <textarea class="acct1016_form_input" id="supplier_notes" rows="4" 
                                  placeholder="Internal notes about this supplier"><?= htmlspecialchars($supplier['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="acct1016_form_group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" id="is_1099_required" 
                                   <?= ($supplier['is_1099_required'] ?? 0) ? 'checked' : '' ?> 
                                   style="width: auto;">
                            <span class="acct1016_form_label" style="margin: 0;">
                                Requires 1099 Filing (Contractor)
                            </span>
                        </label>
                    </div>

                    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb; display: flex; gap: 1rem;">
                        <button type="button" class="acct1015_btn_primary" onclick="saveSupplier()">
                            <i class="bi bi-check-circle"></i> 
                            <?= $isEdit ? 'Update Supplier' : 'Create Supplier' ?>
                        </button>
                        <a href="suppliers.php" class="acct1015_btn_secondary">Cancel</a>
                    </div>
                </form>
            </div>

            <?php if ($isEdit): ?>
            <!-- Linked Expenses Section -->
            <div class="acct1015_card" style="margin-top: 2rem;">
                <div class="acct1015_card_header">
                    <h3 class="acct1015_card_title">
                        <i class="bi bi-receipt"></i> Linked Expenses
                    </h3>
                    <a href="add-expense.php?supplier_id=<?= $supplierId ?>" class="acct1015_btn_secondary">
                        <i class="bi bi-plus"></i> Add Expense
                    </a>
                </div>
                <div id="linkedExpensesContainer">
                    <p style="text-align: center; color: #6b7280; padding: 2rem;">Loading expenses...</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Credentials -->
        <div>
            <div class="acct1015_card">
                <div class="acct1015_card_header">
                    <h3 class="acct1015_card_title">
                        <i class="bi bi-key"></i> Login Credentials
                    </h3>
                    <button type="button" class="acct1015_btn_secondary" onclick="addCredentialRow()">
                        <i class="bi bi-plus-circle"></i> Add
                    </button>
                </div>

                <div id="credentialsContainer">
                    <?php if (empty($credentials)): ?>
                    <p style="text-align: center; color: #6b7280; padding: 2rem;">
                        No credentials saved yet. Add login details to securely store supplier account access.
                    </p>
                    <?php else: ?>
                        <?php foreach ($credentials as $cred): ?>
                        <div class="acct1016_credential_row" data-credential-id="<?= $cred['id'] ?>">
                            <div class="acct1016_credential_header">
                                <div class="acct1016_credential_name">
                                    <i class="bi bi-key"></i> <?= htmlspecialchars($cred['credential_name']) ?>
                                </div>
                                <button type="button" class="acct1015_btn_danger" style="padding: 0.25rem 0.5rem; font-size: 0.875rem;" 
                                        onclick="deleteCredential(<?= $cred['id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <div style="margin-top: 0.75rem;">
                                <span class="acct1016_login_type_badge acct1016_login_type_<?= $cred['login_type'] ?>">
                                    <i class="bi-<?= getLoginTypeIcon($cred['login_type']) ?>"></i> 
                                    <?= formatLoginType($cred['login_type']) ?>
                                </span>
                            </div>
                            <?php if ($cred['login_url']): ?>
                            <div class="acct1016_credential_field">
                                <span class="acct1016_credential_label">URL:</span>
                                <a href="<?= htmlspecialchars($cred['login_url']) ?>" target="_blank" 
                                   class="acct1016_credential_value" style="color: #3b82f6; text-decoration: none;">
                                    <?= htmlspecialchars($cred['login_url']) ?>
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php if ($cred['username']): ?>
                            <div class="acct1016_credential_field">
                                <span class="acct1016_credential_label">Username:</span>
                                <span class="acct1016_credential_value"><?= htmlspecialchars($cred['username']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($cred['decrypted_password']): ?>
                            <div class="acct1016_credential_field">
                                <span class="acct1016_credential_label">Password:</span>
                                <span class="acct1016_credential_value acct1016_password_masked" id="password_<?= $cred['id'] ?>">••••••••</span>
                                <button class="acct1016_password_toggle" onclick="togglePasswordVisibility(<?= $cred['id'] ?>, '<?= htmlspecialchars($cred['decrypted_password'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-eye"></i> Show
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isEdit): ?>
            <!-- Quick Info Card -->
            <div class="acct1015_card" style="margin-top: 2rem; background: #f9fafb;">
                <h4 style="font-size: 0.9375rem; font-weight: 600; margin: 0 0 1rem 0; color: #111827;">
                    <i class="bi bi-shield-check"></i> Security Info
                </h4>
                <ul style="margin: 0; padding-left: 1.25rem; color: #6b7280; font-size: 0.875rem; line-height: 1.6;">
                    <li>Passwords are AES-256 encrypted</li>
                    <li>Access is logged for auditing</li>
                    <li>Only accountants/admins can view</li>
                    <li>Shown passwords hidden by default</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/supplier-management.js"></script>
<script>
// Page-specific overrides
async function saveSupplier() {
    const supplierId = document.getElementById('supplier_id').value;
    const action = supplierId ? 'update_supplier' : 'create_supplier';
    
    const data = {
        action: action,
        supplier_id: supplierId || undefined,
        supplier_name: document.getElementById('supplier_name').value,
        supplier_code: document.getElementById('supplier_code').value,
        contact_name: document.getElementById('contact_name').value,
        contact_email: document.getElementById('contact_email').value,
        contact_phone: document.getElementById('contact_phone').value,
        website_url: document.getElementById('website_url').value,
        supplier_type: document.getElementById('supplier_type').value,
        payment_terms: document.getElementById('payment_terms').value,
        default_expense_category_id: document.getElementById('default_expense_category_id').value || null,
        is_1099_required: document.getElementById('is_1099_required').checked ? 1 : 0,
        notes: document.getElementById('supplier_notes').value
    };
    
    try {
        const response = await fetch('/api/accounting/supplier-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Supplier saved successfully!');
            if (!supplierId && result.supplier_id) {
                // Redirect to edit page for new supplier
                window.location.href = 'suppliers.php?id=' + result.supplier_id;
            } else {
                window.location.reload();
            }
        } else {
            alert('Error: ' + (result.error || 'Failed to save supplier'));
        }
    } catch (error) {
        alert('An error occurred: ' + error.message);
    }
}

async function deactivateSupplier() {
    const supplierId = document.getElementById('supplier_id').value;
    
    try {
        const response = await fetch('/api/accounting/supplier-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete_supplier',
                supplier_id: supplierId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Supplier deactivated successfully');
            window.location.href = 'suppliers.php';
        } else {
            alert('Error: ' + (result.error || 'Failed to deactivate'));
        }
    } catch (error) {
        alert('An error occurred: ' + error.message);
    }
}

function addCredentialRow() {
    alert('Credential management coming in next update. For now, please use the API or contact support.');
}

async function deleteCredential(credentialId) {
    if (!confirm('Are you sure you want to delete this credential?')) return;
    
    try {
        const response = await fetch('/api/accounting/supplier-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete_credential',
                credential_id: credentialId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            document.querySelector(`[data-credential-id="${credentialId}"]`).remove();
            alert('Credential deleted');
        } else {
            alert('Error: ' + (result.error || 'Failed to delete'));
        }
    } catch (error) {
        alert('An error occurred: ' + error.message);
    }
}

function togglePasswordVisibility(credId, password) {
    const element = document.getElementById(`password_${credId}`);
    const button = element.nextElementSibling;
    
    if (element.classList.contains('acct1016_password_masked')) {
        element.classList.remove('acct1016_password_masked');
        element.textContent = password;
        button.innerHTML = '<i class="bi bi-eye-slash"></i> Hide';
    } else {
        element.classList.add('acct1016_password_masked');
        element.textContent = '••••••••';
        button.innerHTML = '<i class="bi bi-eye"></i> Show';
    }
}

// Load linked expenses if editing
<?php if ($isEdit): ?>
document.addEventListener('DOMContentLoaded', function() {
    loadSupplierExpenses(<?= $supplierId ?>);
});

async function loadSupplierExpenses(supplierId) {
    try {
        const response = await fetch(`/api/accounting/supplier-api.php?action=get_supplier_expenses&supplier_id=${supplierId}`);
        const result = await response.json();
        
        if (result.success) {
            displayLinkedExpenses(result.expenses, result.total_amount);
        }
    } catch (error) {
        console.error('Error loading expenses:', error);
    }
}

function displayLinkedExpenses(expenses, totalAmount) {
    const container = document.getElementById('linkedExpensesContainer');
    
    if (expenses.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #6b7280; padding: 2rem;">No expenses linked yet</p>';
        return;
    }
    
    container.innerHTML = `
        <div style="margin-bottom: 1rem; padding: 1rem; background: #f0fdf4; border-radius: 0.5rem;">
            <strong>Total: $${parseFloat(totalAmount).toFixed(2)}</strong>
        </div>
        <table class="acct1016_supplier_table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                ${expenses.map(exp => `
                    <tr>
                        <td>${exp.expense_date}</td>
                        <td>${exp.description}</td>
                        <td>${exp.category_name}</td>
                        <td>$${parseFloat(exp.amount).toFixed(2)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}
<?php endif; ?>
</script>

<?php
// Helper functions
function getLoginTypeIcon($loginType) {
    $icons = [
        'email' => 'envelope',
        'username' => 'person',
        'google_gmail' => 'google',
        'google_auth' => 'shield-lock',
        'microsoft' => 'microsoft',
        'custom' => 'gear'
    ];
    return $icons[$loginType] ?? 'key';
}

function formatLoginType($loginType) {
    return str_replace('_', ' ', ucwords($loginType));
}
?>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>











