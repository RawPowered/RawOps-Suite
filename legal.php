<?php
$SEO = [
    'title' => 'Product® Legal Management Systems — Enterprise Legal Case Management',
    'description' => 'World-class legal case management platform for law firms, authorities, and legal entities. Secure, compliant, and built for high-stakes litigation.',
    'type' => 'website'
];

require_once __DIR__ . '/required/header.php';

start_session();

// Check if viewing a specific legal section (e.g., AI disclaimer)
$section = $_GET['section'] ?? null;

// If viewing a specific section, don't redirect
if (!$section) {
    // If already logged in, redirect to appropriate portal
    $currentUser = current_user_id();
    if ($currentUser) {
        if (user_has_role($currentUser, 'admin')) {
            redirect(path_with_base('/admin/legal/index.php'));
        } elseif (user_has_role($currentUser, 'legal')) {
            redirect(path_with_base('/admin/legal/portal/'));
        }
    }
}

// Handle different sections
if ($section === 'ai-disclaimer') {
    // Show AI disclaimer page
    ?>
    <style>
    .disclaimer-container {
        max-width: 900px;
        margin: 60px auto;
        padding: 0 24px;
    }
    .disclaimer-card {
        background: white;
        border-radius: 16px;
        padding: 48px;
        box-shadow: 0 12px 28px rgba(15,23,42,0.08);
        border: 1px solid #e2e8f0;
    }
    .disclaimer-card h1 {
        font-size: 2.5rem;
        color: #0f172a;
        margin: 0 0 24px 0;
    }
    .disclaimer-card h2 {
        font-size: 1.8rem;
        color: #1e293b;
        margin: 32px 0 16px 0;
    }
    .disclaimer-card p {
        color: #475569;
        line-height: 1.8;
        margin-bottom: 16px;
    }
    .disclaimer-card ul {
        color: #475569;
        line-height: 1.8;
        margin-bottom: 16px;
    }
    .disclaimer-card li {
        margin-bottom: 8px;
    }
    .info-box {
        background: #eff6ff;
        border: 2px solid #3b82f6;
        border-radius: 12px;
        padding: 20px;
        margin: 24px 0;
    }
    .info-box p {
        margin: 0;
        color: #1e40af;
    }
    .back-link {
        display: inline-block;
        color: #3b82f6;
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 24px;
    }
    .back-link:hover {
        text-decoration: underline;
    }
    </style>
    
    <div class="disclaimer-container">
        <a href="<?php echo outbound_path('/'); ?>" class="back-link">← Back to Home</a>
        
        <div class="disclaimer-card">
            <h1>⚠️ AI-Generated Communications Disclaimer</h1>
            
            <div class="info-box">
                <p><strong>Important Notice:</strong> This communication may have been written with assistance from or entirely by artificial intelligence (AI) systems.</p>
            </div>
            
            <h2>About Our AI Systems</h2>
            <p>
                Product utilizes advanced artificial intelligence technology to enhance our communications and 
                improve efficiency. These AI systems assist our sales agents and automated workflows in composing 
                emails, generating content, and providing information to our customers.
            </p>
            
            <h2>Potential Limitations</h2>
            <p>
                While we continuously work to ensure the accuracy and reliability of our AI systems, you should be 
                aware of the following potential issues:
            </p>
            <ul>
                <li><strong>Factual Inconsistencies:</strong> AI may occasionally generate incorrect or outdated information</li>
                <li><strong>Numerical Errors:</strong> Prices, quantities, or calculations may contain mistakes</li>
                <li><strong>Language Issues:</strong> Phrasing may be unclear, awkward, or contextually inappropriate</li>
                <li><strong>Incomplete Information:</strong> AI may omit important details or context</li>
                <li><strong>Misinterpretation:</strong> AI may misunderstand your questions or needs</li>
            </ul>
            
            <h2>Our Commitment to Accuracy</h2>
            <p>
                Product is committed to providing accurate, reliable, and professional communications. We regularly:
            </p>
            <ul>
                <li>Update and refine our AI models to improve accuracy</li>
                <li>Monitor AI-generated content for quality assurance</li>
                <li>Train our team members to review AI output before sending</li>
                <li>Implement safeguards to prevent common AI errors</li>
                <li>Respond promptly to correct any mistakes that occur</li>
            </ul>
            
            <h2>Agent Responsibility</h2>
            <p>
                Our sales agents are trained and required to review all AI-generated content before sending it to 
                customers. They are responsible for ensuring accuracy, appropriateness, and completeness of all 
                communications sent under their name.
            </p>
            
            <h2>Platform-Generated Automation</h2>
            <p>
                Some automated emails are generated directly by our platform's systems without agent intervention. 
                In these cases, Product assumes responsibility for the content and accuracy of these communications.
            </p>
            
            <h2>What You Should Do</h2>
            <p>
                If you receive a communication that may have been AI-generated:
            </p>
            <ul>
                <li><strong>Verify Important Details:</strong> Double-check prices, quantities, dates, and other critical information</li>
                <li><strong>Ask Questions:</strong> If anything is unclear or seems incorrect, please ask for clarification</li>
                <li><strong>Report Errors:</strong> Help us improve by letting us know if you notice any mistakes</li>
                <li><strong>Request Human Review:</strong> You can always request that a human team member review your inquiry</li>
            </ul>
            
            <h2>Contact Us</h2>
            <p>
                If you have any questions, concerns, or notice errors in any AI-generated communication, please contact us:
            </p>
            <ul>
                <li>Email: <a href="mailto:sample-003@example.com" style="color:#3b82f6;">sample-003@example.com</a></li>
                <li>Phone: Available upon request</li>
            </ul>
            
            <p style="margin-top: 40px; padding-top: 24px; border-top: 2px solid #e2e8f0; font-size: 0.9rem; color: #64748b;">
                <strong>Last Updated:</strong> <?php echo date('F j, Y'); ?><br>
                Product LLC — Committed to transparency and quality in all communications
            </p>
        </div>
    </div>
    
    <?php require_once __DIR__ . '/required/footer.php'; ?>
    <?php exit; ?>
<?php
}

