<?php
/**
 * Email Workflow Integration Snippet for leads.php
 * 
 * Copy and paste this code into your leads.php email composition section
 * to enable workflow selection when sending emails
 */
?>

<!-- STEP 1: Include workflow selector script in <head> or before </body> -->
<script src="<?=asset_url('/assets/email-workflow-selector.js')?>"></script>

<!-- STEP 2: Add workflow selector container in your email send modal/form -->
<!-- Place this AFTER your email body field, BEFORE the send button -->

<div id="workflowSelectorContainer"></div>

<!-- STEP 3: Initialize the selector when modal/form opens -->
<script>
// Initialize workflow selector (call this when email compose modal opens)
function initEmailWorkflowSelector() {
    EmailWorkflowSelector.render('workflowSelectorContainer', function(workflowId) {
        console.log('Workflow selected:', workflowId);
        // You can store this in a variable or form field
    });
}

// Call on page load or when modal opens
document.addEventListener('DOMContentLoaded', initEmailWorkflowSelector);
// OR call it when opening email compose modal:
// openEmailModal(); initEmailWorkflowSelector();
</script>

<!-- STEP 4: Include workflow_id when sending email -->
<script>
// Example: Modify your existing sendEmail function
async function sendEmailToLead(emailId) {
    // Get selected workflow ID
    const workflowId = EmailWorkflowSelector.getSelectedWorkflowId();
    
    try {
        const response = await fetch('/api/ai-email-composer.php?action=send', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                email_id: emailId,
                workflow_id: workflowId // Include workflow ID
            })
        });
        
        const result = await response.json();
        
        if (result.ok) {
            if (result.workflow_scheduled) {
                alert('✅ Email sent with automated follow-up workflow!\n\nThe follow-up will trigger based on recipient behavior.');
            } else {
                alert('✅ Email sent successfully!');
            }
            
            // Clear the selector for next email
            EmailWorkflowSelector.clear();
            
            // Refresh UI, close modal, etc.
            // ... your existing code ...
        } else {
            alert('Error: ' + (result.error || 'Failed to send'));
        }
        
    } catch (error) {
        alert('Error: ' + error.message);
    }
}
</script>

<!-- ALTERNATIVE: Add as hidden form field if using traditional forms -->
<input type="hidden" id="attachedWorkflowId" name="workflow_id" value="">

<script>
// Update hidden field when workflow selected
EmailWorkflowSelector.render('workflowSelectorContainer', function(workflowId) {
    document.getElementById('attachedWorkflowId').value = workflowId || '';
});
</script>

<!-- 
COMPLETE EXAMPLE: Email Compose Modal with Workflow Selector

<div id="emailComposeModal" style="display:none;">
    <h3>Send Email to Lead</h3>
    
    <form onsubmit="return sendEmailWithWorkflow(event)">
        <label>
            Subject
            <input type="text" id="emailSubject" required>
        </label>
        
        <label>
            Message
            <textarea id="emailBody" rows="10" required></textarea>
        </label>
        
        <!-- Workflow Selector -->
        <div id="workflowSelectorContainer"></div>
        
        <button type="submit" class="btn">Send Email</button>
    </form>
</div>

<script>
function openEmailModal() {
    document.getElementById('emailComposeModal').style.display = 'block';
    EmailWorkflowSelector.render('workflowSelectorContainer');
}

async function sendEmailWithWorkflow(event) {
    event.preventDefault();
    
    // 1. Save draft first
    const draftResp = await fetch('/api/ai-email-composer.php?action=save_draft', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            lead_id: currentLeadId,
            recipient_email: leadEmail,
            recipient_name: leadName,
            subject: document.getElementById('emailSubject').value,
            body_html: document.getElementById('emailBody').value,
            is_ai_assisted: false
        })
    });
    
    const draft = await draftResp.json();
    
    if (!draft.ok) {
        alert('Error creating draft: ' + draft.error);
        return false;
    }
    
    // 2. Send with workflow attached
    const workflowId = EmailWorkflowSelector.getSelectedWorkflowId();
    
    const sendResp = await fetch('/api/ai-email-composer.php?action=send', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            email_id: draft.email_id,
            workflow_id: workflowId
        })
    });
    
    const result = await sendResp.json();
    
    if (result.ok) {
        alert(result.workflow_scheduled ? 
            '✅ Email sent with automated follow-up!' : 
            '✅ Email sent!');
        
        // Close modal and refresh
        document.getElementById('emailComposeModal').style.display = 'none';
        EmailWorkflowSelector.clear();
    }
    
    return false;
}
</script>
-->





