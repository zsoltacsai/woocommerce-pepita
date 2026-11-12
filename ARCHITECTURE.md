# ARCHITECTURE — WP Pepita Sync

## 1. Design principles

1. **The feed is a projection, never a source of truth.** WooCommerce remains
   authoritative for products, prices, stock and orders.
2. **Generation never destroys a good artefact.** A new feed is written to a
   temporary file, validated, and only then atomically renamed over the live
   file. The previous file is kept as a snapshot.
3. **Work is resumable.** No HTTP request or cron tick has to finish a whole
   catalog. State lives in options and on disk, so a timeout resumes cleanly.
4. **Idempotency is durable, not best-effort.** Order intake reserves the
   external ID in a UNIQUE column before touching WooCommerce.
5. **Fail closed.** Missing keys, inconsistent catalogs, empty feeds and lost
   locks all stop the operation rather than publish something wrong.

## 2. Component map

```
woocommerce-pepita.php
└── WPS\Pepita\Plugin
    ├── Installer            tables, defaults, upgrade
    ├── Settings             single option + secrets
    ├── Storage              private dir, atomic publish
    ├── Logger / Redactor    audit trail without secrets
    ├── Health               read-only checks
    ├── Cli                  wp wps-pepita …
    ├── Catalog\
    │   ├── Category_Rules        deepest rule, inheritance
    │   ├── Product_Selection     include/exclude resolution
    │   ├── Price_Calculator      gross price, VAT, adjustments
    │   ├── Description_Sanitizer HTML → plain text
    │   ├── Product_Mapper        WC product → feed record
    │   └── Validator             per-product + whole-file checks
    ├── Feed\
    │   ├── Xml_Writer            exact Pepita XML
    │   ├── Lock                  ownership token, TTL
    │   ├── Catalog_Index         product/export-id index file
    │   ├── Generator             plan → slices → publish
    │   ├── Scheduler             Action Scheduler / WP-Cron
    │   ├── Change_Detector       WooCommerce hooks → debounce
    │   └── Endpoint              token-authenticated streaming
    ├── Orders\
    │   ├── Api_Controller        auth, limits, responses
    │   ├── Importer              validation, CRUD, notes, labels
    │   ├── Order_Repository      atomic claim, dedupe, queries
    │   └── Mapper                payment / delivery modes
    └── Admin\
        ├── Admin                 menu, settings, actions, notices
        ├── Ajax                  chunked generation, previews, bulk
        ├── Product_Metabox       per-product overrides
        ├── Product_List          list columns and filters
        └── Category_List         category rule fields
```

## 3. Feed generation pipeline

### 3.1 Start

`Generator::start()`:

1. Acquires `Lock` (`wps_pepita_lock_full` / `_stock`) with a random token and TTL.
2. For the stock feed: refuses to start unless a successful full feed exists and
   `stock_requires_full` is on.
3. Creates the temporary feed (`pepita-{type}-{token}.xml.tmp`), writes the XML
   header and `<Catalog>` root, and the temporary catalog index.
4. Builds a **plan**: pages through product IDs in `batch_size` chunks, calling
   `Product_Selection::is_included()` and writing eligible IDs to
   `pepita-{type}-{token}.ids`. Excluded and ineligible products are counted.
5. Persists state in `wps_pepita_generation_{type}`: token, paths, cursor,
   counters, signature seed.

### 3.2 Slices

`Generator::run()` processes `batches_per_run` batches or until `max_runtime`
seconds elapse. Each batch:

- loads products with `wc_get_products()` (CRUD, no raw SQL),
- maps each product with `Product_Mapper::map()`,
- validates the record; invalid records increment `invalid`, are logged with
  their error codes, and are written to the product's `_wps_last_export_status`,
- writes the XML fragment via `XMLWriter::openMemory()` and appends it to the
  temporary feed,
- appends `product_id`, export ID and variation values to the catalog index.

The cursor, counters and signature are persisted after every batch. If the run
is not done, `Scheduler::schedule_continuation()` queues the next slice.

### 3.3 Finalize

