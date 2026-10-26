/**
 * API Restriction Modal
 * Shows informative modal when user is blocked from using APIs
 * 
 * @author Product Development Team
 * @date 2025-10-20
 */

window.APIRestrictionModal = (function() {
    'use strict';
    
    function showRestrictionModal(restrictionType, data) {
        const stats = data.stats || {};
        const needsCount = data.needs_details_count || 0;
        const threshold = data.threshold || (restrictionType === 'google_api' ? 80 : 70);
        
        // Handle completion rate properly - avoid division by zero
        const totalLeads = parseInt(stats.total_leads || 0);
        const detailedLeads = parseInt(stats.detailed_leads || 0);
        let completionRate = 0;
        let completionMessage = '';
        
        if (totalLeads === 0) {
            completionRate = 0;
            completionMessage = 'No leads yet - Get started!';
        } else if (totalLeads < 10) {
            // Less than 10 leads - show actual percentage but note exemption
            completionRate = Math.round((detailedLeads / totalLeads) * 100);
            completionMessage = `${totalLeads} lead${totalLeads !== 1 ? 's' : ''} (First 10 exempt)`;
        } else {
            completionRate = parseFloat(stats.detail_completion_rate || 0);
            completionMessage = 'Current completion rate';
        }
        
        // Determine API name and icon
        const apiInfo = {
            google_api: {
                name: 'Google Places Search',
                icon: '🗺️',
                description: 'Find nearby retail partners, product bars, and potential leads'
            },
            ai_email: {
                name: 'AI Email Composer',
                icon: '🤖',
                description: 'Generate professional sales emails with AI assistance'
            }
        };
        
        const api = apiInfo[restrictionType] || apiInfo.google_api;
        
        // Determine circle color
        let circleColor = '#10b981'; // Green
        if (completionRate < 60) {
            circleColor = '#ef4444'; // Red
        } else if (completionRate < 70) {
            circleColor = '#f59e0b'; // Orange
        } else if (completionRate < 80) {
            circleColor = '#eab308'; // Yellow
        }
        
        // Calculate circle progress
        const circumference = 2 * Math.PI * 50;
        const offset = circumference * (1 - completionRate / 100);
        
        // Build modal HTML
        const modalHTML = `
            <div class="api-restriction-modal-overlay" id="apiRestrictionModal" onclick="APIRestrictionModal.close(event)">
                <div class="api-restriction-modal" onclick="event.stopPropagation()">
                    <div class="api-restriction-modal-header">
                        <div class="api-restriction-modal-icon">${api.icon}</div>
                        <h2 class="api-restriction-modal-title">${api.name} Locked</h2>
                        <p class="api-restriction-modal-subtitle">${api.description}</p>
                    </div>
                    
                    <div class="api-restriction-modal-body">
                        ${totalLeads < 10 ? `
                            <div class="api-restriction-reason" style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border-left: 4px solid #059669;">
                                <h3 class="api-restriction-reason-title" style="color: #065f46;">✅ Learning Phase Active</h3>
                                <p class="api-restriction-reason-text" style="color: #064e3b;">
                                    You're in your first <strong>10 leads</strong> - this feature is currently unlocked!
                                    Keep adding detailed leads to maintain access.
                                </p>
                            </div>
                        ` : `
                            <div class="api-restriction-reason">
                                <h3 class="api-restriction-reason-title">⚠️ Lead Detail Requirement</h3>
                                <p class="api-restriction-reason-text">
                                    To use this feature, you need to maintain at least <strong>${threshold}%</strong> 
                                    of your leads with complete details (email + phone).
                                </p>
                            </div>
                        `}
                        
                        <div class="api-restriction-meter-mini">
                            <div class="api-restriction-meter-circle-container">
                                <svg class="api-restriction-meter-circle" viewBox="0 0 120 120">
                                    <circle class="api-restriction-meter-circle-bg" cx="60" cy="60" r="50"></circle>
                                    <circle 
                                        class="api-restriction-meter-circle-progress" 
                                        cx="60" 
                                        cy="60" 
                                        r="50"
                                        style="stroke: ${circleColor}; stroke-dasharray: ${circumference}; stroke-dashoffset: ${offset};"
                                    ></circle>
                                </svg>
                                <div class="api-restriction-meter-text-overlay">
                                    <div class="api-restriction-meter-percentage" style="color: ${circleColor};">
                                        ${Math.round(completionRate)}%
                                    </div>
                                    <div class="api-restriction-meter-label" style="font-size: 11px;">${completionMessage}</div>
                                </div>
                            </div>
                            
                            ${totalLeads < 10 ? `
                                <div class="api-restriction-needs-badge" style="background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;">
                                    ${10 - totalLeads} more ${10 - totalLeads !== 1 ? 'leads' : 'lead'} until requirement kicks in
                                </div>
                            ` : needsCount > 0 ? `
                                <div class="api-restriction-needs-badge">
                                    Complete ${needsCount} more lead${needsCount !== 1 ? 's' : ''} to unlock
                                </div>
                            ` : ''}
                        </div>
                        
                        <div class="api-restriction-stats">
                            <div class="api-restriction-stat">
                                <div class="api-restriction-stat-label">Total Leads</div>
                                <div class="api-restriction-stat-value">${stats.total_leads || 0}</div>
                            </div>
                            <div class="api-restriction-stat">
                                <div class="api-restriction-stat-label">Detailed</div>
                                <div class="api-restriction-stat-value success">${stats.detailed_leads || 0}</div>
                            </div>
                            <div class="api-restriction-stat">
                                <div class="api-restriction-stat-label">Incomplete</div>
                                <div class="api-restriction-stat-value error">${stats.incomplete_leads || 0}</div>
                            </div>
                            <div class="api-restriction-stat">
                                <div class="api-restriction-stat-label">Need</div>
                                <div class="api-restriction-stat-value error">${threshold}%</div>
                            </div>
                        </div>
                        
                        ${totalLeads < 10 ? `
                            <div class="api-restriction-instructions" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border-left: 4px solid #f59e0b;">
                                <h3 class="api-restriction-instructions-title" style="color: #92400e;">
                                    🎯 Your First 10 Leads - Keep Access
                                </h3>
                                <p style="color: #78350f; margin-bottom: 12px;">
                                    You have API access while building your pipeline. After your first 10 leads, 
                                    you'll need to maintain <strong>${threshold}% detailed leads</strong> to keep access.
                                </p>
                                <ul class="api-restriction-instructions-list" style="color: #92400e;">
                                    <li>Add <strong>email</strong> and <strong>phone</strong> to each new lead as you create them</li>
                                    <li>Current progress: <strong>${detailedLeads} of ${totalLeads}</strong> leads have complete details</li>
                                    <li>Target: Keep <strong>${Math.ceil(totalLeads * (threshold / 100))} of ${totalLeads}</strong> detailed</li>
                                    <li>Build good habits now - it gets easier!</li>
                                </ul>
                            </div>
                        ` : `
                            <div class="api-restriction-instructions">
                                <h3 class="api-restriction-instructions-title">
                                    📋 How to Complete Leads
                                </h3>
                                <ul class="api-restriction-instructions-list">
                                    <li>Open incomplete leads and add <strong>email</strong> and <strong>phone</strong> information</li>
                                    <li>If contact info is unavailable, mark it as "Not Available"</li>
                                    <li>Both email and phone must be present (or marked unavailable)</li>
                                    <li>Your completion rate updates instantly</li>
                                </ul>
                            </div>
                        `}
                    </div>
                    
                    <div class="api-restriction-modal-footer">
                        <button class="api-restriction-btn api-restriction-btn-secondary" onclick="APIRestrictionModal.close()">
                            Cancel
                        </button>
                        <button class="api-restriction-btn api-restriction-btn-primary" onclick="APIRestrictionModal.goToLeads()">
                            📝 Complete Leads
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if present
        const existingModal = document.getElementById('apiRestrictionModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Insert modal
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Prevent body scroll
        document.body.style.overflow = 'hidden';
    }
    
    function close(event) {
        // Only close if clicking overlay, not modal content
        if (event && event.target.id !== 'apiRestrictionModal') {
            return;
        }
        
        const modal = document.getElementById('apiRestrictionModal');
        if (modal) {
            modal.style.opacity = '0';
            setTimeout(() => {
                modal.remove();
                document.body.style.overflow = '';
            }, 200);
        }
    }
    
    function goToLeads() {
        const basePath = window.__BASE_PREFIX || '';
        window.location.href = basePath + '/onboarding/sales-agent/leads.php?filter=incomplete';
    }
    
    // Public API
    return {
        show: showRestrictionModal,
        close: close,
        goToLeads: goToLeads
    };
})();

// Also expose as global function for convenience
window.showRestrictionModal = window.APIRestrictionModal.show;


