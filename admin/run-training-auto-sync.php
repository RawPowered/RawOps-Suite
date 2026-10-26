<?php
/**
 * Training Auto-Sync Runner
 * 
 * Executes auto-sync for all training-enabled products
 * Run this after product updates to ensure training modules stay synchronized
 */

$SEO = [
    'title' => 'Training Auto-Sync — Admin',
    'description' => 'Synchronize product data with training modules',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

// Load auto-sync system
require_once __DIR__ . '/../required/training-auto-sync.php';

$message = '';
$error = '';
$sync_results = null;

// Handle manual sync request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_sync'])) {
    try {
        $sync_results = auto_sync_all_training_products();
        
        if ($sync_results['success']) {
            $message = "Auto-sync completed successfully! Processed {$sync_results['products_processed']} products.";
        } else {
            $error = "Auto-sync completed with issues. {$sync_results['products_failed']} products failed.";
        }
    } catch (Exception $e) {
        $error = 'Auto-sync failed: ' . $e->getMessage();
    }
}

// Get current sync status
$sync_status = get_training_sync_status();
?>

<style>
.sync-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}
.sync-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
}
.status-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.status-good { border-left: 4px solid #16a34a; }
.status-warning { border-left: 4px solid #f59e0b; }
.status-error { border-left: 4px solid #dc2626; }
.status-unknown { border-left: 4px solid #6b7280; }
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin: 16px 0;
}
.stat-item {
    background: #f9fafb;
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}
.stat-number {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
}
.stat-label {
    font-size: 14px;
    color: #6b7280;
    margin-top: 4px;
}
.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-success {
    background: #dcfce7;
    color: #14532d;
    border: 1px solid #16a34a;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #dc2626;
}
.btn {
    background: #3b82f6;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
}
.btn:hover {
    background: #2563eb;
}
.btn-secondary {
    background: #6b7280;
}
.btn-secondary:hover {
    background: #4b5563;
}
.issues-list {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 16px;
    margin-top: 16px;
}
.issue-item {
    padding: 8px 0;
    border-bottom: 1px solid #fecaca;
}
.issue-item:last-child {
    border-bottom: none;
}
.code-block {
    background: #1f2937;
    color: #e5e7eb;
    padding: 16px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    overflow-x: auto;
    white-space: pre;
    line-height: 1.5;
}
</style>

<div class="sync-container">
    <div class="sync-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin: 0 0 8px 0;">🔄 Training Auto-Sync</h1>
                <p style="margin: 0; opacity: 0.9;">Synchronize product data with training modules</p>
            </div>
            <a href="<?php echo path_with_base('/admin/products.php'); ?>" class="btn btn-secondary">
                ← Back to Products
            </a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Current Sync Status -->
    <div class="status-card status-<?php echo $sync_status['overall_status']; ?>">
        <h3 style="margin: 0 0 16px 0;">📊 Current Sync Status</h3>
        
        <div class="stat-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $sync_status['total_products']; ?></div>
                <div class="stat-label">Total Products</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $sync_status['training_enabled']; ?></div>
                <div class="stat-label">Training Enabled</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $sync_status['sync_issues']; ?></div>
                <div class="stat-label">Sync Issues</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" style="color: <?php 
                    echo $sync_status['overall_status'] === 'good' ? '#16a34a' : 
                         ($sync_status['overall_status'] === 'warning' ? '#f59e0b' : '#dc2626'); 
                ?>;">
                    <?php echo ucfirst($sync_status['overall_status']); ?>
                </div>
                <div class="stat-label">Overall Status</div>
            </div>
        </div>
        
        <?php if (!empty($sync_status['products_with_issues'])): ?>
        <div class="issues-list">
            <h4 style="margin: 0 0 12px 0; color: #991b1b;">Products with Issues:</h4>
            <?php foreach ($sync_status['products_with_issues'] as $product): ?>
            <div class="issue-item">
                <strong><?php echo htmlspecialchars($product['sku']); ?></strong> - 
                <?php echo htmlspecialchars($product['name']); ?>
                <span style="color: #dc2626; font-weight: 600;">(<?php echo ucfirst($product['status']); ?>)</span>
                <?php if (!empty($product['issues'])): ?>
                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                    <?php echo implode(', ', $product['issues']); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Manual Sync Controls -->
    <div class="status-card">
        <h3 style="margin: 0 0 16px 0;">🔧 Manual Sync Controls</h3>
        <p style="color: #6b7280; margin-bottom: 20px;">
            Run auto-sync to ensure all training-enabled products are properly integrated with training modules.
            This will update training examples, verify module references, and sync bulk pricing.
        </p>
        
        <form method="post" style="display: inline-block;">
            <button type="submit" name="run_sync" class="btn" onclick="return confirm('Run auto-sync for all training-enabled products? This may take a few moments.')">
                🚀 Run Auto-Sync
            </button>
        </form>
        
        <a href="<?php echo path_with_base('/admin/products.php'); ?>" class="btn btn-secondary" style="margin-left: 12px;">
            📝 Edit Products
        </a>
    </div>
    
    <!-- Sync Results -->
    <?php if ($sync_results): ?>
    <div class="status-card">
        <h3 style="margin: 0 0 16px 0;">📋 Sync Results</h3>
        
        <div class="stat-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $sync_results['products_processed']; ?></div>
                <div class="stat-label">Products Processed</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" style="color: #16a34a;"><?php echo $sync_results['products_successful']; ?></div>
                <div class="stat-label">Successful</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" style="color: #dc2626;"><?php echo $sync_results['products_failed']; ?></div>
                <div class="stat-label">Failed</div>
            </div>
        </div>
        
        <?php if (!empty($sync_results['errors'])): ?>
        <div class="issues-list">
            <h4 style="margin: 0 0 12px 0; color: #991b1b;">Errors:</h4>
            <?php foreach ($sync_results['errors'] as $error): ?>
            <div class="issue-item"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($sync_results['warnings'])): ?>
        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 16px; margin-top: 16px;">
            <h4 style="margin: 0 0 12px 0; color: #92400e;">Warnings:</h4>
            <?php foreach ($sync_results['warnings'] as $warning): ?>
            <div style="padding: 4px 0; color: #78350f;"><?php echo htmlspecialchars($warning); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Instructions -->
    <div class="status-card">
        <h3 style="margin: 0 0 16px 0;">📖 How It Works</h3>
        <div style="color: #6b7280; line-height: 1.6;">
            <p><strong>The auto-sync system performs the following actions:</strong></p>
            <ul>
                <li><strong>Training Examples:</strong> Updates the training_module_examples table with appropriate module assignments based on training context</li>
                <li><strong>Module References:</strong> Verifies that products are properly referenced in training module files</li>
                <li><strong>Bulk Pricing:</strong> Ensures bulk pricing tiers are configured for each product</li>
                <li><strong>Calculated Fields:</strong> Validates that all required calculated fields are present and accurate</li>
                <li><strong>Quiz Questions:</strong> Assigns appropriate quiz questions based on product training context</li>
            </ul>
            
            <p><strong>Training Context Mapping:</strong></p>
            <ul>
                <li><strong>Beginner:</strong> Basic examples, simple calculations, introductory modules</li>
                <li><strong>Intermediate:</strong> More complex examples, advanced calculations, value proposition training</li>
                <li><strong>Advanced:</strong> High-volume scenarios, complex calculations, final assessments</li>
                <li><strong>All:</strong> General examples across all modules</li>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


