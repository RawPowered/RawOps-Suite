/**
 * Supplier Management JavaScript
 * Handles supplier CRUD, autocomplete, fuzzy matching, and verification workflows
 * Date: 2025-10-16
 */

// Global state
let supplierAutocompleteTimeout = null;
let currentSupplierData = null;
let loginTypes = [];

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadSupplierStats();
    loadPendingMatches();
    loadLoginTypes();
    loadExpenseCategories();
});

/**
 * Switch supplier modal tabs
 */
function switchSupplierTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.supplier-tab-content').forEach(tab => {
        tab.style.display = 'none';
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.acct1016_tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    const tabs = {
        'info': 'supplierTabInfo',
        'credentials': 'supplierTabCredentials',
        'expenses': 'supplierTabExpenses'
    };
    
    if (tabs[tabName]) {
        document.getElementById(tabs[tabName]).style.display = 'block';
        event.target.classList.add('active');
        
        // Load data if needed
        if (tabName === 'expenses' && currentSupplierData) {
            loadSupplierExpenses(currentSupplierData.id);
        }
    }
}

/**
 * Load expense categories for dropdown
 */
async function loadExpenseCategories() {
    try {
        const response = await fetch('/api/accounting/expense-api.php?action=get_categories');
        const result = await response.json();
        
        if (result.success) {
            const select = document.getElementById('default_expense_category_id');
            if (select) {
                const options = result.categories.map(cat => 
                    `<option value="${cat.id}">${cat.category_name}</option>`
                ).join('');
                select.innerHTML = '<option value="">-- None --</option>' + options;
            }
        }
    } catch (error) {
        console.error('Error loading categories:', error);
    }
}

/**
 * Load supplier statistics for dashboard widget
 */
async function loadSupplierStats() {
    try {
        const response = await fetch('/api/accounting/supplier-api.php?action=get_supplier_stats');
        const result = await response.json();
        
        if (result.success && result.stats) {
            const stats = result.stats;
            document.getElementById('totalSuppliersCount').textContent = stats.total_active_suppliers || 0;
            document.getElementById('pendingMatchesCount').textContent = stats.pending_matches || 0;
            document.getElementById('totalCredentialsCount').textContent = stats.total_credentials || 0;
            
            // Show notification if pending matches
            if (stats.pending_matches > 0) {
                showPendingMatchesBadge(stats.pending_matches);
            }
        }
    } catch (error) {
        console.error('Error loading supplier stats:', error);
    }
}

/**
 * Load pending supplier matches for verification
 */
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

/**
 * Display pending matches in UI
 */
function displayPendingMatches(matches) {
    const container = document.getElementById('pendingMatchesContainer');
    if (!container) return;
    
    if (matches.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #6b7280;">
                <i class="bi bi-check-circle" style="font-size: 3rem; color: #10b981;"></i>
                <p style="margin-top: 1rem; font-weight: 500;">All matches verified!</p>
            </div>
        `;
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
                <button class="acct1016_btn_reject" onclick="showRejectMatchModal(${match.link_id}, '${escapeHtml(match.original_vendor_name)}')">
                    <i class="bi bi-x"></i> Reject
                </button>
            </div>
        </div>
    `).join('');
}

/**
 * Get confidence level for badge styling
 */
function getConfidenceLevel(confidence) {
    if (confidence >= 85) return 'high';
    if (confidence >= 70) return 'medium';
    return 'low';
}

/**
 * Confirm supplier match
 */
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
            showNotification('Match confirmed successfully', 'success');
            loadPendingMatches();
            loadSupplierStats();
        } else {
            showNotification(result.error || 'Failed to confirm match', 'error');
        }
    } catch (error) {
        console.error('Error confirming match:', error);
        showNotification('An error occurred', 'error');
    }
}

/**
 * Show reject match modal
 */
