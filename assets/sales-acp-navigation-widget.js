/**
 * Sales ACP Navigation Widget
 * Context-aware floating navigation for quick access
 */

(function() {
    'use strict';
    
    // Create floating widget
    function createNavigationWidget() {
        const widget = document.createElement('div');
        widget.id = 'sacp-nav-widget';
        widget.className = 'sacp-nav-widget';
        widget.innerHTML = `
            <div class="sacp-widget-toggle" onclick="toggleWidget()">
                <span class="sacp-widget-icon">🧭</span>
                <span class="sacp-widget-label">Navigate</span>
            </div>
            <div class="sacp-widget-menu" id="widgetMenu">
                <div class="sacp-widget-section">
                    <div class="sacp-widget-title">Quick Jump</div>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/index.php'" class="sacp-widget-link">
                        <span>🏠</span> Dashboard
                    </a>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/leads.php?filter=hot'" class="sacp-widget-link">
                        <span>🔥</span> Hot Leads
                    </a>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/tasks.php?filter=overdue'" class="sacp-widget-link">
                        <span>⚠️</span> Overdue Tasks
                    </a>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/dropoffs.php?filter=pending'" class="sacp-widget-link">
                        <span>⏰</span> Pending Follow-ups
                    </a>
                </div>
                <div class="sacp-widget-divider"></div>
                <div class="sacp-widget-section">
                    <div class="sacp-widget-title">Create New</div>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/leads.php?action=add'" class="sacp-widget-link">
                        <span>➕</span> New Lead
                    </a>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/invoice.php'" class="sacp-widget-link">
                        <span>📄</span> New Invoice
                    </a>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/tasks.php?action=add'" class="sacp-widget-link">
                        <span>✅</span> New Task
                    </a>
                    <a href="javascript:void(0)" onclick="location.href=(window.__BASE_PREFIX||'')+'/onboarding/sales-agent/dropoff.php'" class="sacp-widget-link">
                        <span>🧪</span> Record Demo
                    </a>
                </div>
                <div class="sacp-widget-divider"></div>
                <div class="sacp-widget-search">
                    <input type="text" id="widgetSearch" placeholder="Quick search..." onkeyup="handleQuickSearch(event)">
                </div>
            </div>
        `;
        
        // Add styles
        const styles = `
            <style>
            .sacp-nav-widget {
                position: fixed;
                bottom: 80px;
                right: 20px;
                z-index: 1000;
            }
            
            .sacp-widget-toggle {
                background: linear-gradient(135deg, var(--acp-gold, #FFD700), var(--acp-gold-light, #ffed4e));
                color: var(--acp-carbon, #1f2937);
                padding: 12px 20px;
                border-radius: 24px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                transition: all 300ms ease;
            }
            
            .sacp-widget-toggle:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            }
            
            .sacp-widget-icon {
                font-size: 20px;
                animation: rotate 4s linear infinite;
            }
            
            @keyframes rotate {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .sacp-widget-menu {
                position: absolute;
                bottom: 60px;
                right: 0;
                background: white;
                border: 1px solid var(--acp-platinum, #e5e7eb);
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
                padding: 12px;
                width: 250px;
                display: none;
                max-height: 500px;
                overflow-y: auto;
            }
            
            .sacp-widget-menu.show {
                display: block;
                animation: slideUp 300ms ease;
            }
            
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .sacp-widget-section {
                margin-bottom: 8px;
            }
            
            .sacp-widget-title {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: var(--acp-graphite, #6b7280);
                padding: 8px 8px 4px;
            }
            
            .sacp-widget-link {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 12px;
                color: var(--acp-carbon, #1f2937);
                text-decoration: none;
                border-radius: 8px;
                font-size: 13px;
                transition: all 250ms ease;
            }
            
            .sacp-widget-link:hover {
                background: var(--acp-gold-light, #ffed4e);
            }
            
            .sacp-widget-divider {
                height: 1px;
                background: var(--acp-platinum, #e5e7eb);
                margin: 8px 0;
            }
            
            .sacp-widget-search input {
                width: 100%;
                padding: 8px 12px;
                border: 2px solid var(--acp-platinum, #e5e7eb);
                border-radius: 8px;
                font-size: 13px;
                outline: none;
                transition: all 250ms ease;
            }
            
            .sacp-widget-search input:focus {
                border-color: var(--acp-gold, #FFD700);
                box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
            }
            
            @media (max-width: 768px) {
                .sacp-nav-widget {
                    bottom: 70px;
                    right: 10px;
                }
                
                .sacp-widget-menu {
                    right: -10px;
                    width: calc(100vw - 40px);
                    max-width: 300px;
                }
            }
            </style>
        `;
        
        // Add styles to head
        document.head.insertAdjacentHTML('beforeend', styles);
        
        // Add widget to body
        document.body.appendChild(widget);
    }
    
    // Toggle widget menu
    window.toggleWidget = function() {
        const menu = document.getElementById('widgetMenu');
        menu.classList.toggle('show');
        
        // Focus search when opened
        if (menu.classList.contains('show')) {
            setTimeout(() => {
                document.getElementById('widgetSearch').focus();
            }, 100);
        }
    };
    
    // Handle quick search
    window.handleQuickSearch = function(event) {
        const query = event.target.value.toLowerCase();
        
        if (event.key === 'Enter' && query) {
            // Navigate to search results
            if (query.includes('lead')) {
                window.location.href = (window.__BASE_PREFIX||'') + '/onboarding/sales-agent/leads.php';
            } else if (query.includes('account')) {
                window.location.href = (window.__BASE_PREFIX||'') + '/onboarding/sales-agent/accounts.php';
            } else if (query.includes('invoice')) {
                window.location.href = (window.__BASE_PREFIX||'') + '/onboarding/sales-agent/invoices.php';
            } else if (query.includes('task')) {
                window.location.href = (window.__BASE_PREFIX||'') + '/onboarding/sales-agent/tasks.php';
            } else if (query.includes('call')) {
                window.location.href = (window.__BASE_PREFIX||'') + '/onboarding/sales-agent/calls.php';
            } else {
                // Default to leads search
                window.location.href = (window.__BASE_PREFIX||'') + '/onboarding/sales-agent/leads.php?q=' + encodeURIComponent(query);
            }
        }
    };
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K to open widget
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            toggleWidget();
        }
        
        // Escape to close widget
        if (e.key === 'Escape') {
            const menu = document.getElementById('widgetMenu');
            if (menu && menu.classList.contains('show')) {
                menu.classList.remove('show');
            }
        }
    });
    
    // Click outside to close
    document.addEventListener('click', function(e) {
        const widget = document.getElementById('sacp-nav-widget');
        const menu = document.getElementById('widgetMenu');
        
        if (widget && menu && !widget.contains(e.target) && menu.classList.contains('show')) {
            menu.classList.remove('show');
        }
    });
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        createNavigationWidget();
        
        // Show tooltip on first visit
        const hasSeenWidget = localStorage.getItem('sacp_widget_seen');
        if (!hasSeenWidget) {
            setTimeout(() => {
                const toggle = document.querySelector('.sacp-widget-toggle');
                if (toggle) {
                    toggle.style.animation = 'pulse 2s ease-in-out 3';
                    localStorage.setItem('sacp_widget_seen', 'true');
                }
            }, 2000);
        }
    });
    
})();
