/**
 * Paladin Theme - Enhanced Interactions & Collapsible Sections
 * For Sales ACP CRM System
 */

(function() {
    'use strict';
    
    /**
     * Initialize collapsible sections
     */
    function initCollapsibleSections() {
        // Add collapsible functionality to all elements with data-collapsible
        document.querySelectorAll('[data-collapsible]').forEach(section => {
            const header = section.querySelector('[data-collapsible-header]');
            const content = section.querySelector('[data-collapsible-content]');
            const toggle = section.querySelector('[data-collapsible-toggle]');
            
            if (!header || !content) return;
            
            // Get initial state from localStorage
            const sectionId = section.getAttribute('data-collapsible');
            const isCollapsed = localStorage.getItem(`sacp_collapsed_${sectionId}`) === 'true';
            
            // Set initial state
            if (isCollapsed) {
                content.style.display = 'none';
                section.classList.add('collapsed');
                if (toggle) toggle.textContent = '▼';
            } else {
                content.style.display = 'block';
                section.classList.remove('collapsed');
                if (toggle) toggle.textContent = '▲';
            }
            
            // Add click handler
            header.style.cursor = 'pointer';
            header.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
                
                const isCurrentlyCollapsed = content.style.display === 'none';
                
                if (isCurrentlyCollapsed) {
                    // Expand with animation
                    content.style.display = 'block';
                    content.style.maxHeight = '0';
                    content.style.overflow = 'hidden';
                    content.style.transition = 'max-height 300ms ease';
                    
                    requestAnimationFrame(() => {
                        content.style.maxHeight = content.scrollHeight + 'px';
                        section.classList.remove('collapsed');
                        if (toggle) toggle.textContent = '▲';
                    });
                    
                    setTimeout(() => {
                        content.style.maxHeight = '';
                        content.style.overflow = '';
                        content.style.transition = '';
                    }, 300);
                    
                    localStorage.setItem(`sacp_collapsed_${sectionId}`, 'false');
                } else {
                    // Collapse with animation
                    content.style.maxHeight = content.scrollHeight + 'px';
                    content.style.overflow = 'hidden';
                    content.style.transition = 'max-height 300ms ease';
                    
                    requestAnimationFrame(() => {
                        content.style.maxHeight = '0';
                        section.classList.add('collapsed');
                        if (toggle) toggle.textContent = '▼';
                    });
                    
                    setTimeout(() => {
                        content.style.display = 'none';
                        content.style.maxHeight = '';
                        content.style.overflow = '';
                        content.style.transition = '';
                    }, 300);
                    
                    localStorage.setItem(`sacp_collapsed_${sectionId}`, 'true');
                }
            });
            
            // Add hover effect
            header.addEventListener('mouseenter', function() {
                this.style.background = 'linear-gradient(90deg, transparent, rgba(255, 215, 0, 0.05), transparent)';
            });
            
            header.addEventListener('mouseleave', function() {
                this.style.background = '';
            });
        });
    }
    
    /**
     * Initialize rapid action buttons
     */
    function initRapidActions() {
        document.querySelectorAll('[data-rapid-action]').forEach(button => {
            const action = button.getAttribute('data-rapid-action');
            const leadId = button.getAttribute('data-lead-id');
            const accountId = button.getAttribute('data-account-id');
            
            button.addEventListener('click', async function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Add loading state
                button.classList.add('loading');
                button.disabled = true;
                
                try {
                    switch(action) {
                        case 'quick-call':
                            showQuickCallModal(leadId, accountId);
                            break;
                        case 'quick-note':
                            showQuickNoteModal(leadId, accountId);
                            break;
                        case 'quick-task':
                            showQuickTaskModal(leadId, accountId);
                            break;
                        case 'quick-email':
                            const email = button.getAttribute('data-email');
                            if (email) window.location.href = `mailto:${email}`;
                            break;
                        case 'quick-phone':
                            const phone = button.getAttribute('data-phone');
                            if (phone) window.location.href = `tel:${phone}`;
                            break;
                    }
                } finally {
                    button.classList.remove('loading');
                    button.disabled = false;
                }
            });
        });
    }
    
    /**
     * Initialize hot lead pulsing
     */
    function initHotLeadPulsing() {
        // Find all hot leads that need attention
        document.querySelectorAll('.sacp_lead-card.hot-lead, .needs-follow-up, [data-hot-lead]').forEach(element => {
            element.style.animation = 'pulse-hot-lead 2s ease-in-out infinite';
        });
        
        // Find urgent follow-ups
        document.querySelectorAll('.urgent-followup, [data-urgent-followup]').forEach(element => {
            element.style.animation = 'pulse-urgent 3s ease-in-out infinite';
        });
        
        // Add CSS animations if not already present
        if (!document.querySelector('#sacp-animations')) {
            const style = document.createElement('style');
            style.id = 'sacp-animations';
            style.textContent = `
                @keyframes pulse-hot-lead {
                    0%, 100% {
                        box-shadow: 0 0 0 0 rgba(255, 215, 0, 0.7);
                        border-color: var(--acp-gold);
                    }
                    50% {
                        box-shadow: 0 0 0 10px rgba(255, 215, 0, 0);
                        border-color: var(--acp-gold-light);
                    }
                }
                
                @keyframes pulse-urgent {
                    0%, 100% {
                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
                        transform: scale(1);
                    }
                    50% {
                        box-shadow: 0 0 0 15px rgba(239, 68, 68, 0);
                        transform: scale(1.02);
                    }
                }
                
                @keyframes pulse-success {
                    0%, 100% {
                        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
                    }
                    50% {
                        box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
                    }
                }
                
                .sacp_collapsed-indicator {
                    display: inline-block;
                    margin-left: 8px;
                    transition: transform 250ms ease;
                    color: var(--acp-graphite);
                }
                
                .collapsed .sacp_collapsed-indicator {
                    transform: rotate(-90deg);
                }
                
                [data-collapsible-header] {
                    user-select: none;
                    transition: all 250ms ease;
                    position: relative;
                    padding-right: 30px;
                }
                
                [data-collapsible-toggle] {
                    position: absolute;
                    right: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: var(--acp-graphite);
                    transition: transform 250ms ease;
                }
                
                .collapsed [data-collapsible-toggle] {
                    transform: translateY(-50%) rotate(-90deg);
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    /**
     * Quick Call Modal
     */
    function showQuickCallModal(leadId, accountId) {
        const modal = createModal('Log Quick Call', `
            <form id="quickCallForm" style="display: grid; gap: 16px;">
                <input type="hidden" name="lead_id" value="${leadId || ''}">
                <input type="hidden" name="account_id" value="${accountId || ''}">
                
                <div>
                    <label class="sacp_form-label">Call Outcome</label>
                    <select name="call_outcome" class="sacp_form-select" required>
                        <option value="">Select outcome...</option>
                        <option value="answered">Answered - Spoke with contact</option>
                        <option value="voicemail">Left voicemail</option>
                        <option value="no_answer">No answer</option>
                        <option value="meeting_scheduled">Meeting scheduled</option>
                        <option value="sale_made">Sale made!</option>
                        <option value="not_interested">Not interested</option>
                    </select>
                </div>
                
                <div>
                    <label class="sacp_form-label">Duration (minutes)</label>
                    <input type="number" name="duration" class="sacp_form-input" min="0" value="5">
                </div>
                
                <div>
                    <label class="sacp_form-label">Summary</label>
                    <textarea name="summary" class="sacp_form-textarea" rows="3" placeholder="What was discussed..."></textarea>
                </div>
                
                <div>
                    <label class="sacp_form-label">
                        <input type="checkbox" name="follow_up_required" value="1" onchange="toggleFollowUpDate(this)">
                        Follow-up required?
                    </label>
                    <input type="date" name="follow_up_date" class="sacp_form-input" style="display:none; margin-top:8px;">
                </div>
                
                <div class="sacp_form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal(this)">Cancel</button>
                    <button type="submit" class="btn danger">Log Call</button>
                </div>
            </form>
        `);
        
        document.getElementById('quickCallForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            // Handle form submission via AJAX
            const formData = new FormData(this);
            formData.append('action', 'add_call');
            
            try {
                const response = await fetch(((window.__BASE_PREFIX||'') + '/onboarding/sales-agent/calls.php'), {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    showSuccessToast('Call logged successfully!');
                    closeModal(modal.querySelector('.sacp_modal'));
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showErrorToast('Failed to log call. Please try again.');
            }
        });
    }
    
    /**
     * Quick Note Modal
     */
    function showQuickNoteModal(leadId, accountId) {
        const modal = createModal('Add Quick Note', `
            <form id="quickNoteForm" style="display: grid; gap: 16px;">
                <input type="hidden" name="lead_id" value="${leadId || ''}">
                <input type="hidden" name="account_id" value="${accountId || ''}">
                
                <div>
                    <label class="sacp_form-label">Note Content</label>
                    <textarea name="content" class="sacp_form-textarea" rows="5" required placeholder="Enter your note..."></textarea>
                </div>
                
                <div>
                    <label class="sacp_form-label">Note Type</label>
                    <select name="note_type" class="sacp_form-select">
                        <option value="general">General Note</option>
                        <option value="meeting">Meeting Notes</option>
                        <option value="follow_up">Follow-up Required</option>
                        <option value="important">Important</option>
                    </select>
                </div>
                
                <div class="sacp_form-actions">
                    <button type="button" class="btn secondary" onclick="closeModal(this)">Cancel</button>
                    <button type="submit" class="btn success">Save Note</button>
                </div>
            </form>
        `);
        
        document.getElementById('quickNoteForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            try {
                const formData = new FormData(this);
                formData.append('action', 'add_note');
                const res = await fetch(((window.__BASE_PREFIX||'') + '/onboarding/sales-agent/notes.php'), { method: 'POST', body: formData });
                let data = null; try { data = await res.json(); } catch (e2) {}
                if (data && data.ok) {
                    showSuccessToast('Note saved successfully!');
                    closeModal(modal.querySelector('.sacp_modal'));
                    setTimeout(() => location.reload(), 600);
                } else {
                    showErrorToast((data && data.message) || 'Failed to save note.');
                }
            } catch (err) {
                showErrorToast('Failed to save note.');
            }
        });
    }
    
    /**
     * Create modal helper
     */
    function createModal(title, content) {
        const overlay = document.createElement('div');
        overlay.className = 'sacp_modal-overlay';
        overlay.innerHTML = `
            <div class="sacp_modal">
                <div class="sacp_modal-header">
                    <h3 class="sacp_modal-title">${title}</h3>
                    <button class="sacp_modal-close" onclick="closeModal(this)">×</button>
                </div>
                <div class="sacp_modal-body">
                    ${content}
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        overlay.style.display = 'flex';
        
        // Close on overlay click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeModal(overlay.querySelector('.sacp_modal'));
            }
        });
        
        return overlay;
    }
    
    /**
     * Close modal
     */
    window.closeModal = function(element) {
        const overlay = element.closest('.sacp_modal-overlay');
        if (overlay) {
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 200);
        }
    };
    
    /**
     * Toggle follow-up date field
     */
    window.toggleFollowUpDate = function(checkbox) {
        const dateField = checkbox.closest('form').querySelector('input[name="follow_up_date"]');
        if (dateField) {
            dateField.style.display = checkbox.checked ? 'block' : 'none';
            if (checkbox.checked) {
                // Set default to 3 days from now
                const date = new Date();
                date.setDate(date.getDate() + 3);
                dateField.value = date.toISOString().split('T')[0];
            }
        }
    };
    
    /**
     * Show success toast
     */
    function showSuccessToast(message) {
        showToast(message, 'success');
    }
    
    /**
     * Show error toast
     */
    function showErrorToast(message) {
        showToast(message, 'error');
    }
    
    /**
     * Show toast notification
     */
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `sacp_toast sacp_toast-${type}`;
        toast.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 20px;">
                    ${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}
                </span>
                <span>${message}</span>
            </div>
        `;
        
        // Add toast styles if not present
        if (!document.querySelector('#sacp-toast-styles')) {
            const style = document.createElement('style');
            style.id = 'sacp-toast-styles';
            style.textContent = `
                .sacp_toast {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    padding: 16px 24px;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
                    z-index: 10000;
                    animation: slideInUp 300ms ease;
                    border-left: 4px solid;
                    max-width: 400px;
                }
                
                .sacp_toast-success {
                    border-left-color: var(--acp-success);
                }
                
                .sacp_toast-error {
                    border-left-color: var(--acp-danger);
                }
                
                .sacp_toast-info {
                    border-left-color: var(--acp-info);
                }
                
                @keyframes slideInUp {
                    from {
                        transform: translateY(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateY(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOutDown 300ms ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    /**
     * Initialize everything on DOM ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        initCollapsibleSections();
        initRapidActions();
        initHotLeadPulsing();
    });
    
    /**
     * Export utilities
     */
    window.SACPInteractions = {
        showQuickCallModal,
        showQuickNoteModal,
        showSuccessToast,
        showErrorToast,
        initCollapsibleSections,
        initRapidActions,
        initHotLeadPulsing
    };
    
})();
