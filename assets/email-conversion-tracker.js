/**
 * Email Conversion Tracker
 * Tracks user actions from email click-throughs
 * Automatically loaded on pages when email tracking session exists
 */

(function() {
    'use strict';
    
    const tracker = {
        /**
         * Track a conversion event
         */
        track: function(type, metadata = {}) {
            // Don't track if no session exists
            if (!this.hasTrackingSession()) {
                return Promise.resolve({ok: false, reason: 'no_session'});
            }
            
            return fetch('/api/email-track-conversion.php?action=track_conversion', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    type: type,
                    metadata: metadata
                })
            })
            .then(response => response.json())
            .catch(error => {
                console.error('Email tracking error:', error);
                return {ok: false, error: error.message};
            });
        },
        
        /**
         * Track page view
         */
        trackPageView: function() {
            return this.track('page_view', {
                url: window.location.href,
                referrer: document.referrer,
                timestamp: Date.now()
            });
        },
        
        /**
         * Track product view
         */
        trackProductView: function(productId, productName, productSku) {
            return this.track('product_view', {
                product_id: productId,
                product_name: productName,
                product_sku: productSku,
                url: window.location.href
            });
        },
        
        /**
         * Track add to cart
         */
        trackCartAdd: function(productId, quantity, price) {
            return this.track('cart_add', {
                product_id: productId,
                quantity: quantity,
                price: price,
                value: price * quantity
            });
        },
        
        /**
         * Track checkout start
         */
        trackCheckoutStart: function(cartTotal, itemCount) {
            return this.track('checkout_start', {
                cart_total: cartTotal,
                item_count: itemCount
            });
        },
        
        /**
         * Track order completion
         */
        trackOrderComplete: function(orderId, orderTotal) {
            return this.track('order_complete', {
                order_id: orderId,
                value: orderTotal
            });
        },
        
        /**
         * Track AI chat interaction
         */
        trackAiChat: function(messageCount) {
            return this.track('ai_chat', {
                message_count: messageCount,
                timestamp: Date.now()
            });
        },
        
        /**
         * Check if tracking session exists
         */
        hasTrackingSession: function() {
            // Check session storage flag (set by track_click redirect)
            if (sessionStorage.getItem('email_tracking_active')) {
                return true;
            }
            
            // Check if referrer includes tracking URL
            if (document.referrer && document.referrer.includes('email-track-conversion.php')) {
                sessionStorage.setItem('email_tracking_active', '1');
                return true;
            }
            
            return false;
        },
        
        /**
         * Get tracking session info (for debugging)
         */
        getSession: function() {
            return fetch('/api/email-track-conversion.php?action=get_tracking_session', {
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .catch(error => {
                console.error('Session check error:', error);
                return {ok: false, error: error.message};
            });
        },
        
        /**
         * Clear tracking session (for testing)
         */
        clearSession: function() {
            sessionStorage.removeItem('email_tracking_active');
            return fetch('/api/email-track-conversion.php?action=clear_tracking', {
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .catch(error => {
                console.error('Clear session error:', error);
                return {ok: false, error: error.message};
            });
        }
    };
    
    // Expose globally
    window.EmailConversionTracker = tracker;
    
    // Auto-track page view if tracking session exists
    if (tracker.hasTrackingSession()) {
        // Track after page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                tracker.trackPageView();
            });
        } else {
            tracker.trackPageView();
        }
    }
    
    // Debug mode helper
    if (window.location.search.includes('debug_tracking=1')) {
        console.log('[Email Tracker] Debug mode enabled');
        tracker.getSession().then(function(session) {
            console.log('[Email Tracker] Session:', session);
        });
    }
    
})();





