<?php
// Simple version with better error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

$SEO = [
    'title' => 'Critical URL Fixes — Admin',
    'description' => 'Fix critical 404 errors and URL routing issues',
    'type' => 'website'
];

// Check if required files exist
if (!file_exists(__DIR__ . '/../required/header.php')) {
    die('Error: Required header.php file not found');
}

require_once __DIR__ . '/../required/header.php';

// Check if admin function exists
if (!function_exists('require_admin_or_redirect')) {
    die('Error: Admin functions not available');
}

require_admin_or_redirect(path_with_base('/admin/'));

if (!function_exists('ensure_trailing_slash')) {
    function ensure_trailing_slash(string $value): string
    {
        return rtrim($value, "/") . "/";
    }
}

if (!function_exists('normalize_path_prefix')) {
    function normalize_path_prefix(?string $path): string
    {
        if ($path === null) {
            return '';
        }
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return '';
        }
        $trimmed = trim($trimmed, '/');
        return '/' . $trimmed;
    }
}

if (!function_exists('extract_legacy_entry')) {
    function extract_legacy_entry(string $url): ?array
    {
        $parsed = @parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $scheme = strtolower((string)$parsed['scheme']);
        $host = strtolower((string)$parsed['host']);
        $origin = $scheme . '://' . $host;
        if (!empty($parsed['port'])) {
            $origin .= ':' . intval($parsed['port']);
        }
        $origin = ensure_trailing_slash($origin);

        $pathPrefix = '';
        if (!empty($parsed['path'])) {
            $trimmedPath = trim((string)$parsed['path'], '/');
            if ($trimmedPath !== '') {
                $segments = explode('/', $trimmedPath);
                if (!empty($segments)) {
                    $pathPrefix = '/' . $segments[0];
                }
            }
        }

        $baseUrl = $origin;
        if ($pathPrefix !== '') {
            $baseUrl .= ltrim($pathPrefix, '/') . '/';
        }

        return [
            'origin' => $origin,
            'path_prefix' => $pathPrefix,
            'base_url' => ensure_trailing_slash($baseUrl),
        ];
    }
}

