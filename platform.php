<?php
require_once __DIR__ . '/required/config.php';

$SEO = [
    'title' => 'Product Platform — All-In-One Industry Platform',
    'description' => 'Explore the proprietary Product platform: manufacturing management, sales automation, AI-powered CRM, and enterprise-grade infrastructure built to scale.',
    'type' => 'website',
    'image' => outbound_path('/assets/og-default.png')
];

// Require investor role or admin
$uid = current_user_id();
if (!$uid) {
    // Redirect to investor signup instead of generic login
    redirect('/index.php?role=investor#hero-title');
}

$isInvestor = user_is_investor($uid);
$isAdmin = user_has_role($uid, 'admin');

if (!$isInvestor && !$isAdmin) {
    flash_set('error', 'Access denied. Investor access required.');
    redirect('/');
}

// Check NDA completion for investors
$ndaRequired = false;
$showNdaModal = false;
if ($isInvestor && !$isAdmin) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT nda_completed, platform_access_granted FROM investor_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $profile = $stmt->fetch();
        
        if ($profile && !$profile['nda_completed']) {
            $ndaRequired = true;
            // Show modal immediately if show_nda param is present, or if just registered
            $showNdaModal = !empty($_GET['show_nda']) || !empty($_GET['new_investor']);
        } elseif (!$profile) {
            // No investor profile found - shouldn't happen, but handle gracefully
            error_log("Platform access: No investor profile found for user {$uid}");
            flash_set('error', 'Investor profile not found. Please contact support.');
            redirect('/');
        }
    } catch (Throwable $e) {
        error_log('Platform NDA check error: ' . $e->getMessage());
        flash_set('error', 'Unable to verify platform access. Please try again.');
        redirect('/');
    }
}

require_once __DIR__ . '/required/header.php';
?>

<?php if ($showNdaModal): ?>
<div id="nda-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:16px;max-width:900px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,0.4);">
        <div style="padding:32px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:28px;font-weight:800;color:#0f172a;">Platform Access Agreement</h2>
            <p style="margin:8px 0 0;color:#64748b;">Please review and sign the following agreements to access the platform.</p>
        </div>
        <div style="padding:32px;">
            <div style="margin-bottom:24px;">
                <h3 style="margin:0 0 12px;font-size:16px;font-weight:700;color:#0f172a;">Non-Disclosure Agreement</h3>
                <iframe src="<?php echo outbound_path('/api/get-agreement-embed.php?type=investor_nda'); ?>" style="width:100%;height:300px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:16px;"></iframe>
            </div>
            
            <div style="margin-bottom:24px;">
                <h3 style="margin:0 0 12px;font-size:16px;font-weight:700;color:#0f172a;">Platform Access Agreement</h3>
                <iframe src="<?php echo outbound_path('/api/get-agreement-embed.php?type=investor_platform_access'); ?>" style="width:100%;height:300px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:16px;"></iframe>
            </div>
            
            <div style="background:#f8fafc;padding:20px;border-radius:8px;margin-bottom:24px;border:2px solid #e2e8f0;">
                <label style="display:flex;align-items:start;gap:12px;cursor:pointer;">
                    <input type="checkbox" id="nda-checkbox" required style="margin-top:4px;width:20px;height:20px;flex-shrink:0;">
                    <span style="color:#1e293b;font-size:15px;line-height:1.6;">
                        <strong>I have read and agree to the Non-Disclosure Agreement and Platform Access Agreement.</strong> I understand that all information accessed through this platform is confidential and proprietary.
                    </span>
                </label>
            </div>
            
            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <button onclick="declineNda()" style="padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;background:#f3f4f6;color:#374151;border:none;cursor:pointer;">
                    Decline
                </button>
                <button id="accept-nda-btn" onclick="acceptNda()" disabled style="padding:12px 32px;border-radius:8px;font-size:15px;font-weight:700;background:#0ea5e9;color:#fff;border:none;cursor:not-allowed;opacity:0.5;">
                    Accept & Continue
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('nda-checkbox').addEventListener('change', function() {
    const btn = document.getElementById('accept-nda-btn');
    if (this.checked) {
        btn.disabled = false;
        btn.style.cursor = 'pointer';
        btn.style.opacity = '1';
    } else {
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.5';
    }
});

function declineNda() {
    if (confirm('Are you sure? You will not be able to access the platform without accepting the NDA.')) {
        window.location.href = '<?php echo outbound_path('/logout.php'); ?>';
    }
}

