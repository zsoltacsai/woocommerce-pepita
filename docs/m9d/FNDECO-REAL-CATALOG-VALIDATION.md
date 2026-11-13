# WP Pepita Sync — FN-DECO Valós Katalógus Validáció és Kompatibilitási Jelentés

**Dátum:** 2026. november 13.  
**Célkitűzés:** Az FN-DECO valós katalógusán (`https://fndeco.com/staging/`) azonosított eltérések javítása, az M9-D béta feed megfelelőségének biztosítása a hétfői (2026. november 16.) átadásra.  
**Alap commit:** `55b0c984568e972ba24fc4a5a9d72f13f0abf7cb` (PR #8 HEAD)  
**Munkaág:** `fix/m9d-fndeco-catalog-compatibility`  
**Éles üzemmód státusz:** **STRICT NO-GO** (Kizárólag béta feed tesztelés, rendelés API letiltva).

---

## 1. Azonosított problémák és P0 javítások összefoglalása

### 1.1 Készletmennyiség felső korlát (Quantity Cap - 999 999)
* **Probléma:** A Pepita piactér XML specifikációja nem fogad el 999 999-nél nagyobb készletértéket. Egyes WooCommerce áruházakban a nagykereskedelmi vagy virtuális készletek értéke meghaladja ezt (pl. 1 500 000 vagy 927 999 993 darab).
* **Megoldás:**
  * Bevezetésre került a `Product_Mapper::MAX_EXPORT_QUANTITY = 999999` konstans.
  * A `Product_Mapper::quantity()` metódus automatikusan korlátozza a kezelt készletet: `min(max(0, (int) $product->get_stock_quantity()), self::MAX_EXPORT_QUANTITY)`.
  * Ha a készlet meghaladja a 999 999-et, a rendszer diagnosztikai figyelmeztetést rögzít (`stock_quantity_capped`), miközben a WooCommerce forrásadatokhoz soha nem nyúl (read-only elv).
  * A nem kezelt készletű (`! $product->managing_stock()`) termékek szemantikája változatlan: a `quantity` értéke `null`, így a feedben az `<Availability>` blokkban nem jelenik meg `<Quantity>` tag, megfelelve az unmanaged készlet szabályainak.
  * Variációs termékeknél az összesített készlet és az egyes variációk készlete is betartja a plafont.

### 1.2 Pontosan egy elsődleges kategória hierarchia (Single Primary Category Hierarchy)
* **Probléma:** Több kategóriához rendelt WooCommerce termékek esetén a Pepita pontosan egy darab `<Categories>` konténert fogad el, amely a gyökértől a legmélyebb levélelemig tartalmazza a kategória hierarchiát.
* **Megoldás:**
  * Determinisztikus prioritási sorrend:
    1. **Kifejezett felülírás:** Termék metaboxban beállított `_wps_primary_category_id`.
    2. **SEO beállítások:** Yoast SEO (`_yoast_wpseo_primary_product_cat`) vagy Rank Math (`rank_math_primary_product_cat`) elsődleges kategória.
    3. **Determinisztikus stratégia:** Beállítások menüből választható (`deepest` [alapértelmezett], `lowest_id`, `highest_id`, `alphabetical`).
  * **Kategória kizárás és béta allowlist védelem:** A kiválasztott elsődleges kategória soha nem kerülheti meg a kizárási szabályt (`exclude`), és béta módban kötelezően a jóváhagyott kategóriákból kell kikerülnie.
  * **FN-DECO validáció:** A 9897 ("Termékek") és 9901 ("Dísztárgyak") kategóriához rendelt termék hibátlanul generál egyetlen `<Categories>` blokkot `9897 -> 9901` struktúrával.

### 1.3 Bruttó és nettó árforrás konfiguráció (`price_tax_mode`)
* **Probléma:** Az FN-DECO áruházban a megadott termékárak már bruttó fogyasztói árak (pl. 1300 Ft bruttó, 27% áfa mellett). A korábbi automatikus áfaszámítás ezt nettónak tekintve 1651 Ft-os árat generált volna (kettős adóztatás).
* **Megoldás:**
  * Új beállítás: `price_tax_mode` (`auto`, `gross`, `net`, alapértelmezett: `auto`).
  * `gross` módban a `Price_Calculator::gross()` közvetlenül a megadott árat adja vissza, nem számolja rá újra az áfát.
  * Az áfakulcs (`VatPercent`) helyesen 27%-ként jelenik meg.
  * Ha a beszerzési nettó ár exportálása aktív, a rendszer a 1300 Ft-ból visszaszámolja a nettó értéket (1023.62 Ft), így nem kerül hibás adat a feedbe.

### 1.4 WooCommerce SKU leképezése ProductNumber (MPN) mezőre
* **Probléma:** A kereskedői katalógus azonosításához szükséges a WooCommerce natív cikkszáma (`$product->get_sku()`), de ez nem keveredhet a GTIN-nel vagy a stabil export azonosítóval.
* **Megoldás:**
  * Ha nincs egyedi `_wps_mpn` megadva, a natív `$product->get_sku()` automatikusan bekerül a `<ProductNumber>` tagbe.
  * Ha a kereskedő kitöltötte a Pepita MPN felülírást, az elsőbbséget élvez.
  * Az SKU soha nem kerül a `<StructuredId>` mezőbe (az kizárólag érvényes GTIN/EAN/ISBN kódoknak van fenntartva).
  * A stabil export azonosító (`wps-<id>` vagy `_wps_export_id`) teljesen független marad az SKU-tól.

### 1.5 Leírás formázásának megőrzése (Paragraphs & Line Breaks)
* **Validáció:** A `Description_Sanitizer` tesztelésre került összetett HTML leírásokkal. A több bekezdésből álló szövegek (`<p>`), a sortörések (`<br>`) és a felsorolások (`<li>`) tisztán, dupla illetve szimpla sortöréssel kerülnek a Pepita feedbe, miközben a veszélyes HTML tag-ek és scriptek maradéktalanul eltávolításra kerülnek.

### 1.6 WebP képek és 300x300 felbontási ellenőrzés
* **Megoldás:**
  * Létrejött az `Image_Converter` osztály.
  * A WebP képekhez a rendszer automatikusan JPEG származékot (`derivative`) generál a `wp-content/uploads/wps-derivatives/` mappába GD vagy Imagick segítségével.
  * A célmappát `.htaccess` és `index.php` védi a közvetlen listázástól és script futtatástól.
  * A rendszer soha nem ad vissza hamis `.jpg` kiterjesztést nyers WebP bájtokra.
  * Ha a kép felbontása nem éri el a minimális 300x300 képpontot, a rendszer diagnosztikai figyelmeztetést rögzít (`insufficient_image_dimensions`), de nem végez minőségromboló felméretezést (upscaling).
  * Ha egy termékhez egyáltalán nem generálható kompatibilis kép, a termék nem kerül exportálásra és `missing_image` hibával jelenik meg a felülvizsgálati listában.

### 1.7 Admin Preflight ellenőrző felület és CSV export
* **Megoldás:**
  * Új almenü: **Pepita -> Feed ellenőrzés** (`wps-pepita-preflight`).
  * KPI összesítők: Teljes termékszám, jogosult termékek, kizárt termékek, azonnal exportálható termékek, figyelmet igénylő termékek.
  * Részletes audit táblázat: Készletkorlát túllépések, WebP konverziók, 300x300 alatti felbontások, hiányzó cikkszámok, elsődleges kategória feloldások.
  * Teljes táblázat exportálása Excel-kompatibilis UTF-8 BOM CSV formátumban.

---

## 2. Automatizált tesztelési jegyzőkönyv

A fenti funkciókat a `tests/cases/210-fndeco-catalog-compatibility-test.php` tesztcsomag fedi le:

| Teszteset | Vizsgált funkció | Eredmény |
|---|---|---|
| `fndeco inventory: managed stock below or at cap` | 0, 849, 999 999 készlet pontos átadása | **PASS** |
| `fndeco inventory: managed stock exceeding 999999` | 1.5M és 927M készlet 999 999-re korlátozása, warning rögzítése | **PASS** |
| `fndeco inventory: unmanaged stock preserves null` | Nem kezelt készlet esetén `<Quantity>` tag kihagyása | **PASS** |
| `fndeco category: exactly one primary category hierarchy` | Több kategóriás terméknél pontosan 1 `<Categories>` blokk | **PASS** |
| `fndeco category: explicit override precedence` | `_wps_primary_category_id` felülírás érvényesülése | **PASS** |
| `fndeco category: Yoast and RankMath precedence` | SEO plugin elsődleges kategória detektálása | **PASS** |
| `fndeco category: deterministic fallback strategies` | `deepest`, `lowest_id`, `highest_id`, `alphabetical` stratégiák | **PASS** |
| `fndeco category: FN-DECO real catalog acceptance` | Kategória 9897 és 9901 valós fa struktúra | **PASS** |
| `fndeco pricing: price_tax_mode gross` | 1300 Ft bruttó ár megtartása kettős adóztatás nélkül | **PASS** |
| `fndeco pricing: price_tax_mode net` | 1000 Ft nettó ár 1270 Ft bruttóra váltása | **PASS** |
| `fndeco sku: native WC SKU maps to ProductNumber` | `HDS15-016` és `00123` átadása `<ProductNumber>`-ként | **PASS** |
| `fndeco descriptions: line breaks and paragraphs` | Bekezdések és sortörések megtartása | **PASS** |
| `fndeco images: Image_Converter identifies WebP` | WebP formátum észlelése, dimenziók ellenőrzése | **PASS** |
| `fndeco preflight: audit correctly calculates summary` | Preflight statisztika és hibagyűjtés | **PASS** |
