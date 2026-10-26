/**
 * Product Accordion Component
 * Reusable expandable/collapsible sections with state persistence
 * 
 * Usage:
 * ProductAccordion.create('containerId', [
 *   {
 *     id: 'section1',
 *     title: 'Section Title',
 *     content: 'HTML content or function',
 *     expanded: true,
 *     icon: '🔧',
 *     badge: 'NEW',
 *     theme: 'blue' // blue, purple, green, gray
 *   }
 * ], { persist: true, allowMultiple: true });
 */

(function() {
    'use strict';
    
    window.ProductAccordion = {
        instances: {},
        
        /**
         * Create an accordion
         * @param {string} containerId - Container element ID
         * @param {Array} sections - Array of section configs
         * @param {Object} options - Configuration options
         */
        create: function(containerId, sections, options = {}) {
            const defaults = {
                persist: true,
                storageKey: 'product_accordion_' + containerId,
                allowMultiple: true,
                animationDuration: 300,
                showExpandAll: sections.length > 3,
                theme: 'blue'
            };
            
            const config = { ...defaults, ...options };
            const container = document.getElementById(containerId);
            
            if (!container) {
                console.error('ProductAccordion: Container not found:', containerId);
                return null;
            }
            
            // Load saved state
            const savedState = config.persist ? this.loadState(config.storageKey) : {};
            
            // Initialize instance
            const instance = {
                id: containerId,
                config: config,
                sections: sections.map((section, idx) => ({
                    ...section,
                    id: section.id || 'section_' + idx,
                    expanded: savedState[section.id] !== undefined ? savedState[section.id] : (section.expanded || false)
                })),
                container: container
            };
            
            this.instances[containerId] = instance;
            this.render(instance);
            
            return instance;
        },
        
        /**
         * Render accordion HTML
         */
        render: function(instance) {
            const { sections, config, container } = instance;
            
            let html = '<div class="product-accordion" data-instance="' + instance.id + '">';
            
            // Add Expand All / Collapse All buttons
            if (config.showExpandAll) {
                html += `
                    <div class="product-accordion-controls">
                        <button type="button" class="product-accordion-expand-all" onclick="ProductAccordion.expandAll('${instance.id}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                            Expand All
                        </button>
                        <button type="button" class="product-accordion-collapse-all" onclick="ProductAccordion.collapseAll('${instance.id}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="18 15 12 9 6 15"/>
                            </svg>
                            Collapse All
                        </button>
                    </div>
                `;
            }
            
            // Render each section
            sections.forEach(section => {
                const theme = section.theme || config.theme;
                const expandedClass = section.expanded ? 'product-accordion-section-expanded' : '';
                
                html += `
                    <div class="product-accordion-section ${expandedClass}" data-section-id="${section.id}" data-theme="${theme}">
                        <div class="product-accordion-header" 
                             onclick="ProductAccordion.toggle('${instance.id}', '${section.id}')"
                             tabindex="0"
                             role="button"
                             aria-expanded="${section.expanded}"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();ProductAccordion.toggle('${instance.id}','${section.id}');}">
                            <div class="product-accordion-header-content">
                                ${section.icon ? '<span class="product-accordion-icon">' + section.icon + '</span>' : ''}
                                <span class="product-accordion-title">${section.title}</span>
                                ${section.badge ? '<span class="product-accordion-badge">' + section.badge + '</span>' : ''}
                            </div>
                            <svg class="product-accordion-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </div>
                        <div class="product-accordion-content">
                            <div class="product-accordion-content-inner">
                                ${typeof section.content === 'function' ? section.content() : section.content}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
            
            // Initialize heights for animation
            setTimeout(() => this.initializeHeights(instance), 50);
        },
        
        /**
         * Initialize content heights for smooth animation
         */
        initializeHeights: function(instance) {
            const container = instance.container;
            const sections = container.querySelectorAll('.product-accordion-section');
            
            sections.forEach(section => {
                const content = section.querySelector('.product-accordion-content');
                const inner = section.querySelector('.product-accordion-content-inner');
                
                if (section.classList.contains('product-accordion-section-expanded')) {
                    content.style.maxHeight = inner.scrollHeight + 'px';
                } else {
                    content.style.maxHeight = '0px';
                }
            });
        },
        
        /**
         * Toggle a section
         */
        toggle: function(instanceId, sectionId) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            const section = instance.sections.find(s => s.id === sectionId);
            if (!section) return;
            
            const sectionEl = instance.container.querySelector(`[data-section-id="${sectionId}"]`);
            if (!sectionEl) return;
            
            const wasExpanded = section.expanded;
            
            // If allowMultiple is false, collapse all others
            if (!instance.config.allowMultiple && !wasExpanded) {
                instance.sections.forEach(s => {
                    if (s.id !== sectionId && s.expanded) {
                        this.collapse(instanceId, s.id);
                    }
                });
            }
            
            if (wasExpanded) {
                this.collapse(instanceId, sectionId);
            } else {
                this.expand(instanceId, sectionId);
            }
        },
        
        /**
         * Expand a section
         */
        expand: function(instanceId, sectionId) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            const section = instance.sections.find(s => s.id === sectionId);
            if (!section) return;
            
            section.expanded = true;
            
            const sectionEl = instance.container.querySelector(`[data-section-id="${sectionId}"]`);
            if (!sectionEl) return;
            
            const content = sectionEl.querySelector('.product-accordion-content');
            const inner = sectionEl.querySelector('.product-accordion-content-inner');
            const header = sectionEl.querySelector('.product-accordion-header');
            
            sectionEl.classList.add('product-accordion-section-expanded');
            header.setAttribute('aria-expanded', 'true');
            content.style.maxHeight = inner.scrollHeight + 'px';
            
            // Save state
            if (instance.config.persist) {
                this.saveState(instance);
            }
            
            // Trigger callback if provided
            if (section.onExpand) {
                section.onExpand();
            }
        },
        
        /**
         * Collapse a section
         */
        collapse: function(instanceId, sectionId) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            const section = instance.sections.find(s => s.id === sectionId);
            if (!section) return;
            
            section.expanded = false;
            
            const sectionEl = instance.container.querySelector(`[data-section-id="${sectionId}"]`);
            if (!sectionEl) return;
            
            const content = sectionEl.querySelector('.product-accordion-content');
            const header = sectionEl.querySelector('.product-accordion-header');
            
            sectionEl.classList.remove('product-accordion-section-expanded');
            header.setAttribute('aria-expanded', 'false');
            content.style.maxHeight = '0px';
            
            // Save state
            if (instance.config.persist) {
                this.saveState(instance);
            }
            
            // Trigger callback if provided
            if (section.onCollapse) {
                section.onCollapse();
            }
        },
        
        /**
         * Expand all sections
         */
        expandAll: function(instanceId) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            instance.sections.forEach(section => {
                this.expand(instanceId, section.id);
            });
        },
        
        /**
         * Collapse all sections
         */
        collapseAll: function(instanceId) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            instance.sections.forEach(section => {
                this.collapse(instanceId, section.id);
            });
        },
        
        /**
         * Save state to localStorage
         */
        saveState: function(instance) {
            if (!instance.config.persist) return;
            
            const state = {};
            instance.sections.forEach(section => {
                state[section.id] = section.expanded;
            });
            
            try {
                localStorage.setItem(instance.config.storageKey, JSON.stringify(state));
            } catch (e) {
                console.warn('Failed to save accordion state:', e);
            }
        },
        
        /**
         * Load state from localStorage
         */
        loadState: function(storageKey) {
            try {
                const saved = localStorage.getItem(storageKey);
                return saved ? JSON.parse(saved) : {};
            } catch (e) {
                console.warn('Failed to load accordion state:', e);
                return {};
            }
        },
        
        /**
         * Destroy an accordion instance
         */
        destroy: function(instanceId) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            instance.container.innerHTML = '';
            delete this.instances[instanceId];
        },
        
        /**
         * Update a section's content
         */
        updateSection: function(instanceId, sectionId, newContent) {
            const instance = this.instances[instanceId];
            if (!instance) return;
            
            const section = instance.sections.find(s => s.id === sectionId);
            if (!section) return;
            
            section.content = newContent;
            
            const sectionEl = instance.container.querySelector(`[data-section-id="${sectionId}"]`);
            if (!sectionEl) return;
            
            const inner = sectionEl.querySelector('.product-accordion-content-inner');
            if (inner) {
                inner.innerHTML = typeof newContent === 'function' ? newContent() : newContent;
                
                // Update height if expanded
                if (section.expanded) {
                    const content = sectionEl.querySelector('.product-accordion-content');
                    content.style.maxHeight = inner.scrollHeight + 'px';
                }
            }
        }
    };
})();
















