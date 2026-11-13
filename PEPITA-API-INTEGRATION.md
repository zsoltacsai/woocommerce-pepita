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
| `order_api_trusted_proxies` | IPs/CIDR ranges of reverse proxies whose forwarding headers may be trusted |
| `order_api_require_https` | On by default. Plain HTTP → 403 before the key is compared |
| `order_api_max_body_kb` | Body larger than the limit → 413 (minimum 16 KB) |
| `order_api_keep_payloads` | Off by default: only the SHA-256 hash is stored, not the raw payload |
| `order_api_label_retention_days` | Days a package-label PDF is kept (default 30) |
| `wps_pepita_order_rate_limit` filter | Authenticated requests per minute per client, default 120, `0` disables |
| `wps_pepita_order_failed_auth_limit` filter | Failed authentication attempts per minute per client, default 30 |

The client IP is `REMOTE_ADDR`. `X-Forwarded-For`, `CF-Connecting-IP` and
`X-Real-IP` are honoured **only** when the direct peer is listed in
`order_api_trusted_proxies`; the chain is then walked from the right and the
first untrusted hop is used. The same identity is used for the allowlist, the
rate limit and the rejection-log throttle, so a client cannot spoof a header to
escape a limit or to satisfy the allowlist.

### 2.1 TLS termination

`is_ssl()` is authoritative. When TLS is terminated at a reverse proxy, add that
proxy to `order_api_trusted_proxies`; only then is `X-Forwarded-Proto: https`
from that peer accepted. A client-supplied `X-Forwarded-Proto` from any other
address is ignored. TLS must be enforced at the proxy or web-server ingress,
before credentials are transmitted; the plugin's check is defence in depth, not
a substitute for edge TLS.

### 2.2 Abuse protection order

1. Endpoint enabled check.
2. Client address resolution (trusted-proxy aware).
3. Transport check — a plaintext request never has its key compared.
4. Failed-authentication throttle (30/min/client) — runs before the key
   comparison and before any database log write.
5. API key comparison.
6. IP allowlist.
7. Authenticated rate limit (120/min/client).
8. Body size, JSON and business validation.

Rejection logging is bounded to one row per client/reason/minute and at most
120 rows per minute overall, so an unauthenticated flood cannot exhaust the
database. A legitimate Pepita retry remains possible because only failures are
throttled and the window is one minute.

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
| `status` | string | no | Pepita order status; included in conflict/reconciliation operator notes (order status in WooCommerce is governed by plugin settings) |
| `total_shipping_price` | number | no | Strict non-negative amount; added as a shipping line when > 0 |
| `total_shipping_price_currency` | string | no | Accepted; the order currency comes from the items or store |
| `voucher` | number | no | Strict non-negative amount; added as a negative fee |
| `delivery_mod` | `shipping`\|`gls`\|`gls_parcellocker`\|`gls_xxl`\|`mpl` | no | Mapped to a shipping method |
| `customer_message` | string | no | Order note + `_wps_pepita_customer_message` |
| `courier_message` | string | no | Order note + `_wps_pepita_courier_message` |
| `package_label` | base64 string | no | Must decode to a real PDF (`%PDF-` magic); stored under the private directory |
| `customer` | object | **yes** | Must contain a valid `email` |
| `products` | array | **yes** | At least one line |
| `products[].id` | string | no* | Pepita export ID (`wps-…`) |
| `products[].sku` | string | no* | WooCommerce SKU |
| `products[].currency` | string | no | 3-letter code; all lines must agree |
| `products[].quantity` | int | **yes** | Whole number, 1–100000 |
| `products[].price` | number | **yes** | Gross unit price, strict non-negative amount, must be > 0 |
| `products[].vat` | number | no | 0–100; optional. When provided on a taxable product in a tax-enabled store (for non-exempt customers), it is validated against the configured WooCommerce tax rate (mismatch → 422). Specifying `vat > 0` on a zero-rated/none tax product is also rejected with 422 |

### 3.1 Monetary format

Amounts must be JSON numbers or canonical decimal strings:

- Accepted: `4990`, `4990.00`, `"4990"`, `"4990.00"`, `12.5`
- Rejected with 422: `"4,990"`, `"1 490"`, `"12,5"`, `""`, `"abc"`,
  `"4990 HUF"`, `4990.123`, negative values, `NaN`/`INF`