function acceptNda() {
    fetch('<?php echo outbound_path('/api/investor/accept-nda.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({accept: true})
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            document.getElementById('nda-modal').remove();
            location.reload();
        } else {
            alert('Error accepting NDA. Please try again.');
        }
    })
    .catch(e => {
        alert('Error accepting NDA. Please try again.');
    });
}
</script>
<?php endif; ?>

<section class="section" style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;padding:80px 0;">
    <div class="container" style="text-align:center;">
        <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.15);border:1px solid rgba(14,165,233,0.3);border-radius:50px;margin-bottom:20px;">
            <span style="color:#38bdf8;font-weight:600;font-size:14px;letter-spacing:0.5px;">PROPRIETARY TECHNOLOGY</span>
        </div>
        <h1 style="margin:0 0 24px;font-size:52px;font-weight:900;line-height:1.1;background:linear-gradient(135deg,#fff,#94a3b8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">
            Product™ All-In-One<br>Industry Platform
        </h1>
        <p style="margin:0 auto;max-width:720px;font-size:22px;line-height:1.6;color:#cbd5e1;">
            Enterprise-grade infrastructure powering manufacturing, sales, operations, and fulfillment. Built from the ground up to scale across multiple industries.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="text-align:center;max-width:800px;margin:0 auto 60px;">
            <h2 style="margin:0 0 16px;font-size:38px;font-weight:800;color:var(--blue);">Platform Architecture Overview</h2>
            <p style="margin:0;font-size:18px;color:#475569;line-height:1.7;">
                A comprehensive ecosystem designed for operational excellence. Every component works in harmony to deliver measurable results.
            </p>
        </div>

        <!-- Manufacturing Management -->
        <div class="card" style="padding:48px;margin-bottom:32px;border:2px solid #e0f2fe;background:linear-gradient(135deg,#fff,#f0f9ff);">
            <div style="display:flex;align-items:start;gap:24px;margin-bottom:32px;">
                <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-industry" style="font-size:36px;color:#fff;"></i>
                </div>
                <div>
                    <h3 style="margin:0 0 12px;font-size:32px;font-weight:800;color:#0c4a6e;">Manufacturing Management</h3>
                    <p style="margin:0;font-size:18px;color:#475569;">Complete product lifecycle control from formulation to fulfillment</p>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #e0f2fe;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-flask" style="font-size:24px;color:#0ea5e9;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Product Group Management</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Organize products into groups with shared recipes, COA batch tracking, and version-controlled formulations. Track every ingredient, material, and labor cost.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #e0f2fe;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-certificate" style="font-size:24px;color:#0ea5e9;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">COA Batch Tracking</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Certificate of Analysis management with lot numbers, expiration dates, and full compliance documentation. Every batch traceable from source to sale.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #e0f2fe;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-clipboard-list" style="font-size:24px;color:#0ea5e9;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Work Order System</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Production work orders with real-time status tracking, labor assignment, and automated inventory updates upon completion.
                    </p>
                </div>
            </div>
        </div>

        <!-- Sales Automation & CRM -->
        <div class="card" style="padding:48px;margin-bottom:32px;border:2px solid #dcfce7;background:linear-gradient(135deg,#fff,#f0fdf4);">
            <div style="display:flex;align-items:start;gap:24px;margin-bottom:32px;">
                <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-chart-line" style="font-size:36px;color:#fff;"></i>
                </div>
                <div>
                    <h3 style="margin:0 0 12px;font-size:32px;font-weight:800;color:#14532d;">Sales Automation & CRM</h3>
                    <p style="margin:0;font-size:18px;color:#475569;">Intelligent lead management with AI assistance and zero hallucinations</p>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #dcfce7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-magic" style="font-size:24px;color:#22c55e;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Automatic Lead Generation</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Every registration instantly creates a sales lead with complete contact info, assigned territory, and automated tagging based on business type.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #dcfce7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-robot" style="font-size:24px;color:#22c55e;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">AI-Powered Assistant</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Context-aware AI assistant with access to real customer data, order history, and product catalog. Perfectly balanced human-AI interaction with zero hallucinations.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #dcfce7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-dollar-sign" style="font-size:24px;color:#22c55e;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Commission Automation</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Automatic commission calculation, tracking, and payment processing. Sales agents see real-time earnings with full transparency.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #dcfce7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-map-marked-alt" style="font-size:24px;color:#22c55e;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Territory Management</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Geographic territory assignment with automatic lead routing. Geo-location data enrichment for intelligent market insights.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #dcfce7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-columns" style="font-size:24px;color:#22c55e;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Kanban Pipeline</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Visual drag-and-drop sales pipeline with customizable stages. Track deal progress from first contact to closed-won.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #dcfce7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-tachometer-alt" style="font-size:24px;color:#22c55e;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Real-Time Analytics</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Live performance dashboards with conversion rates, average deal size, and agent leaderboards. Data-driven decision making.
                    </p>
                </div>
            </div>
        </div>

        <!-- Admin & Finance -->
        <div class="card" style="padding:48px;margin-bottom:32px;border:2px solid #fef3c7;background:linear-gradient(135deg,#fff,#fffbeb);">
            <div style="display:flex;align-items:start;gap:24px;margin-bottom:32px;">
                <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-shield-alt" style="font-size:36px;color:#fff;"></i>
                </div>
                <div>
                    <h3 style="margin:0 0 12px;font-size:32px;font-weight:800;color:#78350f;">Admin & Finance Control</h3>
                    <p style="margin:0;font-size:18px;color:#475569;">Enterprise-grade permissions and automated financial operations</p>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fef3c7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-users-cog" style="font-size:24px;color:#f59e0b;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Multi-Role Permissions</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Granular role-based access control. Admin, Legal, Operations, Sales, Customer, Trainee, Investor, Accountant roles with specific permissions.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fef3c7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-calculator" style="font-size:24px;color:#f59e0b;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Accounting Automation</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Automated expense tracking, revenue recognition, and cost allocation. Real-time P&L with drill-down to transaction level.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fef3c7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-envelope-open-text" style="font-size:24px;color:#f59e0b;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Email Workflow Builder</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Drag-and-drop email automation with triggers, delays, and conditional logic. Visual workflow editor with SendGrid integration.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fef3c7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-bug" style="font-size:24px;color:#f59e0b;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Error Tracking & Debug</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Comprehensive error logging with stack traces, user context, and occurrence grouping. Real-time debug tools for rapid troubleshooting.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fef3c7;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-chart-bar" style="font-size:24px;color:#f59e0b;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Usage Analytics</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Track every user interaction, page view, and feature usage. Identify bottlenecks and optimize user experience with data.
                    </p>
                </div>
            </div>
        </div>

        <!-- Training & Certification -->
        <div class="card" style="padding:48px;margin-bottom:32px;border:2px solid #e9d5ff;background:linear-gradient(135deg,#fff,#faf5ff);">
            <div style="display:flex;align-items:start;gap:24px;margin-bottom:32px;">
                <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#a855f7,#9333ea);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-graduation-cap" style="font-size:36px;color:#fff;"></i>
                </div>
                <div>
                    <h3 style="margin:0 0 12px;font-size:32px;font-weight:800;color:#581c87;">Training & Certification System</h3>
                    <p style="margin:0;font-size:18px;color:#475569;">Built-in LMS with compliance tracking and automated certification</p>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #e9d5ff;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-book-open" style="font-size:24px;color:#a855f7;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Interactive Modules</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Rich multimedia training modules with video, interactive quizzes, and knowledge checks. Track completion and scores.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #e9d5ff;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-clipboard-check" style="font-size:24px;color:#a855f7;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Progress Tracking</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Real-time progress monitoring per agent. Admins see completion rates, quiz scores, and time spent per module.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #e9d5ff;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-file-signature" style="font-size:24px;color:#a855f7;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">E-Signature Agreements</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        NDA, non-compete, and consent agreements with IP logging and version control. Full audit trail for compliance.
                    </p>
                </div>
            </div>
        </div>

        <!-- Operations & Fulfillment -->
        <div class="card" style="padding:48px;margin-bottom:32px;border:2px solid #fecaca;background:linear-gradient(135deg,#fff,#fef2f2);">
            <div style="display:flex;align-items:start;gap:24px;margin-bottom:32px;">
                <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#ef4444,#dc2626);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-truck" style="font-size:36px;color:#fff;"></i>
                </div>
                <div>
                    <h3 style="margin:0 0 12px;font-size:32px;font-weight:800;color:#7f1d1d;">Operations & Fulfillment</h3>
                    <p style="margin:0;font-size:18px;color:#475569;">End-to-end order processing and inventory management</p>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fecaca;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-boxes" style="font-size:24px;color:#ef4444;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Inventory Management</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Real-time stock tracking with low-stock alerts, automatic reorder points, and FIFO/LIFO support. Quick-add for rapid inventory updates.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fecaca;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-shipping-fast" style="font-size:24px;color:#ef4444;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Order Processing</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Streamlined order workflow from cart to shipment. Automated shipping rate calculation and label generation.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fecaca;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-vial" style="font-size:24px;color:#ef4444;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Sample Fulfillment</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Automated sample request processing with email notifications and tracking. Convert samples to full orders seamlessly.
                    </p>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:12px;border:1px solid #fecaca;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <i class="fas fa-qrcode" style="font-size:24px;color:#ef4444;"></i>
                        <h4 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">Barcode/QR Ready</h4>
                    </div>
                    <p style="margin:0;font-size:15px;color:#64748b;line-height:1.6;">
                        Infrastructure ready for barcode scanning and QR code integration for inventory, shipping, and product authentication.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" style="background:linear-gradient(135deg,#f8fafc,#eef2ff);">
    <div class="container">
        <div style="text-align:center;max-width:900px;margin:0 auto;">
            <h2 style="margin:0 0 24px;font-size:38px;font-weight:800;color:var(--blue);">Built to Scale Across Industries</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:32px;margin:48px 0;">
                <div>
                    <div style="width:64px;height:64px;border-radius:12px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                        <i class="fas fa-utensils" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#0f172a;">Food & Beverage</h3>
                    <p style="margin:0;color:#64748b;font-size:15px;">Recipe management, batch tracking, compliance documentation</p>
                </div>
                
                <div>
                    <div style="width:64px;height:64px;border-radius:12px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                        <i class="fas fa-prescription-bottle" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#0f172a;">product</h3>
                    <p style="margin:0;color:#64748b;font-size:15px;">COA tracking, lot control, regulatory compliance</p>
                </div>
                
                <div>
                    <div style="width:64px;height:64px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                        <i class="fas fa-cogs" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#0f172a;">Manufacturing</h3>
                    <p style="margin:0;color:#64748b;font-size:15px;">Work orders, material costing, production scheduling</p>
                </div>
            </div>
            
            <div class="card" style="padding:40px;background:#fff;border:2px solid #e0f2fe;margin-top:48px;">
                <h3 style="margin:0 0 16px;font-size:28px;font-weight:800;color:var(--blue);">
                    <i class="fas fa-rocket" style="color:#0ea5e9;margin-right:12px;"></i>
                    Enterprise-Grade Architecture
                </h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:24px;margin-top:32px;">
                    <div style="text-align:left;">
                        <div style="font-size:32px;font-weight:900;color:#0ea5e9;margin-bottom:8px;">99.9%</div>
                        <div style="color:#64748b;font-size:15px;">Uptime SLA</div>
                    </div>
                    <div style="text-align:left;">
                        <div style="font-size:32px;font-weight:900;color:#0ea5e9;margin-bottom:8px;">&lt;200ms</div>
                        <div style="color:#64748b;font-size:15px;">Avg Response Time</div>
                    </div>
                    <div style="text-align:left;">
                        <div style="font-size:32px;font-weight:900;color:#0ea5e9;margin-bottom:8px;">10K+</div>
                        <div style="color:#64748b;font-size:15px;">Concurrent Users</div>
                    </div>
                    <div style="text-align:left;">
                        <div style="font-size:32px;font-weight:900;color:#0ea5e9;margin-bottom:8px;">∞</div>
                        <div style="color:#64748b;font-size:15px;">Scalability</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($ndaRequired && !$showNdaModal): ?>
<section class="section" style="background:#fef3c7;border-top:4px solid #f59e0b;border-bottom:4px solid #f59e0b;">
    <div class="container" style="text-align:center;">
        <i class="fas fa-lock" style="font-size:48px;color:#d97706;margin-bottom:16px;"></i>
        <h2 style="margin:0 0 16px;font-size:28px;font-weight:800;color:#78350f;">NDA Required</h2>
        <p style="margin:0 0 24px;font-size:18px;color:#78350f;">
            To view detailed platform demonstrations and technical documentation, please complete your Non-Disclosure Agreement.
        </p>
        <a href="<?php echo outbound_path('/platform.php?show_nda=1'); ?>" class="btn" style="padding:14px 32px;font-size:16px;font-weight:700;">
            Review & Sign NDA
        </a>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/required/footer.php'; ?>

