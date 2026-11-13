# INSTALLATION — WP Pepita Sync

## 1. Requirements

| Component | Minimum | Recommended |
|---|---|---|
| WordPress | 6.4 | 6.7+ |
| WooCommerce | 8.0 | 9.x / 10.x |
| PHP | 8.1 | 8.3 – 8.5 |
| Database | MySQL 5.7 / MariaDB 10.3 | MySQL 8 / MariaDB 10.6 |
| HTTPS | required for production order intake | always |
| WP-Cron or Action Scheduler | required for automatic feeds | Action Scheduler |

The plugin requires the `manage_woocommerce` capability to configure, and the
`woocommerce` plugin to be active. Without WooCommerce it shows an admin notice
and does nothing.

## 2. Installation

### Option A — upload the ZIP

1. WordPress admin → **Plugins → Add New → Upload Plugin**.
2. Choose `dist/wp-pepita-sync.zip`.
3. **Install Now**, then **Activate Plugin**.

### Option B — copy the directory

1. Copy the plugin folder to `wp-content/plugins/wp-pepita-sync/`.
2. Activate **WP Pepita Sync** under **Plugins**.

On activation the plugin:

- creates `{prefix}wps_pepita_logs` and `{prefix}wps_pepita_orders`,
- writes default settings (autoload disabled),
- generates a feed token and an order API key,
- schedules the recurring full feed, stock feed and log purge jobs.

Nothing is deleted or modified in WooCommerce during activation.

## 3. First configuration

Open **Pepita Sync → Beállítások**.

### 3.1 Katalógus (catalog)

| Setting | Meaning |
|---|---|
| Alapértelmezett besorolás | Whether products are exported when no product or category rule matches. Leave **off** and opt in explicitly. |
| Gyermek kategóriák öröklése | When on, an included category also includes its descendants. |
| Engedélyezett termékstátuszok | Usually only **Közzétéve**. |
| Max. kategóriaútvonalak | Number of category paths written per product (1–20). |
| Max. galériaképek | Gallery images per product (1–50). |

### 3.2 Ár és áfa (price and VAT)

| Setting | Meaning |
|---|---|
| Pénznem | Empty = WooCommerce currency. |
| Áfa mód | `auto` reads the WooCommerce tax class; `fixed` uses one percentage. |
| Fix áfa (%) | Used when the mode is `fixed`. |
| Ár korrekció | `none`, `fixed` or `percent`, applied only in the feed. |
| Kerekítés | `nearest_1`, `nearest_5`, `nearest_10`, `nearest_100`, `up_1`, `down_1`, `none`. |
| Beszerzési nettó ár exportálása | Adds `PurchaseNetPrice` when the product has a cost. |

### 3.3 Szállítás és garancia (shipping and warranty)

| Setting | Meaning |
|---|---|
| Szállítási idő (nap) | Global default; category and product values override it. |
| Szállítási költség | Optional `ShippingPrice`; empty omits the element. |
| Garancia típusa / időtartama | `None`, `Manufacturer`, `Seller` and a duration. |

### 3.4 Tartalom és azonosítók (content and identifiers)

| Setting | Meaning |
|---|---|
| Leírás forrása | `long`, `short` or `both`. |
| Márka forrása | Meta key, taxonomy, or none. |
| Gyártó alapértéke | Fallback manufacturer. |
| GTIN meta kulcsok | Comma-separated list, tried in order (`_wps_gtin,_gtin,_ean,_wps_ean`). |
| GTIN kötelező kategóriák | Categories where a missing GTIN is an error. |

### 3.5 Feed generálás (feed generation)

| Setting | Meaning |
|---|---|
| Köteg méret | Products hydrated per batch (default 100). |
| Kötegek / futás | Batches per cron slice (default 10). |
| Max. futásidő (mp) | Time budget per slice (default 20; `0` = unlimited, CLI only). |
| Teljes feed gyakorisága | Hours between full generations (default 24). |
| Készletfeed gyakorisága | Minutes between stock generations (default 30). |
| Eltávolított termékek kezelése | `omit` (remove immediately) or `zero_stock` (keep unavailable for the grace period). |
| Türelmi idő (nap) | Grace period for `zero_stock`. |
| Üres feed engedélyezése | Only for intentionally empty catalogs. |
| Nyilvános feed | Disables token authentication. Not recommended. |
| Feed cache (mp) | `Cache-Control: max-age`. |
| Feed azonosítók | URL values for `?wps_feed=` (default `full` and `stock`). |

### 3.6 Rendelés API (order API)

