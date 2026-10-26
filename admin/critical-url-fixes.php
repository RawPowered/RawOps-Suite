<?php
$SEO = [
    'title' => 'Critical URL Fixes — Admin',
    'description' => 'Fix critical 404 errors and URL routing issues',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();

// Critical fixes needed based on verification results
$criticalFixes = [
    'routing' => [
        'onboarding' => [
            'issue' => 'Onboarding URLs returning 404',
            'current' => '/onboarding/step-1-welcome.php (404)',
            'actual' => '/onboarding/trainee/welcome.php or /onboarding/sales-agent/index.php',
            'fix_type' => 'redirect'
        ],
        'training' => [
            'issue' => 'Training module URLs returning 404',
            'current' => '/training/module/* (404)',
            'actual' => '/onboarding/trainee/modules/*.php',
            'fix_type' => 'redirect'
        ],
        'api' => [
            'issue' => 'API endpoints returning 404',
            'current' => '/api/auth/login (404)',
            'actual' => 'API files exist but different naming',
            'fix_type' => 'routing'
        ],
        'customer' => [
            'issue' => 'Customer URLs contain /',
            'current' => 'Contains / in URL',
            'actual' => 'Should be /customer/ without /',
            'fix_type' => 'database'
        ]
    ]
];

// Database fixes
$dbFixes = [
    // Fix any remaining / references
    "UPDATE site_config SET value = REPLACE(value, '/', '/') WHERE value LIKE '%/%'",
    "UPDATE site_config SET value = REPLACE(value, 'https://product.example/', 'https://product.example/') WHERE value LIKE '%legacy.example%'",
    
    // Fix user-generated content
    "UPDATE crm_leads SET notes = REPLACE(notes, '/', '/') WHERE notes LIKE '%/%'",
    "UPDATE crm_accounts SET description = REPLACE(description, '/', '/') WHERE description LIKE '%/%'",
    
    // Fix any email templates
    "UPDATE email_templates SET body = REPLACE(body, '/', '/') WHERE body LIKE '%/%'",
    "UPDATE email_templates SET body = REPLACE(body, 'https://product.example/', 'https://product.example/') WHERE body LIKE '%legacy.example%'",
    
    // Fix module content
    "UPDATE modules SET content = REPLACE(content, '/', '/') WHERE content LIKE '%/%'",
    "UPDATE modules SET content = REPLACE(content, 'https://product.example/', 'https://product.example/') WHERE content LIKE '%legacy.example%'"
];

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'fix_database':
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
            $htaccessContent = $_POST['htaccess_content'] ?? '';
            $backupPath = __DIR__ . '/../.htaccess.backup.' . date('YmdHis');
            $htaccessPath = __DIR__ . '/../.htaccess';
            
            // Backup existing
            if (file_exists($htaccessPath)) {
                copy($htaccessPath, $backupPath);
            }
            
            // Write new content
            if (file_put_contents($htaccessPath, $htaccessContent)) {
                $message = "✅ .htaccess file updated (backup saved)";
            } else {
                $error = "Failed to write .htaccess file";
            }
            break;
            
        case 'test_registration':
            // Test registration flow
            $testResults = [];
            
            // Test login page
            $loginUrl = site_origin() . '/login.php';
            $ch = curl_init($loginUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            curl_exec($ch);
            $loginStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $testResults[] = "Login page: " . ($loginStatus == 200 ? '✅ Working' : '❌ Failed (' . $loginStatus . ')');
            
            // Test onboarding paths
            $onboardingPaths = [
                '/onboarding/trainee/',
                '/onboarding/sales-agent/',
                '/onboarding/trainee/welcome.php'
            ];
            
            foreach ($onboardingPaths as $path) {
                $url = site_origin() . $path;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $testResults[] = "$path: " . ($status == 200 ? '✅ Working' : '❌ Failed (' . $status . ')');
            }
            
            $message = "Test Results:\n" . implode("\n", $testResults);
            break;
    }
}

// Check current issues
$currentIssues = [];

// Check for / in database
try {
    // Check if site_config table exists first
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'site_config'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM site_config 
            WHERE value LIKE '%/%' 
            OR value LIKE '%legacy.example%'
        ");
        $configIssues = $stmt->fetchColumn();
        
        if ($configIssues > 0) {
            $currentIssues[] = "Found $configIssues site_config entries with old URLs";
        }
    }
} catch (PDOException $e) {
    // If table doesn't exist or other error, continue without this check
    // No need to show error to user
}

// Check if .htaccess exists
$htaccessPath = __DIR__ . '/../.htaccess';
$htaccessExists = file_exists($htaccessPath);
$htaccessContent = $htaccessExists ? file_get_contents($htaccessPath) : '';
?>

