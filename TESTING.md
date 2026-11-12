# TESTING — WP Pepita Sync

## 1. Current result

```
$ php tests/run.php
133 passed, 0 failed, 0 skipped in 4.66s
```

PHP 8.5.12 CLI, no WordPress installation required.

```
$ find . -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
# no syntax errors
```

These numbers are produced by the commands above. They are not estimated.

## 2. How the suite works

The plugin depends on WordPress and WooCommerce, but the test suite does not
need either. It loads:

- `tests/bootstrap.php` — defines `ABSPATH`, `WP_CONTENT_DIR`, `MINUTE_IN_SECONDS`,
  `KB_IN_BYTES`, `MB_IN_BYTES`, `DAY_IN_SECONDS`, `HOUR_IN_SECONDS`, loads the
  plugin autoloader and every stub, and resets state between cases.
- `tests/stubs/class-wps-test-state.php` — in-memory options, post meta, term
  meta, terms, products, variations, orders, users, attachments, transients,
  hooks, REST routes and attribute labels.
- `tests/stubs/class-wp-stubs.php` — WordPress functions the plugin calls
  (`get_option`, `update_post_meta`, `wp_upload_dir`, `add_action`, `apply_filters`,
  `wp_json_encode`, `sanitize_*`, `wp_generate_password`, `is_email`, `WP_Error`,
  `WP_REST_Request`, `WP_REST_Response`, `register_rest_route`, …).
- `tests/stubs/class-wc-stubs.php` — `WC_Product`, `WC_Product_Variation`,
  `WC_Product_Attribute`, `WC_Order`, `WC_Order_Item_Product`,
  `WC_Order_Item_Shipping`, `WC_Order_Item_Fee`, `wc_get_product`,
  `wc_create_order`, `wc_get_order_statuses`, `wc_attribute_label`, …
- `tests/stubs/class-fake-wpdb.php` — a SQL emulator for the logger and order
  ledger: `INSERT IGNORE`, `UPDATE`, `DELETE`, `SELECT`, `SHOW TABLES LIKE`,
  `prepare()`, `esc_like()`. It reproduces the `UNIQUE external_id` behaviour that
  idempotency relies on.
- `tests/stubs/wp-admin/includes/upgrade.php` — `dbDelta()` no-op.
- `tests/helpers/class-wps-assert.php` — assertions with readable failures.
- `tests/helpers/class-wps-suite.php` — case registry and result reporting.

Run a subset by passing a name fragment:

```bash
php tests/run.php "feed:"
php tests/run.php "import:"
php tests/run.php validator
```

## 3. Coverage by file

| File | Cases | Area |
|---|---:|---|
| `10-settings-test.php` | 9 | Defaults, sanitisation, secrets, constants, currency, maps, intervals |
| `20-price-test.php` | 10 | Gross price, sale price, VAT, adjustments, rounding, net price |
| `30-category-rules-test.php` | 14 | Deepest rule, inheritance, exclusion precedence, category price/shipping/GTIN |
| `40-sanitizer-test.php` | 7 | HTML → text, shortcodes, line breaks, illegal XML chars, GTIN validation |
| `50-xml-writer-test.php` | 5 | Element structure, optional elements, escaping, variations, stock shape |
| `60-mapper-test.php` | 11 | Simple/variable mapping, export ID, stock, overrides, brand, GTIN, dimensions |
| `70-validator-test.php` | 12 | Every error code, warnings, variable rules, whole-file validation |
| `80-feed-generation-test.php` | 12 | Publish, resume, stock consistency, failure preservation, removal policies |
| `90-order-repository-test.php` | 9 | Claim, dedupe, retry, stale claims, queries, purge, missing table |
| `100-order-import-test.php` | 13 | Totals, status, payment, metadata, vouchers, labels, e-mail suppression, idempotency |
| `110-api-controller-test.php` | 11 | Route, auth, headers, duplicates, disabled endpoint, HTTPS, IP, rate limit, body limits |
| `120-prefix-test.php` | 8 | No Allegro symbols, namespaces, constants, hooks, options, meta, tables, text domain |
| `130-performance-test.php` | 5 | 1000 products, bounded runs, memory, stock consistency, idempotency, bulk exclusion |
| `140-bootstrap-test.php` | 7 | Front-end/admin wiring, all eight admin screens render, activation/deactivation, health checks, missing-WooCommerce notice |

### Key behaviours proven

- A full generation publishes a valid feed and reports accurate counters.
- Invalid products are skipped without breaking the feed and appear in the error
  list with their codes.
- Generation resumes across slices without duplicates.
- The stock feed mirrors the full feed's IDs and signatures, and refuses to
  publish when the catalog changed.
