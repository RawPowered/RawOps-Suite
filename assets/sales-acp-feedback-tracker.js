/**
 * Sales ACP Feedback Page Visit Tracker
 * Tracks page visits and manages feedback prompt display
 */

(function() {
    'use strict';
    
    // Configuration
    const TRACKING_API = (window.__BASE_PREFIX || '') + '/api/sales-agent-page-tracking.php';
    const SESSION_KEY = 'sacp_feedback_tracking';
    
    /**
     * Get current page URL (normalized)
     */
    function getCurrentPageUrl() {
        return window.location.pathname;
    }
    
    /**
     * Detect browser information
     */
    function getBrowserInfo() {
        const ua = navigator.userAgent;
        let browser = 'Unknown';
        let version = '';
        
        if (ua.indexOf('Firefox') > -1) {
            browser = 'Firefox';
            version = ua.match(/Firefox\/(\d+\.\d+)/)?.[1] || '';
        } else if (ua.indexOf('Chrome') > -1 && ua.indexOf('Edg') === -1) {
            browser = 'Chrome';
            version = ua.match(/Chrome\/(\d+\.\d+)/)?.[1] || '';
        } else if (ua.indexOf('Safari') > -1 && ua.indexOf('Chrome') === -1) {
            browser = 'Safari';
            version = ua.match(/Version\/(\d+\.\d+)/)?.[1] || '';
        } else if (ua.indexOf('Edg') > -1) {
            browser = 'Edge';
            version = ua.match(/Edg\/(\d+\.\d+)/)?.[1] || '';
        } else if (ua.indexOf('MSIE') > -1 || ua.indexOf('Trident') > -1) {
            browser = 'Internet Explorer';
        }
        
        return `${browser}${version ? ' ' + version : ''}`;
    }
    
    /**
     * Detect device and OS information
     */
    function getDeviceInfo() {
        const ua = navigator.userAgent;
        let os = 'Unknown OS';
        let device = 'Desktop';
        
        // Detect OS
        if (ua.indexOf('Windows') > -1) os = 'Windows';
        else if (ua.indexOf('Mac') > -1) os = 'macOS';
        else if (ua.indexOf('Linux') > -1) os = 'Linux';
        else if (ua.indexOf('Android') > -1) os = 'Android';
        else if (ua.indexOf('iOS') > -1 || ua.indexOf('iPhone') > -1 || ua.indexOf('iPad') > -1) os = 'iOS';
        
        // Detect device type
        if (ua.indexOf('Mobile') > -1 || ua.indexOf('Android') > -1 || ua.indexOf('iPhone') > -1) {
            device = 'Mobile';
        } else if (ua.indexOf('iPad') > -1 || ua.indexOf('Tablet') > -1) {
            device = 'Tablet';
        }
        
        const screenRes = `${screen.width}x${screen.height}`;
        const viewportSize = `${window.innerWidth}x${window.innerHeight}`;
        
        return `${device} | ${os} | Screen: ${screenRes} | Viewport: ${viewportSize}`;
    }
    
    /**
     * Store tracking data in sessionStorage
     */
    function storeTrackingData(data) {
        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
        } catch (e) {
            console.warn('Could not store tracking data:', e);
        }
    }
    
    /**
     * Get stored tracking data
     */
    function getStoredTrackingData() {
        try {
            const data = sessionStorage.getItem(SESSION_KEY);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }
    
    /**
     * Add pulse animation to feedback button
     */
    function addPulseToFeedbackButton() {
        const feedbackButtons = document.querySelectorAll('.sacp_feedback-trigger');
        feedbackButtons.forEach(btn => {
            btn.classList.add('sacp_feedback-pulse');
        });
    }
    
    /**
     * Remove pulse animation from feedback button
     */
    function removePulseFromFeedbackButton() {
        const feedbackButtons = document.querySelectorAll('.sacp_feedback-trigger');
        feedbackButtons.forEach(btn => {
            btn.classList.remove('sacp_feedback-pulse');
        });
        
        // Update stored data to prevent re-adding pulse
        const stored = getStoredTrackingData();
        if (stored) {
            stored.pulse_removed = true;
            storeTrackingData(stored);
        }
    }
    
    /**
     * Track page visit via API
     */
    async function trackPageVisit() {
        const pageUrl = getCurrentPageUrl();
        
        try {
            const formData = new FormData();
            formData.append('page_url', pageUrl);
            
            const response = await fetch(TRACKING_API, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Tracking request failed');
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Store tracking data
                storeTrackingData({
                    visit_count: data.visit_count,
                    has_given_feedback: data.has_given_feedback,
                    should_show_prompt: data.should_show_prompt,
                    pulse_removed: false
                });
                
                // Add pulse if needed
                if (data.should_show_prompt) {
                    addPulseToFeedbackButton();
                }
            }
            
        } catch (error) {
            console.warn('Page visit tracking failed:', error);
        }
    }
    
    /**
     * Initialize tracking on page load
     */
    function initializeTracking() {
        // Check if we're on a Sales ACP page
        const currentPath = window.location.pathname;
        if (!currentPath.includes('/onboarding/sales-agent/')) {
            return;
        }
        
        // Check stored data first
        const stored = getStoredTrackingData();
        if (stored && stored.should_show_prompt && !stored.pulse_removed) {
            addPulseToFeedbackButton();
        }
        
        // Track the current page visit
        trackPageVisit();
    }
    
    /**
     * Export functions to global scope
     */
    window.SACPFeedbackTracker = {
        getBrowserInfo: getBrowserInfo,
        getDeviceInfo: getDeviceInfo,
        getCurrentPageUrl: getCurrentPageUrl,
        removePulse: removePulseFromFeedbackButton,
        trackVisit: trackPageVisit
    };
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeTracking);
    } else {
        initializeTracking();
    }
    
})();


