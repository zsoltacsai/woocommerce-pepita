# DEVELOPMENT-STATUS — WP Pepita Sync

Last updated: 2026-11-13
Version: 1.0.0
Overall state: **feature-complete, unit- and integration-tested locally, live Pepita staging validation pending**

## 1. Milestones

| # | Milestone | Status |
|---|---|---|
| M1 | Discovery: Pepita docs, XML samples, order API PDF, Allegro reference | ✅ Complete |
| M2 | Foundation: bootstrap, settings, storage, installer, logger, catalog layer | ✅ Complete |
| M3 | Feed: XML writer, generator, lock, index, scheduler, endpoint | ✅ Complete |
| M4 | Orders: repository, importer, API controller, payment/shipping mapper | ✅ Complete |
| M5 | Admin: menu, eight screens, AJAX, metabox, list columns, category fields | ✅ Complete |
| M6 | Tests: harness, 14 case files, 133 cases, defect fixes | ✅ Complete |
| M7 | Documentation and packaging | ✅ Complete |
| M8 | Local Compose integration suite (HPOS + legacy) | ✅ Complete — 60/60 on both stores |
| M9 | Live Pepita staging validation | ⛔ Blocked — needs a seller staging account |
| M9-B | RR20 order finalization fencing and recovery | ✅ Complete |
| M9-C | Feed safety, diff reporting, stock health & secure CSV log export | ✅ Complete |

## 2. What was built

### M1 — Discovery

- Extracted the reference Allegro plugin to `/tmp/opencode/allegro/WP-Allegro-Sync`
  and studied its UX patterns only. No code or symbol was reused.
- Captured the Pepita full-feed sample, stock-feed sample and the full order API
  specification text.
- Recorded the exact XML contract, variation rules, stock-feed semantics and the
  order payload/response shape before writing code.

### M2 — Foundation and catalog

- `woocommerce-pepita.php`, autoloader, `Plugin`, `Installer`, `Settings`,
  `Storage`, `Logger`, `Redactor`, `Health`, `Cli`.
- Catalog: `Category_Rules`, `Product_Selection`, `Price_Calculator`,
  `Description_Sanitizer`, `Product_Mapper`, `Validator`.
- Two custom tables with `dbDelta`, prefixed options and meta.

### M3 — Feed

- `Xml_Writer` (exact element order, namespace on the root only), `Lock`,
  `Catalog_Index`, `Generator` (plan → slices → validate → atomic publish),
  `Scheduler` (Action Scheduler / WP-Cron), `Change_Detector`, `Endpoint`
  (token, ETag, 304, streaming).
- Signature consistency between full and stock feeds; removal policies.

### M4 — Orders

- `Order_Repository` (atomic claim, retry, queries, purge), `Importer`
  (validation, product resolution, CRUD creation, notes, labels, e-mail
  suppression), `Api_Controller` (auth, IP, HTTPS, rate limit, size, responses),
  `Mapper` (payment/delivery).

### M5 — Admin

- Eight Hungarian screens, settings save, feed generation/download, secret
  regeneration, log purge, feed reset, notices, metabox, product list columns and
  filters, category rule fields, AJAX endpoints with nonces.

### M6 — Tests

- Custom runner plus WordPress/WooCommerce stubs, fake `$wpdb`, assertions.
- 14 case files, 133 cases, 0 failures.
- Three real defects found and fixed (see §4).

### M7 — Documentation and packaging

- `PROJECT.md`, `ARCHITECTURE.md`, `BACKLOG.md`, `DEVELOPMENT-STATUS.md`.
- `README.md`, `INSTALLATION.md`, `PEPITA-FEED-MAPPING.md`,
  `PEPITA-API-INTEGRATION.md`, `TESTING.md`, `CHANGELOG.md`,
  `SECURITY-REPORT.md`, `COMPATIBILITY-REPORT.md`.
- `bin/build.sh` and `dist/wp-pepita-sync.zip`.

### M8 — Local Compose integration suite

- `integration/` provisions two independent stores on MySQL 8.4: one with HPOS
  enabled, one on legacy order storage, both on WordPress 7.1.4 / PHP 8.5 /
  WooCommerce 11.3.0.