// Default landing page
?>
<style>
.legal-landing {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 24px;
}
.hero-section {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #7c3aed 100%);
    color: #fff;
    border-radius: 20px;
    padding: 80px 60px;
    margin: 60px 0;
    position: relative;
    overflow: hidden;
    box-shadow: 0 24px 48px rgba(15,23,42,0.3);
}
.hero-section::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at top right, rgba(255,255,255,0.15), transparent 60%);
    pointer-events: none;
}
.hero-content {
    position: relative;
    z-index: 1;
    max-width: 800px;
}
.hero-content h1 {
    margin: 0 0 20px 0;
    font-size: 3.5rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.1;
}
.hero-content p {
    margin: 0 0 32px 0;
    font-size: 1.3rem;
    line-height: 1.7;
    opacity: 0.95;
}
.hero-badge {
    display: inline-block;
    background: rgba(251,191,36,0.2);
    color: #fbbf24;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 0.9rem;
    font-weight: 600;
    border: 1px solid rgba(251,191,36,0.4);
    margin-bottom: 24px;
}
.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 28px;
    margin: 60px 0;
}
.feature-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 12px 28px rgba(15,23,42,0.08);
}
.feature-icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.feature-card h3 {
    margin: 0 0 12px 0;
    font-size: 1.4rem;
    color: #0f172a;
}
.feature-card p {
    margin: 0;
    color: #475569;
    line-height: 1.7;
}
.cta-section {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    padding: 48px;
    text-align: center;
    margin: 60px 0;
}
.cta-section h2 {
    margin: 0 0 16px 0;
    font-size: 2rem;
    color: #0f172a;
}
.cta-section p {
    margin: 0 0 28px 0;
    color: #64748b;
    font-size: 1.1rem;
}
.status-badge {
    display: inline-block;
    background: #fef3c7;
    border: 2px solid #fbbf24;
    color: #92400e;
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 1.05rem;
}
</style>