| Setting | Meaning |
|---|---|
| Rendelésfogadás engedélyezve | Master switch. |
| Fizetve → státusz | Default `processing`. |
| Nincs fizetve → státusz | Default `on-hold`. |
| Fizetési mód megfeleltetés | Pepita `cod`/`transfer`/`creditcard` → WooCommerce gateway ID. `creditcard` is intentionally empty until you choose one. |
| Szállítási mód megfeleltetés | Pepita delivery mode → WooCommerce shipping method ID. |
| Engedélyezett IP-címek | Optional allowlist, comma or space separated. Forwarding headers are ignored unless the peer is a trusted proxy. |
| Megbízható proxyk | IPs/CIDR ranges of reverse proxies whose `X-Forwarded-For` / `X-Forwarded-Proto` may be trusted. Leave empty on a direct connection. |
| HTTPS megkövetelése | On by default. Rejects plain HTTP **before** the key is compared. `X-Forwarded-Proto: https` is honoured only from a trusted proxy. |
| Max. kérés méret (KB) | Body limit, minimum 16, default 512. |
| Csomagcímke mentése | Stores the base64 PDF under the private directory. |
| Nyers payload megőrzése | Off by default: only the SHA-256 hash is stored. When on, the raw JSON is kept and purged on the log-retention schedule. |
| Csomagcímke megőrzés (nap) | Days a label PDF is kept after its order closes (default 30). Open orders are always protected. |

### 3.7 Naplózás és életciklus (logging and lifecycle)

| Setting | Meaning |
|---|---|
| Naplószint | `error`, `warning`, `info`, `debug`. |
| Napló megőrzés (nap) | Automatic purge. |
| Export meta írása | Writes `_wps_last_export_status` and timestamps to products. |
| Adatok törlése eltávolításkor | Opt-in destructive uninstall. Off by default. |

Save with **Beállítások mentése**. Every field is sanitised server-side; invalid
values fall back to defaults.

### 3.8 Reverse proxy, TLS and private storage

**TLS.** Production order intake must run over HTTPS. The plugin checks
`is_ssl()` **before** comparing the API key, so a plaintext request never exposes
its credential. If TLS terminates at a reverse proxy (nginx, a load balancer, a
CDN), the backend request looks like plain HTTP; add the proxy's address or CIDR
to **Megbízható proxyk** (`order_api_trusted_proxies`). Only then is
`X-Forwarded-Proto: https` from that peer accepted. Never add a range that
contains untrusted clients.

The same trusted-proxy list governs how the client IP for the allowlist and the
rate limiter is derived: the forwarding chain is walked from the right and the
first untrusted hop is used. A client cannot spoof `X-Forwarded-For` to satisfy
the allowlist or to reset its rate limit.

**Private storage on nginx.** Feeds, the catalog index and package labels live in
`wp-content/uploads/wps-pepita-<random-key>/`. The plugin writes `.htaccess` and
`web.config`, which Apache and IIS honour. **nginx ignores `.htaccess`**, so add
an explicit deny rule and reload nginx:

```nginx
# Block direct access to the plugin's private directory.
location ~* ^/wp-content/uploads/wps-pepita-[^/]+/ {
    deny all;
    return 404;
}
```

On Apache, confirm `AllowOverride` permits `.htaccess`, or add the equivalent
`<Directory>` deny. The canonical feed is served by WordPress through
`?wps_feed=…`; the files themselves never need to be web-readable.

## 4. Selecting products

Three levels, highest priority first:

1. **Product** — WooCommerce product edit screen → **Pepita Sync** box →
   *Pepita exportálás*: `Alapértelmezett`, `Beleértve`, `Kizárva`.
2. **Category** — **Pepita Sync → Kategóriák** or the category edit screen:
   include / exclude, child inheritance, price adjustment, shipping delay, GTIN
   requirement.
3. **Global** — *Alapértelmezett besorolás*.

Exclusion always wins. A product in both an included and an excluded category is
not exported.

Also verify each product has:

- a name and description,
- at least one category,
- at least one image (the first gallery image is the primary image),
- a price greater than zero,
- a valid GTIN if its category requires one.

Invalid products are skipped, counted, and listed on the dashboard with their
error codes. They never break the rest of the feed.

## 5. Generating the feed

1. Open **Pepita Sync → Feedek**.
2. Click **Teljes feed generálása**.
3. The page shows progress and continues in slices until complete.
4. When finished, the feed status shows the product count, size, generation time
   and hash.

Generate the **stock feed** after the first successful full feed. It is refused
until a full feed exists, and refused again if the catalog changed in a way that
would make the two feeds disagree — in that case run a full generation.

### Automatic generation

- Full feed: every `full_feed_interval` hours.
- Stock feed: every `stock_feed_interval` minutes.
- Product and category changes queue a debounced regeneration (120 s by default).

If `DISABLE_WP_CRON` is set, configure a real cron job:

```bash
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

## 6. Connecting Pepita

### 6.1 Feed URLs

Copy from **Pepita Sync → Feedek**:

```
https://example.com/?wps_feed=full&token=…
https://example.com/?wps_feed=stock&token=…
```

Paste them into the Pepita partner panel. Keep the token secret; regenerate it
with **Token újragenerálása** if it leaks. If the token is defined as
`WPS_PEPITA_FEED_TOKEN` in `wp-config.php`, the admin cannot change it and the
constant wins.

Verify with:

```bash
curl -sI "https://example.com/?wps_feed=full&token=YOUR_TOKEN"
# 200, Content-Type: application/xml, ETag, Last-Modified
```

### 6.2 Order API

Copy from **Pepita Sync → Rendelés API**:

- Endpoint: `https://example.com/wp-json/wps/v1/pepita/order`
- API key: 48 characters