- `integration/scripts/setup.sh` installs both stores; `run.sh` re-seeds each
  store and runs 60 cases per store through WP-CLI, writing the output to
  `integration/results/`.
- The cases exercise real HTTP (feed endpoint, ETag/304, auth), the REST order
  route, `dbDelta` schema creation, HPOS and legacy order CRUD, duplicate
  delivery, retry after failure, labels, Action Scheduler and WP-Cron.

### M9-C — Feed safety, diff reporting, stock health and secure CSV export

- **Catalog Difference Reporting** (`WPS\Pepita\Feed\Catalog_Diff`): Line-by-line streaming comparison between the active catalog snapshot and the prior snapshot (`catalog.jsonl` vs `catalog.previous.jsonl`) tracking added, removed, unavailable, price modifications, variation identity modifications, and VAT/currency modifications without high memory usage.
- **Feed Safety Protection**: Configurable drop threshold (`feed_safety_drop_threshold`, default 20%), optional publication blocking (`feed_safety_block_publication`), and variation structure shift alerts (`feed_safety_variation_change_warning`) to prevent accidental emptying of the Pepita marketplace catalog.
- **Stock Feed Health Monitoring** (`WPS\Pepita\Health::stock_feed_check`): Read-only diagnostic check comparing stock feed age against configured interval (OK / WARN / FAIL), alerting on delayed runs or missing cron/Action Scheduler hooks without triggering side-effects during render.
- **Secure CSV Log Export**: Streaming chunked export via `admin_post_wps_pepita_export_logs` guarded by `manage_woocommerce` capability and nonces, with formula injection neutralization (`=, +, -, @, \t, \r` escaping via `Logger::sanitize_csv_field`) and UTF-8 BOM.
- **Declared Minimum Compatibility**: PHP >= 8.1, WordPress >= 6.4, WooCommerce >= 8.2 (HPOS compatible).

## 3. Test results (actual)

Command:

```bash
/tmp/wpcg-php-runtime/usr/bin/php tests/run.php
```

Output:

```
133 passed, 0 failed, 0 skipped in 4.66s
```

Lint:

```bash
find . -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
```

Result: no syntax errors.

Performance (from `130-performance-test.php`, 1000 products):

| Metric | Result |
|---|---|
| Full generation | completed |
| Bounded runs | ≥ 5 (100 products × 2 batches per run) |
| Elapsed | ≈ 0.6 s in the harness |
| Peak memory | ≈ 6 MB |
| Feed size | ≈ 680 KB |
| Stock feed IDs | identical to full feed |
| Duplicates | none |

These are harness numbers. Production timing depends on image count, description
length and tax lookups.

Integration suite:

```bash
sudo -n ./integration/scripts/run.sh
```

| Store | Expected HPOS | Result |
|---|---|---|
| `wordpress-hpos` | yes | 60 passed, 0 failed |
| `wordpress-legacy` | no | 60 passed, 0 failed |

Log: `integration/results/m8-20261113T052704Z.log`. The suite was run twice
consecutively with identical results. Inside the containers the environment
cases confirmed PHP 8.5, MySQL 8.4, WordPress 7.1.4 and WooCommerce 11.3.0.

## 4. Defects found and fixed during testing

1. **Swallowed start errors** — `Generator::run()` returned a generic
   "state could not be created" message instead of the specific `start()` error
   for empty feeds, missing full feeds and lock conflicts. Fixed by returning
   `error`/`skipped` results unchanged.
2. **Wrong HTTP status for product resolution** — `Importer::validate()` nested
   `resolve_products()` errors under `products`, so unknown, variable or
   unpublished products produced 500 instead of 422. Fixed by returning `error`.
3. **Missing payload hash** — the computed SHA-256 was never attached to the
   payload, leaving `_wps_pepita_payload_hash` empty. Fixed with `__hash`.

The integration suite found four further issues, all fixed:

4. **Notes before the order was saved** — order notes were added before
   `save()`, so on a fresh order the note could be lost. Notes are now added
   after the order is saved.
5. **Unsanitised custom attribute keys** — non-taxonomy attribute names were
   used as XML attribute keys verbatim. They now go through `sanitize_title()`.
6. **Stale negative order lookup** — the crash-recovery lookup by external id
   could reuse a cached empty result and create a duplicate order. It now passes
   `cache_results => false`.
