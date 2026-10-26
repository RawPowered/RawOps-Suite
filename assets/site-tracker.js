(function(){
  'use strict';

  if (!('fetch' in window)) { return; }

  if (typeof window.__TRACK_USAGE_ENABLED === 'boolean' && window.__TRACK_USAGE_ENABLED === false) {
    return;
  }

  var API = ((window.__BASE_PREFIX || '') + '/api/site-heartbeat.php');
  var PAGE_ID = 'pg_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
  var startTime = Date.now();
  var lastActivity = startTime;
  var isIdle = false;
  var idleStart = null;
  var idleAccum = 0;
  var maxScrollPct = 0;
  var idleThresholdMs = 60000; // 60s

  function now(){ return Date.now(); }

  function computeTimes(){
    var t = now();
    var total = t - startTime;
    var idle = idleAccum + (isIdle && idleStart ? (t - idleStart) : 0);
    if (idle < 0) idle = 0;
    var engaged = Math.max(0, total - idle);
    return { totalMs: total, idleMs: idle, engagedMs: engaged };
  }

  function onActivity(){
    var t = now();
    lastActivity = t;
    if (isIdle) {
      if (idleStart) { idleAccum += Math.max(0, t - idleStart); }
      isIdle = false; idleStart = null;
    }
  }

  function checkIdle(){
    var t = now();
    if (!isIdle && (t - lastActivity) > idleThresholdMs) {
      isIdle = true; idleStart = t;
    }
  }

  function onScroll(){
    var doc = document.documentElement;
    var scrollTop = (window.pageYOffset || doc.scrollTop || 0);
    var docHeight = Math.max(1, (doc.scrollHeight - window.innerHeight));
    var pct = Math.round(Math.min(100, Math.max(0, (scrollTop / docHeight) * 100)));
    if (pct > maxScrollPct) maxScrollPct = pct;
  }

  // Attach activity listeners to track user engagement
  ['click','keydown','mousemove','touchstart','touchmove','wheel'].forEach(function(ev){
    window.addEventListener(ev, onActivity, { passive: true, capture: true });
  });
  window.addEventListener('scroll', function(){ onActivity(); onScroll(); }, { passive: true, capture: true });

  // Heartbeat function - simplified for site-heartbeat.php API
  function sendHeartbeat(){
    try {
      checkIdle();
      // site-heartbeat.php just needs basic data, no type field needed
      fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          path: location.pathname + location.search,
          page_title: document.title,
          referrer: document.referrer || null,
          is_idle: isIdle ? 1 : 0,
          activity_score: 1, // Simple increment per heartbeat
          session_id: null // Will use PHP session
        }),
        keepalive: true,
        credentials: 'same-origin'
      }).catch(function(){ /* ignore */ });
    } catch(e){ /* ignore */ }
  }
  
  // Initial heartbeat immediately
  onScroll();
  sendHeartbeat();
  
  // Send heartbeat every 10 seconds (site_active_sessions considers 2 min for "online")
  setInterval(sendHeartbeat, 10000); // 10s
  
  // Send final heartbeat on page unload
  window.addEventListener('beforeunload', function(){
    try {
      navigator.sendBeacon(API, new Blob([JSON.stringify({
        path: location.pathname,
        is_idle: 0,
        activity_score: 0
      })], {type:'application/json'}));
    } catch(e){ /* ignore */ }
  });
})();


