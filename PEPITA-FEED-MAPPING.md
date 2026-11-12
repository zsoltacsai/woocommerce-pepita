# PEPITA-FEED-MAPPING — XML element reference

Root element, namespace and document shape:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Catalog xmlns="https://pepita.hu/feed/1.0">
  <Product>
    …
  </Product>
</Catalog>
```

Only the root declares the namespace. Product fragments are written without it,
exactly as the wire format requires.

Two feeds are produced from the same catalog projection:

| Feed | URL | Content |
|---|---|---|
| Full | `?wps_feed=full&token=…` | Every element below |
| Stock | `?wps_feed=stock&token=…` | `Id`, `Availability`, `Prices` (`Currency`, `Price`, `DiscountedPrice`), `Variations` only |

The stock feed is a **complete snapshot**. It must contain the same product IDs
and variation values as the last successful full feed; a signature check
enforces this.

---

## 1. Product level

| XML element | Required | Source in WooCommerce | Rules |
|---|---|---|---|
| `Product` | yes | One per exported product | Simple and variable products |
| `Id` | yes | `_wps_export_id` meta, else `wps-{product_id}` | Persisted on first export; never derived from SKU; max 191 chars |
| `LastMod` | no | `date_modified` (Unix timestamp) | For variable products the newest variation timestamp wins |
| `StructuredId` | no | GTIN from the configured meta keys | 8, 12, 13 or 14 digits; invalid values are omitted, never fabricated |
| `ProductNumber` | no | `_wps_mpn` | Single line |
| `Descriptions` | yes | Container | |
| `Descriptions/Name` | yes | `_wps_title_override`, else product name | Single line, sanitised |
| `Descriptions/Description` | yes | `_wps_description_override`, else long/short description per `description_source` | HTML converted to plain text, shortcodes and media removed, line breaks preserved |
| `Descriptions/Brand` | no | `brand_source`: meta key chain (`_wps_brand`, `brand`, `_brand`) or taxonomy term | First non-empty value |
| `Descriptions/Manufacturer` | no | `_wps_manufacturer`, else `manufacturer_default` | Single line |
| `Prices` | yes | Container | |
| `Prices/Currency` | yes | `currency` setting, else WooCommerce currency | ISO code, uppercase |
| `Prices/Price` | yes | Gross regular price after adjustment | Must be > 0; 2 decimals, trailing zeros trimmed |
| `Prices/VatPercent` | yes | Tax class rate, or `vat_fixed` | 0–100, max 2 decimals; full feed only |
| `Prices/DiscountedPrice` | no | Gross sale price after adjustment | Only when lower than `Price` and > 0 |
| `Prices/PurchaseNetPrice` | no | `Price` converted to net | Only with `include_purchase_net`; full feed only |
| `Prices/ShippingPrice` | no | `_wps_shipping_price`, else `shipping_price` setting | Only when > 0; full feed only |
| `Warranty` | no | `_wps_warranty_type` + `_wps_warranty_duration`, else settings | `Type` ∈ `None`, `Day`, `Week`, `Month`, `Year`; omitted when `None` or duration empty |
| `Warranty/Type` | yes* | Warranty type | *Required when `Warranty` is present |
| `Warranty/Duration` | yes* | Digits only | *Required when `Warranty` is present |
| `Categories` | yes | One block per category path | Up to `max_category_paths` paths |
| `Categories/Category` | yes | One per level, root → leaf | |
| `Categories/Category/Id` | no | Term ID | Written when > 0 |
| `Categories/Category/Name` | yes | Term name | Sanitised |
| `Photos` | yes | Container | At least one usable image is mandatory |
| `Photos/Photo` | yes | Featured image first, then gallery | Up to `max_gallery_images` |
| `Photos/Photo/Url` | yes | Attachment URL | Absolute `http(s)` URL; `ftp`, relative and space-containing URLs are rejected |
| `Photos/Photo/IsPrimary` | yes | `true` for the first image, otherwise `false` | Exactly one primary in practice |
| `Photos/Photo/Title` | no | Attachment title | Omitted when empty |
| `Photos/Photo/PrimaryVariation` | no | First variation value the image belongs to | Only for variable products |
| `Photos/Photo/SecondaryVariation` | no | Second variation value | |
| `Photos/Photo/TertiaryVariation` | no | Third variation value | |
| `VideoLinks` | no | `_wps_video_links` | Comma-separated, validated `http(s)` URLs, duplicates removed |
| `ProductUrl` | no | `get_permalink()` | Spaces percent-encoded |
| `Availability` | yes | Container | |
| `Availability/Available` | yes | `is_in_stock()` | `true` / `false` |
| `Availability/Quantity` | no | `get_stock_quantity()` when stock is managed | Omitted when stock is not managed (= unlimited) |
| `Availability/RestockDate` | no | `_wps_restock_date` | `YYYY-MM-DD` only; full feed only |
| `Availability/ShippingDelay` | no | `_wps_shipping_delay` → category → `shipping_delay` setting | Days, integer ≥ 0 |
| `Attributes` | no | Non-variation product attributes | Omitted when empty |
| `Attributes/Attribute` | yes* | Container | *Required when `Attributes` is present |
| `Attributes/Attribute/AttributeName` | yes | Attribute label | |
| `Attributes/Attribute/AttributeValue` | yes | Comma-joined term names | |
| `VolumeDimensions` | no | Width, height, length, weight | Uses store units; 4 decimals |
| `VolumeDimensions/Width` | no | Product width | |
| `VolumeDimensions/Height` | no | Product height | |
| `VolumeDimensions/Length` | no | Product length | |
| `VolumeDimensions/Weight` | no | Product weight | |
| `Variations` | no | Variable products only | |
| `Variations/PrimaryAttribute` | yes* | First variation axis label | *Required when `Variations` is present |
| `Variations/SecondaryAttribute` | no | Second axis label | |
| `Variations/TertiaryAttribute` | no | Third axis label | |
| `Variations/Variation` | yes* | One per published variation | Max 3 axes |
| `Variations/Variation/PrimaryVariation` | yes* | First axis value | |
| `Variations/Variation/SecondaryVariation` | no | Second axis value | |
| `Variations/Variation/TertiaryVariation` | no | Third axis value | |
| `Variations/Variation/Prices` | yes | Container | |
| `Variations/Variation/Prices/Currency` | yes | Product currency | |
| `Variations/Variation/Prices/Price` | yes | Variation gross price after adjustment | Falls back to the parent price when empty |
| `Variations/Variation/Prices/DiscountedPrice` | no | Variation sale price | Only when lower than the variation price |
| `Variations/Variation/Availability` | yes | Container | |
| `Variations/Variation/Availability/Available` | yes | Variation stock status | |
| `Variations/Variation/Availability/Quantity` | no | Variation stock quantity | Omitted when not managed |
| `Variations/Variation/Availability/ShippingDelay` | no | Inherited from the parent product | |

---

## 2. Element order in the full feed

The writer emits a fixed order. Consumers that validate schema order rely on it:

```
Id
LastMod
StructuredId?
ProductNumber?
Descriptions
  Name
  Description
  Brand?
  Manufacturer?