Thousand separators are never guessed: `"4,990"` is ambiguous between 4990 and
4.99 and is rejected rather than converted. An invalid amount never becomes
zero and never creates an order. The same parser is used for price, shipping,
voucher, VAT and quantity.

\* At least one of `sku` or `id` must resolve to a product. SKU is tried first,
then the export ID.

### 3.2 Product resolution

1. `wc_get_product_id_by_sku( $sku )` when `sku` is present.
2. A lookup of `_wps_export_id` matching `id`.
3. If both identifiers are present and resolve to different products, the line
   is rejected — the plugin never silently prefers one.
4. The product must exist, be published, and not be a variable parent.
5. If any line fails, **no order is created** and the response is 422.

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
| 200 | Created, or an identical duplicate already imported (idempotent) |
| 400 | Missing external ID, empty body, or invalid JSON |
| 401 | Missing/invalid API key, or IP not allowed |
| 403 | HTTPS required |
| 409 | The same order is being processed right now, or the same id was re-sent with different content |
| 413 | Body too large |
| 422 | Business validation failed (products, customer, amounts, modes) |
| 429 | Rate limit or failed-authentication limit exceeded |
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
| `completed`, same payload hash | `completed` | 200, same `orderId` |
| `completed`, different payload hash | `completed` | 409, existing order is not modified |
| `failed` | `claimed` (attempts + 1) | Retry allowed |
| `processing`, updated < 15 min ago | `busy` | 409 |
| `processing`, older than 15 min | `claimed` (attempts + 1) | Retry allowed |

`external_id` uses the `utf8mb4_bin` collation, so `PEP-1` and `pep-1` are two
distinct orders. The crash-recovery lookup re-checks the meta value exactly in
PHP, because the WordPress meta table comparison may be case-insensitive.

If the process dies after WooCommerce created the order but before the ledger was
completed, the next attempt finds the order by `_wps_pepita_order_id` and adopts
it instead of creating a duplicate.

The SHA-256 of the payload is always stored. The raw payload (which contains
customer PII and the base64 label) is stored **only** when
`order_api_keep_payloads` is enabled. When disabled, existing payloads are
cleared immediately on settings save and by the daily maintenance job. When
enabled, payloads older than `log_retention_days` (default 30) are purged. The
dedupe row, its hash and the WooCommerce order are never removed by this job.

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
| Customer ID | Matched WordPress user by e-mail (never an account that can manage the store), else guest |
| Line items | `add_product()` with the payload quantity, calculated net price, and tax lines; item meta `_wps_pepita_product_id`, `_wps_pepita_sku`, `_wps_pepita_vat`, `_wps_pepita_gross_price` |
| Shipping | `WC_Order_Item_Shipping` with net amount and shipping tax when `total_shipping_price > 0` |
| Voucher | `WC_Order_Item_Fee`, non-taxable negative amount |
| Taxes | `WC_Order_Item_Tax` rows for each applied tax rate when taxes are enabled |
| Payment method | `payment_map[payment_mode]`, title from the Hungarian label table |
| Status | `order_api_status_paid` (`processing`) or `order_api_status_unpaid` (`on-hold`) |
| `date_paid` | Only when `payment_status === "paid"` |
| Meta | `_wps_pepita_order_id`, `_wps_pepita_origin`, `_wps_pepita_payment_status`, `_wps_pepita_delivery_mode`, `_wps_pepita_imported_at`, `_wps_pepita_payload_hash`, `_wps_pepita_raw_total`, `_wps_pepita_voucher` |
| Notes | Import summary, customer message, courier message |
| Label | `_wps_pepita_package_label` absolute file path |

### 6.1 Tax representation (NEW-1 resolution)

Pepita prices are gross and authoritative. The importer enforces correct tax-aware
WooCommerce order creation:

1. **Authoritative gross totals:** The total payable amount matches the Pepita payload
   to the cent/fillér; WooCommerce does not add VAT a second time on top.
2. **Net and tax partitioning:** In tax-enabled stores (`woocommerce_calc_taxes=yes`),
   each product line item is partitioned into its net subtotal/total and tax amount based on
   configured WooCommerce tax rates matching Pepita's VAT rate.
3. **WooCommerce tax lines:** Supported `WC_Order_Item_Tax` rows are recorded for each
   applicable tax rate, with cart tax and shipping tax broken down for invoicing plugins.
