# WP Pepita Sync — Order API Contract

> **Source of truth.** Everything below is derived from the plugin code
> (`includes/orders/class-api-controller.php`, `class-importer.php`, `class-money.php`,
> `class-mapper.php`, `class-order-repository.php`, `includes/class-settings.php`) and the
> repository tests (`tests/cases/100-…`, `105-…`, `106-…`, `110-…`, `200-…`).
> The request example in §3 was **verified by reading the importer validation code. It was not
> executed** (PHP is not available in the documentation environment, and no live Pepita API,
> staging or production system was contacted).
> Anything the code does not define is marked **"verify with Pepita"** instead of being guessed.
>
> **Status:** order intake is **not approved for live traffic** — see
> [INTEGRATION-GUIDE.md §6 Documented limits](INTEGRATION-GUIDE.md#6-documented-limits).

---

## 1. Endpoint, transport and authentication

| Item | Value | Source |
|---|---|---|
| Method / URL | `POST /wp-json/wps/v1/pepita/order` | `class-api-controller.php:32-33,68-76` |
| Body | JSON object (`Content-Type: application/json`) | `class-api-controller.php:130-134` |
| Transport | HTTPS required by default (`order_api_require_https` = `true`) | `class-api-controller.php:196-220`, `class-settings.php:122` |
| Authentication | Shared secret (`order_api_key`) — **no** Bearer token, **no** `X-Pepita-Key`, **no** payload signature | `class-api-controller.php:148-178` |

Route `permission_callback` is `__return_true`; authentication is performed inside the handler.

### 1.1 API key placement (checked in this order, first non-empty value wins)

1. Query parameter `apikey` — e.g. `POST /wp-json/wps/v1/pepita/order?apikey=<key>` (this is the URL shown in the admin screen *Pepita → Rendelés API*, `includes/views/api.php:14-18`)
2. Header `X-WPS-Api-Key: <key>`
3. Header `X-Api-Key: <key>`

The key is compared with `hash_equals()`. A key in the query string can end up in web-server/proxy
access logs; use a header where the sender supports it.

Key facts about the secret:

- A 48-character key is generated automatically on activation when none exists (`Settings::ensure_tokens()`, `class-settings.php:269-281`, called from `class-installer.php:22,63`).
- It can instead be defined with the `WPS_PEPITA_ORDER_API_KEY` constant in `wp-config.php`; such a key must be **at least 32 characters**, otherwise every request is rejected with `401` (`class-api-controller.php:157-162`).
- Empty/unset key → `401` ("not configured") (`:151-155`).

### 1.2 HTTPS and proxies

A request is accepted when `is_ssl()` is true, **or** `order_api_require_https` is switched off, **or** the direct peer (`REMOTE_ADDR`) is listed in `order_api_trusted_proxies` and the **rightmost** value of `X-Forwarded-Proto` is `https`. `X-Forwarded-Proto` from an untrusted peer is ignored (`:196-220`; test `110-…:185-241`). The transport check runs **before** the key is read, so a key sent over plain HTTP is never compared.

### 1.3 Optional source-IP allowlist

If `order_api_allowed_ips` is non-empty, the (valid-key) request must come from a listed IP; otherwise the response is **`401`** (not 403) with `A kérés forrás IP címe nincs engedélyezve.` (`:180-188`). The client IP is `REMOTE_ADDR` unless that peer is a trusted proxy; then `X-Forwarded-For` (else `CF-Connecting-IP`, else `X-Real-IP`) is walked right-to-left and the first untrusted hop is used. An unparseable hop resolves to `0.0.0.0` (fails closed) (`:452-490`). `order_api_trusted_proxies` accepts IPs and CIDR ranges; a `/0` prefix is rejected (`:360-379`).

---

## 2. Request processing order

Checks run in exactly this order (`class-api-controller.php:83-143`); the first failing check returns:

1. Feed-only beta mode active → `503`
2. `order_api_enabled` off → `503`
3. HTTPS/transport not allowed → `403`
4. Failed-auth throttle exceeded → `429`
5. Authentication / IP allowlist failure → `401`
6. Per-minute request limit exceeded → `429`
7. Body larger than `order_api_max_body_kb` → `413`
8. Empty body → `400`
9. Body not a valid JSON object/array → `400`
10. Importer (id, claim, validation, order creation) → `200` / `400` / `409` / `422` / `500`

---

## 3. Request body

JSON object. Field names below are the ones the importer reads. Values are only read, never
trusted: monetary/VAT/quantity fields go through the strict parsers in `class-money.php`.

### 3.1 Top-level fields

| Field | Required | Rule (source) |
|---|---|---|
| `id` | **yes** | Scalar, trimmed, 1–191 characters. This is the idempotency key (`external_id`). Missing/empty/too long → `400` (`Importer::external_id()` `class-importer.php:1356`, `import()` `:47-49`) |
| `products` | **yes** | Non-empty array of line objects (§3.2) (`Importer::validate()` `class-importer.php:277`) |
| `customer` | **yes** | Object (§3.3) with a valid `email` (`validate()`) |
| `total_shipping_price` | no | Missing/`null` → `0`. Otherwise a valid amount ≥ 0 (zero allowed) (`validate()`, `Money::parse_optional()` `class-money.php:88`) |
| `voucher` | no | Same rule as shipping. Amount > 0 becomes a negative fee line `Pepita utalvány` (`validate()`; fee created in `create_order()` `class-importer.php:881`) |
| `payment_mode` | no | If non-empty, must be one of `cod`, `transfer`, `creditcard` (after `sanitize_key`) else `422` (`validate()` `class-importer.php:374`; `Mapper::PAYMENT_LABELS` `class-mapper.php`) |
| `delivery_mod` | no | If non-empty, must be one of `shipping`, `gls`, `gls_parcellocker`, `gls_xxl`, `mpl` else `422` (`validate()` `class-importer.php:380`; `Mapper::SHIPPING_LABELS` `class-mapper.php`). Note the field name is `delivery_mod` (not `delivery_mode`) |
| `payment_status` | no | Empty → treated as `unpaid`. Only `paid` or `unpaid` accepted, anything else → `422` (`validate()` `class-importer.php:390`) |
| `origin` | no | Stored on the claim and order meta; default `pepita.hu` for the order meta (`class-importer.php:925`) |
| `date` | no | Parsed with `strtotime()`; unparseable values are ignored (order dated "now") (`Importer::parse_date()` `class-importer.php:1305`) |
| `customer_message`, `courier_message` | no | Stored as order meta (`class-importer.php:939-940`; the customer message is also added as an order note) |
| `package_label` | no | Base64 PDF. Saved only if `order_api_save_label` is on, decodes strictly, is ≤ 8 MB and starts with `%PDF-`. **An invalid label does not fail the order** — it is skipped with a logged warning (`Importer::apply_package_label()` `class-importer.php:1267`) |
| `status`, others | no | Not used to set the WooCommerce status (only mentioned in operator notes/log context). Unknown fields are ignored for processing **but still change the replay hash** (§6) |

### 3.2 Line objects (`products[]`)

| Field | Required | Rule (source) |
|---|---|---|
| `quantity` | **yes** | Positive whole number, 1–100000. JSON integer or digit string. Floats, `0`, negatives → `422` (`Money::parse_quantity()` `class-money.php:122`) |
| `price` | **yes** | Unit price, **gross (VAT-inclusive)**, must be **> 0**. JSON number, or string matching `^(0\|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$`. No thousands separators, spaces, comma decimals, exponents, signs; max 2 decimals; max `1000000000` (`Money::parse()` `class-money.php:45`) |
| `vat` | no | Percentage 0–100, ≤ 2 decimals; missing/`null`/`""` → `0` (`Money::parse_vat()` `class-money.php:102`) |
| `currency` | no | Empty or 3 ASCII letters (upper-cased). All lines that carry a currency must agree; otherwise `422`. If no line carries one, the store currency is used (`validate()`). **The code does not compare the order currency with the store currency** — verify with Pepita/merchant that they match |
| `sku` | one of `sku` / `id` | Resolved with `wc_get_product_id_by_sku()` (`Importer::resolve_products()` `class-importer.php:420`) |
| `id` | one of `sku` / `id` | Pepita/export product identifier, matched against the product meta `_wps_export_id` (`resolve_products()`, `find_product_by_export_id()`) |

Product resolution rules (all failures are `422`, nothing is created — `Importer::resolve_products()`, `class-importer.php:420`):

- Neither `sku` nor `id` matches a product → "nem azonosítható egyetlen WooCommerce termékkel sem".
- `sku` and `id` match **different** products → rejected.
- `sku` matches but the product has a *different* `_wps_export_id` than the line `id` → rejected.
- Product is a **variable parent** (the variation cannot be identified from the payload) → rejected.
- Product status is not `publish` → rejected.
- If WooCommerce taxes are enabled, the customer is not `is_vat_exempt: "yes"` and the product is taxable: the line `vat` must equal the configured WooCommerce rate for the destination (tolerance 0.01 percentage points); no configured rate with `vat > 0` → rejected. A tax-exempt product with `vat > 0` → rejected (`resolve_products()`).

A single bad line aborts the whole order (all-or-nothing).

### 3.3 `customer` object

| Field | Required | Notes |
|---|---|---|
| `email` | **yes** | Must pass WordPress `is_email()` else `422` |
| `first_name`, `last_name` | no | If both empty, `billing_name` is split (first token = last name) (`Importer::apply_customer()` `class-importer.php:1208`) |
| `billing_name` | no | Also stored as billing company |
| `phone` | no | |
| `billing_country`, `billing_city`, `billing_postal_code`, `billing_street`, `billing_house_number` | no | Street is `billing_street`, else `billing_street_address` + `billing_house_number` (`Importer::street()` `class-importer.php:1251`) |
| `shipping_country`, `shipping_city`, `shipping_postal_code`, `shipping_street`, `shipping_house_number` | no | Same pattern; shipping country/city/postcode fall back to billing |
| `tax_number` | no | Stored as `_billing_vat_number` |
| `is_vat_exempt` | no | `"yes"` disables the VAT cross-check |

Orders are never attached to an existing user account that has `manage_woocommerce` or `edit_users`; otherwise they are attached to the user with the same e-mail (`create_order()` `class-importer.php:672`, `is_privileged_user()` `:1592`). Customer e-mails are suppressed during import (`suppress_emails()` `:1327`).

### 3.4 Verified example (code-verified, **not executed**)

Walked through `Importer::validate()` line by line: id present; one product line with integer
`quantity` 2, `price` 1000 (> 0, ≤ 2 decimals), `vat` 27 (0–100), `currency` `HUF`; customer e-mail valid;
`total_shipping_price` 1490 and `voucher` 0 parse as optional amounts; computed total
2 × 1000 + 1490 − 0 = 3490 (≥ 0, below the limit); `payment_mode` `transfer` and `delivery_mod` `gls` are known
values; `payment_status` `paid` is allowed. Product resolution additionally requires that a **published, simple**
WooCommerce product with SKU `SKU-1` exists (and, if it already has a `_wps_export_id`, that it equals `wps-501`),
and — only when WooCommerce taxes are enabled — a configured tax rate of 27 % for the destination.
This mirrors the fixture in `tests/cases/100-order-import-test.php:17-59`.

```json
{
  "id": "PEP-SYNTH-0001",
  "origin": "pepita.hu",
  "date": "2026-11-15T10:30:00+01:00",
  "status": "NEW",
  "payment_mode": "transfer",
  "payment_status": "paid",
  "delivery_mod": "gls",
  "total_shipping_price": 1490,
  "voucher": 0,
  "customer": {
    "email": "synthetic.customer@example.test",
    "first_name": "Anna",
    "last_name": "Teszt",
    "phone": "+36000000000",
    "billing_country": "HU",
    "billing_city": "Budapest",
    "billing_postal_code": "1011",
    "billing_street": "Teszt utca 1.",
    "shipping_country": "HU",
    "shipping_city": "Budapest",
    "shipping_postal_code": "1011",
    "shipping_street": "Teszt utca 1."
  },
  "products": [
    {
      "id": "wps-501",
      "sku": "SKU-1",
      "currency": "HUF",
      "quantity": 2,
      "price": 1000,
      "vat": 27
    }
  ]
}
```

Use **synthetic data only** and only against a staging/sandbox (see Documented limits). The
e-mail/phone/address above are placeholders, not real people.

```bash
# Staging/sandbox only. Replace host and key; never commit a real key.
curl -sS -X POST 'https://STAGING-HOST/wp-json/wps/v1/pepita/order' \
  -H 'Content-Type: application/json' \
  -H 'X-WPS-Api-Key: <ORDER_API_KEY>' \
  --data @synthetic-order.json
```

---

## 4. Response format

Every response from this endpoint — success and error — has the same shape
(`class-api-controller.php:569-592`). **There is no `success`, `duplicate`, `code`, `external_id`
or `existing_wc_order_id` field.**

### 4.1 Success (HTTP 200)

```json
{
  "isError": false,
  "responseCode": 200,
  "message": "A rendelés sikeresen fogadva.",
  "orderId": 4812
}
```

`orderId` is the WooCommerce order ID.

### 4.2 Error (HTTP 4xx/5xx)

```json
{
  "isError": true,
  "responseCode": 422,
  "message": "A(z) 1. tétel nem azonosítható egyetlen WooCommerce termékkel sem (SKU: NOPE, Pepita azonosító: —). A rendelés nem került létrehozásra."
}
```

`responseCode` always equals the HTTP status. `message` is human-readable **Hungarian** text; the
stable machine signal is the HTTP status, not the message. Errors carry no `orderId`.

---

## 5. HTTP status reference

| HTTP | When | Message (Hungarian, abbreviated) | Retry? |
|---|---|---|---|
| `200` | Order created | `A rendelés sikeresen fogadva.` | – |
| `200` | **Duplicate** (same `id`, identical payload) — no new order | `A rendelés (Pepita azonosító: <id>) már korábban importálásra került WooCommerce rendelésként (azonosító: #<n>). Újabb rendelés nem jött létre.` | – (`orderId` = the existing order) |
| `400` | `id` missing/empty/non-scalar/> 191 chars | `Hiányzik vagy érvénytelen a Pepita rendelésazonosító (id).` | No — fix payload |
| `400` | Empty body | `Üres kérés érkezett.` | No |
| `400` | Body is not valid JSON object/array | `A kérés törzse nem érvényes JSON objektum.` | No |
| `401` | Key not configured (`order_api_key` empty) | `A rendelésfogadó API nincs konfigurálva…` | Operator action |
| `401` | Constant key shorter than 32 chars | `A rendelésfogadó API kulcs konfigurációja érvénytelen…` | Operator action |
| `401` | Missing/wrong key | `Érvénytelen vagy hiányzó API kulcs.` | No |
| `401` | Valid key, source IP not in `order_api_allowed_ips` | `A kérés forrás IP címe nincs engedélyezve.` | Operator action |
| `403` | Not HTTPS and `order_api_require_https` on | `A rendelésfogadás csak HTTPS kapcsolaton engedélyezett.` | Use HTTPS |
| `413` | Body > `order_api_max_body_kb` (default 512, setting range 16–8192, code minimum 16) | `A kérés törzse túl nagy (max. N KB).` | No |
| `409` | **Processing in progress** for this `id` (claim younger than 15 min and not failed) | `A rendelés feldolgozása folyamatban van. Kérjük, próbálja újra később.` | **Yes**, later |
| `409` | **Lease lost / taken over** by a newer attempt, or orphan cleanup failed | `A rendelés feldolgozási zárolása lejárt…` / `A félbehagyott rendelés tisztítása…` | **Yes**, later |
| `409` | **Conflicting replay**: same `id`, different payload | `A rendelés már korábban importálásra került, de az új értesítés tartalma eltér. Kérjük, ellenőrizze a rendelést a WooCommerce-ben.` | **No** — needs human review |
| `422` | Validation failure (§3): no/invalid products, e-mail, quantity, price, VAT, currency, amounts, unknown payment/delivery mode, bad `payment_status`, unresolved/ambiguous product, VAT mismatch, negative or over-limit total | specific Hungarian message | No, unless data is corrected (same `id` may then be re-sent, §6) |
| `429` | Failed-auth throttle (default 30 failures/min/client; filter `wps_pepita_order_failed_auth_limit`) | `Túl sok sikertelen hitelesítési kísérlet…` | After ~1 min |
| `429` | Request rate limit (default 120 authenticated requests/min/client; filter `wps_pepita_order_rate_limit`; ≤ 0 disables) | `Túl sok kérés érkezett…` | After ~1 min |
| `500` | Claim table unavailable/claim could not be recorded | `A rendelés rögzítése átmenetileg nem lehetséges.` | Yes, later (operator should check) |
| `500` | WooCommerce order creation failed / unexpected exception | `A WooCommerce rendelés létrehozása sikertelen.` / `Váratlan hiba történt…` | Yes, later (operator should check) |
| `500` | Claim says "completed" but the WooCommerce order is missing or still carries the in-progress marker | `…Üzemeltetői javítás szükséges.` | Operator repair: `wp wps-pepita repair-claim <id>` (`includes/class-cli.php`) |
| `503` | Feed-only beta mode is active (`beta_feed_only` setting or `WPS_PEPITA_BETA_FEED_ONLY` constant) | `A Pepita rendelésfogadás a béta időszakban ki van kapcsolva (feed-only béta).` | Intentional |
| `503` | `order_api_enabled` is off | `A Pepita rendelésfogadás ki van kapcsolva ebben az áruházban.` | Intentional |

Notes:

- `409` is used for **three** different situations (busy, lease lost, conflicting replay). Distinguish by `message`; **verify with Pepita how their dispatcher treats `409` and `5xx` (retry policy, backoff)** — this is not defined in the plugin.
- The failed-auth throttle (step 4) is evaluated *before* the key comparison; while a client is throttled even a correct key gets `429`.
- Rejections are logged (`Logger`, event `order_api`) with at most one row per client/reason/minute and 120 rows/minute overall (`:525-567`).

---

## 6. Idempotency, duplicates and conflicting replays

The `id` field is the idempotency key; state is kept in the table `{$wpdb->prefix}wps_pepita_orders` (unique key on `external_id`, `class-installer.php:114`).

1. **Payload hash.** SHA-256 over the JSON of the **entire payload** after recursive canonicalization: object keys are sorted (`ksort`), array order is preserved, JSON is encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` (`Importer::canonicalize()` `class-importer.php:1606`, `hash_payload()` `:1629`). Consequences: key order does not matter; **any** changed value, added/removed field, changed list order or a number sent as string vs. number **changes the hash**.
2. **First delivery** → claim row created, WooCommerce order created, claim marked `completed` with the order ID and hash → `200` + new `orderId`.
3. **Identical replay** (same `id`, same hash) → `200`, message states that the order was already imported, same `orderId`; **no new order, nothing modified**.
4. **Conflicting replay** (same `id`, different hash) → **`409`**; the existing order is **not modified**; an internal order note and a `order_duplicate` warning (with redacted payload and `reconciliation_event = changed_payload_refused`) are written. Amendments must therefore come with a **new** order `id` (verify the amendment/cancellation process with Pepita — the plugin has no endpoint for updates or cancellations).
5. **Concurrent delivery** of the same `id`: only one request wins the claim (atomic `INSERT IGNORE` / conditional `UPDATE`); the others get `409` "folyamatban".
6. **Retryability.** A claim with status `failed` (e.g. after a `422`) or a `processing` claim not updated for **15 minutes** (`Order_Repository::STALE_AFTER`) can be re-claimed; the attempt counter is incremented. Hence a payload rejected with `422` can be corrected and re-sent under the same `id`.
7. **Crash recovery.** If a previous attempt crashed after the WooCommerce order shell was created, the retry either adopts the finished order (replay rules above apply) or discards the unfinished shell before creating a fresh order (`Importer::import()`).
8. **Historical orders without a stored hash** are compared by invariants (currency, total, e-mail, line items) instead; any difference → `409` (`Importer::handle_existing_order()` `class-importer.php:1647`).
9. By default only the hash is stored, not the raw payload (`order_api_keep_payloads` = `false`, `class-order-repository.php:117-121`).

---

## 7. What the importer creates

(Derived from `Importer::create_order()`; WooCommerce CRUD, HPOS-safe by design — not executed here.)

- Order `created_via = wps-pepita`; meta `_wps_pepita_order_id`, `_wps_pepita_origin`, `_wps_pepita_payment_status`, `_wps_pepita_delivery_mode`, `_wps_pepita_payload_hash`, `_wps_pepita_imported_at`.
- Line prices are treated as **gross**; when WooCommerce taxes are enabled the gross is split into net + tax using the configured rate. The order total is set to Pepita's computed total (items + shipping − voucher).
- Shipping line when `total_shipping_price` > 0; negative fee `Pepita utalvány` when `voucher` > 0.
- Order status: `paid` → `order_api_status_paid` (default `processing`); otherwise `order_api_status_unpaid` (default `on-hold`); an unknown configured status falls back to `on-hold`.
- Payment/shipping method are mapped through the configured maps (`payment_map`, `shipping_map`); unmapped shipping uses `wps_pepita_shipping`.
- Label PDF (if sent and valid) is saved in the plugin's private storage directory under `labels/` (`Storage::label_dir()`, `class-storage.php:357-364`), not in a publicly guessable folder; retention default `order_api_label_retention_days` = 30.
- WooCommerce customer/admin e-mails are suppressed during import.

Not defined by the plugin (**verify with Pepita**): status/cancellation/return notifications back to Pepita, shipment tracking write-back, the dispatcher's retry/backoff policy, the exact set of fields Pepita guarantees to send.
