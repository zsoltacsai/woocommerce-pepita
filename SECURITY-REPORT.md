# SECURITY-REPORT — WP Pepita Sync 1.1.0

## 1. Scope

Reviewed surfaces:

1. Public feed endpoint (`?wps_feed=…`).
2. Public order REST endpoint (`/wp-json/wps/v1/pepita/order`).
3. Admin pages, forms, AJAX handlers and metaboxes.
4. File storage for feeds, indexes and package labels.
5. Database access, including the two custom tables.
6. Logging, secret handling and uninstall.
7. Supply chain: no third-party runtime dependencies.

## 2. Threat model

| Threat | Impact | Control |
|---|---|---|
| Unauthenticated catalog scraping | Business data leak | Random 48-char feed token, constant-time comparison; optional public mode only by explicit choice |
| Token guessing | Catalog leak | 48-char `wp_generate_password(48, false, false)`; `hash_equals()` |
| Feed path enumeration | Direct file read | Private salted directory, `.htaccess`/`web.config` deny, `index.php`; nginx deployments must add the documented `location` deny rule; canonical access is streamed by WordPress |
| Unauthorized order injection | Fake orders, fraud | Shared secret required; 401 when no key is configured (fail closed) |
| Replay / duplicate orders | Inventory and accounting errors | UNIQUE `external_id` (binary collation) claim; identical duplicate returns 200; changed duplicate returns 409 |
| API key brute force | Unauthorized access | 48-char key, constant-time compare, failed-auth throttle before the key comparison (30/min/client), authenticated rate limit (120/min/client), optional IP allowlist |
| MITM of order traffic | Data exposure | HTTPS required by default; `is_ssl()` is authoritative; forwarding headers are accepted only from an explicitly trusted proxy |
| Body flooding | Memory/CPU exhaustion | Configurable body limit (min 16 KB, default 512 KB), failed-auth and authenticated rate limits, bounded rejection logging |
| Malicious payload values | Stored XSS, SQL injection | JSON decoding, strict type/range/format validation, prepared SQL, escaped output |
| Label upload abuse | Disk exhaustion, arbitrary file write | Strict base64 decode, 8 MB cap, `%PDF-` magic check, random filename, private directory, retention job |
| Secret leakage in logs | Credential compromise | `Redactor` masks key names, e-mail, phone, name, address and other PII, and truncates long values |
| Sensitive data retention | Privacy breach | Raw payloads are off by default; label PDFs are purged on a configurable schedule; open orders are protected |
| Privilege escalation | Unauthorized configuration | `manage_woocommerce` capability on every admin action and AJAX handler |
| CSRF | Unauthorized state change | `check_admin_referer` / `check_ajax_referer` on every mutating handler |
| SQL injection through search/filters | Data disclosure | `$wpdb->prepare()`, `esc_like()`, whitelisted columns |
| Direct core-table corruption | Store breakage | No writes to core tables; CRUD API for products/orders; plugin tables only |
| Data loss on uninstall | Irreversible loss | Destructive uninstall is opt-in and off by default |

## 3. Authentication and authorization

### Feed endpoint

- `feed_public = false` (default) requires a token in `?token=` or the
  `X-WPS-Feed-Token` header.
- Comparison: `hash_equals( $expected, $provided )`.
- No key configured and not public → 403.
- The feed is never generated during an HTTP request; only a pre-built file is
  streamed.

### Order endpoint

- Order of checks: enabled → client address → HTTPS → failed-auth throttle → key
  → IP allowlist → authenticated rate limit → size → JSON → business validation.
- The transport check runs before the key is read or compared, so a plaintext
  request never exposes its credential to the comparison path.
- Key sources: `?apikey=`, `X-WPS-Api-Key`, `X-Api-Key`.
- `hash_equals()`; missing configuration logs a bounded rejection and rejects
  everything. A key defined in `wp-config.php` must be at least 32 characters.
- IP allowlist is optional and parsed from a comma/space/semicolon list.
- The client address is `REMOTE_ADDR` unless the direct peer is in
  `order_api_trusted_proxies`; forwarding headers are never trusted otherwise.
- HTTPS enforcement: `is_ssl()` is authoritative. `X-Forwarded-Proto: https` is
  accepted only from a trusted proxy, using the rightmost value.
- Failed authentication: 30 attempts/minute/client, checked before the key
  comparison; a correct key is still accepted below the limit.
- Authenticated rate limit: 120 requests/minute/client via transient, filterable;
  `0` disables.
- Rejections are logged at most once per minute per client and reason, with a
  global budget of 120 rows/minute.

### Admin

- Menu and every handler require `manage_woocommerce`.
- Metabox save uses its own nonce (`wps_pepita_product_save`) and checks
  `edit_product`.
- Category AJAX checks `manage_product_terms`.
- Every `admin-post.php` action calls `check_admin_referer()` with a dedicated
  action name.

