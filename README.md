# RawOps Suite

<div align="center">

<img src="assets/brand/hero.svg" alt="RawOps Suite: CRM, sales enablement, automation and business operations" width="100%">

**A business platform connecting product knowledge, sales activity, customer relationships and internal operations.**

I built RawOps Suite around the work of a product business: preparing agents, finding shops, managing outreach, fulfilling samples, serving accounts and organizing legal records. My work spans the interfaces, data model, APIs, automation and operational reporting.

<p><a href="docs/FEATURE-WALKTHROUGH.md"><img src="assets/brand/nav-walkthrough.svg" alt="Explore workflows" height="44"></a> <a href="docs/evidence/GALLERY.md"><img src="assets/brand/nav-screenshots.svg" alt="See historical screens" height="44"></a> <a href="docs/API-INDEX.md"><img src="assets/brand/nav-source.svg" alt="Inspect the source" height="44"></a> <a href="docs/SECURITY-AND-PRIVACY.md"><img src="assets/brand/nav-security.svg" alt="Security and privacy" height="44"></a></p>

</div>

**Project stage:** preserved history of a previously functional business system. The public archive combines de-identified source, genuine screenshots and firsthand usage context. Current deployment and provider connectivity have not been revalidated.

<h2 id="evidence"><img src="assets/brand/section-evidence.svg" alt="Start with the evidence" width="100%"></h2>

You can inspect the application screens, follow the workflows and read the code behind them.

| Historical record | What you can inspect |
|:--|:--|
| **29 September 2025: operating overview** | The captured dashboard displays **51 leads, 10 sales agents and 16 users in training** in its selected window. <a href="docs/evidence/GALLERY.md#operating-overview--29-september-2025"><img src="assets/brand/actions/operating-overview.svg" alt="View the original interface, redacted" height="34"></a> |
| **20 October 2025: CRM data quality** | **143 detailed leads, 241 incomplete leads and 69 email chains** appear in the capture below. It shows the system exposing incomplete records and follow-up coverage. |
| **Legal case organization** | I used the workspace to retain chronology and evidence and organize material for lawyers, reducing the additional sorting needed to review the records. <a href="docs/FEATURE-WALKTHROUGH.md#6-organize-legal-work-for-review"><img src="assets/brand/actions/legal-workflow-evidence.svg" alt="Firsthand account, interface and source" height="34"></a> |

Each snapshot reflects its own date and filter window. Revenue and conversion attribution are outside this evidence. The legal account describes my experience; no measured time or fee savings are claimed.

<table>
<tr>
<td width="50%" valign="top">
<a href="docs/evidence/GALLERY.md#crm-data-quality--20-october-2025"><img src="docs/evidence/screenshots/crm-data-quality-2025-10-20.png" alt="Historical CRM data-quality panel with lead completeness and follow-up-chain counts" width="100%"></a>
<p><strong>Make the next action visible.</strong><br>Lead completeness, missing contact fields and follow-up chains.</p>
</td>
<td width="50%" valign="top">
<a href="docs/evidence/GALLERY.md#assisted-email-composition--28-october-2025"><img src="docs/evidence/screenshots/assisted-email-composer-2025-10-28.png" alt="Historical assisted email composer showing templates, tone and layout controls with identifying details covered" width="100%"></a>
<p><strong>Put assistance where agents work.</strong><br>Templates, assisted writing, tone and presentation controls.</p>
</td>
</tr>
</table>

<a href="docs/evidence/GALLERY.md"><img src="assets/brand/actions/explore-all-11-captures.svg" alt="Browse all 11 captures: training, chat, digital cards, legal, operations and website audit →" height="38"></a>

<h2 id="features"><img src="assets/brand/section-features.svg" alt="Follow the connected workflows" width="100%"></h2>

**One sales journey:** product-aware training → shop discovery → lead record → reviewed email draft → sample offer → fulfillment → follow-up → store account and continuing service.

Each step has its own records, statuses and user actions. Sample outreach does not automatically create a fulfillment job or a customer account.

