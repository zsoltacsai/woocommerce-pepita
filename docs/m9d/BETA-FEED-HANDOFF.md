# WP Pepita Sync — M9-D Béta Feed-Only Átadási Dokumentáció (Handoff)

**Dátum:** 2026-11-13  
**Céldátum:** 2026-11-16 (Hétfő)  
**Kiadás / Célállapot:** Beta Feed-Only Release Candidate (M9-D)  
**Státusz:** VERIFIED BETA READY — DEPLOYMENT HOLD (Tulajdonosi jóváhagyásra vár)  
**Biztonsági kapuk:**  
- **Éles üzem (Production):** **SZIGORÚ NO-GO**  
- **Pepita Rendelés API:** **LEZÁRVA (HTTP 503 Short-circuit, mellékhatások nélkül)**  
- **Kimenő Pepita API hívások:** **STRICT HOLD (0 hívás engedélyezett)**  
- **Külső staging telepítés:** **HOLD (Külön tulajdonosi engedély szükséges; WP CareGrid infrastruktúrára NEM telepíthető)**  

---

## 1. Vezetői összefoglaló (Executive Summary)

A jelen dokumentáció a **WP Pepita Sync** WooCommerce bővítmény béta feed-only kiadásának üzemeltetési és üzleti átadási leírása. A kiadás célja, hogy a Pepita értékesítési kapcsolattartó számára két stabil, HTTPS alapú XML feed URL-t biztosítson:

1. **Teljes termékkatalógus feed (Full product XML feed)**: `wps_feed=full`
2. **Teljes készlet- és árfeed (Complete stock/price XML feed)**: `wps_feed=stock`

A béta kiadás kizárólag a kereskedő által manuálisan engedélyezett WooCommerce kategóriák termékeit exportálja (Hard Category Allowlist). A kétirányú rendelési integráció a béta fázisban hardveresen és logikailag le van tiltva (HTTP 503), megvédve a kereskedő éles WooCommerce boltját minden nem szándékolt rendelésmódosítástól, készletmozgástól vagy számlázási eseménytől.

---

## 2. Biztonsági és izolációs garanciák

A béta fázis alatt a bővítmény a következő védelmi szintekkel garantálja az áruház sértetlenségét:

1. **Rendelés API azonnali lezárása (HTTP 503):**  
   A `POST /wp-json/wps/v1/pepita/order` végpont a kérés beérkezésekor, minden autentikációt, IP-ellenőrzést és JSON-feldolgozást megelőzően azonnali `HTTP 503 Service Unavailable` választ ad:
   ```json
   {
     "isError": true,
     "responseCode": 503,
     "message": "A Pepita rendelésfogadás a béta időszakban ki van kapcsolva (feed-only béta)."
   }
   ```
   A kérés payloadját a rendszer nem dolgozza fel, nem hoz létre WooCommerce rendelést, nem aktivál készletcsökkentést és nem futtat fizetési vagy szállítási hookokat.
2. **Véletlen rendelésfogadás-visszakapcsolás elleni védelem:**  
   Béta üzemmódban az `order_api_enabled` beállítás nem engedélyezhető (mentéskor és lekérdezéskor a rendszer kényszerített `false` értékre írja felül). A béta mód esetleges kikapcsolása sem aktiválja automatikusan a rendelésfogadást: az inaktív marad, amíg kifejezetten és szándékosan nem engedélyezik.
3. **Kimenő hálózati kapcsolatok tiltása:**  
   A bővítményből nulla hívás indul a Pepita külső API-jai felé.
4. **Biztonságos feed-hozzáférés (Token-alapú hitelesítés):**  
   A feedek nem nyilvánosak (`feed_public = false`). A hozzáféréshez kriptográfiai véletlen token szükséges.

---

## 3. Béta Környezet és Verifikáció

- **Környezet:** A helyi számítógépen a Docker futtatása nem megengedett. Minden teljes integrációs teszt és ellenőrzés a felhőalapú GitHub Actions CI környezetben futott le.
- **HPOS és Hagyományos tárolás:** Mindkét tárolási modellben (WooCommerce High-Performance Order Storage aktív és inaktív) automatizált integrációs tesztek igazolják a működést.
- **Adatbázis izoláció:** Kizárólag szintetikus tesztadatok kerültek felhasználásra. Valós ügyféladat, éles rendelés vagy éles hitelesítési adat nem került expozícióra.

---

## 4. Ellenőrzött Feed URL-ek és Hitelesítés

A Pepita kapcsolattartó számára átadható ellenőrzött végpontok formátuma:

### A) Teljes termék XML feed (Full Product Feed)
```
https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>
```

### B) Teljes készlet- és árfeed (Stock & Price Feed)
```
https://<STAGING_DOMAIN>/?wps_feed=stock&token=<FEED_SECRET_TOKEN>
```

*(Nem dokumentált vagy nem tesztelt REST útvonalak használata tilos és nem támogatott.)*