function showRejectMatchModal(linkId, vendorName) {
    const modal = new bootstrap.Modal(document.getElementById('rejectMatchModal'));
    document.getElementById('rejectLinkId').value = linkId;
    document.getElementById('rejectVendorName').textContent = vendorName;
    
    // Load suppliers for manual selection
    loadSuppliersForSelection();
    
    modal.show();
}

/**
 * Load suppliers for manual selection dropdown
 */
async function loadSuppliersForSelection() {
    try {
        const response = await fetch('/api/accounting/supplier-api.php?action=get_suppliers&limit=200');
        const result = await response.json();
        
        if (result.success) {
            const select = document.getElementById('correctSupplierId');
            select.innerHTML = '<option value="">-- Select Correct Supplier --</option>' +
                result.suppliers.map(s => `
                    <option value="${s.id}">${s.supplier_name}</option>
                `).join('');
        }
    } catch (error) {
        console.error('Error loading suppliers:', error);
    }
}

/**
 * Reject and correct supplier match
 */
async function rejectMatch() {
    const linkId = document.getElementById('rejectLinkId').value;
    const correctSupplierId = document.getElementById('correctSupplierId').value;
    const reason = document.getElementById('rejectionReason').value;
    
    try {
        const response = await fetch('/api/accounting/supplier-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'reject_supplier_match',
                link_id: linkId,
                correct_supplier_id: correctSupplierId || null,
                rejection_reason: reason
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Match rejected and corrected', 'success');
            bootstrap.Modal.getInstance(document.getElementById('rejectMatchModal')).hide();
            loadPendingMatches();
            loadSupplierStats();
        } else {
            showNotification(result.error || 'Failed to reject match', 'error');
        }
    } catch (error) {
        console.error('Error rejecting match:', error);
        showNotification('An error occurred', 'error');
    }
}

/**
 * Search suppliers (type-ahead autocomplete)
 */
