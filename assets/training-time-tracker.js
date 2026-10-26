/**
 * Product Training Time Tracking System
 * Comprehensive monitoring for global operation standards
 * Tracks time spent, idle time, engagement, and user behavior
 */

class TrainingTimeTracker {
    constructor(userId, moduleId, sessionId) {
        this.userId = userId;
        this.moduleId = moduleId;
        this.sessionId = sessionId;
        this.startTime = Date.now();
        this.lastActivity = Date.now();
        this.totalTime = 0;
        this.activeTime = 0;
        this.idleTime = 0;
        this.pageViews = 1;
        this.scrollPercentage = 0;
        this.isIdle = false;
        this.idleStartTime = null;
        this.heartbeatInterval = null;
        this.idleThreshold = 60000; // 60 seconds of inactivity = idle
        this.maxIdleTime = 1800000; // 30 minutes max idle before session timeout
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.startHeartbeat();
        this.trackPageLoad();
        this.setupScrollTracking();
        this.setupVisibilityTracking();
    }

    setupEventListeners() {
        // Track user activity
        const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        activityEvents.forEach(event => {
            document.addEventListener(event, () => {
                this.recordActivity();
            }, true);
        });

        // Track page unload
        window.addEventListener('beforeunload', () => {
            this.saveSession();
        });

        // Track tab visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.handleFocusLost();
            } else {
                this.handleFocusGained();
            }
        });

        // Track window focus changes
        window.addEventListener('blur', () => this.handleFocusLost());
        window.addEventListener('focus', () => this.handleFocusGained());
    }

    setupScrollTracking() {
        let maxScroll = 0;
        
        window.addEventListener('scroll', () => {
            const scrollTop = window.pageYOffset;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const scrollPercent = (scrollTop / docHeight) * 100;
            
            if (scrollPercent > maxScroll) {
                maxScroll = scrollPercent;
                this.scrollPercentage = Math.min(100, Math.max(0, scrollPercent));
            }
        });
    }

    setupVisibilityTracking() {
        // Track if user switches tabs/windows
        document.addEventListener('visibilitychange', () => {
            this.logActivity(document.hidden ? 'focus_lost' : 'focus_gained', {
                timestamp: Date.now(),
                scroll_percentage: this.scrollPercentage
            });
        });
    }

    recordActivity() {
        const now = Date.now();
        
        if (this.isIdle) {
            // User came back from idle
            const idleDuration = now - this.idleStartTime;
            this.idleTime += idleDuration;
            this.isIdle = false;
            this.idleStartTime = null;
            
            this.logActivity('idle_end', {
                idle_duration_seconds: Math.round(idleDuration / 1000),
                total_idle_time: Math.round(this.idleTime / 1000)
            });
        }
        
        this.lastActivity = now;
    }

    checkForIdle() {
        const now = Date.now();
        const timeSinceActivity = now - this.lastActivity;
        
        if (!this.isIdle && timeSinceActivity > this.idleThreshold) {
            // User went idle
            this.isIdle = true;
            this.idleStartTime = now;
            
            this.logActivity('idle_start', {
                time_since_activity: Math.round(timeSinceActivity / 1000)
            });
        }
        
        // Check for excessive idle time
        if (this.isIdle && timeSinceActivity > this.maxIdleTime) {
            this.handleExcessiveIdle();
        }
    }

    handleFocusLost() {
        this.logActivity('focus_lost', {
            timestamp: Date.now(),
            scroll_percentage: this.scrollPercentage
        });
    }

    handleFocusGained() {
        this.recordActivity();
        this.logActivity('focus_gained', {
            timestamp: Date.now(),
            scroll_percentage: this.scrollPercentage
        });
    }

    handleExcessiveIdle() {
        this.logActivity('excessive_idle', {
            idle_minutes: Math.round((Date.now() - this.idleStartTime) / 60000),
            total_idle_minutes: Math.round(this.idleTime / 60000)
        });
        
        // Show warning to user
        if (confirm('You\'ve been idle for a while. Would you like to continue with this module?')) {
            this.recordActivity();
        } else {
            // User wants to pause - save progress
            this.saveSession();
            window.location.href = '/onboarding/trainee/';
        }
    }

    startHeartbeat() {
        this.heartbeatInterval = setInterval(() => {
            this.checkForIdle();
            this.updateTotalTime();
            this.sendHeartbeat();
        }, 10000); // Every 10 seconds
    }

    updateTotalTime() {
        const now = Date.now();
        this.totalTime = now - this.startTime;
        
        if (!this.isIdle) {
            this.activeTime = this.totalTime - this.idleTime;
        }
    }

    sendHeartbeat() {
        const data = {
            user_id: this.userId,
            module_id: this.moduleId,
            session_id: this.sessionId,
            total_time: Math.round(this.totalTime / 1000),
            active_time: Math.round(this.activeTime / 1000),
            idle_time: Math.round(this.idleTime / 1000),
            scroll_percentage: this.scrollPercentage,
            is_idle: this.isIdle,
            page_url: window.location.href
        };

        fetch('/api/training-heartbeat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        }).catch(error => {
            console.warn('Heartbeat failed:', error);
        });
    }

    logActivity(activityType, activityData = {}) {
        const data = {
            user_id: this.userId,
            module_id: this.moduleId,
            session_id: this.sessionId,
            activity_type: activityType,
            activity_data: activityData,
            timestamp: Date.now()
        };

        fetch('/api/training-activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        }).catch(error => {
            console.warn('Activity logging failed:', error);
        });
    }

    trackPageLoad() {
        this.pageViews++;
        this.logActivity('page_load', {
            page_url: window.location.href,
            page_views: this.pageViews,
            referrer: document.referrer
        });
    }

    trackQuizStart() {
        this.logActivity('quiz_start', {
            timestamp: Date.now(),
            scroll_percentage: this.scrollPercentage,
            time_before_quiz: Math.round(this.activeTime / 1000)
        });
    }

    trackQuizSubmit(score, attempts) {
        this.logActivity('quiz_submit', {
            score: score,
            attempts: attempts,
            completion_time: Math.round(this.activeTime / 1000),
            total_time: Math.round(this.totalTime / 1000)
        });
    }

    trackModuleComplete(finalScore = null) {
        this.updateTotalTime();
        
        const completionData = {
            total_time_seconds: Math.round(this.totalTime / 1000),
            active_time_seconds: Math.round(this.activeTime / 1000),
            idle_time_seconds: Math.round(this.idleTime / 1000),
            scroll_percentage: this.scrollPercentage,
            page_views: this.pageViews,
            final_score: finalScore,
            engagement_score: this.calculateEngagementScore()
        };

        this.logActivity('module_complete', completionData);
        this.saveSession(completionData);
    }

    calculateEngagementScore() {
        // Calculate engagement based on multiple factors
        const timeRatio = this.activeTime / this.totalTime; // Higher is better
        const scrollEngagement = this.scrollPercentage / 100; // Higher is better
        const idleRatio = this.idleTime / this.totalTime; // Lower is better
        
        // Weighted engagement score (0-100)
        const engagementScore = (
            (timeRatio * 40) + 
            (scrollEngagement * 30) + 
            ((1 - idleRatio) * 30)
        ) * 100;
        
        return Math.min(100, Math.max(0, engagementScore));
    }

    saveSession(completionData = null) {
        this.updateTotalTime();
        
        const sessionData = {
            user_id: this.userId,
            module_id: this.moduleId,
            session_id: this.sessionId,
            total_time_seconds: Math.round(this.totalTime / 1000),
            active_time_seconds: Math.round(this.activeTime / 1000),
            idle_time_seconds: Math.round(this.idleTime / 1000),
            page_views: this.pageViews,
            scroll_percentage: this.scrollPercentage,
            engagement_score: this.calculateEngagementScore(),
            completion_data: completionData
        };

        // Use sendBeacon for reliable data transmission on page unload
        if (navigator.sendBeacon) {
            navigator.sendBeacon(
                '/api/training-save-session.php',
                JSON.stringify(sessionData)
            );
        } else {
            // Fallback for older browsers
            fetch('/api/training-save-session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sessionData),
                keepalive: true
            });
        }
    }

    // Public methods for integration with training modules
    onQuizStart() {
        this.trackQuizStart();
    }

    onQuizSubmit(score, attempts) {
        this.trackQuizSubmit(score, attempts);
    }

    onModuleComplete(finalScore = null) {
        this.trackModuleComplete(finalScore);
    }

    // Admin can call this to get current session data
    getCurrentStats() {
        this.updateTotalTime();
        return {
            totalTime: Math.round(this.totalTime / 1000),
            activeTime: Math.round(this.activeTime / 1000),
            idleTime: Math.round(this.idleTime / 1000),
            pageViews: this.pageViews,
            scrollPercentage: this.scrollPercentage,
            engagementScore: this.calculateEngagementScore(),
            isIdle: this.isIdle
        };
    }
}

// Global instance - will be initialized by training pages
let trainingTracker = null;

// Initialize tracking when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Get tracking data from page (set by PHP)
    const trackingData = window.trainingTrackingData;
    
    if (trackingData && trackingData.userId && trackingData.moduleId) {
        trainingTracker = new TrainingTimeTracker(
            trackingData.userId,
            trackingData.moduleId,
            trackingData.sessionId || generateSessionId()
        );
        
        console.log('Training time tracking initialized for Module', trackingData.moduleId);
    }
});

// Utility function to generate session ID
function generateSessionId() {
    return 'training_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// Integration functions for training modules
window.TrainingTracker = {
    onQuizStart: () => trainingTracker?.onQuizStart(),
    onQuizSubmit: (score, attempts) => trainingTracker?.onQuizSubmit(score, attempts),
    onModuleComplete: (finalScore) => trainingTracker?.onModuleComplete(finalScore),
    getCurrentStats: () => trainingTracker?.getCurrentStats() || {},
    isActive: () => trainingTracker !== null
};