| Capability | What I built | Inspect the evidence |
|:--|:--|:--|
| **Product-aware training** | Catalog-backed prices, product details and calculations; synchronization tools; lessons, quizzes and sales practice. Education evolved with price and ingredient changes. | <a href="docs/evidence/GALLERY.md#training-progression--14-september-2025"><img src="assets/brand/actions/training-capture.svg" alt="Training screen" height="34"></a><br><a href="required/training-auto-sync.php"><img src="assets/brand/actions/source-training-sync.svg" alt="Synchronization service" height="34"></a><br><a href="required/shortcode-engine.php"><img src="assets/brand/actions/source-dynamic-lessons.svg" alt="Dynamic lesson content" height="34"></a> |
| **Shop discovery and CRM** | Category/location searches, cached place details, lead records, assignment, notes, calls, tasks and follow-up context. | <a href="docs/evidence/GALLERY.md#crm-data-quality--20-october-2025"><img src="assets/brand/actions/crm-capture.svg" alt="CRM capture" height="34"></a><br><a href="api/google-places-proxy.php"><img src="assets/brand/actions/source-places-adapter.svg" alt="Places adapter" height="34"></a><br><a href="onboarding/sales-agent/leads.php"><img src="assets/brand/actions/source-agent-workspace.svg" alt="Agent workspace" height="34"></a> |
| **AI chat and email drafting** | Product and account context for chat; catalog and lead activity for drafts; templates, saved drafts and behavior-aware follow-ups. | <a href="docs/evidence/GALLERY.md#sales-assistant--24-october-2025"><img src="assets/brand/actions/assistant-capture.svg" alt="Assistant interface" height="34"></a><br><a href="api/ai-chat-streamlined.php"><img src="assets/brand/actions/source-chat-context.svg" alt="Chat context" height="34"></a><br><a href="api/ai-email-composer.php"><img src="assets/brand/actions/source-email-composer.svg" alt="Email composer" height="34"></a> |
| **Samples and customer accounts** | Free-sample offers, enrollment, request handling, fulfillment, scheduled communication and store-account workflows. | <a href="docs/FEATURE-WALKTHROUGH.md#4-carry-a-free-sample-through-the-customer-journey"><img src="assets/brand/actions/sample-walkthrough.svg" alt="Workflow explanation" height="34"></a><br><a href="api/sample-suite.php"><img src="assets/brand/actions/source-sample-suite.svg" alt="Sample suite" height="34"></a><br><a href="onboarding/sales-agent/accounts.php"><img src="assets/brand/actions/source-store-accounts.svg" alt="Store accounts" height="34"></a> |
| **Agent digital business cards** | Personalized cards, contact downloads, QR/vCard support, customer signup and sample links carrying agent context. | <a href="docs/evidence/GALLERY.md#agent-digital-business-card--24-october-2025"><img src="assets/brand/actions/agent-card-capture.svg" alt="Historical card" height="34"></a><br><a href="agent/card.php"><img src="assets/brand/actions/source-digital-card.svg" alt="Public card implementation" height="34"></a> |
| **Legal case workspace** | Case timelines, evidence metadata, documents, notes, parties, team assignments, access requests and session activity. | <a href="docs/evidence/GALLERY.md#legal-evidence-timeline--6-october-2025"><img src="assets/brand/actions/legal-timeline-capture.svg" alt="Redacted timeline" height="34"></a><br><a href="required/legal-case-service.php"><img src="assets/brand/actions/source-case-service.svg" alt="Case service" height="34"></a><br><a href="api/legal/update-evidence-metadata.php"><img src="assets/brand/actions/source-evidence-metadata.svg" alt="Metadata handler" height="34"></a> |
| **Time, progress and metrics** | Training completion, active/idle time, long-session alerts, pipeline visibility, lead quality, email events and API usage. | <a href="docs/FEATURE-WALKTHROUGH.md#7-make-progress-and-operational-gaps-visible"><img src="assets/brand/actions/metrics-walkthrough.svg" alt="Measurement walkthrough" height="34"></a><br><a href="api/training-heartbeat.php"><img src="assets/brand/actions/source-training-heartbeat.svg" alt="Training heartbeat" height="34"></a><br><a href="api/admin-usage-analytics.php"><img src="assets/brand/actions/source-usage-reporting.svg" alt="Usage reporting" height="34"></a> |

The walkthrough distinguishes captured interface behavior from source-backed capabilities. The assistant screenshot shows its welcome state; the linked handlers show context assembly and model integration.

<h2 id="product-learning"><img src="assets/brand/section-product-learning.svg" alt="Design case: Product Formulator, LMS, training and marketing" width="100%"></h2>

**For design reviewers: follow the product knowledge.** Product formulation and catalog tools define the operational detail; LMS authoring, lessons and practice help agents understand it; email templates and assisted drafts bring relevant information into customer conversations.

<a href="docs/PRODUCT-LEARNING-DESIGN.md"><img src="docs/diagrams/product-learning-design.svg" alt="Product Formulator and catalog relationships, shared lesson and email rendering, training and marketing, with editorial review boundaries" width="100%"></a>

The case explains shared values, authored content, interface choices and review of assisted output. It includes genuine learning and composer captures, source references and an interview walkthrough relevant to AI Tutor, Design roles. Recipe-to-catalog publication remains an editorial handoff in this diagram; automatic propagation across every edit path is unverified.

<a href="docs/PRODUCT-LEARNING-DESIGN.md"><img src="assets/brand/actions/product-learning-design.svg" alt="Explore product and learning design" height="38"></a>

