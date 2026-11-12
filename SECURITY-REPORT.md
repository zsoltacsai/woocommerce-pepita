# SECURITY-REPORT — WP Pepita Sync 1.0.0

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
| Feed path enumeration | Direct file read | Private salted directory, `.htaccess`/`web.config` deny, `index.php`; canonical access is streamed by WordPress |
| Unauthorized order injection | Fake orders, fraud | Shared secret required; 401 when no key is configured (fail closed) |
| Replay / duplicate orders | Inventory and accounting errors | UNIQUE `external_id` claim; duplicate returns 200 without creating an order |
| API key brute force | Unauthorized access | 48-char key, constant-time compare, per-IP rate limit (120/min), optional IP allowlist |
| MITM of order traffic | Data exposure | Optional HTTPS enforcement; production guidance requires HTTPS |
| Body flooding | Memory/CPU exhaustion | Configurable body limit (min 16 KB, default 512 KB), rate limit |
| Malicious payload values | Stored XSS, SQL injection | JSON decoding, type/range validation, prepared SQL, escaped output |
| Label upload abuse | Disk exhaustion, arbitrary file write | Strict base64 decode, 8 MB cap, `%PDF`-independent but random filename and private directory; extension fixed to `.pdf` |
| Secret leakage in logs | Credential compromise | `Redactor` masks key names and truncates long values |
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

- Order of checks: enabled → key → IP allowlist → HTTPS → rate limit → size →
  JSON → business validation.
- Key sources: `?apikey=`, `X-WPS-Api-Key`, `X-Api-Key`.
- `hash_equals()`; missing configuration logs an error and rejects everything.
- IP allowlist is optional and parsed from a comma/space/semicolon list.
- HTTPS enforcement accepts `is_ssl()` or `X-Forwarded-Proto: https`.
- Rate limit: 120 requests/minute/IP via transient, filterable; `0` disables.
- Rejections are logged at most once per minute per IP and reason to avoid log
  flooding.

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
| External order ID | Non-empty string, max 191 chars (column width) |
| E-mail | `is_email()` |
| Quantity | Integer > 0 |
| Price | Finite float > 0 |
| VAT | 0–100 |
| Currency | `^[A-Z]{3}$`; all lines must match |
| Shipping / voucher | Finite, non-negative; total must not go negative |
| Payment mode | `cod`, `transfer`, `creditcard` only |
| Delivery mode | `shipping`, `gls`, `gls_parcellocker`, `gls_xxl`, `mpl` only |
| Product reference | Must resolve to a published, non-variable product |
| Feed type | Whitelisted `full` / `stock` (plus configured slugs) |
| Settings | Type, range and enum sanitisation per key; maps restricted to known modes |
| GTIN | 8, 12, 13 or 14 digits; never fabricated |
| URLs | Absolute `http`/`https` only |

## 5. Output and storage

- Admin views escape with `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`,
  `wp_kses_post`.
- XML text is escaped by `XMLWriter`; invalid UTF-8 and control characters are
  removed before writing.
- Logs are redacted: `apikey`, `token`, `secret`, `password`, `authorization`,
  `cookie`, `package_label`, e-mail and phone values are masked.
- Secrets live in a non-autoloaded option or in `wp-config.php` constants; the
  constant wins.
- Package labels are written to the private storage directory, never to a public
  uploads path.
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
| Secret redaction | Code review + unit-level behaviour | Implemented |
| Auth, IP, HTTPS, rate, size limits | 11 automated API cases | Pass |
| Idempotency and crash recovery | 9 repository + 13 import cases | Pass |
| E-mail suppression | Automated assertion during `save()` | Pass |
| No gateway execution | Code review (no gateway calls) | Verified |
| No "paid" without `payment_status` | Automated import cases | Pass |
| PHP syntax | `php -l` on every file | Pass |

## 9. Residual risks

1. **Real-environment testing is pending.** Auth, HPOS, cron and HTTP headers are
   proven against stubs, not a live WordPress. Complete the manual plan in
   `TESTING.md` §6 before going live.
2. **Public feed mode.** Enabling `feed_public` intentionally removes the token
   check. It is off by default and should stay off unless the catalog is public.
3. **Proxy headers.** `X-Forwarded-Proto` is trusted for the HTTPS check only;
   an attacker who can inject that header on a plain-HTTP connection could bypass
   the transport check. Terminate TLS at the edge and do not expose the origin.
4. **Rate limiting is per IP.** A distributed attack can exceed it; a WAF is
   still recommended.
5. **Label size.** Labels up to 8 MB are accepted. A malicious sender with a
   valid key can consume disk; monitor the private directory.
6. **Log retention.** Logs contain masked personal data; keep retention short
   (default 30 days) and restrict database access.
7. **Secrets in the database.** If not overridden by constants, secrets are
   readable by anyone with database access, as with any WordPress option.

## 10. Recommendations

- Define `WPS_PEPITA_FEED_TOKEN` and `WPS_PEPITA_ORDER_API_KEY` in
  `wp-config.php`.
- Enable `order_api_require_https` in production.
- Set an IP allowlist if Pepita publishes stable source addresses.
- Keep WordPress, WooCommerce and PHP patched.
- Review **Napló** for repeated `invalid_apikey`, `ip_not_allowed` or
  `rate_limited` events.
- Back up the database and the private feed directory before upgrades.
