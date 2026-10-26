<?php
$SEO = [
    'title' => 'Site Configuration — Product',
    'description' => 'Control domain/base URL behavior for dev vs production.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

require_admin_or_redirect(path_with_base('/admin/'));

$saved = false; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $force = isset($_POST['force_dev_base']) ? (bool)$_POST['force_dev_base'] : false;
        if (!set_dev_base_forced($force)) {
            throw new Exception('Unable to write site_config.json. Check file permissions in required/.');
        }
        bump_cache_bust_token();
        $saved = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$isForced = is_dev_base_forced();
$cfg = site_read_config();
?>

<section class="section">
    <div class="container">
        <h1>🌐 Site Configuration</h1>
        <p class="muted">Toggle the active domain for absolute URLs and canonical tags. This does not block access to either domain; it controls which domain we emit publicly in links, redirects, and metadata.</p>

        <?php if ($saved): ?>
            <div class="card" role="status" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;">Saved. Cache bust updated.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="card" role="alert" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;">Error: <?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <div class="card" style="margin-top:16px;">
            <form method="post">
                <div style="display:flex; align-items:center; gap:12px;">
                    <label style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="force_dev_base" value="1" <?php echo $isForced ? 'checked' : ''; ?>>
                        <span style="font-weight:600;">Force Dev Base</span>
                    </label>
                    <button class="btn" type="submit">Save</button>
                </div>
                <div class="muted" style="margin-top:8px;">
                    When enabled, absolute URLs will use <code>legacy.example</code>.
                    When disabled, absolute URLs will use <code>product.example</code>.
                </div>
            </form>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Current State</h3>
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:8px;">
                <div class="muted">Active Domain</div>
                <div><strong><?php echo sanitize(site_domain()); ?></strong></div>
                <div class="muted">Base Path</div>
                <div><code><?php echo sanitize(site_base_path()); ?></code></div>
                <div class="muted">Base URL</div>
                <div><a href="<?php echo sanitize(site_base_url()); ?>"><?php echo sanitize(site_base_url()); ?></a></div>
                <div class="muted">Raw Config</div>
                <div><pre style="white-space:pre-wrap;overflow:auto;"><?php echo sanitize(json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></div>
            </div>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Developer Overrides</h3>
            <ul style="margin:0 0 0 18px; padding:0;">
                <li>Define <code>PRODUCT_FORCE_DEV_BASE</code> in <code>required/local.php</code> to hard override.</li>
                <li>Or set environment variable <code>PRODUCT_FORCE_DEV_BASE=true</code>.</li>
            </ul>
        </div>
    </div>
    <div class="container" style="margin-top:16px;">
        <a href="<?php echo path_with_base('/admin/'); ?>" class="btn secondary">← Back to Admin</a>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


