# WP Pepita Sync — Partner & Merchant Integration Guide

**Plugin Version:** 1.0.0 (Release Candidate M11)  
**Target Systems:** WordPress 6.0+ / WooCommerce 7.0+ (Full HPOS and Legacy CPT compatibility)  
**Security Standard:** Strictly isolated repository operations; zero runtime coupling to third-party stores; defense-in-depth authorization (`manage_woocommerce`) and CSRF (`wp_nonce`).

---

## 1. System Overview & Architecture

WP Pepita Sync is a carrier-grade WooCommerce integration plugin connecting merchants directly to the Pepita.hu marketplace ecosystem. It provides bidirectional catalog synchronization, intelligent image processing, continuous price/stock feeds, automated order ingestion, and operational reporting.

```
[ WooCommerce Store ] <====== (XML Feeds: Full / Stock) ======> [ Pepita Marketplace Catalog ]
[ WooCommerce Store ] <====== (JSON Webhook / Order API) <====== [ Pepita Order Dispatcher ]
```

### Core Architecture Pillars:
- **Streaming XML Feeds**: Memory-bounded XML generation processing large product catalogs in chunks without memory exhaustion or timeouts.
- **Safety Publishing Gate**: Detects abnormal catalog shrinkage (>20% drop), mass price shifts, or margin violations, automatically quarantining the feed for merchant approval.
- **Order State Machine**: Enforces strictly idempotent order claiming via dedicated database repository (`{$wpdb->prefix}wps_pepita_orders`) preventing duplicate order creation or race conditions.
- **Crash-Safe Merchant Profiles**: Enables exporting and importing portable, secret-free store configurations with a persistent recovery journal guaranteeing atomicity.
- **PII-Free Operational Reporting**: In-memory, bounded operational analytics with automatic email and phone masking and formula neutralization in CSV exports.

---

## 2. Catalog & Feed Synchronization

### 2.1 Feed Types & Lifecycle
1. **Full Product Feed (`full.xml`)**:
   - Contains complete product data: identifiers, titles, descriptions, categories, attributes, prices, stock, image URLs, and variation matrices.
   - Run schedule: Daily (recommended off-peak between 01:00 and 04:00 UTC).
2. **Stock & Fast Price Feed (`stock.xml`)**:
   - Lightweight, delta-focused feed containing only SKU/ID, price, stock quantity, and availability.
   - Run schedule: Every 15–30 minutes.
   - **Invariant**: The stock feed will refuse publication if the catalog structure differs from the last published full feed snapshot.

### 2.2 Intelligent Image Compliance
- **Specification**: Requires JPG/PNG with minimum dimensions of 1200×630 pixels.
- **WebP & Large Image Optimization**: Dynamically decodes and creates compliant JPEG derivatives, cached securely using content hashes in `wp-content/uploads/wps-pepita-cache/`.
- **Validation**: Strict file-header and EOF validation rejects truncated, corrupted, or malicious image attachments before inclusion in XML.

### 2.3 Category Rules & Attribute Mapping
- **Hierarchical Inheritance**: Subcategories inherit parent rules unless explicitly overridden.
- **Price Adjustments**: Support for percentage (`+5%`, `-3%`) or fixed amount adjustments per category.
- **Multi-Store Portability**: Category rules are stored and referenced by **term slug** (`butor`, `elektronika`) ensuring zero dependency on database auto-increment IDs during site migration or staging rollout.

---

## 3. Order Processing & Webhook API

### 3.1 Order Flow
1. Pepita issues an HTTPS POST request containing order JSON to `/wp-json/pepita/v1/orders`.
2. The payload signature / API key is authenticated.
3. The order claim is acquired in `wps_pepita_orders` (`status = 'processing'`).
4. WooCommerce order is constructed:
   - Line items resolved via SKU or Exporter Product ID.
   - Shipping methods and payment gateways mapped to local methods.
   - Pepita vouchers applied as negative fees.
   - Shipping label saved as PDF to secure directory.
5. Claim is finalized (`status = 'completed'`, `wc_order_id = <ID>`).

### 3.2 Label Storage & Download Security
- Package PDF labels are stored under `wp-content/uploads/wps-pepita-labels/`.
- Admin download endpoint validates canonical realpath with `DIRECTORY_SEPARATOR` containment, enforcing `.pdf` extension and sending `X-Content-Type-Options: nosniff`.

---

## 4. Merchant Configuration Profiles & Automation

### 4.1 Export & Portability
- Exports portable settings and category rules into JSON.
- **Security Invariant**: Strictly filters through `PORTABLE_SETTINGS_ALLOWLIST`. All secrets (`feed_token`, `order_api_key`, `storage_key`, alert URLs/emails) and security toggles are completely excluded.
- Computes SHA-256 canonical checksum for integrity verification.

### 4.2 Two-Step Safe Import Flow
1. **Upload & Staging**: Uploaded JSON is validated and staged in a temporary transient (10-minute TTL). **No settings or database records are modified.**
2. **Visual Diff Preview**: The admin inspects side-by-side differences (current vs proposed values) and checksum status.
3. **Explicit Confirmation**: The merchant clicks "Megerősítés és alkalmazás", which requires valid nonce and capability.
4. **Crash-Safe Atomicity**:
   - A recovery journal (`wps_pepita_import_journal`) is written to `wp_options` before any writes.
   - If an exception occurs, compensating rollback restores baseline settings and category metadata.
   - If a crash occurs mid-execution, `recover_interrupted_import()` restores clean state on next admin load.

---

## 5. Security & Operational Hardening Checklist

| Domain | Control | Implementation |
|---|---|---|
| **Access Control** | Admin capability | Enforces `manage_woocommerce` on all screens, AJAX actions, and download handlers. |
| **CSRF Defense** | Nonce verification | Unique nonces for all state-changing actions. |
| **Data Protection** | PII Redaction | Emails (`Redactor::mask_email`), phones (`[REDACTED_PHONE]`) masked in logs and reports. |
| **Formula Injection** | CSV Sanitization | `Logger::sanitize_csv_field` prefixes `=`, `+`, `-`, `@`, `\t`, `\r` with `'`. |
| **Memory Bounds** | Resource Protection | Chunked DB queries (`LIMIT 1000`), bounded image decode buffers. |
