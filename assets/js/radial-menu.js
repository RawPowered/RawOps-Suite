/**
 * Sales ACP Radial Menu Controller
 * Handles interactions, positioning, and animations for the unified radial menu
 */

class RadialMenu {
    constructor(element) {
        if (!element) {
            console.error('RadialMenu: No element provided');
            return;
        }

        this.element = element;
        this.centerBtn = element.querySelector('.radial-center');
        this.itemsContainer = element.querySelector('.radial-items');
        this.backdrop = document.getElementById('radialMenuBackdrop');
        this.items = Array.from(element.querySelectorAll('.radial-item'));
        
        this.isExpanded = false;
        this.currentFocusIndex = -1;
        this.radius = this.getRadius();
        
        this.init();
    }

    init() {
        this.calculatePositions();
        this.bindEvents();
        this.setupKeyboard();
        this.restoreState();
        
        // Mark as initialized
        this.element.classList.add('radial-menu-initialized');
    }

    getRadius() {
        // Responsive radius based on screen size
        // Larger radius for centered menu with text labels
        if (window.innerWidth <= 480) {
            return 100;
        } else if (window.innerWidth <= 768) {
            return 130;
        } else {
            return 160;
        }
    }

    calculatePositions() {
        // Position 8 items around a circle
        // Positions: 12, 1:30, 3, 4:30, 6, 7:30, 9, 10:30 o'clock
        const angles = [
            -90,   // 12 o'clock (top)
            -45,   // 1:30
            0,     // 3 o'clock (right)
            45,    // 4:30
            90,    // 6 o'clock (bottom)
            135,   // 7:30
            180,   // 9 o'clock (left)
            -135   // 10:30
        ];

        this.items.forEach((item, index) => {
            if (index >= angles.length) return;

            const angle = angles[index];
            const radian = (angle * Math.PI) / 180;
            const x = Math.cos(radian) * this.radius;
            const y = Math.sin(radian) * this.radius;

            // Store position data
            item.dataset.x = x;
            item.dataset.y = y;
            item.dataset.angle = angle;

            // Set position
            item.style.left = `${x}px`;
            item.style.top = `${y}px`;
        });
    }

    bindEvents() {
        // Center button click
        this.centerBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggle();
        });

        // Backdrop click to close
        this.backdrop.addEventListener('click', () => {
            this.close();
        });

        // Item clicks
        this.items.forEach((item) => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                this.handleItemClick(item);
            });
        });

        // Window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                this.radius = this.getRadius();
                this.calculatePositions();
            }, 250);
        });

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isExpanded) {
                this.close();
            }
        });
    }

    setupKeyboard() {
        // Keyboard shortcuts (1-8 for direct access)
        document.addEventListener('keydown', (e) => {
            if (!this.isExpanded) return;
            
            // Number keys 1-8
            const num = parseInt(e.key);
            if (num >= 1 && num <= 8) {
                e.preventDefault();
                const item = this.items[num - 1];
                if (item) {
                    this.handleItemClick(item);
                }
            }

            // Arrow key navigation
            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
                this.navigateWithArrows(e.key);
            }
        });

        // Tab navigation
        this.element.addEventListener('keydown', (e) => {
            if (e.key === 'Tab' && this.isExpanded) {
                e.preventDefault();
                this.navigateWithTab(e.shiftKey);
            }
        });
    }

    navigateWithArrows(key) {
        const directions = {
            'ArrowUp': -90,
            'ArrowRight': 0,
            'ArrowDown': 90,
            'ArrowLeft': 180
        };

        const targetAngle = directions[key];
        
        // Find closest item to target angle
        let closestIndex = 0;
        let smallestDiff = Infinity;

        this.items.forEach((item, index) => {
            const itemAngle = parseFloat(item.dataset.angle);
            let diff = Math.abs(targetAngle - itemAngle);
            
            // Handle wrap-around
            if (diff > 180) diff = 360 - diff;
            
            if (diff < smallestDiff) {
                smallestDiff = diff;
                closestIndex = index;
            }
        });

        this.focusItem(closestIndex);
    }

    navigateWithTab(reverse) {
        if (reverse) {
            this.currentFocusIndex--;
            if (this.currentFocusIndex < 0) {
                this.currentFocusIndex = this.items.length - 1;
            }
        } else {
            this.currentFocusIndex++;
            if (this.currentFocusIndex >= this.items.length) {
                this.currentFocusIndex = 0;
            }
        }

        this.focusItem(this.currentFocusIndex);
    }

    focusItem(index) {
        this.currentFocusIndex = index;
        const item = this.items[index];
        if (item) {
            item.focus();
        }
    }

    toggle() {
        if (this.isExpanded) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.isExpanded = true;
        this.element.classList.add('expanded');
        this.backdrop?.classList.add('active');
        this.centerBtn.setAttribute('aria-expanded', 'true');
        
        // Save state
        this.saveState();

        // Announce to screen readers
        this.announce('Menu opened');

        // Auto-close after inactivity (optional)
        this.startAutoCloseTimer();
    }

    close() {
        this.isExpanded = false;
        this.element.classList.remove('expanded');
        this.backdrop?.classList.remove('active');
        this.centerBtn.setAttribute('aria-expanded', 'false');
        this.currentFocusIndex = -1;
        
        // Save state
        this.saveState();

        // Clear auto-close timer
        this.clearAutoCloseTimer();

        // Return focus to center button
        this.centerBtn.focus();

        // Announce to screen readers
        this.announce('Menu closed');
    }

    handleItemClick(item) {
        const action = item.dataset.action;
        const leadId = this.element.dataset.leadId;

        // Execute action
        if (action && window.RadialMenuActions && window.RadialMenuActions[action]) {
            window.RadialMenuActions[action](leadId, item);
        }

        // Close menu after action (with small delay for visual feedback)
        setTimeout(() => {
            this.close();
        }, 150);
    }

    startAutoCloseTimer() {
        this.clearAutoCloseTimer();
        
        // Auto-close after 10 seconds of inactivity
        this.autoCloseTimer = setTimeout(() => {
            if (this.isExpanded) {
                this.close();
            }
        }, 10000);
    }

    clearAutoCloseTimer() {
        if (this.autoCloseTimer) {
            clearTimeout(this.autoCloseTimer);
            this.autoCloseTimer = null;
        }
    }

    saveState() {
        try {
            sessionStorage.setItem('radialMenuExpanded', this.isExpanded);
        } catch (e) {
            // Ignore if sessionStorage not available
        }
    }

    restoreState() {
        try {
            const savedState = sessionStorage.getItem('radialMenuExpanded');
            // Don't auto-open on page load, just remember for current session
            // if (savedState === 'true') {
            //     this.open();
            // }
        } catch (e) {
            // Ignore if sessionStorage not available
        }
    }

    announce(message) {
        // Create live region for screen reader announcements
        let liveRegion = document.getElementById('radial-menu-announcer');
        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.id = 'radial-menu-announcer';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.setAttribute('aria-atomic', 'true');
            liveRegion.style.cssText = 'position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;';
            document.body.appendChild(liveRegion);
        }

        liveRegion.textContent = message;
    }

    destroy() {
        // Cleanup if needed
        this.close();
        this.element.classList.remove('radial-menu-initialized');
    }
}

