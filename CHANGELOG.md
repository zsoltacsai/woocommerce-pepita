# CHANGELOG — WP Pepita Sync

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed (RR20 - concurrent import takeover; found by a two-worker test)
- An import worker that stalled past the 15-minute lease no longer lets a second worker adopt and
  acknowledge its half-built order (HTTP 200) while the first worker later deletes it. The in-progress
  marker is now cleared only by the final, lease-verified save; any unfinalized marker order is rebuilt.
- A taken-over worker can no longer write to a discarded order (save fence; also discards the pending
  WooCommerce status transition, which WooCommerce 11 runs even when `save()` swallows an exception).
- A persisted, finalized order is never deleted by the error path; a swallowed save failure is never
  reported as an imported order; stock is returned before an unfinished order is discarded.
- Replays of a completed claim are acknowledged only if the WooCommerce order exists and is finalized,
  otherwise HTTP 500 with a log entry. New: `wp wps-pepita repair-claim <external_id> [--force]`.
- Uninstall with data removal now also removes plugin order meta on HPOS stores.

### Known residual risks
- Between the fence check and the data-store write there is a single-statement window (no row lock).
- Item rows are written outside the order save and are not fenced; a fenced worker may leave a label PDF.
- A merchant who permanently deletes an imported order gets HTTP 500 on later Pepita replays until the
  claim is repaired/acknowledged (previously a silent 200).
- Swallowed-save detection uses `WC_Order::get_last_save_error()` (WooCommerce >= 11.2.2).

### Build
- Byte-reproducible ZIP (`bin/release-epoch`, `bin/verify-reproducible.sh`, checked in CI).

## [Unreleased]

### Security — audit remediation (1.1.0)

Remediation of the independent security audit (CF-01–CF-13, PV-01–PV-12). No
CRITICAL or HIGH findings were confirmed; the highest confirmed severity was
MEDIUM. Production order intake remains blocked until the live integration suite
and the M9 Pepita staging validation pass.

#### Fixed (confirmed MEDIUM)

- **CF-01** — IP allowlist and rate limiter no longer trust client-supplied
  `X-Forwarded-For` / `CF-Connecting-IP` / `X-Real-IP`. Forwarding headers are
  honoured only when the direct peer is in the new `order_api_trusted_proxies`
  list; the chain is walked from the right and the first untrusted hop is used.
- **CF-02** — the HTTPS requirement is checked **before** the key comparison, and
  `X-Forwarded-Proto: https` is accepted only from a trusted proxy. Plaintext
  requests never reach the credential comparison.
- **CF-03** — failed authentication is throttled (30/min/client) before the key
  comparison, and rejection logging is bounded to one row per client/reason/minute
  with a global 120 rows/minute budget.
- **CF-04** — price, shipping, voucher, VAT and quantity are parsed by a new
  strict `Money` parser. Locale-formatted strings (`"4,990"`, `"1 490"`,
  `"12,5"`) are rejected with 422 instead of being truncated to a near-zero
  amount; they never create an order.
- **CF-05** — `order_api_keep_payloads` is now honoured. Raw payloads (customer
  PII, base64 labels) are stored only when explicitly enabled; by default only
  the SHA-256 hash is kept. Disabling the setting purges stored payloads
  immediately and on the maintenance schedule.
- **CF-06** — package-label PDFs are expired by the maintenance job after
  `order_api_label_retention_days` (default 30). Labels of pending, processing
  and on-hold orders are always retained.

#### Fixed (additional)

- **CF-07** — a SKU and Pepita product id that resolve to different products are
  rejected instead of silently preferring one.
- **CF-08** — a failed order save cleans up the half-built pending order, so a
  retry cannot create a duplicate.
- **CF-09** — reclaiming an expired feed lock is serialised with an atomic option
  mutex; `is_locked()` is now read-only and never deletes a fresh lock.
- **CF-10** — a duplicate delivery whose payload hash differs now returns 409 and
  never rewrites the stored order. (Behaviour change: was 200.)
- **CF-11** — an imported order is never linked to an account that can manage the
  store (`manage_woocommerce` / `edit_users`); such orders stay guest orders.
- **CF-12** — documentation (`SECURITY-REPORT.md`, `PEPITA-API-INTEGRATION.md`,
  `TESTING.md`, `INSTALLATION.md`) corrected to match the implemented controls.
- **CF-13** — package labels must decode to a real PDF (`%PDF-` magic);
  integration Compose ports are bound to `127.0.0.1`.
- **PV-02** — Pepita prices are treated as gross. The order sets
  `prices_include_tax`, recalculates with `calculate_totals( false )`, clears
  cart/shipping tax and stores the validated gross total, so a tax-enabled store
  does not add a second VAT layer. New integration case `08-tax.php` covers
  `prices_include_tax` `yes` and `no`.
