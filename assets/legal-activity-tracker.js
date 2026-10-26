/**
 * Legal Portal Activity Tracker
 * Tracks active vs idle time, enforces idle timeout with grace period
 */
(function(window) {
    const doc = window.document;
    if (!doc || !window.__LEGAL_ACTIVITY_CONFIG) {
        return;
    }

    const config = window.__LEGAL_ACTIVITY_CONFIG;
    const sessionToken = config.sessionToken;
    if (!sessionToken) {
        return;
    }

    const heartbeatUrl = config.heartbeatUrl;
    const endUrl = config.endUrl;
    const logoutUrl = config.logoutUrl || '/logout.php';
    const idleGraceMs = config.idleGraceMs || 30000;
    const idleTimeoutMs = config.idleTimeoutMs || 300000;
    const heartbeatIntervalMs = config.heartbeatIntervalMs || 20000;

    const activityEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'touchmove', 'click'];

    let lastInteraction = Date.now();
    let lastHeartbeat = 0;
    let isIdle = false;
    let idleGraceReached = false;

    let totalActiveMs = config.totalActiveMs || 0;
    let totalIdleMs = config.totalIdleMs || 0;
    const startedAt = config.startedAt || Date.now();

    let idleTimer = null;
    let graceTimer = null;
    let heartbeatTimer = null;

    const countdownContainer = createCountdownContainer();

    function createCountdownContainer() {
        const existing = doc.getElementById('legal-idle-countdown');
        if (existing) {
            return existing;
        }
        const container = doc.createElement('div');
        container.id = 'legal-idle-countdown';
        container.setAttribute('role', 'alert');
        container.style.position = 'fixed';
        container.style.bottom = '24px';
        container.style.right = '24px';
        container.style.zIndex = '9999';
        container.style.maxWidth = '340px';
        container.style.padding = '18px 20px';
        container.style.background = '#1e3a8a';
        container.style.color = '#fff';
        container.style.borderRadius = '14px';
        container.style.boxShadow = '0 20px 48px rgba(15,23,42,0.35)';
        container.style.display = 'none';
        container.style.fontFamily = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
        container.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">'
            + '<div style="font-size:2rem;">⏳</div>'
            + '<div><strong style="display:block;margin-bottom:4px;font-size:1rem;">Idle Timeout Warning</strong>'
            + '<span id="legal-idle-countdown-text" style="font-size:0.95rem;opacity:0.9;">You will be logged out soon.</span></div>'
            + '</div>';
        doc.body.appendChild(container);
        return container;
    }

    function showCountdown(secondsRemaining) {
        const textNode = doc.getElementById('legal-idle-countdown-text');
        if (textNode) {
            textNode.textContent = 'No activity detected. Logging out in ' + secondsRemaining + ' seconds. Move your mouse or press any key to stay logged in.';
        }
        countdownContainer.style.display = 'block';
    }

    function hideCountdown() {
        countdownContainer.style.display = 'none';
    }

    function recordInteraction() {
        const now = Date.now();
        if (isIdle) {
            const idleDuration = now - lastInteraction;
            totalIdleMs += idleDuration;
            isIdle = false;
            idleGraceReached = false;
            hideCountdown();
        }
        lastInteraction = now;
        resetIdleTimers();
    }

    function resetIdleTimers() {
        if (graceTimer) {
            window.clearTimeout(graceTimer);
        }
        if (idleTimer) {
            window.clearTimeout(idleTimer);
        }

        // Start grace period timer
        graceTimer = window.setTimeout(() => {
            idleGraceReached = true;
            scheduleIdleTimeout();
        }, idleGraceMs);
    }

    function scheduleIdleTimeout() {
        if (idleTimer) {
            window.clearTimeout(idleTimer);
        }
        const countdownStart = Date.now();
        const countdownDurationMs = idleTimeoutMs;

        idleTimer = window.setTimeout(() => {
            handleIdleLogout();
        }, countdownDurationMs);

        const tick = () => {
            if (!idleGraceReached || !isIdle) {
                return;
            }
            const elapsed = Date.now() - countdownStart;
            const remainingMs = Math.max(0, countdownDurationMs - elapsed);
            const remainingSeconds = Math.ceil(remainingMs / 1000);
            showCountdown(remainingSeconds);
            if (remainingMs > 0) {
                window.requestAnimationFrame(tick);
            }
        };

        tick();
    }

    function handleIdleLogout() {
        endSession('idle_timeout').finally(() => {
            window.location.href = logoutUrl;
        });
    }

    function sendHeartbeat(force) {
        const now = Date.now();
        if (!force && now - lastHeartbeat < heartbeatIntervalMs) {
            return;
        }
        lastHeartbeat = now;
        const totalMs = now - startedAt;
        const payload = {
            session_token: sessionToken,
            total_ms: totalMs,
            active_ms: totalActiveMs,
            idle_ms: totalIdleMs,
            is_idle: isIdle,
            page_url: window.location.pathname + window.location.search
        };

        navigator.sendBeacon(heartbeatUrl, JSON.stringify(payload));
    }

    function endSession(reason) {
        sendHeartbeat(true);
        const payload = {
            session_token: sessionToken,
            reason: reason || 'manual_end',
            total_ms: Date.now() - startedAt,
            active_ms: totalActiveMs,
            idle_ms: totalIdleMs,
            is_idle: isIdle
        };
        return fetch(endUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
            credentials: 'include'
        }).catch(() => {});
    }

    function evaluateIdleState() {
        const now = Date.now();
        const sinceInteraction = now - lastInteraction;

        if (!isIdle && sinceInteraction >= idleGraceMs) {
            isIdle = true;
        }

        if (isIdle) {
            if (!idleGraceReached) {
                idleGraceReached = true;
                scheduleIdleTimeout();
            }
        } else {
            totalActiveMs += now - lastInteraction;
        }
    }

    function loop() {
        evaluateIdleState();
        sendHeartbeat(false);
        window.setTimeout(loop, 1000);
    }

    // Event listeners
    activityEvents.forEach(evt => {
        window.addEventListener(evt, recordInteraction, {passive: true});
    });

    window.addEventListener('beforeunload', () => {
        endSession('navigation');
    });

    window.addEventListener('visibilitychange', () => {
        if (doc.visibilityState === 'hidden') {
            isIdle = true;
            idleGraceReached = true;
            scheduleIdleTimeout();
            sendHeartbeat(true);
        } else {
            recordInteraction();
        }
    });

    // Initial setup
    resetIdleTimers();
    window.setTimeout(loop, 1000);
    heartbeatTimer = window.setInterval(() => sendHeartbeat(false), heartbeatIntervalMs);

    window.__LEGAL_ACTIVITY_CONTROL = {
        endSession,
        recordInteraction,
        getStatus: () => ({
            idle: isIdle,
            totalActiveMs,
            totalIdleMs,
            lastInteraction
        })
    };

    // Accessibility notice
    if (!config.disableNotice) {
        createNoticeBanner();
    }

    function createNoticeBanner() {
        if (doc.getElementById('legal-time-tracking-notice')) {
            return;
        }
        const banner = doc.createElement('div');
        banner.id = 'legal-time-tracking-notice';
        banner.setAttribute('role', 'status');
        banner.style.position = 'fixed';
        banner.style.bottom = '0';
        banner.style.left = '0';
        banner.style.right = '0';
        banner.style.padding = '14px 20px';
        banner.style.background = 'rgba(15,23,42,0.95)';
        banner.style.color = '#e2e8f0';
        banner.style.fontSize = '0.95rem';
        banner.style.display = 'flex';
        banner.style.justifyContent = 'center';
        banner.style.zIndex = '9998';
        banner.innerHTML = '<strong style="margin-right:8px;color:#fbbf24;">Notice:</strong>'
            + 'Time spent in the legal portal is monitored for compliance. You will be logged out after 5 minutes of inactivity.';

        const closeBtn = doc.createElement('button');
        closeBtn.type = 'button';
        closeBtn.textContent = 'Dismiss';
        closeBtn.style.marginLeft = '16px';
        closeBtn.style.background = '#1d4ed8';
        closeBtn.style.color = '#fff';
        closeBtn.style.border = 'none';
        closeBtn.style.borderRadius = '24px';
        closeBtn.style.padding = '6px 16px';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.fontSize = '0.9rem';
        closeBtn.addEventListener('click', () => {
            banner.remove();
        });

        banner.appendChild(closeBtn);
        doc.body.appendChild(banner);

        window.setTimeout(() => {
            if (banner.parentNode) {
                banner.remove();
            }
        }, 120000);
    }

})(window);







