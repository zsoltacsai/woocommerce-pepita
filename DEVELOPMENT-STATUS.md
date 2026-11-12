# DEVELOPMENT-STATUS — WP Pepita Sync

Last updated: 2026-11-12
Version: 1.0.0
Overall state: **feature-complete, unit-tested, staging validation pending**

## 1. Milestones

| # | Milestone | Status |
|---|---|---|
| M1 | Discovery: Pepita docs, XML samples, order API PDF, Allegro reference | ✅ Complete |
| M2 | Foundation: bootstrap, settings, storage, installer, logger, catalog layer | ✅ Complete |
| M3 | Feed: XML writer, generator, lock, index, scheduler, endpoint | ✅ Complete |
| M4 | Orders: repository, importer, API controller, payment/shipping mapper | ✅ Complete |
| M5 | Admin: menu, eight screens, AJAX, metabox, list columns, category fields | ✅ Complete |
| M6 | Tests: harness, 14 case files, 133 cases, defect fixes | ✅ Complete |
| M7 | Documentation and packaging | ✅ Complete (this session) |
| M8 | Live staging validation | ⛔ Blocked — needs a WordPress/WooCommerce host |

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

- **No WordPress/WooCommerce installation is available in this environment.**
  Every integration point is proven against stubs, not a live stack. The plugin
  must not be called production-ready until M8 is complete.

### Known limitations (by design, documented)

- Only simple and variable products are exported.
- A variable parent in an order payload is rejected because the variation is not
  identified.
- `creditcard` has no default gateway mapping; the store must choose one.
- Multisite is not officially supported.
- The stock feed is a full snapshot; very large catalogs produce a large file.

### Not verified

- `dbDelta` on real MySQL/MariaDB.
- HPOS order tables end to end.
- Real REST/HTTP headers and 304 behaviour.
- Action Scheduler execution.
- Browser behaviour of the admin UI.
- Page cache and object cache interactions.
- Payment gateway mapping against real gateway IDs.

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