- **PV-03** — `external_id` now uses the `utf8mb4_bin` collation, so `PEP-1` and
  `pep-1` are distinct. An upgrade migration alters the existing column; the DB
  version is bumped to `1.1.0`.
- **PV-04** — nginx deny rule for the private storage directory documented in
  `INSTALLATION.md`.
- **PV-05** — `Xml_Writer::clean()` also strips Unicode noncharacters
  (U+FDD0–FDEF and the last two code points of every plane).
- **PV-07** — token-authenticated feed responses send `Cache-Control: private,
  no-store, max-age=0`; only an explicitly public feed is cacheable.
- **PV-09** — `Redactor` masks customer name, e-mail, phone, address, city,
  postcode, tax number and customer/courier messages before a payload reaches the
  log table.

#### Changed

- New defaults: `order_api_keep_payloads=false`, `order_api_require_https=true`,
  `order_api_trusted_proxies=''`, `order_api_label_retention_days=30`.
- `process_claim` stale window raised from 10 to 15 minutes.
- `dist/wp-pepita-sync.zip` rebuilt so the tracked artifact matches the 1.1.0
  source; `uninstall.php` also removes `wps_pepita_collation_ok`.
- Unit suite: 133 → 156 cases. New files `115-money-test.php`,
  `116-redactor-test.php`, `150-retention-test.php`; adversarial cases added to
  the API, import, repository, XML and feed-generation suites.

#### Fixed (independent re-review follow-ups)

- Redact `billing_street_address` / `shipping_street_address` in logs.
- Run the `utf8mb4_bin` migration on activation, record the outcome and retry a
  failed ALTER on later requests instead of silently stamping the new version.
- Fail closed when a forwarding hop is unparseable; normalise `ip:port`;
  reject `/0` trusted-proxy entries.
- Round line and grand totals to cents.
- Only release package labels for known-closed order statuses; custom statuses
  are treated as open.

#### Resolved in 1.1.0 update

- **NEW-1** (tax representation) — full tax-aware order creation implemented.
  Pepita gross totals remain authoritative. In tax-enabled stores, gross prices
  are partitioned into net totals and tax rows based on configured WooCommerce
  tax rates. `WC_Order_Item_Tax` lines, taxable shipping, and zero-tax/exempt orders
  are fully supported. Incompatible VAT values fail safely with HTTP 422.
- **NEW-2** (changed-content duplicates) — non-destructive duplicate handling.
  Different-payload duplicates return 409 Conflict, preserve original orders,
  log redacted reconciliation events, and record operator order notes in WooCommerce.
  Clarification questions for Pepita lifecycle contract documented.

#### M9 Risk Closure (Staging Readiness)
- **Crash Recovery & Orphan Order Prevention**: Immediate order ID association on claim (`Order_Repository::associate()`) and metadata write (`save_meta_data()`) upon `wc_create_order()`. Incomplete shells left by unexpected mid-creation process termination are detected (`is_order_complete()`), purged, and cleanly recreated, completely eliminating duplicate or orphan shell orders.
- **Historical Order Replay Protection**: Replays of pre-1.1.0 orders lacking `_wps_pepita_payload_hash` are validated against invariant business fields (currency, total amount, line item count). Matching replays safely backfill the hash in WooCommerce metadata and claims table (returning 200); altered replays are rejected with 409 Conflict, an operator note, and warning log.
- **Concurrent & Process Termination Safety**: Claims transitions verified under concurrency and process failure, ensuring strict idempotency and zero duplicate creation.
- **Deterministic Test Suites**: Added comprehensive unit suite `tests/cases/106-recovery-and-replay-test.php` (6 tests) and real integration test suite in `integration/tests/cases/07-idempotency.php`.

#### Deferred / accepted residual risk
- **PV-06** (`manage_woocommerce` includes Shop Manager) — accepted as a
  deliberate store-operator role.
- **PV-08** (stale-claim overlap for imports longer than the 15-minute window) —
  accepted; a heartbeat is a possible future hardening.
- **PV-10** (partially mitigated by CF-03/CF-07), **PV-11** (key holder can
  backdate; accepted under the Pepita trust model), **PV-12** (public feed mode
  remains opt-in), and image-digest pinning for CF-13 — documented as residual.

### Added (M8)

- Local Docker Compose integration suite: two WordPress 7.1.4 / PHP 8.5 /
  MySQL 8.4 / WooCommerce 11.3.0 stores, one with HPOS and one with legacy order
  storage. `integration/scripts/run.sh` re-seeds both and runs 60 cases per store.
  Both stores passed on the M8 revision (60/60 each); this remediation revision
  adds `08-tax.php` and extends `07-idempotency.php` and `02-schema.php`, and has
  **not** been re-run (Docker unavailable in the remediation environment).

