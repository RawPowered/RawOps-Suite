<?php
$SEO = [
    'title' => 'Google Integrations — Admin',
    'description' => 'Configure Google API key and test Gmail/Calendar connectivity',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/google_oauth.php';

require_admin_or_redirect(path_with_base('/admin/'));

$saved = false; $error = null; $status = []; $diag = null;
$SECRET_MASK = '********';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cfg = site_read_config();
        $newKey = trim($_POST['google_api_key'] ?? '');
        if ($newKey !== '') {
            $cfg['google_api_key'] = $newKey;
        }
        // Optional: save OAuth client (do not overwrite secret with mask)
        $cid = trim($_POST['google_oauth_client_id'] ?? '');
        $cs = trim($_POST['google_oauth_client_secret'] ?? '');
        if ($cid !== '') { $cfg['google_oauth_client_id'] = $cid; }
        if ($cs !== '' && $cs !== $SECRET_MASK) { $cfg['google_oauth_client_secret'] = $cs; }
        $cfg['updated_at'] = date('c');
        if (!site_write_config($cfg)) {
            throw new Exception('Unable to write required/site_config.json');
        }
        bump_cache_bust_token();
        $saved = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cfg = site_read_config();
$currentKey = $cfg['google_api_key'] ?? ($google_api_key ?? '');
$currentClientId = $cfg['google_oauth_client_id'] ?? getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '';
$hasSecret = ($cfg['google_oauth_client_secret'] ?? getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: '') ? true : false;

// Minimal connectivity tests (non-destructive)
try {
    global $gemini_api_key;
    $testUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash-8b:generateContent?key=' . urlencode($gemini_api_key);
    $payload = [ 'contents' => [ [ 'role' => 'user', 'parts' => [ ['text' => 'ping'] ] ] ] ];
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>1,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>6]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $status['gemini'] = $code ?: 0;
} catch (Throwable $e) { $status['gemini'] = 0; }

// Full diagnostics (on demand)
if (isset($_POST['run_diagnostics'])) {
    $diag = [ 'ts' => date('c'), 'steps' => [] ];
    $safe = function($s){ return is_string($s) ? substr($s,0,8) . '…' : $s; };

    // Config snapshot (no secrets)
    $diag['config'] = [
        'site_config_present' => is_array($cfg),
        'api_key_length' => strlen($currentKey),
        'oauth_client_id_present' => $currentClientId !== '' ? 1 : 0
    ];

    // 1) Gemini test
    try {
        $t0 = microtime(true);
        global $gemini_api_key;
        $gUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash-8b:generateContent?key=' . urlencode($gemini_api_key);
        $payload = [ 'contents' => [ [ 'role' => 'user', 'parts' => [ ['text' => 'diagnostic ping'] ] ] ] ];
        $ch = curl_init($gUrl);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>1,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>8]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch);
        $errMsg = null; $body = @json_decode($resp,true);
        if (is_array($body) && isset($body['error'])) { $errMsg = $body['error']['message'] ?? null; }
        $diag['steps'][] = ['name'=>'gemini','http'=>$code,'ms'=>round((microtime(true)-$t0)*1000),'ok'=>$code===200?1:0,'error'=>$errMsg ?: ($err?:null)];
    } catch (Throwable $e) { $diag['steps'][] = ['name'=>'gemini','error'=>$e->getMessage()]; }

    // 1b) Gemini endpoint matrix (to auto-suggest)
    try {
        $candidates = [
            'v1/models/gemini-1.5-flash:generateContent',
            'v1beta/models/gemini-1.5-flash:generateContent',
            'v1/models/gemini-1.5-pro:generateContent',
            'v1beta/models/gemini-1.5-pro:generateContent',
            'v1/models/gemini-1.5-flash-8b:generateContent'
        ];
        $diag['gemini_matrix'] = [];
        foreach ($candidates as $path) {
            $url = 'https://generativelanguage.googleapis.com/' . $path . '?key=' . urlencode($gemini_api_key);
            $t0 = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>1,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['contents'=>[[ 'role'=>'user','parts'=>[['text'=>'ping']]]]]),CURLOPT_TIMEOUT=>8]);
            $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch);
            $errMsg = null; $body = @json_decode($resp,true);
            if (is_array($body) && isset($body['error'])) { $errMsg = $body['error']['message'] ?? null; }
            $diag['gemini_matrix'][] = ['endpoint'=>$path,'http'=>$code,'ms'=>round((microtime(true)-$t0)*1000),'error'=>$errMsg ?: ($err?:null)];
        }
        // Suggestion
        $ok = array_values(array_filter($diag['gemini_matrix'], function($r){ return ($r['http'] ?? 0) === 200; }));
        if (!empty($ok)) { $diag['hint_gemini'] = 'Use endpoint: ' . $ok[0]['endpoint']; }
        else { $diag['hint_gemini'] = 'Enable Generative Language API for this key and remove API restrictions for it. If already enabled, try v1 endpoints.'; }
    } catch (Throwable $e) { /* ignore */ }

    // 2) Places/Maps proxy
    try {
        $t0 = microtime(true);
        $url = absolute_url(path_with_base('/api/google-places-proxy.php?op=ac&q=test'));
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        $body = @json_decode($resp,true);
        $diag['steps'][] = ['name'=>'places_proxy','http'=>$code,'ms'=>round((microtime(true)-$t0)*1000),'has_predictions'=> isset($body['predictions']) ? 1 : 0];
    } catch (Throwable $e) { $diag['steps'][] = ['name'=>'places_proxy','error'=>$e->getMessage()]; }

    // 3) OAuth token presence for current admin and Gmail/Calendar probes
    try {
        $uid = current_admin_id();
        $pdo = db();
        $row = $pdo->prepare('SELECT LENGTH(access_token) AS len, expires_at FROM google_oauth_tokens WHERE user_id = ? LIMIT 1');
        $row->execute([$uid]);
        $tok = $row->fetch();
        $diag['token'] = ['present' => $tok ? 1 : 0, 'len' => $tok['len'] ?? 0, 'expires_at' => $tok['expires_at'] ?? null];
        if ($tok) {
            $access = google_get_access_token_for_user($uid);
            // Gmail profile
            $t0 = microtime(true);
            $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/profile');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $access],CURLOPT_TIMEOUT=>8]);
            $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
            $diag['steps'][] = ['name'=>'gmail_profile','http'=>$code,'ms'=>round((microtime(true)-$t0)*1000),'ok'=>$code===200?1:0];
            // Calendar list
            $t0 = microtime(true);
            $ch = curl_init('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=1');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $access],CURLOPT_TIMEOUT=>8]);
            $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
            $diag['steps'][] = ['name'=>'calendar_list','http'=>$code,'ms'=>round((microtime(true)-$t0)*1000),'ok'=>$code===200?1:0];
        }
    } catch (Throwable $e) { $diag['steps'][] = ['name'=>'oauth_probe','error'=>$e->getMessage()]; }
}

