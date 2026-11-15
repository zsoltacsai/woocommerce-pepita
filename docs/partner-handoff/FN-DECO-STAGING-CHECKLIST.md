# FN-DECO Staging Verification Checklist & Protocol

**Environment:** FN-DECO WooCommerce Staging Environment  
**Plugin Artifact:** `wp-pepita-sync.zip` (SHA-256: `64068a3034344f41be44ae9b6e3da808300f3bb0de64ac6636530ead17e0e907`)  
**Catalog Target:** 269 total products in database &rarr; 241 compliant products exportable to Pepita (28 items legitimately quarantined due to non-compliant resolution or missing mandatory attributes).

---

## Phase 1: Pre-Deployment Server Verification

- [ ] **PHP Environment**: PHP 8.1, 8.2, 8.3, 8.4, or 8.5 active with `bcmath`, `curl`, `json`, `mbstring`, `openssl`, `simplexml`, and `gd` or `imagick`.
- [ ] **Memory Limit**: `memory_limit` configured to at least `256M` (recommended: `512M` for large catalogs).
- [ ] **Database & Storage Permissions**:
  - `wp-content/uploads/` is writable by web server user.
  - Database user has `CREATE TABLE`, `ALTER`, `INDEX`, `SELECT`, `INSERT`, `UPDATE`, `DELETE` privileges.
- [ ] **WooCommerce Compatibility**: WooCommerce 7.0+ active with High-Performance Order Storage (HPOS) either enabled or running in classic CPT mode.

---

## Phase 2: Plugin Installation & Activation

- [ ] Upload `dist/wp-pepita-sync.zip` via WordPress Admin &rarr; Plugins &rarr; Add New &rarr; Upload Plugin.
- [ ] Verify SHA-256 checksum matches release manifest.
- [ ] Activate plugin.
- [ ] Verify database initialization:
  - Table `{$wpdb->prefix}wps_pepita_orders` created with indexes on `external_id`, `status`, `created_at`, `wc_order_id`.
- [ ] Verify upload directories created with `.htaccess` and `index.php` guards:
  - `wp-content/uploads/wps-pepita-feeds/`
  - `wp-content/uploads/wps-pepita-cache/`
  - `wp-content/uploads/wps-pepita-labels/`

---

## Phase 3: Merchant Profile Configuration

- [ ] Navigate to **Pepita &rarr; Eszközök és riportok**.
- [ ] In the "Profil importálása" card, upload `fn-deco-staging-profile.json`.
- [ ] Verify **Two-Step Preview**:
  - System redirects to `?page=wps-pepita-tools&wps_preview=1`.
  - Checksum status displays "Sértetlen" (Intact).
  - Visual diff table displays proposed shipping delay, price adjustment, and category rules.
  - Store settings remain unchanged before confirmation.
- [ ] Click "Megerősítés és importálás".
- [ ] Verify settings applied cleanly and staging transient is removed.

---

## Phase 4: Full Feed Generation & Quarantine Verification

- [ ] Navigate to **Pepita &rarr; Feedek** and trigger "Teljes feed generálása most".
- [ ] Inspect feed generation log:
  - Total catalog scanned: 269 products.
  - Exported products: 241 products.
  - Excluded/quarantined products: exactly 28 products.
- [ ] Verify reason breakdown in **Pepita &rarr; Hibás termékek**:
  - Undersized images (< 1200×630).
  - Missing mandatory attributes or category assignment.
- [ ] Download `full.xml` via tokenized link and inspect structure against Pepita XML schema.
- [ ] Verify WebP derivatives cached in `wp-content/uploads/wps-pepita-cache/`.

---

## Phase 5: Fast Stock Feed Verification

- [ ] Trigger "Készlet feed generálása most".
- [ ] Verify `stock.xml` completes in under 15 seconds.
- [ ] Confirm product IDs, prices, and stock quantities exactly mirror the 241 products in `full.xml`.

---

## Phase 6: Order Webhook Ingestion Sandbox Test

- [ ] Configure Order API key in **Pepita &rarr; Rendelés API**.
- [ ] Send test order webhook payload via curl:
  ```bash
  curl -X POST https://staging.fn-deco.example.test/wp-json/pepita/v1/orders \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer <API_KEY>" \
    -d @test-order-payload.json
  ```
- [ ] Verify response: HTTP `200 OK` with `success: true` and newly created `wc_order_id`.
- [ ] Verify in WooCommerce Admin:
  - Order appears in Orders list with "Pepita" custom badge.
  - Line items, quantities, customer shipping details, and vouchers match payload.
  - Shipping label PDF is downloadable and opens correctly.
- [ ] Re-send the identical webhook payload:
  - Verify HTTP `200 OK` with `duplicate: true`.
  - Verify NO duplicate WooCommerce order is created.

---

## Phase 7: Operational Reports & Cleanup

- [ ] Navigate to **Pepita &rarr; Eszközök és riportok**.
- [ ] Verify operational report summary displays total orders, success rate, and GMV.
- [ ] Click "Műveleti riport letöltése (CSV)".
- [ ] Verify downloaded CSV:
  - Customer emails are masked (`j***s@example.test`).
  - Customer phone numbers are redacted.
  - Formula characters (`=`, `+`, `-`, `@`) are safely prefixed.
