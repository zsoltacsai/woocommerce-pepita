# WP Pepita Sync — M9-D Béta Feed-Only Átadási Dokumentáció (Handoff)

**Dátum:** 2026-11-13  
**Céldátum:** 2026-11-16 (Hétfő)  
**Kiadás / Célállapot:** Beta Feed-Only Release Candidate (M9-D)  
**Státusz:** VERIFIED BETA READY — STAGING EXECUTION ONLY  
**Biztonsági kapuk:**  
- **Éles üzem (Production):** **SZIGORÚ NO-GO**  
- **Pepita Rendelés API:** **KIKAPCSOLVA (HTTP 503 Short-circuit)**  
- **Kimenő Pepita API hívások:** **STRICT HOLD (0 hívás engedélyezett)**  
- **Külső staging telepítés:** **Tulajdonosi jóváhagyáshoz kötött (Külön GO szükséges)**  

---

## 1. Vezetői összefoglaló (Executive Summary)

A jelen dokumentáció a **WP Pepita Sync** WooCommerce bővítmény béta feed-only kiadásának üzemeltetési és üzleti átadási leírása. A kiadás célja, hogy a Pepita értékesítési kapcsolattartó számára két stabil, HTTPS alapú XML feed URL-t biztosítson:

1. **Teljes termékkatalógus feed (Full product XML feed)**: `pepita-full`
2. **Teljes készlet- és árfeed (Complete stock/price XML feed)**: `pepita-stock`

A béta kiadás kizárólag a kereskedő által manuálisan engedélyezett WooCommerce kategóriák termékeit exportálja (Hard Category Allowlist). A kétirányú rendelési integráció a béta fázisban hardveresen és logikailag le van tiltva, megvédve a kereskedő éles WooCommerce boltját minden nem szándékolt rendelésmódosítástól, készletmozgástól vagy számlázási eseménytől.

---

## 2. Biztonsági és izolációs garanciák

A béta fázis alatt a bővítmény a következő védelmi vonalakkal működik:

1. **Rendelés API azonnali tiltás (HTTP 503):**  
   A `POST /wp-json/wps/v1/pepita/order` végpont azonnali `503 Service Unavailable` választ ad:
   ```json
   {
     "isError": true,
     "responseCode": 503,
     "message": "A Pepita rendelésfogadás a béta időszakban ki van kapcsolva (feed-only béta)."
   }
   ```
   A kérés payloadját a rendszer nem dolgozza fel, nem hoz létre WooCommerce rendelést, nem aktivál készletcsökkentést és nem futtat fizetési vagy szállítási hookokat.
2. **Kimenő hálózati tiltás:**  
   A bővítményből nulla hívás indul a Pepita külső szerverei felé.
3. **Titkosított feed-hozzáférés:**  
   A feedek nem publikusak (a béta üzemmód felülbírálja és letiltja a nyilvános elérést). A hozzáféréshez 64 bites kriptográfiai token szükséges.

---

## 3. Béta Környezet és Architektúra

A béta feedek ellenőrzése izolált környezetben történt:
- **Környezet:** Független Docker tárolók (PHP 8.5, MySQL 8.4, WordPress 7.1.4, WooCommerce 11.3.0).
- **HPOS és Hagyományos tárolás:** Mindkét tárolási modellben (High-Performance Order Storage aktív és inaktív) automatizált integrációs tesztek igazolják a működést (83/83 teszt sikeres HPOS mellett, 83/83 teszt sikeres legacy mellett).
- **Adatbázis izoláció:** Kizárólag szintetikus tesztadatok kerültek felhasználásra. Éles ügyféladat vagy éles rendelés a rendszerben nem létezik.

---

## 4. Feed URL-ek és Hitelesítés

A Pepita kapcsolattartó számára átadható URL-ek formátuma:

### A) Teljes termék XML feed (Full Product Feed)
```
https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>
```
*Vagy REST útvonalon:*
```
https://<STAGING_DOMAIN>/wp-json/wps/v1/pepita/feed/full?token=<FEED_SECRET_TOKEN>
```

### B) Teljes készlet- és árfeed (Stock & Price Feed)
```
https://<STAGING_DOMAIN>/?wps_feed=stock&token=<FEED_SECRET_TOKEN>
```
*Vagy REST útvonalon:*
```
https://<STAGING_DOMAIN>/wp-json/wps/v1/pepita/feed/stock?token=<FEED_SECRET_TOKEN>
```

