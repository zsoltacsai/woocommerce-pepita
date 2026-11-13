# COMPATIBILITY-REPORT — WP Pepita Sync 1.0.0

## 1. Declared support

| Component | Declared | Verified here | Notes |
|---|---|---|---|
| WordPress | 6.4+ | **7.1.4** | Compose integration suite (minimum 6.4 not exercised) |
| WooCommerce | 8.2+ | **11.3.0** | Compose integration suite (8.2–10.x not exercised) |
| PHP | 8.1+ | **8.5.12** | Lint + full unit suite + Compose suite |
| MySQL / MariaDB | 5.7 / 10.3+ | **MySQL 8.4.13** | Real `dbDelta` and queries; MariaDB not exercised |
| HPOS | Declared compatible | **Yes and no** | Both storage modes pass the same 60-case suite |
| Multisite | Not declared | No | Per-site tables and options |

The plugin header states `Requires at least: 6.4`, `Requires PHP: 8.1`,
`WC requires at least: 8.2`, `WC tested up to: 10.0`.

## 2. WordPress compatibility

| Area | Implementation | Risk |
|---|---|---|
| Hooks | Core hooks plus prefixed custom hooks | Low |
| Options | Single non-autoloaded option; individual state options | Low |
| Cron | `wp_schedule_event` fallback, custom schedules | Low |
| REST | `register_rest_route`, `WP_REST_Request/Response` | Low |
| Filesystem | `wp_upload_dir`, `wp_mkdir_p`, direct `rename`/`fopen` | Low; requires a writable uploads directory and same-filesystem temp files |
| i18n | `load_plugin_textdomain`, `wp-pepita-sync` | Low |
| Admin | `add_menu_page`, `add_submenu_page`, `admin_post_*`, `wp_ajax_*` | Low |
| Capabilities | `manage_woocommerce`, `edit_product`, `manage_product_terms` | Low |
| Uninstall | `uninstall.php`, opt-in deletion | Low |

No deprecated WordPress APIs are used. No `wp_localize_script` reliance beyond
standard enqueueing.

## 3. WooCommerce compatibility

| Area | Implementation | HPOS impact |
|---|---|---|
| Products | `wc_get_product`, `wc_get_products`, CRUD getters | None |
| Orders | `wc_create_order`, setters, `add_product`, `add_item`, `calculate_totals`, `save` | Safe |
| Order meta | `$order->update_meta_data()` / `get_meta()` | Safe |
| Order items | `WC_Order_Item_Product/Shipping/Fee` objects | Safe |
| Statuses | `wc_get_order_statuses()` and `set_status()` | Safe |
| Emails | `woocommerce_email_enabled_*` filters | Safe |
| Stock | `is_in_stock`, `managing_stock`, `get_stock_quantity` | None |
| Tax | `wc_get_price_including_tax`, `WC_Tax::get_rates`, `wc_tax_enabled` | None |
| Attributes | `WC_Product_Attribute`, `wc_attribute_label` | None |
| Product brands | Optional taxonomy, default `product_brand` | None |
| HPOS declaration | `FeaturesUtil::declare_compatibility( 'custom_order_tables', … )` | Required |

The plugin performs **no** direct SQL against `wp_posts`, `wp_postmeta`,
`wp_wc_orders` or `wp_woocommerce_order_items`. A source scan enforces this in
the test suite.

### HPOS checklist

- [x] Declared via `before_woocommerce_init`.
- [x] Orders created and read only through CRUD.
- [x] No `get_post_meta()` on order IDs.
- [x] No `$wpdb` queries against order tables.
- [ ] Verified on a live HPOS store (manual step).

## 4. PHP compatibility

- Typed properties, constructor property promotion not used, `match`, arrow
  functions, `str_starts_with`, `str_contains`, nullsafe not required.
- No dynamic properties.
- No deprecated `setAccessible()` calls.
- `XMLWriter`, `SimpleXML`, `mbstring`, `json`, `hash` extensions required
  (all standard).
- Tested on PHP 8.5.12 with `declare(strict_types)` absent, consistent with
  WordPress coding standards.

