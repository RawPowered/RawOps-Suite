# Engineering case notes

These examples summarize problem-solving records found in the local source package and connect them to the de-identified code preserved here. Original operational names, addresses and contact data have been omitted. Dates refer to historical migration or implementation artifacts and do not independently establish production deployment.

## 1. Keep sample outreach separate from fulfillment work

**Problem recorded:** A sample-suite kickoff flow could create placeholder request records before a customer had made a fulfillment request. Those records appeared in operational views as if they were real work.

**Design correction recorded:** Start the outreach as an enrollment tied to lead context. Create a fulfillment request only after the user takes that step. Keep follow-up timing and messages connected to the enrollment.

**Source to review:** api/sample-suite.php, api/sample-request-api.php, scheduled/sample-email-automation.php, migrations/2025-11-15-sample-suite-chain.sql, migrations/2025-11-17-sample-suite-kickoff-refresh.sql.

**Why it matters:** Separating prospect engagement from operations work improves queue quality and gives teams a clearer handoff.

## 2. Protect the invoice and commission handoff

**Problem recorded:** A commission calculation depends on persisted order and line-item identifiers. Silent insertion failures or nested transaction starts can leave partially written finance records or interrupt the invoice flow.

**Design correction recorded:** Capture and validate inserted identifiers, surface failures, coordinate transaction ownership across accounting helpers, and send notifications after the order transaction commits.

**Source to review:** onboarding/sales-agent/invoice.php, required/commission-utils.php, migrations/2025-09-22-commissions-line-model.sql, migrations/2025-10-15-invoice-commission-ai-restructure.sql.

**Why it matters:** Order, line items, commissions and accounting entries need consistent references and clear failure behavior.

## 3. Give email automation an operational feedback loop

**Problem recorded:** Template and sender inconsistencies, duplicate headers, retries and delivery signals can make automated outreach difficult to manage.

**Design represented:** Reusable templates, contextual variables, workflow conditions, queued processing, provider callbacks, engagement events and analytics are separate parts of the communication path.

**Source to review:** api/email-workflow-builder-api.php, api/email-workflow-processor.php, api/email-queue-processor.php, api/sendgrid-webhook.php, api/email/analytics.php, migrations/2025-10-11-email-workflow-system.sql, migrations/2025-11-15-email-automation-hardening.sql.

**Why it matters:** An automated message is a workflow with state and observability, not a single send call.

## 4. Connect CRM context to the next action

**Problem represented:** Lead details, assignment, geographic context, sales activity and follow-up should remain connected as ownership changes.

**Design represented:** Lead boards, tag and assignment handlers, account and contact pages, social-link storage, tasks and activity tracking form a connected work surface.

**Source to review:** onboarding/sales-agent/leads.php, onboarding/sales-agent/accounts.php, api/sacp1015-kanban-data.php, api/sacp1015-assign-tag.php, api/lead-social-links-api.php, migrations/2025-10-01-crm-lead-attribution.sql.

## Evidence note

The source bundle included dated implementation notes for several of these corrections. This public summary retains the technical issue, the design response and the corresponding code paths. Original notes and runtime records were not copied because they contain identifying operational context. The public repository includes no production telemetry or customer record exports.

