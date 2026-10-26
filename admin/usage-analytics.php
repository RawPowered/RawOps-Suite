<?php
$SEO = [
    'title' => 'Usage Analytics — Product Admin',
    'description' => 'Detailed platform usage analytics for agents and admins.',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));
?>

<div class="container" style="padding:24px 0 60px 0;">
    <h1 style="margin-bottom:16px;color:#0f172a;">Usage Analytics</h1>
    <p style="color:#6b7280;margin-bottom:24px;">Review engagement time, top pages, and lead attribution for your team.</p>

    <div class="card" style="margin-bottom:16px;">
        <form id="usageFilter" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <label style="font-weight:600;color:#374151;">Range
                <select name="days" id="filterDays" class="input">
                    <option value="7">Last 7 days</option>
                    <option value="14">Last 14 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="60">Last 60 days</option>
                    <option value="90">Last 90 days</option>
                </select>
            </label>
            <label style="font-weight:600;color:#374151;">User
                <select name="user_id" id="filterUser" class="input">
                    <option value="">All users</option>
                </select>
            </label>
            <button type="submit" class="btn" style="background:#2563eb;color:#fff;">Apply</button>
        </form>
    </div>

    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:24px;">
        <div class="card" style="min-height:200px;">
            <h3 style="margin-bottom:8px;color:#0f172a;">Top Users (Engaged Time)</h3>
            <div id="analyticsTopUsers" style="display:flex;flex-direction:column;gap:8px;"></div>
        </div>
        <div class="card" style="min-height:200px;">
            <h3 style="margin-bottom:8px;color:#0f172a;">Lead Attribution</h3>
            <div id="analyticsLeadAttribution" style="display:flex;flex-direction:column;gap:8px;"></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:24px;">
        <h3 style="margin-bottom:8px;color:#0f172a;">Top Pages</h3>
        <div id="analyticsTopPages" style="overflow:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                        <th style="text-align:left;padding:8px;">Path</th>
                        <th style="text-align:right;padding:8px;">Sessions</th>
                        <th style="text-align:right;padding:8px;">Total Engaged (h)</th>
                        <th style="text-align:right;padding:8px;">Avg Engaged (m)</th>
                    </tr>
                </thead>
                <tbody id="analyticsTopPagesBody"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-bottom:8px;color:#0f172a;">Per-user Daily Breakdown</h3>
        <div id="analyticsUserBreakdown" style="overflow:auto;"></div>
    </div>
</div>