### Hitelesítési módok:
1. **URL lekérdezési paraméter:**  
   `?token=<FEED_SECRET_TOKEN>` (a fenti URL-ek szerint).
2. **HTTP kérés fejléc:**  
   `X-WPS-Feed-Token: <FEED_SECRET_TOKEN>`  
   Ebben az esetben a paraméter elhagyható a tiszta URL-ből: `https://<STAGING_DOMAIN>/?wps_feed=full`.

> [!CAUTION]
> A `<FEED_SECRET_TOKEN>` valós értékét soha ne tárolja vagy tegye közzé a Git verziókezelőben vagy nyilvános hibajegyekben. A tokent biztonságos csatornán (pl. jelszókezelőn keresztül) kell átadni a partnernek.

---

## 5. Kategória Engedélyezési Lista (Hard Category Allowlist)

A béta fázisban a feedbe kizárólag a kereskedő által jóváhagyott kategóriák termékei kerülhetnek:

- **Alapértelmezett szabály:** Minden termék alapértelmezetten kizárt (`beta_unapproved_category`), amíg a kereskedő kifejezetten nem hagyja jóvá a kategóriáját az admin felületen (`wps_include = 'include'`).
- **Besorolatlan termékek:** Kategória nélküli (uncategorized vagy árva) termékek sosem kerülnek a feedbe.
- **Kategória kizárás elsőbbsége:** Ha egy termék több kategóriába tartozik, és azok közül akár egy is kizárt (`exclude`), a kizárás felülbírálja a jóváhagyást (overlap exclusion), és a termék kimarad a feedből.
- **Termékszintű beállítás nem bírálhatja felül a kategóriát:** A terméklapon megadott egyedi beállítás (`_wps_include = 'include'`) sem vihet be olyan terméket, amelynek kategóriája nincs jóváhagyva vagy kizárt.
- **Alkategóriák öröklődése:** A főkategóriánál lévő `wps_include_children` jelölőnégyzet határozza meg, hogy a gyerek-kategóriák automatikusan jóváhagyottak-e.
- **Fail-closed védelem:** Ha egyetlen jóváhagyott kategória sincs (`count === 0`), a feed-generálás azonnal biztonsági leállást hajt végre, megelőzve az üres feed véletlen publikálását.
- **Konzisztens termékkör:** A teljes feed és a készletfeed pontosan ugyanazon a jóváhagyott termékkörön dolgozik.

---

## 6. Hivatalos Pepita XML Megfelelőség

- **Gyökérelem és névtér:** `<Catalog xmlns="https://pepita.hu/feed/1.0">`
- **Kötelező és egyedi azonosítók:** Egyedi belső `<Id>`, termékszintű cikkszám (`<ProductNumber>`) és vonalkód/GTIN (`<StructuredId>`).
- **Készletfeed jellege:** A Pepita specifikációjának megfelelően a készletfeed **nem differenciális (delta) feed**, hanem a teljes jóváhagyott katalógus készlet- és árstátuszát tartalmazza.
- **Karakterkódolás:** Szabványos UTF-8, XML vezérlőkarakterek automatikus szűrésével és entitás-védelemmel.

---

## 7. Variációs Architektúra (1–3 Tengely)

- **Legfeljebb 3 dimenzió:** A Pepita XML szabvány maximum 3 variációs tengelyt engedélyez:
  - 1 tengely: `PrimaryAttribute` (pl. Méret).
  - 2 tengely: `PrimaryAttribute` + `SecondaryAttribute` (pl. Szín + Méret).
  - 3 tengely: `PrimaryAttribute` + `SecondaryAttribute` + `TertiaryAttribute`.
- **Variációk szerkezete:** A variációk különálló `<Product>` elemként jelennek meg a feedben, szülő-hivatkozással, egyedi SKU-val és a variációs attribútumokkal kiegészített névvel.
- **Árak és készletek:** Minden variáció saját árat, akciós árat és készletet kap. A 0 készletű variációk automatikusan kiszűrésre kerülnek.
- **4. tengely kizárása:** A 3-nál több tengellyel rendelkező termékek automatikusan kimaradnak a feedből a partneri import hibák elkerülése érdekében.

---

## 8. Szállítási Adatok és Költségek

- **Szállítási határidő (ShippingDelay):** Termékszintű metaadatból vagy a globális beállításból származik munkanapokban kifejezve (alapértelmezett: 2 munkanap).
- **Szállítási költség (ShippingPrice):** A WooCommerce szállítási zónákból, átalánydíjakból vagy termékszintű felárból képezve az XML `<Prices>` blokkban.
- **Virtuális / digitális termékek:** Automatikusan `<EmailDelivery>true</EmailDelivery>` jelölést kapnak.

---

## 9. Összeomlás-biztos Publikáció (Crash-Consistency) és Készletintegritás

A feedek és az index konzisztenciáját az atomi pointer architektúra (`active-generation.json`) biztosítja:

