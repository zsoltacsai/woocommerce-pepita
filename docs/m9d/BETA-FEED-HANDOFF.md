# WP Pepita Sync — M9-D Béta Feed-Only Átadási Dokumentáció (Handoff)

**Dátum:** 2026-11-13  
**Céldátum:** 2026-11-16 (Hétfő)  
**Kiadás / Célállapot:** Beta Feed-Only Release Candidate (M9-D)  
**Státusz:** VERIFIED BETA READY — DEPLOYMENT HOLD (Külön tulajdonosi jóváhagyásra vár)  
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

## 3. Béta Környezeti és Rendszerkövetelmények (Host-Independent Prerequisites)

A bővítmény bármilyen szabványos Linux/Unix alapú WordPress tárhelyen futtatható az alábbi minimális feltételek mellett:

| Komponens | Minimális követelmény | Ajánlott verzió | Megjegyzés |
|---|---|---|---|
| **PHP** | 8.1+ | 8.2 – 8.5 | `ext-dom`, `ext-xml`, `ext-json`, `ext-hash` szükséges. |
| **WordPress** | 6.4+ | 6.6+ | WP-Cron vagy rendszer-cron futtatás támogatása. |
| **WooCommerce** | 8.2+ | 9.x | Mind a Legacy, mind a HPOS tárolás támogatott. |
| **HTTPS / TLS** | TLS 1.2+ | TLS 1.3 | Érvényes, publikusan megbízható tanúsítvány (Pepita robot crawler). |
| **Képek elérhetősége** | Publikus URL | Közvetlen elérés | A termékképek URL-jei nem lehetnek Basic Auth, Captcha vagy bot-védelem mögött. |
| **Fájlrendszer** | Írható `wp-content/uploads` | SSD / NVMe | Privát sózott mappa létrehozása atomi zárolással. |

> [!IMPORTANT]
> **Telepítési tilalom:** A WP CareGrid infrastruktúrára történő telepítés szigorúan tiltott. A külső béta környezet kijelölése és jóváhagyása kizárólag a Tulajdonos hatásköre (DEPLOYMENT HOLD).

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

### Kategória jóváhagyás beállítási lépései:
1. Navigáljon a WordPress adminisztrációban a **Termékek &rarr; Kategóriák** menüpontba.
2. Kattintson a szinkronizálni kívánt kategória **Szerkesztés** linkjére.
3. Keresse meg a **Pepita szinkronizáció** szekciót.
4. Állítsa a beállítást **Engedélyezve (include)** értékre.
5. Szükség esetén jelölje be az **Összes alkategóriára érvényes** opciót.
6. Kattintson a **Frissítés** gombra. A rendszer azonnal kiüríti a feloldási gyorsítótárat (`Product_Selection::clear_resolution_cache()`).

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
3. **Párhuzamossági védelem és zárolás (Fail-Closed Concurrency Lock):**
   - Az `active-generation.lock` fájl védi a pointer és az állományok frissítését.
   - Ha a zárfájl megnyitása (`fopen`) vagy zárolása (`flock(LOCK_EX)`) meghiúsul, a publikáció azonnal leáll (`return false`), az ideiglenes fájlok törlésre kerülnek, és a mutató érintetlen marad.
4. **Teljes és Készletfeed Invariáns (Full/Stock Catalog Consistency):**
   - Mindkét publikált feed kizárólag ugyanazt a termék- és variációs identitást írhatja le (`catalog_signature === stock_signature`).
   - Ha a termékkör megváltozik egy teljes feed generálás során, az aktív készletfeed azonnal érvénytelenítésre kerül (HTTP 503 fail-closed), amíg az új katalógushoz illeszkedő készletfeed le nem fut.
   - Ha a termékkör identitása nem változott (pl. csak leírás vagy ár módosult), az érvényben lévő készletfeed aktív marad.
5. **Előző verziók megőrzése és szemétgyűjtés:** A rendszer megőrzi a közvetlenül előző generációkat (`previous.full_feed`, `previous.stock_feed`), a régebbi, elavult generációkat pedig biztonságosan törli.

---

## 10. Időzített Generálás (Cron Ütemezés)

A feedek háttérben történő frissítéséhez ajánlott rendszer-szintű Linux cront használni a standard látogatói WP-Cron helyett:

```bash
# Rendszer-szintű WP-CLI cron futtatás percenként:
* * * * * cd /var/www/html && wp cron event run --due-now >/dev/null 2>&1
```

A bővítmény alapértelmezett ütemezése:
- **Teljes termékkatalógus feed (`full`):** Napi 1 alkalommal (pl. éjjel 02:00-kor).
- **Készlet- és árfeed (`stock`):** Óránként vagy 30 percenként.

---

## 11. Üzembe Helyezési Ellenőrző Parancsok (Verification Runbook)

A staging szerveren az alábbi `curl` parancsokkal ellenőrizhető a helyes működés:

### 1. Sikeres feed letöltés (HTTP 200 OK + ETag generálás):
```bash
curl -i -s "https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>" | head -n 25
```
*Várt eredmény:* `HTTP/1.1 200 OK`, `Content-Type: text/xml; charset=UTF-8`, `ETag: "..."`, `Content-Disposition: inline; filename="pepita-full.xml"`.

### 2. Sávszélesség-takarékos gyorsítótár (HTTP 304 Not Modified):
```bash
curl -i -s -H 'If-None-Match: "<KORABBI_ETAG>"' "https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>"
```
*Várt eredmény:* `HTTP/1.1 304 Not Modified`.

### 3. Header-alapú hitelesítés ellenőrzése:
```bash
curl -i -s -H "X-WPS-Feed-Token: <FEED_SECRET_TOKEN>" "https://<STAGING_DOMAIN>/?wps_feed=stock" | head -n 25
```
*Várt eredmény:* `HTTP/1.1 200 OK`.

### 4. Illetéktelen hozzáférés megtagadása (HTTP 403 Forbidden):
```bash
curl -i -s "https://<STAGING_DOMAIN>/?wps_feed=full&token=invalid-token"
```
*Várt eredmény:* `HTTP/1.1 403 Forbidden`, hibaüzenet: `Érvénytelen vagy hiányzó token.`

### 5. Vészhelyzeti leállítás tesztelése (HTTP 503 Service Unavailable):
Aktivált `WPS_PEPITA_FEED_EMERGENCY_DISABLED` esetén:
```bash
curl -i -s "https://<STAGING_DOMAIN>/?wps_feed=full&token=<FEED_SECRET_TOKEN>"
```
*Várt eredmény:* `HTTP/1.1 503 Service Unavailable`, `Retry-After: 300`.

### 6. Rendelés API lezártság ellenőrzése (HTTP 503 Short-Circuit):
```bash
curl -i -s -X POST -H "Content-Type: application/json" -H "X-WPS-Api-Key: teszt" \
  -d '{"id":"TEST-1","origin":"pepita.hu"}' \
  "https://<STAGING_DOMAIN>/wp-json/wps/v1/pepita/order"
```
*Várt eredmény:* `HTTP/1.1 503 Service Unavailable`, válasz: `A Pepita rendelésfogadás a béta időszakban ki van kapcsolva (feed-only béta).` Nulla rendelés jön létre a WooCommerce-ben.

---

## 12. Vészhelyzeti Eljárások és Visszaállítás (Rollback & Incident Playbook)

### A) Vészhelyzeti azonnali feed-leállítás (Emergency Feed Disable)
Ha a feedek kiszolgálását azonnal fel kell függeszteni:
- **Admin felületen:** A **Pepita beállítások &rarr; Vészhelyzeti feed-leállítás** jelölőnégyzet bekapcsolásával.
- **wp-config.php állandóval:** `define( 'WPS_PEPITA_FEED_EMERGENCY_DISABLED', true );`
- **Hatása:** A végpont azonnal `HTTP 503 Service Unavailable` és `Retry-After: 300` választ ad minden kérésre.
- **Kritikus szabály:** **Ne a béta üzemmód kikapcsolásával állítsa le a feedeket!** A vészhelyzeti leállító független kapcsoló, amely nem érinti a rendelésfogadás tiltását.

### B) Token azonnali visszavonása (Token Revocation)
- Az adminisztrációs felületen a feed token újragenerálható, ami azonnal elvágja a partneri hozzáférést a régi címen.

### C) Szigorú hibakezelés és visszaállítás (Fail-Closed Recovery)
- Ha az aktív mutató fájl (`active-generation.json`) megsérül vagy az általa hivatkozott fájl törlődik, a rendszer **nem hull vissza elavult kompatibilitási fájlokra**, hanem azonnal biztonsági `HTTP 503 Service Unavailable` hibával válaszol.
- Az előző stabil verzió automatikusan elérhető a `previous` mutató alatt.

---

## 13. Útmutató a Pepita Értékesítési Kapcsolattartó Részére

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

## 14. Tulajdonosi Jóváhagyási Kapu és Ajánlás (Go / Hold Gate)

- **Kódminőség és teszteredmények:** **BETA CODE: GO**  
  A feed-kiszolgálás, a zárolási mechanizmus, a katalóguskonzisztencia és a rendelési izoláció 100%-osan ellenőrzött, hibátlan.
- **Környezeti telepítés:** **DEPLOYMENT: HOLD**  
  Külső HTTPS staging host kijelölése a Tulajdonos jóváhagyására vár.
- **Szabályzat:**  
  A feed URL-ek élesben történő megosztása és a kód külső szerverre telepítése kizárólag a Tulajdonos külön írásbeli engedélyével végezhető el. WP CareGrid infrastruktúrára telepíteni szigorúan tilos.