function searchSuppliers(query) {
    const input = document.getElementById('supplierSearchInput');
    const dropdown = document.getElementById('supplierAutocompleteDropdown');
    
    if (query.length < 2) {
        dropdown.style.display = 'none';
        return;
    }
    
    // Debounce
    clearTimeout(supplierAutocompleteTimeout);
    supplierAutocompleteTimeout = setTimeout(async () => {
        try {
            dropdown.innerHTML = '<div class="acct1016_autocomplete_loading">Searching...</div>';
            dropdown.style.display = 'block';
            
            const response = await fetch(`/api/accounting/supplier-api.php?action=search_suppliers&q=${encodeURIComponent(query)}&limit=10`);
            const result = await response.json();
            
            if (result.success && result.results.length > 0) {
                dropdown.innerHTML = result.results.map(supplier => `
                    <div class="acct1016_autocomplete_item" onclick="selectSupplier(${supplier.id}, '${escapeHtml(supplier.supplier_name)}')">
                        <div>
                            <div class="acct1016_autocomplete_item_name">${supplier.supplier_name}</div>
                            <div class="acct1016_autocomplete_item_meta">${supplier.supplier_code || 'No code'} • ${supplier.supplier_type}</div>
                        </div>
                    </div>
                `).join('') + `
                    <div class="acct1016_autocomplete_item acct1016_autocomplete_add_new" onclick="openSupplierModal(null, '${escapeHtml(query)}')">
                        <i class="bi bi-plus-circle"></i> Add "${query}" as New Supplier
                    </div>
                `;
            } else {
                dropdown.innerHTML = `
                    <div class="acct1016_autocomplete_empty">No suppliers found</div>
                    <div class="acct1016_autocomplete_item acct1016_autocomplete_add_new" onclick="openSupplierModal(null, '${escapeHtml(query)}')">
                        <i class="bi bi-plus-circle"></i> Add "${query}" as New Supplier
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error searching suppliers:', error);
            dropdown.innerHTML = '<div class="acct1016_autocomplete_empty">Error searching</div>';
        }
    }, 300);
}

/**
 * Select supplier from autocomplete
 */
function selectSupplier(supplierId, supplierName) {
    document.getElementById('supplierSearchInput').value = supplierName;
    document.getElementById('supplier_id').value = supplierId;
    document.getElementById('supplierAutocompleteDropdown').style.display = 'none';
}

/**
 * Open supplier management modal
 */
async function openSupplierModal(supplierId = null, prefillName = null) {
    const modal = new bootstrap.Modal(document.getElementById('supplierManagementModal'));
    const modalTitle = document.getElementById('supplierModalTitle');
    
    if (supplierId) {
        // Edit existing supplier
        modalTitle.textContent = 'Edit Supplier';
        await loadSupplierDetails(supplierId);
    } else {
        // Create new supplier
        modalTitle.textContent = 'New Supplier';
        resetSupplierForm();
        if (prefillName) {
            document.getElementById('supplier_name').value = prefillName;
        }
    }
    
    modal.show();
}

/**
 * Load supplier details for editing
 */
async function loadSupplierDetails(supplierId) {
    try {
        const response = await fetch(`/api/accounting/supplier-api.php?action=get_supplier&supplier_id=${supplierId}`);
        const result = await response.json();
        
        if (result.success) {
            currentSupplierData = result.supplier;
            populateSupplierForm(result.supplier);
        }
    } catch (error) {
        console.error('Error loading supplier:', error);
        showNotification('Failed to load supplier details', 'error');
    }
}

/**
 * Populate supplier form with data
 */
function populateSupplierForm(supplier) {
    document.getElementById('supplier_id').value = supplier.id;
    document.getElementById('supplier_name').value = supplier.supplier_name;
    document.getElementById('supplier_code').value = supplier.supplier_code || '';
    document.getElementById('contact_name').value = supplier.contact_name || '';
    document.getElementById('contact_email').value = supplier.contact_email || '';
    document.getElementById('contact_phone').value = supplier.contact_phone || '';
    document.getElementById('website_url').value = supplier.website_url || '';
    document.getElementById('supplier_type').value = supplier.supplier_type;
    document.getElementById('default_expense_category_id').value = supplier.default_expense_category_id || '';
    document.getElementById('is_1099_required').checked = supplier.is_1099_required == 1;
    document.getElementById('supplier_notes').value = supplier.notes || '';
    
    // Display credentials
    if (supplier.credentials && supplier.credentials.length > 0) {
        displayCredentials(supplier.credentials);
    }
}

/**
 * Reset supplier form
 */
function resetSupplierForm() {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplier_id').value = '';
    document.getElementById('credentialsListContainer').innerHTML = '';
    currentSupplierData = null;
}

/**
 * Save supplier
 */
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
            showNotification('Supplier saved successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('supplierManagementModal')).hide();
            loadSupplierStats();
            
            // If creating during expense entry, populate the ID
            if (result.supplier_id && document.getElementById('supplier_id')) {
                document.getElementById('supplier_id').value = result.supplier_id;
            }
        } else {
            showNotification(result.error || 'Failed to save supplier', 'error');
        }
    } catch (error) {
        console.error('Error saving supplier:', error);
        showNotification('An error occurred', 'error');
    }
}

/**
 * Display credentials list
 */
function displayCredentials(credentials) {
    const container = document.getElementById('credentialsListContainer');
    
    if (credentials.length === 0) {
        container.innerHTML = '<p style="color: #6b7280; text-align: center; padding: 2rem;">No credentials saved</p>';
        return;
    }
    
    container.innerHTML = credentials.map(cred => `
        <div class="acct1016_credential_row">
            <div class="acct1016_credential_header">
                <div class="acct1016_credential_name">
                    <i class="bi bi-key"></i> ${cred.credential_name}
                </div>
                <span class="acct1016_login_type_badge acct1016_login_type_${cred.login_type}">
                    <i class="${getLoginTypeIcon(cred.login_type)}"></i> ${formatLoginType(cred.login_type)}
                </span>
            </div>
            ${cred.login_url ? `
            <div class="acct1016_credential_field">
                <span class="acct1016_credential_label">URL:</span>
                <a href="${cred.login_url}" target="_blank" class="acct1016_credential_value" style="color: #3b82f6;">${cred.login_url}</a>
            </div>
            ` : ''}
            ${cred.username ? `
            <div class="acct1016_credential_field">
                <span class="acct1016_credential_label">Username:</span>
                <span class="acct1016_credential_value">${cred.username}</span>
            </div>
            ` : ''}
            ${cred.decrypted_password ? `
            <div class="acct1016_credential_field">
                <span class="acct1016_credential_label">Password:</span>
                <span class="acct1016_credential_value acct1016_password_masked" id="password_${cred.id}">••••••••</span>
                <button class="acct1016_password_toggle" onclick="togglePasswordVisibility(${cred.id}, '${escapeHtml(cred.decrypted_password)}')">
                    <i class="bi bi-eye"></i> Show
                </button>
            </div>
            ` : ''}
        </div>
    `).join('');
}

/**
 * Toggle password visibility
 */
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

/**
 * Load login types
 */
async function loadLoginTypes() {
    try {
        const response = await fetch('/api/accounting/supplier-api.php?action=get_login_types');
        const result = await response.json();
        
        if (result.success) {
            loginTypes = result.login_types;
        }
    } catch (error) {
        console.error('Error loading login types:', error);
    }
}

/**
 * Get login type icon
 */
function getLoginTypeIcon(loginType) {
    const icons = {
        'email': 'bi-envelope',
        'username': 'bi-person',
        'google_gmail': 'bi-google',
        'google_auth': 'bi-shield-lock',
        'microsoft': 'bi-microsoft',
        'custom': 'bi-gear'
    };
    return icons[loginType] || 'bi-key';
}

/**
 * Format login type for display
 */
function formatLoginType(loginType) {
    return loginType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

/**
 * Show notification
 */
function showNotification(message, type = 'info') {
    // Use existing flash message system if available, or alert
    if (typeof flash_set === 'function') {
        flash_set(type === 'error' ? 'danger' : type, message);
    } else {
        alert(message);
    }
}

/**
 * Show pending matches badge
 */
function showPendingMatchesBadge(count) {
    const badge = document.getElementById('pendingMatchesBadge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = 'inline-block';
    }
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}

/**
 * Load supplier expenses
 */
async function loadSupplierExpenses(supplierId) {
    try {
        const response = await fetch(`/api/accounting/supplier-api.php?action=get_supplier_expenses&supplier_id=${supplierId}`);
        const result = await response.json();
        
        if (result.success) {
            displaySupplierExpenses(result.expenses, result.total_amount);
        }
    } catch (error) {
        console.error('Error loading supplier expenses:', error);
    }
}

/**
 * Display supplier expenses
 */
function displaySupplierExpenses(expenses, totalAmount) {
    const container = document.getElementById('supplierExpensesContainer');
    if (!container) return;
    
    if (expenses.length === 0) {
        container.innerHTML = '<p style="color: #6b7280; text-align: center; padding: 2rem;">No expenses found</p>';
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
                    <th>Match</th>
                </tr>
            </thead>
            <tbody>
                ${expenses.map(exp => `
                    <tr>
                        <td>${exp.expense_date}</td>
                        <td>${exp.description}</td>
                        <td>${exp.category_name}</td>
                        <td>$${parseFloat(exp.amount).toFixed(2)}</td>
                        <td><span class="acct1016_match_badge acct1016_match_badge_${getConfidenceLevel(exp.match_confidence)}">${exp.match_type}</span></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

/**
 * Add new credential form (placeholder for future implementation)
 */
function addNewCredential() {
    showNotification('Credential management coming soon! For now, please add credentials when creating/editing the supplier.', 'info');
}