Prices
  Currency
  Price
  VatPercent
  DiscountedPrice?
  PurchaseNetPrice?
  ShippingPrice?
Warranty?
  Type
  Duration
Categories (repeated)
  Category (repeated)
    Id?
    Name
Photos?
  Photo (repeated)
    Url
    IsPrimary
    Title?
    PrimaryVariation?
    SecondaryVariation?
    TertiaryVariation?
VideoLinks?
ProductUrl?
Availability
  Available
  Quantity?
  RestockDate?
  ShippingDelay?
Attributes?
  Attribute (repeated)
    AttributeName
    AttributeValue
VolumeDimensions?
  Width?
  Height?
  Length?
  Weight?
Variations?
  PrimaryAttribute
  SecondaryAttribute?
  TertiaryAttribute?
  Variation (repeated)
    PrimaryVariation
    SecondaryVariation?
    TertiaryVariation?
    Prices
      Currency
      Price
      DiscountedPrice?
    Availability
      Available
      Quantity?
      ShippingDelay?
```

`?` = omitted when empty or not applicable.

## 3. Stock feed shape

```xml
<Product>
  <Id>wps-42</Id>
  <Availability>
    <Available>true</Available>
    <Quantity>12</Quantity>
    <ShippingDelay>2</ShippingDelay>
  </Availability>
  <Prices>
    <Currency>HUF</Currency>
    <Price>4990</Price>
    <DiscountedPrice>3990</DiscountedPrice>
  </Prices>
  <Variations>
    …
  </Variations>
