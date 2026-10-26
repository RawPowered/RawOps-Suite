<?php 
require_once __DIR__ . '/config.php'; 

// Set Content Security Policy to allow Google Maps and TinyMCE (only if not already set)
if (!headers_sent()) {
    $headersAlreadySent = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Security-Policy:') === 0) {
            $headersAlreadySent = true;
            break;
        }
    }
    if (!$headersAlreadySent) {
        header("Content-Security-Policy: script-src 'self' 'unsafe-inline' 'unsafe-eval' https://maps.googleapis.com https://maps.gstatic.com https://cdn.tiny.cloud; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; img-src 'self' data: https: blob:; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; connect-src 'self' https://maps.googleapis.com;");
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    $title = $SEO['title'] ?? 'Product — Wholesale Product Supply & Sales ACP';
    $desc = $SEO['description'] ?? 'Enterprise-grade wholesale product sourcing, compliant sales enablement, and a powerful ACP for agents.';
    $canonical = $SEO['canonical'] ?? canonical_url();
    $image = $SEO['image'] ?? outbound_path('/assets/logo.svg');
    $type = $SEO['type'] ?? 'website';
    ?>
    <title><?php echo sanitize($title); ?></title>
    <meta name="description" content="<?php echo sanitize($desc); ?>">
    <link rel="canonical" href="<?php echo sanitize($canonical); ?>">
    <meta property="og:title" content="<?php echo sanitize($title); ?>">
    <meta property="og:description" content="<?php echo sanitize($desc); ?>">
    <meta property="og:type" content="<?php echo sanitize($type); ?>">
    <meta property="og:url" content="<?php echo sanitize($canonical); ?>">
    <meta property="og:image" content="<?php echo sanitize($image); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo sanitize($title); ?>">
    <meta name="twitter:description" content="<?php echo sanitize($desc); ?>">
    <meta name="twitter:image" content="<?php echo sanitize($image); ?>">
    <meta name="theme-color" content="#eef6ff">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?php echo outbound_path('/style.css'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>">
    <script>
        window.__BASE_PREFIX = <?php echo json_encode(site_base_path(), JSON_UNESCAPED_SLASHES); ?>;
        window.__TRACK_USAGE_ENABLED = <?php echo !empty($FEATURES['usage_tracking']) ? 'true' : 'false'; ?>;
    </script>
    <?php
    // Server-side crawler pulse (bots don't run JS)
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua) {
            $uaLower = strtolower($ua);
            if (preg_match('/bot|crawl|spider|slurp|mediapartners-google|bingpreview|semrush|ahrefs|yandex|baiduspider|duckduckbot|facebookexternalhit|embedly/i', $uaLower)) {
                $botName = 'bot';
                if (preg_match('/(googlebot|bingbot|semrush|ahrefs|yandex|baiduspider|duckduckbot)/i', $ua, $m)) {
                    $botName = strtolower($m[1]);
                }
                $pdo = db();
                $stmt = $pdo->prepare('INSERT INTO crawler_pulses (bot_name, user_agent, ip_address, path, referer, bucket_minute) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bot_name = VALUES(bot_name)');
                $stmt->execute([
                    $botName,
                    substr($ua, 0, 255),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', 0, 500),
                    substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
                    date('Y-m-d H:i:00')
                ]);
            }
        }
    } catch (Throwable $e) { /* swallow */ }
    ?>
    <?php $isSalesAcp = (strpos(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', path_with_base('/onboarding/sales-agent')) === 0); ?>
    <?php if ($isSalesAcp): ?>
    <link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp.css'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>">
    <script src="<?php echo outbound_path('/assets/sales-acp-modals.js'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo outbound_path('/assets/ai-chat.css'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>">
    <script src="<?php echo outbound_path('/assets/ai-chat.js'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>" defer></script>
    <?php if (!empty($FEATURES['usage_tracking'])): ?>
    <script src="<?php echo outbound_path('/assets/site-tracker.js'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>" defer></script>
    <?php endif; ?>
    <?php if (!empty($SEO['schema'])): ?>
        <script type="application/ld+json">
            <?php echo json_encode($SEO['schema']); ?>
        </script>
    <?php endif; ?>
    <script>
        window.__BASE_PREFIX = '<?php echo rtrim(path_with_base(''), '/'); ?>';
    </script>
    
    <?php
    // Check if sales agent has incomplete agreements (existing user modal)
    $showAgreementModal = false;
    $currentUid = current_user_id();
    if ($currentUid) {
        $currentRoles = user_roles($currentUid);
        
        // Only check for sales agents, and not on the agreements page itself
        if (in_array('sales_agent', $currentRoles ?? [])) {
            $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $isOnAgreementsPage = (strpos($currentPath, '/agreements.php') !== false);
            
            if (!$isOnAgreementsPage) {
                try {
                    $pdo = db();
                    $stmt = $pdo->prepare('
                        SELECT COUNT(DISTINCT at.agreement_type) as signed_count
                        FROM agreement_templates at
                        LEFT JOIN user_agreements ua ON ua.agreement_template_id = at.id AND ua.user_id = ?
                        WHERE at.is_active = 1 
                        AND at.agreement_type IN ("nda", "non_compete", "ai_communications", "sms_consent", "email_consent", "phone_consent", "gmail_account")
                        AND ua.id IS NOT NULL
                    ');
                    $stmt->execute([$currentUid]);
                    $signedCount = $stmt->fetchColumn();
                    
                    $requiredAgreements = 7;
                    if ($signedCount < $requiredAgreements) {
                        $showAgreementModal = true;
                    }
                } catch (Throwable $e) {
                    // Silently fail if tables don't exist yet
                    error_log('Agreement check error: ' . $e->getMessage());
                }
            }
        }
    }
    
    if ($showAgreementModal): ?>
    <script>
        var showAgreementModal = true;
    </script>
    <script src="<?php echo outbound_path('/assets/agreement-modal.js'); ?>?v=<?php echo ASSET_VER; ?>&cb=<?php echo urlencode(cache_bust_token()); ?>"></script>
    <?php endif; ?>
    <?php if (!empty($SEO['extra_head'])): ?>
    <?php echo $SEO['extra_head']; ?>
    <?php endif; ?>
    
    <?php
    // Load walkthrough system and API restriction assets for authenticated users
    if (current_user_id()): 
        // Get user's walkthrough preference from database
        try {
            $stmt = db()->prepare('SELECT disable_walkthroughs FROM user_preferences WHERE user_id = ?');
            $stmt->execute([current_user_id()]);
            $walkthroughDisabled = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $walkthroughDisabled = false;
        }
    ?>
    <link rel="stylesheet" href="<?php echo asset_url('/assets/walkthrough-engine.css'); ?>?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="<?php echo asset_url('/assets/api-restriction-modal.css'); ?>?v=<?php echo ASSET_VER; ?>">
    <script>
        // Set walkthrough preference as global variable to avoid extra API calls
        window.__WALKTHROUGH_DISABLED = <?php echo $walkthroughDisabled ? 'true' : 'false'; ?>;
    </script>
    <script src="<?php echo asset_url('/assets/walkthrough-engine.js'); ?>?v=<?php echo ASSET_VER; ?>" defer></script>
    <script src="<?php echo asset_url('/assets/tutorial-menu.js'); ?>?v=<?php echo ASSET_VER; ?>" defer></script>
    <script src="<?php echo asset_url('/assets/api-restriction-modal.js'); ?>?v=<?php echo ASSET_VER; ?>" defer></script>
    <?php endif; ?>
</head>
<?php 
$bodyClass = !empty($isSalesAcp) ? ' class="paladin-theme"' : ''; 
// Generate walkthrough page identifier from current script name
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$walkthroughPage = '';
if ($isSalesAcp) {
    $walkthroughPage = 'sales-agent-' . $scriptName;
} elseif (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) {
    $walkthroughPage = 'admin-' . $scriptName;
} else {
    $walkthroughPage = $scriptName;
}
$walkthroughPageAttr = $walkthroughPage ? ' data-walkthrough-page="' . htmlspecialchars($walkthroughPage) . '"' : '';
?>
<body<?php echo $bodyClass . $walkthroughPageAttr; ?>>
<header class="site-header" role="banner" style="position:relative; z-index: 1000;">
    <div class="container">
        <?php if (!$isSalesAcp): ?>
        <?php $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'; ?>
        <a class="brand" href="<?php echo (strpos($reqPath, path_with_base('/tools')) === 0) ? outbound_path('/tools/') : outbound_path('/index.php'); ?>" aria-label="Product Home">
            <img src="<?php echo outbound_path('/assets/logo.svg'); ?>" alt="Product" width="160" height="40" decoding="async" fetchpriority="high" style="filter:drop-shadow(0 2px 6px rgba(0,0,0,.12));">
        </a>
        <?php endif; ?>
        <button class="nav-toggle" aria-controls="primary-nav" aria-expanded="false" aria-label="Toggle navigation">☰</button>
        <?php $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'; ?>
        <?php $uid = current_user_id(); $dashboard = $uid ? user_dashboard_path($uid) : null; ?>
        <?php 
        // Check user roles and wholesale account status
        $hasWholesaleAccount = false;
        $cartItemCount = 0;
        $userRoles = [];
        $isTrainee = false;
        $isSalesAgent = false;
        $isAdmin = false;
        $isOperations = false;
        $isLegalUser = false;
        
        if ($uid) {
            try {
                // Get wholesale account status
                $stmt = db()->prepare('SELECT 1 FROM wholesale_accounts WHERE user_id = ? AND approved = 1 LIMIT 1');
                $stmt->execute([$uid]);
                $hasWholesaleAccount = (bool)$stmt->fetch();
                
                // Get user roles
                $stmt = db()->prepare('
                    SELECT r.name 
                    FROM user_roles ur 
                    JOIN roles r ON r.id = ur.role_id 
                    WHERE ur.user_id = ?
                ');
                $stmt->execute([$uid]);
                $userRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $isTrainee = in_array('trainee', $userRoles);
                $isSalesAgent = in_array('sales_agent', $userRoles);
                $isAdmin = in_array('admin', $userRoles);
                $isOperations = in_array('operations', $userRoles);
                $isLegalUser = in_array('legal', $userRoles);
                $isAccountant = in_array('accountant', $userRoles);
                
                // Get cart item count if they have wholesale access
                if ($hasWholesaleAccount && isset($_SESSION['cart'])) {
                    $cartItemCount = array_sum(array_column($_SESSION['cart'], 'quantity'));
                }
            } catch (Throwable $e) {}
        }
        ?>
    <nav id="primary-nav" role="navigation" aria-label="Primary">
        <?php if ($isSalesAcp): ?>
            <!-- Sales ACP renders its own nav with Back to Admin inside the ACP menu -->
        <?php else: ?>
        <?php $isTools = (strpos($currentPath, path_with_base('/tools')) === 0); ?>
        <?php if ($isTools): ?>
            <a href="<?php echo outbound_path('/tools/'); ?>" <?php 
                $isHomeActive = ($currentPath === path_with_base('/tools/') || $currentPath === path_with_base('/tools') || $currentPath === path_with_base('/tools/index.php'));
                echo $isHomeActive ? 'aria-current="page" class="active"' : '';
            ?>>Home</a>
            <a href="<?php echo outbound_path('/tools/profit-calculator.php'); ?>" <?php echo ($currentPath === path_with_base('/tools/profit-calculator.php')) ? 'aria-current="page" class="active"' : ''; ?>>Profit Calculator</a>
            <a href="<?php echo outbound_path('/tools/sales-reference.php'); ?>" <?php echo ($currentPath === path_with_base('/tools/sales-reference.php')) ? 'aria-current="page" class="active"' : ''; ?>>Sales Reference</a>
        <?php else: ?>
            <?php if (!$isLegalUser || $isAdmin): ?>
                <?php $homeHref = $uid ? $dashboard : path_with_base('/index.php'); ?>
                <a href="<?php echo $homeHref; ?>" <?php 
                    $isHomeActive = ($currentPath === path_with_base('/') || $currentPath === path_with_base('') || $currentPath === path_with_base('/index.php'));
                    if ($uid) { $isHomeActive = (strpos($currentPath, $homeHref) === 0); }
                    echo $isHomeActive ? 'aria-current="page" class="active"' : ''; 
                ?>>Home</a>
                <a href="<?php echo outbound_path('/shop/'); ?>" <?php echo (strpos($currentPath, path_with_base('/shop')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>Shop</a>
            <?php endif; ?>
            <?php if ($hasWholesaleAccount): ?>
                <a href="<?php echo outbound_path('/shop/cart.php'); ?>" <?php echo (strpos($currentPath, path_with_base('/shop/cart')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>
                    Cart<?php echo $cartItemCount > 0 ? " ({$cartItemCount})" : ''; ?>
                </a>
            <?php endif; ?>
            <?php if ($isTrainee || $isSalesAgent || $isAdmin): ?>
                <a href="<?php echo outbound_path('/onboarding/trainee/index.php'); ?>" <?php echo (strpos($currentPath, path_with_base('/onboarding/trainee')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>
                    <?php echo $isTrainee ? 'Training' : 'Review Training'; ?>
                </a>
            <?php endif; ?>
            <?php if ($isSalesAgent || $isAdmin): ?>
                <a href="<?php echo outbound_path('/onboarding/sales-agent/index.php'); ?>" <?php echo (strpos($currentPath, path_with_base('/onboarding/sales-agent')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>Sales ACP</a>
            <?php endif; ?>
            <?php 
            // Hide certain links on admin pages: move into admin menu
            $isAdminArea = (strpos($currentPath, path_with_base('/admin')) === 0);
            ?>
            <?php if ($isSalesAgent && !$isAdminArea): ?>
                <a href="<?php echo outbound_path('/admin/my-commissions.php'); ?>" <?php echo (strpos($currentPath, path_with_base('/admin/my-commissions')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>💰 My Commissions</a>
            <?php endif; ?>
            <?php if ($isOperations && !$isAdminArea): ?>
                <a href="<?php echo outbound_path('/operations/'); ?>" <?php echo (strpos($currentPath, path_with_base('/operations')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>🚚 Operations</a>
            <?php endif; ?>
            <?php if (($isAccountant || $isAdmin) && !$isAdminArea): ?>
                <a href="<?php echo outbound_path('/admin/accounting/'); ?>" <?php echo (strpos($currentPath, path_with_base('/admin/accounting')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>📊 Accounting</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a href="<?php echo outbound_path('/admin/'); ?>" <?php echo ($isAdminArea && strpos($currentPath, 'ai-chat-logs') === false && strpos($currentPath, 'legal') === false && strpos($currentPath, 'accounting') === false) ? 'aria-current="page" class="active"' : ''; ?>>Admin</a>
                <a href="<?php echo outbound_path('/admin/legal/'); ?>" <?php echo (strpos($currentPath, path_with_base('/admin/legal')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>⚖️ Legal</a>
                <?php if (!$isAdminArea): ?>
                    <a href="<?php echo outbound_path('/admin/ai-chat-logs.php'); ?>" <?php echo (strpos($currentPath, path_with_base('/admin/ai-chat-logs')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>AI Chat Logs</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($isLegalUser && !$isAdmin): ?>
                <a href="<?php echo outbound_path('/admin/legal/portal/'); ?>" <?php echo (strpos($currentPath, path_with_base('/admin/legal/portal')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>⚖️ Legal Portal</a>
            <?php endif; ?>
            <?php if ($uid): ?>
                <?php if (!$isLegalUser || $isAdmin): ?>
                    <a href="<?php echo $dashboard; ?>">Dashboard</a>
                <?php endif; ?>
                <a href="<?php echo outbound_path('/profile.php'); ?>" <?php echo (strpos($currentPath, path_with_base('/profile')) === 0) ? 'aria-current="page" class="active"' : ''; ?>>👤 Profile</a>
                <a href="<?php echo outbound_path('/logout.php'); ?>">Logout</a>
            <?php else: ?>
                <a href="<?php echo outbound_path('/login.php'); ?>" <?php echo ($currentPath === path_with_base('/login.php')) ? 'aria-current="page" class="active"' : ''; ?>>Login</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </nav>
    </div>
</header>

<?php 
// Check for admin impersonation
if (isset($_SESSION['admin_impersonation'])): 
    $impersonation = $_SESSION['admin_impersonation'];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$impersonation['target_user_id']]);
    $impersonatedUser = $stmt->fetch();
?>
    <div style="background: #dc2626; color: white; padding: 12px 0; text-align: center; position: relative; z-index: 1000;">
        <div class="container">
            <strong>🎭 ADMIN IMPERSONATION ACTIVE</strong> - 
            Viewing as: <?php echo htmlspecialchars($impersonatedUser['first_name'] . ' ' . $impersonatedUser['last_name']); ?> 
            (<?php echo htmlspecialchars($impersonatedUser['email']); ?>)
            <a href="<?php echo outbound_path('/admin/return-to-admin.php'); ?>" style="background: rgba(255,255,255,0.2); color: white; padding: 4px 12px; border-radius: 4px; text-decoration: none; margin-left: 16px;">
                ↩️ Return to Admin
            </a>
        </div>
    </div>
<?php endif; ?>

<?php
// Include admin simulation header
start_session();
include __DIR__ . '/../admin/admin-simulation-header.php';
?>

<?php if ($msg = flash_get('success')): ?>
    <div class="container"><div class="card" role="status" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-top:12px"><?php echo sanitize($msg); ?></div></div>
<?php endif; ?>
<?php if ($msg = flash_get('error')): ?>
    <div class="container"><div class="card" role="alert" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-top:12px"><?php echo sanitize($msg); ?></div></div>
<?php endif; ?>
<main class="site-main" id="main" role="main">
<script>
// Lightweight site heartbeat + basic crawler detection (client side)
(function(){
  const isBot = /bot|crawl|spider|slurp|bingpreview|mediapartners-google|semrush|ahrefs|yandex|duckduckbot/i.test(navigator.userAgent || '');
  let lastActivity = Date.now();
  ['click','scroll','keydown','mousemove','touchstart','touchmove'].forEach(ev => {
    window.addEventListener(ev, () => { lastActivity = Date.now(); }, {passive:true});
  });
  function sendHeartbeat(){
    const isIdle = (Date.now() - lastActivity) > 60000; // 60s idle
    const payload = {
      path: location.pathname + location.search,
      referrer: document.referrer || null,
      is_idle: isIdle,
      activity_score: isIdle ? 0 : 1
    };
    try {
      navigator.sendBeacon('<?php echo outbound_path('/api/site-heartbeat.php'); ?>', new Blob([JSON.stringify(payload)], {type:'application/json'}));
    } catch (e) {
      fetch('<?php echo outbound_path('/api/site-heartbeat.php'); ?>', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)}).catch(()=>{});
    }
  }
  sendHeartbeat();
  setInterval(sendHeartbeat, 30000); // every 30s
})();
</script>
