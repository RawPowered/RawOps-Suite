# RawOps Suite

<div align="center">

<img src="assets/brand/hero.svg" alt="RawOps Suite: CRM, sales enablement, automation and business operations" width="100%">

**From agent training to customer acquisition, fulfillment and legal operations.**

I built this platform to give a growing product business a shared place to learn, sell, serve customers and manage its work. Explore the workflows below to see how the pieces connect.

<p><a href="docs/FEATURE-WALKTHROUGH.md"><img src="assets/brand/nav-walkthrough.svg" alt="Explore workflows" height="44"></a> <a href="docs/evidence/GALLERY.md"><img src="assets/brand/nav-screenshots.svg" alt="See historical screens" height="44"></a> <a href="docs/API-INDEX.md"><img src="assets/brand/nav-source.svg" alt="Inspect the source" height="44"></a> <a href="docs/SECURITY-AND-PRIVACY.md"><img src="assets/brand/nav-security.svg" alt="Security and privacy" height="44"></a> </p>

[Feature walkthrough](docs/FEATURE-WALKTHROUGH.md) · [Historical screenshots](docs/evidence/GALLERY.md) · [Full capability catalog](docs/CAPABILITY-CATALOG.md) · [API source](docs/API-INDEX.md)

</div>

![Connected capabilities: educate, assist, acquire, fulfill, organize and measure](docs/diagrams/connected-capabilities.svg)

<h2 id="review"><img src="assets/brand/section-review.svg" alt="Start with the work you need done" width="100%"></h2>

| If you are hiring for… | Start here |
|:--|:--|
| CRM, APIs and automation | [Prospecting, personalized drafts, samples and store accounts](docs/FEATURE-WALKTHROUGH.md#2-find-shops-and-prepare-relevant-outreach) |
| AI product integration | [Catalog-aware assistance and contextual email generation](docs/FEATURE-WALKTHROUGH.md#3-put-assistance-inside-the-sales-workspace) |
| Internal tools and enterprise systems | [Legal timelines, evidence organization and controlled access](docs/FEATURE-WALKTHROUGH.md#6-organize-legal-work-for-review) |
| Learning platforms and sales enablement | [Training connected to changing product information](docs/FEATURE-WALKTHROUGH.md#1-keep-sales-training-connected-to-the-product) |
| Operations, security and analytics | [Time, progress, data quality and permission boundaries](docs/FEATURE-WALKTHROUGH.md#7-make-progress-and-operational-gaps-visible) |

<h2 id="features"><img src="assets/brand/section-features.svg" alt="What made the build useful" width="100%"></h2>

**Training kept pace with the product.** Agent education evolved as prices and ingredients changed. Catalog-backed content, pricing calculations, training examples, quizzes and sales practice helped keep product knowledge close to the work of selling.

**Sales tools worked together.** Agents could find relevant shop types, collect lead details, prepare assisted email drafts, offer free samples, follow up and create store accounts. Personalized digital business cards provided contact downloads, customer signup and sample-request entry points.

**The assistant had business context.** Chat handlers supplied product information, prices and account context. The email composer used catalog and lead activity to prepare drafts, with templates, tone controls and follow-up options.

**Legal work had a dedicated home.** I used the legal workspace to retain case timelines and evidence and organize material for lawyers. It helped reduce the extra sorting required to understand the records. Case access, team assignments, notes, document metadata and session tracking supported that workflow.

**Managers could see progress.** Training completion, active and idle time, pipeline stages, lead completeness, follow-up chains, email events and API usage provided different views of the work.

[Walk through these capabilities and their implementation →](docs/FEATURE-WALKTHROUGH.md)

<h2 id="evidence"><img src="assets/brand/section-evidence.svg" alt="See the historical application" width="100%"></h2>

<table>
<tr>
<td width="50%"><img src="docs/evidence/screenshots/crm-data-quality-2025-10-20.png" alt="Historical CRM panel measuring lead completeness and follow-up chains" width="100%"><br><strong>CRM data quality and follow-up visibility</strong></td>
<td width="50%"><img src="docs/evidence/screenshots/assisted-email-composer-2025-10-28.png" alt="Historical assisted email composer with template, tone and layout controls; identifying details covered" width="100%"><br><strong>Assisted outreach inside the workflow</strong></td>
</tr>
</table>

The [11-image gallery](docs/evidence/GALLERY.md) includes operations, training, chat, digital cards, legal access and evidence organization. These are preserved captures from the original application, with opaque redactions where needed.

<h2 id="scope"><img src="assets/brand/section-scope.svg" alt="The rest of the platform" width="100%"></h2>

| Workspace | Included capabilities |
|:--|:--|
| Product and production | Catalog, groups, ingredients, recipes, packaging, inventory, pricing tiers, work orders and production navigation |
| CRM and customer service | Leads, accounts, contacts, geographic prospecting, assignment, calls, notes, tasks and customer portals |
| Sales and fulfillment | Sample offers, request handling, shipment updates, invoices, line items, commissions and agent attribution |
| Finance | Expenses, reversals, suppliers, journals, ledgers, financial reporting and tax-document surfaces |
| Communications | Templates, assisted drafting, workflow conditions, scheduled queues, delivery callbacks and engagement records |
| Administration | Role-based workspaces, legal management, training, activity reporting and operational diagnostics |

[Complete feature inventory](docs/CAPABILITY-CATALOG.md) · [Workflow diagrams](docs/WORKFLOWS.md) · [System architecture](docs/diagrams/system-map.svg)

<h2 id="engineering"><img src="assets/brand/section-engineering.svg" alt="Inspect the engineering" width="100%"></h2>

The preserved build includes **122 API source files** and **145 SQL migration files**, with a PHP/MySQL application, JavaScript interfaces and a separate Vite/React prototype.

Review [API implementation](docs/API-INDEX.md), [integration boundaries](docs/INTEGRATIONS.md), [engineering case notes](docs/ENGINEERING-CASE-NOTES.md), and [security and privacy practices](docs/SECURITY-AND-PRIVACY.md). The case notes explain decisions around sample enrollment, fulfillment records, invoice transactions and queued communication.

<h2 id="preservation"><img src="assets/brand/section-preservation.svg" alt="Preservation and scope" width="100%"></h2>

This is preserved partial history of a previously functional business system. The screenshots show historical application states; the source preserves implementation detail. My account of the legal workflow describes firsthand use. Current service availability and deployment behavior have not been revalidated.

Company identity, product identity, customer records, private case material, credentials and runtime files are excluded. Older model endpoints and external integrations require review before reuse. See the [evidence guide](docs/BUILD-EVIDENCE.md) for the distinction between source, captures and historical use.

**Release:** [v1.0.0-showcase](https://github.com/RawPowered/RawOps-Suite/releases/tag/v1.0.0-showcase), a scrubbed historical source and portfolio snapshot. Read the [publication review](docs/PUBLICATION-REVIEW.md) and [source review guide](docs/SOURCE-REVIEW.md). No license is granted.

