# SECURITY-AUDIT-EVIDENCE — redacted record

This document preserves the findings of the independent security audit that
drove the `security/claude-audit-remediation` branch, in redacted form, so the
decisions remain reviewable without storing secrets, customer data or
exploitable payloads in the repository.

## 1. Provenance

| Field | Value |
|---|---|
| Audit type | Independent static security review (Claude) |
| Scope | Plugin source, docs, tests and integration harness at baseline `1fa7979c70ef43f7020c83807a9a88968f846894` |
| Related PR | PR #1 (`security/audit-remediation`), head `624ef4390fdef32f984a90324e38087441c52b0e` — reviewed, not modified by this branch |
| Verdict | **CONDITIONAL PASS** — no CRITICAL/HIGH confirmed; highest confirmed severity MEDIUM |
| Production gate | Order intake blocked until the required MEDIUM findings (CF-01–CF-06) close and live staging validation passes |
| Redaction | Keys, tokens, passwords, customer names/e-mails/addresses and any working exploit strings are omitted or genericised |

The original full audit text is kept outside the repository in the private
project workspace. This file is the durable, shareable summary.

## 2. Confirmed findings (CF)

Severity is the auditor's confirmed rating. "Status" is this branch's disposition.

| ID | Severity | Finding (redacted) | Status | Evidence |
|---|---|---|---|---|
| CF-01 | MEDIUM | IP allowlist and rate limiter trusted client-supplied forwarding headers, contradicting the docs | **Fixed** | `order_api_trusted_proxies`; rightmost-untrusted-hop resolution used for allowlist, rate limit and log throttle. `110-api-controller-test.php` spoofed-header and trusted-proxy cases |
| CF-02 | MEDIUM | HTTPS requirement bypassable via client-set `X-Forwarded-Proto`; key compared before transport check | **Fixed** | Transport check runs before auth; header honoured only from a trusted proxy. `110-api-controller-test.php` |
| CF-03 | MEDIUM | Failed-key traffic unlimited and wrote a DB log row per request | **Fixed** | 30/min/client failed-auth throttle before key compare; bounded rejection logging (120 rows/min). `110-api-controller-test.php` |
| CF-04 | MEDIUM (financial) | `(float)` casts truncated locale amounts (`"4,990"` → 4), enabling near-zero paid orders | **Fixed** | Strict `Money` parser; 422 on ambiguous/malformed amounts; max bounds. `115-money-test.php`, `100-order-import-test.php` |
| CF-05 | MEDIUM (privacy) | `order_api_keep_payloads` never read; raw PII payloads always stored | **Fixed** | Payload stored only when enabled; immediate purge on save and via scheduler. `90-order-repository-test.php`, `100-order-import-test.php` |
| CF-06 | MEDIUM (privacy) | Package-label PDFs never expired by retention | **Fixed** | `purge_labels()` with open-order protection; `order_api_label_retention_days`. `150-retention-test.php` |
| CF-07 | LOW | SKU match ignored Pepita product ID; mismatched line accepted | **Fixed** | Both identifiers resolved and required to agree; mismatch → 422. `100-order-import-test.php` |
| CF-08 | LOW | Failed imports left orphan pending WooCommerce orders | **Fixed** | `discard_order()` on every post-create failure path. `100-order-import-test.php` |
| CF-09 | LOW | Expired-lock reclaim TOCTOU admitted two generators | **Fixed** | Atomic option mutex around reclaim; `is_locked()` read-only. `80-feed-generation-test.php` |
| CF-10 | LOW | Re-sent order with changed content silently ignored (no hash compare) | **Fixed** | Hash mismatch → 409, stored order unchanged. Behaviour change from 200. `100-order-import-test.php`, `integration/tests/cases/07-idempotency.php` |
| CF-11 | LOW | Customer PII (name, address, free text) stored in logs | **Fixed** | `Redactor` PII key list and `*_email`/`*_phone` masking. `116-redactor-test.php` |
| CF-12 | LOW (trust) | Developer security docs contained claims the code contradicted | **Fixed** | `SECURITY-REPORT.md`, `PEPITA-API-INTEGRATION.md`, `TESTING.md`, `INSTALLATION.md` reconciled with code |
| CF-13 | LOW (test env) | Integration stores bound all interfaces with known credentials | **Partially fixed** | Ports bound to `127.0.0.1`; image digests not pinned (no Docker access to verify). Residual documented |

## 3. Potential findings (PV)

