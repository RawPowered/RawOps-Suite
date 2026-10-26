# Systems orchestration

[Portfolio overview](../README.md) · [Full-resolution PNG](diagrams/systems-orchestration.png) · [Editable SVG](diagrams/systems-orchestration.svg)

![RawOps Suite systems orchestration](diagrams/systems-orchestration.svg)

## Read the diagram

Read from top to bottom: people enter role-specific workspaces, act on business records and use shared application services. Handlers, helpers, scheduled processors and provider adapters coordinate the work.

The connecting lines group related capabilities. The source is a PHP/MySQL application; this illustration does not claim a separate message broker, microservice deployment or universal workflow engine. Grey lines indicate shared capability groupings, lime arrows indicate logical work/data paths, and the dashed panel identifies external providers.

## Follow the implementation

| Diagram area | Representative source |
|:--|:--|
| Role-specific workspaces | [Agent pages](../onboarding/sales-agent/), [customer pages](../customer/), [learning](../onboarding/trainee/), [operations](../operations/), [legal](../admin/legal/) |
| Product-aware learning | [Training helpers](../required/training-helpers.php), [synchronization](../required/training-auto-sync.php), [shortcodes](../required/shortcode-engine.php) |
| Shared record context | [Chat context](../api/ai-chat-streamlined.php), [email composer](../api/ai-email-composer.php), [invoice handling](../onboarding/sales-agent/invoice.php) |
| Scheduled communication | [Workflow processor](../api/email-workflow-processor.php), [queue processor](../api/email-queue-processor.php), [sample follow-up](../scheduled/sample-email-automation.php) |
| Provider requests and callbacks | [Places adapter](../api/google-places-proxy.php), [SendGrid webhook](../api/sendgrid-webhook.php), [Printify adapter](../api/printify-admin-api.php), [shipping rates](../api/shipping-rate-api.php) |
| Relational persistence | [Historical SQL migration archive](../migrations/), [case services](../required/legal-case-service.php), [commission helpers](../required/commission-utils.php) |
| Access and activity | [Legal permission helper](../required/legal-permissions.php), [training heartbeat](../api/training-heartbeat.php), [legal sessions](../required/legal-session.php), [usage analytics](../api/admin-usage-analytics.php) |

## Interpretation and scope

Provider requests and responses include distinct outbound calls and inbound callbacks; their timing and behavior depend on each integration. Database lines summarize persistence responsibilities rather than every query path.

The controls row identifies implemented checks and instrumentation. The historical evidence-locker route has broader role-based access than the case-membership helper, so consistent enforcement remains a restoration requirement. See [security and privacy](SECURITY-AND-PRIVACY.md).

This is a source-derived illustration. The [historical screenshot gallery](evidence/GALLERY.md) provides the separate visual record of application use.

