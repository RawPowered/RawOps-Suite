/**
 * Product URL Helpers
 * Centralized URL management for JavaScript
 * Part of URL Synchronization Mega Plan - Phase 3
 */

const ProductURLs = {
    // Base URL without trailing slash
    baseURL: window.location.origin,
    
    /**
     * Build a complete URL with optional query parameters
     * @param {string} path - The path to build (will be normalized)
     * @param {Object} params - Optional query parameters
     * @returns {string} Complete URL
     */
    buildURL: function(path, params = {}) {
        // Normalize path - remove / and ensure single leading slash
        path = '/' + path
            .replace(/^\/+|\/+$/g, '')
            .replace(/\/product\//g, '/');
        
        // Build URL object
        const url = new URL(path, this.baseURL);
        
        // Add query parameters if provided
        Object.keys(params).forEach(key => {
            if (params[key] !== undefined && params[key] !== null) {
                url.searchParams.append(key, params[key]);
            }
        });
        
        return url.toString();
    },
    
    /**
     * Generate asset URL
     * @param {string} path - Asset path (without /assets/ prefix)
     * @returns {string} Complete asset URL
     */
    asset: function(path) {
        // Remove any /assets/ or /assets/ prefix
        path = path
            .replace(/^\/product\/assets\//, '')
            .replace(/^\/assets\//, '')
            .replace(/^\/+/, '');
        
        return this.buildURL('assets/' + path);
    },
    
    /**
     * Generate API endpoint URL
     * @param {string} endpoint - API endpoint (without /api/ prefix)
     * @param {Object} params - Optional query parameters
     * @returns {string} Complete API URL
     */
    api: function(endpoint, params = {}) {
        // Remove any /api/ or /api/ prefix
        endpoint = endpoint
            .replace(/^\/product\/api\//, '')
            .replace(/^\/api\//, '')
            .replace(/^\/+/, '');
        
        return this.buildURL('api/' + endpoint, params);
    },
    
    /**
     * Generate shop URL
     * @param {string} path - Shop path
     * @returns {string} Complete shop URL
     */
    shop: function(path = '') {
        path = path.replace(/^\/+/, '');
        return this.buildURL('shop/' + path);
    },
    
    /**
     * Generate admin URL
     * @param {string} path - Admin path
     * @returns {string} Complete admin URL
     */
    admin: function(path = '') {
        path = path.replace(/^\/+/, '');
        return this.buildURL('admin/' + path);
    },
    
    /**
     * Generate customer URL
     * @param {string} path - Customer path
     * @returns {string} Complete customer URL
     */
    customer: function(path = '') {
        path = path.replace(/^\/+/, '');
        return this.buildURL('customer/' + path);
    },
    
    /**
     * Generate training URL
     * @param {string} type - 'trainee' or 'sales-agent'
     * @param {string} path - Additional path
     * @returns {string} Complete training URL
     */
    training: function(type = 'trainee', path = '') {
        path = path.replace(/^\/+/, '');
        return this.buildURL(`onboarding/${type}/${path}`);
    },
    
    /**
     * Generate tools URL
     * @param {string} tool - Tool name (e.g., 'profit-calculator')
     * @returns {string} Complete tool URL
     */
    tools: function(tool = '') {
        tool = tool.replace(/^\/+/, '');
        return this.buildURL('tools/' + tool);
    },
    
    /**
     * Common URLs for quick access
     */
    urls: {
        login: '/login.php',
        logout: '/logout.php',
        dashboard: '/admin/',
        shop: '/shop/',
        cart: '/shop/cart.php',
        checkout: '/shop/checkout.php',
        profile: '/customer/',
        profitCalc: '/tools/profit-calculator.php',
        salesRef: '/tools/sales-reference.php',
        crm: '/onboarding/sales-agent/',
        training: '/onboarding/trainee/',
        api: {
            health: '/api/health.php',
            login: '/api/auth/login.php',
            logout: '/api/auth/logout.php',
            profile: '/api/user/profile.php',
            activity: '/api/training-activity.php',
        }
    },
    
    /**
     * Navigate to a URL (wrapper for window.location)
     * @param {string} path - Path to navigate to
     * @param {Object} params - Optional query parameters
     */
    navigate: function(path, params = {}) {
        window.location.href = this.buildURL(path, params);
    },
    
    /**
     * Open URL in new tab
     * @param {string} path - Path to open
     * @param {Object} params - Optional query parameters
     */
    openNewTab: function(path, params = {}) {
        window.open(this.buildURL(path, params), '_blank');
    },
    
    /**
     * Replace current URL without adding to history
     * @param {string} path - Path to replace with
     * @param {Object} params - Optional query parameters
     */
    replace: function(path, params = {}) {
        window.location.replace(this.buildURL(path, params));
    },
    
    /**
     * Get current path without domain
     * @returns {string} Current path
     */
    getCurrentPath: function() {
        return window.location.pathname;
    },
    
    /**
     * Check if current path matches
     * @param {string} path - Path to check
     * @returns {boolean} True if matches
     */
    isCurrentPath: function(path) {
        const currentPath = this.getCurrentPath();
        const checkPath = '/' + path.replace(/^\/+/, '');
        return currentPath === checkPath || currentPath.startsWith(checkPath + '/');
    },
    
    /**
     * Make AJAX request with proper URL handling
     * @param {string} endpoint - API endpoint
     * @param {Object} options - Fetch options
     * @returns {Promise} Fetch promise
     */
    fetch: function(endpoint, options = {}) {
        const url = this.api(endpoint);
        
        // Add default headers if not present
        if (!options.headers) {
            options.headers = {};
        }
        
        // Add JSON content type for POST/PUT/PATCH with body
        if ((options.method === 'POST' || options.method === 'PUT' || options.method === 'PATCH') && options.body) {
            if (!options.headers['Content-Type']) {
                options.headers['Content-Type'] = 'application/json';
            }
            
            // Stringify body if it's an object
            if (typeof options.body === 'object' && !(options.body instanceof FormData)) {
                options.body = JSON.stringify(options.body);
            }
        }
        
        return fetch(url, options);
    },
    
    /**
     * jQuery AJAX wrapper with proper URL handling
     * @param {Object} settings - jQuery AJAX settings
     * @returns {Object} jQuery promise
     */
    ajax: function(settings) {
        // Only works if jQuery is loaded
        if (typeof $ === 'undefined') {
            console.error('jQuery is not loaded');
            return null;
        }
        
        // Update URL to use our API helper
        if (settings.url && settings.url.startsWith('/api/')) {
            settings.url = this.api(settings.url.replace('/api/', ''));
        } else if (settings.url) {
            settings.url = this.buildURL(settings.url);
        }
        
        return $.ajax(settings);
    },
    
    /**
     * Axios wrapper with proper URL handling
     * @param {Object} config - Axios config
     * @returns {Promise} Axios promise
     */
    axios: function(config) {
        // Only works if axios is loaded
        if (typeof axios === 'undefined') {
            console.error('Axios is not loaded');
            return null;
        }
        
        // Update URL to use our helpers
        if (config.url && config.url.startsWith('/api/')) {
            config.url = this.api(config.url.replace('/api/', ''));
        } else if (config.url) {
            config.url = this.buildURL(config.url);
        }
        
        return axios(config);
    }
};

// Convenience aliases
const URLs = ProductURLs;
const Product = ProductURLs;

// Auto-fix legacy code patterns if enabled
if (typeof PRODUCT_AUTO_FIX_URLS !== 'undefined' && PRODUCT_AUTO_FIX_URLS) {
    // Override window.location.href setter
    const originalLocationSetter = Object.getOwnPropertyDescriptor(window.location, 'href').set;
    Object.defineProperty(window.location, 'href', {
        set: function(value) {
            if (typeof value === 'string' && value.includes('/')) {
                value = value.replace(/\/product\//g, '/');
                console.warn('Auto-fixed URL containing /:', value);
            }
            return originalLocationSetter.call(this, value);
        }
    });
    
    // Override fetch
    const originalFetch = window.fetch;
    window.fetch = function(url, ...args) {
        if (typeof url === 'string' && url.includes('/')) {
            url = url.replace(/\/product\//g, '/');
            console.warn('Auto-fixed fetch URL containing /:', url);
        }
        return originalFetch.call(this, url, ...args);
    };
    
    // Override XMLHttpRequest.open
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        if (typeof url === 'string' && url.includes('/')) {
            url = url.replace(/\/product\//g, '/');
            console.warn('Auto-fixed XMLHttpRequest URL containing /:', url);
        }
        return originalOpen.call(this, method, url, ...args);
    };
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProductURLs;
}
