# WP Pepita Sync — Error Codes & Diagnostic Reference

> Derived from the plugin code only (file references in each section). Codes that earlier drafts of this
> document listed but that **do not exist in the code** (`unauthorized`, `bad_request`, `validation_failed`,
> `unresolved_product`, `currency_mismatch`, `conflict`, `label_save_failed`, `missing_mandatory_field`,
> `malformed_gtin`, `variable_missing_axes`, `feed_shrinkage_detected`, `lock_held`, `unsupported_schema`,
> `checksum_mismatch`, `sensitive_key_stripped`, `category_update_failed`) were removed.
> Order API responses carry **no symbolic error code** — only `isError`, `responseCode` (= HTTP status)
> and a Hungarian `message` (see [API-CONTRACT.md §4–5](API-CONTRACT.md#4-response-format)).

---

## 1. Order API (`POST /wp-json/wps/v1/pepita/order`)

Response body (all outcomes): `{"isError": bool, "responseCode": int, "message": string[, "orderId": int]}`.
The HTTP status below is the stable signal; the message text is for humans.

| HTTP | Meaning | Typical cause / resolution |
|---|---|---|
| `200` | Order created **or** identical replay acknowledged (no new order) | Both return `isError:false` and an `orderId`. They differ only by `message` text. |
| `400` | Missing/invalid `id` (empty, non-scalar, > 191 chars), empty body, or body that is not valid JSON | Fix the request; do not retry unchanged. |
| `401` | Key not configured, key defined by constant is shorter than 32 chars, wrong/missing key, **or** source IP not in `order_api_allowed_ips` | Check the key (`apikey` query param, `X-WPS-Api-Key` or `X-Api-Key` header) and the IP allowlist. Wrong keys count towards the failed-auth throttle. |
| `403` | Plain HTTP while `order_api_require_https` is on (key is **not** evaluated) | Use HTTPS, or configure `order_api_trusted_proxies` so a trusted TLS-terminating proxy sets `X-Forwarded-Proto: https`. |
| `409` | (a) Same `id` still **processing**; (b) processing lease taken over / orphan cleanup failed; (c) **conflicting replay** — same `id`, different payload | (a)(b) retry later. (c) Do **not** retry: the existing order is left unchanged and an operator note + `order_duplicate` log entry are written; send amendments with a new `id` (verify process with Pepita). |
| `413` | Body larger than `order_api_max_body_kb` (default 512 KB) | Reduce payload (e.g. oversized `package_label`) or raise the setting (16–8192). |
| `422` | Payload validation failed — nothing was created | Read `message`: missing products/customer/e-mail; invalid quantity, price, VAT, currency or amounts; unknown `payment_mode` / `delivery_mod` / `payment_status`; SKU/Pepita id unresolved or ambiguous; variable parent product; unpublished product; VAT rate differs from WooCommerce tax configuration; negative or over-limit total. After correction the same `id` may be re-sent. |
| `429` | Failed-auth throttle (default 30/min/client) **or** request rate limit (default 120/min/client) | Wait about a minute. Tunable via filters `wps_pepita_order_failed_auth_limit` / `wps_pepita_order_rate_limit`. |
| `500` | Claim table unavailable, WooCommerce order creation failed, unexpected exception, or a "completed" claim without a finalized WooCommerce order | Inspect *Pepita → Napló*; for the last case run `wp wps-pepita repair-claim <external_id>`. |
| `503` | Feed-only beta mode active, or `order_api_enabled` off | Intentional kill switches (see [INTEGRATION-GUIDE.md §6](INTEGRATION-GUIDE.md#6-documented-limits)). |

### 1.1 Order API rejection log reasons (`Logger`, event `order_api`)

Written at most once per client/reason/minute and at most 120 rows/minute overall
(`includes/orders/class-api-controller.php:525-567`). Context never contains the submitted key.

| `reason` | HTTP returned |
|---|---|
| `not_configured` | 401 |
| `weak_configured_key` | 401 |
| `invalid_apikey` | 401 |
| `ip_not_allowed` | 401 |
| `failed_auth_limited` | 429 |
| `rate_limited` | 429 |

### 1.2 Order import log events

`order_import` (validation rejections, unexpected exceptions, invalid label skipped), `order_recovery`
(adopting/cleaning half-finished orders, lease loss), `order_duplicate` (changed-payload replay refused;
context field `reconciliation_event = changed_payload_refused`) — all in `includes/orders/class-importer.php`.
Logged payloads are redacted (`Redactor::redact()`).

---

## 2. Feed validation (per product)

Blocking **errors** exclude the product from the feed and list it under *Pepita → Hibás termékek*.
Sources: `includes/catalog/class-validator.php`, `class-product-mapper.php`, `includes/admin/class-product-issues.php`.

| Code | Description | Merchant action |
|---|---|---|
| `missing_product` | Product not found / not a WooCommerce product | Check the product still exists. |
| `missing_identifier`, `identifier_too_long` | No stable Pepita id, or id > 191 chars | Contact support; do not edit `_wps_export_id` by hand. |
| `missing_name`, `missing_description` | Mandatory text missing | Complete in WooCommerce. |
| `invalid_price` | Gross price is 0 or invalid (Pepita does not accept price 0) | Set a positive price. |
| `invalid_vat` | VAT outside 0–100 % | Check the WooCommerce tax class/settings. |
| `invalid_currency` | Currency is not a valid ISO 4217 code | Check store currency. |
| `missing_category` | No (allowed/mapped) category assigned | Assign an included category; see category rules. |
| `missing_image` | No usable featured or gallery image | Add a JPEG/PNG of at least 1200×630 px. |
| `invalid_product_url` | Product URL invalid | Check permalinks/site URL. |
| `invalid_gtin` | `gtin` present but not 8 or 12–14 digits (`class-validator.php:102-106`). The code checks the **length only**; no check-digit calculation was found in the code | Enter the real GTIN/EAN/ISBN from the manufacturer. |
| `gtin_required` | The product's category is marked "GTIN required" but no valid GTIN is stored. The plugin never generates a code (`class-product-mapper.php:186-191`) | Enter the real GTIN, or un-require GTIN for that category only if Pepita confirms it is not needed (verify with Pepita). |
| `missing_variations`, `invalid_variation_structure`, `too_many_variation_attributes` (max 3), `no_published_variations`, `duplicate_variation`, `invalid_variation_price` | Variable product structure problems | Fix attributes/variations in WooCommerce. |

Non-blocking **warnings** (product still exported): `sale_price_ignored`, `long_name`, `no_primary_image`,
`invalid_video_url`, `stock_quantity_capped` (stock above 999 999 is capped in the feed only),
`insufficient_image_dimensions` (image < 1200×630; reported in preflight/issues, no upscaling),
`invalid_image_file` (truncated/undecodable image), `margin_below_threshold` (pricing-strategy margin).
Product-issue list also uses `missing_gtin` (category requires GTIN), `missing_sku` (optional) and
`validation_error`.

### 2.1 Feed publication safety (generator)

| Condition | Behaviour (`includes/feed/class-generator.php:596-720`, `class-catalog-diff.php`) |
|---|---|
| Stock feed catalog signature ≠ last full feed | Stock feed **not published**; full feed marked dirty; message asks for a full regeneration. |
| No successful full feed yet | Stock feed not published. |
| Generated feed would be empty | Previous feed kept (unless empty feeds are explicitly allowed). |
| Catalog drop ≥ `feed_safety_drop_threshold` (default **20 %**), emptied feed, drastic price drops (≥ `feed_safety_max_price_drop_percent`, default 50 %, for at least the configured number of products), variation structure changes, mass quality exclusions | Always logged (`feed_safety`) and shown as warnings. **Publication is blocked only if** `feed_safety_block_publication` is on, and **held for approval only if** `feed_safety_require_approval` is on. **Both default to off**, so by default these conditions warn but still publish. |
| Feed endpoint | `404` unknown feed, `403` wrong token, `503` (with `Retry-After`) when emergency-disabled / not yet generated / unreadable (`includes/feed/class-endpoint.php`). |

---

## 3. Merchant profile import/export

Profile validation returns **free-text Hungarian messages, not symbolic codes**
(`Merchant_Profiles::validate_profile()`, `includes/admin/class-merchant-profiles.php`; this file may still
change in the current branch — re-check before relying on exact wording):

| Condition | Level |
|---|---|
| `schema_version` missing, not an integer, or not `1` | Error |
| `settings` missing/not an array | Error |
| Category rules not an array | Error |
| `checksum` present but empty/invalid format | Error |
| `checksum` present but does not match the canonical content (corrupted/edited file) | Error |
| `checksum` absent while the caller requires one | Error |
| Secret key (`feed_token`, `order_api_key`, …) in the file | Warning — ignored on import |
| Setting outside `PORTABLE_SETTINGS_ALLOWLIST` (security toggles such as `order_api_enabled`, `order_api_require_https`, `feed_public`) | Warning — ignored on import |

Interrupted imports are detected through the recovery journal and logged with the event
`profile_import_recovered`; rollback failures are reported as `rollback_errors` text. A stored lock loss is
logged as `profile_import_lock_lost`.

---

## 4. Operational report error classes (fixed codes)

Operational summaries and the CSV export (*Pepita → Eszközök és riportok*) **never contain raw free-text
errors**. A raw `last_error` is only used as classifier input and is replaced by one of these fixed codes
(`includes/admin/class-operational-reports.php`, `ERROR_CLASSES`; classification is pattern-based and
therefore approximate):

`replay_conflict`, `sku_not_found`, `payment_mode`, `stock_problem`, `shipping_problem`, `database_error`,
`validation_error`, `order_creation`, and `unclassified_error` (fallback).

Untrusted identifiers are exported only if they match a strict safe pattern (`^[A-Za-z0-9_-]{1,64}$` for the
Pepita id; short marketplace-style origins), otherwise as a salted HMAC hash prefix (`hash:…`) or `other`.
CSV cells are formula-neutralised. The raw error text remains available only to authorised administrators
(`manage_woocommerce`) in the per-order admin view and logs.
