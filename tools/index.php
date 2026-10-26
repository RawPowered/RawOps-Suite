<?php
$SEO = [
    'title' => 'Product Sales Tools (Public Index)',
    'description' => 'Quick access to public Product sales tools for reps and partners.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();

$tools = [
    [
        'title' => 'Profit Calculator',
        'description' => 'Show customers clear profit, ROI, and per‑drink economics for each formula.',
        'href' => '/tools/profit-calculator.php',
        'emoji' => '💰',
        'accent' => '#059669'
    ],
    [
        'title' => 'Sales Reference (Price Sheet)',
        'description' => 'Product specs, MSRP, wholesale prices, and bulk discount matrix (X5/X10/X15/X20).',
        'href' => '/tools/sales-reference.php',
        'emoji' => '📋',
        'accent' => '#3b82f6'
    ],
];
?>

<style>
.pwa-prompt { background:#ecfeff; border:1px solid #a5f3fc; color:#0e7490; padding:12px 16px; border-radius:8px; margin-bottom:16px; display:none; }
.tools-wrap { max-width: 1200px; margin: 0 auto; padding: 24px; }
.hero { background: linear-gradient(135deg,#065f46,#059669); color:#fff; padding:40px 24px; border-radius:12px; margin-bottom:24px; }
.grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(300px,1fr)); gap:20px; }
.tool-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.06); overflow:hidden; display:flex; flex-direction:column; }
.tool-head { padding:20px; display:flex; gap:12px; align-items:center; border-bottom:1px solid #eef2f7; }
.tool-emoji { font-size:28px; }
.tool-title { font-size:20px; font-weight:700; color:#0f172a; }
.tool-body { padding:20px; color:#475569; flex:1; }
.tool-actions { padding:16px 20px; background:#f8fafc; display:flex; gap:12px; }
.btn-primary { background: var(--green, #059669); color:#fff; padding:10px 14px; border-radius:8px; text-decoration:none; display:inline-block; }
.btn-secondary { background:#e2e8f0; color:#0f172a; padding:10px 14px; border-radius:8px; text-decoration:none; display:inline-block; }
.copy { border:none; background:#3b82f6; color:#fff; padding:8px 12px; border-radius:8px; cursor:pointer; font-size:14px; }
.copy:hover { background:#2563eb; }
.note { color:#64748b; font-size:13px; margin-top:6px; }
@media (max-width: 640px){ .hero { padding:24px; } }
</style>

<div class="tools-wrap">
  <div class="hero">
    <h1 style="margin:0; font-size:32px;">Product Sales Tools</h1>
    <p style="margin:8px 0 0 0; opacity:.95;">Public links for reps and partners. Bookmark and share freely.</p>
  </div>

  <div class="pwa-prompt" id="pwaPrompt">Add this page to your home screen for one‑tap access. Tap Share → Add to Home Screen.</div>

  <div class="grid">
    <?php foreach($tools as $tool): ?>
      <div class="tool-card">
        <div class="tool-head">
          <div class="tool-emoji"><?php echo $tool['emoji']; ?></div>
          <div class="tool-title"><?php echo htmlspecialchars($tool['title']); ?></div>
        </div>
        <div class="tool-body">
          <p style="margin:0;"><?php echo htmlspecialchars($tool['description']); ?></p>
          <div class="note">URL: <code><?php echo $tool['href']; ?></code></div>
        </div>
        <div class="tool-actions">
          <a class="btn-primary" href="<?php echo $tool['href']; ?>">Open</a>
          <button class="copy" data-href="<?php echo $tool['href']; ?>">Copy Link</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:24px; color:#64748b;">
    Need another public tool added here? Send the title and desired URL and we’ll publish it.
  </div>
</div>

<script>
// Register manifest and basic PWA prompt for iOS/Android
const link = document.createElement('link');
link.rel = 'manifest';
link.href = '/tools/manifest.webmanifest';
document.head.appendChild(link);

// iOS install hint (since iOS ignores manifest prompts)
function showPWAPrompt(){
  const ua = navigator.userAgent.toLowerCase();
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  const isiOS = /iphone|ipad|ipod/.test(ua);
  if (!isStandalone && (isiOS || 'getInstalledRelatedApps' in navigator)) {
    const el = document.getElementById('pwaPrompt');
    if (el) el.style.display = 'block';
  }
}
showPWAPrompt();

document.querySelectorAll('.copy').forEach(btn => {
  btn.addEventListener('click', () => {
    const url = location.origin + btn.getAttribute('data-href');
    navigator.clipboard.writeText(url).then(() => {
      const old = btn.textContent; btn.textContent = 'Copied!'; btn.style.background = '#10b981';
      setTimeout(() => { btn.textContent = old; btn.style.background = '#3b82f6'; }, 1500);
    });
  });
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


