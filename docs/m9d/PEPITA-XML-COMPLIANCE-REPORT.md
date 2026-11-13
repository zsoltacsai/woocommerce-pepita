# Pepita XML Contract Compliance Report (M9-D)

**Dátum:** 2026-11-13  
**Státusz:** VERIFIED CONTRACT COMPLIANT  
**Környezeti kapu:** STAGING EXECUTION ONLY — Production STRICT NO-GO  
**Külső partner kapcsolat:** STRICT HOLD (zero live requests to Pepita endpoints)  
**Referencia dokumentáció:**
* [Pepita XML Formátum](https://pepita.hu/partners/xml-format)
* [Pepita Feed és API kapcsolatok](https://sellercenter.pepita.com/feed-es-api-kapcsolatok/)

---

## 1. Vezetői összefoglaló (Executive Summary)

Az M9-D mérföldkő célja a WooCommerce &rarr; Pepita integráció termék- (`wps_feed=full`) és készlet/ár (`wps_feed=stock`) XML feedjeinek teljes, bizonyítható megfeleltetése a Pepita hivatalos, publikusan dokumentált XML specifikációjával.

A fejlesztés során:
1. **Hivatalos szerződés audit és mátrix:** Részletesen feldolgozásra került a Pepita hivatalos HTML specifikációja és XML mintafájljai (`docs/m9d/pepita-sample.xml`, `docs/m9d/pepita-stock-sample-hu.xml`). Elkészült a 97 XML mezőt lefedő [PEPITA-XML-CONTRACT-MATRIX.md](file:///home/zsolt/Projects/woocommerce-pepita/docs/m9d/PEPITA-XML-CONTRACT-MATRIX.md).
2. **Katalógus leképezés bővítése:**
   - Digitális / e-mailben kézbesített termékek támogatása (`<EmailDelivery>true</EmailDelivery>`).
   - Könyv specifikus metaadatok leképezése (`<Descriptions><Author>...</Author><Publisher>...</Publisher></Descriptions>`).
   - Variációs árak és beszerzési adatok (`<Prices><VatPercent>`, `<PurchaseNetPrice>`, `<ShippingPrice></Prices>`).
   - Variációs beérkezési dátum (`<RestockDate>YYYY-MM-DD</RestockDate>`).
3. **Variáció-kezelési szabályok:**
   - 1, 2 és 3 dimenziós variációk transzparens támogatása (`PrimaryAttribute`, `SecondaryAttribute`, `TertiaryAttribute`).
   - 4. tengely szigorú és determinisztikus elutasítása (hibaüzenet generálása, a termék nem kerül exportálásra, megelőzve a piactéri elutasítást).
4. **Készlet/ár feed (Stock feed):**
   - Garantáltan teljes aktív katalógus pillanatkép (full active catalog snapshot), nem delta feed.
   - Hivatalos szerződés szerint: ami kimarad a feedből, azt a Pepita töröltnek / inaktívnak tekinti.
5. **Publikációs tranzakcionalitás és védelem:**
   - `Storage::publish_full()` bevezetése a feed XML és a `catalog.jsonl` index szinkronban tartására.
   - Automatikus visszaállítás `.prev` biztonsági mentésből hiba esetén.
   - `Catalog_Diff` memória-benchmark: 10 000 terméknél ~4.0 MB csúcs memóriaigény, ami a WooCommerce 256 MB-os alapértelmezett korlátjának kevesebb mint 1.6%-a.

---

## 2. Szerződés-megfelelőségi mátrix összefoglaló

A részletes [PEPITA-XML-CONTRACT-MATRIX.md](file:///home/zsolt/Projects/woocommerce-pepita/docs/m9d/PEPITA-XML-CONTRACT-MATRIX.md) alapján:

| Kategória | Teljes feed (Full) | Készlet feed (Stock) | Megfelelőség |
| :--- | :--- | :--- | :--- |
| **Gyökérelem és névtér** | `<Catalog xmlns="https://pepita.hu/feed/1.0">` | `<Catalog xmlns="https://pepita.hu/feed/1.0">` | **100% PASS** |
| **Azonosítók** | `Id`, `StructuredId` (GTIN/EAN), `ProductNumber` (MPN) | `Id` | **100% PASS** |
| **Kézbesítés és Leírások** | `EmailDelivery`, `Descriptions/Name`, `Description`, `Brand`, `Manufacturer`, `Author`, `Publisher` | Nem szerepel a szerződésben | **100% PASS** |
| **Árak** | `Prices/Currency`, `Price`, `VatPercent`, `DiscountedPrice`, `PurchaseNetPrice`, `ShippingPrice` | `Prices/Currency`, `Price`, `DiscountedPrice` | **100% PASS** |
| **Elérhetőség és készlet** | `Availability/Available`, `Quantity`, `RestockDate`, `ShippingDelay` | `Availability/Available`, `Quantity`, `RestockDate`, `ShippingDelay` | **100% PASS** |
| **Garancia** | `Warranty/Type`, `Duration` | Nem szerepel a szerződésben | **100% PASS** |
| **Kategóriák és Képek** | `Categories/Category`, `Photos/Photo/Url`, `Primary`, `Title`, `PrimaryVariation`... | Nem szerepel a szerződésben | **100% PASS** |
| **Videók és Linkek** | `VideoLinks`, `ProductUrl` | Nem szerepel a szerződésben | **100% PASS** |
| **Attribútumok és Méretek** | `Attributes/Attribute`, `VolumeDimensions/Width`, `Height`, `Length`, `Weight` | Nem szerepel a szerződésben | **100% PASS** |
| **Variációk (1–3 tengely)** | `Variations/PrimaryAttribute`, `Variation/PrimaryVariation`, `Prices`, `Availability` | `Variations/Variation/Id`, `Prices`, `Availability` | **100% PASS** |

---

## 3. Variációs struktúra és 3-tengelyes architektúra

A Pepita XML specifikáció szigorúan legfeljebb **3 variációs tengelyt** engedélyez termékenként:
1. `PrimaryAttribute` &rarr; egyedi variációnként `PrimaryVariation`
2. `SecondaryAttribute` &rarr; egyedi variációnként `SecondaryVariation`
3. `TertiaryAttribute` &rarr; egyedi variációnként `TertiaryVariation`

### Leképezési szabályok
* **1 attribútum (pl. Szín):**  
  `<PrimaryAttribute>Szín</PrimaryAttribute>`  
  Variációk: `<PrimaryVariation>Fekete</PrimaryVariation>`.
* **2 attribútum (pl. Szín + Méret):**  
  `<PrimaryAttribute>Szín</PrimaryAttribute>`  
  `<SecondaryAttribute>Méret</SecondaryAttribute>`  
  Variációk: `<PrimaryVariation>Kék</PrimaryVariation><SecondaryVariation>XL</SecondaryVariation>`.
* **3 attribútum (pl. Szín + Méret + Anyag):**  
  Mindhárom tengely deklarálva a `<Variations>` fejlécében, és mindhárom érték szerepel az egyes `<Variation>` blokkokban.
* **4 vagy több attribútum elutasítása:**  
  Amennyiben egy WooCommerce variációs termék 4 vagy több tengelyt használ (pl. Szín, Méret, Anyag, Minta), a `Product_Mapper::extract_variations()` hibát rögzít (`Product exceeds Pepita maximum of 3 variation axes`), és a termék exportálása kimarad. Ez védi a partnert a piactéri feed import feldolgozási hibáktól és az inkonzisztens katalógusállapottól.

---

## 4. Készlet és Ár feed (Stock feed) szerződés

A Pepita hivatalos dokumentációja egyértelműen rögzíti:
> *"A stock feednek a teljes aktív termékkínálatot tartalmaznia kell. Nem használható delta feed, mert a feedben nem szereplő termékeket a rendszer automatikusan inaktívnak tekinti vagy törli."*

### Implementációs garanciák
1. A `wps_feed=stock` végpont a katalógus minden aktív (vásárolható vagy látható) termékét tartalmazza.
2. A készlet feed minimális adathalmazt használ (Id, Árak, Elérhetőség, Készlet, Beérkezési idő, valamint variációk esetén a variációs ID-k és azok készletei/árai).
3. Ezzel a hálózati sávszélesség és feldolgozási idő töredékére csökken a teljes termékfeedhez képest, miközben a Pepita törlési logikája nem aktiválódik tévesen.

---

## 5. Publikáció-biztonság és Memória-terhelés (Benchmark)

### Publikációs védelem (`Storage::publish_full`)
A korábbi verziókban a feed XML és a `catalog.jsonl` index fájl függetlenül került kiírásra. Hiba esetén a két fájl inkonzisztenssé válhatott volna.
Az M9-D-ben bevezetett `Storage::publish_full($feed_temp, $index_temp)`:
1. Ellenőrzi a tárhely könyvtár meglétét (`ensure_directory()`).
2. Biztonsági másolatot készít a jelenlegi éles fájlokról (`.prev`).
3. Elsőként az új `catalog.jsonl` fájlt mozgatja atomi módon a helyére.
4. Másodikként az új `feed.xml` fájlt publikálja.
5. Ha bármelyik lépés meghiúsul, automatikusan visszaállítja az indexet az előző működő állapotra, így a feed és az index soha nem kerül szétcsúszott állapotba.

### `Catalog_Diff` Memória Benchmark eredmények
A memória- és CPU-terhelést szintetikus katalógusokon mértük fel:

| Termékszám (N) | Futási idő | Csúcs memória többlet | Megjegyzés |
| :--- | :--- | :--- | :--- |
| **100** | 5.76 ms | 0 KB | Azonnali lefutás |
| **1 000** | 22.21 ms | 2 048 KB (~2.0 MB) | Bázis terhelés |
| **5 000** | 155.43 ms | 4 096 KB (~4.0 MB) | Nagyobb áruház méret |
| **10 000** | 225.45 ms | 4 096 KB (~4.0 MB) | Bounded streaming architektúra |

**Következtetés:** A memóriahasználat 10 000 termék esetén mindössze ~4 MB (a WordPress / WooCommerce 256 MB-os ajánlott memóriakorlátjának ~1.5%-a). A feed generálás és a diff számítás biztonságosan futtatható háttérfolyamatként anélkül, hogy OOM (Out Of Memory) hibát okozna.

---

## 6. Tesztelési és Reprodukálhatósági Bizonyítékok

1. **Egységtesztek:**
   - Összesen **219 teszt** fut le hibátlanul (`219 passed, 0 failed, 0 skipped`).
   - Új tesztfájlok:
     * `tests/cases/180-xml-contract-compliance-test.php`: 7 teszteset (egyszerű, 1-3 tengelyes variáció, 4. tengely elutasítás, magyar ékezetek `áéíóöőúüű`, hiányzó GTIN kihagyás, készlet feed felépítés, hivatalos Pepita minta XML validáció).
     * `tests/cases/185-feed-publication-safety-test.php`: 4 hibabefecskendezéses és integritás teszt (atomi publikálás, üres fájlok elutasítása, feed hiba esetén visszagörgetés, stock feed izoláció).
2. **Integrációs tesztkörnyezet:**
   - Docker Compose: PHP 8.5 NTS, MySQL 8.4, WordPress 7.1.4, WooCommerce 11.3.0.
   - HPOS és Legacy környezetben futó integrációs tesztek: 142/142 PASS.
3. **Determinisztikus csomagkészítés (`bin/verify-reproducible.sh`):**
   - 5 tiszta build futtatva független könyvtárakban és különböző időzónákban (UTC, Europe/Budapest, America/Los_Angeles, Asia/Tokyo).
   - Mind az 5 csomag SHA-256 lenyomata szigorúan azonos:
     `7bf25f26324103e9ca668f581ce8f20ea66e8f06483d99975f79e39a44e14083`.

---

## 7. Fennmaradó Kockázatok és Partner Onboarding Ellenőrzőlista

### Fennmaradó kockázatok (Residual Risks)
1. **Érvénytelen kategória azonosítók:** A Pepita a kategóriákat saját kategóriafájában tartja nyilván. Bár a WooCommerce kategóriák XML-be kerülnek, a végleges piactéri megjelenéshez szükséges lehet a Pepita hivatalos kategória-leképezésének (category mapping) beállítása.
2. **Képek elérhetősége:** A Pepita botjai külső HTTP/HTTPS eléréssel töltik le a képeket. Ha a staging szerver jelszóval védett (Basic Auth) vagy tűzfal mögött van, a képletöltés meghiúsulhat.
3. **Pénznem:** A Pepita alapértelmezetten HUF devizát kezel. Többdevizás áruház esetén a feed lekérdezésekor a pénznem konzisztenciája biztosítandó.

### Partner Onboarding Ellenőrzőlista (Későbbi élesítéshez)
- [ ] Staging környezetben éleshez hasonló termékadatok tesztelése.
- [ ] Pepita Seller Center regisztráció és API kulcs igénylés.
- [ ] Pepita fiókban a Full Feed URL (`https://bolt.hu/?wps_feed=full&token=...`) és a Stock Feed URL (`https://bolt.hu/?wps_feed=stock&token=...`) megadása.
- [ ] Pepita manuális feed ellenőrző futtatása a Seller Center felületén.
- [ ] Rendelés-szinkronizációs webhook / API végpont (`/wp-json/wps-pepita/v1/orders`) regisztrálása a Pepita felületén a kiadott API kulccsal és IP engedélyezési listával.
