# WP Pepita Sync — Order API & Webhook Contract

**Endpoint:** `POST /wp-json/pepita/v1/orders`  
**Transport:** HTTPS Only (Enforced in production)  
**Authentication:** Secret Bearer Token / API Key header (`Authorization: Bearer <order_api_key>` or `X-Pepita-Key: <order_api_key>`)

---

## 1. Webhook Payload Specification

### Request Headers
```http
POST /wp-json/pepita/v1/orders HTTP/1.1
Host: merchant-store.example.test
Content-Type: application/json; charset=utf-8
Authorization: Bearer pep_live_sec_********************
User-Agent: Pepita-Order-Dispatcher/2.0
```

### JSON Request Body Schema
```json
{
  "id": "PEP-20261115-98765",
  "origin": "hu",
  "currency": "HUF",
  "status": "paid",
  "created_at": "2026-11-15T00:30:00Z",
  "customer": {
    "first_name": "János",
    "last_name": "Kovács",
    "email": "kovacs.janos@example.test",
    "phone": "+36301234567",
    "billing_address": {
      "address_1": "Fő utca 12.",
      "city": "Budapest",
      "postcode": "1052",
      "country": "HU"
    },
    "shipping_address": {
      "address_1": "Fő utca 12.",
      "city": "Budapest",
      "postcode": "1052",
      "country": "HU"
    }
  },
  "items": [
    {
      "sku": "FND-CHAIR-BLK",
      "external_product_id": "7821",
      "name": "FN-DECO Étkezőszék Fekete",
      "quantity": 2,
      "unit_price_gross": 24990.0,
      "unit_price_net": 19677.17,
      "vat_rate": 27.0
    }
  ],
  "shipping": {
    "carrier_code": "gls",
    "method_title": "GLS Házhozszállítás",
    "price_gross": 1990.0,
    "label_base64": "JVBERi0xLjQKJcTl8uXr...<raw-pdf-stream>...EOF",
    "tracking_number": "GLS123456789HU"
  },
  "payment": {
    "method": "online_card",
    "is_paid": true,
    "transaction_id": "OTPPAY-987654321"
  },
  "voucher": {
    "code": "PEPITA10",
    "discount_gross": 2500.0
  }
}
```

---

## 2. Response Status Codes & Semantics

### 200 OK — Order Processed Successfully
Sent when the order is successfully claimed, verified, and created in WooCommerce (or if an exact duplicate order notification is re-sent).
```json
{
  "success": true,
  "external_id": "PEP-20261115-98765",
  "wc_order_id": 4812,
  "status": "completed",
  "duplicate": false,
  "message": "Order created successfully."
}
```

### 409 Conflict — Duplicate ID With Changed Payload
Sent when Pepita attempts to re-send an order ID that has already been claimed, but the order data, totals, or line items have changed. The existing WooCommerce order is preserved unmodified.
```json
{
  "code": "conflict",
  "message": "Order external ID already exists with a different payload hash.",
  "external_id": "PEP-20261115-98765",
  "existing_wc_order_id": 4812
}
```

### 422 Unprocessable Entity — Validation Failure
Sent when required fields are missing, invalid currency is provided, SKU cannot be resolved, or line items are unavailable.
```json
{
  "code": "validation_failed",
  "message": "Required SKU 'UNKNOWN-SKU-999' could not be resolved to an active product."
}
```

### 401 Unauthorized — Authentication Failure
Sent when API key is missing or invalid.
```json
{
  "code": "unauthorized",
  "message": "Invalid or missing order API credentials."
}
```

---

## 3. Idempotency & Deduplication Contract

1. **Exact Duplicate Resends**: If Pepita network timeouts cause duplicate delivery of the identical payload, WP Pepita Sync returns `200 OK` with `"duplicate": true` without creating multiple WooCommerce orders.
2. **Payload Hash Verification**: A SHA-256 hash of the normalized JSON payload is stored in the deduplication ledger table.
3. **Atomic Claiming**: Orders are claimed using database atomic conditional updates (`UPDATE ... WHERE status = 'failed' OR (status = 'processing' AND updated_at < NOW() - 300)`), guaranteeing zero duplicate order generation under high concurrency.
