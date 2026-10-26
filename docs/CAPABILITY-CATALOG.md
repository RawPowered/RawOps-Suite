# Capability catalog

Start with the [connected feature walkthrough](FEATURE-WALKTHROUGH.md) for practical examples, historical use and screenshots. This catalog covers the wider feature inventory.

This catalog describes capabilities represented in the source snapshot. Source references point to reviewable implementation artifacts. A file's presence establishes that code exists; it does not establish current production status, successful deployment, or usage volume.

## Product catalog and operations

- Maintain catalog entries, product groups, categories, attributes, packaging, price tiers and inventory.
- Review group costs, ingredient or recipe records, fulfillment details and related operational data.
- Support inventory updates and product-to-training synchronization.
- Publish product data through an external catalog adapter.

Representative source: [product groups](../admin/product-groups.php), [group editor](../admin/product-group-editor.php), [ingredient records](../operations/product-group-ingredients.php), [recipe editor](../operations/product-group-recipe-editor.php), [group pricing API](../api/get-product-group-tiers.php), [inventory API](../api/quick-add-inventory.php), [publishing adapter](../api/printify-admin-api.php).

## CRM and sales pipeline

- Work with accounts, contacts and leads across role-specific sales pages.
- Capture lead details, social profile links, tasks, notes, calls and follow-up activity.
- Assign and tag leads, move records through status boards, and preserve geographic context for routing and service planning.
- Archive or cascade related lead data where the workflow requires it.
- Track agent activity and selected site interactions.

Representative source: [sales leads](../onboarding/sales-agent/leads.php), [accounts](../onboarding/sales-agent/accounts.php), [contacts](../onboarding/sales-agent/contacts.php), [lead status board API](../api/sacp1015-kanban-data.php), [lead tagging API](../api/sacp1015-auto-tag.php), [social links API](../api/lead-social-links-api.php), [Places adapter](../api/google-places-proxy.php), [CRM schema](../migrations/2025-09-11-crm-system-schema.sql), [lead attribution](../migrations/2025-10-01-crm-lead-attribution.sql).

## Customer requests, sample suite and fulfillment

- Accept and review sample or product requests.
- Link requests to lead or customer context and preserve request status through later handoffs.
- Support sample-suite enrollment, email kickoff and scheduled follow-up, shipment notices and feedback collection.
- Route accepted requests into operations for fulfillment and shipment updates.
- Provide sales-agent pages for request entry and request status review.

Representative source: [sample request API](../api/sample-request-api.php), [sample suite API](../api/sample-suite.php), [fulfillment workspace](../operations/sample-fulfillment.php), [scheduled sample email](../scheduled/sample-email-automation.php), [agent request page](../onboarding/sales-agent/request-sample.php), [sample-suite chain migration](../migrations/2025-11-15-sample-suite-chain.sql), [kickoff refresh migration](../migrations/2025-11-17-sample-suite-kickoff-refresh.sql).

## Orders, invoices and commissions

- Create and manage invoices and line items in the sales-agent workspace.
- Calculate commissions from order and line-item context using configured commission tiers.
- Connect invoice and commission events to notifications and finance records.
- Maintain agent books, business cards, QR and vCard outputs, financial summaries and tax-document views.
- Review payment-method records and payment status through application pages. Payment-provider authorization and live settlement are unverified in this snapshot.

Representative source: [invoice creation](../onboarding/sales-agent/invoice.php), [invoice list](../onboarding/sales-agent/invoices.php), [commission utilities](../required/commission-utils.php), [agent books API](../api/agent-books-api.php), [business-card API](../api/business-card-api.php), [QR generator](../api/business-card-qr-generate.php), [vCard output](../api/business-card-vcard.php), [commission line model](../migrations/2025-09-22-commissions-line-model.sql).

## Accounting and supplier operations

- Work with expenses, expense reversals, journals, ledgers, invoices, suppliers, tax records and financial reports.
- Use matching helpers and unified endpoints to connect operational events with accounting records.
- Preserve relationship and transaction boundaries across order, commission and journal workflows.

Representative source: [accounting APIs](../api/accounting/), [accounting workspace](../admin/accounting/), [accounting suite migration](../migrations/2025-10-11-accounting-suite.sql), [supplier management migration](../migrations/2025-10-16-supplier-management-system.sql).

## Customer and team portals

- Provide registration, login, profile, order-status and request-history surfaces.
- Support email verification, password reset and consent preference workflows.
- Give sales agents role-specific pages for leads, accounts, invoices, finances, books, tasks, calls and follow-up.
- Guide trainees through learning modules, quizzes, assessments, progress tracking and structured sales practice.

Representative source: [customer portal](../customer/), [authentication APIs](../api/auth/), [user APIs](../api/user/), [sales-agent workspace](../onboarding/sales-agent/), [trainee onboarding](../onboarding/trainee/), [quiz submission](../api/training-quiz-submit.php), [training session API](../api/training-save-session.php), [walkthrough API](../api/walkthrough-api.php).

