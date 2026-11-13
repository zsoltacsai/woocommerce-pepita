# Security Policy — WP Pepita Sync

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x (current: 1.0.0) | Yes |
| Earlier or unreleased builds | No |

Security fixes are prepared for the latest released 1.0.x version. Backports to
older versions, forks and modified copies are not provided. The plugin declares
PHP 8.1+ and WooCommerce 8.0+; the current release is tested on PHP 8.1–8.5 and
WooCommerce 11.3.0 (see `COMPATIBILITY-REPORT.md`).

## Reporting a vulnerability

Do **not** open a public issue with vulnerability details, exploit steps,
credentials, customer data or order payloads.

Preferred channel: **GitHub private vulnerability reporting**, if it is
available and enabled for this repository — use the repository **Security** tab
and choose **Report a vulnerability**. This opens a private advisory visible only
to the maintainer.

If private vulnerability reporting is not available (it requires GitHub Advanced
Security on a private repository), open a minimal public issue titled
`Security contact request` that contains **no vulnerability details** and asks
the maintainer for a private channel. The maintainer will reply with a private
way to share the report.

There is no published security email address; do not send reports to an address
you find elsewhere without confirming it with the maintainer first.

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