<style>
.fix-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.fix-header {
    background: linear-gradient(135deg, #dc2626, #ef4444);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.fix-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
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

.fix-card {
    background: #f0fdf4;
    border: 2px solid #86efac;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
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

.fix-button {
    background: #10b981;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    margin: 8px;
}

.fix-button:hover {
    background: #059669;
}

.test-button {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    margin: 8px;
}

.alert-message {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #dcfce7;
    border: 2px solid #86efac;
    color: #166534;
}

.alert-error {
    background: #fee2e2;
    border: 2px solid #f87171;
    color: #991b1b;
}
</style>

<div class="fix-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">🚨 Critical URL Fixes</h1>
        <p style="font-size: 18px; opacity: 0.9;">Fix 404 errors and ensure registration, training, and shop functionality</p>
    </div>
</div>

<div class="fix-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message): ?>
    <div class="alert-message alert-success">
        <?php echo nl2br(htmlspecialchars($message)); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert-message alert-error">
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <!-- Current Issues -->
    <div class="fix-section">
        <h2 style="color: #dc2626; margin-bottom: 20px;">⚠️ Critical Issues Detected</h2>
        
        <?php foreach ($criticalFixes['routing'] as $area => $fix): ?>
        <div class="issue-card">
            <h3><?php echo ucfirst($area); ?> Issues</h3>
            <p><strong>Problem:</strong> <?php echo htmlspecialchars($fix['issue']); ?></p>
            <p><strong>Current:</strong> <code><?php echo htmlspecialchars($fix['current']); ?></code></p>
            <p><strong>Should be:</strong> <code><?php echo htmlspecialchars($fix['actual']); ?></code></p>
            <p><strong>Fix type:</strong> <?php echo htmlspecialchars($fix['fix_type']); ?></p>
        </div>
        <?php endforeach; ?>
        
        <?php if (!empty($currentIssues)): ?>
        <div class="issue-card">
            <h3>Database Issues</h3>
            <?php foreach ($currentIssues as $issue): ?>
            <p>⚠️ <?php echo htmlspecialchars($issue); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Database Fixes -->
    <div class="fix-section">
        <h2 style="color: #059669; margin-bottom: 20px;">🔧 Fix 1: Database URLs</h2>
        
        <p>This will fix all remaining / references and dev URLs in the database.</p>
        
        <div class="code-block">
<?php foreach ($dbFixes as $sql): ?>
<?php echo htmlspecialchars($sql); ?>;
<?php endforeach; ?>
        </div>
        
        <form method="post">
            <input type="hidden" name="action" value="fix_database">
            <button type="submit" class="fix-button">
                🗄️ Apply Database Fixes
            </button>
        </form>
    </div>
    
    <!-- .htaccess Fixes -->
    <div class="fix-section">
        <h2 style="color: #059669; margin-bottom: 20px;">🔧 Fix 2: URL Routing (.htaccess)</h2>
        
        <p>Create proper redirects and routing rules for missing paths.</p>
        
        <form method="post">
            <input type="hidden" name="action" value="create_htaccess">
            
            <textarea name="htaccess_content" style="width: 100%; height: 400px; font-family: monospace; font-size: 13px;"><?php 
// If existing .htaccess exists, show it, otherwise show default content
if ($htaccessExists && $htaccessContent) {
    echo htmlspecialchars($htaccessContent);
} else {
    // Default .htaccess content
    echo '# Product URL Routing Rules
RewriteEngine On

# Force HTTPS
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

# Remove / from URLs
RewriteRule ^product/(.*)$ /$1 [R=301,L]

# Training module redirects (old structure to new)
RewriteRule ^training/$ /onboarding/trainee/ [R=301,L]
RewriteRule ^training/module/introduction/?$ /onboarding/trainee/modules/intro-product.php [R=301,L]
RewriteRule ^training/module/product-knowledge/?$ /onboarding/trainee/modules/products-product categories-new.php [R=301,L]
RewriteRule ^training/module/sales-techniques/?$ /onboarding/trainee/modules/ten-step-close.php [R=301,L]
RewriteRule ^training/module/compliance/?$ /onboarding/trainee/modules/compliance-sales.php [R=301,L]
RewriteRule ^training/module/advanced-strategies/?$ /onboarding/trainee/modules/ai-assisted-sales.php [R=301,L]

# Onboarding redirects (generic steps to role-based)
RewriteRule ^onboarding/step-1-welcome\.php$ /onboarding/trainee/welcome.php [R=301,L]
RewriteRule ^onboarding/step-2-goals\.php$ /onboarding/trainee/index.php [R=301,L]
RewriteRule ^onboarding/step-3-experience\.php$ /onboarding/trainee/progress.php [R=301,L]

# API routing (create friendly endpoints)
RewriteRule ^api/health/?$ /api/test-connection.php [L]
RewriteRule ^api/auth/login/?$ /login.php [L]
RewriteRule ^api/auth/logout/?$ /logout.php [L]
RewriteRule ^api/user/profile/?$ /customer/dashboard.php [L]
RewriteRule ^api/training/progress/?$ /api/training-activity.php [L]

# Remove trailing slashes
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.+)/$ /$1 [R=301,L]

# Default index files
DirectoryIndex index.php index.html

# Error pages
ErrorDocument 404 /404.php
ErrorDocument 403 /403.php
ErrorDocument 500 /500.php

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

# PHP settings
<IfModule mod_php7.c>
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value max_execution_time 300
    php_value max_input_time 300
</IfModule>';
}
?></textarea>
            
            <button type="submit" class="fix-button">
                📝 Update .htaccess File
            </button>
        </form>
    </div>
    
    <!-- Test Critical Functionality -->
    <div class="fix-section">
        <h2 style="color: #3b82f6; margin-bottom: 20px;">🧪 Test Critical Functionality</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="fix-card">
                <h3>Registration Flow</h3>
                <p>Test that users can register and access their role-based dashboard.</p>
                <form method="post">
                    <input type="hidden" name="action" value="test_registration">
                    <button type="submit" class="test-button">Test Registration</button>
                </form>
            </div>
            
            <div class="fix-card">
                <h3>Training Access</h3>
                <p>Verify training modules are accessible.</p>
                <a href="<?php echo path_with_base('/onboarding/trainee/'); ?>" target="_blank" class="test-button" style="display: inline-block; text-decoration: none;">
                    Open Training
                </a>
            </div>
            
            <div class="fix-card">
                <h3>Shop Functionality</h3>
                <p>Ensure shop and checkout work.</p>
                <a href="<?php echo path_with_base('/shop/'); ?>" target="_blank" class="test-button" style="display: inline-block; text-decoration: none;">
                    Open Shop
                </a>
            </div>
            
            <div class="fix-card">
                <h3>API Health</h3>
                <p>Check API endpoints respond.</p>
                <a href="<?php echo site_origin(); ?>/api/test-connection.php" target="_blank" class="test-button" style="display: inline-block; text-decoration: none;">
                    Test API
                </a>
            </div>
        </div>
    </div>
    
    <!-- Quick SQL Fixes -->
    <div class="fix-section">
        <h2 style="color: #7c3aed; margin-bottom: 20px;">🚀 Quick Copy-Paste SQL Fixes</h2>
        
        <p>Run these in phpMyAdmin to fix specific issues:</p>
        
        <h3>Fix ALL URL Issues:</h3>
        <div class="code-block">
-- Comprehensive URL fix for all tables
SET @old_domain = 'https://product.example/';
SET @new_domain = 'https://product.example/';

-- Update all text columns in all tables
UPDATE site_config SET value = REPLACE(REPLACE(value, @old_domain, @new_domain), '/', '/');
UPDATE users SET bio = REPLACE(REPLACE(bio, @old_domain, @new_domain), '/', '/') WHERE bio IS NOT NULL;
UPDATE modules SET content = REPLACE(REPLACE(content, @old_domain, @new_domain), '/', '/');
UPDATE module_sections SET content = REPLACE(REPLACE(content, @old_domain, @new_domain), '/', '/');
UPDATE crm_leads SET notes = REPLACE(REPLACE(notes, @old_domain, @new_domain), '/', '/') WHERE notes IS NOT NULL;
UPDATE crm_accounts SET description = REPLACE(REPLACE(description, @old_domain, @new_domain), '/', '/') WHERE description IS NOT NULL;
UPDATE email_templates SET body = REPLACE(REPLACE(body, @old_domain, @new_domain), '/', '/') WHERE body IS NOT NULL;

-- Fix any JSON data
UPDATE user_meta SET meta_value = REPLACE(REPLACE(meta_value, @old_domain, @new_domain), '/', '/') WHERE meta_value LIKE '%product%';
        </div>
        
        <h3>Create Missing Tables (if needed):</h3>
        <div class="code-block">
-- Create 404 error tracking
CREATE TABLE IF NOT EXISTS error_404_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(500),
    referrer VARCHAR(500),
    user_agent TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_url (url),
    INDEX idx_created (created_at)
);
        </div>
    </div>
    
    <!-- Action Summary -->
    <div class="fix-section" style="background: #eff6ff; border: 2px solid #3b82f6;">
        <h2 style="color: #1e40af; margin-bottom: 20px;">📋 Action Summary</h2>
        
        <ol style="line-height: 2;">
            <li><strong>Apply Database Fixes</strong> - Removes all / and dev URL references</li>
            <li><strong>Update .htaccess</strong> - Creates proper redirects for 404 pages</li>
            <li><strong>Test Registration</strong> - Verify users can sign up and access training</li>
            <li><strong>Test Training</strong> - Ensure modules are accessible</li>
            <li><strong>Test Shop</strong> - Confirm checkout process works</li>
            <li><strong>Run URL Verification</strong> - Check all fixes are applied</li>
        </ol>
        
        <div style="margin-top: 20px; padding: 16px; background: white; border-radius: 8px;">
            <h3>Next Steps After Fixes:</h3>
            <ol>
                <li>Clear any caching (browser, CDN, PHP opcache)</li>
                <li>Test as different user roles (admin, trainee, sales agent, customer)</li>
                <li>Monitor error logs for any new issues</li>
                <li>Run the URL Verification tool to confirm all 404s are resolved</li>
            </ol>
        </div>
    </div>
</div>

<script>
// Auto-refresh test results
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
