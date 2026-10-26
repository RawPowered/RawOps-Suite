<?php
/**
 * Sample Request Placeholder Cleanup Tool
 * Removes orphaned placeholder sample requests that were never completed
 */

$SEO = [
    'title' => 'Cleanup Placeholder Samples — Admin',
    'description' => 'Remove orphaned placeholder sample requests',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/config.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$adminId = current_admin_id();

$cleanupResult = null;

// Handle cleanup action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup') {
    $dryRun = !isset($_POST['confirm_delete']);
    $olderThanDays = intval($_POST['older_than_days'] ?? 7);
    
    try {
        // Find placeholder requests
        $stmt = $pdo->prepare("
            SELECT 
                sr.id,
                sr.recipient_name,
                sr.agent_id,
                sr.lead_id,
                sr.requested_at,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                l.company_name AS lead_company,
                DATEDIFF(NOW(), sr.requested_at) AS days_old
            FROM sample_requests sr
            JOIN users u ON u.id = sr.agent_id
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            WHERE sr.status = 'pending'
            AND (sr.agent_notes IS NULL OR sr.agent_notes = '')
            AND (sr.recipient_address_line1 IS NULL OR sr.recipient_address_line1 = '')
            AND sr.requested_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY sr.requested_at ASC
        ");
        $stmt->execute([$olderThanDays]);
        $placeholders = $stmt->fetchAll();
        
        $cleanupResult = [
            'found' => count($placeholders),
            'deleted' => 0,
            'dry_run' => $dryRun,
            'placeholders' => $placeholders
        ];
        
        if (!$dryRun && count($placeholders) > 0) {
            // Delete placeholders
            $deleteStmt = $pdo->prepare("DELETE FROM sample_requests WHERE id = ?");
            
            foreach ($placeholders as $placeholder) {
                try {
                    $deleteStmt->execute([$placeholder['id']]);
                    $cleanupResult['deleted']++;
                } catch (Throwable $e) {
                    // Skip if FK constraint or other issue
                }
            }
        }
        
    } catch (Exception $e) {
        $cleanupResult = ['error' => $e->getMessage()];
    }
}

// Get current placeholder count
$currentPlaceholders = [];
try {
    $stmt = $pdo->query("
        SELECT 
            sr.id,
            sr.recipient_name,
            sr.requested_at,
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            l.company_name AS lead_company,
            DATEDIFF(NOW(), sr.requested_at) AS days_old
        FROM sample_requests sr
        JOIN users u ON u.id = sr.agent_id
        LEFT JOIN crm_leads l ON l.id = sr.lead_id
        WHERE sr.status = 'pending'
        AND (sr.agent_notes IS NULL OR sr.agent_notes = '')
        AND (sr.recipient_address_line1 IS NULL OR sr.recipient_address_line1 = '')
        ORDER BY sr.requested_at DESC
        LIMIT 100
    ");
    $currentPlaceholders = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

require_once __DIR__ . '/../../required/header.php';
?>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 24px;">
    <div style="margin-bottom: 24px;">
        <a href="index.php" class="btn secondary">← Back to Sample Management</a>
    </div>
    
    <h1>🧹 Cleanup Placeholder Sample Requests</h1>
    <p style="color: #6b7280; margin-bottom: 24px;">
        Remove orphaned placeholder sample requests that were never completed by customers
    </p>
    
    <?php if ($cleanupResult): ?>
    <div class="card" style="margin-bottom: 24px; <?php echo $cleanupResult['dry_run'] ? 'background:#fef3c7;border:2px solid #f59e0b;' : 'background:#d1fae5;border:2px solid #059669;'; ?>">
        <h3 style="margin: 0 0 16px 0;">
            <?php if ($cleanupResult['dry_run']): ?>
                📋 Dry Run Results
            <?php else: ?>
                ✓ Cleanup Complete
            <?php endif; ?>
        </h3>
        
        <?php if (isset($cleanupResult['error'])): ?>
            <div style="color: #dc2626;">
                Error: <?php echo htmlspecialchars($cleanupResult['error']); ?>
            </div>
        <?php else: ?>
            <div style="font-size: 14px;">
                <strong>Found:</strong> <?php echo $cleanupResult['found']; ?> placeholder requests<br>
                <?php if (!$cleanupResult['dry_run']): ?>
                    <strong>Deleted:</strong> <?php echo $cleanupResult['deleted']; ?> placeholder requests<br>
                <?php endif; ?>
            </div>
            
            <?php if ($cleanupResult['dry_run'] && count($cleanupResult['placeholders']) > 0): ?>
            <div style="margin-top: 16px;">
                <h4>Placeholders that would be deleted:</h4>
                <ul style="max-height: 200px; overflow-y: auto; margin: 8px 0 0 20px;">
                    <?php foreach ($cleanupResult['placeholders'] as $p): ?>
                    <li>
                        ID <?php echo $p['id']; ?>: 
                        <?php echo htmlspecialchars($p['lead_company'] ?: $p['recipient_name']); ?> 
                        (<?php echo $p['days_old']; ?> days old, Agent: <?php echo htmlspecialchars($p['agent_name']); ?>)
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Cleanup Form -->
    <div class="card" style="margin-bottom: 24px;">
        <h3>Cleanup Settings</h3>
        <form method="post" style="margin-top: 16px;">
            <input type="hidden" name="action" value="cleanup">
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                    Delete placeholders older than:
                </label>
                <select name="older_than_days" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="3">3 days</option>
                    <option value="7" selected>7 days</option>
                    <option value="14">14 days</option>
                    <option value="30">30 days</option>
                </select>
            </div>
            
            <div style="background: #fef3c7; padding: 16px; border-radius: 8px; border-left: 4px solid #f59e0b; margin-bottom: 16px;">
                <h4 style="margin: 0 0 8px 0; color: #92400e;">⚠️ What are placeholders?</h4>
                <p style="margin: 0; color: #92400e; font-size: 14px;">
                    Placeholder requests are sample_requests with:
                </p>
                <ul style="margin: 8px 0 0 16px; color: #92400e; font-size: 13px;">
                    <li>Empty agent_notes</li>
                    <li>Empty recipient_address_line1</li>
                    <li>Status = 'pending'</li>
                </ul>
                <p style="margin: 8px 0 0 0; color: #92400e; font-size: 13px;">
                    These are created when agents send kickoff emails but customers never complete the promo portal signup.
                </p>
            </div>
            
            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn secondary" style="background: #0369a1;">
                    🔍 Preview (Dry Run)
                </button>
                <button type="submit" name="confirm_delete" value="1" class="btn" style="background: #dc2626;" 
                        onclick="return confirm('Permanently delete these placeholder sample requests?\n\nThis cannot be undone.');">
                    🗑️ Delete Placeholders
                </button>
            </div>
        </form>
    </div>
    
    <!-- Current Placeholders -->
    <div class="card">
        <h3>Current Placeholder Requests (<?php echo count($currentPlaceholders); ?>)</h3>
        <p style="color: #6b7280; font-size: 14px;">
            These sample requests have no agent notes or address and were likely never completed
        </p>
        
        <?php if (empty($currentPlaceholders)): ?>
        <div style="text-align: center; padding: 32px; color: #059669;">
            <div style="font-size: 48px; margin-bottom: 12px;">✓</div>
            <p><strong>No placeholder requests found!</strong></p>
            <p style="color: #6b7280; font-size: 14px;">All sample requests have complete data</p>
        </div>
        <?php else: ?>
        <div style="overflow-x: auto; margin-top: 16px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 12px 8px; text-align: left;">ID</th>
                        <th style="padding: 12px 8px; text-align: left;">Recipient</th>
                        <th style="padding: 12px 8px; text-align: left;">Lead</th>
                        <th style="padding: 12px 8px; text-align: left;">Agent</th>
                        <th style="padding: 12px 8px; text-align: left;">Age</th>
                        <th style="padding: 12px 8px; text-align: left;">Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($currentPlaceholders as $p): ?>
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 12px 8px;"><?php echo $p['id']; ?></td>
                        <td style="padding: 12px 8px;">
                            <?php echo htmlspecialchars($p['recipient_name'] ?: '(empty)'); ?>
                        </td>
                        <td style="padding: 12px 8px;">
                            <?php echo htmlspecialchars($p['lead_company'] ?: '—'); ?>
                        </td>
                        <td style="padding: 12px 8px;">
                            <?php echo htmlspecialchars($p['agent_name']); ?>
                        </td>
                        <td style="padding: 12px 8px; <?php echo $p['days_old'] > 14 ? 'color:#dc2626;font-weight:600;' : ''; ?>">
                            <?php echo $p['days_old']; ?> days
                        </td>
                        <td style="padding: 12px 8px; color: #6b7280; font-size: 13px;">
                            <?php echo date('M j, Y g:i A', strtotime($p['requested_at'])); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.btn {
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    display: inline-block;
    text-decoration: none;
}

.btn.secondary {
    background: #f3f4f6;
    color: #1f2937;
}

.btn.secondary:hover {
    background: #e5e7eb;
}
</style>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>



