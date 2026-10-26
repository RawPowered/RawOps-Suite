/**
 * Agreement Reminder Modal
 * Displays a non-dismissible modal for users who need to complete legal agreements
 */

document.addEventListener('DOMContentLoaded', function() {
    // Check if modal should be shown (set by PHP)
    if (typeof showAgreementModal === 'undefined' || !showAgreementModal) {
        return;
    }
    
    // Create modal overlay
    const modalOverlay = document.createElement('div');
    modalOverlay.id = 'agreement-reminder-modal';
    modalOverlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.85);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    `;
    
    // Create modal content
    const modalContent = document.createElement('div');
    modalContent.style.cssText = `
        background: white;
        border-radius: 16px;
        max-width: 600px;
        width: 100%;
        padding: 40px;
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.3);
        text-align: center;
    `;
    
    modalContent.innerHTML = `
        <div style="font-size: 64px; margin-bottom: 24px;">⚖️</div>
        <h2 style="font-size: 28px; font-weight: 700; color: #1f2937; margin: 0 0 16px 0;">
            Legal Agreements Required
        </h2>
        <p style="font-size: 16px; color: #6b7280; line-height: 1.6; margin: 0 0 32px 0;">
            Before you can continue using the platform, you must review and sign all required legal agreements. 
            This is a one-time process that ensures compliance and protects both you and Product.
        </p>
        <button 
            id="complete-agreements-btn"
            style="
                padding: 14px 32px;
                background: #10b981;
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            "
            onmouseover="this.style.background='#059669'"
            onmouseout="this.style.background='#10b981'"
        >
            Complete Agreements Now →
        </button>
        <p style="font-size: 13px; color: #9ca3af; margin: 24px 0 0 0;">
            This will take approximately 5-10 minutes
        </p>
    `;
    
    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);
    
    // Prevent body scrolling
    document.body.style.overflow = 'hidden';
    
    // Handle button click
    document.getElementById('complete-agreements-btn').addEventListener('click', function() {
        // Use the base path if it exists
        const basePath = window.__BASE_PREFIX || '';
        window.location.href = basePath + '/onboarding/trainee/agreements.php';
    });
    
    // Prevent closing modal by clicking outside (no escape!)
    modalOverlay.addEventListener('click', function(e) {
        if (e.target === modalOverlay) {
            // Shake animation to show it can't be dismissed
            modalContent.style.animation = 'shake 0.5s';
            setTimeout(() => {
                modalContent.style.animation = '';
            }, 500);
        }
    });
    
    // Prevent escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            // Shake animation
            modalContent.style.animation = 'shake 0.5s';
            setTimeout(() => {
                modalContent.style.animation = '';
            }, 500);
        }
    });
});

// Add shake animation to document
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-10px); }
        20%, 40%, 60%, 80% { transform: translateX(10px); }
    }
`;
document.head.appendChild(style);