1. **Egyedi és megváltoztathatatlan (immutable) fájlnevek:**
   - Minden teljes generálás új azonosítót kap: `pepita-full.<GEN_ID>.xml` és `catalog.<GEN_ID>.jsonl`.
   - Minden sikeres készletgenerálás saját, egyedi fájlnévvel jön létre: `pepita-stock.<STOCK_GEN_ID>.xml`. Az ismételt készletfrissítések nem írják felül az aktív fájlt a publikáció előtt.
2. **Atomi átváltás (Pointer Swap):** A fájl teljes kiírása és méretellenőrzése után a rendszer atomi `rename` művelettel lépteti életbe az új generációt.
3. **Párhuzamossági védelem (Concurrency Lock):** Az `active-generation.lock` zárolás megelőzi a full-feed és a stock-feed generálások közötti ütközést. A full-feed publikáció megőrzi az érvényben lévő készletfeedet, a készletfeed pedig megőrzi az érvényben lévő katalógusindexet.
4. **Előző verziók megőrzése és szemétgyűjtés:** A rendszer megőrzi a közvetlenül előző generációkat (`previous.full_feed`, `previous.stock_feed`), a régebbi, elavult generációkat pedig biztonságosan törli.

---

## 10. Vészhelyzeti Leállítás és Visszaállítás (Emergency Kill-Switch & Rollback)

### A) Különálló vészhelyzeti feed-leállítás (Emergency Feed Disable)
Ha a feedek kiszolgálását azonnal fel kell függeszteni:
- **Admin felületen:** A **Pepita beállítások &rarr; Vészhelyzeti feed-leállítás** jelölőnégyzet bekapcsolásával.
- **wp-config.php állandóval:** `define( 'WPS_PEPITA_FEED_EMERGENCY_DISABLED', true );`
- **Hatása:** A végpont azonnal `HTTP 503 Service Unavailable` és `Retry-After: 300` választ ad minden kérésre.
- **Kritikus szabály:** **Ne a béta üzemmód kikapcsolásával állítsa le a feedeket!** A vészhelyzeti leállító független kapcsoló, amely nem érinti a rendelésfogadás tiltását.

### B) Token azonnali érvénytelenítése
- Az adminisztrációs felületen a feed token újragenerálható, ami azonnal elvágja a partneri hozzáférést a régi címen.

### C) Szigorú hibakezelés (Fail-Closed Recovery)
- Ha az aktív mutató fájl (`active-generation.json`) megsérül vagy az általa hivatkozott fájl törlődik, a rendszer **nem hull vissza elavult kompatibilitási fájlokra**, hanem azonnal biztonsági `HTTP 503 Service Unavailable` hibával válaszol.
- Korábbi hagyományos (unversioned) telepítésről való áttérésre a dedikált, ellenőrzött migrációs folyamat (`Storage::migrate_legacy_to_versioned()`) szolgál.

---

## 11. Útmutató a Pepita Értékesítési Kapcsolattartó Részére

A partner felé továbbítandó hivatalos tájékoztató szöveg:

> **Kedves Pepita Partnercsapat!**  
>  
> Elkészítettük a WooCommerce áruházunk hivatalos Pepita XML feedjeit a béta integrációhoz a jóváhagyott termékkategóriákra.  
>  
> Kérjük, állítsák be a Pepita Seller Centerben a következő két stabil végpontot:  
>  
> 1. **Teljes termékkatalógus feed (Product feed):**  
>    `https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>`  
>  
> 2. **Készlet- és árfeed (Stock & price feed):**  
>    `https://<STAGING_DOMAIN>/?wps_feed=stock&token=<FEED_SECRET_TOKEN>`  
>  
> **Fontos technikai információk:**  
> - A végpontok URL query paraméterben (`token`) és HTTP kérés fejlécben (`X-WPS-Feed-Token`) is fogadják a hitelesítést.  
> - A készletfeed a Pepita specifikációjának megfelelően a teljes jóváhagyott katalógus készlet- és árstátuszát tartalmazza.  
> - A feedek HTTP ETag és 304 Not Modified támogatással rendelkeznek az optimális hálózati sávszélesség érdekében.  
> - A béta fázisban a megrendelések fogadása (Order API) még inaktív, kizárólag a katalógus- és készletszinkronizáció tesztelése zajlik.  
>  
> Kérjük szíves visszajelzésüket az első sikeres katalógus-beolvasást követően!

---

## 12. Tulajdonosi Jóváhagyási Kapu és Ajánlás (Go / Hold Gate)

- **Ajánlás:** **BETA FEED: GO** (A feed-kiszolgálás kódja és integritása ellenőrzött, hibátlan).
- **Telepítési korlát:** **DEPLOYMENT: HOLD**  
  Külső HTTPS staging host jelenleg még nem áll rendelkezésre.
- **Szabályzat:**  
  A feed URL-ek élesben történő megosztása és a kód külső szerverre telepítése kizárólag a Tulajdonos külön írásbeli engedélyével végezhető el. WP CareGrid infrastruktúrára telepíteni tilos.
