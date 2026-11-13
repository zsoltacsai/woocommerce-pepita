# WP Pepita Sync — M9 Staging Readiness & Final Risk Closure

**Decision:** GO — Staging Preparation Only  
**Production Gate:** STRICT NO-GO (HOLD)  
**Repository:** `zsoltacsai/woocommerce-pepita`  
**Candidate Release Commit:** `d7abc001c7bae5611bbed35ff882f6dbe29308ca`  
**Branch:** `security/m9-staging-readiness`  
**Base Commit:** `a37099bbac334480d7bd943243e3696186aa4ab1` (PR #2 remediation HEAD)  
**Date:** 2026-11-13  
**Auditor Persona:** Alastor "Mad-Eye" Moody (Independent QA via Claude Haiku 5.5)  
**Harness / Engine:** T3 Code Orchestrator / Gemini 3.8 Flash

---

## Executive Summary

This document certifies that the **WP Pepita Sync** WooCommerce plugin has successfully resolved the four remaining security and financial integrity risks identified in the M9 audit scope, verified through automated unit tests across PHP 8.1–8.5 and full integration tests on real HPOS and legacy WooCommerce stores.

The staging distribution archive has been generated in a strictly reproducible, secret-free manner. Staging deployment is approved under isolated dry-run constraints. **Production deployment remains under strict NO-GO hold** pending live staging acceptance and partner team clarifications.

---

## 1. Staging Distribution Package Artifact

| Attribute | Specification / Value |
|---|---|
| **Archive File** | `dist/wp-pepita-sync.zip` |
| **Plugin Slug** | `wp-pepita-sync` |
| **Plugin Version** | `1.1.0` (`WPS_PEPITA_VERSION`) |
| **Database Schema Version** | `1.1.0` (`WPS_PEPITA_DB_VERSION`) |
| **Commit SHA** | `d7abc001c7bae5611bbed35ff882f6dbe29308ca` |
| **Package SHA-256** | `0fefdff825c3b1199ef222d28fa39790e798c993b4d5d4a04b8dd80beb5f6b1d` |
| **Archive File Count** | 55 files |
| **Archive Uncompressed Size** | 436,894 bytes (~427 KB) |
| **Archive Compressed Size** | 135,932 bytes (~133 KB) |
| **Secret-Free Verification** | Confirmed: no API keys, tokens, passwords, private salts, or credentials in archive |
| **Development Leftovers** | Confirmed clean: `.git`, `tests/`, `integration/`, `dist/`, `.DS_Store`, `*.map` stripped |

---

## 2. Final Security & Financial Integrity Risk Closures

The four remaining risks identified during M9 staging preparation have been investigated, fixed, and verified with deterministic tests:

### 2.1 Crash Between WooCommerce Order Creation and Metadata Persistence
* **Problem**: `wc_create_order()` allocates a persistent order record in the WooCommerce database immediately. If PHP crashed (process termination, SIGKILL, memory exhaustion, timeout) before metadata was saved, an incomplete order existed in WooCommerce without `_wps_pepita_order_id`. On subsequent retry, crash recovery failed to locate the orphaned record and invoked `wc_create_order()` a second time, leaving a duplicate ghost shell order in the merchant's store.
* **Remediation**:
  1. **Immediate Association**: Immediately upon `wc_create_order()`, `Order_Repository::associate($external_id, $order_id)` records the assigned WooCommerce order ID in the active claim row in MySQL.
  2. **Immediate In-Progress Metadata**: Initial metadata (`META_ORDER_ID`, `META_PAYLOAD_HASH`, and `_wps_pepita_in_progress = '1'`) is written and saved via `save_meta_data()` immediately after allocation.
  3. **Crash Recovery Shell Purge**: On webhook retry following an interrupted attempt, `find_order_by_external_id()` locates the existing order (via metadata or claim row). If `is_order_incomplete()` detects `_wps_pepita_in_progress === '1'`, the incomplete shell is logged (`order_recovery`), forcefully deleted via `$existing_order->delete(true)`, disassociated in MySQL, and a clean, complete order is rebuilt from scratch.
  4. **Atomic In-Progress Removal**: Upon full successful order population, `_wps_pepita_in_progress` is removed, and `_wps_pepita_imported_at` timestamp is written.
* **Verification**:
  - Unit test `recovery: mid-creation crash leaving incomplete shell is discarded and cleanly rebuilt` in [106-recovery-and-replay-test.php](file:///home/zsolt/Projects/woocommerce-pepita/tests/cases/106-recovery-and-replay-test.php#L55).
  - HPOS integration test `Mid-creation crash leaving an incomplete order shell is discarded and cleanly rebuilt` in [07-idempotency.php](file:///home/zsolt/Projects/woocommerce-pepita/integration/tests/cases/07-idempotency.php#L196).

### 2.2 Replay Handling for Historical Orders Lacking Payload Hash
* **Problem**: Orders created in production prior to version 1.1.0 lack `_wps_pepita_payload_hash` in WooCommerce metadata and claims storage. A replay of a historical external ID with altered prices or items could be mistakenly treated as an identical duplicate (HTTP 200) without verifying whether the order contents matched.
* **Remediation**:
  1. **Invariant Business Verification**: When an existing order has no stored payload hash, `handle_existing_order()` parses the incoming payload and evaluates business invariants:
     - Currency must match existing order currency (`$existing_order->get_currency()`).
     - Authoritative payload total (calculated from lines, shipping, and vouchers via `calculate_payload_total()`) must equal existing order total within 0.01 precision.
     - Line item count must match count of `line_item` entries on the existing order.
  2. **Conflict Rejection (HTTP 409)**: If any invariant fails to match, the replay is rejected with HTTP 409 Conflict, an operator note is recorded detailing the discrepancy, and a warning log is recorded with reconciliation event `changed_payload_refused`. The existing order is unmodified.
  3. **Secure Hash Backfilling**: If all invariants match, the calculated payload hash is backfilled into `_wps_pepita_payload_hash` on the WooCommerce order (`save_meta_data()`) and into `wps_pepita_order_claims` via `Order_Repository::backfill_hash()`. An operator note confirms the validation, and HTTP 200 duplicate is returned.
* **Verification**:
  - Unit test `replay: historical order without hash backfills hash when data matches` in [106-recovery-and-replay-test.php](file:///home/zsolt/Projects/woocommerce-pepita/tests/cases/106-recovery-and-replay-test.php#L192).
  - Unit test `replay: historical order without hash rejects altered total with 409` in [106-recovery-and-replay-test.php](file:///home/zsolt/Projects/woocommerce-pepita/tests/cases/106-recovery-and-replay-test.php#L260).
  - Unit test `replay: historical order without hash rejects altered currency with 409` in [106-recovery-and-replay-test.php](file:///home/zsolt/Projects/woocommerce-pepita/tests/cases/106-recovery-and-replay-test.php#L326).
  - HPOS integration test `Historical order without payload hash backfills on matching replay and rejects altered replay with 409` in [07-idempotency.php](file:///home/zsolt/Projects/woocommerce-pepita/integration/tests/cases/07-idempotency.php#L243).

### 2.3 Recovery Behavior Under Concurrent Webhook Delivery
* **Problem**: Bursts of identical webhooks delivered concurrently by Pepita or courier webhooks must never race to allocate multiple WooCommerce orders for the same external ID.
* **Remediation**:
  1. **Atomic Conditional Claims**: `Order_Repository::claim()` relies on an atomic MySQL `INSERT ... ON DUPLICATE KEY UPDATE` and conditional state checks. The first worker receives state `claimed`. Any concurrent worker attempting to claim an active lease receives `busy`.
  2. **Retry Lease Timeout**: If a worker terminates abnormally, the lease window of 15 minutes allows safe reclaim only after stale lease expiration.
  3. **Completed State Locking**: Once marked `completed`, the claim row locks permanently; subsequent requests are immediately recognized as `completed` without entering creation code.
* **Verification**:
  - Unit test `concurrency: atomic claim transitions and conditional reclaim under load` in [106-recovery-and-replay-test.php](file:///home/zsolt/Projects/woocommerce-pepita/tests/cases/106-recovery-and-replay-test.php#L385).
  - Integration test `The durable claim transitions claimed -> busy -> failed -> claimed -> completed` in [07-idempotency.php](file:///home/zsolt/Projects/woocommerce-pepita/integration/tests/cases/07-idempotency.php#L92).

### 2.4 Deduplication Scheme Safety Under Process Termination
* **Problem**: Process termination at any instruction must not corrupt deduplication state, leave unrecoverable locks, or bypass idempotency checks.
* **Remediation**:
  1. **Dual Anchor Architecture**: Deduplication is anchored in two independent datastores: MySQL table `wps_pepita_order_claims` (with unique index on `external_id` using `utf8mb4_bin` collation) and WooCommerce order metadata (`_wps_pepita_order_id`).
  2. **Self-Healing Recovery**: If the claims table row is lost or dropped, crash recovery uses indexed order metadata to restore the claim. If the WooCommerce metadata write was interrupted, the claim table provides the allocated order ID for cleanup.
* **Verification**:
  - Integration test `A lost claim row recovers the existing order by its external id meta` in [07-idempotency.php](file:///home/zsolt/Projects/woocommerce-pepita/integration/tests/cases/07-idempotency.php#L127).

---

## 3. Automated Verification Matrix & CI Status

### 3.1 GitHub Actions Verification (Commit `d7abc00`)
* **Workflow Run**: [Run #43412825241](https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43412825241)
* **Status**: Complete / Validated

| Job Name | Runtime / Target | Status | Duration |
|---|---|---|---|
| `lint (PHP 8.5)` | Ubuntu 24.04, PHP 8.5 | **PASS** | 12s |
| `unit (PHP 8.1)` | Ubuntu 24.04, PHP 8.1 | **PASS** | 10s |
| `unit (PHP 8.2)` | Ubuntu 24.04, PHP 8.2 | **PASS** | 9s |
| `unit (PHP 8.3)` | Ubuntu 24.04, PHP 8.3 | **PASS** | 15s |
| `unit (PHP 8.4)` | Ubuntu 24.04, PHP 8.4 | **PASS** | 7s |
| `unit (PHP 8.5)` | Ubuntu 24.04, PHP 8.5 | **PASS** | 8s |
| `integration-hpos` | WP 7.1.4, WC 11.3.0, PHP 8.5, MySQL 8.4 (HPOS Enabled) | **PASS** | 1m 37s |
| `integration-legacy` | WP 7.1.4, WC 11.3.0, PHP 8.5, MySQL 8.4 (Legacy Posts) | **PASS** | 1m 46s |

*Total Suite: 173 Unit Tests (0 failures), 16 HPOS Integration Scenarios (0 failures).*

---

## 4. Independent Security Re-Review (Mad-Eye Moody)

An independent security QA review was conducted on candidate commit `d7abc00` by Claude Haiku 5.5 in persona as Alastor "Mad-Eye" Moody.

* **Auditor**: Claude Haiku 5.5 (`claude-haiku-5-5`)
* **Scope**: `class-importer.php`, `class-order-repository.php`, `class-wps-assert.php`, `106-recovery-and-replay-test.php`, `07-idempotency.php`.
* **Focus**: Financial integrity, duplicate order creation, crash recovery, historical replay, concurrent webhooks, process kill resilience.
* **Verdict**: **PASS / STAGING GO**
* **Auditor Notes**:
  - The crash window between order allocation and metadata persistence is closed by immediate claim association and in-progress metadata tracking.
  - The in-progress flag ensures incomplete order shells left by killed processes are destroyed and recreated cleanly rather than adopted in a truncated state.
  - The invariant validation (currency, total, item count) for historical orders prevents silent acceptance of altered payloads while providing safe hash backfilling for legitimate replays.
  - Dual anchoring across `wps_pepita_order_claims` and WooCommerce order metadata guarantees robust idempotency under process termination.
  - Production gate remains strictly **NO-GO** pending staging environment dry-run.

---

## 5. Staging Environment Preflight & Isolation Checklist

Before deploying `wp-pepita-sync.zip` to a staging environment, operators MUST verify the following isolation boundaries. Staging must never interact with live customers, live inventory, or external production APIs.

### 5.1 Environment Isolation Controls

| Isolation Area | Mandatory Preflight Check | Verification Method |
|---|---|---|
| **Payment Gateways** | All WooCommerce payment gateways must be set to Sandbox/Test mode or disabled (B-Payment, Barion, Stripe, PayPal, SimplePay). Real credit cards must never be charged. | Check **WooCommerce > Settings > Payments**. |
| **Customer Notifications** | Customer emails must be disabled or intercepted. No real customer should receive an order confirmation or status change email from staging. | Install **WP Mail Logging** or redirect SMTP to **Mailhog** / sandbox relay. Verify `wp_mail()` suppression. |
| **Fulfillment & ERP** | Disconnect ERP connectors (Tharanis, SAP, Odoo, Kulcs-Soft) and automated courier dispatch plugins (GLS, DPD, MPL, Foxpost). | Ensure shipping API credentials in staging are test credentials or fulfillment webhooks are disabled. |
| **Inventory & Stock** | Staging inventory changes must not sync back to central warehousing or physical store POS systems. | Verify ERP/inventory sync cron jobs are disabled in staging. |
| **External Invoicing** | External invoicing integrations (Számlázz.hu, Billingo) must be set to sandbox/test mode or de-hooked. Live tax invoices must never be generated. | Check Számlázz.hu / Billingo plugin settings: verify test mode is active. |
| **Pepita Endpoints** | Staging order reception must use staging API keys. Staging must NOT call live Pepita status update endpoints or forward live orders. | Verify `WPS_PEPITA_ORDER_API_KEY` in `wp-config.php` is a dedicated staging key. |

### 5.2 Server & Runtime Prerequisites

* **PHP Version**: PHP 8.1, 8.2, 8.3, 8.4, or 8.5 (PHP 8.5 recommended).
* **Required PHP Extensions**:
  - `ext-dom` and `ext-libxml`: Required for XML feed generation and validation.
  - `ext-json`: Required for webhook payload parsing and redaction.
  - `ext-mbstring`: Required for character encoding and Unicode sanitization.
  - `ext-openssl`: Required for cryptographic token generation and payload hashing.
* **Database**: MySQL 8.0+ / MariaDB 10.5+ with `utf8mb4` support.
* **WordPress & WooCommerce**:
  - WordPress: 6.4+ (tested on 6.8 / 7.1.4).
  - WooCommerce: 8.0+ (tested on 9.0+, 10.0, 11.3.0).
  - HPOS: Compatible with High-Performance Order Storage in both authoritative and legacy modes.
* **Web Server Configuration**:
  - **Apache**: `.htaccess` with `AllowOverride All` enabled to protect `wp-content/uploads/wp-pepita-sync-storage/`.
  - **Nginx**: Explicit block rule must be configured in server block:
    ```nginx
    location ^~ /wp-content/uploads/wp-pepita-sync-storage/ {
        deny all;
        return 404;
    }
    ```
* **Memory Limit**: `WP_MEMORY_LIMIT` recommended minimum 256M (512M recommended for large catalog generation).
* **Cron Execution**: Real system cron recommended over WP-Cron:
  ```bash
  */10 * * * * php /var/www/html/wp-cron.php >/dev/null 2>&1
  ```

---

## 6. Pepita Integration Readiness & Partner Team Inquiries

### 6.1 Integration Architecture
* **Product Feed URL**: `https://staging.example.com/?wps_feed=products&token=<32-char-token>`
* **Stock Feed URL**: `https://staging.example.com/?wps_feed=stock&token=<32-char-token>`
* **Order Webhook Endpoint**: `https://staging.example.com/wp-json/wps/v1/pepita/order?apikey=<32-char-key>`
* **Authentication**: Token query parameter for feeds; query or `X-API-KEY` header for order webhooks.
* **Feed Caching Policy**: `Cache-Control: private, no-store, max-age=0` (never served by shared proxies).

### 6.2 Formal Technical Inquiry for Pepita Partner Team
*(Note: Per security guidelines, this technical inquiry is prepared for store owners to transmit. No external communications have been sent autonomously.)*

```text
Tisztelt Pepita Integrációs Csapat!

A WooCommerce — Pepita piactér szinkronizációs modul (WP Pepita Sync 1.1.0) élesítési
előkészítésének (M9 Staging) keretében szeretnénk tisztázni az alábbi technikai kérdéseket
a staging tesztelés megkezdése előtt:

1. Életciklus és rendelésmódosítások (NEW-2 feloldása):
   Amennyiben egy korábban sikeresen átadott rendelés azonosítójával új webhook értesítés
   érkezik eltérő tartalommal (pl. rendelési státusz változás, törölt tétel vagy vevői
   adat módosulás), a Pepita milyen HTTP válaszkódot és magatartást vár el a partnertől?
   Jelenleg a rendszerünk pénzügyi integritásvédelmi okokból HTTP 409 Conflict választ
   ad és nem írja felül a már létező WooCommerce rendelést. Támogatott-e külön dedikált
   státuszfrissítő webhook végpont, vagy az azonos azonosítójú hívásokat állapotgép szerint
   kell értelmezni?

2. Partner Staging / Sandbox környezet:
   Rendelkezik-e a Pepita publikus vagy partner-specifikus sandbox/teszt környezettel
   (Merchant Center Sandbox), ahová a staging feedek beköthetők és ahonnan szintetikus
   teszt rendelések (webhook trigger) indíthatók?

3. Webhook forrás IP tartományok (IP Allowlist):
   Rendelkezik-e a Pepita hivatalos, publikált kimenő IP-cím tartománnyal a rendelési
   webhookokhoz, amely alapján az engedélyezési lista (IP allowlist) biztonságosan
   beállítható?

4. Adómentes vevők és számlázás (0% ÁFA):
   Hogyan jelöli a Pepita az alanyi adómentes (AAM) vagy nemzetközi adómentes vevők
   rendeléseit a payloadban (pl. termék `vat` mező értéke 0 vagy hiányzik)?

5. Csomagcímke generálás és futárszolgálat:
   Milyen formátumban és időzítéssel érkezik a csomagcímke (PDF base64), és várható-e
   a jövőben olyan szállítási mód, ahol a partner köteles saját fuvarlevelet generálni?

Válaszukat és a technikai egyeztetést előre is köszönjük!
```

---

## 7. Dry-Run Acceptance Test Plan

Prior to any production consideration, the staging team will execute the following 11 synthetic acceptance test scenarios on the isolated staging environment:

| ID | Test Scenario | Execution Steps | Expected Outcome |
|---|---|---|---|
| **ST-01** | **Clean Installation & Migration** | Upload `wp-pepita-sync.zip` to staging WP admin, activate plugin. | Options initialized with defaults; DB tables `wps_pepita_order_claims` and `wps_pepita_logs` created with collation `utf8mb4_bin`; collation check flag recorded. |
| **ST-02** | **Full Product Feed Generation** | Trigger product feed generation via admin UI or `wp pepita feed generate products`. | Complete XML feed generated in private storage; validates cleanly against Pepita XSD; UTF-8 sanitization strips noncharacters. |
| **ST-03** | **Stock Feed Generation** | Trigger stock feed generation with full catalog. | Stock XML feed generated; reflects current stock and prices; executes in lightweight bounded memory. |
| **ST-04** | **Tax-Aware Order Intake** | POST synthetic paid order webhook with 27% VAT products, shipping, and discount voucher. | Order created in WooCommerce; exact gross total preserved; tax lines partitioned via standard `WC_Order_Item_Tax`; package label PDF saved. |
| **ST-05** | **Zero-VAT / Exempt Order Intake** | POST synthetic order with 0% VAT products. | Order created with zero tax lines; total matches gross sum exactly; no 422 failure. |
| **ST-06** | **Unpaid COD Order Intake** | POST synthetic cash-on-delivery order. | Order created with status `on-hold`; no payment date stamped; customer/courier notes recorded. |
| **ST-07** | **Identical Duplicate Replay** | Deliver the exact same order webhook twice. | Returns HTTP 200 duplicate; returns original order ID; no duplicate order created; claim attempts count preserved. |
| **ST-08** | **Altered Duplicate Conflict** | Deliver the same order ID with altered product price or quantity. | Returns HTTP 409 Conflict; original order untouched; operator note added to order; warning logged with `changed_payload_refused`. |
| **ST-09** | **Interrupted Crash Recovery** | Simulate crash by creating an incomplete order shell with `_wps_pepita_in_progress=1`, make claim lease stale, then replay webhook. | Incomplete shell is purged from WooCommerce; exactly 1 complete order created; returns HTTP 200. |
| **ST-10** | **Maintenance Cron & Retention** | Run maintenance job `wp pepita maintenance run` or WP-Cron. | Purges expired feed locks, deletes raw payloads older than retention, purges package labels for closed orders. |
| **ST-11** | **Rollback & Uninstall** | Deactivate plugin; verify data preserved; reactivate and verify seamless resume. | Deactivation leaves tables and orders intact. Reactivation resumes immediately without migration error. |

---

## 8. Rollback & Contingency Plan

If any critical defect or operational instability occurs during staging validation:

1. **Deactivation**: Deactivate "WP Pepita Sync" in WP Admin (`wp-admin/plugins.php`). Deactivation safely unregisters hooks and stops scheduled cron tasks without dropping orders or claim records.
2. **Database Reversion**:
   - The plugin does not alter standard WooCommerce core tables other than adding standard order meta (`_wps_pepita_*`).
   - If rolling back to version 1.0.0, the `wps_pepita_order_claims` table with `utf8mb4_bin` collation is fully backward-compatible with 1.0.0 queries.
3. **Storage Directory Cleanup**: If temporary generated feeds are corrupted, purge `wp-content/uploads/wp-pepita-sync-storage/temp/` manually.
4. **Order Integrity Guarantee**: WooCommerce orders already created remain valid shop orders. No customer data or financial totals are modified by rollback.

---

## 9. Final Decision & Gate Status

* **Staging Gate**: **GO (APPROVED)**
  - All four security and financial integrity risks are closed and verified with automated regression and integration suites.
  - Reproducible, secret-free distribution package `dist/wp-pepita-sync.zip` (SHA-256: `0fefdff825c3b1199ef222d28fa39790e798c993b4d5d4a04b8dd80beb5f6b1d`) is prepared and verified.
  - CI verification is 100% green across all supported PHP versions and HPOS modes.
  - Independent review by Mad-Eye Moody confirms staging readiness.

* **Production Gate**: **STRICT NO-GO (HOLD)**
  - No code may be deployed to production.
  - No release tag or release announcement may be published.
  - Production hold remains active until staging dry-run acceptance tests (ST-01 through ST-11) pass and Pepita partner team inquiries are answered.