?>

<div class="container">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    <div class="reference-card" style="background:white; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,0.1);">
        <div class="card-header" style="background:linear-gradient(135deg,#10b981,#059669); color:white; padding:20px 24px; border-radius:12px 12px 0 0;">
            <h1 style="margin:0;">🧩 Google Integrations</h1>
            <p style="margin:8px 0 0 0; opacity:.9;">Centralize API key and validate connectivity for Maps/Places, Gemini, Gmail, and Calendar.</p>
        </div>
        <div class="card-body" style="padding:24px;">
            <?php if ($saved): ?><div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;">Saved configuration.</div><?php endif; ?>
            <?php if ($error): ?><div class="card" role="alert" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;">Error: <?php echo sanitize($error); ?></div><?php endif; ?>

            <h3>API Key</h3>
            <form method="post" style="display:grid; grid-template-columns: 2fr auto; gap:12px; align-items:end;">
                <label>
                    <span style="display:block; font-size:12px; color:#6b7280;">Google API Key (Gemini, Maps/Places)</span>
                    <input name="google_api_key" value="<?php echo htmlspecialchars($currentKey); ?>" autocomplete="off">
                </label>
                <button class="btn" type="submit" style="background:#059669; color:white;">Save</button>
            </form>

            <h3 style="margin-top:18px;">OAuth Client</h3>
            <form method="post" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:12px; align-items:end;">
                <label>
                    <span style="display:block; font-size:12px; color:#6b7280;">Client ID</span>
                    <input name="google_oauth_client_id" value="<?php echo htmlspecialchars($currentClientId); ?>" autocomplete="off">
                </label>
                <label>
                    <span style="display:block; font-size:12px; color:#6b7280;">Client Secret</span>
                    <input name="google_oauth_client_secret" value="<?php echo $hasSecret ? $SECRET_MASK : ''; ?>" autocomplete="off" placeholder="<?php echo $hasSecret ? $SECRET_MASK : ''; ?>">
                </label>
                <button class="btn" type="submit">Save</button>
            </form>

            <div style="margin-top:16px; display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="card">
                    <h4 style="margin-top:0;">Status</h4>
                    <ul style="margin:0 0 0 18px;">
                        <li>Gemini test: <strong><?php echo $status['gemini'] === 200 ? 'OK' : ('HTTP ' . intval($status['gemini'])); ?></strong> (using model <code>gemini-1.5-flash-8b</code>)</li>
                        <li>Maps/Places via proxy: <code>/api/google-places-proxy.php?op=ac&q=test</code></li>
                    </ul>
                </div>
                <div class="card">
                    <h4 style="margin-top:0;">Next Steps</h4>
                    <ul style="margin:0 0 0 18px;">
                        <li>Connect Gmail and Calendar via OAuth</li>
                        <li>
                            <a class="btn small" style="background:#2563eb;color:white;" href="<?php echo path_with_base('/required/api/oauth-google-start.php'); ?>">Connect Google Account</a>
                        </li>
                        <li>Define notification templates and conditions</li>
                    </ul>
                </div>
            </div>

            <details style="margin-top:16px;">
                <summary style="cursor:pointer;font-weight:600;">Diagnostics / Debug Logs</summary>
                <div class="card" style="margin-top:12px;">
                    <form method="post" style="margin-bottom:12px;">
                        <input type="hidden" name="run_diagnostics" value="1">
                        <button class="btn" type="submit">Run Full Diagnostics</button>
                    </form>
                    <?php if ($diag): ?>
                        <pre id="diagOutput" style="white-space:pre-wrap;overflow:auto;max-height:360px;background:#0b1b30;color:#d1e7ff;padding:12px;border-radius:6px;"><?php echo htmlspecialchars(json_encode($diag, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
                        <button class="btn small" onclick="copyDiag()">Copy logs</button>
                        <script>function copyDiag(){var t=document.getElementById('diagOutput'); if(!t) return; navigator.clipboard.writeText(t.textContent).then(()=>alert('Copied'));}</script>
                    <?php else: ?>
                        <div class="muted">Run diagnostics to collect detailed HTTP codes, timing, token presence, and proxy responses.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>



