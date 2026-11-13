# TESTING — WP Pepita Sync

## 1. Current result

```
$ php tests/run.php
185 passed, 0 failed, 0 skipped
```

PHP 8.1–8.5 CLI, no WordPress installation required.

```
$ git ls-files -z '*.php' | xargs -0 -n1 -P "$(nproc)" php -l
# no syntax errors detected
```

These numbers are produced by the automated test suites in CI and local verification.

### Integration suite (real WordPress/WooCommerce/MySQL)

```bash
sudo -n ./integration/scripts/run.sh
```

**Status:** Verified in CI runner containers across both storage architectures (HPOS and Legacy post meta) running PHP 8.5, MySQL 8.4, WordPress 7.1.4, and WooCommerce 11.3.0.

| Store | Expected HPOS | Result |
|---|---|---|
| `wordpress-hpos` | yes | 71 passed, 0 failed |
| `wordpress-legacy` | no | 71 passed, 0 failed |

Verified cases cover environment, schema creation and collation, REST endpoint authentication and authorization, catalog feed XML generation and validation, order processing, package labels, idempotency, crash recovery and lease fencing, background scheduler execution, and tax handling.

### GitHub Actions

The repository CI workflow runs on Ubuntu 24.04 runners for every PR and push. The workflow matrix includes:
1. `lint (PHP 8.5)`: PHP syntax check across all PHP files.
2. `unit (PHP 8.1–8.5)`: Unit test suite (185 tests across 19 cases).
3. `integration-hpos`: Full integration suite on WooCommerce 11.3 HPOS store.
4. `integration-legacy`: Full integration suite on WooCommerce 11.3 legacy store.
never as PASS, until the workflow reaches `main` and runs on the exact review
HEAD.

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
| `50-xml-writer-test.php` | 6 | Element structure, optional elements, escaping, variations, stock shape, noncharacters |
| `60-mapper-test.php` | 11 | Simple/variable mapping, export ID, stock, overrides, brand, GTIN, dimensions |
| `70-validator-test.php` | 12 | Every error code, warnings, variable rules, whole-file validation |
| `80-feed-generation-test.php` | 13 | Publish, resume, stock consistency, failure preservation, removal policies, lock reclaim |
| `90-order-repository-test.php` | 11 | Claim, dedupe, retry, stale claims, queries, purge, payload opt-in, missing table |
| `100-order-import-test.php` | 20 | Totals, status, payment, metadata, vouchers, labels, e-mail suppression, idempotency, money, SKU mismatch, conflict, orphan cleanup, payload retention |
| `110-api-controller-test.php` | 16 | Route, auth, headers, duplicates, disabled endpoint, HTTPS, spoofed headers, trusted proxy, unparseable hop, failed-auth throttle, IP, rate limit, body limits |
| `115-money-test.php` | 4 | Strict amount/VAT/quantity parsing, ambiguity rejection, bounds |
| `116-redactor-test.php` | 2 | Secret and PII masking in nested log payloads |
| `120-prefix-test.php` | 8 | No Allegro symbols, namespaces, constants, hooks, options, meta, tables, text domain |
| `130-performance-test.php` | 5 | 1000 products, bounded runs, memory, stock consistency, idempotency, bulk exclusion |
| `140-bootstrap-test.php` | 7 | Front-end/admin wiring, all eight admin screens render, activation/deactivation, health checks, missing-WooCommerce notice |
| `150-retention-test.php` | 3 | Package-label retention for open, closed and custom-status orders |
| `105-order-tax-test.php` | 9 | Deterministic zero-tax and tax-inclusive/exclusive handling, operator notes |
| `106-recovery-and-replay-test.php` | 18 | Mid-creation crash recovery, shell purge, lease fencing, historical replay, deduplication |

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
- The transport check runs before the key comparison, and a client-set
  `X-Forwarded-Proto` is ignored unless it comes from a configured trusted proxy.
- Spoofed `X-Forwarded-For` / `CF-Connecting-IP` / `X-Real-IP` headers cannot
  satisfy the IP allowlist or reset the rate limiter.
- Failed authentication is throttled before the key is compared, and rejection
  logging stays bounded during a failed-key flood.
- Locale-formatted amounts (`"4,990"`, `"1 490"`, `"12,5"`) are rejected with 422
  instead of being silently truncated to a near-zero price.
- A raw payload is stored only when `order_api_keep_payloads` is enabled; only the
  SHA-256 hash is kept by default.
- Expired package-label PDFs of closed orders are deleted and their meta cleared;
  labels of open orders are retained.
- A SKU and Pepita product id that resolve to different products are rejected.
- A save failure leaves no orphan pending order behind.
- An expired feed lock is reclaimed by exactly one process.
- A changed duplicate returns 409 and never rewrites the stored order.
- Invalid XML control characters and Unicode noncharacters are stripped.
- Secrets and customer PII (name, e-mail, phone, address, city, postcode, tax
  number, messages) are masked before a payload reaches the log table.
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

The Compose suite added four more:

