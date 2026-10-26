/**
 * Sales ACP Feedback Modal
 * Handles feedback form display, validation, and submission
 */

(function() {
    'use strict';
    
    // Configuration
    const FEEDBACK_API = (window.__BASE_PREFIX || '') + '/api/sales-agent-feedback.php';
    
    // Category definitions
    const FEEDBACK_CATEGORIES = [
        { value: 'Bug Report', icon: '🐛', description: 'Something isn\'t working correctly' },
        { value: 'Feature Request', icon: '💡', description: 'Suggest a new feature or enhancement' },
        { value: 'UX Improvement', icon: '✨', description: 'Better user experience or design' },
        { value: 'Performance Issue', icon: '⚡', description: 'Slow loading or responsiveness' },
        { value: 'General Feedback', icon: '💬', description: 'General thoughts or comments' },
        { value: 'Documentation Request', icon: '📚', description: 'Need help or documentation' }
    ];
    
    /**
     * Create feedback modal HTML
     */
    function createModalHTML() {
        const categoriesHTML = FEEDBACK_CATEGORIES.map(cat => 
            `<option value="${cat.value}">${cat.icon} ${cat.value}</option>`
        ).join('');
        
        return `
            <div id="sacpFeedbackModal" class="sacp_feedback-overlay" style="display: none;">
                <div class="sacp_feedback-modal">
                    <div class="sacp_feedback-header">
                        <h3>💬 Send Feedback</h3>
                        <button type="button" class="sacp_feedback-close" onclick="window.SACPFeedbackModal.close()" aria-label="Close">
                            ✕
                        </button>
                    </div>
                    
                    <div class="sacp_feedback-intro">
                        <p>Help us improve the Sales ACP! Your feedback goes directly to our development team.</p>
                    </div>
                    
                    <form id="sacpFeedbackForm" class="sacp_feedback-form" enctype="multipart/form-data">
                        <div class="sacp_feedback-field">
                            <label for="feedbackCategory">Category *</label>
                            <select id="feedbackCategory" name="category" required>
                                <option value="">Select a category...</option>
                                ${categoriesHTML}
                            </select>
                            <div id="categoryDescription" class="sacp_feedback-hint"></div>
                        </div>
                        
                        <div class="sacp_feedback-field">
                            <label for="feedbackTitle">Title *</label>
                            <input 
                                type="text" 
                                id="feedbackTitle" 
                                name="title" 
                                maxlength="255" 
                                placeholder="Brief summary of your feedback..."
                                required
                            >
                            <div class="sacp_feedback-hint"><span id="titleCounter">0</span>/255 characters</div>
                        </div>
                        
                        <div class="sacp_feedback-field">
                            <label for="feedbackDescription">Description *</label>
                            <textarea 
                                id="feedbackDescription" 
                                name="description" 
                                rows="6" 
                                placeholder="Please provide as much detail as possible. What happened? What did you expect? Steps to reproduce (for bugs)?"
                                required
                            ></textarea>
                            <div class="sacp_feedback-hint">Be specific to help us understand and address your feedback</div>
                        </div>
                        
                        <div class="sacp_feedback-field">
                            <label for="feedbackScreenshot">Screenshot (Optional)</label>
                            <input 
                                type="file" 
                                id="feedbackScreenshot" 
                                name="screenshot" 
                                accept="image/png,image/jpeg,image/jpg,image/gif,image/webp"
                                style="padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; width: 100%;"
                            >
                            <div class="sacp_feedback-hint">
                                📸 Upload a screenshot to help us understand the issue better (Max 5MB: PNG, JPG, GIF, or WebP)
                            </div>
                            <div id="screenshotPreview" style="margin-top: 8px; display: none;">
                                <img id="screenshotPreviewImg" style="max-width: 100%; max-height: 200px; border-radius: 6px; border: 1px solid #e5e7eb;" />
                            </div>
                        </div>
                        
                        <div class="sacp_feedback-context">
                            <strong>📍 Automatically included:</strong>
                            <ul>
                                <li id="contextPage">Current page: <span class="sacp_feedback-context-value">...</span></li>
                                <li id="contextBrowser">Browser: <span class="sacp_feedback-context-value">...</span></li>
                                <li id="contextDevice">Device: <span class="sacp_feedback-context-value">...</span></li>
                            </ul>
                        </div>
                        
                        <div id="feedbackMessage" class="sacp_feedback-message" style="display: none;"></div>
                        
                        <div class="sacp_feedback-actions">
                            <button type="button" class="sacp_feedback-btn sacp_feedback-btn-secondary" onclick="window.SACPFeedbackModal.close()">
                                Cancel
                            </button>
                            <button type="submit" class="sacp_feedback-btn sacp_feedback-btn-primary" id="feedbackSubmitBtn">
                                <span class="sacp_feedback-btn-text">Submit Feedback</span>
                                <span class="sacp_feedback-btn-loading" style="display: none;">
                                    <span class="sacp_feedback-spinner"></span> Submitting...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
    }
    
    /**
     * Initialize modal
     */
    function initializeModal() {
        // Check if modal already exists
        if (document.getElementById('sacpFeedbackModal')) {
            return;
        }
        
        // Create and inject modal HTML
        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = createModalHTML();
        document.body.appendChild(modalContainer.firstElementChild);
        
        // Attach event listeners
        attachEventListeners();
    }
    
    /**
     * Attach event listeners
     */
    function attachEventListeners() {
        const form = document.getElementById('sacpFeedbackForm');
        const categorySelect = document.getElementById('feedbackCategory');
        const titleInput = document.getElementById('feedbackTitle');
        
        // Form submission
        if (form) {
            form.addEventListener('submit', handleSubmit);
        }
        
        // Category change (show description)
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                const selected = FEEDBACK_CATEGORIES.find(c => c.value === this.value);
                const descElement = document.getElementById('categoryDescription');
                if (descElement && selected) {
                    descElement.textContent = selected.description;
                    descElement.style.display = 'block';
                } else if (descElement) {
                    descElement.style.display = 'none';
                }
            });
        }
        
        // Title character counter
        if (titleInput) {
            titleInput.addEventListener('input', function() {
                const counter = document.getElementById('titleCounter');
                if (counter) {
                    counter.textContent = this.value.length;
                }
            });
        }
        
        // Close on overlay click
        const overlay = document.getElementById('sacpFeedbackModal');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeModal();
                }
            });
        }
        
        // Screenshot preview
        const screenshotInput = document.getElementById('feedbackScreenshot');
        if (screenshotInput) {
            screenshotInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                const preview = document.getElementById('screenshotPreview');
                const previewImg = document.getElementById('screenshotPreviewImg');
                
                if (!file) {
                    if (preview) preview.style.display = 'none';
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    showMessage('Screenshot must be less than 5MB', 'error');
                    e.target.value = '';
                    if (preview) preview.style.display = 'none';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (previewImg) previewImg.src = e.target.result;
                    if (preview) preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            });
        }
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('sacpFeedbackModal');
                if (modal && modal.style.display !== 'none') {
                    closeModal();
                }
            }
        });
    }
    
    /**
     * Open modal
     */
    function openModal() {
        // Ensure modal exists
        initializeModal();
        
        const modal = document.getElementById('sacpFeedbackModal');
        if (!modal) return;
        
        // Populate context information
        if (window.SACPFeedbackTracker) {
            const pageUrl = window.SACPFeedbackTracker.getCurrentPageUrl();
            const browserInfo = window.SACPFeedbackTracker.getBrowserInfo();
            const deviceInfo = window.SACPFeedbackTracker.getDeviceInfo();
            
            const pageElement = document.querySelector('#contextPage .sacp_feedback-context-value');
            const browserElement = document.querySelector('#contextBrowser .sacp_feedback-context-value');
            const deviceElement = document.querySelector('#contextDevice .sacp_feedback-context-value');
            
            if (pageElement) pageElement.textContent = pageUrl;
            if (browserElement) browserElement.textContent = browserInfo;
            if (deviceElement) deviceElement.textContent = deviceInfo;
        }
        
        // Show modal
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Focus first input
        setTimeout(() => {
            const categorySelect = document.getElementById('feedbackCategory');
            if (categorySelect) categorySelect.focus();
        }, 100);
        
        // Remove pulse from feedback button
        if (window.SACPFeedbackTracker) {
            window.SACPFeedbackTracker.removePulse();
        }
    }
    
    /**
     * Close modal
     */
    function closeModal() {
        const modal = document.getElementById('sacpFeedbackModal');
        if (!modal) return;
        
        modal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Reset form
        const form = document.getElementById('sacpFeedbackForm');
        if (form) {
            form.reset();
            hideMessage();
            
            // Reset character counter
            const counter = document.getElementById('titleCounter');
            if (counter) counter.textContent = '0';
            
            // Hide category description
            const catDesc = document.getElementById('categoryDescription');
            if (catDesc) catDesc.style.display = 'none';
        }
    }
    
    /**
     * Show message
     */
    function showMessage(message, type = 'info') {
        const messageEl = document.getElementById('feedbackMessage');
        if (!messageEl) return;
        
        messageEl.textContent = message;
        messageEl.className = 'sacp_feedback-message sacp_feedback-message-' + type;
        messageEl.style.display = 'block';
    }
    
    /**
     * Hide message
     */
    function hideMessage() {
        const messageEl = document.getElementById('feedbackMessage');
        if (messageEl) {
            messageEl.style.display = 'none';
        }
    }
    
    /**
     * Set loading state
     */
    function setLoading(isLoading) {
        const submitBtn = document.getElementById('feedbackSubmitBtn');
        if (!submitBtn) return;
        
        const btnText = submitBtn.querySelector('.sacp_feedback-btn-text');
        const btnLoading = submitBtn.querySelector('.sacp_feedback-btn-loading');
        
        if (isLoading) {
            submitBtn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (btnLoading) btnLoading.style.display = 'inline-flex';
        } else {
            submitBtn.disabled = false;
            if (btnText) btnText.style.display = 'inline';
            if (btnLoading) btnLoading.style.display = 'none';
        }
    }
    
    /**
     * Handle form submission
     */
    async function handleSubmit(e) {
        e.preventDefault();
        
        hideMessage();
        setLoading(true);
        
        const form = e.target;
        const formData = new FormData(form);
        
        // Add context information
        if (window.SACPFeedbackTracker) {
            formData.append('page_url', window.SACPFeedbackTracker.getCurrentPageUrl());
            formData.append('browser_info', window.SACPFeedbackTracker.getBrowserInfo());
            formData.append('device_info', window.SACPFeedbackTracker.getDeviceInfo());
        }
        
        // Convert to JSON
        const data = {};
        formData.forEach((value, key) => {
            data[key] = value;
        });
        
        try {
            const response = await fetch(FEEDBACK_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                credentials: 'same-origin'
            });
            
            const result = await response.json();
            
            if (result.success) {
                showMessage('✅ ' + result.message, 'success');
                
                // Close modal after 2 seconds
                setTimeout(() => {
                    closeModal();
                }, 2000);
                
            } else {
                showMessage('❌ ' + (result.error || 'Failed to submit feedback'), 'error');
                setLoading(false);
            }
            
        } catch (error) {
            console.error('Feedback submission error:', error);
            showMessage('❌ Network error. Please check your connection and try again.', 'error');
            setLoading(false);
        }
    }
    
    /**
     * Export to global scope
     */
    window.SACPFeedbackModal = {
        open: openModal,
        close: closeModal,
        initialize: initializeModal
    };
    
    // Initialize modal when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeModal);
    } else {
        initializeModal();
    }
    
})();