<h2 id="scope"><img src="assets/brand/section-scope.svg" alt="Explore the full platform" width="100%"></h2>

<a href="docs/diagrams/systems-orchestration.png"><img src="docs/diagrams/systems-orchestration.svg" alt="RawOps Suite systems orchestration: role workspaces, business workflows, shared services, MySQL records, external providers and operational visibility" width="100%"></a>

<a href="docs/diagrams/systems-orchestration.png"><img src="assets/brand/actions/full-resolution-diagram.svg" alt="Open full-resolution diagram" height="38"></a> <a href="docs/SYSTEMS-ORCHESTRATION.md"><img src="assets/brand/actions/architecture-walkthrough.svg" alt="Read the architecture and source references" height="38"></a>

<details>
<summary><strong>Expand the wider feature inventory</strong></summary>

| Workspace | Additional depth | Review |
|:--|:--|:--|
| Product and production | Product groups, ingredients, recipes, packaging, stock, pricing tiers and work-order interfaces | <a href="docs/CAPABILITY-CATALOG.md#product-catalog-and-operations"><img src="assets/brand/actions/product-operations.svg" alt="Capability catalog" height="34"></a><br><a href="docs/evidence/GALLERY.md#operations-workspace--26-october-2025"><img src="assets/brand/actions/operations-capture.svg" alt="Operations capture" height="34"></a> |
| Orders and commissions | Invoices, persisted line items, commission tiers, agent books and financial summaries | <a href="docs/CAPABILITY-CATALOG.md#orders-invoices-and-commissions"><img src="assets/brand/actions/orders-and-commissions.svg" alt="Order and commission scope" height="34"></a> |
| Accounting and suppliers | Expenses, reversals, journals, ledgers, suppliers, tax records and financial-reporting surfaces | <a href="docs/CAPABILITY-CATALOG.md#accounting-and-supplier-operations"><img src="assets/brand/actions/accounting-and-suppliers.svg" alt="Accounting scope" height="34"></a> |
| Communication automation | Reusable templates, conditions, delays, queued work, provider callbacks and engagement records | <a href="docs/CAPABILITY-CATALOG.md#email-and-workflow-automation"><img src="assets/brand/actions/email-automation.svg" alt="Communication scope" height="34"></a> |
| Customer and team portals | Registration, verification, reset flows, account/request history and role-specific workspaces | <a href="docs/CAPABILITY-CATALOG.md#customer-and-team-portals"><img src="assets/brand/actions/customer-and-team-portals.svg" alt="Portal scope" height="34"></a> |
| Web experience | Shared navigation, responsive layouts, metadata, structured data and a dated Lighthouse capture | <a href="docs/CAPABILITY-CATALOG.md#website-presentation-responsive-layouts-and-technical-seo"><img src="assets/brand/actions/website-and-audit.svg" alt="Website scope and audit" height="34"></a> |

</details>

<a href="docs/CAPABILITY-CATALOG.md"><img src="assets/brand/actions/full-capability-catalog.svg" alt="Full capability catalog" height="38"></a> <a href="docs/WORKFLOWS.md"><img src="assets/brand/actions/workflow-diagrams.svg" alt="Workflow diagrams" height="38"></a> <a href="docs/diagrams/system-map.svg"><img src="assets/brand/actions/system-architecture.svg" alt="System architecture" height="38"></a> <a href="docs/INTEGRATIONS.md"><img src="assets/brand/actions/integration-map.svg" alt="Integration map" height="38"></a>

<h2 id="engineering"><img src="assets/brand/section-engineering.svg" alt="Review the engineering decisions" width="100%"></h2>

The implementation is available for review: **437 PHP files, 33 standalone JavaScript files, 122 API source files and 145 SQL migration files**. These overlapping inventory counts describe the archive's scope.

| Problem addressed | Design represented in the code | Go deeper |
|:--|:--|:--|
| Outreach appeared as fulfillment work before a request existed | Separate lead-linked enrollment from physical sample requests; retain follow-up context | <a href="docs/ENGINEERING-CASE-NOTES.md#1-keep-sample-outreach-separate-from-fulfillment-work"><img src="assets/brand/actions/sample-handoff-design.svg" alt="Sample workflow correction" height="34"></a> |
| Invoice, commission and finance records needed consistent references | Validate persisted identifiers, coordinate transaction ownership and notify after commit | <a href="docs/ENGINEERING-CASE-NOTES.md#2-protect-the-invoice-and-commission-handoff"><img src="assets/brand/actions/invoice-integrity.svg" alt="Invoice handoff" height="34"></a> |
| Automated communication needed a traceable lifecycle | Separate templates, conditions, queue processing, provider callbacks and reporting | <a href="docs/ENGINEERING-CASE-NOTES.md#3-give-email-automation-an-operational-feedback-loop"><img src="assets/brand/actions/email-processing-design.svg" alt="Email processing design" height="34"></a> |