</Product>
```

- No `VatPercent`, `PurchaseNetPrice`, `ShippingPrice`, `RestockDate`.
- No descriptions, images, categories, warranty, dimensions or attributes.
- `Id` and every variation value are byte-identical to the full feed.

## 4. Pricing rules

```
base          = wc_get_price_including_tax( price )
adjusted      = base ± fixed | base × (1 ± percent/100)
rounded       = per rounding mode
DiscountedPrice = adjusted sale price, only if < adjusted price and > 0
PurchaseNetPrice = adjusted / (1 + vat/100)
```

- Prices are always **gross**; WooCommerce data is never modified.
- `rounding`: `nearest_1`, `nearest_5`, `nearest_10`, `nearest_100`, `up_1`,
  `down_1`, `none`.
- Adjustment resolution: product meta → deepest assigned category → global.
- A sale price that is not lower than the regular price is dropped and logged as
  `sale_price_ignored` rather than exported as a fake discount.

## 5. Stock and availability rules

| WooCommerce state | `Available` | `Quantity` |
|---|---|---|
| In stock, stock not managed | `true` | omitted (unlimited) |
| In stock, quantity 10 | `true` | `10` |
| Out of stock, quantity 0 | `false` | `0` |
| Backorder allowed, quantity ≤ 0 | per `is_in_stock()` | actual quantity, min 0 |
| Variable parent | `true` if any variation is in stock | sum of managed variation quantities, omitted if none managed |
| Retired product under `zero_stock` | `false` | `0` |

`Available=false` with a positive `Quantity` is never produced; quantities are
clamped to ≥ 0 and the availability flag is authoritative.

## 6. Variation identity

- Axes are the product's variation attributes, ordered by
  `WC_Product_Attribute::get_position()`.
- Axis label: `wc_attribute_label()`; falls back to the attribute key.
- Value: the variation's `attribute_{key}` value; taxonomy slugs are resolved to
  term names.
- Identity is the ordered tuple of values. It is used for duplicate detection and
  for matching the stock feed to the full feed.
- Maximum three axes. More is an error (`too_many_variation_attributes`), not a
  silent truncation.
- Two variations with the same tuple produce `duplicate_variation`.
- A product previously exported as variable cannot become simple
  (`variable_to_simple_forbidden`) because its variations would be orphaned at
  Pepita.

## 7. Category paths

- Paths are built from the product's assigned `product_cat` terms, root → leaf.
- A product assigned to a child category yields the full ancestor chain.
- Multiple assignments produce multiple `<Categories>` blocks, capped by
  `max_category_paths`.
- `Categories/Category/Id` is the WooCommerce term ID; `Name` is the term name.

## 8. Text sanitisation

`Description_Sanitizer` and `Xml_Writer::clean()` guarantee valid XML:

| Input | Output |
|---|---|
| HTML (`<p>`, `<strong>`, lists) | Plain text with paragraph/line breaks |
| Shortcodes (`[gallery]`, `[caption]`) | Removed |
| Embedded media / scripts / styles | Removed |
| HTML entities | Decoded |
| Runs of spaces | Collapsed |
| Invalid UTF-8 | Repaired |
| Control characters `\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F` | Removed |
| `&`, `<`, `>`, quotes | Escaped by `XMLWriter` |

## 9. Validation rules

A product is exported only when all hard requirements pass:

| Code | Condition |
|---|---|
| `missing_product` | Product cannot be loaded |
| `product_status` | Status not in `allowed_product_statuses` |
| `unsupported_product_type` | Not simple/variable |
| `missing_identifier` | Empty export ID |
| `identifier_too_long` | > 191 chars |
| `missing_name` | Empty name |
| `missing_description` | Empty description |
| `missing_category` | No `product_cat` term |
| `missing_image` | No usable featured or gallery image |
| `invalid_image_url` | Non-HTTP or malformed image URL |
| `invalid_price` | Price ≤ 0 |
| `invalid_vat` | VAT outside 0–100 |
| `invalid_currency` | Not a 3-letter code |
| `invalid_gtin` | GTIN present but not 8/12/13/14 digits |
| `gtin_required` | Category requires GTIN and none is present |
| `invalid_product_url` | Permalink is not HTTP |
| `no_variation_attributes` | Variable product without variation attributes |
| `too_many_variation_attributes` | More than 3 axes |
| `invalid_variation_structure` | Missing/mismatched variation values |
| `duplicate_variation` | Two variations share the same tuple |
| `no_published_variations` | No published variation |
| `invalid_variation_price` | Variation price ≤ 0 |
| `variable_to_simple_forbidden` | Shape changed from variable to simple |

Warnings do not block export: `long_name`, `no_primary_image`,
`invalid_video_url`, `sale_price_ignored`, `missing_mpn`.

Whole-file validation additionally checks: well-formed XML, `<Catalog>` root,
Pepita namespace, duplicate `Id` values, and that the number of `<Product>`
elements equals the number of successfully exported products.

## 10. Removal policy

| Policy | Effect |
|---|---|
| `omit` (default) | A product that stops being eligible disappears from the next feed. Pepita treats missing IDs as deleted. |
| `zero_stock` | The product is written with `Available=false` and `Quantity=0` for `removal_grace_days`, then omitted. |

Retired products are tracked in the catalog index with `retired_at`. If a retired
product can no longer be mapped validly, it is logged and omitted rather than
emitted as an invalid record.
