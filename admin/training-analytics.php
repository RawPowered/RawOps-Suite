<?php
$SEO = [
    'title' => 'Training Analytics — Product Admin',
    'description' => 'Admin training access and role simulation analytics.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect();

$pdo = db();

// Get admin training access stats
try {
    $stmt = $pdo->query("
        SELECT 
            u.first_name, u.last_name, u.email,
            tm.title as module_title,
            ata.access_type,
            ata.simulated_role,
            ata.access_timestamp,
            ata.notes
        FROM admin_training_access ata
        JOIN users u ON u.id = ata.admin_user_id
        LEFT JOIN training_modules tm ON tm.id = ata.accessed_module_id
        ORDER BY ata.access_timestamp DESC
        LIMIT 50
    ");
    $trainingAccess = $stmt->fetchAll();
} catch (Exception $e) {
    $trainingAccess = [];
    $error = "Training access data not available: " . $e->getMessage();
}

// Get role simulation stats
try {
    $stmt = $pdo->query("
        SELECT 
            u.first_name, u.last_name, u.email,
            rsl.simulated_role,
            rsl.started_at,
            rsl.ended_at,
            rsl.duration_seconds,
            rsl.actions_taken,
            tu.first_name as target_first_name,
            tu.last_name as target_last_name,
            tu.email as target_email
        FROM role_simulation_log rsl
        JOIN users u ON u.id = rsl.admin_user_id
        LEFT JOIN users tu ON tu.id = rsl.target_user_id
        ORDER BY rsl.started_at DESC
        LIMIT 50
    ");
    $roleSimulations = $stmt->fetchAll();
} catch (Exception $e) {
    $roleSimulations = [];
    $error = ($error ?? '') . " Role simulation data not available: " . $e->getMessage();
}

// Get dashboard summary
try {
    $stmt = $pdo->query("SELECT * FROM admin_training_dashboard");
    $dashboardData = $stmt->fetchAll();
} catch (Exception $e) {
    $dashboardData = [];
}
?>

<section class="section">
    <div class="container">
        <div style="max-width: 1200px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
                <h1>📊 Training Analytics</h1>
                <a href="<?php echo path_with_base('/admin/'); ?>" class="btn secondary">← Back to Admin</a>
            </div>

            <?php if (isset($error)): ?>
                <div class="card" style="border-color: #fbbf24; background: #fffbeb; color: #92400e; margin-bottom: 24px;">
                    <p><strong>⚠️ Note:</strong> <?php echo htmlspecialchars($error); ?></p>
                    <p>Run the migration to enable full analytics: <a href="/run_admin_training_optimization.php" style="color: #92400e; text-decoration: underline;">Run Migration</a></p>
                </div>
            <?php endif; ?>

            <!-- Dashboard Summary -->
            <?php if (!empty($dashboardData)): ?>
            <div class="card" style="margin-bottom: 32px;">
                <h2 style="margin-top: 0;">👥 Admin Training Dashboard</h2>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Admin</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Modules Reviewed</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Last Access</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Simulations</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Total Sim Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardData as $admin): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?><br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($admin['email']); ?></small>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo intval($admin['modules_reviewed']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php echo $admin['last_training_access'] ? date('M j, Y g:i A', strtotime($admin['last_training_access'])) : 'Never'; ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo intval($admin['simulation_sessions']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php 
                                    $totalSeconds = intval($admin['total_simulation_time']);
                                    $hours = floor($totalSeconds / 3600);
                                    $minutes = floor(($totalSeconds % 3600) / 60);
                                    echo $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Training Access -->
            <div class="card" style="margin-bottom: 32px;">
                <h2 style="margin-top: 0;">📚 Recent Training Access</h2>
                <?php if (!empty($trainingAccess)): ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Admin</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Module</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Access Type</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Simulated Role</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trainingAccess as $access): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php echo htmlspecialchars($access['first_name'] . ' ' . $access['last_name']); ?><br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($access['email']); ?></small>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo htmlspecialchars($access['module_title'] ?? 'General Access'); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <span style="background: <?php echo $access['access_type'] === 'simulation' ? '#fef3c7' : '#dcfce7'; ?>; color: <?php echo $access['access_type'] === 'simulation' ? '#92400e' : '#166534'; ?>; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                                        <?php echo ucfirst($access['access_type']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo $access['simulated_role'] ? htmlspecialchars(ucwords(str_replace('_', ' ', $access['simulated_role']))) : '—'; ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo date('M j, Y g:i A', strtotime($access['access_timestamp'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color: #6b7280; text-align: center; padding: 32px;">No training access data available yet.</p>
                <?php endif; ?>
            </div>

            <!-- Recent Role Simulations -->
            <div class="card">
                <h2 style="margin-top: 0;">🎭 Recent Role Simulations</h2>
                <?php if (!empty($roleSimulations)): ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Admin</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Simulated Role</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Target User</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Started</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Duration</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roleSimulations as $sim): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php echo htmlspecialchars($sim['first_name'] . ' ' . $sim['last_name']); ?><br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($sim['email']); ?></small>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <span style="background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $sim['simulated_role']))); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php if ($sim['target_first_name']): ?>
                                        <?php echo htmlspecialchars($sim['target_first_name'] . ' ' . $sim['target_last_name']); ?><br>
                                        <small style="color: #6b7280;"><?php echo htmlspecialchars($sim['target_email']); ?></small>
                                    <?php else: ?>
                                        <span style="color: #6b7280;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo date('M j, Y g:i A', strtotime($sim['started_at'])); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php if ($sim['duration_seconds']): ?>
                                        <?php 
                                        $minutes = floor($sim['duration_seconds'] / 60);
                                        $seconds = $sim['duration_seconds'] % 60;
                                        echo $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
                                        ?>
                                    <?php else: ?>
                                        <span style="color: #6b7280;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                                    <?php if ($sim['ended_at']): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 4px; font-size: 12px;">Ended</span>
                                    <?php else: ?>
                                        <span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 12px;">Active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color: #6b7280; text-align: center; padding: 32px;">No role simulation data available yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>