| ID | Severity if confirmed | Hypothesis (redacted) | Disposition |
|---|---|---|---|
| PV-01 | LOW | Authenticated feed marked `public, max-age`, no `Vary`; shared cache could serve it | **Fixed** via PV-07 change: token feeds now `private, no-store` |
| PV-02 | MEDIUM (financial) | Tax-enabled store could add VAT on top of a gross Pepita total | **Fixed** — gross handling (`prices_include_tax`, `calculate_totals(false)`, zero tax, exact total). `integration/tests/cases/08-tax.php` (not yet executed) |
| PV-03 | LOW | `external_id` collation case/accent-insensitive; `PEP-1` vs `pep-1` collide | **Fixed** — `utf8mb4_bin` column + upgrade migration + DB version 1.1.0. `02-schema.php`, `07-idempotency.php` |
| PV-04 | LOW–MEDIUM | Private storage protected only by `.htaccess`/`web.config`; nginx serves directly | **Mitigated/documented** — nginx `location` deny rule in `INSTALLATION.md`; random directory name remains defence in depth. Host must apply the rule |
| PV-05 | LOW (availability) | `Xml_Writer::clean()` stripped only C0, not Unicode noncharacters | **Fixed** — strips U+FDD0–FDEF and plane-ending FFFE/FFFF. `50-xml-writer-test.php` |
| PV-06 | LOW (privilege design) | `manage_woocommerce` lets Shop Managers read/regenerate secrets | **Deferred — accepted** as a deliberate store-operator role; narrowing would be a behaviour change |
| PV-07 | LOW | Label content not validated; non-PDF stored as `.pdf` | **Fixed** — strict base64, 8 MB cap, `%PDF-` magic. `100-order-import-test.php` |
| PV-08 | LOW | Import longer than stale window can overlap a retry | **Deferred — accepted**; window raised to 15 min; imports bounded and products resolved before creation. Heartbeat noted as future hardening |
| PV-09 | INFO | `wc_get_orders` meta lookup behaviour under HPOS unverified | **Resolved by CF-11/PV-03 work** — exact meta value re-checked in PHP; `cache_results => false`. Live HPOS run still required |
| PV-10 | LOW | Customer e-mail matching an admin account links the order to that account | **Fixed** — privileged users (`manage_woocommerce`/`edit_users`) never linked; order stays guest. `100-order-import-test.php` |
| PV-11 | LOW | Payload `date`/`payment_status` let a key holder backdate paid orders | **Deferred — accepted** under the Pepita trust model |
| PV-12 | INFO | Admin key mask shows first/last 4 characters | **Deferred — accepted**; admin-only display |

## 4. Required-remediation traceability

The auditor's required items 1–5 (production blockers) map to CF-01–CF-06 and are
all fixed. Recommended items 6–12 map to CF-07–CF-11, PV-02, PV-03, PV-04,
PV-05 and PV-07 and are fixed or documented. Infrastructure items map to CF-13
(ports bound, digests deferred), SECURITY.md reporting channel (owner decision
pending) and PV-01 (fixed).

## 5. Residual risk register

| Risk | Why accepted | Revisit trigger |
|---|---|---|
| Shop Manager can read secrets (PV-06) | Deliberate operator role | If least-privilege is required, introduce a dedicated capability |
| Stale-claim overlap (PV-08) | Bounded import, 15-min window | If imports can exceed 15 min under load |
| Key holder backdating (PV-11) | Pepita trust model | If financial audit requires server-side timestamps |
| Admin key mask (PV-12) | Admin-only | Cosmetic |
| nginx storage rule absent on host (PV-04) | Deployment responsibility, documented | Every non-Apache deployment |
| Unpinned image digests (CF-13) | No Docker access to pull/verify | Before CI relies on the images |
| No private vulnerability channel | Single maintainer; owner decision | Before public distribution |
| Live staging validation pending | Environment lacks Docker/MySQL | Mandatory M9 gate |

## 6. Independent re-review at remediation HEAD

An independent Claude re-review (`opencode-go/claude-haiku-5-5`, isolated
subagent) reviewed commit `0520704` and then the follow-up fixes. It verified
the core controls (CF-01, CF-02, CF-04, CF-05, CF-07, CF-10, CF-13, PV-05,
PV-07, PV-10) and raised ten follow-ups:

| New ID | Severity | Finding | Disposition |
|---|---|---|---|
| NEW-1 | MEDIUM (accounting) | Zeroed cart/shipping tax means WooCommerce tax lines show no VAT | **Documented residual** — exact gross total is preserved; VAT remains in total + item meta. Owner must confirm invoicing representation before go-live |
| NEW-2 | MEDIUM (functional) | Changed-content duplicate returns 409 forever, so later status updates are refused | **Documented residual** — this is the audit-recommended CF-10 behaviour; confirm Pepita's contract |
| NEW-3 | MEDIUM (CF-11 gap) | `billing_street_address` / `shipping_street_address` were not redacted | **Fixed** — added to `PII_KEYS`; regression test extended |
| NEW-4 | LOW (migration) | `activate()` skipped the collation migration; ALTER result unchecked | **Fixed** — migration runs on activation; result checked and logged |
| NEW-5 | LOW (client IP) | An unparseable forwarding hop fell back to the proxy address | **Fixed** — chain now fails closed (`0.0.0.0`); `ip:port` normalised; `/0` rejected |
| NEW-6 | LOW (money) | Float sums could leak binary artefacts | **Fixed** — line and grand totals rounded to cents |
| NEW-7 | LOW (retention) | Custom open statuses were treated as closed | **Fixed** — only known-closed statuses release a label |
| NEW-8 | LOW (logging) | Masked `ip` reduces rejection-log forensics | **Accepted** — access logs carry the source; log rows stay PII-free |
| NEW-9 | LOW (tests) | Some tests were sequential/weak (lock, log bound) | **Partially fixed** — log-bound assertion tightened; concurrency remains runtime-only |
| NEW-10 | INFO | IPv4-mapped IPv6, `/0`, query-string key, empty optional amounts | **Partially fixed** — `/0` rejected; others documented as residual |

Re-review verdict: **CONDITIONAL PASS**. No CRITICAL/HIGH. After the fixes above,
the remaining MEDIUM items (NEW-1, NEW-2) are explicit business/contract
decisions, not control failures, and production stays NO-GO pending the live
integration suite and M9 staging validation.

## 7. What this document deliberately does not contain

- Real API keys, feed tokens, admin passwords or `wp-config.php` secrets.
- Customer names, e-mail addresses, phone numbers, addresses or order contents.
- Working proof-of-concept payloads beyond the generic shapes already documented
  in `PEPITA-API-INTEGRATION.md`.
- Hostnames, IP addresses or credentials of any real deployment.

The unredacted audit is retained privately for auditors and the repository owner.