### Fixed (M8)

- Order notes are now added after the order is saved, so they are not lost on a
  freshly created order.
- Non-taxonomy product attribute names are sanitised with `sanitize_title()`
  before being used as XML attribute keys.
- The crash-recovery order lookup by external id passes `cache_results => false`
  so a cached empty result cannot cause a duplicate order.
- Integration harness corrections for WooCommerce 11 (`limit => -1` with a type
  filter, order-note limits), the WP HTTP API `-gzip` ETag suffix, WP-Cron lock
  semantics and Action Scheduler's per-process time budget.

## [1.0.0] — 2026-11-12

Initial release. Feature-complete and covered by the bundled test suite; live
WordPress/WooCommerce staging validation is still required before production use
(see `DEVELOPMENT-STATUS.md`).

### Added

**Catalog and feed**

- Pepita XML feed generation with namespace `https://pepita.hu/feed/1.0` and the
  documented element order.
- Simple and variable products, up to three variation axes, per-variation prices,
  stock and images.
- Gross pricing, VAT from WooCommerce tax classes or a fixed rate, sale prices,
  purchase net price, shipping price, warranty, GTIN, MPN, brand, manufacturer,
  attributes, dimensions, multiple category paths and galleries.
- Stable `wps-{id}` export identifiers persisted as `_wps_export_id`; never
  derived from SKU.
- Separate stock/price feed that is a complete snapshot and shares IDs and
  variation values with the full feed.
- Signature check that refuses to publish a stock feed inconsistent with the
  last full feed.
- Resumable, sliced generation with a plan file, cursor, time budget and
  scheduled continuations.
- Atomic publish with the previous feed kept as a snapshot; failed or empty
  generations never replace the last good feed.
- Removal policies `omit` and `zero_stock` with a configurable grace period.
- Whole-file validation: well-formedness, root element, namespace, duplicate IDs,
  product-count consistency.

**Selection and rules**

- Priority resolution: product exclusion → product inclusion → deepest category
  rule → global default.
- Category inheritance for inclusion, exclusion, price adjustment, shipping delay
  and GTIN requirement.
- Per-product overrides: inclusion, title, description, GTIN, brand, manufacturer,
  MPN, price adjustment, shipping delay, shipping price, warranty, video links,
  restock date.
- Bulk inclusion/exclusion from the product list.

**Orders**

- `POST /wp-json/wps/v1/pepita/order` with shared-secret authentication via query
  parameter or header.
- Optional IP allowlist, HTTPS enforcement and per-IP rate limiting.
- Request size limit, strict JSON parsing and full payload validation.
- Durable idempotency through `{prefix}wps_pepita_orders` with a UNIQUE
  `external_id`; duplicate and concurrent requests are safe.
- Crash recovery by adopting an existing order that carries the Pepita order ID.
- WooCommerce CRUD order creation: line items, shipping line, voucher fee,
  billing/shipping addresses, tax number, customer matching, totals.
- Payment and delivery mode mapping with Hungarian labels.
- Paid status and `date_paid` only for `payment_status: "paid"`.
- Customer and courier messages as notes and meta.
- Package labels decoded from base64 and stored as PDFs in the private directory.
- All customer e-mails suppressed during import; no gateway execution.

**Admin**

- Hungarian admin menu with eight screens: dashboard, products, categories,
  feeds, orders, API, settings, logs.
- Dashboard health checks: WooCommerce, HPOS, PHP, storage, tokens, HTTPS,
  scheduler, feed age, lock state.
- Feed status, counters, error list and manual generation with chunked AJAX
  polling and a non-JavaScript fallback.
- Token and API key display, regeneration and `wp-config.php` constant detection.
- Product metabox, product list columns and filters, category rule fields.
- Log viewer with level filter, search, retention and purge.

**Platform**

- HPOS compatibility declared through the official WooCommerce API.
- Action Scheduler integration with a WP-Cron fallback.
- Change detection on product, stock, category and import events with a debounced
  queue.
- WP-CLI: `status`, `generate`, `orders`, `purge-logs`.
- Uninstall with opt-in data removal.
- 133 automated tests and a reproducible ZIP build.

### Security

- Constant-time secret comparison, private salted storage, deny rules, prepared
  SQL, nonce and capability checks on every admin action, log redaction, strict
  label handling, and no direct writes to core tables.

### Known limitations

- Only simple and variable products are exported.
- Variable parent lines in orders are rejected because the payload does not
  identify the variation.
- Live WordPress/WooCommerce staging validation is pending.

[1.0.0]: https://github.com/zsoltacsai/woocommerce-pepita/releases/tag/v1.0.0
[Unreleased]: https://github.com/zsoltacsai/woocommerce-pepita/compare/v1.0.0...HEAD
