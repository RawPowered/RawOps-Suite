<?php
/**
 * Shortcode Migration Tool
 * Converts hardcoded PHP/HTML to dynamic shortcodes
 * Scans all training modules and suggests replacements
 */

$SEO = [
    'title' => 'Shortcode Migration Tool — Admin',
    'description' => 'Convert hardcoded content to dynamic shortcodes',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$action = $_GET['action'] ?? 'scan';
$moduleSlug = $_GET['module'] ?? '';

// Scan modules for hardcoded values
function scanModuleForHardcodedValues($moduleSlug, $content) {
    $findings = [];
    
    // Pattern 1: Hardcoded prices ($79.95, $199.99, etc.)
    if (preg_match_all('/\$\d+\.\d{2}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $findings[] = [
                'type' => 'price',
                'value' => $match[0],
                'position' => $match[1],
                'suggestion' => '[product_price sku="BLEND-XX-XX"]',
                'severity' => 'high'
            ];
        }
    }
    
    // Pattern 2: Hardcoded product names (contains "Blend", "Blue 10", etc.)
    if (preg_match_all('/(Blue \d+|Gold \d+|Black \d+|BLEND-\d+-\d+)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $findings[] = [
                'type' => 'product_name',
                'value' => $match[0],
                'position' => $match[1],
                'suggestion' => '[product_name sku="' . $match[0] . '"]',
                'severity' => 'medium'
            ];
        }
    }
    
    // Pattern 3: Hardcoded servings/quantities (\d+ servings, \d+ drinks)
    if (preg_match_all('/(\d+)\s+(servings?|drinks?)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $findings[] = [
                'type' => 'servings',
                'value' => $match[0],
                'position' => $match[1],
                'suggestion' => '[product_servings sku="BLEND-XX-XX"] servings',
                'severity' => 'high'
            ];
        }
    }
    
    // Pattern 4: Hardcoded attribute values (\d+mg)
    if (preg_match_all('/(\d+)\s*mg/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $findings[] = [
                'type' => 'attribute_content',
                'value' => $match[0],
                'position' => $match[1],
                'suggestion' => '[product_attribute sku="BLEND-XX-XX" type="per_oz"]',
                'severity' => 'high'
            ];
        }
    }
    
    // Pattern 5: Old quiz HTML (hardcoded quizzes)
    if (preg_match_all('/<div class="quiz-question">.*?<\/div>/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $findings[] = [
                'type' => 'quiz_html',
                'value' => substr($match[0], 0, 100) . '...',
                'position' => $match[1],
                'suggestion' => '[quiz sku="BLEND-XX-XX" position="mid"]',
                'severity' => 'critical'
            ];
        }
    }
    
    return $findings;
}