4. Order notes were added before the order was saved, so a note could be lost on
   a freshly created order.
5. Non-taxonomy attribute names were used as XML attribute keys without
   sanitisation.
6. The crash-recovery lookup by external id could reuse a cached empty result
   and create a duplicate order; it now passes `cache_results => false`.
7. The harness itself had to be corrected for WooCommerce 11 behaviour
   (`limit => -1` with a type filter, `wc_get_order_notes()` limits), the WP HTTP
   API's `-gzip` ETag suffix, WP-Cron lock semantics and Action Scheduler's
   per-process time budget.

The security audit (CF-01–CF-13, PV-01–PV-12) added the following regressions,
each now covered by a case:

8. `(float)` casts silently truncated locale-formatted amounts to near-zero;
   replaced by `Money` with strict parsing (CF-04).
9. The HTTPS check trusted a client-set `X-Forwarded-Proto` and ran after the key
   comparison; the transport check now runs first and trusts the header only from
   a configured proxy (CF-02).
10. The allowlist and rate limiter trusted spoofable forwarding headers (CF-01).
11. Failed-key traffic was unlimited and wrote a log row per request (CF-03).
12. `order_api_keep_payloads` was never read, so raw PII was always stored
    (CF-05), and package labels were never expired (CF-06).
13. A SKU/id mismatch silently resolved to one product (CF-07).
14. A failed `save()` could leave an orphan pending order (CF-08).
15. Two processes could reclaim an expired feed lock at once (CF-09).
16. A changed duplicate was reported as success instead of a conflict (CF-10).
17. A privileged WordPress account could be linked as an imported order's
    customer (CF-11).
18. Non-PDF bytes were accepted as a package label (CF-13).
19. `external_id` used a case-insensitive collation, so `PEP-1` and `pep-1`
    collided (PV-03).
20. Stale-worker lease takeover was unfenced; a worker stalled past the 15-minute lease
    could overwrite newly reclaimed attempts. Now fenced by `attempts = %d` on all state
    mutations (`associate()`, `complete()`, `fail()`), matching 0 rows and aborting with 409 retry.
21. Fenced-out completed order preservation: a worker losing its lease at the completion
    boundary preserves the created order intact instead of calling `delete(true)`, allowing
    the overtaking worker or retry to adopt it via `find_order_by_external_id()`.
22. Incomplete order shell discard: mid-creation crashes and fence-outs reliably discard
    partial order shells with `_wps_pepita_in_progress` before returning 409 retry.
23. Historical order replay without stored hash verifies all invariant fields
    (currency, line item count, totals within 0.001, billing email, per-line SKU and quantity)
    and fails closed if any field conflicts.
24. Fail-closed claim initialization: claim read-back returning null or non-positive attempt
    counter rejects the order with 500 error instead of silently bypassing fencing.

## 5. What the suites do not cover

The stub suite covers the plugin logic without WordPress. The Compose suite
(`integration/`) covers the same plugin against a real WordPress, WooCommerce and
MySQL stack on both HPOS and legacy storage, including:

- `dbDelta` schema creation on MySQL 8.4.
- Real `WC_Product`/`WC_Order` hydration, HPOS and legacy order tables.
- Real HTTP behaviour of the feed endpoint and REST route (headers, ETag, 304,
  auth through Apache).
- Action Scheduler and WP-Cron execution.
- Duplicate delivery, retry after failure and crash recovery.
- Tax-enabled stores with `prices_include_tax` set to `yes` and `no`
  (`08-tax.php`, added by this revision, **not yet executed**).

What still requires Pepita staging or real hosting:

- Pepita's acceptance of the full and stock feeds and the variation shape.
- Pepita's live order-forwarding payload and response behaviour.
- Browser behaviour of the admin UI, AJAX polling and nonce flows.
- Page cache, object cache and reverse-proxy interactions.
- Payment gateway mapping against a specific store's gateway IDs.
- nginx private-storage protection (`.htaccess` is Apache-only; the nginx rule
  must be added and verified on the real host).
- WordPress multisite.

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
13. **Tax** — enable taxes, set a 27% standard rate, import the sample order with
    `prices_include_tax` set to `yes` and then `no`; confirm the stored total
    equals the Pepita gross total (2990 in the fixture) and `get_total_tax()` is
    0 in both cases.
14. **Changed duplicate** — resend the same external id with a different line
    price; confirm 409, the original order unchanged and no second order.
15. **Transport** — send a plain-HTTP request with a correct key; confirm 403 and
    that no key comparison is logged. Behind a TLS-terminating proxy, confirm the
    request succeeds only after the proxy address is in
    `order_api_trusted_proxies`.
16. **Private storage** — request the label/feed path directly from a browser on
    the production web server; confirm 403/404. On nginx, verify the documented
    `location` deny rule is present.
17. **Retention** — with `order_api_keep_payloads` off, import an order and confirm
    the claim row's `payload` column is NULL; run the maintenance job and confirm
    expired closed-order labels are removed while open-order labels survive.

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