<script>
(function(){
    const filterForm = document.getElementById('usageFilter');
    const selectDays = document.getElementById('filterDays');
    const selectUser = document.getElementById('filterUser');
    const topUsersEl = document.getElementById('analyticsTopUsers');
    const leadAttrEl = document.getElementById('analyticsLeadAttribution');
    const pagesBody = document.getElementById('analyticsTopPagesBody');
    const breakdownEl = document.getElementById('analyticsUserBreakdown');

    let currentData = null;

    filterForm.addEventListener('submit', function(e){
        e.preventDefault();
        loadData();
    });

    async function loadData(){
        const params = new URLSearchParams({days: selectDays.value});
        const userId = selectUser.value;
        if (userId) params.append('user_id', userId);

        const res = await fetch('/api/admin-usage-analytics.php?' + params.toString(), {
            headers: {'Accept':'application/json'},
            credentials: 'same-origin'
        });
        if (!res.ok) {
            console.error('Usage analytics fetch failed', res.status);
            return;
        }
        const data = await res.json();
        currentData = data;
        populateUserFilter(data);
        renderTopUsers(data);
        renderLeadAttribution(data);
        renderTopPages(data);
        renderBreakdown(data);
    }

    function populateUserFilter(data){
        const users = data.users || {};
        const existing = new Set();
        Array.from(selectUser.options).forEach(opt => existing.add(opt.value));
        Object.values(users).forEach(user => {
            if (!existing.has(String(user.id))) {
                const opt = document.createElement('option');
                opt.value = user.id;
                opt.textContent = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `User #${user.id}`;
                selectUser.appendChild(opt);
            }
        });
    }

    function renderTopUsers(data){
        const time = data.time || [];
        const users = data.users || {};
        const sorted = time.slice().sort((a,b) => (b.engaged_ms||0) - (a.engaged_ms||0)).slice(0,10);
        topUsersEl.innerHTML = sorted.map(entry => {
            const user = users[entry.user_id] || {};
            const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `User #${entry.user_id}`;
            const engagedHours = ((entry.engaged_ms||0)/3600000).toFixed(2);
            const idleHours = ((entry.idle_ms||0)/3600000).toFixed(2);
            return `<div style="padding:8px;border:1px solid #e5e7eb;border-radius:8px;display:flex;justify-content:space-between;">
                <div>
                    <div style="font-weight:600;color:#0f172a;">${name}</div>
                    <div style="font-size:12px;color:#6b7280;">Sessions ${entry.sessions||0}</div>
                </div>
                <div style="text-align:right;font-size:12px;color:#2563eb;">
                    Engaged ${engagedHours}h<br><span style="color:#9ca3af;">Idle ${idleHours}h</span>
                </div>
            </div>`;
        }).join('') || '<div style="color:#9ca3af;font-size:12px;">No data available</div>';
    }

    function renderLeadAttribution(data){
        const attribution = data.lead_attribution || {};
        const users = data.users || {};
        const rows = Object.keys(attribution).map(uid => {
            const counts = attribution[uid];
            const manual = counts['manual:created'] || 0;
            const nearby = (counts['nearby_leads:created'] || 0) + (counts['nearby_accounts:created'] || 0);
            const revived = (counts['nearby_leads:revived'] || 0) + (counts['nearby_accounts:revived'] || 0);
            const user = users[uid] || {};
            const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `User #${uid}`;
            return `<div style="padding:8px;border:1px solid #e5e7eb;border-radius:8px;display:flex;justify-content:space-between;">
                <div style="font-weight:600;color:#0f172a;">${name}</div>
                <div style="font-size:12px;color:#6b7280;">
                    Manual ${manual} • Nearby ${nearby} • Revived ${revived}
                </div>
            </div>`;
        });
        leadAttrEl.innerHTML = rows.join('') || '<div style="color:#9ca3af;font-size:12px;">No attribution data</div>';
    }

    function renderTopPages(data){
        const pages = data.pages || [];
        pagesBody.innerHTML = pages.map(row => {
            const totalHours = ((row.total_engaged_ms || 0)/3600000).toFixed(2);
            const avgMinutes = ((row.avg_engaged_ms || 0)/60000).toFixed(2);
            return `<tr style="border-bottom:1px solid #e5e7eb;">
                <td style="padding:8px;">${row.path || '/'}</td>
                <td style="padding:8px;text-align:right;">${row.sessions || 0}</td>
                <td style="padding:8px;text-align:right;">${totalHours}</td>
                <td style="padding:8px;text-align:right;">${avgMinutes}</td>
            </tr>`;
        }).join('') || '<tr><td colspan="4" style="padding:8px;color:#9ca3af;">No page data</td></tr>';
    }

    function renderBreakdown(data){
        const time = data.time || [];
        const users = data.users || {};
        const { start, end } = data.range || {};
        const days = [];
        if (start && end) {
            let cur = new Date(start);
            const last = new Date(end);
            while (cur <= last) {
                days.push(cur.toISOString().slice(0,10));
                cur.setDate(cur.getDate()+1);
            }
        }

        const header = ['User'].concat(days.map(d => d.slice(5))).join('</th><th>');
        const rows = time.map(entry => {
            const user = users[entry.user_id] || {};
            const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `User #${entry.user_id}`;
            const dayCells = days.map(day => {
                const info = entry.days ? entry.days[day] : null;
                if (!info) return '<td style="text-align:right;padding:6px;color:#9ca3af;">—</td>';
                const hours = ((info.engaged_ms||0)/3600000).toFixed(2);
                return `<td style="text-align:right;padding:6px;">${hours}h</td>`;
            }).join('');
            return `<tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:6px;font-weight:600;color:#0f172a;">${name}</td>${dayCells}</tr>`;
        }).join('');

        breakdownEl.innerHTML = `<table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;"><th>${header}</th></tr></thead>
            <tbody>${rows || '<tr><td style="padding:8px;color:#9ca3af;">No data</td></tr>'}</tbody>
        </table>`;
    }

    loadData();
})();
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

