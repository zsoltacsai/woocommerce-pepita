# WP Pepita Sync — M9 Controlled Staging Execution Report

**Decision:** GO — Isolated Staging Verification Only  
**Production Gate:** STRICT NO-GO (HOLD)  
**Repository:** `zsoltacsai/woocommerce-pepita`  
**Target Pull Request:** [PR #2 (security/claude-audit-remediation)](https://github.com/zsoltacsai/woocommerce-pepita/pull/2) (Draft, Unmerged)  
**Exact Review & Candidate SHA:** `1cbadd24353ea0eb31c63e29c1eca4db4417034a`  
**Distribution Package SHA-256:** `63ca05e193d131f7fd0860c08dc4f8e685eacf8172ab80463b22a6a64f6d8ba2`  
**Date:** 2026-11-13  
**Auditor:** Alastor "Mad-Eye" Moody (Independent QA via Claude Haiku 5.5 / `claude-haiku-5-5`)  
**Development Harness:** T3 Code Orchestrator / Gemini 3.8 Flash

---

## 1. Executive Summary & Gate Status

This report certifies the controlled staging execution and release candidate synchronization for **WP Pepita Sync** (version 1.1.0, database schema 1.1.0).

All four security and financial integrity risks identified in the M9 audit scope have been investigated, remediated with optimistic lease fencing and dual-anchor recovery, deterministically verified through automated test suites (185 unit tests across PHP 8.1–8.5, 71 integration scenarios on HPOS and legacy stores), and audited by independent reviewer Alastor "Mad-Eye" Moody.

The release candidate has been synchronized with [PR #2](https://github.com/zsoltacsai/woocommerce-pepita/pull/2) via a clean, strict fast-forward merge without history rewriting. PR #2 remains in **Draft** status and strictly unmerged.

| Gate | Status | Conditions & Action Required |
|---|---|---|
| **Staging Execution Gate** | **GO (APPROVED)** | Approved for isolated, non-customer-facing staging execution following preflight isolation controls. |
| **Production Gate** | **STRICT NO-GO (HOLD)** | Prohibited. No production deployment, release tag, or live order intake permitted until staging dry-run acceptance and partner team clarifications are completed. |

---

## 2. Release Candidate & Distribution Package Artifact

| Attribute | Specification / Verification Evidence |
|---|---|
| **Archive File** | `dist/wp-pepita-sync.zip` |
| **Plugin Slug** | `wp-pepita-sync` |
| **Plugin Version** | `1.1.0` (`WPS_PEPITA_VERSION`) |
| **Database Version** | `1.1.0` (`WPS_PEPITA_DB_VERSION`) |
| **Candidate Commit** | `1cbadd24353ea0eb31c63e29c1eca4db4417034a` |
| **Package SHA-256** | `63ca05e193d131f7fd0860c08dc4f8e685eacf8172ab80463b22a6a64f6d8ba2` |
| **Entry Count** | 55 entries (47 files + 8 directories) |
| **Compressed Size** | 134,845 bytes (~132 KB) |
| **Uncompressed Size** | 445,466 bytes (~435 KB) |
| **Source Identity** | 100% byte-for-byte identity against repository tree files |
| **Cleanliness & Secrets** | 100% secret-free; `.git`, `tests/`, `integration/`, `dist/`, `.DS_Store`, and `*.map` stripped |
| **Build Reproducibility** | Byte-for-byte reproducible via `SOURCE_DATE_EPOCH=1794570814 bash bin/build.sh` |

---

## 3. Automated CI & Integration Verification Evidence

### 3.1 GitHub Actions Verification Runs

1. **Pull Request #2 CI Run**:
   - **URL**: [https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43425328716](https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43425328716)
   - **Trigger / Event**: `pull_request` on `security/claude-audit-remediation` at commit `1cbadd2`
   - **Jobs (8/8 Passed 100% Green)**:
     - `lint (PHP 8.5)`: PASSED (9s) — clean `php -l` across all source files
     - `unit (PHP 8.1)`: PASSED (9s) — 185 passed, 0 failed
     - `unit (PHP 8.2)`: PASSED (17s) — 185 passed, 0 failed
     - `unit (PHP 8.3)`: PASSED (11s) — 185 passed, 0 failed
     - `unit (PHP 8.4)`: PASSED (10s) — 185 passed, 0 failed
     - `unit (PHP 8.5)`: PASSED (10s) — 185 passed, 0 failed
     - `integration-legacy`: PASSED (2m 1s) — 71 scenarios passed on WooCommerce 11.3.0 Legacy storage
     - `integration-hpos`: PASSED (1m 50s) — 71 scenarios passed on WooCommerce 11.3.0 HPOS storage
2. **Candidate Branch CI Run**:
   - **URL**: [https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43424860822](https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43424860822)
   - **Trigger / Event**: `workflow_dispatch` on `security/m9-staging-readiness` at commit `1cbadd2`
   - **Jobs (8/8 Passed 100% Green)**: All lint, unit, and integration checks passed.

### 3.2 Real HPOS & Legacy Integration Environment Evidence

Both integration test jobs execute against real containerized stacks:
* **PHP Runtime**: PHP 8.5 (verified via `Environment: PASS PHP 8.5 is the runtime`)
* **Database**: MySQL 8.4 server (verified via `PASS MySQL 8.4 is the database server`)
* **WordPress**: WordPress 7.1.4 (verified via `PASS WordPress 7.1.4 is installed`)
* **WooCommerce**: WooCommerce 11.3.0 (verified via `PASS WooCommerce 11.3.0 is active`)
* **HPOS Storage**: Verified with HPOS tables active (`PASS The order storage mode matches this test configuration`)
* **Legacy Storage**: Verified with `wp_posts` / `wp_postmeta` order tables active

---

## 4. Automated CI Suite Readiness for Synthetic Staging Scenarios (ST-01 to ST-11)

The code capabilities underlying the 11 synthetic staging acceptance scenarios defined in [M9-STAGING-READINESS.md](file:///home/zsolt/Projects/woocommerce-pepita/M9-STAGING-READINESS.md) have been verified through automated unit tests and Docker-based CI integration tests.

> [!IMPORTANT]
> **Operational Verification Hierarchy**:
> Automated CI tests (`tests/cases/` and `integration/tests/cases/`) prove code correctness and protocol compliance in a simulated environment. They do **not** replace execution on a real, isolated staging server. Formal execution of ST-01 through ST-11 on a dedicated WordPress staging server with real TLS, reverse-proxy, and mail traps remains a strict prerequisite before production consideration.

| Scenario | Scope & Capabilities | CI Code Readiness | Automated Test Evidence |
|---|---|---|---|
| **ST-01** | **Clean Installation & Migration**: Activation creates `wps_pepita_order_claims` and `wps_pepita_logs` with `utf8mb4_bin` collation. | **READY (CI PASS)** | `02-schema.php`: tests 21–31 (`create_tables()`, `maybe_upgrade()`, column validation). |
| **ST-02** | **Full Product Feed Generation**: Valid XML generation with Unicode noncharacter filtering and specification-based structural validation (not official XSD). | **READY (CI PASS)** | `03-feed-generation.php`: tests 32–39, 44 (`full feed generation completes with exact counters`). |
| **ST-03** | **Stock Feed Generation**: Complete catalog stock feed snapshot (entire current eligible catalog, never a delta feed) without duplicates. | **READY (CI PASS)** | `03-feed-generation.php`: tests 40–42 (`stock feed generation completes`). |
| **ST-04** | **Tax-Aware Order Intake**: Gross total preserved, 27% VAT partitioned via `WC_Order_Item_Tax`. | **READY (CI PASS)** | `08-tax.php`: tests 82–85 (`taxable 27% VAT: net, tax, gross and tax rows preserved`). |
| **ST-05** | **Zero-VAT / Exempt Order Intake**: Customer tax exemption creates zero-tax orders without error. | **READY (CI PASS)** | `08-tax.php`: tests 86–87 (`customer tax exemption creates clean zero-tax order`). |
| **ST-06** | **Unpaid COD Order Intake**: Cash-on-delivery orders placed on-hold without payment stamp. | **READY (CI PASS)** | `06-order-import.php`: test 64 (`unpaid transfer order imported as on-hold`). |
| **ST-07** | **Identical Duplicate Replay**: Repeated webhook returns HTTP 200 duplicate with original order ID; claims table not mutated. | **READY (CI PASS)** | `07-idempotency.php`: test 71 & `106-recovery-and-replay-test.php` (no write on duplicate). |
| **ST-08** | **Altered Duplicate Conflict**: Mismatched payload returns HTTP 409 Conflict, order unmodified. | **READY (CI PASS)** | `07-idempotency.php`: test 77 & `106-recovery-and-replay-test.php` (altered total/SKU rejected). |
| **ST-09** | **Crash Recovery & Concurrency**: Incomplete shells cleaned up under atomic lease fence (or quarantined on failure); stale worker fenced out on complete; single order preserved. | **READY (CI PASS)** | `07-idempotency.php`: test 76 & `106-recovery-and-replay-test.php` (fencing tests 1–11). |
| **ST-10** | **Maintenance Cron & Retention**: Expired locks cleared, raw payloads purged, labels retained. | **READY (CI PASS)** | `09-scheduler.php`: tests 79–80 & `150-retention-test.php` (Action Scheduler & cron execution). |
| **ST-11** | **Rollback & Uninstall**: Deactivation/reactivation preserves orders and claims safely. | **READY (CI PASS)** | `02-schema.php`: tests 22, 30–31 (idempotent table recreation and upgrade). |

---

## 5. Extended Verification Requirements

### A. Recovery Concurrency
1. **Simulated Interrupted Creation**: An incomplete order shell tagged with `_wps_pepita_in_progress = '1'` is abandoned midway.
2. **Atomic Cleanup Fencing**: Before attempting shell deletion, the worker executes `Order_Repository::fence_incomplete_order_cleanup($external_id, $expected_order_id, $attempt)`. If the worker lost its lease, cleanup aborts and returns `HTTP 409 retry` without deleting the order.
3. **Fresh Order Reload & Verification**: Following successful fencing, the order is reloaded fresh. If concurrently completed or populated with items (`!is_order_incomplete()`), it is preserved and handed off to `handle_existing_order()`.
4. **Pre-Delete Check & Quarantine Fallback**: The worker re-verifies `is_attempt_current()` immediately before deletion. If deletion throws an exception or fails, the shell is quarantined (`_wps_pepita_quarantined_from`, status `failed`, external ID cleared).
5. **Stale Worker Fence-Out**: If a stalled worker awakens at `Order_Repository::complete()`, its stale attempt counter is rejected (`0 rows updated`). The fenced worker does not delete the completed order, logs a warning, and returns `HTTP 409 retry`.
6. **Single Order Guarantee**: Exactly one valid, non-duplicate WooCommerce order exists in the database.

### B. Financial Integrity
1. **Net, VAT, Gross Partitioning**: Verified under both `prices_include_tax = yes` and `prices_include_tax = no`. Net line amounts plus calculated tax sum to the exact gross price sent by Pepita.
2. **WooCommerce Tax Rows**: Standard `WC_Order_Item_Tax` entries are created with matching rate percentages (`27%`), ensuring native WooCommerce reporting and accounting align.
3. **Zero-VAT & Exempt Orders**: Orders with 0% VAT or customer tax exemption create clean zero-tax orders with no tax rows and exact gross totals, avoiding 422 calculation errors.
4. **Unpaid Orders**: Orders marked unpaid remain in `on-hold` status without payment transaction IDs or paid dates.
5. **No External Invoicing**: External billing plugins (Számlázz.hu, Billingo) are de-hooked; no invoices or live credit card charges occur.

### C. Feed Consistency
1. **Full Product Feed**: Generates complete XML catalog adhering to Pepita merchant specification with UTF-8 noncharacter filtering.
2. **Stock Feed**: Generates complete stock feed snapshot (entire current eligible catalog containing id, price, and stock for all products, never a delta feed) with identical product identifiers (`<id>`, `<sku>`) matching the full feed signature.
3. **Variable Products**: Product axes and variations mapped deterministically.
4. **Out of Stock**: Correctly tagged with `<availability>0</availability>` while maintaining pricing.
5. **Pepita Disclaimer**: Official Pepita acceptance cannot be claimed solely from local XML validation until verified against Pepita's partner feed ingest endpoint.

### D. Operational Safety
1. **Scheduled Tasks**: Action Scheduler and WP-Cron fallback verified.
2. **Log Redaction**: Customer PII (street addresses, phone numbers, emails, API keys) redacted in `wps_pepita_logs`.
3. **Private Storage**: PDF package labels stored in protected upload directory with `.htaccess` / nginx deny rules and `%PDF-` magic header enforcement.
4. **Zero Notifications**: `suppress_emails()` active during all order imports; no customer order emails dispatched.

### E. Staging Operational Isolation Preflight
1. **Real TLS Termination**: Staging host must enforce HTTPS with trusted TLS certificate and HSTS. Plain HTTP webhook delivery is strictly rejected.
2. **Reverse Proxy Configuration**: Nginx/Apache reverse proxies must pass `X-Forwarded-For`, `X-Forwarded-Proto`, and preserve client IP in `$_SERVER['REMOTE_ADDR']`.
3. **Mandatory Mail Interception**: Outgoing emails must be trapped (Mailpit / Mailhog / `pre_wp_mail` short-circuit). No staging customer notifications may leak to live recipients.
4. **ERP & External Sync Disconnection**: All external ERP, accounting (Számlázz.hu, Billingo), and shipping carrier sync integrations must be mocked or disabled.

---

## 6. Pepita Partner Integration Dependencies

Before activating live order forwarding or connecting external Pepita endpoints, store operators must submit the formal technical inquiry documented in [M9-STAGING-READINESS.md §6.2](file:///home/zsolt/Projects/woocommerce-pepita/M9-STAGING-READINESS.md#L210-L248):

1. **Merchant Sandbox Availability**: Availability of a partner sandbox for synthetic order delivery.
2. **Webhook Retry Schedule**: Pepita's retry backoff policy on receiving `HTTP 409 Conflict` or `HTTP 500`.
3. **Post-Creation Order Changes (NEW-2)**: Expected behavior if Pepita sends status changes or line cancellations under the same external order ID.
4. **HTTP 409 Semantics**: Confirmation that Pepita pauses or retries on 409 without treating it as an unrecoverable failure.
5. **Outbound IP Allowlist**: Official egress IP CIDR blocks for webhook firewall filtering.
6. **Tax-Exempt / AAM Representation**: Payload conventions for 0% VAT or tax-exempt buyers.
7. **Package Label Delivery**: Delivery timing and SLA for base64 PDF shipping labels.
8. **Feed Validation Portal**: Partner ingestion validator for full and stock feeds.

*(Note: Per security guidelines, no automated communications have been sent to partner teams.)*

---

## 7. Independent Security Review Verdict (Mad-Eye Moody)

The candidate SHA `1cbadd24353ea0eb31c63e29c1eca4db4417034a` and staging execution evidence were reviewed by Claude Haiku 5.5 (`claude-haiku-5-5`) in persona as Alastor "Mad-Eye" Moody.

* **Reviewer**: Claude Haiku 5.5 (`claude-haiku-5-5`) via `claudeAgent`
* **Execution Environment Limitations**: Local machine lacks native PHP and Docker daemon; execution verification performed via automated GitHub Actions runners.
* **Findings Verification**: All 8 findings from previous rounds verified as **Resolved**:
  - Finding 1 (CRITICAL): Completed orders preserved on fenced complete() — **Resolved**
  - Finding 2 (HIGH): Importer-level lease fencing test coverage (5 tests added) — **Resolved**
  - Finding 3 (MEDIUM): `create_order` fence-out mapped to 409 retry — **Resolved**
  - Finding 4 (MEDIUM): Reclaimed replay complete() return checked — **Resolved**
  - Finding 5 (MEDIUM): Fail-closed on corrupted/null claim row — **Resolved**
  - Finding 6 (MEDIUM): WC stub customer note filtering restored — **Resolved**
  - Finding 7 (LOW): Pre-save lease re-verification implemented — **Resolved**
  - Finding 8 (LOW): 55 entries packaging verified — **Resolved**
* **Reviewer Residual Observations & Recommendations**:
  1. *Stale Incomplete Shell Delete Ordering* (`class-importer.php:112`): At line 112, `$existing_order->delete(true)` runs before `associate(..., 0, $attempt)`. While it cannot delete a completed order (checked via `is_order_incomplete()`), recommendation is to move the fence before the delete or make delete conditional on active attempt.
  2. *Duplicate Path Complete Fence* (`class-importer.php:1458`): Calls `Order_Repository::complete(..., 0)`, skipping attempt check. Benign due to completed status filter, but recommended to document or fence for full consistency.
  3. *Unit Test Count Evidence*: 185 unit tests confirmed via CI job logs (185 passed, 0 failed across PHP 8.1–8.5).
* **Reviewer Verdict**: **CONDITIONAL PASS — Staging Execution Only; Production Gate remains STRICT NO-GO**.

---

## 8. Rollback & Contingency Readiness

If unexpected anomalies arise during isolated staging tests:
1. **Deactivation**: Deactivate `wp-pepita-sync` in WP Admin. Safely unhooks filters, stops scheduled jobs, and keeps order claims intact.
2. **Database Reversion**: `wps_pepita_order_claims` table with `utf8mb4_bin` collation is fully backward-compatible with 1.0.0.
3. **Storage Reset**: Purge `wp-content/uploads/wp-pepita-sync-storage/temp/` to clear transient feed files.
4. **Order Integrity**: Existing WooCommerce orders remain valid shop orders; no financial or customer records are corrupted.

---

## 9. Final Decision & Sign-Off

* **Staging Execution**: **GO (CONDITIONAL APPROVAL)**
* **Production Deployment**: **STRICT NO-GO (HOLD)**
* **PR #2 Status**: Fast-forwarded to `1cbadd24353ea0eb31c63e29c1eca4db4417034a`. Must remain Draft and unmerged.
