# CHANGELOG — WP Pepita Sync

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Local Docker Compose integration suite: two WordPress 7.1.4 / PHP 8.5 /
  MySQL 8.4 / WooCommerce 11.3.0 stores, one with HPOS and one with legacy order
  storage. `integration/scripts/run.sh` re-seeds both and runs 60 cases per store.
  Both stores pass: 60 passed, 0 failed.

### Fixed

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