1. Writes the closing `</Catalog>`.
2. Validates the whole file (`Validator::validate_xml_file`): well-formedness,
   `<Catalog>` root, Pepita namespace, duplicate IDs.
3. Enforces the empty-feed guard unless `allow_empty_feed` is enabled.
4. For the stock feed: compares the new signature against the last successful
   full feed signature. A mismatch aborts the publish and marks the full feed
   dirty.
5. `Storage::publish()` renames the temp file over the live file (the old file
   is first moved to `pepita-{type}-previous.xml`).
6. Publishes the catalog index, updates `wps_pepita_feed_state_{type}`
   (`last_success`, `hash`, `signature`, counts, size, errors), releases the
   lock, clears the dirty flag and logs the result.

Any exception or error before step 5 leaves the previous feed untouched.

### 3.4 State machine

```
idle ──start──▶ running ──slice──▶ running ──done──▶ completed
                  │                   │
                  │                   └──validation error──▶ error (old feed kept)
                  └──lock lost──▶ error (state discarded)
```

`run()` returns `status` ∈ `started | running | completed | error | skipped`.

## 4. Product selection

Resolution order (first match wins):

1. Product `_wps_include` = `exclude`.
2. Product `_wps_include` = `include`.
3. Category rules: walk the product's assigned categories and their ancestors;
   the deepest explicit rule wins. `exclude` always inherits. `include` inherits
   only when the category has `wps_include_children` enabled (default on).
   An exclusion anywhere in the resolved set wins over an inclusion.
4. Global `default_included`.

A product must also be in `allowed_product_statuses` (default `publish`) and be
a supported type (simple, variable, or a variation of an exported parent).

## 5. Pricing and VAT

- Feed prices are **gross**. `Price_Calculator::gross()` uses
  `wc_get_price_including_tax()` when available, otherwise the raw price.
- `DiscountedPrice` is emitted only when a sale price exists, is lower than the
  regular price, and is greater than zero. A higher sale price is ignored and
  produces a warning instead of a false discount.
- VAT comes from the product tax class via `WC_Tax::get_rates()`, or from
  `vat_fixed` when `vat_mode = fixed`. Tax-disabled stores emit `0`.
- Adjustments (`fixed` or `percent`) resolve product → deepest category →
  global. Rounding modes: `nearest_1`, `nearest_5`, `nearest_10`, `nearest_100`,
  `up_1`, `down_1`, `none`.
- WooCommerce prices are never modified; adjustments exist only in the feed.

## 6. XML output

Both feeds are UTF-8, 2-space indented, namespace
`https://pepita.hu/feed/1.0` on the root only. Product fragments carry no
namespace declaration; the root supplies it.

Full feed element order is fixed (see `PEPITA-FEED-MAPPING.md`): `Id`, `LastMod`,
`StructuredId`, `ProductNumber`, `Descriptions`, `Prices`, `Warranty`,
`Categories`, `Photos`, `VideoLinks`, `ProductUrl`, `Availability`, `Attributes`,
`VolumeDimensions`, `Variations`.

The stock feed emits only `Id`, `Availability`, `Prices` (`Currency`, `Price`,
`DiscountedPrice`) and, for variable products, `Variations`. It never emits
`VatPercent`, descriptions, images or categories.