if (!function_exists('discover_legacy_parameters')) {
    function discover_legacy_parameters(PDO $pdo, string $currentBaseUrl): array
    {
        $legacyBaseUrls = [];
        $legacyPathPrefixes = [];
        $pattern = '#https?://[^"\'\s<>]+#i';
        $sources = [
            ['table' => 'site_config', 'column' => 'value'],
            ['table' => 'email_templates', 'column' => 'body'],
            ['table' => 'modules', 'column' => 'content'],
        ];

        foreach ($sources as $source) {
            try {
                $sql = sprintf('SELECT %s FROM %s WHERE %s LIKE "http%%" LIMIT 200',
                    $source['column'],
                    $source['table'],
                    $source['column']
                );
                $stmt = $pdo->query($sql);
                if (!$stmt) {
                    continue;
                }
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    foreach ($row as $value) {
                        if (!is_string($value) || $value === '') {
                            continue;
                        }
                        if (!preg_match_all($pattern, $value, $matches)) {
                            continue;
                        }
                        foreach ($matches[0] as $matchUrl) {
                            $entry = extract_legacy_entry($matchUrl);
                            if (!$entry) {
                                continue;
                            }
                            $baseUrl = $entry['base_url'];
                            if ($baseUrl === ensure_trailing_slash($currentBaseUrl)) {
                                continue;
                            }
                            $legacyBaseUrls[$baseUrl] = $baseUrl;
                            if (!empty($entry['path_prefix']) && $entry['path_prefix'] !== '/') {
                                $legacyPathPrefixes[$entry['path_prefix']] = $entry['path_prefix'];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return [
            'base_urls' => array_values($legacyBaseUrls),
            'path_prefixes' => array_values($legacyPathPrefixes),
        ];
    }
}

if (!function_exists('normalize_domain_list')) {
    function normalize_domain_list($input, string $currentBaseUrl): array
    {
        $values = [];
        if (is_string($input)) {
            $candidate = preg_split('/[\r\n,]+/', $input);
            if ($candidate !== false) {
                $values = $candidate;
            }
        } elseif (is_array($input)) {
            $values = $input;
        }

        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim((string)$value);
            if ($trimmed === '') {
                continue;
            }
            $entry = extract_legacy_entry($trimmed);
            if (!$entry) {
                continue;
            }
            $baseUrl = $entry['base_url'];
            if ($baseUrl === ensure_trailing_slash($currentBaseUrl)) {
                continue;
            }
            $normalized[$baseUrl] = $baseUrl;
        }

        return array_values($normalized);
    }
}

if (!function_exists('normalize_path_list')) {
    function normalize_path_list($input): array
    {
        $values = [];
        if (is_string($input)) {
            $candidate = preg_split('/[\r\n,]+/', $input);
            if ($candidate !== false) {
                $values = $candidate;
            }
        } elseif (is_array($input)) {
            $values = $input;
        }

        $normalized = [];
        foreach ($values as $value) {
            $prefix = normalize_path_prefix($value);
            if ($prefix === '') {
                continue;
            }
            $normalized[$prefix] = $prefix;
        }

        return array_values($normalized);
    }
}

if (!function_exists('build_domain_replace_statements')) {
    function build_domain_replace_statements(PDO $pdo, array $legacyBaseUrls, string $currentBaseUrl): array
    {
        $statements = [];
        if (empty($legacyBaseUrls)) {
            return $statements;
        }

        $targets = [
            ['table' => 'site_config', 'column' => 'value'],
            ['table' => 'email_templates', 'column' => 'body'],
            ['table' => 'modules', 'column' => 'content'],
            ['table' => 'module_sections', 'column' => 'content'],
            ['table' => 'crm_leads', 'column' => 'notes'],
            ['table' => 'users', 'column' => 'bio'],
        ];

        foreach ($legacyBaseUrls as $legacyBaseUrl) {
            $quotedLegacy = $pdo->quote($legacyBaseUrl);
            $quotedCurrent = $pdo->quote($currentBaseUrl);
            $likePattern = $pdo->quote('%' . trim($legacyBaseUrl, '/') . '%');

            foreach ($targets as $target) {
                $statements[] = sprintf(
                    'UPDATE %s SET %s = REPLACE(%s, %s, %s) WHERE %s LIKE %s',
                    $target['table'],
                    $target['column'],
                    $target['column'],
                    $quotedLegacy,
                    $quotedCurrent,
                    $target['column'],
                    $likePattern
                );
            }
        }

        return array_values(array_unique($statements));
    }
}

if (!function_exists('build_path_replace_statements')) {
    function build_path_replace_statements(PDO $pdo, array $legacyPathPrefixes, string $currentBasePath): array
    {
        $statements = [];
        if (empty($legacyPathPrefixes)) {
            return $statements;
        }

        $targets = [
            ['table' => 'site_config', 'column' => 'value'],
            ['table' => 'email_templates', 'column' => 'body'],
            ['table' => 'modules', 'column' => 'content'],
            ['table' => 'module_sections', 'column' => 'content'],
            ['table' => 'crm_leads', 'column' => 'notes'],
            ['table' => 'users', 'column' => 'bio'],
        ];

        $replacement = $currentBasePath === '' ? '/' : $currentBasePath . '/';

        foreach ($legacyPathPrefixes as $prefix) {
            $source = rtrim($prefix, '/') . '/';
            $quotedLegacy = $pdo->quote($source);
            $quotedCurrent = $pdo->quote($replacement);
            $likePattern = $pdo->quote('%' . trim($source, '/') . '%');

            foreach ($targets as $target) {
                $statements[] = sprintf(
                    'UPDATE %s SET %s = REPLACE(%s, %s, %s) WHERE %s LIKE %s',
                    $target['table'],
                    $target['column'],
                    $target['column'],
                    $quotedLegacy,
                    $quotedCurrent,
                    $target['column'],
                    $likePattern
                );
            }
        }

        return array_values(array_unique($statements));
    }
}

if (!function_exists('build_default_htaccess_content')) {
    function build_default_htaccess_content(?string $legacyPath, string $currentBasePath): string
    {
        $lines = [
            '# Product URL Routing Rules',
            'RewriteEngine On',
            '',
            '# Force HTTPS',
            'RewriteCond %{HTTPS} !=on',
            'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]',
            '',
        ];

        if ($legacyPath !== null && $legacyPath !== '') {
            $legacyPattern = '^' . ltrim($legacyPath, '/') . '/(.*)$';
            $targetBasePath = normalize_path_prefix($currentBasePath);
            $target = $targetBasePath === '' ? '/$1' : $targetBasePath . '/$1';
            $lines[] = '# Legacy base path redirect';
            $lines[] = 'RewriteRule ' . $legacyPattern . ' ' . $target . ' [R=301,L]';
            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '# Training module redirects',
            'RewriteRule ^training/$ ' . path_with_base('/onboarding/trainee/') . ' [R=301,L]',
            'RewriteRule ^training/module/introduction/?$ ' . path_with_base('/onboarding/trainee/modules/intro-product.php') . ' [R=301,L]',
            'RewriteRule ^training/module/product-knowledge/?$ ' . path_with_base('/onboarding/trainee/modules/products-product categories-new.php') . ' [R=301,L]',
            'RewriteRule ^training/module/sales-techniques/?$ ' . path_with_base('/onboarding/trainee/modules/ten-step-close.php') . ' [R=301,L]',
            'RewriteRule ^training/module/compliance/?$ ' . path_with_base('/onboarding/trainee/modules/compliance-sales.php') . ' [R=301,L]',
            'RewriteRule ^training/module/advanced-strategies/?$ ' . path_with_base('/onboarding/trainee/modules/ai-assisted-sales.php') . ' [R=301,L]',
            '',
            '# Onboarding redirects',
            'RewriteRule ^onboarding/step-1-welcome\.php$ ' . path_with_base('/onboarding/trainee/welcome.php') . ' [R=301,L]',
            'RewriteRule ^onboarding/step-2-goals\.php$ ' . path_with_base('/onboarding/trainee/index.php') . ' [R=301,L]',
            'RewriteRule ^onboarding/step-3-experience\.php$ ' . path_with_base('/onboarding/trainee/progress.php') . ' [R=301,L]',
            '',
            '# API routing',
            'RewriteRule ^api/health/?$ ' . path_with_base('/api/test-connection.php') . ' [L]',
            'RewriteRule ^api/auth/login/?$ ' . path_with_base('/login.php') . ' [L]',
            'RewriteRule ^api/auth/logout/?$ ' . path_with_base('/logout.php') . ' [L]',
            'RewriteRule ^api/user/profile/?$ ' . path_with_base('/customer/dashboard.php') . ' [L]',
            'RewriteRule ^api/training/progress/?$ ' . path_with_base('/api/training-activity.php') . ' [L]',
            '',
            '# Default index files',
            'DirectoryIndex index.php index.html',
            '',
            '# Error pages',
            'ErrorDocument 404 ' . path_with_base('/404.php'),
            'ErrorDocument 403 ' . path_with_base('/403.php'),
            'ErrorDocument 500 ' . path_with_base('/500.php'),
        ]);

        return implode("\n", $lines);
    }
}

if (!function_exists('build_quick_sql_template')) {
    function build_quick_sql_template(array $statements): string
    {
        if (empty($statements)) {
            return "-- No SQL replacements were generated automatically.\n-- Provide legacy base URLs or paths and re-run the scan.";
        }
        return implode("\n", $statements);
    }
}

// Check if db function exists
if (!function_exists('db')) {
    die('Error: Database function not available');
}

$pdo = db();

$currentBaseUrl = ensure_trailing_slash(site_base_url());
$currentBasePath = normalize_path_prefix(site_base_path());

$detectedLegacy = discover_legacy_parameters($pdo, $currentBaseUrl);
$legacyDomains = normalize_domain_list($detectedLegacy['base_urls'] ?? [], $currentBaseUrl);
$legacyPaths = normalize_path_list($detectedLegacy['path_prefixes'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (array_key_exists('legacy_domains', $_POST)) {
        $legacyDomains = normalize_domain_list($_POST['legacy_domains'], $currentBaseUrl);
    }
    if (array_key_exists('legacy_paths', $_POST)) {
        $legacyPaths = normalize_path_list($_POST['legacy_paths']);
    }
}

if (empty($legacyDomains)) {
    $legacyDomains = [];
}

if (empty($legacyPaths) && $currentBasePath !== '') {
    $legacyPaths[] = $currentBasePath;
}


$domainReplaceStatements = build_domain_replace_statements($pdo, $legacyDomains, $currentBaseUrl);
$pathReplaceStatements = build_path_replace_statements($pdo, $legacyPaths, $currentBasePath);

$databaseReplaceStatements = array_values(array_unique(array_merge(
    $domainReplaceStatements,
    $pathReplaceStatements
)));

$htaccessDefaultContent = build_default_htaccess_content($legacyPaths[0] ?? null, $currentBasePath);
$quickSqlTemplate = build_quick_sql_template($databaseReplaceStatements);

// Initialize variables
$message = '';
$error = '';
$currentIssues = [];
$configIssues = 0;

// Try to check for database issues
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM site_config 
        WHERE value LIKE '%/%' 
        OR value LIKE '%legacy.example%'
    ");
    if ($stmt) {
        $configIssues = $stmt->fetchColumn();
        if ($configIssues > 0) {
            $currentIssues[] = "Found $configIssues site_config entries with old URLs";
        }
    }
} catch (PDOException $e) {
    // Table might not exist, that's okay
    $currentIssues[] = "Could not check site_config table: " . $e->getMessage();
}

// Check if .htaccess exists
$htaccessPath = __DIR__ . '/../.htaccess';
$htaccessExists = file_exists($htaccessPath);
$htaccessContent = '';
if ($htaccessExists) {
    $htaccessContent = @file_get_contents($htaccessPath);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'fix_database':
            $dbFixes = $databaseReplaceStatements;

            if (empty($dbFixes)) {
                $error = "No replacement statements were generated. Please provide at least one legacy domain or path.";
                break;
            }
            
            $fixedCount = 0;
            $errors = [];
            
            foreach ($dbFixes as $sql) {
                try {
                    $pdo->exec($sql);
                    $fixedCount += $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
                } catch (PDOException $e) {
                    $errors[] = "SQL Error: " . $e->getMessage();
                }
            }
            
            if (empty($errors)) {
                $message = "✅ Fixed $fixedCount database entries";
            } else {
                $error = "Some fixes failed: " . implode(', ', $errors);
            }
            break;
            
        case 'create_htaccess':
            $htaccessNewContent = $_POST['htaccess_content'] ?? '';
            $backupPath = __DIR__ . '/../.htaccess.backup.' . date('YmdHis');
            
            // Backup existing
            if ($htaccessExists) {
                @copy($htaccessPath, $backupPath);
            }
            
            // Write new content
            if (@file_put_contents($htaccessPath, $htaccessNewContent)) {
                $message = "✅ .htaccess file updated (backup saved)";
            } else {
                $error = "Failed to write .htaccess file - check permissions";
            }
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Critical URL Fixes - Admin</title>
    <style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        margin: 0;
        padding: 0;
        background: #f5f5f5;
    }
    
    .fix-header {
        background: linear-gradient(135deg, #dc2626, #ef4444);
        color: white;
        padding: 40px 20px;
        text-align: center;
    }
    
    .fix-header h1 {
        margin: 0 0 10px 0;
        font-size: 36px;
    }
    
    .fix-container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 0 20px;
    }
    
    .fix-section {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .issue-card {
        background: #fef2f2;
        border: 2px solid #f87171;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 16px;
    }
    
    .success-message {
        background: #dcfce7;
        border: 2px solid #86efac;
        color: #166534;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .error-message {
        background: #fee2e2;
        border: 2px solid #f87171;
        color: #991b1b;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .code-block {
        background: #1f2937;
        color: #f9fafb;
        padding: 16px;
        border-radius: 8px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        overflow-x: auto;
        margin: 12px 0;
    }
    
    .button {
        background: #10b981;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        margin: 8px 8px 8px 0;
    }
    
    .button:hover {
        background: #059669;
    }
    
    .button-blue {
        background: #3b82f6;
    }
    
    .button-blue:hover {
        background: #2563eb;
    }
    
    textarea {
        width: 100%;
        height: 400px;
        font-family: monospace;
        font-size: 13px;
        padding: 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        box-sizing: border-box;
    }
    </style>
</head>
<body>
    <div class="fix-header">
        <h1>🚨 Critical URL Fixes</h1>
        <p>Fix 404 errors and ensure registration, training, and shop functionality</p>
    </div>
    
    <div class="fix-container">
        <?php if ($message): ?>
        <div class="success-message">
            <?php echo nl2br(htmlspecialchars($message)); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <!-- Current Issues -->
        <div class="fix-section">
            <h2>⚠️ Critical Issues Detected</h2>
            
            <div class="issue-card">
                <h3>Training Module 404s</h3>
                <p>URLs like <code><?php echo htmlspecialchars(path_with_base('/training/module/*')); ?></code> return 404 because files are actually at <code><?php echo htmlspecialchars(path_with_base('/onboarding/trainee/modules/*')); ?></code></p>
                <p><strong>Fix:</strong> Add .htaccess redirects</p>
            </div>
            
            <div class="issue-card">
                <h3>Onboarding 404s</h3>
                <p>Generic onboarding URLs like <code><?php echo htmlspecialchars(path_with_base('/onboarding/step-1-welcome.php')); ?></code> don't exist</p>
                <p>Actual files are at <code><?php echo htmlspecialchars(path_with_base('/onboarding/trainee/welcome.php')); ?></code></p>
                <p><strong>Fix:</strong> Add .htaccess redirects</p>
            </div>
            
            <div class="issue-card">
                <h3>API Endpoint 404s</h3>
                <p>API URLs like <code><?php echo htmlspecialchars(path_with_base('/api/auth/login')); ?></code> don't exist</p>
                <p><strong>Fix:</strong> Create routing rules</p>
            </div>
            
            <?php if (!empty($currentIssues)): ?>
            <div class="issue-card">
                <h3>Database Issues</h3>
                <?php foreach ($currentIssues as $issue): ?>
                <p>⚠️ <?php echo htmlspecialchars($issue); ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Database Fix -->
        <div class="fix-section">
            <h2>🔧 Fix 1: Clean Database URLs</h2>
            <p>Replace legacy domains and path prefixes with current config values.</p>
            <p>Detected current base URL: <code><?php echo htmlspecialchars($currentBaseUrl); ?></code></p>
            <p>Detected legacy domains:
                <?php if (!empty($legacyDomains)): ?>
                    <code><?php echo htmlspecialchars(implode(', ', $legacyDomains)); ?></code>
                <?php else: ?>
                    <em>none detected</em>
                <?php endif; ?>
            </p>
            <p>Detected legacy paths:
                <?php if (!empty($legacyPaths)): ?>
                    <code><?php echo htmlspecialchars(implode(', ', $legacyPaths)); ?></code>
                <?php else: ?>
                    <em>none detected</em>
                <?php endif; ?>
            </p>
            <p>Update the lists below to refine replacements:</p>
            <form method="post" class="legacy-inputs">
                <input type="hidden" name="action" value="update_legacy_inputs">
                <div>
                    <label for="legacy_domains">Legacy Domains (one per line):</label><br>
                    <textarea name="legacy_domains" id="legacy_domains" rows="4"><?php echo htmlspecialchars(implode("\n", $legacyDomains)); ?></textarea>
                </div>
                <div style="margin-top:12px;">
                    <label for="legacy_paths">Legacy Path Prefixes (one per line):</label><br>
                    <textarea name="legacy_paths" id="legacy_paths" rows="3"><?php echo htmlspecialchars(implode("\n", $legacyPaths)); ?></textarea>
                </div>
                <button type="submit" class="button" style="margin-top:12px;">Update Legacy Values</button>
            </form>
            
            <div class="code-block">
<?php echo htmlspecialchars($quickSqlTemplate); ?>
            </div>
            
            <form method="post">
                <input type="hidden" name="action" value="fix_database">
                <button type="submit" class="button">🗄️ Apply Database Fixes</button>
            </form>
        </div>
        
        <!-- .htaccess Fix -->
        <div class="fix-section">
            <h2>🔧 Fix 2: Add URL Redirects (.htaccess)</h2>
            <p>Create redirects to map old/expected URLs to actual file locations.</p>
            
            <form method="post">
                <input type="hidden" name="action" value="create_htaccess">
                
                <textarea name="htaccess_content"><?php 
if ($htaccessExists && $htaccessContent) {
    echo htmlspecialchars($htaccessContent);
} else {
    echo htmlspecialchars($htaccessDefaultContent);
}
?></textarea>
                
                <button type="submit" class="button">📝 Update .htaccess File</button>
            </form>
        </div>
        
        <!-- Quick Links -->
        <div class="fix-section">
            <h2>🧪 Test After Fixes</h2>
            
            <p>After applying fixes, test these critical functions:</p>
            
            <a href="<?php echo htmlspecialchars(path_with_base('/onboarding/trainee/')); ?>" target="_blank" class="button button-blue">Test Training</a>
            <a href="<?php echo htmlspecialchars(path_with_base('/shop/')); ?>" target="_blank" class="button button-blue">Test Shop</a>
            <a href="<?php echo htmlspecialchars(path_with_base('/login.php')); ?>" target="_blank" class="button button-blue">Test Login</a>
            <a href="<?php echo htmlspecialchars(path_with_base('/api/test-connection.php')); ?>" target="_blank" class="button button-blue">Test API</a>
        </div>
        
        <!-- Quick SQL -->
        <div class="fix-section">
            <h2>🚀 Quick SQL Fix (Copy & Run in phpMyAdmin)</h2>
            
            <div class="code-block">
<?php echo htmlspecialchars($quickSqlTemplate); ?>
            </div>
        </div>
    </div>
</body>
</html>