<div class="legal-landing">
    <section class="hero-section">
        <div class="hero-content">
            <span class="hero-badge">Enterprise Legal Technology</span>
            <h1>Product<sup style="font-size:0.5em;">®</sup> Legal Management Systems</h1>
            <p>World-class case management platform designed for law firms, legal authorities, and corporate legal departments. Secure evidence handling, team collaboration, and regulatory compliance built into every feature.</p>
        </div>
    </section>
    
    <section class="features-grid">
        <article class="feature-card">
            <div class="feature-icon">🗂️</div>
            <h3>Case Organization</h3>
            <p>Centralized case management with metadata tracking, retention policies, and regulatory compliance automation. Each case maintains its own secure evidence locker.</p>
        </article>
        
        <article class="feature-card">
            <div class="feature-icon">🔒</div>
            <h3>Secure Evidence Handling</h3>
            <p>Bank-grade encryption, chain-of-custody tracking, and audit logging for every document. Role-based access ensures only authorized team members view sensitive materials.</p>
        </article>
        
        <article class="feature-card">
            <div class="feature-icon">👥</div>
            <h3>Team Collaboration</h3>
            <p>Real-time shoutbox with @mentions, threaded notes, and activity feeds. Assign roles (lead counsel, paralegal, investigator) with granular permissions.</p>
        </article>
        
        <article class="feature-card">
            <div class="feature-icon">📊</div>
            <h3>Retention & Compliance</h3>
            <p>Configurable retention policies (1-20 years or indefinite). Regulatory holds, automated expiration warnings, and comprehensive activity logs for discovery.</p>
        </article>
        
        <article class="feature-card">
            <div class="feature-icon">🔍</div>
            <h3>Advanced Search & Tagging</h3>
            <p>Tag evidence, mention documents in discussions, and maintain detailed metadata. Full-text search across case notes and document descriptions.</p>
        </article>
        
        <article class="feature-card">
            <div class="feature-icon">📱</div>
            <h3>Multi-Device Access</h3>
            <p>Responsive design works on desktop, tablet, and mobile. Secure session management with device tracking for security audits.</p>
        </article>
    </section>
    
    <section class="cta-section">
        <h2>Request Legal Team Access</h2>
        <p>Join as legal counsel, paralegal, or investigator on an active case. Select your case and request access below.</p>
        
        <form id="legal-access-form" style="max-width:600px;margin:32px auto;text-align:left;">
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#0f172a;">Your Full Name *</label>
                <input type="text" name="requestor_name" required style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;">
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#0f172a;">Law Firm / Organization</label>
                <input type="text" name="law_firm" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;">
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#0f172a;">Email Address *</label>
                <input type="email" name="email" required style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;">
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#0f172a;">Phone Number</label>
                <input type="tel" name="phone" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;">
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#0f172a;">Desired Username *</label>
                <input type="text" name="desired_username" required style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;">
                <small style="display:block;margin-top:4px;color:#64748b;">This will be your login username</small>
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:8px;color:#0f172a;">Select Case *</label>
                <select name="case_key" required style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;" id="case-select">
                    <option value="">-- Select a case --</option>
                    <?php
                    try {
                        $pdo = db();
                        $stmt = $pdo->query("SELECT case_key, title FROM legal_cases WHERE status IN ('active', 'on_hold') ORDER BY case_number DESC");
                        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($cases as $case) {
                            // Show last 4 characters for privacy
                            $censoredTitle = '••••' . substr($case['title'], -4);
                            echo '<option value="' . htmlspecialchars($case['case_key']) . '">' . htmlspecialchars($censoredTitle) . '</option>';
                        }
                    } catch (Exception $e) {
                        echo '<option value="">Error loading cases</option>';
                    }
                    ?>
                </select>
                <small style="display:block;margin-top:4px;color:#64748b;">Cases are censored for privacy. You should know which case to select.</small>
            </div>
            
            <div style="border-top:2px solid #e2e8f0;padding-top:20px;margin-top:28px;">
                <h4 style="margin:0 0 16px 0;color:#0f172a;">Additional Team Members (Optional)</h4>
                <p style="margin:0 0 16px 0;font-size:0.95rem;color:#64748b;">Add up to 3 additional team members who need access</p>
                
                <div id="additional-members">
                    <!-- Additional member forms will be added here -->
                </div>
                
                <button type="button" id="add-member-btn" style="padding:10px 20px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;font-weight:600;cursor:pointer;color:#0f172a;">
                    ➕ Add Team Member
                </button>
            </div>
            
            <button type="submit" style="width:100%;padding:16px;background:#1e3a8a;color:#fff;border:none;border-radius:8px;font-size:1.1rem;font-weight:600;cursor:pointer;margin-top:28px;">
                Submit Access Request
            </button>
            
            <div id="form-message" style="margin-top:16px;padding:12px;border-radius:8px;display:none;"></div>
        </form>
    </section>
    
    <script>
    (function() {
        let memberCount = 0;
        const maxMembers = 3;
        
        document.getElementById('add-member-btn').addEventListener('click', function() {
            if (memberCount >= maxMembers) {
                alert('Maximum 3 additional team members allowed');
                return;
            }
            
            memberCount++;
            const container = document.getElementById('additional-members');
            const memberDiv = document.createElement('div');
            memberDiv.className = 'member-form';
            memberDiv.style.cssText = 'margin-bottom:20px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;position:relative;';
            memberDiv.innerHTML = `
                <button type="button" class="remove-member" style="position:absolute;top:8px;right:8px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:4px 12px;border-radius:6px;font-size:0.85rem;cursor:pointer;">Remove</button>
                <h5 style="margin:0 0 12px 0;color:#0f172a;">Team Member ${memberCount}</h5>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;font-size:0.9rem;">Full Name</label>
                    <input type="text" name="member_name_${memberCount}" class="member-input" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;font-size:0.9rem;">Email</label>
                    <input type="email" name="member_email_${memberCount}" class="member-input" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;font-size:0.9rem;">Phone</label>
                    <input type="tel" name="member_phone_${memberCount}" class="member-input" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            `;
            
            container.appendChild(memberDiv);
            
            memberDiv.querySelector('.remove-member').addEventListener('click', function() {
                memberDiv.remove();
                memberCount--;
            });
        });
        
        document.getElementById('legal-access-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const payload = {
                requestor_name: formData.get('requestor_name'),
                law_firm: formData.get('law_firm'),
                email: formData.get('email'),
                phone: formData.get('phone'),
                desired_username: formData.get('desired_username'),
                case_key: formData.get('case_key'),
                additional_members: []
            };
            
            // Collect additional members
            const memberInputs = document.querySelectorAll('.member-input');
            for (let i = 1; i <= memberCount; i++) {
                const name = formData.get(`member_name_${i}`);
                const email = formData.get(`member_email_${i}`);
                const phone = formData.get(`member_phone_${i}`);
                
                if (name && email) {
                    payload.additional_members.push({ name, email, phone });
                }
            }
            
            const messageDiv = document.getElementById('form-message');
            const submitBtn = e.target.querySelector('button[type="submit"]');
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            
            try {
                const response = await fetch('<?php echo path_with_base('/api/legal/request-access.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                
                const result = await response.json();
                
                messageDiv.style.display = 'block';
                
                if (result.ok) {
                    messageDiv.style.background = '#dcfce7';
                    messageDiv.style.border = '2px solid #86efac';
                    messageDiv.style.color = '#15803d';
                    messageDiv.innerHTML = `
                        <strong>✅ Request Submitted Successfully!</strong><br>
                        ${result.message}<br>
                        <small>You will receive an email confirmation shortly.</small>
                    `;
                    e.target.reset();
                    document.getElementById('additional-members').innerHTML = '';
                    memberCount = 0;
                } else {
                    messageDiv.style.background = '#fee2e2';
                    messageDiv.style.border = '2px solid #fca5a5';
                    messageDiv.style.color = '#991b1b';
                    messageDiv.innerHTML = `<strong>❌ Error:</strong> ${result.message}`;
                }
            } catch (error) {
                messageDiv.style.display = 'block';
                messageDiv.style.background = '#fee2e2';
                messageDiv.style.border = '2px solid #fca5a5';
                messageDiv.style.color = '#991b1b';
                messageDiv.innerHTML = `<strong>❌ Error:</strong> Failed to submit request. Please try again.`;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Access Request';
            }
        });
    })();
    </script>
    
    <section style="text-align:center;margin:60px 0;padding:40px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;">
        <h2 style="margin:0 0 16px 0;color:#0f172a;">About Product<sup style="font-size:0.5em;">®</sup></h2>
        <p style="max-width:700px;margin:0 auto;color:#475569;line-height:1.8;">
            Product® is a multifaceted technology and sales company specializing in enterprise solutions for wholesale distribution, sales enablement, and now legal case management. Our platforms are built with security, compliance, and user experience at the forefront.
        </p>
    </section>
</div>

<?php require_once __DIR__ . '/required/footer.php';
