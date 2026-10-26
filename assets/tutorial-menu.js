/**
 * Tutorial Menu
 * UI component for viewing and restarting available tutorials
 * 
 * Features:
 * - Compact, responsive button (icon-only on mobile)
 * - Database-backed preferences
 * - List all available tutorials with status
 * - Restart completed/skipped tutorials
 * - Enable/disable walkthroughs globally
 * 
 * @author Product Development Team
 * @date 2025-10-20
 * @updated 2025-10-22 - Improved button styling, database preferences
 */

(function() {
    'use strict';
    
    const TutorialMenu = {
        modalOpen: false,
        walkthroughs: [],
        
        init: function() {
            // Create tutorial button in header
            this.createButton();
            
            // Create modal
            this.createModal();
            
            // Setup event listeners
            this.setupListeners();
        },
        
        createButton: function() {
            // Find the best location for tutorial button - look for user menu or header nav
            const header = document.querySelector('header nav') || document.querySelector('.header-nav') || document.querySelector('nav');
            
            if (!header) return;
            
            // Create tutorial button
            const button = document.createElement('button');
            button.id = 'tutorial-menu-btn';
            button.className = 'tutorial-menu-btn';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <span class="tutorial-btn-text">Tutorials</span>
            `;
            button.title = 'View available tutorials';
            button.onclick = () => this.open();
            
            // Add to header (prepend to keep it on left side, or find a better spot)
            header.appendChild(button);
            
            // Add styles
            const style = document.createElement('style');
            style.textContent = `
                .tutorial-menu-btn {
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    padding: 6px 10px;
                    background: #eff6ff;
                    border: 1px solid #bfdbfe;
                    border-radius: 6px;
                    color: #1e40af;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s;
                    margin-left: 8px;
                }
                
                .tutorial-menu-btn:hover {
                    background: #dbeafe;
                    border-color: #93c5fd;
                }
                
                .tutorial-menu-btn svg {
                    flex-shrink: 0;
                }
                
                /* Responsive: icon-only on smaller screens */
                @media (max-width: 768px) {
                    .tutorial-menu-btn {
                        padding: 6px 8px;
                    }
                    .tutorial-btn-text {
                        display: none;
                    }
                }
            `;
            document.head.appendChild(style);
        },
        
        createModal: function() {
            const modal = document.createElement('div');
            modal.id = 'tutorial-modal';
            modal.className = 'tutorial-modal';
            modal.innerHTML = `
                <div class="tutorial-modal-overlay"></div>
                <div class="tutorial-modal-content">
                    <div class="tutorial-modal-header">
                        <h2>📚 Available Tutorials</h2>
                        <button class="tutorial-modal-close" onclick="window.TutorialMenu.close()">&times;</button>
                    </div>
                    <div class="tutorial-modal-body">
                        <div id="tutorial-list-loading" class="tutorial-loading">
                            <div class="spinner"></div>
                            <p>Loading tutorials...</p>
                        </div>
                        <div id="tutorial-list" class="tutorial-list"></div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Add modal styles
            const style = document.createElement('style');
            style.textContent = `
                .tutorial-modal {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 10000;
                }
                
                .tutorial-modal.active {
                    display: block;
                }
                
                .tutorial-modal-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                }
                
                .tutorial-modal-content {
                    position: relative;
                    max-width: 600px;
                    margin: 80px auto;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    max-height: calc(100vh - 160px);
                    display: flex;
                    flex-direction: column;
                }
                
                .tutorial-modal-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 20px 24px;
                    border-bottom: 1px solid #e5e7eb;
                }
                
                .tutorial-modal-header h2 {
                    margin: 0;
                    font-size: 20px;
                    color: #111827;
                }
                
                .tutorial-modal-close {
                    background: none;
                    border: none;
                    font-size: 32px;
                    color: #6b7280;
                    cursor: pointer;
                    line-height: 1;
                    padding: 0;
                    width: 32px;
                    height: 32px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .tutorial-modal-close:hover {
                    color: #111827;
                }
                
                .tutorial-modal-body {
                    padding: 20px 24px;
                    overflow-y: auto;
                }
                
                .tutorial-loading {
                    text-align: center;
                    padding: 40px 20px;
                    color: #6b7280;
                }
                
                .spinner {
                    width: 40px;
                    height: 40px;
                    border: 3px solid #e5e7eb;
                    border-top-color: #3b82f6;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                    margin: 0 auto 16px;
                }
                
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                
                .tutorial-list {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }
                
                .tutorial-item {
                    padding: 16px;
                    border: 2px solid #e5e7eb;
                    border-radius: 8px;
                    transition: all 0.2s;
                }
                
                .tutorial-item:hover {
                    border-color: #3b82f6;
                }
                
                .tutorial-item-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: start;
                    margin-bottom: 8px;
                }
                
                .tutorial-item-title {
                    font-weight: 600;
                    font-size: 16px;
                    color: #111827;
                }
                
                .tutorial-item-status {
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                
                .tutorial-status-completed {
                    background: #d1fae5;
                    color: #065f46;
                }
                
                .tutorial-status-skipped {
                    background: #fee2e2;
                    color: #991b1b;
                }
                
                .tutorial-status-not_started {
                    background: #dbeafe;
                    color: #1e40af;
                }
                
                .tutorial-status-in_progress {
                    background: #fef3c7;
                    color: #92400e;
                }
                
                .tutorial-item-description {
                    font-size: 14px;
                    color: #6b7280;
                    margin-bottom: 12px;
                }
                
                .tutorial-item-actions {
                    display: flex;
                    gap: 8px;
                }
                
                .tutorial-btn {
                    padding: 6px 12px;
                    border-radius: 6px;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    border: none;
                    transition: all 0.2s;
                }
                
                .tutorial-btn-primary {
                    background: #3b82f6;
                    color: white;
                }
                
                .tutorial-btn-primary:hover {
                    background: #2563eb;
                }
                
                .tutorial-btn-secondary {
                    background: #f3f4f6;
                    color: #374151;
                    border: 1px solid #d1d5db;
                }
                
                .tutorial-btn-secondary:hover {
                    background: #e5e7eb;
                }
                
                .tutorial-empty {
                    text-align: center;
                    padding: 40px 20px;
                    color: #6b7280;
                }
            `;
            document.head.appendChild(style);
        },
        
        setupListeners: function() {
            // Close on overlay click
            document.querySelector('.tutorial-modal-overlay')?.addEventListener('click', () => this.close());
            
            // Close on ESC key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.modalOpen) {
                    this.close();
                }
            });
        },
        
        open: async function() {
            const modal = document.getElementById('tutorial-modal');
            modal.classList.add('active');
            this.modalOpen = true;
            
            // Check if tutorials are disabled - use global var if available, otherwise API call
            let disabled = false;
            if (typeof window.__WALKTHROUGH_DISABLED !== 'undefined') {
                disabled = window.__WALKTHROUGH_DISABLED;
            } else {
                try {
                    const response = await fetch('/api/walkthrough-api.php?action=get_preference');
                    const data = await response.json();
                    if (data.ok) {
                        disabled = data.disable_walkthroughs;
                    }
                } catch (error) {
                    console.error('Failed to get walkthrough preference:', error);
                }
            }
            
            // Load walkthroughs
            await this.loadWalkthroughs(disabled);
        },
        
        close: function() {
            const modal = document.getElementById('tutorial-modal');
            modal.classList.remove('active');
            this.modalOpen = false;
        },
        
        loadWalkthroughs: async function(isDisabled) {
            const loading = document.getElementById('tutorial-list-loading');
            const list = document.getElementById('tutorial-list');
            
            loading.style.display = 'block';
            list.innerHTML = '';
            
            try {
                // If disabled, show enable option
                if (isDisabled) {
                    list.innerHTML = `
                        <div class="tutorial-empty">
                            <div style="font-size: 48px; margin-bottom: 16px;">🚫</div>
                            <h3 style="margin: 0 0 12px 0; color: #111827;">Tutorials Disabled</h3>
                            <p style="color: #6b7280; margin-bottom: 20px;">
                                You have disabled all automatic tutorials.
                            </p>
                            <button onclick="window.TutorialMenu.enableWalkthroughs()" class="btn" style="background: #10b981; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                ✅ Re-Enable Tutorials
                            </button>
                            <p style="font-size: 12px; color: #9ca3af; margin-top: 12px;">
                                Tutorials will automatically appear for new features
                            </p>
                        </div>
                    `;
                    loading.style.display = 'none';
                    return;
                }
                
                const response = await fetch('/api/walkthrough-api.php?action=list_available');
                const data = await response.json();
                
                if (!data.ok) {
                    throw new Error(data.error || 'Failed to load tutorials');
                }
                
                this.walkthroughs = data.walkthroughs;
                this.renderWalkthroughs();
                
            } catch (error) {
                list.innerHTML = `
                    <div class="tutorial-empty">
                        <p>❌ Failed to load tutorials</p>
                        <p style="font-size: 14px;">${error.message}</p>
                    </div>
                `;
            } finally {
                loading.style.display = 'none';
            }
        },
        
        renderWalkthroughs: function() {
            const list = document.getElementById('tutorial-list');
            
            if (this.walkthroughs.length === 0) {
                list.innerHTML = `
                    <div class="tutorial-empty">
                        <div style="font-size: 48px; margin-bottom: 16px;">📚</div>
                        <p>No tutorials available yet</p>
                    </div>
                `;
                return;
            }
            
            list.innerHTML = this.walkthroughs.map(wt => `
                <div class="tutorial-item">
                    <div class="tutorial-item-header">
                        <div class="tutorial-item-title">${this.escapeHtml(wt.title)}</div>
                        <span class="tutorial-item-status tutorial-status-${wt.status}">
                            ${wt.status.replace('_', ' ')}
                        </span>
                    </div>
                    ${wt.description ? `<div class="tutorial-item-description">${this.escapeHtml(wt.description)}</div>` : ''}
                    <div class="tutorial-item-actions">
                        ${wt.can_restart ? `
                            <button class="tutorial-btn tutorial-btn-primary" onclick="window.TutorialMenu.restart(${wt.id}, '${this.escapeHtml(wt.title)}')">
                                🔄 Restart Tutorial
                            </button>
                        ` : wt.status === 'not_started' ? `
                            <button class="tutorial-btn tutorial-btn-primary" onclick="window.TutorialMenu.startNow(${wt.id})">
                                ▶️ Start Now
                            </button>
                        ` : wt.status === 'in_progress' ? `
                            <button class="tutorial-btn tutorial-btn-secondary" onclick="window.TutorialMenu.startNow(${wt.id})">
                                ▶️ Continue
                            </button>
                        ` : ''}
                    </div>
                </div>
            `).join('');
        },
        
        restart: async function(walkthroughId, title) {
            if (!confirm(`Restart the "${title}" tutorial?\n\nThis will reset your progress and show the tutorial again when you refresh the page.`)) {
                return;
            }
            
            try {
                const response = await fetch('/api/walkthrough-api.php?action=restart', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ walkthrough_id: walkthroughId })
                });
                
                const data = await response.json();
                console.log('Restart response:', data);
                
                if (data.ok) {
                    alert(data.message || 'Tutorial reset! Refresh the page to see it.');
                    this.close();
                    
                    // Optional: auto-refresh after 1 second
                    setTimeout(() => {
                        if (confirm('Refresh the page now to see the tutorial?')) {
                            location.reload();
                        }
                    }, 500);
                } else {
                    alert('Error: ' + (data.error || 'Failed to restart tutorial'));
                }
                
            } catch (error) {
                alert('Error restarting tutorial: ' + error.message);
            }
        },
        
        startNow: async function(walkthroughId) {
            // For not-started or in-progress tutorials, just reload the page
            location.reload();
        },
        
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        enableWalkthroughs: async function() {
            try {
                // Call API to enable walkthroughs
                await fetch('/api/walkthrough-api.php?action=set_preference', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        disable_walkthroughs: false
                    })
                });
                
                // Update global state
                window.__WALKTHROUGH_DISABLED = false;
                
                // Reload walkthroughs
                await this.loadWalkthroughs(false);
                
                // Show success message
                alert('✅ Tutorials re-enabled! New tutorials will automatically appear.');
            } catch (error) {
                console.error('Failed to enable walkthroughs:', error);
                alert('Error enabling tutorials. Please try again.');
            }
        }
    };
    
    // Expose globally
    window.TutorialMenu = TutorialMenu;
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => TutorialMenu.init());
    } else {
        TutorialMenu.init();
    }
    
})();

