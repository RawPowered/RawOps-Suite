/**
 * Paladin Theme - Responsive Enhancements
 * Mobile interactions and touch optimizations for Sales ACP
 */

(function() {
    'use strict';
    
    // Detect device type
    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    const isTablet = /(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(navigator.userAgent);
    const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    
    // Add device classes to body
    document.addEventListener('DOMContentLoaded', function() {
        const body = document.body;
        
        if (isMobile) body.classList.add('is-mobile');
        if (isTablet) body.classList.add('is-tablet');
        if (isTouchDevice) body.classList.add('is-touch');
        
        // Mark ACP context and initialize responsive features
        if (!body.classList.contains('in-acp')) {
            // Heuristic: presence of .sacp_nav means ACP
            if (document.querySelector('.sacp_nav')) {
                body.classList.add('in-acp');
            }
        }

        if (body.classList.contains('in-acp')) {
            // Suppress the primary site header entirely on ACP pages
            suppressPrimaryHeaderInACP();
            disablePrimaryStickyInACP();
        }

        // Initialize responsive features
        initMobileNavigation();
        initTouchOptimizations();
        initTableResponsive();
        initSwipeGestures();
        initOrientationHandler();
    });
    
    /**
     * Enhanced Mobile Navigation
     */
    function initMobileNavigation() {
        const nav = document.querySelector('.sacp_nav');
        const menuBtn = document.querySelector('.sacp_overflow-btn');
        const menu = document.querySelector('.sacp_overflow-menu');
        
        if (!menuBtn) return;
        
        // Toggle menu with Mobile Menu Hub
        menuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMobileMenuHub();
        });
        
        // Close menu when clicking outside hub
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mmhub_panel') && !e.target.closest('.sacp_overflow-btn')) {
                closeMenu();
            }
        });
        
        // Close menu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMenu();
            }
        });
    }
    
    function closeMenu() {
        document.body.classList.remove('mmhub_open');
        removeOverlay();
    }

    /**
     * Collapse/expand main site header with a handle when inside ACP
     */
    function initMainHeaderHandle() {
        const existing = document.querySelector('.acp-header-handle');
        const siteHeader = document.querySelector('.site-header, header.site, header.site-header');
        if (existing || !siteHeader) return;

        const handle = document.createElement('button');
        handle.type = 'button';
        handle.className = 'acp-header-handle';
        handle.setAttribute('aria-expanded', 'false');
        handle.setAttribute('aria-label', 'Show/Hide Main Menu');
        handle.innerHTML = '<span class="dot" aria-hidden="true"></span><span>Show/Hide Main Menu</span>';
        document.body.appendChild(handle);

        // Restore state from localStorage
        const key = 'acp:siteHeaderCollapsed';
        let collapsed = localStorage.getItem(key);
        if (collapsed === null) {
            // default: collapsed
            collapsed = '1';
            localStorage.setItem(key, '1');
        }
        if (collapsed === '1') {
            siteHeader.classList.add('is-collapsed');
            handle.setAttribute('aria-expanded', 'false');
        } else {
            handle.setAttribute('aria-expanded', 'true');
        }

        handle.addEventListener('click', () => {
            siteHeader.classList.toggle('is-collapsed');
            const isExpanded = !siteHeader.classList.contains('is-collapsed');
            handle.setAttribute('aria-expanded', String(isExpanded));
            localStorage.setItem(key, siteHeader.classList.contains('is-collapsed') ? '1' : '0');
        });
    }

    /**
     * Disable sticky behavior on the primary site header when in ACP
     */
    function disablePrimaryStickyInACP() {
        const siteHeader = document.querySelector('.site-header, header.site, header.site-header');
        if (!siteHeader) return;

        // Add marker class so CSS turns off sticky
        siteHeader.classList.add('is-sticky');
    }
    
    // Hide site header controls on ACP pages – no show/hide handle, no header menu
    function suppressPrimaryHeaderInACP() {
        const siteHeader = document.querySelector('.site-header, header.site, header.site-header');
        if (!siteHeader) return;
        
        // Remove any previously created ACP handle
        const handle = document.querySelector('.acp-header-handle');
        if (handle) handle.remove();
        
        // Collapse and hide interactive elements in the site header
        siteHeader.classList.add('is-collapsed');
        const interactive = siteHeader.querySelectorAll('button, a, [role="button"], [role="menu"], .dropdown, .menu, .menu-panel');
        interactive.forEach(el => {
            el.setAttribute('tabindex', '-1');
            el.setAttribute('aria-hidden', 'true');
            el.style.pointerEvents = 'none';
        });
    }
    
    function createOverlay() {
        if (document.getElementById('menu-overlay')) return;
        
        const overlay = document.createElement('div');
        overlay.id = 'menu-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            z-index: 1295; /* below hub panel */
            opacity: 0;
            transition: opacity 250ms ease;
        `;
        document.body.appendChild(overlay);
        
        // Trigger animation
        setTimeout(() => overlay.style.opacity = '1', 10);
        
        overlay.addEventListener('click', closeMenu);
    }
    
    function removeOverlay() {
        const overlay = document.getElementById('menu-overlay');
        if (overlay) {
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 250);
        }
    }

    /**
     * Unified Mobile Menu Hub (CRM / Site)
     */
    function toggleMobileMenuHub() {
        if (!document.querySelector('.mmhub_panel')) buildMobileMenuHub();
        const isOpen = document.body.classList.toggle('mmhub_open');
        if (isOpen) createOverlay(); else removeOverlay();
    }

    function buildMobileMenuHub() {
        const panel = document.createElement('div');
        panel.className = 'mmhub_panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.innerHTML = `
            <div class="mmhub_header" role="tablist">
                <button class="mmhub_tab active" role="tab" aria-selected="true" data-tab="crm">Sales CRM</button>
                <button class="mmhub_tab" role="tab" aria-selected="false" data-tab="site">Main Site</button>
            </div>
            <div class="mmhub_body">
                <div class="mmhub_list" data-panel="crm"></div>
                <div class="mmhub_list" data-panel="site" style="display:none"></div>
            </div>
        `;
        const backdrop = document.createElement('div');
        backdrop.className = 'mmhub_backdrop';
        document.body.appendChild(backdrop);
        document.body.appendChild(panel);

        // Populate CRM links from existing overflow menu or static list
        const crmList = panel.querySelector('[data-panel="crm"]');
        const existing = document.querySelector('.sacp_overflow-menu');
        if (existing) {
            existing.querySelectorAll('a.sacp_link').forEach(a => {
                const href = a.getAttribute('href');
                const label = (a.textContent || '').trim();
                if (!href || !label) return;
                const link = document.createElement('a');
                link.href = href; link.textContent = label; crmList.appendChild(link);
            });
        } else {
            const items = [
                ['index.php','Dashboard'],
                ['leads.php','Leads'],
                ['accounts.php','Accounts'],
                ['contacts.php','Contacts'],
                ['calls.php','Call Log'],
                ['tasks.php','Tasks'],
                ['invoices.php','View Invoices'],
                ['invoice.php','Create Invoice'],
                ['dropoffs.php','Demo Records'],
                ['dropoff.php','Record Drop-off'],
            ];
            items.forEach(([href,label])=>{ const a=document.createElement('a'); a.href=href; a.textContent=label; crmList.appendChild(a); });
        }

        // Populate Site links by scraping header nav if present
        const siteList = panel.querySelector('[data-panel="site"]');
        const siteHeader = document.querySelector('.site-header');
        if (siteHeader) {
            const anchors = siteHeader.querySelectorAll('a[href]');
            const seen = new Set();
            anchors.forEach(a => {
                const href = a.getAttribute('href');
                const label = (a.textContent || '').trim();
                if (!href || !label || seen.has(href)) return;
                seen.add(href);
                const link = document.createElement('a'); link.href = href; link.textContent = label; siteList.appendChild(link);
            });
        } else {
            const note = document.createElement('div'); note.textContent = 'Main site menu unavailable.'; siteList.appendChild(note);
        }

        // Tab switching
        panel.querySelectorAll('.mmhub_tab').forEach(btn => {
            btn.addEventListener('click', () => {
                panel.querySelectorAll('.mmhub_tab').forEach(b=>{b.classList.remove('active');b.setAttribute('aria-selected','false')});
                btn.classList.add('active'); btn.setAttribute('aria-selected','true');
                const tab = btn.getAttribute('data-tab');
                panel.querySelector('[data-panel="crm"]').style.display = tab==='crm'?'block':'none';
                panel.querySelector('[data-panel="site"]').style.display = tab==='site'?'block':'none';
            });
        });

        // Close hub when a link is clicked
        panel.addEventListener('click', (e)=>{ if (e.target.closest('a')) closeMenu(); });
    }
    
    /**
     * Touch Optimizations
     */
    function initTouchOptimizations() {
        if (!isTouchDevice) return;
        
        // Improve button touch feedback
        document.querySelectorAll('.btn, .sacp_link').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.classList.add('touch-active');
            });
            
            element.addEventListener('touchend', function() {
                setTimeout(() => this.classList.remove('touch-active'), 150);
            });
        });
        
        // Prevent double-tap zoom on buttons
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });
        
        // Add touch scrolling to overflow elements
        document.querySelectorAll('.overflow-y-auto').forEach(element => {
            element.style.webkitOverflowScrolling = 'touch';
        });
    }
    
    /**
     * Responsive Tables
     */
    function initTableResponsive() {
        const tables = document.querySelectorAll('table:not(.no-responsive)');
        
        tables.forEach(table => {
            // Skip if already wrapped
            if (table.closest('.table-wrapper')) return;
            
            // Wrap table for horizontal scroll
            const wrapper = document.createElement('div');
            wrapper.className = 'table-wrapper';
            wrapper.style.cssText = 'overflow-x: auto; -webkit-overflow-scrolling: touch;';
            
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
            
            // Add scroll indicators if needed
            checkTableScroll(wrapper);
            wrapper.addEventListener('scroll', () => checkTableScroll(wrapper));
        });
    }
    
    function checkTableScroll(wrapper) {
        const table = wrapper.querySelector('table');
        if (!table) return;
        
        const canScrollLeft = wrapper.scrollLeft > 0;
        const canScrollRight = wrapper.scrollLeft < (wrapper.scrollWidth - wrapper.clientWidth - 1);
        
        wrapper.classList.toggle('can-scroll-left', canScrollLeft);
        wrapper.classList.toggle('can-scroll-right', canScrollRight);
        
        // Add scroll indicators
        if (!wrapper.querySelector('.scroll-indicator')) {
            if (canScrollRight) {
                const indicator = document.createElement('div');
                indicator.className = 'scroll-indicator';
                indicator.innerHTML = '→';
                indicator.style.cssText = `
                    position: absolute;
                    right: 0;
                    top: 50%;
                    transform: translateY(-50%);
                    background: linear-gradient(to left, white, transparent);
                    padding: 8px;
                    pointer-events: none;
                    font-size: 20px;
                    color: #999;
                `;
                wrapper.style.position = 'relative';
                wrapper.appendChild(indicator);
            }
        }
    }
    
    /**
     * Swipe Gestures for Navigation
     */
    function initSwipeGestures() {
        if (!isTouchDevice) return;
        
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        
        const leadCards = document.querySelectorAll('.sacp_lead-card');
        
        leadCards.forEach(card => {
            card.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
                touchStartY = e.changedTouches[0].screenY;
            }, { passive: true });
            
            card.addEventListener('touchend', function(e) {
                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;
                handleSwipe(this);
            }, { passive: true });
        });
        
        function handleSwipe(element) {
            const swipeThreshold = 50;
            const verticalThreshold = 100;
            
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;
            
            // Check if horizontal swipe
            if (Math.abs(deltaX) > swipeThreshold && Math.abs(deltaY) < verticalThreshold) {
                if (deltaX > 0) {
                    // Swipe right - could show quick actions
                    showQuickActions(element);
                } else {
                    // Swipe left - could show delete option
                    showDeleteOption(element);
                }
            }
        }
        
        function showQuickActions(element) {
            // Future: Implement quick actions reveal
            console.log('Swipe right on:', element);
        }
        
        function showDeleteOption(element) {
            // Future: Implement delete option reveal
            console.log('Swipe left on:', element);
        }
    }
    
    /**
     * Handle Orientation Changes
     */
    function initOrientationHandler() {
        let orientation = window.orientation || 0;
        
        window.addEventListener('orientationchange', function() {
            const newOrientation = window.orientation || 0;
            
            if (Math.abs(newOrientation - orientation) === 90) {
                // Orientation changed
                document.body.classList.add('orientation-changing');
                
                setTimeout(() => {
                    document.body.classList.remove('orientation-changing');
                    
                    // Recalculate responsive elements
                    initTableResponsive();
                    
                    // Trigger resize event for other scripts
                    window.dispatchEvent(new Event('resize'));
                }, 500);
            }
            
            orientation = newOrientation;
        });
    }
    
    /**
     * Utility: Debounce function for performance
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Handle viewport changes
     */
    const handleViewportChange = debounce(function() {
        // Recalculate responsive elements
        initTableResponsive();
        
        // Update navigation state
        if (window.innerWidth > 768) {
            closeMenu();
        }
    }, 250);
    
    window.addEventListener('resize', handleViewportChange);
    
    /**
     * Improve form experience on mobile
     */
    if (isMobile) {
        // Auto-scroll to focused input
        document.addEventListener('focusin', function(e) {
            if (e.target.matches('input, textarea, select')) {
                setTimeout(() => {
                    e.target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }, 300);
            }
        });
        
        // Add input type optimizations
        document.querySelectorAll('input[type="email"]').forEach(input => {
            input.setAttribute('autocapitalize', 'off');
            input.setAttribute('autocorrect', 'off');
        });
        
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.setAttribute('pattern', '[0-9]*');
            input.setAttribute('inputmode', 'numeric');
        });
    }
    
    /**
     * Export utilities for other scripts
     */
    window.SACPResponsive = {
        isMobile,
        isTablet,
        isTouchDevice,
        closeMenu,
        debounce
    };
    
})();