**Security review:** roles, case membership, session identity and data boundaries are documented. The archive also documents historical gaps, including broader access in the evidence-locker route. Restoration requires consistent case-level authorization and a full deployment review.

<a href="docs/SECURITY-AND-PRIVACY.md"><img src="assets/brand/actions/security-and-privacy.svg" alt="Security and privacy" height="38"></a>

**Publication checks:** All included PHP and standalone JavaScript files passed syntax inspection; Gitleaks reported no detected secrets. These checks do not execute the business workflows.

<a href="docs/PUBLICATION-REVIEW.md"><img src="assets/brand/actions/publication-checks.svg" alt="Review the checks and scope" height="38"></a>

<h2 id="review"><img src="assets/brand/section-review.svg" alt="Choose the review closest to your opening" width="100%"></h2>

| Your opening | A useful place to start |
|:--|:--|
| **CRM, business systems or support operations** | <a href="docs/FEATURE-WALKTHROUGH.md#2-find-shops-and-prepare-relevant-outreach"><img src="assets/brand/actions/crm-delivery.svg" alt="Shop discovery through customer follow-up" height="34"></a><br><a href="docs/WORKFLOWS.md"><img src="assets/brand/actions/workflow-handoffs.svg" alt="workflow handoffs" height="34"></a> |
| **AI Tutor, Design or learning experience** | <a href="docs/PRODUCT-LEARNING-DESIGN.md"><img src="assets/brand/actions/product-learning-design.svg" alt="Product formulation, learning and marketing design case" height="34"></a> |
| **AI product or API integration** | <a href="docs/FEATURE-WALKTHROUGH.md#3-put-assistance-inside-the-sales-workspace"><img src="assets/brand/actions/contextual-ai.svg" alt="Contextual assistance" height="34"></a><br><a href="api/ai-email-composer.php"><img src="assets/brand/actions/source-email-composer.svg" alt="composer implementation" height="34"></a> |
| **Learning platforms or sales enablement** | <a href="docs/FEATURE-WALKTHROUGH.md#1-keep-sales-training-connected-to-the-product"><img src="assets/brand/actions/learning-and-enablement.svg" alt="Product-aware training" height="34"></a><br><a href="api/training-heartbeat.php"><img src="assets/brand/actions/source-training-heartbeat.svg" alt="progress instrumentation" height="34"></a> |
| **Internal tools, operations or technical product delivery** | <a href="docs/FEATURE-WALKTHROUGH.md#6-organize-legal-work-for-review"><img src="assets/brand/actions/legal-operations.svg" alt="Legal organization" height="34"></a><br><a href="docs/FEATURE-WALKTHROUGH.md#7-make-progress-and-operational-gaps-visible"><img src="assets/brand/actions/operational-metrics.svg" alt="operational metrics" height="34"></a><br><a href="docs/ENGINEERING-CASE-NOTES.md"><img src="assets/brand/actions/engineering-decisions.svg" alt="engineering decisions" height="34"></a> |

**In an interview, ask me to trace one record through the system.** I can explain the business need, interface, data relationships, automation, failure handling and the evidence preserved here. Useful starting points are a sample enrollment, a product change feeding training, or a case timeline prepared for review.

<a href="https://www.linkedin.com/in/josephedwardscto/"><img src="assets/brand/actions/connect-on-linkedin.svg" alt="Joseph Edwards on LinkedIn" height="38"></a> <a href="https://github.com/RawPowered"><img src="assets/brand/actions/rawpowered-portfolio.svg" alt="Main portfolio" height="38"></a>

<h2 id="preservation"><img src="assets/brand/section-preservation.svg" alt="Preservation and release scope" width="100%"></h2>

Company/product identity, customer records, private legal content, credentials and runtime configuration are excluded. Screenshots retain historical UI states with opaque redactions. Original evidence files remain private.

The code is a historical source archive. Older provider/model endpoints, migrations and security boundaries require review before restoration.

<a href="docs/SOURCE-REVIEW.md"><img src="assets/brand/actions/source-review-guide.svg" alt="Source review guide" height="38"></a> <a href="docs/BUILD-EVIDENCE.md"><img src="assets/brand/actions/evidence-methodology.svg" alt="Evidence methodology" height="38"></a>

The main branch contains the current portfolio presentation.

<a href="https://github.com/RawPowered/RawOps-Suite/releases/tag/v1.0.1-showcase"><img src="assets/brand/actions/source-release-v1-0-1.svg" alt="v1.0.1-showcase" height="38"></a> <a href="RIGHTS.md"><img src="assets/brand/actions/rights-and-reuse.svg" alt="Rights and reuse" height="38"></a>
