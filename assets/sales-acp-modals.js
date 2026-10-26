// Minimal, dependency-free modal system + action handlers for Sales ACP
(function(){
  if (window.SalesACPModals) return; // singleton
  const Modals = {};

  // Create modal root once
  const root = document.createElement('div');
  root.id = 'sacp-modal-root';
  root.style.position = 'fixed';
  root.style.inset = '0';
  root.style.display = 'none';
  root.style.alignItems = 'center';
  root.style.justifyContent = 'center';
  root.style.background = 'rgba(0,0,0,.5)';
  root.style.zIndex = '2000';
  root.innerHTML = '<div role="dialog" aria-modal="true" style="max-width: 680px; width: 92vw; max-height: 86vh; overflow:auto; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.2);"><div id="sacp-modal-body"></div></div>';
  document.addEventListener('DOMContentLoaded', function(){ document.body.appendChild(root); });

  function open(html){
    const body = document.getElementById('sacp-modal-body');
    if (!body) return;
    body.innerHTML = html;
    root.style.display = 'flex';
  }
  function close(){ root.style.display = 'none'; document.getElementById('sacp-modal-body').innerHTML = ''; }

  root.addEventListener('click', function(e){ if (e.target === root) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && root.style.display !== 'none') close(); });

  // Fetch an endpoint and render inside modal
  async function fetchFragment(url){
    const u = new URL(((window.__BASE_PREFIX || '') + url), window.location.origin);
    u.searchParams.set('modal', '1');
    const res = await fetch(u.toString(), { headers: { 'X-Requested-With':'fetch' } });
    const html = await res.text();
    open(html);
  }

  // Submit forms in modal via AJAX, show flash and refresh list or close
  async function submitModalForm(form){
    const data = new FormData(form);
    const res = await fetch(form.action || window.location.href, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With':'fetch' }
    });
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      const j = await res.json();
      // Expect {ok:true, message?, redirect?}
      if (j.redirect) { window.location.href = j.redirect; return; }
      if (j.ok) {
        close();
        // Soft refresh sections likely affected
        try { window.dispatchEvent(new CustomEvent('sacp:data-updated', { detail: { area: 'crm' } })); } catch(e){}
        return;
      }
      alert(j.message || 'Action failed.');
    } else {
      // Fallback: assume HTML fragment returned (validation errors)
      const html = await res.text();
      open(html);
    }
  }

  // Delegate clicks for standard actions
  document.addEventListener('click', function(e){
    const a = e.target.closest('a,button');
    if (!a) return;

    // data-modal-action values: call, task, dropoff
    const action = a.getAttribute('data-modal-action');
    if (!action) return;
    e.preventDefault();

    // Build endpoint from attributes
    const leadId = a.getAttribute('data-lead-id');
    const accountId = a.getAttribute('data-account-id');
    const base = (function(){
      switch(action){
        case 'call': return ((window.__BASE_PREFIX||'') + '/onboarding/sales-agent/calls.php?action=add' + (leadId?('&lead_id='+encodeURIComponent(leadId)):'') + (accountId?('&account_id='+encodeURIComponent(accountId)):''));
        case 'task': return ((window.__BASE_PREFIX||'') + '/onboarding/sales-agent/tasks.php?action=add' + (leadId?('&lead_id='+encodeURIComponent(leadId)):'') + (accountId?('&account_id='+encodeURIComponent(accountId)):''));
        case 'note': return ((window.__BASE_PREFIX||'') + '/onboarding/sales-agent/notes.php?action=add' + (leadId?('&lead_id='+encodeURIComponent(leadId)):'') + (accountId?('&account_id='+encodeURIComponent(accountId)):''));
        case 'dropoff': return ((window.__BASE_PREFIX||'') + '/onboarding/sales-agent/dropoff.php' + (leadId?('?lead_id='+encodeURIComponent(leadId)):''));
        default: return null;
      }
    })();
    if (!base) return;
    fetchFragment(base);
  });

  // Delegate submit inside modal
  document.addEventListener('submit', function(e){
    const form = e.target;
    if (!form.closest('#sacp-modal-root')) return;
    // If form requests ajax explicitly or we are in a modal fragment
    if (form.hasAttribute('data-ajax') || form.querySelector('input[name="modal"]')) {
      e.preventDefault();
      submitModalForm(form);
    }
  });

  // Expose API
  window.SalesACPModals = { open, close, fetchFragment };
})();