- A failed or empty generation leaves the previous feed untouched.
- `omit` and `zero_stock` behave as documented, including the grace period.
- A variable → simple change is rejected instead of orphaning variations.
- An order is created once, with the right totals, status, payment, addresses,
  notes and meta.
- A repeated notification returns a duplicate response and never creates a
  second order.
- A crash between WooCommerce creation and ledger completion is recovered by
  adopting the existing order.
- A rejected order is retryable after the data is fixed.
- Customer e-mails are disabled during save and the filters are removed after.
- The API rejects bad keys, non-HTTPS requests, disallowed IPs, oversized bodies,
  malformed JSON and rate-limit overflows.
- The plugin writes no core table rows directly and uses no Allegro symbol.
- 1000 products generate in bounded slices under 60 s and 128 MB, with matching
  full and stock identifiers.
- A front-end request registers only the public components, while an admin
  request registers every screen, `admin-post` handler and AJAX action.
- All eight admin screens render without a fatal error in the stub environment.
- Activation creates the settings, tokens, schedules and storage directory;
  deactivation clears the schedules but keeps the data.
- The health checks cover the operational surface and report a consistent
  summary.

## 4. Defects found by the suite

The suite is not decorative; it found real bugs that were fixed in the plugin:

1. `Generator::run()` replaced specific `start()` errors (empty feed, missing full
   feed, lock conflict) with a generic "state could not be created" message.
2. `Importer::validate()` nested product-resolution errors under `products`,
   turning a 422 into a 500.
3. `Importer` never stored the computed payload hash, so
   `_wps_pepita_payload_hash` was always empty.

## 5. What is not covered

These require a real WordPress + WooCommerce environment and are **not** verified
by this suite:

- Actual `dbDelta` schema creation on MySQL/MariaDB.
- Real `WC_Product`/`WC_Order` hydration, HPOS order tables, and WooCommerce
  email/gateway behaviour.
- Real HTTP behaviour of the feed endpoint and REST route (headers, 304, auth
  through a web server).
- Action Scheduler and WP-Cron execution.
- Browser behaviour of the admin UI, AJAX polling and nonce flows.
- WordPress multisite, object cache, and page-cache interactions.
- Payment gateway mapping against a specific store's gateway IDs.

See `COMPATIBILITY-REPORT.md` §5 for the manual test plan that closes these gaps.

## 6. Manual integration test plan

Run on a staging copy of the store:

1. **Activation** — activate the plugin; confirm both tables exist and no PHP
   notice appears with `WP_DEBUG` on.
2. **Settings** — save every settings section with valid and invalid values;
   confirm sanitisation and that secrets are never echoed back in full.
3. **Feed** — generate a full feed; download it; validate with an XML tool;
   confirm the namespace, element order and product count.
4. **Feed auth** — request with a wrong token (403), no token (403), correct
   token (200), `If-None-Match` (304).
5. **Stock feed** — generate it; diff the ID list against the full feed; change a
   price and confirm the stock feed reflects it.
6. **Consistency guard** — exclude a product, run only the stock feed, confirm it
   is refused and the full feed is untouched.
7. **Order import** — send the sample payload; confirm totals, addresses,
   shipping line, voucher fee, notes and meta; confirm no e-mail was sent.
8. **Idempotency** — send the same payload again; confirm 200 and one order.
9. **Payment safety** — send `payment_status: unpaid`; confirm `on-hold` and no
   `date_paid`.
10. **Label** — send a base64 PDF; confirm the file exists outside the web root
    path and the meta points to it.
11. **HPOS** — enable HPOS, repeat steps 7–10.
12. **Cron** — set intervals to 1 minute, wait for two runs, confirm feeds update
    and no overlapping generation occurs.

## 7. Adding tests

Create `tests/cases/NNN-name-test.php`:

```php
<?php
use WPS\Pepita\Settings;

$suite = $GLOBALS['wps_suite'];

$suite->add(
    'area: what it proves',
    static function (): void {
        WPS_Assert::same( 'expected', 'actual' );
    }
);
```

Rules:

- Reset static caches when a case changes options or meta:
  `Settings::reset_cache()`, `Product_Selection::clear_cache()`,
  `Order_Repository::reset_table_cache()`, `Logger::reset_table_cache()`,
  `Storage::reset()`.
- Use `Generator::run( $type, array( 'max_runtime' => 0 ) )` in loops until
  `status === 'completed'`.
- Do not assert on wall-clock values; assert on structure and counters.
- Performance thresholds must be generous enough for slow CI but tight enough to
  catch regressions (currently 60 s and 128 MB for 1000 products).