Pepita sends the key as `?apikey=…`. The plugin also accepts `X-WPS-Api-Key` and
`X-Api-Key`.

Smoke test (replace the key and use a real product SKU):

```bash
curl -s -X POST "https://example.com/wp-json/wps/v1/pepita/order?apikey=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "origin": "pepita.hu",
    "id": "TEST-0001",
    "payment_mode": "transfer",
    "payment_status": "unpaid",
    "delivery_mod": "gls",
    "total_shipping_price": 1490,
    "total_shipping_price_currency": "HUF",
    "voucher": 0,
    "customer": {
      "email": "test@example.com",
      "first_name": "Teszt",
      "last_name": "Vásárló",
      "billing_country": "HU",
      "billing_city": "Budapest",
      "billing_postal_code": "1011",
      "billing_street": "Fő utca 1."
    },
    "products": [
      { "sku": "YOUR-SKU", "currency": "HUF", "quantity": 1, "price": 1000, "vat": 27 }
    ]
  }'
```

Expected: HTTP 200 with `"isError": false` and an `orderId`. Delete the test
order afterwards. A repeated request returns 200 and the same `orderId`.

## 7. wp-config.php constants

```php
// Recommended: keep secrets out of the database.
// Each constant must be at least 32 random characters.
define( 'WPS_PEPITA_FEED_TOKEN', 'a-long-random-token-of-at-least-32-chars' );
define( 'WPS_PEPITA_ORDER_API_KEY', 'another-long-random-key-at-least-32-chars' );

// Optional tuning.
define( 'WP_DEBUG', true );
```

When a constant is defined, it takes precedence over the stored value and the
admin field becomes read-only.

## 8. Verification checklist

- [ ] **Pepita Sync → Áttekintés** shows no red health check.
- [ ] Feed directory is writable.
- [ ] Full feed generated; product count matches expectation.
- [ ] `curl` on the full feed returns 200 and well-formed XML.
- [ ] Stock feed generated and its product count equals the full feed's.
- [ ] Pepita accepts both feed URLs.
- [ ] A direct request to the private `wps-pepita-*` directory returns 403/404
      (nginx deny rule present if not Apache).
- [ ] A test order from Pepita appears under **WooCommerce → Orders** with the
      Pepita order ID in the order notes.
- [ ] No customer e-mail was sent for the imported order.
- [ ] The same order notification sent twice creates one order.
- [ ] A changed duplicate returns 409 and leaves the original order unchanged.
- [ ] With taxes enabled, the imported total equals the Pepita gross total and
      `get_total_tax()` is 0.
- [ ] Plain-HTTP order requests are refused; a TLS-terminating proxy is listed in
      **Megbízható proxyk**.
- [ ] With `order_api_keep_payloads` off, the claim row's `payload` is NULL.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `A feed még nem készült el` (503) | No successful generation yet | Generate the full feed |
| `403 invalid_token` | Wrong or missing token | Copy the URL from the admin, or regenerate |
| Feed is empty / generation aborts with "üres" | No product is included | Check rules, product status, category, images |
| `A készletfeed kínálata eltér…` | Catalog changed since the last full feed | Run a full generation |
| Products missing from the feed | Excluded, draft, no image, no category, zero price, invalid GTIN | Check the dashboard error list and the product metabox |
| Stock feed never updates | Cron not running | Check **Áttekintés → Ütemező**, run `wp cron event run --due-now` |
| Order API returns 401 | Missing/invalid key, or IP not allowlisted | Re-copy the key; check the allowlist and that the real client address (not a spoofed header) is the one being tested |
| Order API returns 403 | HTTPS enforced but request is plain HTTP, or a proxy sends `X-Forwarded-Proto` but is not in **Megbízható proxyk** | Use HTTPS; add the TLS-terminating proxy to the trusted-proxy list |
| Order API returns 409 | The same order is being processed, or the same external id was resent with different content | Retry after a minute; for a content change, use a new external id — a stored order is never rewritten |
| Order API returns 422 | Unknown SKU/export ID, unpublished or variable parent, a SKU/id mismatch, or an invalid amount | Send the variation, publish the product, use the export ID, or send a canonical amount |
| Order API returns 429 | Authenticated limit (120/min) or failed-auth limit (30/min) exceeded from one client | Raise `wps_pepita_order_rate_limit`; for repeated 401s, fix the key — failures are throttled |
| A locale amount is rejected with 422 | Thousands separators or comma decimals are ambiguous | Send a JSON number or a canonical decimal string (`4990`, `"4990.00"`) |
| Labels are missing | `order_api_save_label` off, or invalid base64 | Enable it; check the log |
| Lock never clears | A generation died without cleanup | Locks expire automatically; **Feedek → Zárolás feloldása** if needed |

Logs are under **Pepita Sync → Napló**. Secrets and package labels are redacted.

## 10. Uninstall

By default, deactivating and deleting the plugin leaves all data intact.

To remove everything, enable **Adatok törlése eltávolításkor** first, then delete
the plugin. This removes settings, feed files, custom tables and the plugin's own
post/term meta. WooCommerce orders, products and unrelated meta are never
touched.
