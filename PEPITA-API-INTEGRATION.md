# PEPITA-API-INTEGRATION — Order intake contract

Pepita pushes orders to WooCommerce. This plugin **receives** them. It does not
send orders to Pepita, and it does not call any Pepita endpoint.

## 1. Endpoint

```
POST https://example.com/wp-json/wps/v1/pepita/order?apikey=YOUR_API_KEY
Content-Type: application/json
```

Registered through `register_rest_route()` in namespace `wps/v1`, route
`/pepita/order`, method `POST`. Authentication happens inside the handler, so
the REST permission callback is intentionally public.

## 2. Authentication

The shared secret can be supplied in three ways, in this order:

1. Query parameter `?apikey=…`
2. Header `X-WPS-Api-Key: …`
3. Header `X-Api-Key: …`

Comparison uses `hash_equals()`. If no key is configured on the site, every
request is rejected with 401 and an error is logged — the endpoint fails closed.

Optional controls:

| Setting | Effect |
|---|---|
| `order_api_enabled` | Off → 503 for every request |
| `order_api_allowed_ips` | Comma/space/semicolon separated allowlist; others → 401 |
| `order_api_require_https` | Plain HTTP → 403; `X-Forwarded-Proto: https` is honoured behind a proxy |
| `order_api_max_body_kb` | Body larger than the limit → 413 (minimum 16 KB) |
| `wps_pepita_order_rate_limit` filter | Requests per minute per IP, default 120, `0` disables |

The client IP is `REMOTE_ADDR`. Proxy headers are not trusted for rate limiting
or allowlisting, to prevent spoofing.

## 3. Request payload

```json
{
  "origin": "pepita.hu",
  "id": "PEP-2026-000123",
  "date": "2026-11-10 10:00:00",
  "payment_mode": "transfer",
  "payment_status": "paid",
  "status": "NEW",
  "total_shipping_price": 1490,
  "total_shipping_price_currency": "HUF",
  "voucher": 0,
  "delivery_mod": "gls",
  "customer_message": "Kérem a kapuhoz.",
  "courier_message": "Hívjon telefonon.",
  "package_label": "JVBERi0xLjQK…",
  "customer": {
    "email": "vasarlo@example.com",
    "first_name": "Anna",
    "last_name": "Kis",
    "phone": "+36301234567",
    "billing_name": "",
    "billing_country": "HU",
    "billing_city": "Budapest",
    "billing_postal_code": "1011",
    "billing_street": "Fő utca 1.",
    "billing_house_number": "1",
    "shipping_country": "HU",
    "shipping_city": "Budapest",
    "shipping_postal_code": "1011",
    "shipping_street": "Fő utca 1.",
    "shipping_house_number": "1",
    "tax_number": "12345678-1-41"
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

### 3.1 Field reference

| Field | Type | Required | Behaviour |
|---|---|---|---|
| `origin` | string | no | Stored as `_wps_pepita_origin`; defaults to `pepita.hu` |
| `id` | string/int | **yes** | External Pepita order ID; the dedupe key |
| `date` | string | no | Parsed with `strtotime()`; becomes `date_created` |
| `payment_mode` | `cod`\|`transfer`\|`creditcard` | no | Mapped to a WooCommerce gateway |
| `payment_status` | `paid`\|`unpaid` | no | Only `paid` sets a paid status and `date_paid` |
| `status` | string | no | Stored for reference; the WooCommerce status comes from settings |
| `total_shipping_price` | number | no | Added as a shipping line when > 0 |
| `total_shipping_price_currency` | string | no | Accepted; the order currency comes from the items or store |
| `voucher` | number | no | Added as a negative fee |
| `delivery_mod` | `shipping`\|`gls`\|`gls_parcellocker`\|`gls_xxl`\|`mpl` | no | Mapped to a shipping method |
| `customer_message` | string | no | Order note + `_wps_pepita_customer_message` |
| `courier_message` | string | no | Order note + `_wps_pepita_courier_message` |
| `package_label` | base64 string | no | Decoded to a PDF under the private directory |
| `customer` | object | **yes** | Must contain a valid `email` |
| `products` | array | **yes** | At least one line |
| `products[].id` | string | no* | Pepita export ID (`wps-…`) |
| `products[].sku` | string | no* | WooCommerce SKU |
| `products[].currency` | string | no | 3-letter code; all lines must agree |
| `products[].quantity` | int | **yes** | Must be > 0 |
| `products[].price` | number | **yes** | Gross unit price, must be > 0 |
| `products[].vat` | number | no | 0–100 |

\* At least one of `sku` or `id` must resolve to a product. SKU is tried first,
then the export ID.

### 3.2 Product resolution

1. `wc_get_product_id_by_sku( $sku )` when `sku` is present.
2. Otherwise a lookup of `_wps_export_id` matching `id`.
3. The product must exist, be published, and not be a variable parent.
4. If any line fails, **no order is created** and the response is 422.

A variable parent is rejected because the payload does not identify which
variation was bought; guessing would ship the wrong item.

## 4. Response

Success (created or duplicate):

```json
{
  "isError": false,
  "responseCode": 200,
  "message": "A rendelés sikeresen fogadva.",
  "orderId": 1234
}
```

Error:

```json
{
  "isError": true,
  "responseCode": 422,
  "message": "A(z) 1. tétel nem azonosítható egyetlen WooCommerce termékkel sem …"
}
```

`orderId` is an extra field for diagnostics; Pepita only needs `isError`,
`responseCode` and `message`.

| HTTP | Meaning |
|---|---|
| 200 | Created, or already imported (idempotent) |
| 400 | Missing external ID, empty body, or invalid JSON |
| 401 | Missing/invalid API key, or IP not allowed |
| 403 | HTTPS required |
| 409 | The same order is being processed right now |
| 413 | Body too large |
| 422 | Business validation failed (products, customer, totals, modes) |
| 429 | Rate limit exceeded |
| 500 | Storage, WooCommerce, or unexpected failure |
| 503 | Endpoint disabled |

## 5. Idempotency

Before any WooCommerce work, the importer calls:

```sql
INSERT IGNORE INTO {prefix}wps_pepita_orders
  (external_id, origin, status, wc_order_id, payload, payload_hash, attempts, created_at, updated_at)
