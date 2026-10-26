# Reviewing the source

[Portfolio overview](../README.md) · [Feature walkthrough](FEATURE-WALKTHROUGH.md) · [API index](API-INDEX.md)

RawOps Suite preserves a PHP/MySQL business application and a separate React/Vite interface prototype. Start with the workflow relevant to your role, then follow its linked files.

## Repository map

| Path | What to inspect |
|:--|:--|
| admin/ | Administration, CRM oversight, training, accounting, legal and product management |
| onboarding/sales-agent/ | Prospecting, accounts, communication, invoices and agent work |
| onboarding/trainee/ | Courses, lessons, quizzes and sales practice |
| operations/ | Product, inventory, production and fulfillment interfaces |
| api/ | 122 route handlers, provider adapters and supporting source files |
| required/ | Shared services, context assembly, permission helpers and calculations |
| scheduled/ | Scheduled email and operational processing |
| migrations/ | 145 historical SQL files |
| agent/ and customer/ | Agent cards and customer-facing workspaces |
| v2/ | Separate React interface prototype |
| docs/evidence/ | Historical screenshots and their captions |

## Suggested review

1. Follow [product information into training](FEATURE-WALKTHROUGH.md#1-keep-sales-training-connected-to-the-product).
2. Inspect [prospecting and email drafting](FEATURE-WALKTHROUGH.md#2-find-shops-and-prepare-relevant-outreach).
3. Trace [sample enrollment and fulfillment](ENGINEERING-CASE-NOTES.md).
4. Review [legal organization and case access](FEATURE-WALKTHROUGH.md#6-organize-legal-work-for-review).
5. Compare [activity metrics](FEATURE-WALKTHROUGH.md#7-make-progress-and-operational-gaps-visible) with the dated captures.

## Runtime scope

This release is for source and portfolio review. Configuration, original databases, provider credentials, user uploads and server rules are omitted. Historical model identifiers and provider adapters may need replacement. The migration files are an implementation record, not a validated sequential installation script.

A restoration would need an isolated environment, synthetic data, a configuration contract, reviewed migrations, current provider adapters, consistent authorization and end-to-end checks. Do not use private customer or case data for that work.

Syntax checks do not execute the application, contact providers, send messages or apply migrations. No production flows were executed for this publication.

## Rights

No open-source license is granted. Review [RIGHTS.md](../RIGHTS.md) before reuse.

