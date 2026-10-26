# Security and privacy review

This is a source review of controls visible in the portfolio snapshot. It is not a penetration test, security certification or deployment review.

## Controls represented in source

- Login, logout, profile, password-change and reset flows are grouped under api/auth/ and api/user/.
- Email verification and consent changes have dedicated endpoints and schema migrations.
- Role-specific pages and server-side permission checks appear across admin, customer, agent, trainee and legal areas.
- Email workflows include queue processing, provider callbacks, event tracking and spam-prevention work.
- Order, line-item and commission code includes transaction boundaries and failure handling.
- Legal workflow endpoints separate access requests, assignment, notes, document metadata and case activity.
- The source includes operational health and error-tracking components.

Representative references: api/auth/, api/user/, api/legal/, required/commission-utils.php, api/email-queue-processor.php, api/error-tracker.php.

## Concrete examples to discuss in an interview

| Boundary | Source and behavior |
|:--|:--|
| Case membership | [Legal permissions](../required/legal-permissions.php) permit administrators and require legal-role users to belong to the requested case. |
| Training identity | [Training heartbeat](../api/training-heartbeat.php) requires sign-in and rejects a supplied user ID that differs from the signed-in user. This is a user-identity check, not a complete audit of session ownership. |
| Agent card management | [Card API](../api/business-card-api.php) checks agent and administrator roles before exposing its management actions. |
| Team changes | [Legal team assignment](../api/legal/assign-team-member.php) requires an administrator, validates the assignment role and records the action. |
| Activity visibility | [Legal session handling](../required/legal-session.php) maintains active, idle and total time with page context. Such records need access and retention rules. |
| Model input boundary | [Chat](../api/ai-chat-streamlined.php) and [email generation](../api/ai-email-composer.php) send selected business context to an external model provider. A deployment needs an explicit review of which customer data may leave the application. |

These examples support a discussion of specific implementation choices. They do not imply uniform protection across all endpoints. The historical evidence-locker screen uses security language from the original interface; its text alone is not a security assurance.

## Portfolio privacy measures

- Original company and product identity was replaced with a neutral portfolio identity.
- Literal email addresses were replaced with synthetic example.com addresses.
- Phone and street-address examples were removed or replaced with neutral form labels.
- Legal evidence images, uploaded customer material, logs, database exports, local configuration, host access rules and unrelated strategy material were excluded.
- Old product marketing imagery and compliance marks were removed.
- Debug and test utilities were removed from this public archive.

## Deployment checks still required

The archive omits runtime credentials and server configuration. Before using the system, an operator would need to review:

1. HTTPS, secure cookie settings, session renewal and CSRF protection.
2. Authorization checks on every write endpoint and uploaded file path.
3. Password hashing, reset-token expiry, email verification and consent behavior.
4. SQL parameterization, transaction boundaries, retries and idempotency.
5. Rate limits, anti-spam rules, error redaction, log retention and alerting.
6. Upload restrictions, content inspection, storage access and backup policy.
7. External provider credentials, webhook signatures and secret rotation.
8. Migration ordering, rollback plans, least-privilege database access and recovery tests.

Code presence does not confirm that each item is configured correctly in a live deployment. This candidate has not undergone an independent security audit.



## Historical inconsistencies requiring restoration review

The evidence-locker page retains a role-based auto-unlock path that is broader than the case-membership helper. Supplier credential handling and model context transmission also need a full deployment review. See the [publication review](PUBLICATION-REVIEW.md) for the concrete limitations and public-adaptation changes.
