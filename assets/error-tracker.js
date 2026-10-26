/**
 * Comprehensive Error Tracking System
 * Captures JavaScript errors, HTTP errors, resource failures, performance issues, and redirect loops
 * Integrates with site heartbeat system
 * @version 1.0.0
 * @date 2025-09-30
 */

(function(){
  'use strict';

  // Configuration
  var config = {
    apiEndpoint: ((window.__BASE_PREFIX || '') + '/api/error-tracker.php'),
    enabled: true,
    sampleRate: 1.0, // Capture 100% of errors (reduce for high traffic sites)
    maxStackLength: 5000,
    maxErrorsPerSession: 100,
    debugMode: false,
    performanceThresholds: {
      pageLoad: 3000, // 3 seconds
      apiResponse: 5000, // 5 seconds
      resourceLoad: 10000 // 10 seconds
    }
  };

  // State management
  var state = {
    errorsLogged: 0,
    sessionId: null,
    visitedUrls: [],
    lastUrl: null,
    redirectCount: 0,
    originalFetch: window.fetch,
    initialized: false
  };

  // Initialize session ID (sync with site-tracker.js)
  function getSessionId() {
    if (!state.sessionId) {
      state.sessionId = 'err_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
    }
    return state.sessionId;
  }

  // Check if we should sample this error
  function shouldSample() {
    return Math.random() < config.sampleRate && state.errorsLogged < config.maxErrorsPerSession;
  }

  // Get browser context information
  function getBrowserContext() {
    var context = {
      viewport_width: window.innerWidth || null,
      viewport_height: window.innerHeight || null,
      screen_width: screen.width || null,
      screen_height: screen.height || null,
      connection_type: null,
      memory_used_mb: null
    };

    // Get connection info if available
    if (navigator.connection || navigator.mozConnection || navigator.webkitConnection) {
      var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      context.connection_type = conn.effectiveType || conn.type || null;
    }

    // Get memory info if available (Chrome only)
    if (performance.memory) {
      context.memory_used_mb = (performance.memory.usedJSHeapSize / 1048576).toFixed(2);
    }

    return context;
  }

  // Get performance metrics
  function getPerformanceMetrics() {
    if (!window.performance || !window.performance.timing) {
      return null;
    }

    var timing = performance.timing;
    var loadTime = timing.loadEventEnd - timing.navigationStart;
    
    return {
      page_load_time_ms: loadTime > 0 ? loadTime : null,
      dns_time: timing.domainLookupEnd - timing.domainLookupStart,
      tcp_time: timing.connectEnd - timing.connectStart,
      request_time: timing.responseEnd - timing.requestStart,
      dom_ready_time: timing.domContentLoadedEventEnd - timing.navigationStart,
      dom_interactive_time: timing.domInteractive - timing.navigationStart
    };
  }

  // Send error to API
  function sendErrorReport(errorData) {
    if (!config.enabled || !shouldSample()) {
      return;
    }

    state.errorsLogged++;

    // Add browser context
    var browserContext = getBrowserContext();
    var perfMetrics = getPerformanceMetrics();
    
    var payload = Object.assign({}, errorData, browserContext, {
      session_id: getSessionId(),
      url: window.location.href,
      path: window.location.pathname + window.location.search,
      referrer: document.referrer || null,
      timestamp: Date.now()
    });

    if (perfMetrics && perfMetrics.page_load_time_ms) {
      payload.page_load_time_ms = perfMetrics.page_load_time_ms;
    }

    if (config.debugMode) {
      console.log('[Error Tracker] Sending error report:', payload);
    }

    // Send to API using sendBeacon for reliability or fetch as fallback
    try {
      var blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
      if (navigator.sendBeacon) {
        navigator.sendBeacon(config.apiEndpoint, blob);
      } else {
        fetch(config.apiEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          keepalive: true,
          credentials: 'same-origin'
        }).catch(function(err) {
          if (config.debugMode) {
            console.error('[Error Tracker] Failed to send error report:', err);
          }
        });
      }
    } catch (e) {
      if (config.debugMode) {
        console.error('[Error Tracker] Exception while sending report:', e);
      }
    }
  }

  // Track JavaScript errors
  function trackJavaScriptError(message, source, lineno, colno, error) {
    var errorData = {
      error_type: 'javascript',
      error_code: (error && error.name) || 'JavaScriptError',
      error_message: message || 'Unknown JavaScript Error',
      error_severity: 'error',
      file_path: source || null,
      line_number: lineno || null,
      column_number: colno || null,
      stack_trace: (error && error.stack) ? error.stack.substring(0, config.maxStackLength) : null,
      metadata: {
        error_name: (error && error.name) || null,
        error_type: (error && error.constructor && error.constructor.name) || null
      }
    };

    sendErrorReport(errorData);
  }

  // Track unhandled promise rejections
  function trackUnhandledRejection(event) {
    var reason = event.reason;
    var message = 'Unhandled Promise Rejection';
    var stack = null;

    if (reason instanceof Error) {
      message = reason.message || message;
      stack = reason.stack;
    } else if (typeof reason === 'string') {
      message = reason;
    } else if (reason && typeof reason === 'object') {
      message = JSON.stringify(reason).substring(0, 500);
    }

    var errorData = {
      error_type: 'javascript',
      error_code: 'UnhandledRejection',
      error_message: message,
      error_severity: 'error',
      stack_trace: stack ? stack.substring(0, config.maxStackLength) : null,
      metadata: {
        rejection_type: typeof reason
      }
    };

    sendErrorReport(errorData);
  }

  // Track resource loading errors
  function trackResourceError(event) {
    var target = event.target || event.srcElement;
    if (!target) return;

    var tagName = target.tagName ? target.tagName.toLowerCase() : 'unknown';
    var resourceUrl = target.src || target.href || 'unknown';
    var resourceType = getResourceType(tagName, target);

    var errorData = {
      error_type: 'resource',
      error_code: 'RESOURCE_LOAD_FAILED',
      error_message: 'Failed to load ' + resourceType + ': ' + resourceUrl,
      error_severity: 'warning',
      resource_url: resourceUrl,
      resource_type: resourceType,
      metadata: {
        tag_name: tagName,
        element_id: target.id || null,
        element_class: target.className || null
      }
    };

    sendErrorReport(errorData);
  }

  // Determine resource type from element
  function getResourceType(tagName, element) {
    switch(tagName) {
      case 'script': return 'script';
      case 'link': 
        return (element.rel === 'stylesheet') ? 'stylesheet' : 'other';
      case 'img': return 'image';
      case 'video':
      case 'audio': return 'media';
      case 'iframe': return 'other';
      default: return 'other';
    }
  }

  // Monitor fetch/XHR requests
  function interceptFetch() {
    if (!window.fetch) return;

    window.fetch = function() {
      var args = arguments;
      var url = args[0];
      var startTime = Date.now();

      return state.originalFetch.apply(this, args)
        .then(function(response) {
          var duration = Date.now() - startTime;
          
          // Track HTTP errors (4xx, 5xx)
          if (response.status >= 400) {
            trackHTTPError(url, response.status, response.statusText, duration);
          }
          
          // Track slow API responses
          if (duration > config.performanceThresholds.apiResponse) {
            trackSlowAPI(url, duration);
          }

          return response;
        })
        .catch(function(error) {
          var duration = Date.now() - startTime;
          trackAPIFailure(url, error, duration);
          throw error; // Re-throw to maintain original behavior
        });
    };
  }

  // Track HTTP errors (from fetch/XHR)
  function trackHTTPError(url, status, statusText, duration) {
    var severity = status >= 500 ? 'critical' : (status >= 400 ? 'error' : 'warning');
    
    var errorData = {
      error_type: 'http',
      error_code: String(status),
      error_message: 'HTTP ' + status + ' - ' + (statusText || 'Error') + ' for ' + url,
      error_severity: severity,
      metadata: {
        status_code: status,
        status_text: statusText,
        request_url: url,
        duration_ms: duration
      }
    };

    sendErrorReport(errorData);
  }

  // Track API failures (network errors, timeouts)
  function trackAPIFailure(url, error, duration) {
    var errorData = {
      error_type: 'api',
      error_code: 'NETWORK_ERROR',
      error_message: 'API request failed: ' + url + ' - ' + (error.message || 'Unknown error'),
      error_severity: 'error',
      metadata: {
        request_url: url,
        duration_ms: duration,
        error_type: error.name || null
      }
    };

    sendErrorReport(errorData);
  }

  // Track slow API responses
  function trackSlowAPI(url, duration) {
    var errorData = {
      error_type: 'performance',
      error_code: 'SLOW_API',
      error_message: 'Slow API response: ' + url + ' took ' + duration + 'ms',
      error_severity: 'warning',
      issue_type: 'failed_api',
      metric_value: duration,
      threshold_value: config.performanceThresholds.apiResponse,
      metadata: {
        request_url: url
      }
    };

    sendErrorReport(errorData);
  }

  // Detect redirect loops
  function detectRedirectLoop() {
    var currentUrl = window.location.href;
    
    // Track URL history
    state.visitedUrls.push({
      url: currentUrl,
      timestamp: Date.now()
    });

    // Keep only last 10 URLs
    if (state.visitedUrls.length > 10) {
      state.visitedUrls.shift();
    }

    // Check for loops (same URL visited 3+ times in short period)
    var urlCounts = {};
    var recentTime = Date.now() - 30000; // Last 30 seconds
    
    state.visitedUrls.forEach(function(visit) {
      if (visit.timestamp >= recentTime) {
        urlCounts[visit.url] = (urlCounts[visit.url] || 0) + 1;
      }
    });

    // Alert if loop detected
    for (var url in urlCounts) {
      if (urlCounts[url] >= 3) {
        trackRedirectLoop(url, urlCounts[url]);
        break;
      }
    }
  }

  // Track redirect loops
  function trackRedirectLoop(url, count) {
    var errorData = {
      error_type: 'redirect',
      error_code: 'REDIRECT_LOOP',
      error_message: 'Redirect loop detected: ' + url + ' visited ' + count + ' times',
      error_severity: 'critical',
      metadata: {
        loop_url: url,
        loop_count: count,
        visited_urls: state.visitedUrls.map(function(v) { return v.url; })
      }
    };

    sendErrorReport(errorData);
  }

  // Monitor page load performance
  function monitorPageLoadPerformance() {
    if (!window.performance || !window.performance.timing) {
      return;
    }

    // Wait for page to fully load
    window.addEventListener('load', function() {
      setTimeout(function() {
        var timing = performance.timing;
        var loadTime = timing.loadEventEnd - timing.navigationStart;

        if (loadTime > config.performanceThresholds.pageLoad) {
          var errorData = {
            error_type: 'performance',
            error_code: 'SLOW_PAGE_LOAD',
            error_message: 'Slow page load: ' + loadTime + 'ms',
            error_severity: 'warning',
            issue_type: 'slow_load',
            metric_value: loadTime,
            threshold_value: config.performanceThresholds.pageLoad,
            metadata: getPerformanceMetrics()
          };

          sendErrorReport(errorData);
        }
      }, 1000); // Wait 1 second after load to ensure timing is complete
    });
  }

  // Track console errors (optional, for development)
  function interceptConsoleError() {
    if (!config.debugMode) return;

    var originalError = console.error;
    console.error = function() {
      var args = Array.prototype.slice.call(arguments);
      var message = args.map(function(arg) {
        return typeof arg === 'object' ? JSON.stringify(arg) : String(arg);
      }).join(' ');

      // Only track if it looks like an actual error, not just logging
      if (message.toLowerCase().includes('error')) {
        trackJavaScriptError(message, null, null, null, null);
      }

      return originalError.apply(console, arguments);
    };
  }

  // Initialize error tracking
  function init() {
    if (state.initialized) return;
    state.initialized = true;

    if (config.debugMode) {
      console.log('[Error Tracker] Initializing comprehensive error tracking...');
    }

    // Track uncaught errors
    window.addEventListener('error', function(event) {
      // Distinguish between JavaScript errors and resource errors
      if (event.target && (event.target.tagName || event.target === window)) {
        if (event.target !== window) {
          trackResourceError(event);
        } else {
          trackJavaScriptError(event.message, event.filename, event.lineno, event.colno, event.error);
        }
      } else {
        trackJavaScriptError(event.message, event.filename, event.lineno, event.colno, event.error);
      }
    }, true); // Use capture phase to catch all errors

    // Track unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
      trackUnhandledRejection(event);
    });

    // Intercept fetch for API monitoring
    interceptFetch();

    // Monitor page load performance
    monitorPageLoadPerformance();

    // Detect redirect loops
    detectRedirectLoop();

    // Intercept console.error (debug mode only)
    interceptConsoleError();

    // Track navigation changes for SPA redirect detection
    if (window.history && window.history.pushState) {
      var originalPushState = window.history.pushState;
      window.history.pushState = function() {
        detectRedirectLoop();
        return originalPushState.apply(this, arguments);
      };
    }

    if (config.debugMode) {
      console.log('[Error Tracker] Error tracking initialized successfully');
    }
  }

  // Public API (for manual error reporting)
  window.ErrorTracker = {
    track: function(errorData) {
      sendErrorReport(errorData);
    },
    setConfig: function(options) {
      Object.assign(config, options);
    },
    getState: function() {
      return {
        errorsLogged: state.errorsLogged,
        sessionId: state.sessionId
      };
    }
  };

  // Auto-initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();