// Handle migration actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate_module'])) {
    try {
        $slug = $_POST['module_slug'];
        $newContent = $_POST['migrated_content'];
        
        $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $slug . '.php';
        
        // Backup original
        $backupFile = __DIR__ . '/../onboarding/trainee/modules/backups/' . $slug . '_' . date('Y-m-d_His') . '.php';
        @mkdir(dirname($backupFile), 0755, true);
        copy($moduleFile, $backupFile);
        
        // Save migrated content
        file_put_contents($moduleFile, $newContent);
        
        flash_set('success', 'Module migrated to shortcodes! Original backed up to: ' . basename($backupFile));
        redirect($_SERVER['REQUEST_URI']);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all modules
$modules = $pdo->query("SELECT * FROM training_modules WHERE sort_order < 100 ORDER BY sort_order")->fetchAll();

// If scanning specific module
$moduleData = null;
$findings = [];
if ($moduleSlug) {
    $stmt = $pdo->prepare("SELECT * FROM training_modules WHERE slug = ?");
    $stmt->execute([$moduleSlug]);
    $moduleData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($moduleData) {
        $moduleFile = __DIR__ . '/../onboarding/trainee/modules/' . $moduleSlug . '.php';
        if (file_exists($moduleFile)) {
            $content = file_get_contents($moduleFile);
            $findings = scanModuleForHardcodedValues($moduleSlug, $content);
        }
    }
}
?>

<style>
.migration-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}
.migration-header {
    background: linear-gradient(135deg, #dc2626, #991b1b);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
}
.finding-item {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
}
.severity-critical { border-color: #dc2626; background: #fef2f2; }
.severity-high { border-color: #f59e0b; background: #fffbeb; }
.severity-medium { border-color: #3b82f6; background: #eff6ff; }
.severity-low { border-color: #10b981; background: #f0fdf4; }
</style>

<div class="migration-container">
    <div class="migration-header">
        <h1 style="margin: 0 0 8px 0;">🔄 Shortcode Migration Tool</h1>
        <p style="margin: 0; opacity: 0.9;">Convert hardcoded values to dynamic shortcodes for automatic database syncing</p>
    </div>
    
    <?php if ($action === 'scan' && empty($moduleSlug)): ?>
        <!-- Module List -->
        <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <h2 style="margin: 0 0 20px 0;">📚 Select Module to Scan</h2>
            
            <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid #3b82f6;">
                <strong style="color: #1e40af;">How it works:</strong>
                <ol style="margin: 8px 0 0 0; color: #6b7280;">
                    <li>Select a training module to scan</li>
                    <li>Tool identifies hardcoded prices, product names, servings, attribute values, and old quiz HTML</li>
                    <li>Suggests shortcode replacements</li>
                    <li>Auto-generates migrated content</li>
                    <li>Backup original before applying</li>
                </ol>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                <?php foreach ($modules as $module): ?>
                <a href="?action=scan&module=<?php echo urlencode($module['slug']); ?>" 
                   class="btn" 
                   style="background: #f9fafb; color: #1f2937; text-decoration: none; padding: 20px; border: 2px solid #e5e7eb; text-align: left;">
                    <div style="font-weight: 600; margin-bottom: 4px;"><?php echo htmlspecialchars($module['title']); ?></div>
                    <div style="font-size: 12px; color: #6b7280;">
                        Slug: <?php echo $module['slug']; ?> • Duration: <?php echo $module['duration_minutes']; ?>min
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
    <?php elseif ($action === 'scan' && !empty($moduleSlug)): ?>
        <!-- Scan Results -->
        <?php if (!$moduleData): ?>
            <div style="background: #fee2e2; border: 2px solid #dc2626; padding: 24px; border-radius: 8px; margin-bottom: 24px; text-align: center;">
                <h3 style="color: #991b1b; margin: 0 0 8px 0;">❌ Module Not Found</h3>
                <p style="color: #7f1d1d; margin: 0 0 16px 0;">Module "<?php echo htmlspecialchars($moduleSlug); ?>" doesn't exist in database.</p>
                <a href="?action=scan" class="btn">← Back to Module List</a>
            </div>
        <?php else: ?>
        <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">📊 Scan Results: <?php echo htmlspecialchars($moduleData['title'] ?? 'Unknown'); ?></h2>
                <a href="?action=scan" class="btn secondary">← Back to Module List</a>
            </div>
            
            <?php if (empty($findings)): ?>
                <div style="background: #dcfce7; border: 2px solid #16a34a; padding: 24px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 12px;">✅</div>
                    <h3 style="color: #14532d; margin: 0 0 8px 0;">Module is clean!</h3>
                    <p style="color: #6b7280; margin: 0;">No hardcoded values detected. This module may already use shortcodes or has no product-specific content.</p>
                </div>
            <?php else: ?>
                <div style="background: #fef3c7; border: 2px solid #f59e0b; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
                    <h3 style="margin: 0 0 8px 0; color: #92400e;">⚠️ Found <?php echo count($findings); ?> Hardcoded Value(s)</h3>
                    <p style="margin: 0; color: #78350f;">These should be converted to shortcodes for automatic database syncing.</p>
                </div>
                
                <?php 
                $criticalCount = count(array_filter($findings, fn($f) => $f['severity'] === 'critical'));
                $highCount = count(array_filter($findings, fn($f) => $f['severity'] === 'high'));
                $mediumCount = count(array_filter($findings, fn($f) => $f['severity'] === 'medium'));
                ?>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
                    <div style="background: #fee2e2; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: bold; color: #dc2626;"><?php echo $criticalCount; ?></div>
                        <div style="font-size: 13px; color: #7f1d1d;">Critical</div>
                    </div>
                    <div style="background: #fef3c7; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: bold; color: #f59e0b;"><?php echo $highCount; ?></div>
                        <div style="font-size: 13px; color: #78350f;">High Priority</div>
                    </div>
                    <div style="background: #dbeafe; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: bold; color: #3b82f6;"><?php echo $mediumCount; ?></div>
                        <div style="font-size: 13px; color: #1e40af;">Medium</div>
                    </div>
                </div>
                
                <h3 style="margin: 24px 0 16px 0;">🔍 Detected Issues:</h3>
                
                <?php foreach ($findings as $idx => $finding): ?>
                <div class="finding-item severity-<?php echo $finding['severity']; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                        <div>
                            <strong style="font-size: 16px;">
                                <?php echo ucfirst(str_replace('_', ' ', $finding['type'])); ?>
                            </strong>
                            <span style="background: <?php 
                                echo $finding['severity'] === 'critical' ? '#dc2626' : 
                                     ($finding['severity'] === 'high' ? '#f59e0b' : '#3b82f6'); 
                            ?>; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px;">
                                <?php echo strtoupper($finding['severity']); ?>
                            </span>
                        </div>
                        <small style="color: #6b7280;">Position: <?php echo $finding['position']; ?></small>
                    </div>
                    
                    <div style="background: white; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 13px; margin-bottom: 8px;">
                        <strong>Current:</strong> <code><?php echo htmlspecialchars($finding['value']); ?></code>
                    </div>
                    
                    <div style="background: #dcfce7; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 13px;">
                        <strong>Replace with:</strong> <code><?php echo htmlspecialchars($finding['suggestion']); ?></code>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div style="background: #f0f9ff; padding: 20px; border-radius: 8px; margin-top: 24px; border-left: 4px solid #3b82f6;">
                    <h3 style="margin: 0 0 12px 0; color: #1e40af;">💡 Migration Recommendations</h3>
                    <ul style="margin: 0; color: #6b7280;">
                        <li><strong>Manual Review Required:</strong> Auto-migration can't determine which SKU each value belongs to</li>
                        <li><strong>Best Practice:</strong> Open module in LMS Content Manager and use shortcode buttons</li>
                        <li><strong>Alternative:</strong> Use Find & Replace in code editor to swap hardcoded values</li>
                        <li><strong>Test After Migration:</strong> View module as trainee to verify shortcodes render correctly</li>
                    </ul>
                </div>
                
                <div style="margin-top: 24px; display: flex; gap: 12px;">
                    <a href="<?php echo path_with_base('/admin/lms-manager.php?section=content&action=edit&module=' . $moduleSlug); ?>" 
                       class="btn" style="background: #3b82f6; color: white; text-decoration: none;">
                        📝 Edit Module in LMS
                    </a>
                    <a href="<?php echo path_with_base('/admin/shortcode-builder.php'); ?>" 
                       class="btn" style="background: #f59e0b; color: white; text-decoration: none;">
                        🛠️ Open Shortcode Builder
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Migration Summary -->
        <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin: 0 0 16px 0;">📋 Migration Guide</h3>
            
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Hardcoded Value</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Shortcode Replacement</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Example</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>$79.95</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>[product_price sku="BLEND-10-32"]</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">$79.95</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>Blue 10 Blend - 32oz</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>[product_name sku="BLEND-10-32"]</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">Blue 10 Blend - 32oz</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>64 servings</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>[product_servings sku="BLEND-10-32"] servings</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">64 servings</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>20mg attribute per oz</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>[product_attribute sku="BLEND-10-32" type="per_oz"] attribute per oz</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">20mg attribute per oz</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>&lt;div class="quiz-question"&gt;...&lt;/div&gt;</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><code>[quiz sku="BLEND-10-32" position="mid"]</code></td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">Renders quiz from database</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <!-- Default view if no action -->
        <div style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
            <div style="font-size: 64px; margin-bottom: 20px;">🔄</div>
            <h2 style="color: #1f2937; margin-bottom: 16px;">Shortcode Migration Tool</h2>
            <p style="color: #6b7280; margin-bottom: 24px;">Scan training modules for hardcoded values and convert them to dynamic shortcodes.</p>
            <a href="?action=scan" class="btn" style="background: #f59e0b; color: white; text-decoration: none; display: inline-block; padding: 12px 24px;">
                🚀 Start Scanning Modules
            </a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