### Hitelesítési opciók:
1. **URL paraméter:** `token=<FEED_SECRET_TOKEN>` (a fenti példa szerint).
2. **HTTP kérés fejléc (ajánlott automatizált lehívóknak):**  
   `X-WPS-Feed-Token: <FEED_SECRET_TOKEN>`  
   Ebben az esetben az URL tisztán maradhat: `https://<STAGING_DOMAIN>/?wps_feed=full`.

> [!CAUTION]
> A `<FEED_SECRET_TOKEN>` valós értékét soha ne tegye közzé publikus felületeken, nyílt Pull Request leírásokban vagy nyilvános issue-kban. A tokent biztonságos csatornán (pl. jelszókezelőn vagy belső üzenetben) kell eljuttatni a partnerhez.

---

## 5. Kategória Engedélyezési Lista (Hard Allowlist)

A béta időszak alatt a termékek exportálását egy szigorú kategória engedélyezési lista szabályozza:

- **Alapértelmezett szabály:** Minden termék kizárt (`beta_unapproved_category`), amíg a kereskedő kifejezetten nem engedélyezi a kategóriáját.
- **Kategória hozzárendelés hiánya:** A kategória nélküli (uncategorized/árva) termékek soha nem kerülnek exportálásra.
- **Termékszintű beállítás nem bírálhatja felül a kategória tiltást:** Ha egy terméknél be van jelölve a közvetlen felülbírálás (`_wps_include = 'include'`), az kizárólag akkor lép életbe, ha a termék kategóriája is az engedélyezési listán szerepel.
- **Átfedő kategóriák (Overlap conflict):** Ha egy termék több kategóriába is be van sorolva, és ezek közül legalább az egyik tiltott (`exclude`), a tiltás azonnal felülbírálja az engedélyezést, és a termék kimarad a feedből.
- **Gyermek-kategóriák öröklődése:** A szülő kategóriánál beállított `wps_include_children` határozza meg, hogy az alkategóriák automatikusan engedélyezettek-e. Ha a jelölőnégyzet nincs bepipálva, az alkategória termékei kizárásra kerülnek.
- **Üres kategóriakészlet védelme:** Ha a kereskedő véletlenül minden kategória engedélyezését törli, a feed generáló azonnal leáll hibával, és a korábban publikált stabil feed érintetlen marad (nem publikál üres feedet).

---

## 6. Hivatalos Pepita XML Megfelelőség (Contract Compliance)

Mindkét feed maradéktalanul teljesíti a Pepita hivatalos specifikációját:
- **Gyökérelem és névtér:** `<Catalog xmlns="https://pepita.hu/feed/1.0">`
- **Azonosítók:** Egyedi, stabil belső `Id`, termékszintű `StructuredId` (GTIN/EAN) és `ProductNumber` (cikkszám).
- **Készletfeed jellege:** A Pepita specifikációjának megfelelően a készletfeed **nem delta feed**, hanem a teljes jóváhagyott katalógus állapotát tükrözi. A kimaradó termékeket a piactér inaktívnak tekinti, így a feed garantálja a konzisztenciát.
- **Karakterkódolás és XML biztonság:** UTF-8 kódolás, XML tiltott vezérlőkarakterek és érvénytelen Unicode tartományok automatikus tisztítása.

---

## 7. Variációs Architektúra (1–3 Tengely)

A Pepita legfeljebb 3 variációs dimenziót engedélyez termékenként:
- **1 tengely:** `PrimaryAttribute` (pl. Szín) &rarr; variációknál `PrimaryVariation`.
- **2 tengely:** `PrimaryAttribute` + `SecondaryAttribute` (pl. Szín + Méret).
- **3 tengely:** `PrimaryAttribute` + `SecondaryAttribute` + `TertiaryAttribute`.
- **Variációs árak és készletek:** Minden variáció egyedi `<Id>`, `<Prices>` (Ár, Áfa, Akciós ár) és `<Availability>` blokkot kap.
- **4. tengely kizárása (Fail-closed):** 3-nál több attribútumtengellyel rendelkező variációs termék exportja azonnal megtagadva, figyelmeztető hibanapló rögzítésével, elkerülve a piactéri partner oldali import hibákat.

---

## 8. Szállítási és Beszerzési Források

- **Szállítási késleltetés (ShippingDelay):** Kategória és termékszinten konfigurálható munkanapokban megadott szállítási idő (alapértelmezett: 1 munkanap).
- **Szállítási díjak (ShippingPrice):** Termékenként vagy kategóriánként hozzárendelt egyedi szállítási felár a `<Prices>` blokkban.
- **Digitális kézbesítés:** Virtuális termékek esetén automatikus `<EmailDelivery>true</EmailDelivery>` mező generálás.

