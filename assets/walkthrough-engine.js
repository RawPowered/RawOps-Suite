/**
 * Walkthrough Engine
 * Client-side walkthrough/tutorial rendering system
 * 
 * Features:
 * - Auto-loads walkthroughs for current page
 * - Multi-step support with navigation
 * - Skip protection dialog
 * - Progress tracking
 * - Keyboard navigation
 * - Database-backed preferences (no localStorage)
 * 
 * Auto-start behavior:
 * - Only auto-starts walkthroughs that have never been seen (status=null)
 * - Once completed or skipped, walkthroughs don't auto-start
 * - Users can manually restart via Tutorials menu
 * 
 * @author Product Development Team
 * @date 2025-10-20
 * @updated 2025-10-22 - Database-backed preferences, fixed auto-start behavior
 */

(function() {
    'use strict';
    
    const WalkthroughEngine = {
        walkthroughs: [],
        currentWalkthrough: null,
        currentStep: 0,
        initialized: false,
        
        init: async function() {
            if (this.initialized) return;
            this.initialized = true;
            
            // Get page identifier from body data attribute
            const pageId = document.body.getAttribute('data-walkthrough-page');
            
            // Load active walkthroughs for this page
            await this.loadWalkthroughs(pageId);
            
            // Start first walkthrough if available AND it's never been seen (status is null)
            if (this.walkthroughs.length > 0) {
                const firstWalkthrough = this.walkthroughs[0];
                // Only auto-start if user has never seen this walkthrough (status is null/undefined)
                if (!firstWalkthrough.user_status) {
                    // Delay slightly to ensure page is fully loaded
                    setTimeout(() => {
                        this.start(firstWalkthrough);
                    }, 1000);
                }
            }
            
            // Setup keyboard navigation
            this.setupKeyboardNav();
        },
        
        loadWalkthroughs: async function(pageId) {
            try {
                const basePath = window.__BASE_PREFIX || '';
                const url = pageId 
                    ? `${basePath}/api/walkthrough-api.php?action=get_active&page=${encodeURIComponent(pageId)}`
                    : `${basePath}/api/walkthrough-api.php?action=get_active`;
                    
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.ok) {
                    this.walkthroughs = data.walkthroughs || [];
                    // If disabled flag is set, walkthroughs array will be empty
                    if (data.disabled) {
                        console.log('Walkthroughs disabled by user preference');
                    }
                }
            } catch (error) {
                console.error('Failed to load walkthroughs:', error);
            }
        },
        
        start: function(walkthrough) {
            this.currentWalkthrough = walkthrough;
            this.currentStep = walkthrough.current_step || 0;
            
            // Log start event
            this.logEvent('started');
            
            // Update progress to in_progress
            this.updateProgress('in_progress', this.currentStep);
            
            // Render walkthrough
            if (walkthrough.is_multi_step && walkthrough.steps && walkthrough.steps.length > 0) {
                this.renderStep(0);
            } else {
                this.renderSingleStep();
            }
        },
        
        renderSingleStep: function() {
            const walkthrough = this.currentWalkthrough;
            
            const html = `
                <div class="walkthrough-overlay" id="walkthroughOverlay">
                    <div class="walkthrough-modal">
                        <div class="walkthrough-header">
                            <h2 class="walkthrough-title">${this.escapeHtml(walkthrough.title)}</h2>
                            <button class="walkthrough-close" onclick="WalkthroughEngine.requestSkip()">×</button>
                        </div>
                        <div class="walkthrough-content">
                            ${walkthrough.content}
                        </div>
                        <div class="walkthrough-footer">
                            <button class="walkthrough-btn walkthrough-btn-secondary" onclick="WalkthroughEngine.requestSkip()">
                                Skip Tutorial
                            </button>
                            <button class="walkthrough-btn walkthrough-btn-primary" onclick="WalkthroughEngine.complete()">
                                Got It!
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', html);
            document.body.style.overflow = 'hidden';
        },
        
        renderStep: function(stepIndex) {
            const walkthrough = this.currentWalkthrough;
            const step = walkthrough.steps[stepIndex];
            
            if (!step) {
                this.complete();
                return;
            }
            
            this.currentStep = stepIndex;
            
            // Log step view
            this.logEvent('step_viewed', stepIndex + 1);
            
            const totalSteps = walkthrough.steps.length;
            const isFirst = stepIndex === 0;
            const isLast = stepIndex === totalSteps - 1;
            
            // Determine position
            const position = step.position || 'modal';
            const isModal = position === 'modal' || position === 'center';
            
            // Remove existing walkthrough
            const existing = document.getElementById('walkthroughOverlay');
            if (existing) {
                existing.remove();
            }
            
            // Build HTML
            let html = `
                <div class="walkthrough-overlay ${isModal ? '' : 'walkthrough-tooltip-mode'}" id="walkthroughOverlay">
            `;
            
            if (isModal) {
                html += `
                    <div class="walkthrough-modal">
                        <div class="walkthrough-header">
                            <div class="walkthrough-step-indicator">
                                Step ${stepIndex + 1} of ${totalSteps}
                            </div>
                            <h2 class="walkthrough-title">${this.escapeHtml(step.title)}</h2>
                            <button class="walkthrough-close" onclick="WalkthroughEngine.requestSkip()">×</button>
                        </div>
                        <div class="walkthrough-progress">
                            <div class="walkthrough-progress-bar" style="width: ${((stepIndex + 1) / totalSteps) * 100}%"></div>
                        </div>
                        <div class="walkthrough-content">
                            ${step.content}
                        </div>
                        <div class="walkthrough-footer">
                            <button class="walkthrough-btn walkthrough-btn-secondary" onclick="WalkthroughEngine.requestSkip()">
                                Skip Tutorial
                            </button>
                            <div class="walkthrough-nav-buttons">
                                ${!isFirst ? '<button class="walkthrough-btn walkthrough-btn-ghost" onclick="WalkthroughEngine.prevStep()">← Previous</button>' : ''}
                                <button class="walkthrough-btn walkthrough-btn-primary" onclick="WalkthroughEngine.nextStep()">
                                    ${isLast ? 'Finish' : 'Next →'}
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Tooltip mode - position near target element
                const targetElement = step.target_element_selector ? document.querySelector(step.target_element_selector) : null;
                
                if (step.highlight_element && targetElement) {
                    this.addSpotlight(targetElement);
                }
                
                html += `
                    <div class="walkthrough-tooltip walkthrough-tooltip-${position}" 
                         ${targetElement ? `style="top: ${targetElement.getBoundingClientRect().top}px; left: ${targetElement.getBoundingClientRect().left}px;"` : ''}>
                        <div class="walkthrough-tooltip-header">
                            <span class="walkthrough-step-indicator">Step ${stepIndex + 1}/${totalSteps}</span>
                            <button class="walkthrough-close" onclick="WalkthroughEngine.requestSkip()">×</button>
                        </div>
                        <h3 class="walkthrough-tooltip-title">${this.escapeHtml(step.title)}</h3>
                        <div class="walkthrough-tooltip-content">
                            ${step.content}
                        </div>
                        <div class="walkthrough-tooltip-footer">
                            ${!isFirst ? '<button class="walkthrough-btn-mini" onclick="WalkthroughEngine.prevStep()">←</button>' : ''}
                            <button class="walkthrough-btn-mini walkthrough-btn-primary" onclick="WalkthroughEngine.nextStep()">
                                ${isLast ? '✓' : '→'}
                            </button>
                        </div>
                    </div>
                `;
            }
            
            html += '</div>';
            
            document.body.insertAdjacentHTML('beforeend', html);
            
            if (isModal) {
                document.body.style.overflow = 'hidden';
            }
            
            // Update progress
            this.updateProgress('in_progress', stepIndex);
        },
        
        addSpotlight: function(element) {
            if (!element) return;
            
            const spotlight = document.createElement('div');
            spotlight.className = 'walkthrough-spotlight';
            spotlight.id = 'walkthroughSpotlight';
            
            const rect = element.getBoundingClientRect();
            spotlight.style.top = (rect.top - 10) + 'px';
            spotlight.style.left = (rect.left - 10) + 'px';
            spotlight.style.width = (rect.width + 20) + 'px';
            spotlight.style.height = (rect.height + 20) + 'px';
            
            document.body.appendChild(spotlight);
        },
        
        removeSpotlight: function() {
            const spotlight = document.getElementById('walkthroughSpotlight');
            if (spotlight) {
                spotlight.remove();
            }
        },
        
        nextStep: function() {
            if (!this.currentWalkthrough) return;
            
            const walkthrough = this.currentWalkthrough;
            
            if (!walkthrough.is_multi_step) {
                this.complete();
                return;
            }
            
            const nextIndex = this.currentStep + 1;
            
            // Log step completion
            this.logEvent('step_completed', this.currentStep + 1);
            
            if (nextIndex >= walkthrough.steps.length) {
                this.complete();
            } else {
                this.removeSpotlight();
                this.renderStep(nextIndex);
            }
        },
        
        prevStep: function() {
            if (!this.currentWalkthrough || this.currentStep === 0) return;
            
            this.removeSpotlight();
            this.renderStep(this.currentStep - 1);
        },
        
        requestSkip: function() {
            // Show skip confirmation dialog
            const html = `
                <div class="walkthrough-skip-dialog-overlay" id="skipDialog">
                    <div class="walkthrough-skip-dialog">
                        <div class="walkthrough-skip-icon">⚠️</div>
                        <h2 class="walkthrough-skip-title">Skip Tutorial?</h2>
                        <p class="walkthrough-skip-message">
                            This is a unique system, so while it may seem easy to understand, there may be some 
                            nuances to the system you might want to know about, even if some of it may be obvious to you.
                        </p>
                        <div class="walkthrough-skip-footer">
                            <button class="walkthrough-btn walkthrough-btn-secondary" id="continueWalkthroughBtn">
                                Continue Walkthrough
                            </button>
                            <button class="walkthrough-btn walkthrough-btn-warning" id="confirmSkipBtn">
                                Proceed Unguided (For now...)
                            </button>
                        </div>
                        <div class="walkthrough-skip-admin-option" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                            <button class="walkthrough-btn walkthrough-btn-danger" id="disableAllBtn" style="font-size: 12px; padding: 6px 12px;">
                                🚫 Disable All Future Tutorials (Admin)
                            </button>
                            <p style="font-size: 11px; color: #6b7280; margin: 8px 0 0 0;">
                                You can re-enable from the Tutorials menu
                            </p>
                        </div>
                        <p class="walkthrough-skip-note">
                            💡 Future tutorials may appear to introduce new updates to the system.
                        </p>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', html);
            
            // Add event listeners after inserting HTML
            document.getElementById('continueWalkthroughBtn')?.addEventListener('click', () => this.cancelSkip());
            document.getElementById('confirmSkipBtn')?.addEventListener('click', () => this.confirmSkip());
            document.getElementById('disableAllBtn')?.addEventListener('click', () => this.disableAllWalkthroughs());
        },
        
        cancelSkip: function() {
            const dialog = document.getElementById('skipDialog');
            if (dialog) {
                dialog.remove();
            }
        },
        
        confirmSkip: async function() {
            console.log('confirmSkip called');
            this.cancelSkip();
            await this.skip();
        },
        
        skip: async function() {
            console.log('skip() called, currentWalkthrough:', this.currentWalkthrough);
            
            if (!this.currentWalkthrough) {
                console.error('No current walkthrough to skip!');
                return;
            }
            
            const walkthroughId = this.currentWalkthrough.id;
            console.log('Skipping walkthrough ID:', walkthroughId);
            
            try {
                const basePath = window.__BASE_PREFIX || '';
                const apiUrl = `${basePath}/api/walkthrough-api.php?action=skip_all`;
                const payload = {
                    walkthrough_id: walkthroughId
                };
                
                console.log('API URL:', apiUrl);
                console.log('Payload:', payload);
                
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                
                console.log('Response status:', response.status);
                const data = await response.json();
                console.log('Walkthrough skip API response:', data);
                
                if (!data.ok) {
                    console.error('Failed to skip walkthrough:', data.error || data.message);
                    alert('Error skipping tutorial: ' + (data.error || data.message));
                    return;
                }
                
                // If admin auto-disabled, update global state
                if (data.admin_auto_disabled) {
                    window.__WALKTHROUGH_DISABLED = true;
                    console.log('Admin walkthroughs auto-disabled');
                }
                
                console.log('Skip successful, closing walkthrough');
            } catch (error) {
                console.error('Failed to skip walkthrough:', error);
                alert('Error skipping tutorial. Check console for details.');
                return;
            }
            
            this.close();
        },
        
        complete: async function() {
            if (!this.currentWalkthrough) return;
            
            // Log completion event
            this.logEvent('completed');
            
            try {
                const basePath = window.__BASE_PREFIX || '';
                await fetch(`${basePath}/api/walkthrough-api.php?action=update_progress`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        walkthrough_id: this.currentWalkthrough.id,
                        status: 'completed',
                        current_step: this.currentStep
                    })
                });
            } catch (error) {
                console.error('Failed to complete walkthrough:', error);
            }
            
            this.close();
            
            // Show completion notification
            this.showNotification('Tutorial completed! 🎉', 'success');
        },
        
        close: function() {
            const overlay = document.getElementById('walkthroughOverlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.remove();
                    document.body.style.overflow = '';
                }, 200);
            }
            
            this.removeSpotlight();
            this.currentWalkthrough = null;
            this.currentStep = 0;
        },
        
        updateProgress: async function(status, step) {
            if (!this.currentWalkthrough) return;
            
            try {
                const basePath = window.__BASE_PREFIX || '';
                await fetch(`${basePath}/api/walkthrough-api.php?action=update_progress`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        walkthrough_id: this.currentWalkthrough.id,
                        status: status,
                        current_step: step
                    })
                });
            } catch (error) {
                console.error('Failed to update progress:', error);
            }
        },
        
        logEvent: async function(eventType, stepNumber = null) {
            if (!this.currentWalkthrough) return;
            
            try {
                const basePath = window.__BASE_PREFIX || '';
                await fetch(`${basePath}/api/walkthrough-api.php?action=log_event`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        walkthrough_id: this.currentWalkthrough.id,
                        event_type: eventType,
                        step_number: stepNumber
                    })
                });
            } catch (error) {
                console.error('Failed to log event:', error);
            }
        },
        
        setupKeyboardNav: function() {
            document.addEventListener('keydown', (e) => {
                if (!this.currentWalkthrough) return;
                
                // Skip dialog is open - ignore keyboard nav
                if (document.getElementById('skipDialog')) return;
                
                switch (e.key) {
                    case 'Escape':
                        this.requestSkip();
                        break;
                    case 'ArrowRight':
                    case 'Enter':
                        this.nextStep();
                        break;
                    case 'ArrowLeft':
                        this.prevStep();
                        break;
                }
            });
        },
        
        disableAllWalkthroughs: async function() {
            if (confirm('Disable ALL future tutorials?\n\nYou can re-enable them anytime from the Tutorials menu button in the header.')) {
                try {
                    // Call API to set preference
                    const basePath = window.__BASE_PREFIX || '';
                    await fetch(`${basePath}/api/walkthrough-api.php?action=set_preference`, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            disable_walkthroughs: true
                        })
                    });
                    
                    // Skip current walkthrough
                    await this.skip();
                    
                    // Show notification
                    alert('✅ All future tutorials disabled. Click the "Tutorials" button in the header to re-enable or view specific tutorials.');
                } catch (error) {
                    console.error('Failed to disable walkthroughs:', error);
                    alert('Error disabling tutorials. Please try again.');
                }
            }
        },
        
        showNotification: function(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `walkthrough-notification walkthrough-notification-${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('walkthrough-notification-show');
            }, 10);
            
            setTimeout(() => {
                notification.classList.remove('walkthrough-notification-show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        },
        
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };
    
    // Expose globally
    window.WalkthroughEngine = WalkthroughEngine;
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            WalkthroughEngine.init();
        });
    } else {
        WalkthroughEngine.init();
    }
})();


