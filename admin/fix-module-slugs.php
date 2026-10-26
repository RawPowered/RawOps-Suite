<?php
// Quick fix for module slug mismatches
require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Get all modules from database
$modules = $pdo->query('SELECT * FROM training_modules ORDER BY sort_order')->fetchAll();

// Check existing files
$moduleDir = __DIR__ . '/../onboarding/trainee/modules/';
$existingFiles = glob($moduleDir . '*.php');
$fileNames = array_map(function($file) {
    return basename($file, '.php');
}, $existingFiles);

echo "<h2>Module Slug Fix Utility</h2>";
echo "<h3>Current Database Modules:</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>Database Slug</th><th>Title</th><th>File Exists?</th><th>Suggested Fix</th></tr>";

$fixes = [];

foreach ($modules as $module) {
    $expectedFile = $moduleDir . $module['slug'] . '.php';
    $fileExists = file_exists($expectedFile);
    
    echo "<tr>";
    echo "<td>{$module['id']}</td>";
    echo "<td>{$module['slug']}</td>";
    echo "<td>{$module['title']}</td>";
    echo "<td style='color: " . ($fileExists ? 'green' : 'red') . "'>" . ($fileExists ? 'YES' : 'NO') . "</td>";
    
    if (!$fileExists) {
        // Try to find alternative file names
        $possibleAlternatives = [
            str_replace('-and-', '-', $module['slug']),
            str_replace('-', '', $module['slug']),
            preg_replace('/[^a-z0-9]/', '-', strtolower($module['title']))
        ];
        
        $foundAlternative = null;
        foreach ($possibleAlternatives as $alt) {
            if (in_array($alt, $fileNames)) {
                $foundAlternative = $alt;
                break;
            }
        }
        
        if ($foundAlternative) {
            echo "<td style='color: orange;'>Update slug to: <strong>{$foundAlternative}</strong></td>";
            $fixes[] = [
                'id' => $module['id'],
                'old_slug' => $module['slug'],
                'new_slug' => $foundAlternative
            ];
        } else {
            echo "<td style='color: red;'>No matching file found</td>";
        }
    } else {
        echo "<td style='color: green;'>OK</td>";
    }
    
    echo "</tr>";
}

echo "</table>";

if (!empty($fixes)) {
    echo "<h3>Recommended Fixes:</h3>";
    echo "<p>Run these SQL commands to fix the slug mismatches:</p>";
    echo "<pre style='background: #f0f0f0; padding: 16px; border-radius: 8px;'>";
    
    foreach ($fixes as $fix) {
        echo "UPDATE training_modules SET slug = '{$fix['new_slug']}' WHERE id = {$fix['id']};\n";
    }
    
    echo "</pre>";
    
    // Auto-apply fixes if requested
    if (isset($_GET['apply_fixes'])) {
        try {
            foreach ($fixes as $fix) {
                $stmt = $pdo->prepare('UPDATE training_modules SET slug = ? WHERE id = ?');
                $stmt->execute([$fix['new_slug'], $fix['id']]);
            }
            echo "<div style='background: #d4edda; color: #155724; padding: 16px; border-radius: 8px; margin-top: 16px;'>";
            echo "<strong>✅ Fixes Applied Successfully!</strong> All module slugs have been updated to match existing files.";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 16px; border-radius: 8px; margin-top: 16px;'>";
            echo "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
    } else {
        echo "<p><a href='?apply_fixes=1' style='background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>🔧 Apply All Fixes Automatically</a></p>";
    }
}

echo "<h3>Available Module Files:</h3>";
echo "<ul>";
foreach ($fileNames as $fileName) {
    echo "<li>{$fileName}.php</li>";
}
echo "</ul>";

echo "<p><a href='/admin/lms-content-manager.php'>← Back to LMS Content Manager</a></p>";
?>