Potential PHP 8.x notices were reviewed: array offsets are guarded, nullable
returns are typed, and `preg_*` failures fall back to empty values.

## 5. Multisite

Not officially supported. On multisite:

- Network activation runs the installer per site on first load.
- Tables and options are per site (`$wpdb->prefix` includes the blog ID).
- Feed storage is per site under that site's uploads directory.
- Scheduled events are per site.

No network-wide settings screen exists. If network activation is used, each site
must be configured individually.

## 6. Conflicts and isolation

| Check | Result |
|---|---|
| Shared symbols with the Allegro plugin | None (`WPS_`/`wps_` only, verified by test) |
| Global function declarations | None outside classes |
| Option/meta/table collisions | Prefixed; verified by test |
| Hook name collisions | Prefixed; verified by test |
| Text domain collisions | `wp-pepita-sync` only |
| Rewrite rules | None added |
| `init` priority | Feed endpoint at `0`, scheduler at `30` |

## 7. Hosting requirements

| Requirement | Why |
|---|---|
| Writable `wp-content/uploads` | Feed, index and label storage |
| `rename()` across the temp and target path | Atomic publish; both live in the same directory |
| `fopen`/`fwrite`/`fpassthru` allowed | Streaming generation and delivery |
| `XMLWriter` and `SimpleXML` | Feed writing and validation |
| Enough `max_execution_time` per slice | Default budget is 20 s; reduce `batches_per_run` on slow hosts |
| Real cron or Action Scheduler | Reliable scheduled generation |

If `DISABLE_WP_CRON` is set, a system cron must call `wp cron event run
--due-now`.

## 8. Known limitations

1. **Pepita-side integration is unverified.** The plugin passes a real
   WordPress/WooCommerce/MySQL Compose suite on both HPOS and legacy storage, but
   no Pepita seller staging account was available: feed acceptance, the variation
   shape and live order forwarding still need a staging pass.
2. **Product types.** Only `simple` and `variable` are exported. Subscriptions,
   bundles, grouped and external products are rejected with
   `unsupported_product_type`.
3. **Variable order lines.** The Pepita payload does not identify a variation,
   so a variable parent in an order is rejected rather than guessed.
4. **Stock feed is full-snapshot.** Very large catalogs produce a large stock
   file; the design assumes Pepita accepts complete snapshots, as documented.
5. **Single currency per order.** Mixed-currency payloads are rejected.
6. **No network admin UI** on multisite.
7. **Feed generation is per site**, not shared across a multisite network.

## 9. Manual verification plan

Before production, on staging with the real stack:

1. Activate on the store's actual WordPress and WooCommerce versions (the local
   suite covers WordPress 7.1.4 / WooCommerce 11.3.0 only).
2. Confirm `dbDelta` created both tables with the expected indexes (already
   exercised on MySQL 8.4; repeat on the store's server).
3. Repeat the order import tests on the store's order storage mode.
4. Run the manual plan in `TESTING.md` §6, including Pepita acceptance.
5. Test with a page cache and an object cache enabled.
6. Test with `WP_DEBUG` and `WP_DEBUG_LOG` on; confirm no notices.
7. Confirm the feed directory is not reachable directly over HTTP.
8. Confirm Action Scheduler runs the continuation jobs (locally verified).

## 10. Version matrix

Locally verified by the Compose suite:

| WordPress | WooCommerce | PHP | HPOS | Result |
|---|---|---|---|---|
| 7.1.4 | 11.3.0 | 8.5 | off | 60 passed, 0 failed |
| 7.1.4 | 11.3.0 | 8.5 | on | 60 passed, 0 failed |

Still to record on staging:

| WordPress | WooCommerce | PHP | HPOS | Result |
|---|---|---|---|---|
| _pending_ | _pending_ | 8.1 | off | |
| _pending_ | _pending_ | 8.2 | off | |
| _pending_ | _pending_ | 8.3 | on | |
| _pending_ | _pending_ | 8.4 | on | |

This table must be filled with real results before the plugin is called
production-ready.