## Email and workflow automation

- Maintain customer, team and workflow email templates.
- Compose messages with assisted drafting and preserve contextual template variables.
- Build conditional workflow rules, enqueue messages, process scheduled steps, track delivery events and receive provider webhooks.
- Include inactive-user follow-up, verification, password reset, onboarding, sample follow-up, invoice and feedback paths.
- Record engagement events for workflow review.

Representative source: [assisted email composer](../api/ai-email-composer.php), [workflow API](../api/email-workflow-api.php), [workflow builder API](../api/email-workflow-builder-api.php), [workflow processor](../api/email-workflow-processor.php), [queue processor](../api/email-queue-processor.php), [SendGrid webhook](../api/sendgrid-webhook.php), [email analytics](../api/email/analytics.php), [scheduled jobs](../scheduled/), [workflow schema](../migrations/2025-10-11-email-workflow-system.sql).

## Training and enablement

- Organize material into modules, quizzes and assessments.
- Track completion, activity time and walkthrough progress.
- Provide sales workout and coaching flows alongside product and process training.
- Render catalog-backed prices, descriptions, comparisons and calculation examples through shortcodes.
- Synchronize enabled products into training examples and pricing tiers, and flag missing module references for maintenance.

Representative source: [trainee workspace](../onboarding/trainee/), [learning modules](../onboarding/trainee/modules/), [sales workouts](../onboarding/trainee/sales-workouts.php), [workout API](../api/sales-workouts-api.php), [time tracker](../api/training-time-tracker.js), [multi-course schema](../migrations/2025-10-13-multi-course-architecture.sql).

## Legal and access workflows

- Maintain legal case records, parties, notes, team assignments, document metadata and access requests.
- Organize evidence using timeline dates, horizontal/vertical timeline views and sorting controls.
- Support lawyer review of organized chronology and records. The feature walkthrough includes my firsthand usage account.
- Record case-session activity and selected evidence metadata through permission-controlled pages and endpoints.
- Provide investor agreement and NDA acceptance flows.

Representative source: [legal admin pages](../admin/legal/), [legal APIs](../api/legal/), [NDA acceptance](../api/investor/accept-nda.php), [legal management schema](../migrations/2025-10-03-legal-management.sql), [agreements schema](../migrations/2025-10-15-comprehensive-agreements-system.sql).

The public archive excludes legal evidence images and uploaded records. The code surfaces are included for architecture review, not as examples of legal data.

## Analytics and operations

- Track site activity, selected sales-agent page activity, email events and training progress.
- Distinguish active and idle time in training and legal sessions; surface long training sessions for review.
- Review lead-detail completeness, missing contact fields, follow-up chains and provider API usage.
- Provide real-time admin views, usage summaries and error-tracking components.
- Maintain migration and deployment support code in the source tree.

Representative source: [real-time admin API](../api/admin-realtime.php), [usage analytics API](../api/admin-usage-analytics.php), [site activity API](../api/site-activity.php), [agent page tracking](../api/sales-agent-page-tracking.php), [error tracker](../api/error-tracker.php), [real-time analytics schema](../migrations/2025-09-23-realtime-analytics.sql).

## AI-assisted features

- Provide AI-chat and learning interfaces with product and account context.
- Assemble catalog prices, descriptions, lead activity and active promotions for assisted email drafting; save drafts and generate behavior-aware follow-ups.
- Assist with email composition and selected analytics views.
- Keep AI-assisted outputs inside existing user, communication and workflow surfaces.

Representative source: [AI chat](../api/ai-chat.php), [AI chat v2](../api/ai-chat-v2.php), [AI email composer](../api/ai-email-composer.php), [AI learning](../api/ai-learning.php), [chat log schema](../migrations/2025-09-10-create-ai-chat-logs.sql).

This catalog makes no claim about model quality, autonomous decision-making, or current provider availability.

## Website presentation, responsive layouts and technical SEO

- Build public landing, catalog and account-facing pages with shared navigation, forms and interface components.
- Use CSS viewport breakpoints to adapt page sections and grids across screen sizes.
- Set page metadata and structured data in the public entry page and maintain a sitemap artifact.
- Preserve a dated Lighthouse capture in the [evidence gallery](evidence/GALLERY.md): Performance 81, Accessibility 96, Best Practices 96 and SEO 100 on 31 October 2025. The capture is one historical page audit, not a current or sitewide guarantee.

Representative source: [public entry page](../index.php), [shop](../shop/index.php), [site styles](../style.css), [client script](../script.js), [portfolio sitemap](../sitemap.xml). The sitemap uses the reserved example host and lists only the public root page.

## Build maturity labels

- **Code present:** source files and related schema artifacts are included.
- **Integration code present:** application-side adapter, event handler or webhook is included; credentials and provider-side configuration are external.
- **Historical change record:** migration and/or implementation notes document the build work; execution in a current environment is unverified.
- **Runtime unverified:** current deployment, data, scheduled execution and external service health are not included in this public snapshot.


