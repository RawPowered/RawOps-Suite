/**
 * Email Workflow Entity Configuration
 * Modal interface for sales agents to configure follow-up workflows per lead/account
 */

(function() {
    'use strict';
    
    window.WorkflowEntityConfig = {
        /**
         * Show workflow configuration modal for a lead or account
         */
        show: function(entityType, entityId, entityName) {
            if (!entityType || !entityId) {
                alert('Invalid entity type or ID');
                return;
            }
            
            // Create modal
            const modal = this.createModal(entityType, entityId, entityName);
            document.body.appendChild(modal);
            
            // Load workflows and settings
            this.loadWorkflowsForEntity(entityType, entityId);
        },
        
        /**
         * Create the modal HTML
         */
        createModal: function(entityType, entityId, entityName) {
            // Load accordion assets if not already loaded
            if (!document.getElementById('product-accordion-css')) {
                const link = document.createElement('link');
                link.id = 'product-accordion-css';
                link.rel = 'stylesheet';
                link.href = '/assets/product-accordion.css';
                document.head.appendChild(link);
            }
            
            if (!document.getElementById('product-accordion-js')) {
                const script = document.createElement('script');
                script.id = 'product-accordion-js';
                script.src = '/assets/product-accordion.js';
                document.head.appendChild(script);
            }
            
            const modal = document.createElement('div');
            modal.id = 'workflowConfigModal';
            modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;';
            
            modal.innerHTML = `
                <div style="background:#fff;border-radius:16px;max-width:900px;width:95%;max-height:90vh;overflow:auto;">
                    <div style="background:linear-gradient(135deg,#14b8a6,#0ea5e9);color:#fff;padding:24px;border-radius:16px 16px 0 0;">
                        <h3 style="margin:0;font-size:22px;">🔄 Follow-up Workflow Settings</h3>
                        <div style="margin-top:8px;font-size:13px;opacity:0.9;">
                            ${entityType === 'lead' ? 'Lead' : 'Account'}: <strong>${entityName}</strong>
                        </div>
                    </div>
                    
                    <div style="padding:24px;">
                        <div style="background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;padding:16px;margin-bottom:20px;">
                            <h4 style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1e40af;">💡 How This Works</h4>
                            <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;">
                                Configure automated email follow-ups for this ${entityType}. Customize timing, enable/disable specific workflows, and optimize send times. 
                                Each workflow can have its own custom settings that override the defaults.
                            </p>
                        </div>
                        
                        <!-- Loading State -->
                        <div id="workflowConfigLoading" style="text-align:center;padding:40px;color:#64748b;">
                            <div style="width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 16px;"></div>
                            Loading workflows...
                        </div>
                        
                        <!-- Workflows List -->
                        <div id="workflowConfigContent" style="display:none;">
                            <div id="workflowsList"></div>
                            
                            <div style="margin-top:24px;padding-top:24px;border-top:2px solid #e5e7eb;">
                                <h4 style="margin:0 0 16px;font-size:16px;font-weight:600;color:#1f2937;">📅 Scheduled Follow-ups</h4>
                                <div id="scheduledList" style="font-size:13px;"></div>
                            </div>
                        </div>
                        
                        <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:24px;border-top:1px solid #e5e7eb;padding-top:20px;">
                            <button class="btn btn-secondary" onclick="WorkflowEntityConfig.close()">Close</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Close on background click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.close();
                }
            });
            
            // Store entity info
            modal.dataset.entityType = entityType;
            modal.dataset.entityId = entityId;
            
            return modal;
        },
        
        /**
         * Load workflows and entity settings
         */
        loadWorkflowsForEntity: async function(entityType, entityId) {
            try {
                // Load all workflows
                const workflowsResp = await fetch('/api/email-workflow-api.php?action=get_workflows', {
                    credentials: 'same-origin'
                });
                const workflowsData = await workflowsResp.json();
                
                if (!workflowsData.ok) {
                    throw new Error(workflowsData.error || 'Failed to load workflows');
                }
                
                // Load entity settings
                const settingsResp = await fetch(`/api/email-workflow-api.php?action=get_entity_settings&entity_type=${entityType}&entity_id=${entityId}`, {
                    credentials: 'same-origin'
                });
                const settingsData = await settingsResp.json();
                
                if (!settingsData.ok) {
                    throw new Error(settingsData.error || 'Failed to load settings');
                }
                
                // Build settings map
                const settingsMap = {};
                settingsData.settings.forEach(s => {
                    settingsMap[s.workflow_id] = s;
                });
                
                // Render workflows
                this.renderWorkflows(workflowsData.workflows, settingsMap, entityType, entityId);
                
                // Load scheduled follow-ups
                this.loadScheduledForEntity(entityType, entityId);
                
                // Show content
                document.getElementById('workflowConfigLoading').style.display = 'none';
                document.getElementById('workflowConfigContent').style.display = 'block';
                
            } catch (error) {
                document.getElementById('workflowConfigLoading').textContent = 'Error: ' + error.message;
            }
        },
        
        /**
         * Render workflows list with settings using accordion
         */
        renderWorkflows: function(workflows, settingsMap, entityType, entityId) {
            const container = document.getElementById('workflowsList');
            
            if (!workflows || workflows.length === 0) {
                container.innerHTML = '<p style="color:#64748b;font-style:italic;">No workflows available. Contact admin to create workflows.</p>';
                return;
            }
            
            // Create accordion sections for each workflow
            const sections = workflows.map(workflow => {
                const settings = settingsMap[workflow.id] || {};
                const isEnabled = settings.is_enabled !== undefined ? settings.is_enabled : true;
                const customDelay = settings.custom_delay_hours || workflow.trigger_delay_hours;
                const timeOptimization = settings.allow_time_of_day_optimization || false;
                const preferredHour = settings.preferred_send_hour || 10;
                
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
                
                return {
                    id: 'workflow_' + workflow.id,
                    title: workflow.name,
                    icon: triggerIcon,
                    badge: isEnabled ? 'Enabled' : 'Disabled',
                    expanded: isEnabled,
                    theme: isEnabled ? 'green' : 'gray',
                    content: () => `
                        <div style="margin-bottom:16px;">
                            <div style="font-size:13px;color:#64748b;margin-bottom:12px;line-height:1.6;">
                                ${workflow.description || 'No description provided for this workflow.'}
                            </div>
                            
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">
                                <div style="background:#f8fafc;padding:12px;border-radius:8px;">
                                    <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Trigger</div>
                                    <div style="font-size:14px;font-weight:600;color:#1f2937;">${workflow.trigger_type.replace(/_/g, ' ')}</div>
                                </div>
                                <div style="background:#f8fafc;padding:12px;border-radius:8px;">
                                    <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Default Delay</div>
                                    <div style="font-size:14px;font-weight:600;color:#1f2937;">${workflow.trigger_delay_hours}h</div>
                                </div>
                                <div style="background:#f8fafc;padding:12px;border-radius:8px;">
                                    <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">AI Powered</div>
                                    <div style="font-size:14px;font-weight:600;color:#1f2937;">${workflow.allow_ai_generation ? '✓ Yes' : '✗ No'}</div>
                                </div>
                            </div>
                            
                            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin-bottom:16px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <h4 style="margin:0;font-size:14px;font-weight:600;color:#0369a1;">Workflow Status</h4>
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="checkbox" 
                                               id="workflow_enabled_${workflow.id}"
                                               ${isEnabled ? 'checked' : ''} 
                                               onchange="WorkflowEntityConfig.toggleWorkflowStatus('${entityType}', ${entityId}, ${workflow.id}, this.checked)"
                                               style="width:18px;height:18px;">
                                        <span style="font-size:13px;font-weight:600;color:#0369a1;">${isEnabled ? 'Enabled' : 'Disabled'}</span>
                                    </label>
                                </div>
                                <div style="font-size:12px;color:#0c4a6e;">
                                    ${isEnabled ? '✓ This workflow will automatically send follow-up emails based on recipient behavior.' : '✗ This workflow is currently disabled for this ' + entityType + '.'}
                                </div>
                            </div>
                            
                            <div id="workflow_custom_${workflow.id}" style="${isEnabled ? '' : 'display:none;'}">
                                <h4 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1f2937;">⚙️ Custom Settings</h4>
                                
                                <div style="display:grid;gap:16px;margin-bottom:16px;">
                                    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:12px;">
                                        <label style="display:block;margin-bottom:8px;">
                                            <span style="color:#78350f;font-size:13px;font-weight:600;display:block;margin-bottom:6px;">⏰ Custom Delay (hours)</span>
                                            <div style="display:flex;gap:8px;align-items:center;">
                                                <input type="number" 
                                                       value="${customDelay}" 
                                                       min="1" 
                                                       max="720"
                                                       style="flex:1;padding:8px;border:1px solid #fbbf24;border-radius:6px;font-size:14px;"
                                                       onchange="WorkflowEntityConfig.updateDelay('${entityType}', ${entityId}, ${workflow.id}, this.value)">
                                                <div style="display:flex;gap:4px;">
                                                    <button onclick="document.querySelector('#workflow_custom_${workflow.id} input[type=number]').value=24; WorkflowEntityConfig.updateDelay('${entityType}', ${entityId}, ${workflow.id}, 24);" 
                                                            style="padding:6px 10px;background:#fff;border:1px solid #fbbf24;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;color:#92400e;">24h</button>
                                                    <button onclick="document.querySelector('#workflow_custom_${workflow.id} input[type=number]').value=48; WorkflowEntityConfig.updateDelay('${entityType}', ${entityId}, ${workflow.id}, 48);" 
                                                            style="padding:6px 10px;background:#fff;border:1px solid #fbbf24;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;color:#92400e;">48h</button>
                                                    <button onclick="document.querySelector('#workflow_custom_${workflow.id} input[type=number]').value=72; WorkflowEntityConfig.updateDelay('${entityType}', ${entityId}, ${workflow.id}, 72);" 
                                                            style="padding:6px 10px;background:#fff;border:1px solid #fbbf24;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;color:#92400e;">72h</button>
                                                    <button onclick="document.querySelector('#workflow_custom_${workflow.id} input[type=number]').value=168; WorkflowEntityConfig.updateDelay('${entityType}', ${entityId}, ${workflow.id}, 168);" 
                                                            style="padding:6px 10px;background:#fff;border:1px solid #fbbf24;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;color:#92400e;">1wk</button>
                                                </div>
                                            </div>
                                        </label>
                                        <div style="font-size:11px;color:#78350f;margin-top:4px;">
                                            💡 Adjust how long to wait before sending the follow-up email
                                        </div>
                                    </div>
                                    
                                    <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:12px;">
                                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer;">
                                            <input type="checkbox" 
                                                   id="time_opt_${workflow.id}"
                                                   ${timeOptimization ? 'checked' : ''} 
                                                   onchange="WorkflowEntityConfig.toggleTimeOptimization('${entityType}', ${entityId}, ${workflow.id}, this.checked)"
                                                   style="width:18px;height:18px;">
                                            <span style="font-size:13px;font-weight:600;color:#115e59;">🕐 Enable time-of-day optimization</span>
                                        </label>
                                        
                                        <div id="time_opt_controls_${workflow.id}" style="${timeOptimization ? '' : 'display:none;'}">
                                            <label style="display:block;">
                                                <span style="color:#0f766e;font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Preferred Send Hour (24h format)</span>
                                                <input type="number" 
                                                       value="${preferredHour}" 
                                                       min="0" 
                                                       max="23"
                                                       style="width:100%;padding:8px;border:1px solid #5eead4;border-radius:6px;font-size:14px;"
                                                       onchange="WorkflowEntityConfig.updateSendHour('${entityType}', ${entityId}, ${workflow.id}, this.value)">
                                            </label>
                                            <div style="font-size:11px;color:#0f766e;margin-top:4px;">
                                                📧 Emails will be scheduled to send around this hour when possible
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `
                };
            });
            
            // Create accordion
            if (typeof ProductAccordion !== 'undefined') {
                ProductAccordion.create('workflowsList', sections, {
                    persist: true,
                    storageKey: 'workflow_entity_config_' + entityType + '_' + entityId,
                    allowMultiple: true,
                    showExpandAll: sections.length > 3
                });
            } else {
                // Fallback if accordion not loaded
                let html = '<div style="color:#dc2626;background:#fee2e2;padding:12px;border-radius:8px;margin-bottom:12px;">⚠️ Accordion component not loaded. Using fallback display.</div>';
                sections.forEach(section => {
                    html += `<div style="margin-bottom:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
                        <h4 style="margin:0 0 12px;">${section.icon} ${section.title}</h4>
                        ${typeof section.content === 'function' ? section.content() : section.content}
                    </div>`;
                });
                container.innerHTML = html;
            }
        },
        
        /**
         * Toggle workflow status with accordion update
         */
        toggleWorkflowStatus: async function(entityType, entityId, workflowId, enabled) {
            await this.toggleWorkflow(entityType, entityId, workflowId, enabled);
            
            // Update accordion badge
            const section = document.querySelector(`[data-section-id="workflow_${workflowId}"]`);
            if (section) {
                const badge = section.querySelector('.product-accordion-badge');
                if (badge) {
                    badge.textContent = enabled ? 'Enabled' : 'Disabled';
                }
                
                // Update theme
                section.dataset.theme = enabled ? 'green' : 'gray';
                
                // Update header if needed
                const header = section.querySelector('.product-accordion-header');
                if (header && enabled) {
                    header.style.background = 'linear-gradient(135deg, #dcfce7, #bbf7d0)';
                } else if (header) {
                    header.style.background = 'linear-gradient(135deg, #f8fafc, #f1f5f9)';
                }
            }
        },
        
        /**
         * Load scheduled follow-ups for entity
         */
        loadScheduledForEntity: async function(entityType, entityId) {
            try {
                const resp = await fetch(`/api/email-workflow-api.php?action=get_scheduled_followups&entity_type=${entityType}&entity_id=${entityId}`, {
                    credentials: 'same-origin'
                });
                const data = await resp.json();
                
                const container = document.getElementById('scheduledList');
                
                if (!data.ok || !data.followups || data.followups.length === 0) {
                    container.innerHTML = '<p style="color:#64748b;font-style:italic;font-size:13px;">No follow-ups scheduled yet.</p>';
                    return;
                }
                
                let html = '<div style="display:grid;gap:8px;">';
                data.followups.forEach(f => {
                    const statusColor = f.status === 'pending' ? '#f59e0b' : (f.status === 'sent' ? '#10b981' : '#94a3b8');
                    html += `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;">
                            <div>
                                <div style="font-weight:600;font-size:13px;">${f.workflow_name}</div>
                                <div style="font-size:11px;color:#64748b;">
                                    ${new Date(f.scheduled_for).toLocaleString()} • ${f.recipient_email}
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="background:${statusColor};color:#fff;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                                    ${f.status.toUpperCase()}
                                </span>
                                ${f.status === 'pending' ? `<button style="padding:4px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px;" onclick="WorkflowEntityConfig.cancelFollowup(${f.id})">Cancel</button>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                
                container.innerHTML = html;
                
            } catch (error) {
                console.error('Failed to load scheduled follow-ups:', error);
            }
        },
        
        /**
         * Toggle workflow enabled/disabled
         */
        toggleWorkflow: async function(entityType, entityId, workflowId, enabled) {
            try {
                const resp = await fetch('/api/email-workflow-api.php?action=update_entity_settings', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        entity_type: entityType,
                        entity_id: entityId,
                        workflow_id: workflowId,
                        is_enabled: enabled ? 1 : 0
                    })
                });
                
                const data = await resp.json();
                
                if (!data.ok) {
                    alert('Error: ' + (data.error || 'Failed to update'));
                    return;
                }
                
                // Show/hide customization options
                const customDiv = document.getElementById(`workflow_custom_${workflowId}`);
                if (customDiv) {
                    customDiv.style.display = enabled ? 'block' : 'none';
                }
                
            } catch (error) {
                alert('Error: ' + error.message);
            }
        },
        
        /**
         * Update custom delay
         */
        updateDelay: async function(entityType, entityId, workflowId, delayHours) {
            try {
                const resp = await fetch('/api/email-workflow-api.php?action=update_entity_settings', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        entity_type: entityType,
                        entity_id: entityId,
                        workflow_id: workflowId,
                        custom_delay_hours: parseInt(delayHours)
                    })
                });
                
                const data = await resp.json();
                
                if (!data.ok) {
                    alert('Error: ' + (data.error || 'Failed to update'));
                }
                
            } catch (error) {
                alert('Error: ' + error.message);
            }
        },
        
        /**
         * Toggle time optimization
         */
        toggleTimeOptimization: async function(entityType, entityId, workflowId, enabled) {
            try {
                const resp = await fetch('/api/email-workflow-api.php?action=update_entity_settings', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        entity_type: entityType,
                        entity_id: entityId,
                        workflow_id: workflowId,
                        allow_time_of_day_optimization: enabled ? 1 : 0
                    })
                });
                
                const data = await resp.json();
                
                if (!data.ok) {
                    alert('Error: ' + (data.error || 'Failed to update'));
                }
                
                // Show/hide time optimization controls
                const controls = document.getElementById(`time_opt_controls_${workflowId}`);
                if (controls) {
                    controls.style.display = enabled ? 'block' : 'none';
                }
                
            } catch (error) {
                alert('Error: ' + error.message);
            }
        },
        
        /**
         * Update preferred send hour
         */
        updateSendHour: async function(entityType, entityId, workflowId, hour) {
            try {
                const resp = await fetch('/api/email-workflow-api.php?action=update_entity_settings', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        entity_type: entityType,
                        entity_id: entityId,
                        workflow_id: workflowId,
                        preferred_send_hour: parseInt(hour)
                    })
                });
                
                const data = await resp.json();
                
                if (!data.ok) {
                    alert('Error: ' + (data.error || 'Failed to update'));
                }
                
            } catch (error) {
                alert('Error: ' + error.message);
            }
        },
        
        /**
         * Cancel a scheduled follow-up
         */
        cancelFollowup: async function(followupId) {
            if (!confirm('Are you sure you want to cancel this scheduled follow-up?')) {
                return;
            }
            
            try {
                const resp = await fetch('/api/email-workflow-api.php?action=cancel_followup', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: followupId,
                        reason: 'Cancelled by agent'
                    })
                });
                
                const data = await resp.json();
                
                if (!data.ok) {
                    alert('Error: ' + (data.error || 'Failed to cancel'));
                    return;
                }
                
                // Reload scheduled list
                const modal = document.getElementById('workflowConfigModal');
                if (modal) {
                    this.loadScheduledForEntity(modal.dataset.entityType, modal.dataset.entityId);
                }
                
            } catch (error) {
                alert('Error: ' + error.message);
            }
        },
        
        /**
         * Close the modal
         */
        close: function() {
            const modal = document.getElementById('workflowConfigModal');
            if (modal) {
                modal.remove();
            }
        }
    };
    
})();





