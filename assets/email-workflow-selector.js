/**
 * Email Workflow Selector Component - Enhanced Card-Based UI
 * Embeddable UI for selecting workflows when sending emails
 * Usage: EmailWorkflowSelector.render(containerId, onSelect)
 */

(function() {
    'use strict';
    
    window.EmailWorkflowSelector = {
        workflows: [],
        selectedWorkflowId: null,
        conditionalWorkflows: {
            opened: null,
            unopened: null
        },
        conditionalMode: false,
        onSelectCallback: null,
        isExpanded: false,
        defaultDecisionWaitHours: 48,
        conditionalDecisionWaitHours: 48,
        
        /**
         * Initialize and render the workflow selector
         * @param {string} containerId - ID of container div
         * @param {function} onSelect - Callback when workflow selected
         */
        render: async function(containerId, onSelect) {
            this.onSelectCallback = onSelect || function() {};
            
            const container = document.getElementById(containerId);
            if (!container) {
                console.error('Container not found:', containerId);
                return;
            }
            
            // Load workflows
            await this.loadWorkflows();
            
            // Load saved expansion state
            const savedState = localStorage.getItem('email_workflow_selector_expanded');
            this.isExpanded = savedState === 'true';
            
            // Render UI
            this.renderUI(container);
        },
        
        /**
         * Load available workflows from API
         */
        loadWorkflows: async function() {
            try {
                const resp = await fetch('/api/ai-email-composer.php?action=get_available_workflows', {
                    credentials: 'same-origin'
                });
                const data = await resp.json();
                
                if (data.ok && data.workflows) {
                    this.workflows = data.workflows;
                }
            } catch (error) {
                console.error('Failed to load workflows:', error);
                this.workflows = [];
            }
        },
        
        /**
         * Render the selector UI (card-based expandable with conditional logic)
         */
        renderUI: function(container) {
            const expandedClass = this.isExpanded ? 'expanded' : '';
            
            let html = `
                <div class="workflow-selector-container ${expandedClass}" id="workflowSelectorCard">
                    <div class="workflow-selector-header" onclick="EmailWorkflowSelector.toggleExpansion()">
                        <div class="workflow-selector-header-left">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="workflow-icon">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                            <h4>Attach Follow-up Workflow</h4>
                            ${this.selectedWorkflowId || this.conditionalMode ? '<span class="workflow-attached-badge">✓ Attached</span>' : '<span class="workflow-optional-badge">Optional</span>'}
                        </div>
                        <svg class="workflow-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </div>
                    
                    <div class="workflow-selector-body">
            `;
            
            if (this.workflows.length === 0) {
                html += `
                    <div class="workflow-empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;margin:0 auto 16px;">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6v6l4 2"/>
                        </svg>
                        <h4>No Workflows Available</h4>
                        <p>Create your first workflow to enable automated follow-ups based on recipient behavior.</p>
                        <a href="/onboarding/sales-agent/email-workflows.php" class="btn-create-workflow">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Create First Workflow
                        </a>
                    </div>
                `;
            } else {
                // Mode Toggle
                html += `
                    <div style="display:flex;gap:8px;padding:16px 16px 0;margin-bottom:12px;">
                        <button type="button" 
                                class="workflow-mode-btn ${!this.conditionalMode ? 'active' : ''}" 
                                onclick="EmailWorkflowSelector.setMode(false)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 8h1a4 4 0 0 1 0 8h-1M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
                            </svg>
                            Single Workflow
                        </button>
                        <button type="button" 
                                class="workflow-mode-btn ${this.conditionalMode ? 'active' : ''}" 
                                onclick="EmailWorkflowSelector.setMode(true)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="2"/>
                                <path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/>
                            </svg>
                            Conditional Logic
                            <span style="padding:2px 6px;background:rgba(59,130,246,0.2);color:#2563eb;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px;">PRO</span>
                        </button>
                    </div>
                `;
                
                // Info panel
                html += `
                    <div class="workflow-info-panel">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0;">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="16" x2="12" y2="12" stroke="white" stroke-width="2"/>
                            <line x1="12" y1="8" x2="12.01" y2="8" stroke="white" stroke-width="2"/>
                        </svg>
                        <div>
                            ${this.conditionalMode ? 
                                '<strong>Conditional Logic:</strong> Set different workflows based on whether the recipient opens the email or not. Perfect for sophisticated nurture campaigns.' :
                                '<strong>Single Workflow:</strong> One automated follow-up workflow based on recipient behavior (opens, clicks, conversions).'
                            }
                        </div>
                    </div>
                `;
                
                if (this.conditionalMode) {
                    // Conditional workflow UI
                    html += this.renderConditionalWorkflowUI();
                } else {
                    // Standard workflow cards
                    html += '<div class="workflow-cards-grid">';
                    
                    // Group workflows
                    const agentWorkflows = this.workflows.filter(w => w.scope === 'agent');
                    const globalWorkflows = this.workflows.filter(w => w.scope === 'global');
                    
                    // Render agent workflows
                    if (agentWorkflows.length > 0) {
                        html += '<div class="workflow-group-label">My Workflows</div>';
                        agentWorkflows.forEach(workflow => {
                            html += this.renderWorkflowCard(workflow);
                        });
                    }
                    
                    // Render global workflows
                    if (globalWorkflows.length > 0) {
                        html += '<div class="workflow-group-label">Global Workflows</div>';
                        globalWorkflows.forEach(workflow => {
                            html += this.renderWorkflowCard(workflow);
                        });
                    }
                    
                    html += '</div>';
                }
                
                html += `
                    <div class="workflow-quick-actions">
                        <button type="button" class="btn-quick-create" onclick="window.location.href='/onboarding/sales-agent/email-workflows.php?tab=create'">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Create New Workflow
                        </button>
                        ${this.selectedWorkflowId || this.conditionalMode ? `
                            <button type="button" class="btn-clear-selection" onclick="EmailWorkflowSelector.clearSelection()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                                Clear Selection
                            </button>
                        ` : ''}
                    </div>
                `;
            }
            
            html += `
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
            
            // Add styles if not already added
            if (!document.getElementById('workflow-selector-styles')) {
                const style = document.createElement('style');
                style.id = 'workflow-selector-styles';
                style.textContent = this.getStyles();
                document.head.appendChild(style);
            }
        },
        
        /**
         * Render conditional workflow UI (split for opened/unopened)
         */
        renderConditionalWorkflowUI: function() {
            let html = '<div class="conditional-workflow-container">';
            
            // If Opened
            html += `
                <div class="conditional-workflow-section" data-condition="opened">
                    <div class="conditional-workflow-header">
                        <div class="conditional-icon-badge" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </div>
                        <div>
                            <h4 style="margin:0;font-size:14px;font-weight:600;color:#166534;">If Email IS Opened</h4>
                            <p style="margin:4px 0 0;font-size:12px;color:#15803d;">Recipient reads your email</p>
                        </div>
                    </div>
                    <div class="conditional-workflow-select">
                        <select id="workflow_opened" onchange="EmailWorkflowSelector.selectConditionalWorkflow('opened', this.value)" style="width:100%;padding:10px;border:1px solid #86efac;border-radius:6px;font-size:13px;background:#f0fdf4;">
                            <option value="">No follow-up</option>
                            ${this.renderWorkflowOptions('opened')}
                        </select>
                        ${this.conditionalWorkflows.opened ? this.renderConditionalWorkflowPreview(this.conditionalWorkflows.opened) : ''}
                    </div>
                </div>
            `;
            
            // Divider
            html += `
                <div style="text-align:center;margin:16px 0;">
                    <div style="display:inline-block;padding:8px 16px;background:#f8fafc;border:2px solid #e5e7eb;border-radius:20px;font-size:12px;font-weight:700;color:#64748b;">
                        OR
                    </div>
                </div>
            `;
            
            // If NOT Opened
            html += `
                <div class="conditional-workflow-section" data-condition="unopened">
                    <div class="conditional-workflow-header">
                        <div class="conditional-icon-badge" style="background:linear-gradient(135deg,#fee2e2,#fecaca);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <line x1="18" y1="6" x2="6" y2="18"/>
                            </svg>
                        </div>
                        <div>
                            <h4 style="margin:0;font-size:14px;font-weight:600;color:#991b1b;">If Email is NOT Opened</h4>
                            <p style="margin:4px 0 0;font-size:12px;color:#b91c1c;">Recipient doesn't open your email</p>
                        </div>
                    </div>
                    <div class="conditional-workflow-select">
                        <select id="workflow_unopened" onchange="EmailWorkflowSelector.selectConditionalWorkflow('unopened', this.value)" style="width:100%;padding:10px;border:1px solid#fca5a5;border-radius:6px;font-size:13px;background:#fef2f2;">
                            <option value="">No follow-up</option>
                            ${this.renderWorkflowOptions('unopened')}
                        </select>
                        ${this.conditionalWorkflows.unopened ? this.renderConditionalWorkflowPreview(this.conditionalWorkflows.unopened) : ''}
                    </div>
                </div>
            `;
            
            html += '</div>';
            return html;
        },
        
        /**
         * Render workflow options for dropdown
         */
        renderWorkflowOptions: function(condition) {
            let html = '';
            
            const agentWorkflows = this.workflows.filter(w => w.scope === 'agent');
            const globalWorkflows = this.workflows.filter(w => w.scope === 'global');
            
            if (agentWorkflows.length > 0) {
                html += '<optgroup label="My Workflows">';
                agentWorkflows.forEach(w => {
                    const selected = this.conditionalWorkflows[condition] === w.id ? 'selected' : '';
                    html += `<option value="${w.id}" ${selected}>${w.name} (${w.trigger_delay_hours}h)</option>`;
                });
                html += '</optgroup>';
            }
            
            if (globalWorkflows.length > 0) {
                html += '<optgroup label="Global Workflows">';
                globalWorkflows.forEach(w => {
                    const selected = this.conditionalWorkflows[condition] === w.id ? 'selected' : '';
                    html += `<option value="${w.id}" ${selected}>${w.name} (${w.trigger_delay_hours}h)</option>`;
                });
                html += '</optgroup>';
            }
            
            return html;
        },
        
        /**
         * Render conditional workflow preview
         */
        renderConditionalWorkflowPreview: function(workflowId) {
            const workflow = this.workflows.find(w => w.id == workflowId);
            if (!workflow) return '';
            
            return `
                <div style="margin-top:8px;padding:10px;background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;">
                    <div style="font-weight:600;color:#1f2937;margin-bottom:4px;">✓ ${workflow.name}</div>
                    <div style="color:#64748b;">
                        Delay: ${workflow.trigger_delay_hours}h | ${workflow.allow_ai_generation ? '✨ AI-powered' : 'Template-based'}
                    </div>
                </div>
            `;
        },
        
        /**
         * Select conditional workflow
         */
        selectConditionalWorkflow: function(condition, workflowId) {
            this.conditionalWorkflows[condition] = parseInt(workflowId) || null;
            
            // Update preview
            const section = document.querySelector(`[data-condition="${condition}"] .conditional-workflow-select`);
            if (section) {
                const existingPreview = section.querySelector('div[style*="margin-top:8px"]');
                if (existingPreview) existingPreview.remove();
                
                if (workflowId) {
                    const preview = this.renderConditionalWorkflowPreview(parseInt(workflowId));
                    section.insertAdjacentHTML('beforeend', preview);
                }
            }
            
            // Update header badge
            this.updateHeaderBadge();
            
            // Call callback
            if (this.onSelectCallback) {
                this.onSelectCallback(this.getSelectedWorkflows());
            }
        },
        
        /**
         * Set mode (single or conditional)
         */
        setMode: function(conditional) {
            this.conditionalMode = conditional;
            
            // Clear selections when switching modes
            if (!conditional) {
                this.conditionalWorkflows = { opened: null, unopened: null };
            } else {
                this.selectedWorkflowId = null;
                this.conditionalDecisionWaitHours = this.defaultDecisionWaitHours;
            }
            
            // Re-render
            const container = document.getElementById('workflowSelectorContainer');
            if (container) {
                this.renderUI(container);
            }
        },
        
        /**
         * Get selected workflows (returns object for conditional or single ID)
         */
        getSelectedWorkflows: function() {
            if (this.conditionalMode) {
                return {
                    mode: 'conditional',
                    opened: this.conditionalWorkflows.opened,
                    unopened: this.conditionalWorkflows.unopened
                };
            } else {
                return {
                    mode: 'single',
                    workflowId: this.selectedWorkflowId
                };
            }
        },
        
        getSelectionPayload: function(includeEmpty = false) {
            if (this.conditionalMode) {
                const openedId = this.conditionalWorkflows.opened ? parseInt(this.conditionalWorkflows.opened, 10) : null;
                const unopenedId = this.conditionalWorkflows.unopened ? parseInt(this.conditionalWorkflows.unopened, 10) : null;
                if (!openedId && !unopenedId && !includeEmpty) {
                    return null;
                }
                return {
                    mode: 'conditional',
                    opened_workflow_id: openedId,
                    unopened_workflow_id: unopenedId,
                    decision_wait_hours: this.conditionalDecisionWaitHours || this.defaultDecisionWaitHours
                };
            }
            
            if (!this.selectedWorkflowId && !includeEmpty) {
                return null;
            }
            
            return {
                mode: this.selectedWorkflowId ? 'single' : null,
                workflow_id: this.selectedWorkflowId ? parseInt(this.selectedWorkflowId, 10) : null
            };
        },
        
        /**
         * Update header badge
         */
        updateHeaderBadge: function() {
            const header = document.querySelector('.workflow-selector-header-left');
            if (!header) return;
            
            const oldBadge = header.querySelector('.workflow-attached-badge, .workflow-optional-badge');
            if (oldBadge) oldBadge.remove();
            
            const hasSelection = this.selectedWorkflowId || 
                                this.conditionalWorkflows.opened || 
                                this.conditionalWorkflows.unopened;
            
            const badge = document.createElement('span');
            if (hasSelection) {
                badge.className = 'workflow-attached-badge';
                badge.textContent = '✓ Attached';
            } else {
                badge.className = 'workflow-optional-badge';
                badge.textContent = 'Optional';
            }
            header.appendChild(badge);
        },
        
        /**
         * Render individual workflow card
         */
        renderWorkflowCard: function(workflow) {
            const isSelected = this.selectedWorkflowId === workflow.id;
            const selectedClass = isSelected ? 'workflow-card-selected' : '';
            
            // Determine trigger icon
            const triggerIcons = {
                'unopened': '📭',
                'opened_not_clicked': '👁️',
                'clicked_no_action': '🖱️',
                'clicked_no_sale': '🛍️',
                'clicked_sale_with_agent': '✅',
                'clicked_ai_interaction': '🤖'
            };
            const triggerIcon = triggerIcons[workflow.trigger_type] || '🔔';
            
            // Format delay
            const hours = parseInt(workflow.trigger_delay_hours);
            const delayText = hours < 24 ? `${hours}h` : `${Math.round(hours / 24)}d`;
            
            return `
                <div class="workflow-card ${selectedClass}" 
                     onclick="EmailWorkflowSelector.selectWorkflow(${workflow.id})"
                     data-workflow-id="${workflow.id}">
                    <div class="workflow-card-header">
                        <div class="workflow-card-title">
                            ${isSelected ? '<span class="workflow-card-check">✓</span>' : ''}
                            <span>${workflow.name}</span>
                        </div>
                        ${workflow.scope === 'global' ? '<span class="workflow-scope-badge">Global</span>' : ''}
                    </div>
                    
                    <div class="workflow-card-meta">
                        <div class="workflow-meta-item" title="Trigger condition">
                            <span class="workflow-meta-icon">${triggerIcon}</span>
                            <span class="workflow-meta-text">${workflow.trigger_type.replace(/_/g, ' ')}</span>
                        </div>
                        <div class="workflow-meta-item" title="Delay before sending">
                            <span class="workflow-meta-icon">⏰</span>
                            <span class="workflow-meta-text">${delayText} delay</span>
                        </div>
                        ${workflow.allow_ai_generation ? `
                            <div class="workflow-meta-item" title="AI-powered content">
                                <span class="workflow-meta-icon">✨</span>
                                <span class="workflow-meta-text">AI</span>
                            </div>
                        ` : ''}
                    </div>
                    
                    ${workflow.description ? `
                        <div class="workflow-card-description">
                            ${workflow.description}
                        </div>
                    ` : ''}
                </div>
            `;
        },
        
        /**
         * Toggle expansion state
         */
        toggleExpansion: function() {
            this.isExpanded = !this.isExpanded;
            
            const container = document.getElementById('workflowSelectorCard');
            if (container) {
                if (this.isExpanded) {
                    container.classList.add('expanded');
                } else {
                    container.classList.remove('expanded');
                }
            }
            
            // Save state
            localStorage.setItem('email_workflow_selector_expanded', this.isExpanded);
        },
        
        /**
         * Select a workflow
         */
        selectWorkflow: function(workflowId) {
            this.selectedWorkflowId = workflowId;
            
            // Update UI
            const cards = document.querySelectorAll('.workflow-card');
            cards.forEach(card => {
                if (parseInt(card.dataset.workflowId) === workflowId) {
                    card.classList.add('workflow-card-selected');
                    // Add check mark if not present
                    if (!card.querySelector('.workflow-card-check')) {
                        const title = card.querySelector('.workflow-card-title');
                        const check = document.createElement('span');
                        check.className = 'workflow-card-check';
                        check.textContent = '✓';
                        title.insertBefore(check, title.firstChild);
                    }
                } else {
                    card.classList.remove('workflow-card-selected');
                    const check = card.querySelector('.workflow-card-check');
                    if (check) check.remove();
                }
            });
            
            // Update header badge
            const header = document.querySelector('.workflow-selector-header-left');
            if (header) {
                const oldBadge = header.querySelector('.workflow-attached-badge, .workflow-optional-badge');
                if (oldBadge) oldBadge.remove();
                
                const badge = document.createElement('span');
                badge.className = 'workflow-attached-badge';
                badge.textContent = '✓ Attached';
                header.appendChild(badge);
            }
            
            // Update quick actions
            const quickActions = document.querySelector('.workflow-quick-actions');
            if (quickActions && !document.querySelector('.btn-clear-selection')) {
                const clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'btn-clear-selection';
                clearBtn.onclick = () => this.clearSelection();
                clearBtn.innerHTML = `
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                    Clear Selection
                `;
                quickActions.appendChild(clearBtn);
            }
            
            // Call callback
            if (this.onSelectCallback) {
                this.onSelectCallback(workflowId);
            }
        },
        
        /**
         * Clear selection
         */
        clearSelection: function() {
            this.selectedWorkflowId = null;
            this.conditionalWorkflows = { opened: null, unopened: null };
            
            // Update UI
            const cards = document.querySelectorAll('.workflow-card');
            cards.forEach(card => {
                card.classList.remove('workflow-card-selected');
                const check = card.querySelector('.workflow-card-check');
                if (check) check.remove();
            });
            
            // Clear conditional selects
            const openedSelect = document.getElementById('workflow_opened');
            const unopenedSelect = document.getElementById('workflow_unopened');
            if (openedSelect) openedSelect.value = '';
            if (unopenedSelect) unopenedSelect.value = '';
            
            // Update header badge
            this.updateHeaderBadge();
            
            // Remove clear button
            const clearBtn = document.querySelector('.btn-clear-selection');
            if (clearBtn) clearBtn.remove();
            
            // Call callback
            if (this.onSelectCallback) {
                this.onSelectCallback(null);
            }
        },
        
        /**
         * Get currently selected workflow ID
         */
        getSelectedWorkflowId: function() {
            return this.selectedWorkflowId;
        },
        
        /**
         * Clear and reset
         */
        clear: function() {
            this.clearSelection();
        },
        
        /**
         * Get component styles
         */
        getStyles: function() {
            return `
                .workflow-selector-container {
                    background: #ffffff;
                    border: 2px solid #e5e7eb;
                    border-radius: 12px;
                    margin: 20px 0;
                    overflow: hidden;
                    transition: all 0.3s ease;
                }
                
                .workflow-selector-container:hover {
                    border-color: #cbd5e1;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                }
                
                .workflow-selector-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 16px 20px;
                    background: linear-gradient(135deg, #f0f9ff, #dbeafe);
                    cursor: pointer;
                    user-select: none;
                    transition: background 0.2s ease;
                }
                
                .workflow-selector-container.expanded .workflow-selector-header {
                    background: linear-gradient(135deg, #3b82f6, #60a5fa);
                    color: #ffffff;
                }
                
                .workflow-selector-header:hover {
                    background: linear-gradient(135deg, #2563eb, #3b82f6);
                    color: #ffffff;
                }
                
                .workflow-selector-header-left {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    flex: 1;
                }
                
                .workflow-selector-header-left h4 {
                    margin: 0;
                    font-size: 16px;
                    font-weight: 600;
                }
                
                .workflow-icon {
                    flex-shrink: 0;
                }
                
                .workflow-attached-badge {
                    padding: 4px 10px;
                    background: rgba(16, 185, 129, 0.15);
                    color: #059669;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 700;
                    text-transform: uppercase;
                }
                
                .workflow-selector-container.expanded .workflow-attached-badge {
                    background: rgba(255, 255, 255, 0.25);
                    color: #ffffff;
                }
                
                .workflow-optional-badge {
                    padding: 4px 10px;
                    background: rgba(100, 116, 139, 0.1);
                    color: #64748b;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                
                .workflow-selector-container.expanded .workflow-optional-badge {
                    background: rgba(255, 255, 255, 0.2);
                    color: rgba(255, 255, 255, 0.9);
                }
                
                .workflow-chevron {
                    flex-shrink: 0;
                    transition: transform 0.3s ease;
                }
                
                .workflow-selector-container.expanded .workflow-chevron {
                    transform: rotate(180deg);
                }
                
                .workflow-selector-body {
                    max-height: 0;
                    overflow: hidden;
                    transition: max-height 0.3s ease;
                }
                
                .workflow-selector-container.expanded .workflow-selector-body {
                    max-height: 800px;
                    overflow-y: auto;
                }
                
                .workflow-info-panel {
                    display: flex;
                    gap: 12px;
                    padding: 16px;
                    margin: 16px;
                    background: #dbeafe;
                    border: 1px solid #93c5fd;
                    border-radius: 8px;
                    font-size: 13px;
                    color: #1e40af;
                    line-height: 1.5;
                }
                
                .workflow-empty-state {
                    text-align: center;
                    padding: 48px 24px;
                }
                
                .workflow-empty-state h4 {
                    margin: 0 0 8px;
                    font-size: 18px;
                    color: #1f2937;
                }
                
                .workflow-empty-state p {
                    margin: 0 0 20px;
                    color: #64748b;
                    font-size: 14px;
                    line-height: 1.6;
                }
                
                .btn-create-workflow {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    padding: 10px 20px;
                    background: linear-gradient(135deg, #3b82f6, #60a5fa);
                    color: #ffffff;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    text-decoration: none;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                
                .btn-create-workflow:hover {
                    background: linear-gradient(135deg, #2563eb, #3b82f6);
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
                }
                
                .workflow-cards-grid {
                    display: grid;
                    gap: 12px;
                    padding: 0 16px;
                    margin: 16px 0;
                }
                
                .workflow-group-label {
                    font-size: 12px;
                    font-weight: 700;
                    color: #64748b;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin: 16px 0 8px;
                    padding: 0 4px;
                }
                
                .workflow-card {
                    background: #f8fafc;
                    border: 2px solid #e5e7eb;
                    border-radius: 10px;
                    padding: 16px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                
                .workflow-card:hover {
                    background: #f1f5f9;
                    border-color: #cbd5e1;
                    transform: translateY(-1px);
                    box-shadow: 0 4px 8px rgba(0,0,0,0.08);
                }
                
                .workflow-card-selected {
                    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
                    border-color: #10b981;
                }
                
                .workflow-card-selected:hover {
                    background: linear-gradient(135deg, #bbf7d0, #86efac);
                }
                
                .workflow-card-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: start;
                    margin-bottom: 12px;
                }
                
                .workflow-card-title {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 15px;
                    font-weight: 600;
                    color: #1f2937;
                    line-height: 1.4;
                }
                
                .workflow-card-check {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 20px;
                    height: 20px;
                    background: #10b981;
                    color: #ffffff;
                    border-radius: 50%;
                    font-size: 12px;
                    flex-shrink: 0;
                }
                
                .workflow-scope-badge {
                    padding: 2px 8px;
                    background: rgba(59, 130, 246, 0.15);
                    color: #2563eb;
                    border-radius: 10px;
                    font-size: 10px;
                    font-weight: 700;
                    text-transform: uppercase;
                }
                
                .workflow-card-meta {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 12px;
                    margin-bottom: 8px;
                }
                
                .workflow-meta-item {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 12px;
                    color: #64748b;
                }
                
                .workflow-meta-icon {
                    font-size: 14px;
                    line-height: 1;
                }
                
                .workflow-card-description {
                    font-size: 12px;
                    color: #64748b;
                    line-height: 1.5;
                    margin-top: 8px;
                    padding-top: 8px;
                    border-top: 1px solid rgba(0,0,0,0.05);
                }
                
                .workflow-quick-actions {
                    display: flex;
                    gap: 8px;
                    padding: 16px;
                    border-top: 1px solid #e5e7eb;
                    background: #f8fafc;
                }
                
                .btn-quick-create,
                .btn-clear-selection {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 8px 14px;
                    background: #ffffff;
                    color: #3b82f6;
                    border: 1px solid #3b82f6;
                    border-radius: 6px;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                
                .btn-quick-create:hover {
                    background: #3b82f6;
                    color: #ffffff;
                }
                
                .btn-clear-selection {
                    color: #ef4444;
                    border-color: #ef4444;
                }
                
                .btn-clear-selection:hover {
                    background: #ef4444;
                    color: #ffffff;
                }
                
                /* Conditional Logic Mode Buttons */
                .workflow-mode-btn {
                    flex: 1;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    padding: 10px 16px;
                    background: #ffffff;
                    border: 2px solid #e5e7eb;
                    border-radius: 8px;
                    color: #64748b;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                
                .workflow-mode-btn:hover {
                    border-color: #cbd5e1;
                    background: #f8fafc;
                }
                
                .workflow-mode-btn.active {
                    background: linear-gradient(135deg, #3b82f6, #60a5fa);
                    border-color: #3b82f6;
                    color: #ffffff;
                }
                
                /* Conditional Workflow Container */
                .conditional-workflow-container {
                    padding: 16px;
                }
                
                .conditional-workflow-section {
                    background: #ffffff;
                    border: 2px solid #e5e7eb;
                    border-radius: 12px;
                    padding: 16px;
                    margin-bottom: 16px;
                }
                
                .conditional-workflow-header {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    margin-bottom: 12px;
                }
                
                .conditional-icon-badge {
                    width: 40px;
                    height: 40px;
                    border-radius: 10px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                }
                
                .conditional-workflow-select {
                    padding-left: 52px;
                }
                
                @media (max-width: 768px) {
                    .workflow-selector-header {
                        padding: 14px 16px;
                    }
                    
                    .workflow-selector-header-left h4 {
                        font-size: 14px;
                    }
                    
                    .workflow-card {
                        padding: 14px;
                    }
                    
                    .workflow-quick-actions {
                        flex-direction: column;
                    }
                }
            `;
        }
    };
    
})();