## 4. Input validation

| Input | Validation |
|---|---|
| External order ID | Non-empty string, max 191 chars; stored with `utf8mb4_bin` collation so case variants are distinct |
| E-mail | `is_email()` |
| Quantity | Whole number, 1–100000 |
| Price | Strict canonical amount > 0; no thousands separators, no comma decimals, max two decimal places, upper bound 1e9 |
| VAT | 0–100, at most two decimals |
| Currency | `^[A-Z]{3}$`; all lines must match |
| Shipping / voucher | Strict canonical non-negative amount; total must not go negative |
| Payment mode | `cod`, `transfer`, `creditcard` only |
| Delivery mode | `shipping`, `gls`, `gls_parcellocker`, `gls_xxl`, `mpl` only |
| Payment status | Exactly `paid` or `unpaid` (missing → `unpaid`) |
| Product reference | Must resolve to a published, non-variable product; SKU and Pepita ID must agree when both are present |
| Package label | Strict base64, max 8 MB, must start with `%PDF-` |
| Feed type | Whitelisted `full` / `stock` (plus configured slugs) |
| Settings | Type, range and enum sanitisation per key; maps restricted to known modes |
| GTIN | 8, 12, 13 or 14 digits; never fabricated |
| URLs | Absolute `http`/`https` only |

## 5. Output and storage

- Admin views escape with `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`,
  `wp_kses_post`.
- XML text is escaped by `XMLWriter`; invalid UTF-8, C0 controls and Unicode
  noncharacters are removed before writing.
- Logs are redacted: `apikey`, `token`, `secret`, `password`, `authorization`,
  `cookie`, `package_label`, e-mail, phone, name, address, city, postcode, tax
  number and customer/courier messages are masked.
- Secrets live in a non-autoloaded option or in `wp-config.php` constants; the
  constant wins.
- Package labels are written to the private storage directory, never to a public
  uploads path. Expired labels of closed orders are deleted and their order meta
  is cleared; labels of open orders are retained.
- Raw order payloads are not stored by default; only the SHA-256 hash is kept.
  When enabled, stored payloads are purged on the configured schedule.
- Token-authenticated feed responses are sent with `Cache-Control: private,
  no-store`; only an explicitly public feed is cacheable.
- The catalog index and in-flight files share the same protected directory.

## 6. Database

- Only two custom tables, created with `dbDelta`.
- All queries use `$wpdb->prepare()`; table names are interpolated but derived
  from `$wpdb->prefix` and fixed suffixes.
- Reads may join `{$wpdb->postmeta}` / `{$wpdb->posts}` for admin filtering; the
  plugin never writes to them with raw SQL.
- A test (`120-prefix-test.php`) scans the source for `INSERT`/`UPDATE`/`DELETE`/
  `TRUNCATE` statements against core tables and fails the suite if one appears.
- Uninstall deletes only prefixed options, plugin tables and the plugin's own
  meta keys, and only when explicitly enabled.

## 7. Availability and integrity

- Generation locks use an owner token with TTL; a lost lock aborts safely.
- Reclaiming an expired lock is serialised with a short-lived option mutex, so
  two processes cannot both acquire the generation lock.
- Publishing is atomic (`rename()`); readers never see a partial feed.
- The previous feed is preserved for rollback and diagnostics.
- Stock feed publication is blocked when its signature differs from the last
  full feed.
- Empty feeds are refused unless explicitly allowed.
- A failed generation never replaces the last good feed.

## 8. Verification performed

| Check | Method | Result |
|---|---|---|
| No Allegro symbols or references | Automated source scan (`prefix:` tests) | Pass |
| All globals namespaced | Automated source scan | Pass |
| No direct core table writes | Automated source scan | Pass |
| No text-domain drift | Automated source scan | Pass |
| Secret and PII redaction | Unit cases `116-redactor-test.php` | Pass |
| Strict monetary parsing | Unit cases `115-money-test.php` + import cases | Pass |
| Auth, proxy spoofing, HTTPS, failed-auth, rate, size limits | `110-api-controller-test.php` | Pass |
| Idempotency, conflict and crash recovery | `90-order-repository-test.php`, `100-order-import-test.php` | Pass |
| Orphan-order cleanup on save failure | `100-order-import-test.php` | Pass |
| Payload and label retention | `90-order-repository-test.php`, `150-retention-test.php` | Pass |
| Lock reclaim | `80-feed-generation-test.php` | Pass |
| XML noncharacters | `50-xml-writer-test.php` | Pass |
| E-mail suppression | Automated assertion during `save()` | Pass |
| No gateway execution | Code review (no gateway calls) | Verified |
| No "paid" without `payment_status` | Automated import cases | Pass |
| PHP syntax | `php -l` on every file | Pass |

