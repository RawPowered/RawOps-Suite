/**
 * SACP Dashboard Enhanced Views
 * JavaScript for tabbed interface, Kanban view, geographic breakdown, and maps
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

(function() {
    'use strict';
    
    // ========================================
    // Tab Switching Logic
    // ========================================
    
    const sacp1015_tabs = {
        currentTab: 'leads',
        tabsLoaded: {
            'leads': true, // Default tab already loaded
            'accounts': true,
            'activity': true,
            'status-kanban': false,
            'kanban': false,
            'geographic': false
        },
        
        init() {
            // Desktop tab buttons
            const tabButtons = document.querySelectorAll('.sacp1015_tab-btn');
            tabButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const tabName = btn.getAttribute('data-tab');
                    this.switchTab(tabName);
                });
            });
            
            // Mobile tab select
            const tabSelect = document.querySelector('.sacp1015_tab-select');
            if (tabSelect) {
                tabSelect.addEventListener('change', (e) => {
                    this.switchTab(e.target.value);
                });
                
                // Update select value on tab button clicks
                document.querySelectorAll('.sacp1015_tab-btn').forEach(btn => {
                    const originalHandler = btn.onclick;
                    btn.addEventListener('click', () => {
                        const tabName = btn.getAttribute('data-tab');
                        if (tabSelect) {
                            tabSelect.value = tabName;
                        }
                    });
                });
            }
            
            // Restore last active tab from localStorage
            const savedTab = localStorage.getItem('sacp1015_active_tab');
            if (savedTab && this.tabsLoaded.hasOwnProperty(savedTab)) {
                this.switchTab(savedTab);
            }
        },
        
        switchTab(tabName) {
            if (!this.tabsLoaded.hasOwnProperty(tabName)) return;
            
            // Update active state
            this.currentTab = tabName;
            
            // Update tab buttons
            document.querySelectorAll('.sacp1015_tab-btn').forEach(btn => {
                if (btn.getAttribute('data-tab') === tabName) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // Update tab panes
            document.querySelectorAll('.sacp1015_tab-pane').forEach(pane => {
                if (pane.id === `sacp1015_tab-${tabName}`) {
                    pane.classList.add('active');
                } else {
                    pane.classList.remove('active');
                }
            });
            
            // Update mobile select
            const tabSelect = document.querySelector('.sacp1015_tab-select');
            if (tabSelect) {
                tabSelect.value = tabName;
            }
            
            // Save to localStorage
            localStorage.setItem('sacp1015_active_tab', tabName);
            
            // Lazy load tab content if not yet loaded
            if (!this.tabsLoaded[tabName]) {
                this.loadTabContent(tabName);
            }
        },
        
        async loadTabContent(tabName) {
            const pane = document.getElementById(`sacp1015_tab-${tabName}`);
            if (!pane) return;
            
            pane.innerHTML = '<div style="text-align:center;padding:48px;"><div class="sacp1015_loading-spinner" style="margin:0 auto;"></div><p style="color:#6b7280;margin-top:16px;">Loading...</p></div>';
            
            try {
                if (tabName === 'status-kanban') {
                    await this.loadStatusKanbanData(pane);
                } else if (tabName === 'kanban') {
                    await this.loadKanbanData(pane);
                } else if (tabName === 'geographic') {
                    await this.loadGeographicData(pane);
                }
                
                this.tabsLoaded[tabName] = true;
            } catch (error) {
                console.error(`Error loading ${tabName} tab:`, error);
                pane.innerHTML = `<div class="sacp1015_error">Error loading content. Please refresh the page.</div>`;
            }
        },
        
        async loadStatusKanbanData(pane) {
            try {
                const response = await fetch('/api/sacp1015-status-kanban-data.php');
                
                if (!response.ok) {
                    // Try to get error details from response
                    let errorDetails = `HTTP ${response.status}: ${response.statusText}`;
                    try {
                        const errorData = await response.json();
                        console.error('Status Kanban API Error:', errorData);
                        if (errorData.error) {
                            errorDetails = errorData.error;
                            if (errorData.file && errorData.line) {
                                errorDetails += ` (${errorData.file}:${errorData.line})`;
                            }
                        }
                    } catch (e) {
                        // Response wasn't JSON
                        console.error('Could not parse error response');
                    }
                    throw new Error(errorDetails);
                }
                
                const data = await response.json();
                console.log('Status Kanban Data:', data); // Debug log
                
                if (data.success) {
                    pane.innerHTML = sacp1015_status_kanban.render(data.kanban_data, data.available_tags, data.meta);
                    sacp1015_status_kanban.initFilters(data.available_tags);
                } else {
                    let errorMsg = data.error || 'Failed to load Status Kanban data';
                    if (data.file && data.line) {
                        errorMsg += ` (${data.file}:${data.line})`;
                    }
                    throw new Error(errorMsg);
                }
            } catch (error) {
                console.error('Status Kanban Error:', error);
                pane.innerHTML = `
                    <div class="sacp1015_error" style="padding:24px;background:#fee;border:1px solid #fcc;border-radius:8px;margin:16px;">
                        <strong style="color:#c00;">Error Loading Status Kanban</strong><br>
                        <div style="font-size:13px;margin-top:12px;color:#666;font-family:monospace;background:#fff;padding:12px;border-radius:4px;overflow-x:auto;">${error.message}</div>
                        <button onclick="location.reload()" class="btn" style="margin-top:12px;">Refresh Page</button>
                    </div>
                `;
                throw error;
            }
        },
        
        async loadKanbanData(pane) {
            const response = await fetch('/api/sacp1015-kanban-data.php');
            const data = await response.json();
            
            if (data.success) {
                pane.innerHTML = sacp1015_kanban.render(data.kanban_data, data.meta);
                sacp1015_kanban.initFilters();
            } else {
                throw new Error(data.error || 'Failed to load Kanban data');
            }
        },
        
        async loadGeographicData(pane) {
            try {
                const response = await fetch('/api/sacp1015-geographic-data.php');
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    pane.innerHTML = sacp1015_geographic.render(data.geographic_data, data.map_pins, data.stages);
                    sacp1015_geographic.initAccordions();
                    sacp1015_geographic.cacheMapPins(data.map_pins);
                } else {
                    throw new Error(data.message || data.error || 'Failed to load geographic data');
                }
            } catch (error) {
                console.error('Geographic Data Error:', error);
                pane.innerHTML = `
                    <div class="sacp1015_error">
                        <strong>Error Loading Geographic View</strong><br>
                        <span style="font-size:13px;">${error.message}</span><br>
                        <button onclick="location.reload()" class="btn" style="margin-top:12px;">Refresh Page</button>
                    </div>
                `;
                throw error;
            }
        }
    };
    
    // ========================================
    // Kanban View
    // ========================================
    
    const sacp1015_kanban = {
        currentData: null,
        currentFilters: {},
        
        render(kanbanData, meta) {
            this.currentData = kanbanData;
            
            let html = `
                <div class="sacp1015_kanban-filters">
                    <select id="sacp1015_kanban-tag-filter" class="input" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value="">All Tags</option>
                    </select>
                    <select id="sacp1015_kanban-stage-filter" class="input" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value="">All Stages</option>
                    </select>
                    <input type="date" id="sacp1015_kanban-date-from" class="input" placeholder="From Date" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                    <input type="date" id="sacp1015_kanban-date-to" class="input" placeholder="To Date" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                    <button onclick="sacp1015_kanban.applyFilters()" class="btn" style="padding:8px 16px;">Apply</button>
                    <button onclick="sacp1015_kanban.clearFilters()" class="btn secondary" style="padding:8px 16px;">Clear</button>
                </div>
                
                <div class="sacp1015_kanban-grid">
            `;
            
            if (!kanbanData || kanbanData.length === 0) {
                html += `<div class="sacp1015_kanban-empty">No leads found. Try adjusting your filters.</div>`;
            } else {
                kanbanData.forEach(column => {
                    html += this.renderColumn(column);
                });
            }
            
            html += '</div>';
            
            return html;
        },
        
        renderColumn(column) {
            const tag = column.tag;
            const stages = column.stages;
            
            let html = `
                <div class="sacp1015_kanban-column" style="border-top:4px solid ${tag.tag_color}">
                    <div class="sacp1015_kanban-header">
                        <div class="sacp1015_kanban-title">
                            <span style="font-size:20px;">${tag.tag_icon}</span>
                            <span>${tag.tag_name}</span>
                        </div>
                        <div class="sacp1015_kanban-count">${column.total_count}</div>
                    </div>
                    <div class="sacp1015_kanban-stages">
            `;
            
            // Render stages with leads
            for (const [stageKey, stageData] of Object.entries(stages)) {
                if (stageData.count > 0) {
                    html += `
                        <div class="sacp1015_kanban-stage-group" style="border-left-color:${stageData.stage.stage_color}">
                            <div class="sacp1015_kanban-stage-label" style="color:${stageData.stage.stage_color}">
                                ${stageData.stage.stage_icon} ${stageData.stage.stage_label} (${stageData.count})
                            </div>
                            <div class="sacp1015_kanban-leads">
                    `;
                    
                    stageData.leads.forEach(lead => {
                        html += this.renderLeadCard(lead);
                    });
                    
                    html += `
                            </div>
                        </div>
                    `;
                }
            }
            
            html += `
                    </div>
                </div>
            `;
            
            return html;
        },
        
        renderLeadCard(lead) {
            return `
                <div class="sacp1015_kanban-card" onclick="window.location.href='leads.php?id=${lead.id}'">
                    <div class="sacp1015_kanban-card-header">${lead.company_name || 'Store'}</div>
                    <div class="sacp1015_kanban-card-meta">
                        <span>📍 ${lead.location || 'No location'}</span>
                        <span>📞 ${lead.call_count} calls</span>
                        <span>⏱️ ${lead.days_old}d</span>
                    </div>
                </div>
            `;
        },
        
        initFilters() {
            // Restore saved filters
            const savedFilters = localStorage.getItem('sacp1015_kanban_filters');
            if (savedFilters) {
                try {
                    this.currentFilters = JSON.parse(savedFilters);
                    // Apply saved filter values to inputs
                    if (this.currentFilters.date_from) {
                        document.getElementById('sacp1015_kanban-date-from').value = this.currentFilters.date_from;
                    }
                    if (this.currentFilters.date_to) {
                        document.getElementById('sacp1015_kanban-date-to').value = this.currentFilters.date_to;
                    }
                } catch (e) {
                    console.error('Error loading saved filters:', e);
                }
            }
        },
        
        async applyFilters() {
            const tagFilter = document.getElementById('sacp1015_kanban-tag-filter').value;
            const stageFilter = document.getElementById('sacp1015_kanban-stage-filter').value;
            const dateFrom = document.getElementById('sacp1015_kanban-date-from').value;
            const dateTo = document.getElementById('sacp1015_kanban-date-to').value;
            
            this.currentFilters = { tag: tagFilter, stage: stageFilter, date_from: dateFrom, date_to: dateTo };
            localStorage.setItem('sacp1015_kanban_filters', JSON.stringify(this.currentFilters));
            
            const params = new URLSearchParams();
            if (tagFilter) params.append('tags', tagFilter);
            if (stageFilter) params.append('stages', stageFilter);
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            
            const pane = document.getElementById('sacp1015_tab-kanban');
            pane.classList.add('sacp1015_loading');
            
            try {
                const response = await fetch('/api/sacp1015-kanban-data.php?' + params.toString());
                const data = await response.json();
                
                if (data.success) {
                    pane.innerHTML = this.render(data.kanban_data, data.meta);
                    this.initFilters();
                }
            } catch (error) {
                console.error('Error applying filters:', error);
            } finally {
                pane.classList.remove('sacp1015_loading');
            }
        },
        
        clearFilters() {
            document.getElementById('sacp1015_kanban-tag-filter').value = '';
            document.getElementById('sacp1015_kanban-stage-filter').value = '';
            document.getElementById('sacp1015_kanban-date-from').value = '';
            document.getElementById('sacp1015_kanban-date-to').value = '';
            localStorage.removeItem('sacp1015_kanban_filters');
            this.applyFilters();
        }
    };
    
    // ========================================
    // Status Kanban View (Inverse of Tag Kanban)
    // ========================================
    
    const sacp1015_status_kanban = {
        currentData: null,
        currentFilters: {},
        availableTags: [],
        
        render(kanbanData, availableTags, meta) {
            try {
                this.currentData = kanbanData || [];
                this.availableTags = availableTags || [];
                
                let html = `
                    <div class="sacp1015_kanban-filters">
                        <select id="sacp1015_status-kanban-tag-filter" class="input" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;" multiple size="1">
                            <option value="">All Tags (View All)</option>
                        </select>
                        <input type="date" id="sacp1015_status-kanban-date-from" class="input" placeholder="From Date" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                        <input type="date" id="sacp1015_status-kanban-date-to" class="input" placeholder="To Date" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                        <button onclick="sacp1015_status_kanban.applyFilters()" class="btn" style="padding:8px 16px;">Apply</button>
                        <button onclick="sacp1015_status_kanban.clearFilters()" class="btn secondary" style="padding:8px 16px;">Clear</button>
                    </div>
                    
                    <div class="sacp1015_kanban-grid">
                `;
                
                if (!kanbanData || kanbanData.length === 0) {
                    html += `<div class="sacp1015_kanban-empty">No leads found. Try adjusting your filters.</div>`;
                } else {
                    kanbanData.forEach(column => {
                        const columnHtml = this.renderColumn(column);
                        if (columnHtml) {
                            html += columnHtml;
                        }
                    });
                }
                
                html += '</div>';
                
                return html;
            } catch (error) {
                console.error('Error rendering Status Kanban:', error);
                return `<div class="sacp1015_error">Error rendering view: ${error.message}</div>`;
            }
        },
        
        renderColumn(column) {
            if (!column || !column.stage) {
                console.error('Invalid column data:', column);
                return '';
            }
            
            const stage = column.stage;
            const tagGroups = column.tag_groups || {};
            
            let html = `
                <div class="sacp1015_kanban-column" style="border-top:4px solid ${stage.stage_color || '#6b7280'}">
                    <div class="sacp1015_kanban-header">
                        <div class="sacp1015_kanban-title">
                            <span style="font-size:20px;">${stage.stage_icon || '📋'}</span>
                            <span>${stage.stage_label || 'Unknown'}</span>
                        </div>
                        <div class="sacp1015_kanban-count">${column.total_count || 0}</div>
                    </div>
                    <div class="sacp1015_kanban-stages">
            `;
            
            // If tag groups exist (filtered view), show them
            if (tagGroups && Object.keys(tagGroups).length > 0) {
                for (const [groupKey, groupData] of Object.entries(tagGroups)) {
                    if (groupData && groupData.count > 0 && groupData.leads && groupData.leads.length > 0) {
                        // Show tag group header only if we have tag filtering
                        if (groupKey !== 'all' && groupData.tag) {
                            html += `
                                <div class="sacp1015_status-kanban-tag-group" style="border-left-color:${groupData.tag.tag_color || '#d1d5db'}">
                                    <div class="sacp1015_kanban-stage-label" style="color:${groupData.tag.tag_color || '#6b7280'}">
                                        ${groupData.tag.tag_icon || '📌'} ${groupData.tag.tag_name} (${groupData.count})
                                    </div>
                                    <div class="sacp1015_kanban-leads">
                            `;
                        } else {
                            // "all" group - no header, just leads
                            html += `<div class="sacp1015_kanban-leads">`;
                        }
                        
                        groupData.leads.forEach(lead => {
                            html += this.renderLeadCard(lead);
                        });
                        
                        if (groupKey !== 'all' && groupData.tag) {
                            html += `
                                    </div>
                                </div>
                            `;
                        } else {
                            html += `</div>`;
                        }
                    }
                }
            }
            
            html += `
                    </div>
                </div>
            `;
            
            return html;
        },
        
        renderLeadCard(lead) {
            if (!lead || !lead.id) {
                console.error('Invalid lead data:', lead);
                return '';
            }
            
            const isConverted = lead.is_converted || false;
            const targetUrl = isConverted && lead.account_id 
                ? `accounts.php?id=${lead.account_id}` 
                : `leads.php?id=${lead.id}`;
            const cardClass = isConverted ? 'sacp1015_kanban-card sacp1015_kanban-card-converted' : 'sacp1015_kanban-card';
            const accountBadge = isConverted 
                ? `<div class="sacp1015_account-badge">🎉 Active Account</div>` 
                : '';
            
            return `
                <div class="${cardClass}" onclick="window.location.href='${targetUrl}'">
                    <div class="sacp1015_kanban-card-header">
                        ${lead.company_name || lead.account_name || 'Store'}
                        ${isConverted ? ' <span style="color:#10b981;">✓</span>' : ''}
                    </div>
                    ${accountBadge}
                    <div class="sacp1015_kanban-card-meta">
                        <span>📍 ${lead.location || 'No location'}</span>
                        <span>📞 ${lead.call_count || 0} calls</span>
                        <span>⏱️ ${lead.days_old || 0}d</span>
                    </div>
                    ${lead.tag_names ? `<div style="font-size:10px;color:#3b82f6;margin-top:4px;">${lead.tag_names}</div>` : ''}
                </div>
            `;
        },
        
        initFilters(availableTags) {
            this.availableTags = availableTags || this.availableTags;
            
            // Populate tag filter dropdown
            const tagFilter = document.getElementById('sacp1015_status-kanban-tag-filter');
            if (tagFilter && this.availableTags.length > 0) {
                // Add tag options
                this.availableTags.forEach(tag => {
                    const option = document.createElement('option');
                    option.value = tag.tag_slug;
                    option.textContent = `${tag.tag_icon} ${tag.tag_name}`;
                    tagFilter.appendChild(option);
                });
                
                // Add untagged option
                const untaggedOption = document.createElement('option');
                untaggedOption.value = 'untagged';
                untaggedOption.textContent = '❓ Untagged';
                tagFilter.appendChild(untaggedOption);
            }
            
            // Restore saved filters
            const savedFilters = localStorage.getItem('sacp1015_status_kanban_filters');
            if (savedFilters) {
                try {
                    this.currentFilters = JSON.parse(savedFilters);
                    // Apply saved filter values to inputs
                    if (this.currentFilters.tags && tagFilter) {
                        const tagsArray = this.currentFilters.tags.split(',');
                        Array.from(tagFilter.options).forEach(opt => {
                            if (tagsArray.includes(opt.value)) {
                                opt.selected = true;
                            }
                        });
                    }
                    if (this.currentFilters.date_from) {
                        document.getElementById('sacp1015_status-kanban-date-from').value = this.currentFilters.date_from;
                    }
                    if (this.currentFilters.date_to) {
                        document.getElementById('sacp1015_status-kanban-date-to').value = this.currentFilters.date_to;
                    }
                } catch (e) {
                    console.error('Error loading saved filters:', e);
                }
            }
        },
        
        async applyFilters() {
            const tagFilterEl = document.getElementById('sacp1015_status-kanban-tag-filter');
            const dateFrom = document.getElementById('sacp1015_status-kanban-date-from').value;
            const dateTo = document.getElementById('sacp1015_status-kanban-date-to').value;
            
            // Get selected tags (multiple select)
            const selectedTags = Array.from(tagFilterEl.selectedOptions)
                .map(opt => opt.value)
                .filter(val => val !== ''); // Exclude "All Tags" option
            
            const tagFilter = selectedTags.join(',');
            
            this.currentFilters = { tags: tagFilter, date_from: dateFrom, date_to: dateTo };
            localStorage.setItem('sacp1015_status_kanban_filters', JSON.stringify(this.currentFilters));
            
            const params = new URLSearchParams();
            if (tagFilter) params.append('tags', tagFilter);
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            
            const pane = document.getElementById('sacp1015_tab-status-kanban');
            pane.classList.add('sacp1015_loading');
            
            try {
                const response = await fetch('/api/sacp1015-status-kanban-data.php?' + params.toString());
                const data = await response.json();
                
                if (data.success) {
                    pane.innerHTML = this.render(data.kanban_data, data.available_tags, data.meta);
                    this.initFilters(data.available_tags);
                }
            } catch (error) {
                console.error('Error applying filters:', error);
            } finally {
                pane.classList.remove('sacp1015_loading');
            }
        },
        
        clearFilters() {
            const tagFilter = document.getElementById('sacp1015_status-kanban-tag-filter');
            if (tagFilter) {
                Array.from(tagFilter.options).forEach(opt => opt.selected = false);
                tagFilter.selectedIndex = 0; // Select "All Tags"
            }
            document.getElementById('sacp1015_status-kanban-date-from').value = '';
            document.getElementById('sacp1015_status-kanban-date-to').value = '';
            localStorage.removeItem('sacp1015_status_kanban_filters');
            this.applyFilters();
        }
    };
    
    // ========================================
    // Geographic View
    // ========================================
    
    const sacp1015_geographic = {
        cachedMapPins: null,
        map: null,
        markers: [],
        isMapVisible: false,
        
        render(geoData, mapPins, stages) {
            this.cacheMapPins(mapPins);
            
            let html = `
                <div class="sacp1015_geo-controls">
                    <select id="sacp1015_geo-tag-filter" class="input" style="padding:8px 12px;">
                        <option value="">All Tags</option>
                    </select>
                    <select id="sacp1015_geo-stage-filter" class="input" style="padding:8px 12px;">
                        <option value="">All Stages</option>
                    </select>
                    <button onclick="sacp1015_geographic.applyFilters()" class="btn" style="padding:8px 16px;">Apply Filters</button>
                    <button id="sacp1015_map-toggle-btn" onclick="sacp1015_geographic.showMapInline()" class="btn" style="background:#10b981;padding:8px 16px;">
                        🗺️ Show Map
                    </button>
                </div>
                
                <!-- Inline Map (Initially Hidden) -->
                <div id="sacp1015_map-inline-wrapper" style="display:none;margin:20px 0;">
                    <div style="background:#f9fafb;padding:12px;border-radius:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span style="font-size:13px;color:#6b7280;font-weight:600;">
                                ${mapPins && mapPins.length > 0 ? mapPins.length + ' locations plotted' : 'Map ready'}
                            </span>
                            <button onclick="sacp1015_geographic.zoomToAllPins()" class="btn secondary" style="padding:6px 12px;font-size:13px;">Fit All</button>
                            <button onclick="sacp1015_geographic.exportMapLink()" class="btn secondary" style="padding:6px 12px;font-size:13px;">Export</button>
                        </div>
                    </div>
                    <div id="sacp1015_map-inline" class="sacp1015_map-container"></div>
                </div>
                
                <div class="sacp1015_geo-list">
            `;
            
            if (!geoData || geoData.length === 0) {
                html += '<div class="sacp1015_kanban-empty">No leads found in any location.</div>';
            } else {
                geoData.forEach(state => {
                    html += this.renderState(state, stages);
                });
            }
            
            html += '</div>';
            
            return html;
        },
        
        renderState(stateData, stages) {
            const stageFunnel = stages.map(stage => {
                const count = stateData.stage_distribution[stage.stage_key] || 0;
                if (count === 0) return '';
                return `
                    <div class="sacp1015_geo-stage-mini">
                        <span class="sacp1015_geo-stage-mini-count" style="color:${stage.stage_color}">${count}</span>
                        <span class="sacp1015_geo-stage-mini-label">${stage.stage_icon}</span>
                    </div>
                `;
            }).filter(s => s).join('');
            
            let html = `
                <div class="sacp1015_geo-state">
                    <div class="sacp1015_geo-state-header" onclick="sacp1015_geographic.toggleState(this)">
                        <div class="sacp1015_geo-state-title">
                            <span style="font-size:14px;color:#6b7280;margin-right:8px;">▶</span>
                            <span class="sacp1015_geo-state-name">📍 ${stateData.state}</span>
                            <span class="sacp1015_geo-state-count">${stateData.total_count} leads</span>
                        </div>
                        <div class="sacp1015_geo-state-funnel">${stageFunnel}</div>
                    </div>
                    <div class="sacp1015_geo-cities">
            `;
            
            stateData.cities.forEach(city => {
                html += this.renderCityKanban(city, stages);
            });
            
            html += `
                    </div>
                </div>
            `;
            
            return html;
        },
        
        renderCityKanban(cityData, stages) {
            // Kanban-style horizontal layout for each city
            const leadsByStage = cityData.leads_by_stage || {};
            
            let stageColumns = '';
            stages.forEach(stage => {
                const stageData = leadsByStage[stage.stage_key];
                if (!stageData || stageData.leads.length === 0) return; // Skip empty stages
                
                const leads = stageData.leads;
                const leadCards = leads.map(lead => this.renderLeadCard(lead)).join('');
                
                stageColumns += `
                    <div class="sacp1015_geo-stage-column" style="border-top:3px solid ${stage.stage_color}">
                        <div class="sacp1015_geo-stage-header" style="background:${stage.stage_color}10">
                            <span style="font-size:16px;">${stage.stage_icon}</span>
                            <span style="font-weight:700;color:${stage.stage_color}">${stage.stage_label}</span>
                            <span class="sacp1015_geo-count-badge" style="background:${stage.stage_color}">${leads.length}</span>
                        </div>
                        <div class="sacp1015_geo-leads-list">
                            ${leadCards}
                        </div>
                    </div>
                `;
            });
            
            return `
                <div class="sacp1015_geo-city-section">
                    <div class="sacp1015_geo-city-header" onclick="sacp1015_geographic.toggleCity(this)">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:12px;color:#6b7280;">▶</span>
                            <span class="sacp1015_geo-city-name">${cityData.city}</span>
                            <span class="sacp1015_geo-city-count">${cityData.total_count}</span>
                        </div>
                    </div>
                    <div class="sacp1015_geo-city-kanban">
                        ${stageColumns || '<div style="padding:20px;text-align:center;color:#9ca3af;">No leads in this city</div>'}
                    </div>
                </div>
            `;
        },
        
        renderLeadCard(lead) {
            const isComplete = lead.email && lead.phone;
            const priorityColor = {
                'urgent': '#dc2626',
                'high': '#f59e0b',
                'medium': '#6b7280',
                'low': '#9ca3af'
            }[lead.priority] || '#6b7280';
            
            return `
                <div class="sacp1015_geo-lead-card" onclick="window.location.href='leads.php?id=${lead.id}'" style="cursor:pointer;">
                    <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:6px;">
                        <div style="font-weight:600;color:#111827;font-size:13px;flex:1;">${lead.company_name || 'Store'}</div>
                        <div style="width:8px;height:8px;border-radius:50%;background:${priorityColor};" title="${lead.priority} priority"></div>
                    </div>
                    <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">${lead.contact_name}</div>
                    ${lead.tags_display ? `<div style="font-size:10px;color:#3b82f6;margin-bottom:4px;">${lead.tags_display}</div>` : ''}
                    <div style="display:flex;gap:8px;font-size:11px;color:#9ca3af;flex-wrap:wrap;">
                        <span>${isComplete ? '✓' : '⚠️'} ${isComplete ? 'Complete' : 'Incomplete'}</span>
                        <span>📞 ${lead.call_count}</span>
                        <span>⏱️ ${lead.days_old}d</span>
                    </div>
                </div>
            `;
        },
        
        initAccordions() {
            // All states collapsed by default
        },
        
        toggleState(headerEl) {
            const citiesEl = headerEl.nextElementSibling;
            const arrow = headerEl.querySelector('span:first-child');
            
            if (citiesEl.classList.contains('expanded')) {
                citiesEl.classList.remove('expanded');
                if (arrow) arrow.textContent = '▶';
            } else {
                citiesEl.classList.add('expanded');
                if (arrow) arrow.textContent = '▼';
            }
        },
        
        toggleCity(headerEl) {
            const kanbanEl = headerEl.nextElementSibling;
            const arrow = headerEl.querySelector('span:first-child');
            
            if (kanbanEl.classList.contains('expanded')) {
                kanbanEl.classList.remove('expanded');
                if (arrow) arrow.textContent = '▶';
            } else {
                kanbanEl.classList.add('expanded');
                if (arrow) arrow.textContent = '▼';
            }
        },
        
        cacheMapPins(pins) {
            this.cachedMapPins = pins;
        },
        
        async showMapInline() {
            const wrapper = document.getElementById('sacp1015_map-inline-wrapper');
            const apiKey = window.GOOGLE_MAPS_API_KEY || '';
            
            // Toggle if already visible
            if (this.isMapVisible) {
                this.hideMapInline();
                return;
            }
            
            // Check API key
            if (!apiKey || apiKey === '') {
                alert('Google Maps API key is not configured. Please contact your administrator to enable map features.');
                return;
            }
            
            // Show the map wrapper
            wrapper.style.display = 'block';
            this.isMapVisible = true;
            
            // Update button text
            const btn = document.getElementById('sacp1015_map-toggle-btn');
            if (btn) {
                btn.innerHTML = '🗺️ Hide Map';
                btn.style.background = '#ef4444';
            }
            
            // Load Google Maps API if not loaded
            if (typeof google === 'undefined' || !google.maps) {
                document.getElementById('sacp1015_map-inline').innerHTML = '<div class="sacp1015_map-loading">Loading Google Maps API...</div>';
                try {
                    await this.loadGoogleMapsAPI(apiKey);
                    document.getElementById('sacp1015_map-inline').innerHTML = '';
                } catch (error) {
                    console.error('Maps API Error:', error);
                    document.getElementById('sacp1015_map-inline').innerHTML = `
                        <div class="sacp1015_error">
                            <strong>Failed to load Google Maps</strong><br>
                            <span style="font-size:13px;">Error: ${error.message}</span><br>
                            <span style="font-size:12px;">Check console for details.</span>
                        </div>
                    `;
                    return;
                }
            }
            
            // Initialize map (blank or with pins if available)
            this.initMapInline();
        },
        
        hideMapInline() {
            const wrapper = document.getElementById('sacp1015_map-inline-wrapper');
            const btn = document.getElementById('sacp1015_map-toggle-btn');
            
            if (wrapper) {
                wrapper.style.display = 'none';
            }
            if (btn) {
                btn.innerHTML = '🗺️ Show Map';
                btn.style.background = '#10b981';
            }
            
            this.isMapVisible = false;
        },
        
        loadGoogleMapsAPI(apiKey) {
            return new Promise((resolve, reject) => {
                if (typeof google !== 'undefined' && google.maps) {
                    resolve();
                    return;
                }
                
                // Add callback function to window
                window.initGoogleMaps = function() {
                    resolve();
                    delete window.initGoogleMaps;
                };
                
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&callback=initGoogleMaps`;
                script.async = true;
                script.defer = true;
                script.onerror = function() {
                    reject(new Error('Failed to load Google Maps script'));
                };
                document.head.appendChild(script);
            });
        },
        
        initMapInline() {
            const mapEl = document.getElementById('sacp1015_map-inline');
            if (!mapEl) return;
            
            // Clear existing markers
            this.markers.forEach(m => m.setMap(null));
            this.markers = [];
            
            // Create blank map centered on US
            const map = new google.maps.Map(mapEl, {
                zoom: 5,
                center: { lat: 39.8283, lng: -98.5795 }, // Center of US
                mapTypeControl: true,
                fullscreenControl: true,
                streetViewControl: false,
                zoomControl: true,
            });
            
            this.map = map;
            
            // Add info overlay if no pins
            if (!this.cachedMapPins || this.cachedMapPins.length === 0) {
                const infoDiv = document.createElement('div');
                infoDiv.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:24px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.15);text-align:center;pointer-events:none;';
                infoDiv.innerHTML = `
                    <div style="font-size:48px;margin-bottom:12px;">📍</div>
                    <div style="font-weight:600;color:#111827;margin-bottom:4px;">Map Ready</div>
                    <div style="font-size:13px;color:#6b7280;">No leads with addresses found. Pins will appear when leads have complete location data.</div>
                `;
                mapEl.appendChild(infoDiv);
                return;
            }
            
            // Add pins if available
            const bounds = new google.maps.LatLngBounds();
            const geocoder = new google.maps.Geocoder();
            
            // Add markers for each pin
            this.cachedMapPins.forEach(pin => {
                this.geocodeAndAddMarker(geocoder, map, pin, bounds);
            });
            
            // Fit map to show all markers after geocoding completes
            setTimeout(() => {
                if (this.markers.length > 0) {
                    map.fitBounds(bounds);
                } else {
                    // If no markers were successfully geocoded, show message
                    const infoDiv = document.createElement('div');
                    infoDiv.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:24px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.15);text-align:center;pointer-events:none;';
                    infoDiv.innerHTML = `
                        <div style="font-size:48px;margin-bottom:12px;">⚠️</div>
                        <div style="font-weight:600;color:#111827;margin-bottom:4px;">Geocoding Failed</div>
                        <div style="font-size:13px;color:#6b7280;">Unable to plot addresses on map. Check that leads have valid address information.</div>
                    `;
                    mapEl.appendChild(infoDiv);
                }
            }, 2000);
        },
        
        zoomToAllPins() {
            if (!this.map || this.markers.length === 0) return;
            
            const bounds = new google.maps.LatLngBounds();
            this.markers.forEach(marker => {
                bounds.extend(marker.getPosition());
            });
            this.map.fitBounds(bounds);
        },
        
        geocodeAndAddMarker(geocoder, map, pin, bounds) {
            geocoder.geocode({ address: pin.address }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    const position = results[0].geometry.location;
                    const marker = new google.maps.Marker({
                        position: position,
                        map: map,
                        title: pin.company_name,
                        icon: {
                            path: google.maps.SymbolPath.CIRCLE,
                            scale: 8,
                            fillColor: pin.tag_color,
                            fillOpacity: 0.8,
                            strokeColor: '#ffffff',
                            strokeWeight: 2
                        }
                    });
                    
                    const infoWindow = new google.maps.InfoWindow({
                        content: `
                            <div style="padding:8px;">
                                <strong>${pin.company_name}</strong><br>
                                <span style="font-size:12px;color:#6b7280;">${pin.address}</span><br>
                                <span style="font-size:12px;">${pin.tag_icon} ${pin.tag}</span><br>
                                <a href="leads.php?id=${pin.id}" class="btn small" style="margin-top:8px;">View Lead</a>
                            </div>
                        `
                    });
                    
                    marker.addListener('click', () => {
                        infoWindow.open(map, marker);
                    });
                    
                    this.markers.push(marker);
                    bounds.extend(position);
                }
            });
        },
        
        exportMapLink() {
            if (!this.cachedMapPins || this.cachedMapPins.length === 0) {
                alert('No locations to export.');
                return;
            }
            
            // Generate Google Maps URL with all pins
            const addresses = this.cachedMapPins.map(pin => encodeURIComponent(pin.address)).join('|');
            const url = `https://www.google.com/maps/dir/?api=1&destination=${this.cachedMapPins[0].address}&waypoints=${addresses}`;
            
            // Copy to clipboard
            navigator.clipboard.writeText(url).then(() => {
                alert('Map link copied to clipboard!');
            }).catch(() => {
                prompt('Copy this map link:', url);
            });
        },
        
        async applyFilters() {
            // Similar to Kanban filters
            const pane = document.getElementById('sacp1015_tab-geographic');
            pane.classList.add('sacp1015_loading');
            
            try {
                const response = await fetch('/api/sacp1015-geographic-data.php');
                const data = await response.json();
                
                if (data.success) {
                    pane.innerHTML = this.render(data.geographic_data, data.map_pins, data.stages);
                    this.initAccordions();
                    this.cacheMapPins(data.map_pins);
                    
                    // If map was visible, reload it with new pins
                    if (this.isMapVisible) {
                        this.hideMapInline();
                        setTimeout(() => this.showMapInline(), 300);
                    }
                }
            } catch (error) {
                console.error('Error applying filters:', error);
            } finally {
                pane.classList.remove('sacp1015_loading');
            }
        }
    };
    
    // ========================================
    // Collapsible Sections
    // ========================================
    
    const sacp1015_collapsible = {
        init() {
            document.querySelectorAll('.sacp1015_collapsible-toggle').forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const section = this.closest('.sacp1015_collapsible');
                    section.classList.toggle('collapsed');
                    
                    // Save state
                    const sectionId = section.getAttribute('id');
                    if (sectionId) {
                        localStorage.setItem(`sacp1015_collapsed_${sectionId}`, section.classList.contains('collapsed'));
                    }
                });
            });
            
            // Restore saved states
            document.querySelectorAll('.sacp1015_collapsible').forEach(section => {
                const sectionId = section.getAttribute('id');
                if (sectionId) {
                    const saved = localStorage.getItem(`sacp1015_collapsed_${sectionId}`);
                    if (saved === 'true') {
                        section.classList.add('collapsed');
                    }
                }
            });
        }
    };
    
    // ========================================
    // Performance Stats Loading
    // ========================================
    
    const sacp1015_performance = {
        async load() {
            const container = document.getElementById('sacp1015_perf-stats-container');
            if (!container) return;
            
            try {
                const response = await fetch('/api/sacp1015-agent-performance.php');
                const data = await response.json();
                
                if (data.success) {
                    container.innerHTML = this.render(data.stats);
                }
            } catch (error) {
                console.error('Error loading performance stats:', error);
                container.innerHTML = '<div class="sacp1015_error">Error loading performance stats.</div>';
            }
        },
        
        render(stats) {
            // Determine completion rate status
            const detailRate = parseFloat(stats.detail_completion_rate || 0);
            let rateStatus = 'optimal';
            let rateLabel = 'Optimal';
            
            if (detailRate < 60) {
                rateStatus = 'critical';
                rateLabel = 'Critical';
            } else if (detailRate < 70) {
                rateStatus = 'low';
                rateLabel = 'Low';
            } else if (detailRate < 80) {
                rateStatus = 'fair';
                rateLabel = 'Fair';
            }
            
            return `
                <div class="sacp1015_perf-stats">
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-calendar-day sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.leads_today}</div>
                        <div class="sacp1015_perf-label">Today</div>
                    </div>
                    
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-calendar-week sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.leads_week}</div>
                        <div class="sacp1015_perf-label">This Week</div>
                    </div>
                    
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-bolt sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.active_leads}</div>
                        <div class="sacp1015_perf-label">Active</div>
                    </div>
                    
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-chart-line sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.total_leads}</div>
                        <div class="sacp1015_perf-label">Total Leads</div>
                    </div>
                    
                    <div class="sacp1015_perf-card sacp1015_perf-card-success">
                        <i class="fas fa-check-circle sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.detailed_leads}</div>
                        <div class="sacp1015_perf-label">Detailed</div>
                    </div>
                    
                    <div class="sacp1015_perf-card sacp1015_perf-card-warning">
                        <i class="fas fa-exclamation-circle sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.incomplete_leads}</div>
                        <div class="sacp1015_perf-label">Incomplete</div>
                    </div>
                    
                    <div class="sacp1015_perf-card sacp1015_perf-card-muted">
                        <i class="fas fa-envelope sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.missing_email}</div>
                        <div class="sacp1015_perf-label">No Email</div>
                    </div>
                    
                    <div class="sacp1015_perf-card sacp1015_perf-card-muted">
                        <i class="fas fa-phone sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.missing_phone}</div>
                        <div class="sacp1015_perf-label">No Phone</div>
                    </div>
                    
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-trophy sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.conversions_today}</div>
                        <div class="sacp1015_perf-label">Conv. Today</div>
                    </div>
                    
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-award sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.conversions_week}</div>
                        <div class="sacp1015_perf-label">Conv. Week</div>
                    </div>
                    
                    <div class="sacp1015_perf-card">
                        <i class="fas fa-medal sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${stats.conversions_month}</div>
                        <div class="sacp1015_perf-label">Conv. Month</div>
                    </div>
                    
                    <div class="sacp1015_perf-card sacp1015_perf-card-primary sacp1015_perf-card-${rateStatus}">
                        <i class="fas fa-chart-pie sacp1015_perf-icon"></i>
                        <div class="sacp1015_perf-value">${detailRate}%</div>
                        <div class="sacp1015_perf-label">Detail Quality</div>
                        <div class="sacp1015_perf-status">${rateLabel}</div>
                    </div>
                </div>
            `;
        }
    };
    
    // ========================================
    // Initialize on DOM Ready
    // ========================================
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        sacp1015_tabs.init();
        sacp1015_collapsible.init();
        sacp1015_performance.load();
    }
    
    // Export to global scope
    window.sacp1015_tabs = sacp1015_tabs;
    window.sacp1015_kanban = sacp1015_kanban;
    window.sacp1015_status_kanban = sacp1015_status_kanban;
    window.sacp1015_geographic = sacp1015_geographic;
    window.sacp1015_collapsible = sacp1015_collapsible;
    window.sacp1015_performance = sacp1015_performance;
    
})();

