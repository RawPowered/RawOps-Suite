# Build evidence

This guide points to artifacts that can be reviewed in the public archive. It separates source evidence from live-use evidence.

## Evidence included

| Evidence | What it supports | What it cannot establish |
|:--|:--|:--|
| Application pages and API endpoints | Workflows and interfaces represented by source | That every route was deployed or used after this snapshot |
| SQL migration archive | Data-model and workflow changes recorded over time | That each migration ran successfully in production |
| Dated implementation patterns | Engineering work and defect corrections represented in code | Independent verification of customer outcomes or uptime |
| Workflow diagrams and capability catalog | System organization and intended record movement | A runtime trace of the original system |
| Redacted screen captures | Historical UI surfaces and captured aggregate values shown in the evidence gallery | Current availability, usage, revenue, delivery or security status |

The migration filenames span January through November 2025 and cover CRM schema, lead attribution, sample operations, email workflows, commissions, accounting, training, legal workflow, permissions, reporting and integration support. The full set is under migrations/.

## Representative implementation evidence

### Sample-suite workflow correction

The dated change record for the sample suite describes separating email kickoff from fulfillment requests, linking enrollment through lead context, and scheduling follow-up. Related source is in api/sample-suite.php, api/sample-request-api.php, scheduled/sample-email-automation.php, and migrations/2025-11-15-sample-suite-chain.sql.

### Invoice and commission integrity

Invoice and commission code connects order line items, commission calculation and downstream finance records. The source includes transaction and error-handling paths in onboarding/sales-agent/invoice.php and required/commission-utils.php, with schema work in migrations/2025-09-22-commissions-line-model.sql.

### Email workflow processing

The code includes template management, queue processing, workflow rules, provider callbacks and analytics. Review api/email-queue-processor.php, api/email-workflow-processor.php, api/sendgrid-webhook.php and migrations/2025-11-15-email-automation-hardening.sql.

### Accounting suite

The accounting API surface is organized into expenses, invoices, journals, ledgers, suppliers, taxes and reports. Review api/accounting/ and migrations/2025-10-11-accounting-suite.sql.

For problem-solving examples, see [Engineering case notes](ENGINEERING-CASE-NOTES.md).

## Production-use statement

I built and used this system in a real operating business. The legal workspace supported my work retaining case timelines and presenting organized evidence to lawyers. This is a firsthand usage account, with no quantified time savings or legal outcome claimed. This public adaptation preserves code and dated implementation artifacts. Customer records, legal evidence images, credentials, runtime logs and company-identifying media are excluded. The public repository includes eleven historical screen captures, redacted where needed. They document visible screens and aggregate values displayed at capture time. They do not independently verify current deployments, user counts, business outcomes, email delivery or service status.

## How the evidence is preserved

The gallery now connects eleven archived captures to the system walkthrough. Use the evidence capture guide at docs/evidence/README.md. Each image should come from the real application, preserve its original context, and hide names, email addresses, phones, street addresses, customer and product identifiers, account balances, credentials and legal matter details.