Text is passed through `Xml_Writer::clean()`: invalid UTF-8 is repaired and
illegal XML control characters (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`) are
removed. Escaping is handled by `XMLWriter`.

## 7. Storage layout

Private directory: `wp-content/uploads/wps-pepita-{16 hex chars}/`, salted with a
random `storage_key` stored in settings. The directory contains `index.php`,
`.htaccess` (`Require all denied`) and `web.config` as defence in depth; the
canonical protection is that the feed is only served through WordPress.

```
wps-pepita-<key>/
├── pepita-full.xml              live full feed
├── pepita-full-previous.xml     previous published full feed
├── pepita-stock.xml             live stock feed
├── pepita-stock-previous.xml
├── pepita-catalog-index.jsonl   export id ↔ product id index
├── pepita-{type}-{token}.xml.tmp    in-flight feed
├── pepita-{type}-{token}.ids        plan
└── labels/                      Pepita package labels (PDF)
```

Publishing uses `rename()` within the same filesystem, so readers never see a
partial file.

## 8. Data model

### 8.1 Options (all `autoload = false`)

| Option | Purpose |
|---|---|
| `wps_pepita_settings` | Every setting, including secrets and maps |
| `wps_pepita_db_version` | Schema version |
| `wps_pepita_generation_full` / `_stock` | In-flight generation state |
| `wps_pepita_feed_state_full` / `_stock` | Last success, hash, signature, counters, errors |
| `wps_pepita_dirty_full` / `_stock` | Debounced regeneration pending |
| `wps_pepita_lock_full` / `_stock` | Lock token and expiry |
| `wps_pepita_schedule_signature` | Detects interval changes |

### 8.2 Custom tables

Only two, both prefixed and created with `dbDelta`:

`{prefix}wps_pepita_logs` — `id`, `created_at`, `level`, `event`, `message`,
`context`, `object_type`, `object_id`; indexed by time, level, event and object.

`{prefix}wps_pepita_orders` — the durable dedupe ledger:

```sql
external_id varchar(191) NOT NULL,
origin      varchar(64)  NOT NULL DEFAULT '',
status      varchar(20)  NOT NULL DEFAULT 'processing', -- processing|completed|failed
wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
payload     longtext NULL,
payload_hash char(64) NOT NULL DEFAULT '',
attempts    int(10) unsigned NOT NULL DEFAULT 1,
last_error  text NULL,
created_at  datetime NOT NULL,
updated_at  datetime NOT NULL,
UNIQUE KEY external_id (external_id)
```

`INSERT IGNORE` plus the UNIQUE key is the concurrency primitive: exactly one
request wins the claim, every other request observes the existing row.

### 8.3 Post meta (all `_wps_` prefixed)

`_wps_export_id`, `_wps_include`, `_wps_title_override`,
`_wps_description_override`, `_wps_gtin`, `_wps_brand`, `_wps_manufacturer`,
`_wps_mpn`, `_wps_price_adjustment_type`, `_wps_price_adjustment_value`,
`_wps_shipping_delay`, `_wps_shipping_price`, `_wps_warranty_type`,
`_wps_warranty_duration`, `_wps_video_links`, `_wps_restock_date`,
`_wps_last_export_status`, `_wps_last_export_at`, `_wps_last_export_message`.

Order meta: `_wps_pepita_order_id`, `_wps_pepita_origin`,
`_wps_pepita_payment_status`, `_wps_pepita_delivery_mode`,
`_wps_pepita_imported_at`, `_wps_pepita_payload_hash`, `_wps_pepita_voucher`,
`_wps_pepita_raw_total`, `_wps_pepita_package_label`,
`_wps_pepita_customer_message`, `_wps_pepita_courier_message`.
Item meta: `_wps_pepita_product_id`, `_wps_pepita_sku`, `_wps_pepita_vat`.

### 8.4 Term meta (category rules)

`wps_include`, `wps_include_children`, `wps_gtin_required`,
`wps_price_adjustment_type`, `wps_price_adjustment_value`, `wps_shipping_delay`.

## 9. Order intake

```
POST /wp-json/wps/v1/pepita/order?apikey=…
  1. endpoint enabled?              503
  2. API key (query, X-WPS-Api-Key, X-Api-Key)   401
  3. IP allowlist (optional)         401
  4. HTTPS required (optional)       403
  5. rate limit 120/min/IP           429
  6. body ≤ max_body_kb              413
  7. non-empty, valid JSON object    400
  8. Importer::import()
       a. external id present?       400
       b. Order_Repository::claim()  UNIQUE insert
            completed → duplicate 200
            busy      → 409
            failed    → 500
       c. adopt an existing WC order carrying the same meta (crash recovery)
       d. validate payload           422
       e. create order via wc_create_order + CRUD setters
       f. suppress e-mails, save, restore filters
       g. store label, notes, meta, complete claim
```

Guarantees:

- A gateway is never executed. Payment state is metadata plus status, not a
  transaction.
- `date_paid` and a paid status are only set when `payment_status === 'paid'`.
- All customer e-mails are disabled through `woocommerce_email_enabled_*`
  filters for the duration of the save and removed afterwards.
- A retry with the same external ID can never create a second order, even if
  two requests race.
- A failed attempt records `failed` and stays retryable; a fresh `processing`
  claim is treated as busy for 10 minutes.

## 10. Scheduling and change detection

- Recurring full / stock generation and daily log purge.
- Action Scheduler is used when present, WP-Cron otherwise. Hooks are separate
  for recurring, queued and continuation jobs so rescheduling cannot delete a
  pending run.
- WooCommerce hooks (`woocommerce_update_product`, stock changes, new/trash
  product, category edits, product import) call `Scheduler::queue()` with a
  120-second debounce (filter `wps_pepita_debounce_seconds`).
- A stock-signature mismatch marks the full feed dirty.

## 11. Admin surface

Menu slug `wps-pepita`, capability `manage_woocommerce`:

| Page | Slug |
|---|---|
| Áttekintés | `wps-pepita` |
| Termékek | `wps-pepita-products` |
| Kategóriák | `wps-pepita-categories` |
| Feedek | `wps-pepita-feeds` |
| Rendelések | `wps-pepita-orders` |
| Rendelés API | `wps-pepita-api` |
| Beállítások | `wps-pepita-settings` |
| Napló | `wps-pepita-logs` |

State-changing operations use nonce-protected `admin-post.php` handlers with a
capability check; AJAX endpoints use `check_ajax_referer`. Generation buttons
are progressively enhanced: JavaScript polls a chunked AJAX loop and falls back
to the `admin-post` action when JavaScript is unavailable.

## 12. Security model

| Area | Control |
|---|---|
| Feed access | Random 48-char token compared with `hash_equals`; optional public mode; `X-WPS-Feed-Token` header supported |
| API access | Shared secret, constant-time comparison, optional IP allowlist, optional HTTPS, per-IP rate limit |
| Secrets at rest | Stored in a non-autoloaded option; may be overridden by `wp-config.php` constants, which take precedence |
| Secrets in logs | `Redactor` masks token/key values and `package_label` before writing |
| Admin actions | `manage_woocommerce` + nonces; every handler validates its nonce |
| Output | `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` in views |
| SQL | Prepared statements; interpolated table names only |
| File access | Private salted directory, deny rules, WordPress-mediated streaming |
| Uploads | Package labels are base64-decoded, size-capped, `%PDF` checked, stored under a random name |
| Uninstall | Data removed only with explicit opt-in |

## 13. Performance

Measured on the bundled test harness (PHP 8.5.12, in-memory stubs) with 1000
products: full generation completes in ≈0.6 s across 6 bounded slices, peak
memory ≈6 MB, feed ≈680 KB. Production numbers depend on image count, description
length and WooCommerce tax lookups, which is why generation is sliced and
time-budgeted rather than monolithic.

Design choices that keep memory flat:

- Only `batch_size` products are hydrated at once.
- XML is written per product and appended, never accumulated in memory.
- The plan is a file of IDs, not an array in an option.
- The catalog index is streamed line by line.

## 14. Extension points

| Hook | Type | Purpose |
|---|---|---|
| `wps_pepita_debounce_seconds` | filter | Debounce window for automatic regeneration |
| `wps_pepita_order_rate_limit` | filter | Requests per minute per IP (default 120; `0` disables) |

Constants: `WPS_PEPITA_FEED_TOKEN`, `WPS_PEPITA_ORDER_API_KEY`.

## 15. Documented assumptions

1. Pepita's stock feed is a **complete** snapshot; omitted IDs are treated as
   deleted by the marketplace. Therefore the stock feed is built from the same
   plan as the full feed and is refused when the two disagree.
2. Variation identity is the ordered tuple of variation values. Labels may
   change without breaking identity; values must not.
3. Once a product has been exported as variable, exporting it as simple would
   orphan its variations at Pepita, so it is rejected
   (`variable_to_simple_forbidden`) instead of silently changing shape.
4. `creditcard` has no default WooCommerce gateway mapping because the store
   decides which gateway represents card payments; the admin must choose one.
5. The order API is intake only. Nothing in the plugin pushes orders to Pepita.
