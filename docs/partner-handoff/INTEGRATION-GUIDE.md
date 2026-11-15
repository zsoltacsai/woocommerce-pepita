# WP Pepita Sync — Partner & Merchant Integration Guide

**Plugin version (header of `woocommerce-pepita.php`):** 1.1.0 — M11 merchant-automation branch (release candidate, **not released to production**)
**Requirements (plugin header):** WordPress ≥ 6.4, PHP ≥ 8.1, WooCommerce ≥ 8.2 (HPOS-oriented order code; a live HPOS/legacy matrix has **not** been executed in this documentation environment)
**Security posture:** repository operations are isolated; admin screens require `manage_woocommerce` and nonces for state-changing actions.

> Documentation-only handoff. Statements here are derived from the plugin code and its test suite
> (`tests/cases/`). Nothing in this document was executed against a live Pepita API, production or
> staging system. Where the code does not define behavior, the text says **"verify with Pepita"**.

---

## 1. System overview & architecture

WP Pepita Sync connects a WooCommerce store to the Pepita.hu marketplace: XML product feeds (full and stock/price), image compliance handling, an order-intake REST endpoint, and operational reporting.

```
[ WooCommerce Store ] ---- XML feeds (full / stock) --------> [ Pepita catalog import ]
[ WooCommerce Store ] <--- POST /wp-json/wps/v1/pepita/order - [ Pepita order notification ]
```

### Core architecture points