4. **Taxable shipping:** If shipping is configured as taxable, gross shipping is
   partitioned into net shipping and shipping tax.
5. **Zero-rated & tax-exempt:** Zero-rated products (0% VAT), stores with taxes disabled,
   and tax-exempt customer orders are cleanly supported with 0 tax lines.
6. **Validation & fail safe:** Imported VAT rates are validated against WooCommerce tax
   configuration. Incompatible or ambiguous VAT data is rejected with HTTP 422 before
   creating an order. Catalog prices remain untouched.

### 6.2 Duplicate and retry semantics (NEW-2 resolution)

1. **Identical deliveries:** If an already completed order is received with the identical
   payload hash, HTTP 200 is returned with the existing `orderId` (idempotent, no side effects).
2. **Changed-payload duplicate deliveries:** If the same external ID is received with modified
   content (different payload hash), the system behaves non-destructively:
   - Does not overwrite or mutate the existing WooCommerce order.
   - Does not create a duplicate order.
   - Preserves the original order data intact.
   - Adds an internal operator order note to the WooCommerce order informing the merchant
     about the new payload hash and received Pepita status/payment status.
   - Records a redacted reconciliation event in the plugin log (event `order_duplicate` with `reconciliation_event: changed_payload_refused`).
   - Returns HTTP 409 Conflict.
3. **Retry risks:** If Pepita's sender treats 409 as a retryable transient failure rather than
   a business conflict, repetitive webhooks could cause log bloat or webhook queue backlogs.
   Automatic order status mutations based on unconfirmed assumptions are rejected.

### 6.3 Technical clarification questions for Pepita

The following questions must be confirmed with the Pepita partner integration team before
enabling automated lifecycle status mutations:

1. **Modified deliveries:** Can an existing order ID ever be delivered again with modified
   content (e.g. customer address change, item cancellation, or replacement lines)?
2. **Status and payment forwarding:** Are post-creation payment and status updates (e.g.
   unpaid → paid, or cancellation) forwarded through this same webhook endpoint, or via
   a distinct callback/polling API?
3. **Non-2xx retry policy:** What is Pepita's retry schedule and backoff policy for 4xx
   and 5xx responses (especially 409 Conflict and 422 Unprocessable Entity)?
4. **Expected response for imported orders:** Is HTTP 200 with `{isError: false, orderId}`
   accepted as acknowledgement for duplicate deliveries?
5. **Out-of-order delivery:** Can webhook deliveries occur out of order, and how are sequence
   guarantees established?
6. **Tax-exempt pricing semantics:** For tax-exempt buyers (`customer.is_vat_exempt === 'yes'`),
   does Pepita's `products[].price` represent a net price or does it still include standard VAT?
   The plugin zeroes WooCommerce tax and treats `products[].price` as the total payable amount.

Package-label PDFs are kept for `order_api_label_retention_days` (default 30).
The daily maintenance job deletes expired labels of closed orders and clears
their order meta. A label attached to a pending, processing or on-hold order is
kept regardless of age, so a late shipment never loses its document. Labels are
stored in the private, token-protected storage directory and are only streamed
to an authorised admin request.

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
7. **No stored secrets or PII in logs.** Keys and labels are redacted, and
   customer name, e-mail, phone, address, postcode, city, tax number and
   messages are masked before a payload is written to the log table.
8. **Label safety.** Base64 is strictly decoded, capped at 8 MB, the `%PDF-`
   magic is required, and the file is written under a random filename in the
   private directory. Expired labels are deleted by the retention job.

## 9. Operational notes

- Run the site over HTTPS and keep `order_api_require_https` enabled. If TLS is
  terminated at a proxy, add that proxy address/CIDR to
  `order_api_trusted_proxies`; never expose the origin over plain HTTP.
- Keep the API key in `wp-config.php` as `WPS_PEPITA_ORDER_API_KEY` so it is not
  stored in the database.
- Keep `order_api_keep_payloads` off unless raw payloads are genuinely needed
  for debugging; when on, set a short `log_retention_days`.
- Review the label retention window against fulfilment practice; open orders are
  always protected.
- Watch **Pepita Sync → Rendelések** for `failed` claims and **Napló** for
  rejected requests (reason, IP, rate limiting).
- If Pepita retries a 500/409, the retry is safe by design.
- If a product is missing, the order is not created and Pepita must be corrected
  — the response message names the failing line, SKU and export ID.
