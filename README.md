# WP Pepita Sync

WooCommerce ↔ Pepita marketplace integration for Hungarian stores.

Generates the Pepita product feed and a separate stock/price feed, and accepts
Pepita orders through a token-authenticated REST endpoint — without touching
your product data, without sending customer e-mails, and without ever executing
a payment gateway.

- **Plugin slug:** `wp-pepita-sync`
- **Version:** 1.0.0
- **Requires:** WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+
- **HPOS:** compatible (declared through the official WooCommerce API)
- **Admin UI:** Hungarian
- **Code:** English, `WPS\Pepita` namespace, `WPS_` / `wps_` prefixes

## Features

### Product feed

- Exact Pepita XML: namespace `https://pepita.hu/feed/1.0`, documented element
  order and nesting.
- Simple and variable products, up to three variation axes.
- Gross prices, VAT percent, discounted price, purchase net price, shipping
  price, warranty, GTIN, MPN, brand, manufacturer, dimensions, attributes,
  multiple category paths and galleries.
- Stable product IDs that never change when a SKU changes.
- Per-product overrides in a WooCommerce metabox; category-level rules for
  inclusion, price adjustment, shipping delay and GTIN requirement.

### Stock / price feed

- Complete snapshot, never a delta.
- Identical product IDs and variation values as the full feed — enforced by a
  signature check that refuses to publish a mismatched stock feed.
- Updates availability, quantity, restock date, shipping delay and prices only.

### Safety

- Generation is sliced and resumable; a timeout never loses progress.
- New feeds are validated and published atomically; the previous feed is kept.
- A failed or empty generation never replaces the last good feed.
- Two removal policies: omit retired products, or keep them unavailable for a
  grace period.

### Order intake

- `POST /wp-json/wps/v1/pepita/order` with a shared secret.
- Durable idempotency: a UNIQUE ledger row per Pepita order ID.
- Orders created through the WooCommerce CRUD API (HPOS safe).
- Payment and delivery mode mapping, vouchers as negative fees, package labels
  stored as PDF files, customer and courier messages as order notes.
- No e-mails, no gateway execution, and never "paid" without
  `payment_status: "paid"`.

### Operations

- Hungarian dashboard with health checks, feed status, error list and counters.
- Token-authenticated feed URLs with ETag / 304 support.
- Custom log table with retention, level filter and redaction of secrets.
- WP-CLI commands and Action Scheduler / WP-Cron scheduling.

## Quick start

1. Install and activate WooCommerce.
2. Upload `dist/wp-pepita-sync.zip` in **Plugins → Add New → Upload Plugin**.
3. Open **Pepita Sync → Beállítások** and choose which products to export.
4. Open **Pepita Sync → Feedek** and generate the full feed.
5. Copy the two feed URLs (full and stock) into Pepita.
6. Open **Pepita Sync → Rendelés API**, copy the endpoint URL and API key into
   Pepita's order notification settings.

Detailed steps: [INSTALLATION.md](INSTALLATION.md).

## Documentation

| Document | Contents |
|---|---|
| [INSTALLATION.md](INSTALLATION.md) | Install, configure, verify, troubleshoot |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Components, generation pipeline, data model, security |
| [PEPITA-FEED-MAPPING.md](PEPITA-FEED-MAPPING.md) | Every XML element and its WooCommerce source |
| [PEPITA-API-INTEGRATION.md](PEPITA-API-INTEGRATION.md) | Order endpoint contract and behaviour |
| [TESTING.md](TESTING.md) | Test suite, coverage, how to run, limitations |
| [SECURITY-REPORT.md](SECURITY-REPORT.md) | Threat model and controls |
| [COMPATIBILITY-REPORT.md](COMPATIBILITY-REPORT.md) | WordPress, WooCommerce, PHP, HPOS, multisite |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [PROJECT.md](PROJECT.md) | Scope, requirements, glossary |
| [DEVELOPMENT-STATUS.md](DEVELOPMENT-STATUS.md) | Milestones, test results, known issues |
| [BACKLOG.md](BACKLOG.md) | Known gaps and future work |

## Feed URLs

```
https://example.com/?wps_feed=full&token=YOUR_FEED_TOKEN
https://example.com/?wps_feed=stock&token=YOUR_FEED_TOKEN
```

The token can also be sent as the `X-WPS-Feed-Token` header. With
`feed_public` enabled the token is not required — only do this if the catalog is
public anyway.

## Order endpoint

```
POST https://example.com/wp-json/wps/v1/pepita/order?apikey=YOUR_API_KEY
Content-Type: application/json
```

Response:

```json
{ "isError": false, "responseCode": 200, "message": "A rendelés sikeresen fogadva.", "orderId": 1234 }
```

See [PEPITA-API-INTEGRATION.md](PEPITA-API-INTEGRATION.md) for the full contract.

## WP-CLI

```bash
wp wps-pepita status
wp wps-pepita generate full
wp wps-pepita generate stock --runtime=20
wp wps-pepita orders --status=completed --limit=20
wp wps-pepita purge-logs
```

## Development

```bash
php -l woocommerce-pepita.php
php tests/run.php            # 133 tests
bash bin/build.sh            # dist/wp-pepita-sync.zip
```

The test suite runs against lightweight WordPress/WooCommerce stubs and needs no
WordPress installation. See [TESTING.md](TESTING.md) for what is and is not
covered.

## License

GPL-2.0-or-later.
