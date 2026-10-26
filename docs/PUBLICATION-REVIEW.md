# Publication review

Review date: 26 October 2026. Release: v1.0.0-showcase.

RawOps Suite is a de-identified historical source archive and portfolio walkthrough. This publication uses a new Git history so excluded private material is absent from the public commit history.

## Preserved

- Application architecture, role-specific interfaces, API handlers and workflow implementations.
- Database structures and historical migration files, with private seed records removed.
- Eleven genuine archived captures, with identifying regions covered where needed.
- Source-linked feature walkthroughs, diagrams and firsthand usage context.

## Removed or normalized

- Company and product identity, original contact details, case identities and private legal narrative.
- Case, party and evidence-document seed records embedded in migrations.
- Credentials, runtime configuration, database exports, logs, uploaded documents and original evidence files.
- Legacy setup, bypass and cache utilities excluded during release preparation.
- Shared access-password material and predictable bootstrap passwords.
- Original development-host references and product-specific field labels.
- Unused model-key injection into the separate browser interface prototype.

Product fields and identifiers were normalized across references. Syntax errors introduced by earlier substitutions were corrected. These are changes in the public adaptation; the original source archive remains separate.

## Checks performed

| Check | Result |
|:--|:--|
| Gitleaks 8.30.1 directory scan | No detected secrets in the release tree |
| PHP syntax inspection | 437 files passed |
| Standalone JavaScript syntax inspection | 33 files passed |
| Documentation and asset references | Checked for missing local files and heading targets |
| Contact and identity scan | No remaining matches for the reviewed private identities, original host, original brand or product category; email literals use example.com |
| Historical images | Opaque redactions visually reviewed; original captures remain private |
| Brand graphics | Rendered and checked for clipping and readability |

These checks cover publication hygiene and syntax. They do not establish application correctness, runtime security, provider connectivity or migration compatibility.

## Important preservation details

The source includes historical maintenance tools and overlapping implementation versions. Runtime configuration is intentionally absent. Do not point the migration archive or maintenance tools at a live database.

The legal permissions helper includes case-membership checks. The preserved evidence-locker page also contains a broader role-based auto-unlock path. That difference must be resolved before any restoration involving private records. The screen's historical security wording is not an assurance of protection.

Some preserved supplier administration code handles credential fields, and model handlers send selected business context to external providers. A restoration needs a full data-flow, authorization, secret-storage and retention review. See [security and privacy](SECURITY-AND-PRIVACY.md).

## Evidence language

Screenshots document historical UI states. Source demonstrates implementation. Firsthand usage statements describe the builder's experience. The release does not claim independently measured revenue, legal outcomes, fee savings, certified security or present-day uptime.

