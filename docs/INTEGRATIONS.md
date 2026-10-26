# Integration map

The repository contains application-side integration code. Credentials, provider-side configuration, external accounts and current service availability are outside this public archive.

| Service or system | Source evidence | Integration role | Status in this release |
|:--|:--|:--|:--|
| SendGrid | api/sendgrid.php, api/sendgrid-webhook.php, api/email-queue-processor.php | Outbound email, delivery callbacks and workflow events | Adapter source present; credentials and live delivery unverified |
| Google OAuth, email and calendar | migrations/2025-09-25-google-oauth-email-calendar.sql, required/google_mailer.php | Account connection and communication/calendar support | Schema and adapter references present; provider setup unverified |
| Google Places | api/google-places-proxy.php, migrations/2025-09-19-crm-places-cache.sql | Geographic context and cached place lookups | Proxy and schema present; credentials and live API status unverified |
| Printify | api/printify-admin-api.php, api/printify-webhook.php, api/printify-webhook-processor.php | Product publishing and provider events | Adapter and webhook source present; external account unverified |
| Shipping quotes | api/shipping-rate-api.php | Quote lookup for request and fulfillment workflows | Adapter source present; carrier configuration unverified |
| AI-assisted workflows | api/ai-chat.php, api/ai-email-composer.php, api/ai-learning.php | Chat, drafting and learning surfaces | Application code present; model/provider configuration and quality unverified |
| Social links | api/lead-social-links-api.php | Store social profile references with lead context | Source present; external profile access is not implied |
| QR and vCard | api/business-card-qr-generate.php, api/business-card-vcard.php | Agent business-card export and sharing | Source present; live link and contact-card behavior unverified |

## Integration boundary

A request handler or webhook proves that application-side integration code exists. It does not prove an active API key, successful provider call, valid production account, current data or provider approval. Public examples use synthetic contact information.

