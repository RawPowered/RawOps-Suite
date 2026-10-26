# Workflow maps

These maps are derived from application pages, API handlers and migration artifacts in this repository. They show intended record handoffs represented by the source. They are not runtime traces.

## Request to fulfillment

```mermaid
flowchart LR
    A[Catalog and availability] --> B[Lead or customer request]
    B --> C[Review and qualification]
    C --> D[Enrollment or request record]
    D --> E[Operations fulfillment]
    E --> F[Shipment or status update]
    F --> G[Follow-up and feedback]
    D --> H[Scheduled email workflow]
    H --> G
    G --> I[Lead and request history]
```

Source references: api/sample-request-api.php, api/sample-suite.php, operations/sample-fulfillment.php, scheduled/sample-email-automation.php, onboarding/sales-agent/my-sample-requests.php.

## Lead to order to finance

```mermaid
flowchart LR
    A[Lead intake] --> B[Assignment, tags and status]
    B --> C[Calls, tasks, notes and follow-up]
    C --> D[Account and contact context]
    D --> E[Order and invoice]
    E --> F[Line items]
    F --> G[Commission calculation]
    E --> H[Customer notification]
    G --> I[Journal and ledger records]
    I --> J[Accounting reports]
```

Source references: onboarding/sales-agent/leads.php, api/sacp1015-kanban-data.php, onboarding/sales-agent/invoice.php, required/commission-utils.php, api/accounting/.

## Email automation lifecycle

```mermaid
flowchart LR
    A[Business event] --> B[Template and context]
    B --> C[Workflow conditions and delay]
    C --> D[Queue]
    D --> E[Provider adapter]
    E --> F[Delivery webhook]
    F --> G[Engagement events]
    G --> H[Workflow reporting]
```

Source references: api/email-workflow-api.php, api/email-workflow-builder-api.php, api/email-workflow-processor.php, api/email-queue-processor.php, api/sendgrid-webhook.php, api/email/analytics.php.

## Training and onboarding

```mermaid
flowchart LR
    A[Account and role] --> B[Onboarding steps]
    B --> C[Learning module]
    C --> D[Quiz or assessment]
    D --> E[Progress record]
    E --> F[Sales practice]
    F --> G[Manager review]
```

Source references: onboarding/trainee/, api/training-quiz-submit.php, api/training-save-session.php, api/walkthrough-api.php, api/sales-workouts-api.php.

## Handoff design

Shared record identifiers and explicit status changes connect these workflows. The source includes transaction handling around order and commission work, queue-based email processing, and role-oriented pages. The migration archive shows schema changes made to support these handoffs. Actual data movement in a live deployment is not visible in this repository.

## Product information to agent readiness

```mermaid
flowchart TD
    A[Product prices and specifications] --> B[Catalog-backed helpers and shortcodes]
    A --> C[Training synchronization service]
    B --> D[Lesson examples and calculations]
    C --> D
    C --> E[Reference and calculation checks]
    E --> F[Manual follow-up when integration is missing]
    D --> G[Quizzes and sales practice]
    G --> H[Progress and session records]
```

The source supports dynamic fields and synchronization tools. Authored content and missing references still need maintenance; this diagram does not imply every catalog edit triggers a complete curriculum rewrite.

Source: [shortcodes](../required/shortcode-engine.php), [training sync](../required/training-auto-sync.php), [heartbeat](../api/training-heartbeat.php).

## Prospecting to a continuing store relationship

```mermaid
flowchart TD
    A[Shop category and location search] --> B[Lead record and assignment]
    B --> C[Contextual email draft]
    C --> D[Agent review and outreach]
    D --> E[Sample offer and follow-up enrollment]
    E --> F[Accepted sample request]
    F --> G[Fulfillment and shipment updates]
    G --> H[Follow-up and customer conversation]
    H --> I[Store account and contacts]
    I --> J[Orders and relationship history]
    K[Agent digital card] --> F
    K --> I
    B --> L[Calls, notes and tasks]
    L --> H
```

This is a business journey supported by separate application workflows. Account creation and conversion are deliberate actions; the diagram does not imply that a sample automatically creates a customer or sale. Email-only enrollment is kept separate from fulfillment requests.

Source: [Places adapter](../api/google-places-proxy.php), [composer](../api/ai-email-composer.php), [sample suite](../api/sample-suite.php), [card](../agent/card.php), [accounts](../onboarding/sales-agent/accounts.php).

## Case organization and review access

```mermaid
flowchart TD
    A[Case record and team assignment] --> B[Notes, documents and evidence metadata]
    B --> C[Timeline dates and organized evidence view]
    D[External access request] --> E[Administrator review]
    E --> F[Legal role and case membership]
    F --> C
    C --> G[Lawyer review and case communication]
    G --> H[Further notes and organized follow-up]
    C --> I[Session activity records]
```

The legal workspace was used to organize case material for lawyers. The public captures conceal the matter itself. Access helpers and metadata code support review of the design; private evidence and attorney communications remain excluded.

Source: [permissions](../required/legal-permissions.php), [metadata](../api/legal/update-evidence-metadata.php), [access actions](../api/legal/access-request-action.php), [session tracking](../required/legal-session.php).

## Reading these maps

Arrows describe supported handoffs and intended relationships. They do not measure execution, timing or conversion. The [feature walkthrough](FEATURE-WALKTHROUGH.md) pairs each journey with source and historical screen evidence.