7. **Harness-only defects** — the suite also had to correct its own assumptions
   about WooCommerce 11 (`limit => -1` with a type filter returns no rows,
   `wc_get_order_notes()` caps at one note), the WP HTTP API's `-gzip` ETag
   suffix, WP-Cron lock semantics, and Action Scheduler's per-process time
   budget. These are test-harness fixes, not plugin behaviour changes.

## 5. Files delivered

```
woocommerce-pepita.php
uninstall.php
includes/autoload.php
includes/class-plugin.php
includes/class-settings.php
includes/class-installer.php
includes/class-storage.php
includes/class-logger.php
includes/class-redactor.php
includes/class-health.php
includes/class-cli.php
includes/catalog/class-category-rules.php
includes/catalog/class-product-selection.php
includes/catalog/class-price-calculator.php
includes/catalog/class-description-sanitizer.php
includes/catalog/class-product-mapper.php
includes/catalog/class-validator.php
includes/feed/class-xml-writer.php
includes/feed/class-lock.php
includes/feed/class-catalog-index.php
includes/feed/class-generator.php
includes/feed/class-scheduler.php
includes/feed/class-change-detector.php
includes/feed/class-endpoint.php
includes/orders/class-order-repository.php
includes/orders/class-importer.php
includes/orders/class-api-controller.php
includes/orders/class-mapper.php
includes/admin/class-admin.php
includes/admin/class-ajax.php
includes/admin/class-product-metabox.php
includes/admin/class-product-list.php
includes/admin/class-category-list.php
includes/views/dashboard.php
includes/views/products.php
includes/views/categories.php
includes/views/feeds.php
includes/views/orders.php
includes/views/api.php
includes/views/settings.php
includes/views/logs.php
assets/admin.css
assets/admin.js
tests/… (runner, stubs, helpers, 14 cases)
bin/build.sh
dist/wp-pepita-sync.zip
PROJECT.md
ARCHITECTURE.md
BACKLOG.md
DEVELOPMENT-STATUS.md
README.md
INSTALLATION.md
PEPITA-FEED-MAPPING.md
PEPITA-API-INTEGRATION.md
TESTING.md
CHANGELOG.md
SECURITY-REPORT.md
COMPATIBILITY-REPORT.md
```

## 6. Known issues and blockers

### Blocker

- **No Pepita seller staging account or credentials.** The plugin is proven
  against a real local WordPress/WooCommerce/MySQL stack, but Pepita has not
  accepted a feed or forwarded an order to it. The plugin must not be called
  production-ready until that staging pass is complete.

### Known limitations (by design, documented)

- Only simple and variable products are exported.
- A variable parent in an order payload is rejected because the variation is not
  identified.
- `creditcard` has no default gateway mapping; the store must choose one.
- Multisite is not officially supported.
- The stock feed is a full snapshot; very large catalogs produce a large file.

### Verified locally by the Compose suite

- `dbDelta` on MySQL 8.4.
- HPOS order tables and legacy post-based orders end to end.
- Real REST/HTTP headers, ETag and 304 behaviour.
- Action Scheduler and WP-Cron execution.
- Duplicate delivery, retry after failure and crash recovery.
- Feed generation, stock-feed consistency and removal policies over real data.

### Still unverified

- Pepita's acceptance of the full and stock feeds, including the variation shape.
- Pepita's live order-forwarding payload and response behaviour.
- Payment gateway mapping against real gateway IDs.
- Browser behaviour of the admin UI.
- Page cache, object cache and reverse-proxy interactions on real hosting.
- Multisite.

## 7. Next steps

1. Deploy to staging and run the manual plan (`TESTING.md` §6).
2. Fill the version matrix in `COMPATIBILITY-REPORT.md` §10.
3. Confirm Pepita accepts both feeds and imports the variation shape.
4. Test order intake end to end, including duplicate delivery and labels.
5. Only then mark 1.0.0 as production-ready and tag the release.

## 8. Session protocol

At the start of every session:

1. Read `PROJECT.md`, `ARCHITECTURE.md`, `BACKLOG.md`, this file.
2. Run `php tests/run.php` and `php -l` before changing anything.
3. Work in a milestone; update this file and `BACKLOG.md` at the end.
4. Record real command output, never estimates.
