# Security Policy — WP Pepita Sync

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x (development package; current: 1.0.0) | Yes |
| Earlier or unreleased builds | No |

**Release status:** `1.0.0` is a development/package version. No official
production release has been approved or published, and live Pepita staging
validation is still pending (see `DEVELOPMENT-STATUS.md`). The version number,
the packaged ZIP and the passing local/CI suites are not a production release
approval.

Security fixes are prepared for the latest 1.0.x package. Backports to older
versions, forks and modified copies are not provided. The plugin declares
PHP 8.1+ and WooCommerce 8.0+; the current package is tested on PHP 8.1–8.5 and
WooCommerce 11.3.0 (see `COMPATIBILITY-REPORT.md`).

## Reporting a vulnerability

**No private vulnerability reporting channel is currently available to external
reporters.** This is a known limitation of the current repository plan:

- GitHub private vulnerability reporting requires GitHub Advanced Security on a
  private repository and is not enabled here.
- GitHub issues on a private repository are visible only to collaborators, so an
  external reporter cannot open or read an issue in this repository.
- No security email address is published.

Do **not** disclose vulnerability details, exploit steps, credentials, customer
data or order payloads in a public place (social media, public gists, public
forks, or issues on other repositories), and do not guess or use an address that
is not explicitly published here.

If you already have a private channel with the maintainer (for example through an
existing engagement or contract), use it and reference this policy. Otherwise,
wait until a private reporting channel is published.

**Owner decision required:** before this repository accepts external reports, the
owner must enable GitHub private vulnerability reporting (requires GitHub Pro or
Advanced Security) or publish an approved private contact method. This policy does
not imply that either has been done. When a channel becomes available, this
section will be updated with the exact steps.

## What to include

- Affected version or commit SHA.
- The component involved (feed endpoint, order REST route, admin screen,
  importer, scheduler, packaging).
- Reproduction steps against a **test** store, with secrets and customer data
  removed.
- Impact assessment: what an attacker can read, change or trigger.
- Any suggested fix, if you have one.

## Sensitive information handling

- Never include real API keys, feed tokens, WordPress salts, database
  credentials, customer names, addresses, e-mail addresses, phone numbers, order
  payloads or payment data in a report, issue, pull request or log excerpt.
- Redact tokens as `<redacted>` and replace customer data with synthetic values.
- Use a disposable local or staging store, never a production store.
- The plugin masks secrets in its own logs (`Redactor`), but reports must still
  be redacted before they are shared.
- If you accidentally publish a secret, rotate it immediately: regenerate the
  feed token and order API key in the plugin settings (or the corresponding
  `wp-config.php` constants) and invalidate the exposed value.

## Security update expectations

These are best-effort targets for a single-maintainer project, not a commercial
SLA:

- Acknowledge a report within 5 business days.
- Triage severity and confirm the affected versions within 10 business days.
- Prepare and release a fix for confirmed high-severity issues as soon as
  practical; coordinate the disclosure date with the reporter.
- Publish a GitHub security advisory and a `CHANGELOG.md` entry after a fix is
  available.
- Credit the reporter unless they ask to remain anonymous.

## Scope

In scope: this plugin's PHP code, feed endpoint, order REST route, admin screens,
scheduling, file handling and packaging.

Out of scope: WordPress, WooCommerce and Action Scheduler core; the Pepita
service and its API; vulnerabilities that require an already-compromised
administrator account or server; reports produced only by automated scanners
without a demonstrated impact.

## Security-relevant tests

The repository contains tests for authentication, authorisation, rate limiting,
body limits, secret redaction and duplicate-order handling. See
`M8-INDEPENDENT-REVIEW.md` for the exact commands and evidence locations.