- **Streaming XML feeds**: generated in bounded chunks; published atomically with a generation pointer (`includes/class-storage.php`).
- **Safety publishing gate (opt-in)**: a catalog diff (drop ≥ `feed_safety_drop_threshold`, default 20 %, emptied feed, drastic price drops, variation changes, mass quality exclusions) is always computed and logged. It **blocks** publication only if `feed_safety_block_publication` is on and **holds the feed for approval** only if `feed_safety_require_approval` is on — **both default to off** (`includes/class-settings.php:90-92`, `includes/feed/class-generator.php:648-720`).
- **Order claim ledger**: orders are claimed in `{$wpdb->prefix}wps_pepita_orders` (unique `external_id`) so a repeated notification never creates a second WooCommerce order; a changed payload under the same id is refused. See [API-CONTRACT.md §6](API-CONTRACT.md#6-idempotency-duplicates-and-conflicting-replays).
- **Crash-safe merchant profiles**: portable, secret-free settings export/import with a recovery journal.
- **Fail-closed operational reporting**: exports and reports **do not claim to be "PII-free"**. They are built so that no raw free-text errors are ever exported: errors are classified into fixed codes with predefined explanations; untrusted identifiers (`external_id`, `origin`) are exported only if they match a strict safe pattern, otherwise as a salted hash / `other`; CSV cells are formula-neutralised; queries are bounded. Raw error text remains only in the per-order admin view and logs (access: `manage_woocommerce`). Merchants should still treat exports as business-confidential data.

---

## 2. Catalog & feed synchronization

### 2.1 Feed types and lifecycle

Feeds are served from `home_url('/?wps_feed=full|stock&token=<feed_token>')` (`Settings::feed_url()`); the token is required unless `feed_public` is on (default **off**).

1. **Full feed (`full`)** — complete product data: identifiers, titles, descriptions, categories, attributes, prices, stock, image URLs, variations. Interval setting `full_feed_interval` (hours, default 24, range 1–168).
2. **Stock/price feed (`stock`)** — lighter feed with price and availability. Interval setting `stock_feed_interval` (minutes, default 30, range 5–1440).
3. **Full/stock consistency invariant (code-enforced):** the stock feed is **not published** if its catalog signature differs from the last successful full feed, or if no full feed has succeeded yet; the generator then marks the full feed dirty and reports that a full regeneration is required (`includes/feed/class-generator.php:602-620`; the served stock file is also withheld on a signature mismatch, `includes/class-storage.php:284-290`). Operationally: after any catalog change that adds/removes products, **upload/refresh the full feed in Pepita before the stock feed**, and never import a stock feed generated from a different catalog state than the full feed Pepita currently has.
4. Feed endpoint answers `404` unknown feed, `403` wrong token, `503` + `Retry-After: 300` when not yet generated / emergency-disabled (`includes/feed/class-endpoint.php`).

### 2.2 Image compliance

- Pepita specification used by the plugin: JPEG/PNG, at least **1200×630 px** (`Image_Converter::MIN_WIDTH/MIN_HEIGHT`).
- WebP sources are converted to JPEG derivatives under `wp-content/uploads/wps-derivatives/` (GD or Imagick); small images are **not** upscaled — they raise the non-blocking warning `insufficient_image_dimensions` (see the "Hibás termékek" and "Feed ellenőrzés" screens).
- Products without any usable image are excluded (`missing_image`).
- Truncated/undecodable files are rejected (`invalid_image_file`).

### 2.3 Category rules and attribute mapping

- Subcategories inherit parent rules unless overridden; percentage/fixed price adjustments per category (`Category_Rules::price_adjustment()`).
- Category rules in merchant profiles are exported/imported by **term slug**, not numeric ID.
- Pepita expects exactly one `<Categories>` hierarchy per product; the plugin chooses the primary category via product override → Yoast/Rank Math primary → strategy (`deepest` default) — see `docs/m9d/FNDECO-REAL-CATALOG-VALIDATION.md` §1.2.

---

## 3. Order processing (REST endpoint)

> The authoritative contract — URL, authentication, payload fields, responses, status codes, duplicate/replay behavior and rate limits — is **[API-CONTRACT.md](API-CONTRACT.md)**. Error reference: **[ERROR-CODES.md](ERROR-CODES.md)**.

### 3.1 Summary of the flow (as implemented)

1. Pepita sends `POST /wp-json/wps/v1/pepita/order` over HTTPS with the shared key in the `apikey` query parameter (or the `X-WPS-Api-Key` / `X-Api-Key` header). There is **no** Bearer token and **no** payload signature.
2. The handler checks, in order: beta mode (503), endpoint switched off (503), HTTPS (403), failed-auth throttle (429), key and optional IP allowlist (401), request rate limit (429), body size (413), body/JSON (400).
3. The importer claims the Pepita `id` in `wps_pepita_orders`, validates the payload (`products`, `customer.email`, prices, VAT, quantities, modes), resolves every line to a published simple WooCommerce product by SKU and/or Pepita id, and only then creates the order (all-or-nothing).
4. Success and duplicate replays both return HTTP `200` with `{"isError":false,"responseCode":200,"message":"…","orderId":<WC id>}`; a replay with a different payload returns `409` and leaves the order untouched; validation failures return `422`.
5. Response bodies always use `isError` / `responseCode` / `message` (/ `orderId`). There are no `success`, `duplicate` or symbolic `code` fields.

### 3.2 Label storage

A valid `package_label` (base64 PDF, ≤ 8 MB, starting with `%PDF-`) is saved under the plugin's private storage directory in `labels/` (`wp-content/uploads/wps-pepita-<storage key>/labels/`, `Storage::label_dir()`); an invalid label never fails the order. Retention setting: `order_api_label_retention_days` (default 30). The admin download handler is restricted to `manage_woocommerce`.

---

## 4. Merchant configuration profiles & automation

### 4.1 Export & portability

- Exports portable settings and category rules to JSON, filtered through `PORTABLE_SETTINGS_ALLOWLIST`. Secrets (`feed_token`, `order_api_key`, `storage_key`, alert URLs/e-mails) and security toggles (including `order_api_enabled`, `order_api_require_https`, `feed_public`, `beta_feed_only`) are **excluded**.
- A canonical SHA-256 `checksum` is included for integrity verification.

### 4.2 Two-step safe import

1. **Upload & staging** — the JSON is validated and staged; no setting or term meta is changed yet.
2. **Preview** — current vs. proposed values and checksum status are shown.
3. **Explicit confirmation** — requires nonce and capability.
4. **Crash safety** — a recovery journal is written before any write; failures roll back; an interrupted import is recovered on the next admin load.

Importing a profile can **never** switch the Order API on, because `order_api_enabled` is not portable.

---

## 5. Security & operational hardening

| Domain | Control | Implementation |
|---|---|---|
| Access control | Admin capability | `manage_woocommerce` on admin screens, AJAX actions and download handlers. |
| CSRF | Nonces | State-changing admin actions use nonces. |
| Order API auth | Shared key + optional IP allowlist | `hash_equals`, transport checked before the key is read, failed-auth throttle (default 30/min/client) and request limit (default 120/min/client), body-size cap (default 512 KB). |
| Data protection | Logging/reporting | Logs use `Redactor`; operational reports/exports contain **fixed error codes only** (no raw free-text errors) and hashed untrusted identifiers. |
| CSV | Formula injection | `Logger::sanitize_csv_field()` neutralises cells starting with `=`, `+`, `-`, `@`, tab, CR. |
| Resource bounds | Chunked queries, bounded buffers | Reports are limited to 1000 rows (`Operational_Reports::MAX_EXPORT_ROWS`). |

---

## 6. Documented limits

These limits describe what is **not** proven or **not** permitted yet. They override any optimistic reading of the rest of this guide.

### 6.1 Order API — live traffic is NOT approved

- **The order API must stay disabled for live (production) traffic.** Production/live order intake is a NO-GO until a separate, explicit acceptance is recorded.
- **Important:** the code default is `order_api_enabled = true` and a 48-character `order_api_key` is generated automatically on activation (`includes/class-settings.php:116`, `ensure_tokens()` at `:269`, `class-installer.php:22`). A fresh install therefore **accepts orders for anyone who knows the key**. The operator must explicitly switch it off (*Pepita → Beállítások*: untick the Order API checkbox) **or** enable feed-only beta mode (setting `beta_feed_only` or constant `WPS_PEPITA_BETA_FEED_ONLY`), which forces the endpoint to answer `503` and cannot be re-enabled through a profile import.
- **Test orders only against a staging/sandbox store, with synthetic data** (placeholder customers on `example.test`, synthetic SKUs, throw-away key). Never post real customer data, never point a live Pepita dispatcher at a test store, and never run these tests on production. No live Pepita API call was made while producing this documentation.
- The request examples in the contract were **verified by reading the importer validation code; they were not executed** (PHP is unavailable here). Run the staging checklist before relying on them.
- Not defined by the plugin — **verify with Pepita**: Pepita's actual order payload specification and field guarantees, retry/backoff behavior on `409`/`5xx`, how order amendments and cancellations are communicated, and whether Pepita supports header-based key delivery instead of the `apikey` query parameter.
- Prices in the payload are interpreted as **gross**. When WooCommerce taxes are enabled the line `vat` must equal the configured WooCommerce rate or the order is rejected (`422`). Order currency is **not** compared with the store currency by the code — verify manually.

### 6.2 XML feed — validate with real, non-staging URLs before Pepita import

- Feeds generated on a staging host contain **staging product URLs and image URLs**. Before importing a feed into Pepita, generate and inspect a feed whose product URLs and image URLs are the **real public (non-staging) URLs** of the live shop, and confirm that the image URLs are publicly reachable (HTTP 200, JPEG/PNG, ≥ 1200×630). Staging-hosted or non-public URLs must not be sent to Pepita.
- A feed that passes the plugin's own validators and the repository XML-contract tests is **not** proof that Pepita accepts it. Pepita's own import result is the acceptance criterion (**verify with Pepita**).
- The "269 products → 241 exportable / 28 excluded" numbers in older drafts are **synthetic fixture figures**, not verified results of a real FN-DECO staging export (`docs/autonomous-development/PEPITA-HANDOFF.md`). Do not quote them as evidence.

### 6.3 GTIN requirements and category mapping

- The plugin **never generates or guesses a GTIN.** A GTIN is exported only when the stored value contains 8, 12, 13 or 14 digits (`Description_Sanitizer::gtin()`, `Validator`: pattern `^\d{8}$|^\d{12,14}$`); there is no check-digit validation in the code. The SKU is never used as a GTIN.
- A category can be flagged "GTIN required" (`gtin_required_categories` / category setting). Products in such a category without a valid GTIN get the blocking error `gtin_required` and are not exported. Which Pepita categories actually require GTINs is **a Pepita rule — verify with Pepita** and mirror it in the category settings.
- Each exported product has exactly one category hierarchy. Pepita category mapping/acceptance of each hierarchy must be confirmed by Pepita; the plugin cannot verify that a category exists on Pepita's side. In beta mode only explicitly included categories are exported.
- Pricing mode (`price_tax_mode`: `auto`/`gross`/`net`) must match how prices are stored in WooCommerce, otherwise prices are double-taxed or under-stated (see `docs/m9d/FNDECO-REAL-CATALOG-VALIDATION.md` §1.3).

### 6.4 Pepita import-error reconciliation process

The plugin cannot read Pepita's import results; reconciliation is a manual loop:

1. Export or copy the error list from Pepita's import report for the feed run (**verify with Pepita** which report/format Pepita provides).
2. Match each Pepita error to the product via the exported product identifier (`_wps_export_id`, `wps-<id>`) and SKU/`ProductNumber`.
3. Compare with *Pepita → Hibás termékek* and *Feed ellenőrzés* (CSV export available there): if the plugin already excluded the product, fix the listed issue; if the plugin exported it but Pepita rejected it, treat it as a **gap between plugin validation and Pepita rules** and record it (do not "fix" by editing the XML by hand).
4. Fix the data in WooCommerce, regenerate the **full** feed, re-import into Pepita, then regenerate the stock feed.
5. Repeat until Pepita's rejected count and the plugin's excluded count are both explained product by product. Keep the evidence (counts, dates, feed generation IDs) outside the plugin.

### 6.5 Full / stock feed consistency

- The stock feed is refused when it does not match the last published full feed (see §2.1). A "stock" import must never be used to add or remove products.
- Pepita must be importing the **same generation** of both files; after any catalog change import the full feed first.
- Safety gates (shrinkage, price drops, quality exclusions) only warn by default; enable `feed_safety_block_publication` and/or `feed_safety_require_approval` if automatic publication of a drastically changed catalog is not acceptable. This is a configuration decision for the merchant, not a default.

### 6.6 Reports are not "PII-free"

Operational summaries/exports intentionally exclude raw free-text errors and hash untrusted identifiers, but they still contain order identifiers (when they match the safe pattern), WooCommerce order IDs, timestamps, status and attempt counts. They are business data: restrict access to authorised staff (`manage_woocommerce`) and do not describe them to partners as "PII-free".

### 6.7 Release artifact

The ZIP checksum is **not** recorded in these documents. Take the SHA-256 from `dist/wp-pepita-sync.zip.sha256` produced for the **exact release commit** (`bin/build.sh`; reproducibility check: `bin/verify-reproducible.sh`) and compare it with the file actually uploaded.
