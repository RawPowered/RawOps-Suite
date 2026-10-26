<?php
$SEO = [
    'title' => 'Lab Results (COA) — Product‑It',
    'description' => 'Search and verify Product‑It batch COAs by product and batch code in real time.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

$pdo = db();

// Fetch products for filter
$products = [];
try {
    $stmt = $pdo->query("SELECT id, sku, name FROM products WHERE active = 1 ORDER BY name ASC");
    $products = $stmt->fetchAll();
} catch (Throwable $e) { $products = []; }

// Initial recent results (public)
$initialLabs = [];
try {
    $stmt = $pdo->query("\n        SELECT lr.id, lr.product_id, p.sku AS product_sku, p.name AS product_name,\n               lr.batch_code, lr.result_title, lr.lab_name, lr.result_date,\n               lr.file_path, lr.file_name, lr.file_mime, lr.file_size_bytes,\n               lr.created_at\n        FROM lab_results lr\n        LEFT JOIN products p ON p.id = lr.product_id\n        WHERE lr.is_public = 1\n        ORDER BY COALESCE(lr.result_date, lr.created_at) DESC\n        LIMIT 25\n    ");
    $initialLabs = $stmt->fetchAll();
} catch (Throwable $e) { $initialLabs = []; }
?>

<div class="container" style="margin-top: 18px;">
    <div class="card" style="padding: 16px;">
        <h1 style="margin: 0 0 8px 0;">Lab Results (Certificates of Analysis)</h1>
        <p style="margin: 0 0 12px 0; color: #374151;">Search by product and batch code. Results update in real time.</p>

        <div style="display:grid; grid-template-columns: 1fr 220px 220px; gap: 10px; align-items:end;">
            <label style="display:block;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Search</div>
                <input id="lab-q" type="text" placeholder="Product, title, lab, batch..." style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>
            <label style="display:block;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Product</div>
                <select id="lab-product" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
                    <option value="">All products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo intval($p['id']); ?>"><?php echo htmlspecialchars($p['name'] . ' (' . $p['sku'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:block;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Batch Code</div>
                <input id="lab-batch" type="text" placeholder="e.g. K-2025-09-01-A" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>
        </div>

        <div id="lab-stats" style="margin-top: 10px; color:#6b7280; font-size: 13px;">Showing latest results</div>
    </div>

    <div class="card" style="margin-top: 14px; padding: 0; overflow: auto;">
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#f9fafb; text-align:left;">
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Product</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Batch</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Title</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Lab</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Date</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:right;">COA</th>
                </tr>
            </thead>
            <tbody id="lab-tbody">
            </tbody>
        </table>
    </div>
</div>

<script>
(function(){
  const $q = document.getElementById('lab-q');
  const $product = document.getElementById('lab-product');
  const $batch = document.getElementById('lab-batch');
  const $tbody = document.getElementById('lab-tbody');
  const $stats = document.getElementById('lab-stats');
  const BASE = (window.__BASE_PREFIX || '');

  const initial = <?php echo json_encode($initialLabs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  function formatDate(d){
    if(!d) return '';
    try { const dt = new Date(d); return dt.toLocaleDateString(); } catch(e){ return d; }
  }

  function render(rows, total){
    $tbody.innerHTML = '';
    if(!rows || rows.length === 0){
      $tbody.innerHTML = '<tr><td colspan="6" style="padding:12px; color:#6b7280;">No results found.</td></tr>';
      $stats.textContent = 'No results';
      return;
    }
    const frag = document.createDocumentFragment();
    rows.forEach(function(r){
      const tr = document.createElement('tr');
      tr.innerHTML = [
        `<td style="padding:10px; border-bottom:1px solid #f3f4f6;">${(r.product_name||'—')}<div style=\"color:#6b7280;font-size:12px;\">${(r.product_sku||'')}</div></td>`,
        `<td style="padding:10px; border-bottom:1px solid #f3f4f6; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">${(r.batch_code||'')}</td>`,
        `<td style="padding:10px; border-bottom:1px solid #f3f4f6;">${(r.result_title||'')}</td>`,
        `<td style="padding:10px; border-bottom:1px solid #f3f4f6;">${(r.lab_name||'')}</td>`,
        `<td style="padding:10px; border-bottom:1px solid #f3f4f6; white-space:nowrap;">${formatDate(r.result_date)}</td>`,
        `<td style="padding:10px; border-bottom:1px solid #f3f4f6; text-align:right;">` +
          (r.file_path ? `<a href="${BASE}${r.file_path}" target="_blank" rel="noopener" class="btn small">Open</a>` : '—') +
        `</td>`
      ].join('');
      frag.appendChild(tr);
    });
    $tbody.appendChild(frag);
    $stats.textContent = typeof total === 'number' ? `Showing ${rows.length} of ${total}` : `Showing ${rows.length}`;
  }

  function buildUrl(){
    const u = new URL((BASE || '') + '/api/labs-search.php', location.origin);
    const term = ($q.value||'').trim();
    const pid = ($product.value||'').trim();
    const batch = ($batch.value||'').trim();
    if(term) u.searchParams.set('term', term);
    if(pid) u.searchParams.set('product_id', pid);
    if(batch) u.searchParams.set('batch', batch);
    u.searchParams.set('limit', '50');
    return u.toString();
  }

  let timer = null;
  function trigger(){
    clearTimeout(timer);
    timer = setTimeout(async function(){
      try {
        const res = await fetch(buildUrl(), { headers: { 'Accept': 'application/json' }});
        const data = await res.json();
        if(data && data.success){ render(data.results || [], data.total); }
        else { render([], 0); }
      } catch(e){ render([], 0); }
    }, 250);
  }

  // Init with server-provided rows for speed
  render(initial || [], undefined);

  $q.addEventListener('input', trigger);
  $product.addEventListener('change', trigger);
  $batch.addEventListener('input', trigger);
})();
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>




