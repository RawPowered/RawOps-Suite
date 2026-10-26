<?php
$SEO = [
    'title' => 'Legal Access Requests — Product Legal Management',
    'description' => 'Review and approve external legal access invitations.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/legal-permissions.php';

try {
    require_legal_or_admin(path_with_base('/admin/'));
    start_session();
    $pdo = db();
    
    // Only admins can access request management
    $currentUser = current_user_id();
    if (!user_has_role($currentUser, 'admin')) {
        redirect(path_with_base('/admin/legal/portal/'));
    }
} catch (Throwable $e) {
    error_log('[legal/access-requests.php] initialization error: ' . $e->getMessage());
    throw $e;
}

try {
    $stmt = $pdo->query('SELECT r.*, c.title AS case_title
        FROM legal_access_requests r
        LEFT JOIN legal_cases c ON c.id = r.case_id
        ORDER BY r.status = "pending" DESC, r.created_at DESC
        LIMIT 200');
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[legal/access-requests.php] query failure: ' . $e->getMessage());
    $requests = [];
}

$breadcrumbs = [
    ['label' => 'Access Requests', 'url' => null]
];
?>
<?php include __DIR__ . '/partials/breadcrumb.php'; ?>
<style>
.legal-requests-page {
    max-width: 1200px;
    margin: 40px auto 80px auto;
    padding: 0 28px;
}
.requests-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}
.request-card {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    background: #fff;
    box-shadow: 0 12px 28px rgba(15,23,42,0.08);
    transition: all 0.2s;
}
.request-card:hover {
    box-shadow: 0 16px 32px rgba(15,23,42,0.12);
    transform: translateY(-1px);
}
.request-card.pending { 
    border-color: #fbbf24; 
    background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
}
.request-title {
    font-size: 1.3rem;
    margin: 0;
    color: #0f172a;
    font-weight: 700;
}
.request-meta {
    font-size: 0.95rem;
    color: #475569;
}
.request-actions {
    margin-top: 20px;
    display: flex;
    gap: 12px;
}
.request-actions button {
    border-radius: 8px;
    border: none;
    padding: 12px 24px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}
.request-actions .approve { 
    background: #10b981; 
    color: #fff;
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
}
.request-actions .approve:hover {
    background: #059669;
    box-shadow: 0 6px 16px rgba(16,185,129,0.4);
    transform: translateY(-1px);
}
.request-actions .deny { 
    background: #dc2626; 
    color: #fff;
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}
.request-actions .deny:hover {
    background: #b91c1c;
    box-shadow: 0 6px 16px rgba(220,38,38,0.4);
    transform: translateY(-1px);
}
.request-empty {
    text-align: center;
    padding: 40px;
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    color: #475569;
}
</style>

<div class="legal-requests-page">
    <div class="requests-header">
        <div>
            <h1 style="margin:0;font-size:2rem;">Legal Access Requests</h1>
            <p style="margin:6px 0 0 0;color:#475569;">Approve or deny external counsel access. Approved users will receive automated onboarding via SendGrid.</p>
        </div>
        <a href="<?php echo path_with_base('/admin/legal/index.php'); ?>" style="color:#1d4ed8;font-weight:600;text-decoration:none;">← Back to Legal Dashboard</a>
    </div>

    <?php if (empty($requests)): ?>
        <div class="request-empty">
            <strong>No access requests at this time.</strong>
            <p style="margin:12px 0 0 0;">Legal guests can request access from the public brief once they review the summary evidence.</p>
        </div>
    <?php else: ?>
        <?php foreach ($requests as $request): ?>
            <?php
            // Parse notes JSON for better display
            $requestNotes = json_decode($request['notes'] ?? '{}', true) ?: [];
            $accessReason = $requestNotes['access_reason'] ?? '';
            $entityType = $requestNotes['entity_type'] ?? 'individual';
            $company = $requestNotes['company'] ?? '';
            $desiredUsername = $requestNotes['desired_username'] ?? $request['desired_username'] ?? '';
            $ipAddress = $requestNotes['ip_address'] ?? '';
            $userAgent = $requestNotes['user_agent'] ?? '';
            $requestedAt = $requestNotes['requested_at'] ?? $request['created_at'];
            ?>
            <article class="request-card <?php echo $request['status'] === 'pending' ? 'pending' : ''; ?>">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:16px;">
                    <div>
                        <h2 class="request-title"><?php echo sanitize($request['requestor_name']); ?></h2>
                        <div style="font-size:0.9rem;color:#64748b;margin-top:4px;">
                            <?php echo sanitize($request['law_firm'] ?: ($company ?: ucfirst($entityType))); ?>
                            <?php if ($desiredUsername): ?> • Username: <strong><?php echo sanitize($desiredUsername); ?></strong><?php endif; ?>
                        </div>
                    </div>
                    <span style="background:<?php echo $request['status'] === 'pending' ? '#fef3c7' : ($request['status'] === 'approved' ? '#d1fae5' : '#fee2e2'); ?>;color:<?php echo $request['status'] === 'pending' ? '#92400e' : ($request['status'] === 'approved' ? '#065f46' : '#991b1b'); ?>;padding:6px 12px;border-radius:999px;font-size:0.8rem;font-weight:600;"><?php echo strtoupper($request['status']); ?></span>
                </div>
                
                <div class="request-meta" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                    <div>
                        <strong style="color:#475569;">Contact</strong>
                        <div><a href="mailto:<?php echo sanitize($request['email']); ?>" style="color:#1d4ed8;font-weight:600;"><?php echo sanitize($request['email']); ?></a></div>
                        <?php if ($request['phone']): ?><div><a href="tel:<?php echo sanitize($request['phone']); ?>" style="color:#1d4ed8;"><?php echo sanitize($request['phone']); ?></a></div><?php endif; ?>
                    </div>
                    
                    <div>
                        <strong style="color:#475569;">Case Assignment</strong>
                        <div><?php echo $request['case_title'] ? sanitize($request['case_title']) : 'General Access'; ?></div>
                    </div>
                    
                    <div>
                        <strong style="color:#475569;">Request Details</strong>
                        <div style="font-size:0.9rem;">
                            <?php 
                            $reasonDisplay = match($accessReason) {
                                'invited_general' => 'Invited (General)',
                                'found_case' => 'Found Case, Interested',
                                default => preg_match('/invited_by_\d+_case_\d+/', $accessReason) ? 'Invited by Team Member' : 'Other'
                            };
                            echo $reasonDisplay;
                            ?>
                        </div>
                        <div style="font-size:0.85rem;color:#94a3b8;">Requested <?php echo date('M j, Y g:ia', strtotime($requestedAt)); ?></div>
                    </div>
                </div>
                
                <?php if ($ipAddress || $userAgent): ?>
                    <details style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <summary style="cursor:pointer;font-weight:600;color:#475569;font-size:0.9rem;">🔒 Security Information</summary>
                        <div style="margin-top:8px;font-size:0.85rem;color:#64748b;">
                            <?php if ($ipAddress): ?><div><strong>IP:</strong> <?php echo sanitize($ipAddress); ?></div><?php endif; ?>
                            <?php if ($userAgent): ?><div><strong>Device:</strong> <?php echo sanitize(substr($userAgent, 0, 100)); ?></div><?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
                
                <?php if ($request['status'] === 'pending'): ?>
                    <div class="request-actions">
                        <button class="approve" data-action="approve" data-id="<?php echo (int)$request['id']; ?>">✅ Approve & Send Email</button>
                        <button class="deny" data-action="deny" data-id="<?php echo (int)$request['id']; ?>">❌ Deny</button>
                    </div>
                <?php elseif ($request['reviewed_by']): ?>
                    <div style="font-size:0.85rem;color:#64748b;padding:12px;background:#f8fafc;border-radius:8px;">
                        <strong>Reviewed by Admin ID <?php echo $request['reviewed_by']; ?></strong> on <?php echo date('M j, Y g:ia', strtotime($request['reviewed_at'])); ?>
                        <?php if (strpos($request['notes'], 'Admin:') !== false): ?>
                            <div style="margin-top:6px;font-style:italic;"><?php echo nl2br(sanitize(substr($request['notes'], strpos($request['notes'], 'Admin:')))); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Define path_with_base helper for JavaScript
function path_with_base(path) {
    const base = window.__BASE_PREFIX || '';
    if (path.startsWith('/')) {
        return base + path;
    }
    return base + '/' + path;
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.request-actions button').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const action = btn.dataset.action;
            const reason = action === 'deny' ? prompt('Provide reason for denial (optional):') : '';
            try {
                const res = await fetch(path_with_base('/api/legal/access-request-action.php'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ id, action, reason })
                });
                const json = await res.json();
                if (json.ok) {
                    window.location.reload();
                } else {
                    alert(json.message || 'Action failed.');
                }
            } catch (err) {
                console.error(err);
                alert('Unable to perform action.');
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php';