// Action handlers
window.RadialMenuActions = {
    // Lead Stats/Completion
    stats: function(leadId) {
        if (window.showLeadStatsModal) {
            window.showLeadStatsModal();
        }
    },

    // Request Sample/Demo
    sample: function(leadId) {
        if (leadId) {
            window.location.href = `request-sample.php?lead_id=${leadId}`;
        } else {
            window.location.href = 'request-sample.php';
        }
    },

    // Log Call
    call: function(leadId) {
        if (window.openModal) {
            window.openModal('call', leadId);
        } else if (leadId) {
            window.location.href = `calls.php?action=add&lead_id=${leadId}`;
        } else {
            window.location.href = 'calls.php?action=add';
        }
    },

    // Add Note or Social Interaction (combined action)
    note_or_social: function(leadId) {
        // Show quick menu to choose between note and social interaction
        if (leadId && window.showNoteOrSocialMenu) {
            window.showNoteOrSocialMenu(leadId);
        } else if (window.openModal) {
            window.openModal('note', leadId);
        } else if (leadId) {
            window.location.href = `notes.php?action=add&lead_id=${leadId}`;
        }
    },
    
    // Add Note (legacy support)
    note: function(leadId) {
        if (window.openModal) {
            window.openModal('note', leadId);
        } else if (leadId) {
            window.location.href = `notes.php?action=add&lead_id=${leadId}`;
        }
    },

    // Create Task
    task: function(leadId) {
        if (window.openModal) {
            window.openModal('task', leadId);
        } else if (leadId) {
            window.location.href = `tasks.php?action=add&lead_id=${leadId}`;
        } else {
            window.location.href = 'tasks.php?action=add';
        }
    },

    // Create Invoice
    invoice: function(leadId) {
        if (leadId) {
            window.location.href = `invoice.php?lead_id=${leadId}`;
        } else {
            window.location.href = 'invoice.php';
        }
    },

    // Archived Leads
    archive: function() {
        if (window.toggleArchivedLeadsPanel) {
            window.toggleArchivedLeadsPanel();
        }
    },

    // UI Chat/AI Assistant
    chat: function() {
        // Trigger the existing AI chat button
        const aiChatButton = document.getElementById('ai-chat-button');
        if (aiChatButton) {
            aiChatButton.click();
        } else if (window.openAIChat) {
            window.openAIChat();
        } else if (window.showAIChatModal) {
            window.showAIChatModal();
        } else {
            console.warn('AI Chat button not found');
        }
    }
};

// Initialize radial menu on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    const radialMenuElement = document.getElementById('salesACPRadialMenu');
    if (radialMenuElement) {
        window.radialMenuInstance = new RadialMenu(radialMenuElement);
    }
});