The unit suite runs against WordPress/WooCommerce stubs on PHP 8.5. The
integration suite (real WordPress, WooCommerce, MySQL, HPOS and legacy storage)
is defined in `integration/`; its execution status for this revision is recorded
in `TESTING.md` and the remediation report. Static and stubbed evidence alone is
not a substitute for the live M9 staging validation.

## 9. Residual risks

1. **Live-environment validation is pending.** The unit suite proves the
   remediation against stubs; the real WordPress/WooCommerce/MySQL integration
   suite and the M9 Pepita staging validation must still run before production
   order intake.
2. **Public feed mode.** Enabling `feed_public` intentionally removes the token
   check. It is off by default and should stay off unless the catalog is public.
3. **Proxy configuration.** Forwarding headers are trusted only from
   `order_api_trusted_proxies`. A misconfigured list (for example, trusting a
   range that contains untrusted clients) would reintroduce spoofing; keep the
   list to the actual ingress addresses.
4. **Rate limiting is per client.** A distributed attack can exceed it; a WAF is
   still recommended.
5. **Label size.** Labels up to 8 MB are accepted. A malicious sender with a
   valid key can consume disk until the retention job runs; monitor the private
   directory.
6. **Log retention.** Logs contain masked personal data; keep retention short
   (default 30 days) and restrict database access.
7. **Secrets in the database.** If not overridden by constants, secrets are
   readable by anyone with database access, as with any WordPress option.
8. **Trust in Pepita.** Pricing, paid status and product identity are taken from
   the payload. Anyone holding the key can create paid orders at a chosen price.
   This is an architectural trust decision; protect the key and the transport.
9. **Shop Manager capability (PV-06).** Admin screens use `manage_woocommerce`,
   which Shop Managers normally hold. This is accepted as a deliberate
   store-operator role; a narrower capability would be a behaviour change for
   existing stores.
10. **Integration image pinning (CF-13).** Ports are bound to `127.0.0.1` and
    image tags are version-pinned, but the compose file does not pin image
    digests. Digest pinning is deferred until the images can be pulled and
    verified in an environment with Docker access.
11. **Backdating by a key holder (PV-11).** `date` and `payment_status` drive
    `date_created`/`date_paid`. This is accepted under the Pepita trust model.
12. **Stale-claim overlap (PV-08).** A single import that runs longer than the
    15-minute stale window can overlap a retry. Imports are bounded by the body
    limit and resolve all products before creation, so the window is considered
    acceptable; a heartbeat is a possible future hardening.
13. **nginx private storage (PV-04).** `.htaccess` is not read by nginx. The
    required `location` deny rule is documented in `INSTALLATION.md`; a
    deployment that omits it relies on the random storage directory name.
14. **Tax representation (independent re-review NEW-1 — RESOLVED).** Resolved with
    correct tax-aware order creation. Pepita gross prices remain authoritative;
    in tax-enabled stores (`woocommerce_calc_taxes=yes`), gross amounts are cleanly
    partitioned into net totals and tax rows based on configured WooCommerce tax rates,
    preserving exact total amounts, standard `WC_Order_Item_Tax` lines, taxable shipping
    breakdown, and compatibility with invoicing plugins without adding VAT a second time.
    Mismatched or invalid tax rates fail safely with 422 before order creation.
15. **Changed-content duplicates (independent re-review NEW-2 — RESOLVED).** A re-send
    of the same external id with a different payload hash returns 409 Conflict. In accordance
    with security requirements, existing orders are never overwritten, duplicate orders are
    never created, a redacted reconciliation event is logged, and an operator order note is
    recorded on the existing WooCommerce order to keep store staff informed. Clarification
    questions for Pepita's lifecycle contract have been documented.
16. **Custom order statuses and label retention (independent re-review NEW-7).**
    Labels are deleted only for the known-closed statuses `completed`,
    `cancelled`, `refunded`, `failed` and `trash`. Custom statuses are treated as
    open and retained, which is the safe default.

## 10. Recommendations

- Define `WPS_PEPITA_FEED_TOKEN` and `WPS_PEPITA_ORDER_API_KEY` in
  `wp-config.php` with at least 32 random characters.
- Keep `order_api_require_https` enabled and terminate TLS at the edge; add only
  the real ingress addresses to `order_api_trusted_proxies`.
- Set an IP allowlist if Pepita publishes stable source addresses.
- Keep `order_api_keep_payloads` off; if enabled, use a short retention window.
- Review the package-label retention window against fulfilment practice.
- Add the nginx deny rule from `INSTALLATION.md` when the site does not run on
  Apache with `AllowOverride` enabled.
- Keep WordPress, WooCommerce and PHP patched.
- Review **Napló** for repeated `invalid_apikey`, `ip_not_allowed`,
  `failed_auth_limited` or `rate_limited` events.
- Back up the database and the private feed directory before upgrades.