---

## 9. Összeomlás-biztos Publikáció (Crash-Consistency)

A termékfeed XML és a `catalog.jsonl` index szinkronitását egy atomi pointer architektúra (`active-generation.json`) biztosítja:

1. **Generáció azonosító:** Minden generálás egyedi időbélyeggel és véletlen azonosítóval ellátott fájlokba ír:
   - `pepita-full.<GENERATION_ID>.xml`
   - `catalog.<GENERATION_ID>.jsonl`
   - `pepita-stock.<GENERATION_ID>.xml`
2. **Atomi átváltás (Pointer Swap):** A fájlok előkészítése és ellenőrzése után a rendszer egyetlen kernel-szintű atomi fájlátnevezéssel (`rename`) váltja át az aktív mutatót.
3. **Félbeszakadás elleni védelem:** Ha a szerver vagy a PHP folyamat a fájlírás közben leáll (SIGKILL, memóriahiány), az aktív mutató a korábbi stabil generációra mutat, így az ügyfél soha nem kap csonka vagy sérült feedet.

---

## 10. Visszaállítási Eljárás (Rollback Procedure)

Ha egy publikált feedben váratlan adateltérés merül fel:

1. **Azonnali visszaállítás az előző generációra:**  
   Az `active-generation.json` fájlban a `previous` blokk tartalmazza az előző működő fájlneveket. A mutató visszaállítása a korábbi állapotra azonnal helyreállítja az előző feed kiszolgálását:
   ```json
   {
     "version": 1,
     "active": {
       "generation_id": "<PREVIOUS_GEN_ID>",
       "full_feed": "pepita-full.<PREVIOUS_GEN_ID>.xml",
       "catalog_index": "catalog.<PREVIOUS_GEN_ID>.jsonl",
       "stock_feed": "pepita-stock.<PREVIOUS_GEN_ID>.xml"
     }
   }
   ```
2. **Kényszerített újragenerálás:**  
   A WooCommerce adminisztrációs felületén a **Pepita Sync &rarr; Feedek** menüpontban a „Teljes generálás futtatása most” gombbal bármikor manuális generálás indítható.
3. **Katasztrófa utáni fallback:**  
   A rendszer támogatja a legacy fájlokat (`pepita-full.xml`, `pepita-stock.xml`) arra az esetre is, ha a pointer fájl megsérülne.

---

## 11. Útmutató a Pepita Értékesítési Kapcsolattartó Részére

A Pepita kapcsolattartó felé továbbítandó tájékoztató szövegjavaslat:

> **Kedves Pepita Partnercsapat!**  
>  
> Elkészítettük és teszteltük a WooCommerce áruházunk hivatalos Pepita XML feedjeit a béta integrációhoz. A feedek maradéktalanul illeszkednek a Pepita XML 1.0 specifikációjához.  
>  
> Kérjük, állítsák be a Pepita Seller Centerben a következő két stabil végpontot:  
>  
> 1. **Teljes termékkatalógus feed (Product feed):**  
>    `https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>`  
>  
> 2. **Készlet- és árfeed (Stock & price feed):**  
>    `https://<STAGING_DOMAIN>/?wps_feed=stock&token=<FEED_SECRET_TOKEN>`  
>  
> **Fontos tudnivalók:**  
> - A feedek HTTP Basic és URL Token hitelesítést is támogatnak (`token` paraméter, vagy `X-WPS-Feed-Token` HTTP fejléc).  
> - A készletfeed a teljes jóváhagyott katalógus készlet- és árváltozásait tartalmazza, igazodva a Pepita teljes állapot-szinkronizációs elvárásához.  
> - A béta időszak alatt a rendelési API végpont még inaktív, a megrendelések kezelése a tesztidőszak későbbi fázisában indul.  
>  
> Kérjük visszajelzésüket az első sikeres katalógus-beolvasást követően!

---

## 12. Átadási Nyilatkozat és Tulajdonosi Kapu (Sign-off)

A bővítmény jelen béta állapota a belső izolált tesztek és az integrációs ellenőrzések alapján **alkalmas** a partneri feed tesztelésre.

> [!IMPORTANT]
> **TULAJDONOSI JÓVÁHAGYÁSI KORLÁTOZÁS:**  
> A feedek éles (live) felvétele a Pepita Seller Centerbe, valamint a kód telepítése külső staging szerverre kizárólag a **Tulajdonos kifejezett, írásbeli telepítési engedélye (Host-install GO)** birtokában végezhető el. Addig a rendszer izolált belső állapotban marad.
