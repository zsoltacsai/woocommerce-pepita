# PROJECT — WP Pepita Sync

Persistent project context. Read this file, `ARCHITECTURE.md`, `BACKLOG.md` and
`DEVELOPMENT-STATUS.md` at the start of every development session.

## 1. Objective

Deliver a production-ready WooCommerce plugin that integrates a Hungarian
WooCommerce store with the **Pepita** marketplace:

1. **Product feed** — a Pepita XML catalog (`https://pepita.hu/feed/1.0`) built
   from WooCommerce products.
2. **Stock / price feed** — a separate, complete feed that updates availability
   and prices for products already known to Pepita.
3. **Order intake** — a REST endpoint that accepts Pepita orders and creates
   them as WooCommerce orders through the WooCommerce CRUD API.
4. **Hungarian admin UI** — configuration, monitoring and manual operations in
   Hungarian, with English code and identifiers.
5. **Operational safety** — never corrupt the live catalog, never duplicate an
   order, never mark an order paid without an explicit `payment_status: paid`.

## 2. Hard requirements

| Requirement | Status |
|---|---|
| Declared minimums: PHP 8.1+, WP 6.4+, WC 8.2+ (HPOS compatible) | Implemented and verified |
| Hungarian admin labels, English code | Implemented |
| Modular architecture, PSR-4 style autoloading | Implemented |
| Independent of the reference Allegro plugin | Implemented (no shared symbols) |
| Unique `WPS_` / `wps_` prefix for every global symbol | Implemented and tested |
| Exact Pepita XML namespace, tags and nesting | Implemented and tested |
| No invented endpoints, fields or authentication | Implemented |
| Selection priority: exclusion > inclusion > category > default | Implemented and tested |
| Full and stock feed share identical IDs and variation values | Implemented and tested |
| Stock feed is complete, never a delta | Implemented and tested |
| Failed generation preserves the last good feed | Implemented and tested |
| Feed difference reporting and publication safety drop guards (M9-C) | Implemented and tested |
| Dedicated stock feed health monitoring and freshness tracking (M9-C) | Implemented and tested |
| Secure CSV log export with formula injection prevention (M9-C) | Implemented and tested |
| Official XML contract compliance (1–3 axes, email delivery, books, safe publish) (M9-D) | Implemented and tested |
| Pepita → WooCommerce order push only | Implemented |
| Durable idempotency for order intake | Implemented and tested |
| No customer e-mails or gateway execution during import | Implemented and tested |
| Never mark paid without `payment_status: paid` | Implemented and tested |
| No direct SQL writes to WooCommerce product/order storage | Verified by test |
| Allegro plugin untouched | Verified (never referenced) |

## 3. Non-goals

- WooCommerce → Pepita order export. The documented Pepita API is intake only.
- Pepita product upload beyond the XML feed (no product write API is documented).
- Editing or replacing the reference Allegro plugin.
- Modifying a live store, its data, or its configuration from this repository.
- Payment capture, invoice issuing, or shipping-label generation beyond storing
  the label Pepita sends.

## 4. Source of truth

All Pepita-specific behaviour derives from documents captured under
`/tmp/opencode/` during discovery:

| Artefact | Purpose |
|---|---|
| `pepita-sample.xml` | Reference full feed |
| `pepita-stock-sample-hu.xml` | Reference stock feed |
| `pepita-order-api.txt` | Full text of the order API specification |
| `allegro/WP-Allegro-Sync` | Reference plugin used only for UX patterns |

If a behaviour is not documented there, it is not implemented. Assumptions are
recorded in `ARCHITECTURE.md` §9.

## 5. Glossary

| Term | Meaning |
|---|---|
| **Full feed** | Complete catalog with descriptions, images, categories, prices, stock, variations. |
| **Stock feed** | Availability + price update for products already exported; never introduces new IDs. |
| **Export ID** | Stable Pepita product identifier. Persisted as `_wps_export_id`; defaults to `wps-{product_id}`. Never derived from SKU. |
| **Signature** | SHA-256 over the ordered list of exported product IDs. Full and stock feeds must agree. |
| **Removal policy** | What happens to previously exported products that are no longer eligible: `omit` (default) or `zero_stock` (keep for a grace period, unavailable). |
| **Claim** | Atomic row in `{prefix}wps_pepita_orders` that reserves an external Pepita order ID before any WooCommerce work starts. |
| **Plan** | The list of eligible product IDs written to disk at the start of a full generation. |
| **Slice** | One bounded generation run (`batches_per_run` × `batch_size` products). |

## 6. Repository layout

```
woocommerce-pepita/
├── woocommerce-pepita.php      Plugin header, constants, bootstrap
├── uninstall.php               Opt-in data removal
├── includes/
│   ├── autoload.php            WPS\Pepita PSR-4 style autoloader
│   ├── class-plugin.php        Wiring, HPOS declaration, dependency guard
│   ├── class-settings.php      Single option, defaults, sanitisation, secrets
│   ├── class-installer.php     Tables, defaults, upgrade routine
│   ├── class-storage.php       Private feed directory, atomic publish
│   ├── class-logger.php        Custom log table
│   ├── class-redactor.php      Secret masking in logs
│   ├── class-health.php        Read-only dashboard checks
│   ├── class-cli.php           WP-CLI commands
│   ├── catalog/                Selection, rules, pricing, mapping, validation
│   ├── feed/                   XML writer, generator, lock, index, scheduler, endpoint
│   ├── orders/                 API controller, importer, repository, mapper
│   ├── admin/                  Menu, AJAX, metabox, list columns, category fields
│   └── views/                  Hungarian admin screens
├── assets/                     admin.css, admin.js
├── tests/                      Custom runner, stubs, 14 case files
├── bin/build.sh                Reproducible ZIP build
└── dist/                       Built plugin package
```

## 7. Working agreements

- Work in milestones (M1–M7). Report after each: functionality, files, tests,
  issues, next step.
- Never fabricate test results. Record the exact command and output summary.
- Prefer WordPress/WooCommerce APIs. Direct SQL is limited to the plugin's own
  tables and documented in `ARCHITECTURE.md`.
- Keep the admin UI Hungarian and the code English.
- Update `DEVELOPMENT-STATUS.md` and `BACKLOG.md` whenever the state changes.

## 8. Definition of done

A milestone is done when:

1. The feature is implemented behind the documented settings.
2. It has automated coverage, or a documented reason why not.
3. `php -l` passes on every file.
4. `php tests/run.php` reports 0 failures.
5. The relevant documentation section is written.
