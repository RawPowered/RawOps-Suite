<?php
// required/email_triggers_templates.php
// Defines preset email layouts for the admin email designer.

$AVAILABLE_EMAIL_TEMPLATES = [
    'clean' => [
        'label' => 'Minimal - Clean White',
        'description' => 'A professional white layout with call-to-action button.',
        'html' => <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Product Email</title>
<style>
body { margin:0; padding:0; background:#f4f4f4; font-family: "Helvetica Neue", Arial, sans-serif; }
.wrapper { width:100%; table-layout:fixed; background:#f4f4f4; padding:32px 0; }
.main { background:#ffffff; margin:0 auto; width:600px; border-radius:12px; overflow:hidden; box-shadow:0 4px 16px rgba(15,23,42,0.08); }
.header { background:linear-gradient(135deg,#0f766e,#14b8a6); padding:40px 32px; color:#ffffff; text-align:center; }
.content { padding:32px; color:#1f2937; line-height:1.7; }
.button { display:inline-block; padding:14px 26px; background:#0f766e; color:#ffffff !important; text-decoration:none; border-radius:999px; font-weight:600; }
.footer { padding:20px 32px; font-size:12px; color:#6b7280; text-align:center; background:#f9fafb; }
</style>
</head>
<body>
<table class="wrapper" role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td>
            <table class="main" role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td class="header">
                        <img src="{{assets_base_url}}/logo.svg" alt="Product" width="140">
                        <h1 style="margin:24px 0 8px;font-size:28px;font-weight:600;">{{subject}}</h1>
                        <p style="opacity:0.85; margin:0;">We’re building better wholesale systems together.</p>
                    </td>
                </tr>
                <tr>
                    <td class="content">
                        <p>Hi {{firstName}},</p>
                        <p>Thanks for connecting with Product. This is a friendly welcome email template. Replace this text with your message copy.</p>
                        <p>✳️ Use this highlight line for a key benefit or next step you want the reader to take.</p>
                        <p><a href="{{base_url}}" class="button">Visit Your Dashboard</a></p>
                        <p style="margin-top:32px;">If you have any questions, just reply to this email—we’re here to help.</p>
                        <p style="margin-top:24px; font-weight:600;">— The Product Team</p>
                    </td>
                </tr>
                <tr>
                    <td class="footer">
                        Product Wholesale · Example address omitted<br>
                        <a href="{{unsubscribe_link}}" style="color:#6b7280;">Unsubscribe</a> · <a href="{{base_url}}/privacy" style="color:#6b7280;">Privacy Policy</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
HTML,
        'text' => "Hi {{firstName}},\nThank you for connecting with Product. Visit your dashboard at {{base_url}}."
    ],

    'dark' => [
        'label' => 'Bold - Midnight Gradient',
        'description' => 'High-impact dark mode template with bold typography.',
        'html' => <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Product Email</title>
<style>
body { margin:0; padding:0; background:#0f172a; font-family: 'Inter', Arial, sans-serif; color:#e2e8f0; }
.wrapper { width:100%; table-layout:fixed; padding:40px 0; }
.main { width:640px; margin:0 auto; border-radius:20px; overflow:hidden; background:linear-gradient(160deg,#0f172a 0%,#111827 35%,#1f2937 100%); box-shadow:0 20px 35px rgba(15,23,42,0.5); }
.hero { padding:48px 40px; background:radial-gradient(circle at top left,rgba(56,189,248,0.35),rgba(14,165,233,0)); }
.hero h1 { margin:0 0 12px; font-size:32px; font-weight:700; }
.hero p { margin:0; font-size:16px; color:#cbd5f5; }
.content { padding:32px 40px; line-height:1.8; font-size:15px; }
.tag { display:inline-block; border-radius:999px; padding:6px 14px; background:rgba(59,130,246,0.12); color:#60a5fa; font-weight:600; font-size:12px; letter-spacing:0.08em; text-transform:uppercase; }
.button { display:inline-block; margin-top:24px; padding:16px 32px; border-radius:12px; background:linear-gradient(135deg,#3b82f6,#22d3ee); color:#0f172a !important; font-weight:700; letter-spacing:0.02em; text-decoration:none; }
.footer { padding:24px 32px; font-size:12px; color:#94a3b8; text-align:center; background:#0b1120; }
</style>
</head>
<body>
<table class="wrapper" role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr>
<td>
<table class="main" role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr><td class="hero">
<span class="tag">Product Wholesale</span>
<h1>{{subject}}</h1>
<p>Let’s simplify sourcing premium Products together.</p>
</td></tr>
<tr><td class="content">
<p>Hey {{firstName}},</p>
<p>Welcome to the next phase of your wholesale operations. Replace this copy with a mission statement or onboarding message.</p>
<p style="margin:28px 0; font-size:16px;">
    <strong>Highlights</strong><br>
    ✅ Tailored inventory recommendations.<br>
    ✅ Instant access to sales coaching & resources.<br>
    ✅ Real-time reorder alerts and forecasts.
</p>
<a href="{{base_url}}" class="button">Launch the Dashboard</a>
<p style="margin-top:40px">Need a personal walk-through? Reply and we’ll schedule time for you.</p>
<p style="margin-top:24px">Cheers,<br>The Product Wholesale Team</p>
</td></tr>
<tr><td class="footer">
    You're receiving this because you're a Product wholesale partner.<br>
    <a href="{{unsubscribe_link}}" style="color:#94a3b8;">Manage preferences</a>
</td></tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML,
        'text' => "Hey {{firstName}}, welcome to Product wholesale. Launch your dashboard at {{base_url}}."
    ],

    'newsletter' => [
        'label' => 'Newsletter - Sectioned Layout',
        'description' => 'Multi-section template with features and testimonials.',
        'html' => <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Product Newsletter</title>
<style>
body { margin:0; padding:0; background:#eef2ff; font-family:'Segoe UI', Helvetica, Arial, sans-serif; }
.outer { width:100%; background:#eef2ff; padding:24px 0; }
.inner { width:640px; margin:0 auto; background:#ffffff; border-radius:18px; overflow:hidden; box-shadow:0 24px 50px rgba(79,70,229,0.15); }
.header { padding:48px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#ffffff; }
.header h1 { margin:0; font-size:34px; }
.section { padding:36px 48px; color:#1f2937; line-height:1.7; }
.section h2 { font-size:22px; margin-top:0; }
.cards { display:flex; gap:24px; }
.card { flex:1; background:#f3f4ff; border-radius:12px; padding:20px; }
.testimonial { background:#fdf2f8; border-left:4px solid #ec4899; padding:20px; border-radius:12px; font-style:italic; }
.footer { background:#111827; color:#9ca3af; padding:24px; text-align:center; font-size:12px; }
.button { display:inline-block; margin-top:20px; padding:12px 22px; background:#4f46e5; color:#ffffff !important; border-radius:999px; text-decoration:none; font-weight:600; }
</style>
</head>
<body>
<table class="outer" role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr><td>
<table class="inner" role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr><td class="header">
<p style="letter-spacing:0.2em; text-transform:uppercase; font-size:12px; margin-bottom:12px;">Monthly Briefing</p>
<h1>{{subject}}</h1>
<p style="margin:16px 0 0; opacity:0.92;">Insights and growth moves for Product distributors.</p>
</td></tr>
<tr><td class="section">
<h2>What’s New</h2>
<div class="cards">
<div class="card">
    <h3>New Product Flights</h3>
    <p>Highlight new arrivals, limited editions, or seasonal blends here with compelling copy.</p>
</div>
<div class="card">
    <h3>Sales Coaching</h3>
    <p>Share tactical guidance or training reminders that help partners move more inventory.</p>
</div>
</div>
<a href="{{base_url}}/training" class="button">View Training Hub</a>
</td></tr>
<tr><td class="section">
<h2>Partner Spotlight</h2>
<div class="testimonial">
    “Product gave us the margins and white-glove support our retail locations needed to win loyalty.”<br>
    <strong>— {{firstName}} from {{company_name}}</strong>
</div>
</td></tr>
<tr><td class="section">
<h2>Next Steps</h2>
<ul>
    <li>✅ Restock your top-selling liter bundles.</li>
    <li>📊 Schedule a one-on-one optimization session.</li>
    <li>🎓 Complete module 3 in the training portal.</li>
</ul>
</td></tr>
<tr><td class="footer">
    Product Wholesale · Example address omitted<br>
    <a href="{{unsubscribe_link}}" style="color:#9ca3af;">Manage preferences</a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML,
        'text' => "Monthly briefing from Product. View training hub: {{base_url}}/training"
    ]
];
