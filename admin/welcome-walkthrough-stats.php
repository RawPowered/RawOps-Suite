<?php
$SEO = [
    'title' => 'Welcome Walkthrough Statistics — Product Admin',
    'description' => 'View statistics on trainee welcome walkthrough completion.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Require admin access
require_admin_access();

$pdo = db();

try {
    // Get welcome walkthrough statistics
    $stmt = $pdo->query('
        SELECT 
            COUNT(*) as total_views,
            COUNT(completed_at) as completed_count,
            COUNT(CASE WHEN skipped = 1 THEN 1 END) as skipped_count,
            AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, viewed_at, completed_at) END) as avg_completion_time_seconds
        FROM welcome_walkthrough_tracking
    ');
    $stats = $stmt->fetch();
    
    // Get recent walkthrough activity
    $stmt = $pdo->query('
        SELECT 
            w.*,
            u.first_name,
            u.last_name,
            u.email
        FROM welcome_walkthrough_tracking w
        JOIN users u ON w.user_id = u.id
        ORDER BY w.viewed_at DESC
        LIMIT 20
    ');
    $recentActivity = $stmt->fetchAll();
    
    $hasData = true;
} catch (Throwable $e) {
    $hasData = false;
    $error = $e->getMessage();
}
?>

<section class="hero" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 0;">
    <div class="container">
        <h1 style="color: #fff; margin-bottom: 8px;">Welcome Walkthrough Statistics 📊</h1>
        <p style="color: rgba(255,255,255,0.9); font-size: 18px;">
            Track how trainees interact with the welcome walkthrough experience.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (!$hasData): ?>
            <div class="card" style="border-color: #fca5a5; background: #fef2f2;">
                <h3 style="color: #991b1b; margin-bottom: 16px;">⚠️ Database Table Missing</h3>
                <p style="color: #991b1b; margin-bottom: 16px;">
                    The welcome walkthrough tracking table hasn't been created yet. Please run the migration:
                </p>
                <code style="background: #f3f4f6; padding: 8px 12px; border-radius: 4px; display: block; margin-bottom: 16px;">
                    mysql -u root -p product_dev < migrations/2025-09-11-welcome-walkthrough-tracking.sql
                </code>
                <p style="color: #7f1d1d; font-size: 14px;">
                    Error: <?php echo htmlspecialchars($error ?? 'Unknown error'); ?>
                </p>
            </div>
        <?php else: ?>
            <!-- Statistics Cards -->
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; margin-bottom: 40px;">
                <div class="card" style="text-align: center; padding: 30px;">
                    <div style="font-size: 48px; margin-bottom: 12px;">👁️</div>
                    <h3 style="margin-bottom: 8px; color: #0b2a4a;">Total Views</h3>
                    <div style="font-size: 32px; font-weight: 800; color: #059669;">
                        <?php echo number_format($stats['total_views']); ?>
                    </div>
                </div>
                
                <div class="card" style="text-align: center; padding: 30px;">
                    <div style="font-size: 48px; margin-bottom: 12px;">✅</div>
                    <h3 style="margin-bottom: 8px; color: #0b2a4a;">Completed</h3>
                    <div style="font-size: 32px; font-weight: 800; color: #059669;">
                        <?php echo number_format($stats['completed_count']); ?>
                    </div>
                    <div style="color: #6b7280; font-size: 14px; margin-top: 4px;">
                        <?php echo $stats['total_views'] > 0 ? round(($stats['completed_count'] / $stats['total_views']) * 100, 1) : 0; ?>% completion rate
                    </div>
                </div>
                
                <div class="card" style="text-align: center; padding: 30px;">
                    <div style="font-size: 48px; margin-bottom: 12px;">⏭️</div>
                    <h3 style="margin-bottom: 8px; color: #0b2a4a;">Skipped</h3>
                    <div style="font-size: 32px; font-weight: 800; color: #f59e0b;">
                        <?php echo number_format($stats['skipped_count']); ?>
                    </div>
                    <div style="color: #6b7280; font-size: 14px; margin-top: 4px;">
                        <?php echo $stats['total_views'] > 0 ? round(($stats['skipped_count'] / $stats['total_views']) * 100, 1) : 0; ?>% skip rate
                    </div>
                </div>
                
                <div class="card" style="text-align: center; padding: 30px;">
                    <div style="font-size: 48px; margin-bottom: 12px;">⏱️</div>
                    <h3 style="margin-bottom: 8px; color: #0b2a4a;">Avg. Time</h3>
                    <div style="font-size: 32px; font-weight: 800; color: #6366f1;">
                        <?php 
                        if ($stats['avg_completion_time_seconds']) {
                            $minutes = floor($stats['avg_completion_time_seconds'] / 60);
                            $seconds = $stats['avg_completion_time_seconds'] % 60;
                            echo $minutes . 'm ' . round($seconds) . 's';
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div style="color: #6b7280; font-size: 14px; margin-top: 4px;">
                        Time to complete
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <h2 style="margin-bottom: 24px;">Recent Walkthrough Activity</h2>
                
                <?php if (empty($recentActivity)): ?>
                    <p style="color: #6b7280; text-align: center; padding: 40px;">
                        No walkthrough activity recorded yet.
                    </p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid #e5e7eb;">
                                    <th style="padding: 12px; text-align: left; color: #374151;">User</th>
                                    <th style="padding: 12px; text-align: left; color: #374151;">Viewed At</th>
                                    <th style="padding: 12px; text-align: left; color: #374151;">Status</th>
                                    <th style="padding: 12px; text-align: left; color: #374151;">Completion Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivity as $activity): ?>
                                    <tr style="border-bottom: 1px solid #f3f4f6;">
                                        <td style="padding: 12px;">
                                            <div>
                                                <strong><?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?></strong>
                                            </div>
                                            <div style="color: #6b7280; font-size: 14px;">
                                                <?php echo htmlspecialchars($activity['email']); ?>
                                            </div>
                                        </td>
                                        <td style="padding: 12px; color: #6b7280;">
                                            <?php echo date('M j, Y g:i A', strtotime($activity['viewed_at'])); ?>
                                        </td>
                                        <td style="padding: 12px;">
                                            <?php if ($activity['completed_at']): ?>
                                                <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                    ✅ Completed
                                                </span>
                                            <?php elseif ($activity['skipped']): ?>
                                                <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                    ⏭️ Skipped
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #e5e7eb; color: #374151; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                    👁️ Viewed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px; color: #6b7280;">
                                            <?php if ($activity['completed_at']): ?>
                                                <?php
                                                $completionTime = strtotime($activity['completed_at']) - strtotime($activity['viewed_at']);
                                                $minutes = floor($completionTime / 60);
                                                $seconds = $completionTime % 60;
                                                echo $minutes . 'm ' . $seconds . 's';
                                                ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