VALUES (…, 'processing', 0, …, 1, now, now)
```

The `UNIQUE KEY external_id` makes this the concurrency primitive.

| Existing row | Claim result | Response |
|---|---|---|
| none | `claimed` | Continue |
| `completed` | `completed` | 200, same `orderId` |
| `failed` | `claimed` (attempts + 1) | Retry allowed |
| `processing`, updated < 10 min ago | `busy` | 409 |
| `processing`, older than 10 min | `claimed` (attempts + 1) | Retry allowed |

If the process dies after WooCommerce created the order but before the ledger was
completed, the next attempt finds the order by `_wps_pepita_order_id` and adopts
it instead of creating a duplicate.

The original payload and its SHA-256 are stored for auditing. Payloads older
than 14 days are purged by `Order_Repository::purge_payloads()`; the dedupe row
itself is never removed.

## 6. Order construction

| Target | Source |
|---|---|
| `created_via` | `wps-pepita` |
| `currency` | First line currency, else store currency |
| `date_created` | `date` when parseable |
| Billing name | `first_name` + `last_name`, or split `billing_name` |
| Billing company | `billing_name` when present |
| Billing e-mail | `customer.email` (required) |
| Billing phone | `customer.phone` |
| Billing address | `billing_street` (or `billing_street_address` + `billing_house_number`) and `billing_house_number` as address 2 |
| Billing postcode/city/country | `billing_postal_code`, `billing_city`, `billing_country` |
| Shipping address | `shipping_*`, falling back to the billing values |
| Tax number | `_billing_vat_number` |
| Customer ID | Matched WordPress user by e-mail, else guest |
| Line items | `add_product()` with the payload quantity and gross price; item meta `_wps_pepita_product_id`, `_wps_pepita_sku`, `_wps_pepita_vat` |
| Shipping | `WC_Order_Item_Shipping` when `total_shipping_price > 0` |
| Voucher | `WC_Order_Item_Fee`, negative amount |
| Payment method | `payment_map[payment_mode]`, title from the Hungarian label table |
| Status | `order_api_status_paid` (`processing`) or `order_api_status_unpaid` (`on-hold`) |
| `date_paid` | Only when `payment_status === "paid"` |
| Meta | `_wps_pepita_order_id`, `_wps_pepita_origin`, `_wps_pepita_payment_status`, `_wps_pepita_delivery_mode`, `_wps_pepita_imported_at`, `_wps_pepita_payload_hash`, `_wps_pepita_raw_total`, `_wps_pepita_voucher` |
| Notes | Import summary, customer message, courier message |
| Label | `_wps_pepita_package_label` absolute file path |

Totals are recalculated by WooCommerce (`calculate_totals()`), so the order
reflects the payload lines, shipping and voucher exactly.

## 7. Payment and delivery mapping

Default payment map (`Settings::payment_map()`):

| Pepita | WooCommerce gateway | Hungarian title |
|---|---|---|
| `cod` | `cod` | Utánvét |
| `transfer` | `bacs` | Átutalás |
| `creditcard` | *(empty)* | Bankkártya |

`creditcard` has no default because the store must decide which gateway handles
card payments. Until one is chosen, the order keeps the title but no gateway ID.
This never marks the order paid; only `payment_status` does.

Default shipping map:

| Pepita | WooCommerce method | Hungarian title |
|---|---|---|
| `shipping` | `flat_rate` | Alapértelmezett szállítás |
| `gls` | `flat_rate` | GLS házhozszállítás |
| `gls_parcellocker` | `flat_rate` | GLS csomagautomata |
| `gls_xxl` | `flat_rate` | GLS XXL házhozszállítás |
| `mpl` | `flat_rate` | MPL házhozszállítás |

Both maps are editable in the admin. Unknown modes are rejected with 422 rather
than silently dropped.

## 8. Safety guarantees

1. **No e-mails.** All `woocommerce_email_enabled_*` hooks are filtered to
   `false` for the duration of `save()` and restored in a `finally` block.
2. **No gateway execution.** The plugin sets payment metadata; it never calls
   `process_payment()`, `payment_complete()` or any gateway API.
3. **No false "paid".** `date_paid` and the paid status require
   `payment_status: "paid"`.
4. **No duplicate orders.** The UNIQUE ledger plus the crash-recovery lookup.
5. **No partial orders.** Products are resolved before the order is created; a
   single bad line aborts the whole request with 422.
6. **No direct SQL** against WooCommerce order or product tables — only the
   plugin's own ledger and log tables.
7. **No stored secrets in logs.** Keys and labels are redacted.
8. **Label safety.** Base64 is strictly decoded, capped at 8 MB, written under a
   random filename in the private directory.

## 9. Operational notes

- Run the site over HTTPS and enable `order_api_require_https` in production.
- Keep the API key in `wp-config.php` as `WPS_PEPITA_ORDER_API_KEY` so it is not
  stored in the database.
- Watch **Pepita Sync → Rendelések** for `failed` claims and **Napló** for
  rejected requests (reason, IP, rate limiting).
- If Pepita retries a 500/409, the retry is safe by design.
- If a product is missing, the order is not created and Pepita must be corrected
  — the response message names the failing line, SKU and export ID.
