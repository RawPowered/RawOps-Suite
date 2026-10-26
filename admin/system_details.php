<?php
$SEO = [
    'title' => 'System Information — Admin',
    'description' => 'System health and configuration information.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

// Get system information from database and config (NOT from planning files)
$pdo = db();
$systemInfo = [];

try {
    // Get database information
    $systemInfo['database'] = [
        'status' => 'Connected',
        'name' => getenv('DB_NAME') ?: 'foggksfx_product',
        'host' => getenv('DB_HOST') ?: 'localhost',
    ];
    
    // Get table counts
    $systemInfo['tables'] = [
        'users' => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'wholesale_accounts' => $pdo->query('SELECT COUNT(*) FROM wholesale_accounts')->fetchColumn(),
        'products' => $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'orders' => $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'crm_leads' => $pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn(),
        'training_modules' => $pdo->query('SELECT COUNT(*) FROM training_modules')->fetchColumn(),
    ];
    
    // Get system config
    $systemInfo['config'] = [
        'environment' => PRODUCT_ENV,
        'asset_version' => ASSET_VER,
        'domain' => site_domain(),
        'base_path' => site_base_path() ?: '/',
        'site_url' => site_base_url(),
    ];
    
    // PHP Info
    $systemInfo['php'] = [
        'version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
    ];
    
} catch (Throwable $e) {
    $systemInfo['error'] = 'Unable to retrieve system information';
}
?>

<section class="section">
    <div class="container">
        <div style="max-width: 1200px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #eef2ff, #e8f5ff); border:1px solid #e5e7eb; padding:16px 20px; border-radius:14px; margin-bottom: 24px;">
                <h1 style="margin: 0; font-size: 28px; color: #1e40af;">⚙️ System Information</h1>
                <p style="margin: 8px 0 0 0; color: #6b7280;">Current system status and configuration</p>
            </div>

            <!-- Database Information -->
            <div class="card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0; color: #059669;">🗄️ Database Status</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div style="padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Status</div>
                        <div style="font-size: 20px; font-weight: 700; color: #059669;">✓ <?php echo $systemInfo['database']['status'] ?? 'Unknown'; ?></div>
                    </div>
                    <div style="padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Database</div>
                        <div style="font-size: 16px; font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($systemInfo['database']['name'] ?? 'N/A'); ?></div>
                    </div>
                    <div style="padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Host</div>
                        <div style="font-size: 16px; font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($systemInfo['database']['host'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Table Statistics -->
            <div class="card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0; color: #2563eb;">📊 Database Records</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                    <?php foreach ($systemInfo['tables'] ?? [] as $table => $count): ?>
                        <div style="padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;"><?php echo str_replace('_', ' ', $table); ?></div>
                            <div style="font-size: 24px; font-weight: 700; color: #2563eb;"><?php echo number_format($count); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- System Configuration -->
            <div class="card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0; color: #7c3aed;">🔧 System Configuration</h2>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <?php foreach ($systemInfo['config'] ?? [] as $key => $value): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px; font-weight: 600; color: #374151; text-transform: capitalize;"><?php echo str_replace('_', ' ', $key); ?></td>
                            <td style="padding: 12px; color: #1f2937; font-family: monospace;"><?php echo htmlspecialchars($value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- PHP Configuration -->
            <div class="card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0; color: #dc2626;">🐘 PHP Configuration</h2>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <?php foreach ($systemInfo['php'] ?? [] as $key => $value): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px; font-weight: 600; color: #374151; text-transform: capitalize;"><?php echo str_replace('_', ' ', $key); ?></td>
                            <td style="padding: 12px; color: #1f2937; font-family: monospace;"><?php echo htmlspecialchars($value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div style="margin-top: 24px; padding: 16px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;">
                <p style="margin: 0; color: #991b1b; font-size: 14px;">
                    <strong>🔒 Security Note:</strong> This page displays only safe system information. 
                    Sensitive configuration details, database schemas, and planning documents are never exposed through the web interface.
                </p>
            </div>
            
            <div style="margin-top: 16px;">
                <a href="<?php echo path_with_base('/admin/'); ?>" style="color: #059669; text-decoration: none; font-size: 14px;">
                    ← Back to Admin Dashboard
                </a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
