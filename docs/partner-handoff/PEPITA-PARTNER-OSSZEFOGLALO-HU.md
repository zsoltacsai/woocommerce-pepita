# WP Pepita Sync — Pepita partneregyeztetés, tulajdonosi összefoglaló (RC2)

**Állapot:** belső előkészítés. A Pepita felé semmi nem lett elküldve, élő Pepita-kapcsolat nem aktív. A technikai alap a `release/pepita-rc2` (RC1 + a rendelés API alapértelmezetten KI módosítás). Részletek: `docs/partner-handoff/` (API-CONTRACT, ERROR-CODES, INTEGRATION-GUIDE, FN-DECO-STAGING-CHECKLIST).

## 1. Mit tud ma az integráció

| Képesség | Állapot |
|---|---|
| Teljes termék-XML feed (`full`) | Működik; sávos (chunkolt) generálás, atomi publikálás, tokenes URL. |
| Készlet/ár XML feed (`stock`) | Működik; csak akkor publikálódik, ha a katalógus megegyezik az utolsó sikeres teljes feeddel. |
| Minőségkizárások | Hibás termék (hiányzó kép, érvénytelen GTIN, GTIN-kötelező kategória stb.) kimarad, az ok látszik az adminban. |
| Képkezelés | JPEG/PNG, min. 1200×630; WebP → JPEG származék; kis kép csak figyelmeztetés. |
| Kategóriák | Termékenként egy kategória-hierarchia; leképezés szabályokkal és „approved parent” stratégiával. |
| Rendelés fogadás (REST) | Megvan, **alapértelmezetten KI** (503), csak a kereskedő kifejezett bekapcsolásával él. Élő forgalomra **nincs jóváhagyva**. |
| Duplikáció / újrakézbesítés | Azonos azonosító + azonos tartalom → 200, ugyanaz a rendelés; eltérő tartalom → 409, a rendelés nem változik. |

## 2. Feed formátumok és tesztelhető URL-ek

- Teljes feed: `https://<áruház>/?wps_feed=full&token=<feed_token>`
- Készlet feed: `https://<áruház>/?wps_feed=stock&token=<feed_token>`
- A token nélküli (nyilvános) elérés alapból tiltott. Pepitának tokenes URL-t adunk át, csak a Pepita által megadott csatornán.
- **Fontos:** a stagingről generált feed staging termék- és kép-URL-eket tartalmaz. Pepita felé **csak valódi, publikus (nem staging) URL-eket tartalmazó feed** mehet. Ezt a feedet az éles áruház URL-jeivel külön elő kell állítani és a képek elérhetőségét (HTTP 200, JPEG/PNG, ≥ 1200×630) ellenőrizni.

## 3. Kategórialeképezés — példák és kérdések

- Példa: WooCommerce „Bútor > Étkezőszékek” → Pepita kategóriaút (a pontos Pepita-kategóriafa nálunk nincs meg).
- **Kérdések Pepitának:** (1) Kapjuk meg a hivatalos kategóriafát/azonosítókat? (2) Elfogadják-e a mélyebb hierarchiát vagy csak adott szintet? (3) Mely kategóriákban kötelező a GTIN? (4) Mi történik az ismeretlen kategóriával — elutasítás vagy besorolatlan?

## 4. Kép és GTIN ellenőrzési kérdések

- Elfogadott képformátum és minimum méret azonos-e a mi 1200×630 JPEG/PNG feltételezésünkkel? Korlátozás a fájlméretre, a képek számára, a külső (CDN) hosztra?
- GTIN: nálunk 8/12/13/14 számjegy, ellenőrző számjegy-számítás nélkül. Pepita ellenőriz-e check digitet? Mit tegyünk GTIN nélküli (saját márkás) terméknél?

## 5. Elvárt import-elfogadási folyamat

1. Pepita beolvassa a teljes feedet → hibalistát ad (formátum? riport? kérjük a mintát).
2. A hibás termékeket a belső azonosító (`wps-<id>`) és SKU alapján párosítjuk a „Hibás termékek” képernyővel.
3. Javítás WooCommerce-ben → új teljes feed → újraimport → majd készletfeed.
4. A folyamat addig ismétlődik, amíg Pepita elutasított és a mi kizárt darabszámunk termékenként megmagyarázott.

## 6. Készletfeed frissítési elvárások

- Alapértelmezett: készlet/ár feed 30 percenként, teljes feed 24 óránként (állítható).
- **Kérdés:** milyen gyakran húz Pepita, és mi a megengedett késés? Kell-e `Retry-After`/ETag támogatás?
- Szabály: katalóguséváltozás után előbb a teljes feed, utána a készlet feed.

## 7. Rendelés API — nyitott kérdések Pepita felé

- A mi szerződésünk: `POST /wp-json/wps/v1/pepita/order`, HTTPS, kulcs az `apikey` query paraméterben vagy `X-WPS-Api-Key` / `X-Api-Key` fejlécben. **Nincs** Bearer token és payload-aláírás.
- Kérdések: (1) Ez a tényleges Pepita-oldali payload? Kapjuk meg a hivatalos mintát/specifikációt. (2) Küldhető-e a kulcs fejlécben (query paraméter naplókban megjelenhet)? (3) Újrapróbálkozás és backoff 409/5xx esetén? (4) Hogyan jelzik a módosítást és a lemondást? (5) Valutaegyezés: a kód nem hasonlítja össze a rendelés és az áruház pénznemét.

## 8. Tesztrendelés és sandbox feltételek

- Csak staging/sandbox áruházban, szintetikus adatokkal (`example.test` e-mail, dobható kulcs); valódi ügyfeladat soha.
- A rendelés API-t a tesztre kifejezetten be kell kapcsolni (Beállítások → Rendelés API), a teszt után ki.
- Kérés Pepitának: sandbox/teszt rendelésküldő és tesztfiók, amely nem hoz létre valódi szállítást vagy számlázást.

## 9. Belső tesztek és Pepita-elfogadás közti különbségek

| Belső bizonyíték | Nem bizonyítja |
|---|---|
| XML-szerződés és validátor tesztek, valós MySQL integrációs tesztek HPOS/legacy módban (RC2) | Hogy Pepita elfogadja a feedet és a kategóriákat |
| Duplikáció/konkurencia/lease-fencing tesztek | Pepita tényleges újrapróbálkozási viselkedése |
| Szintetikus rendelés-fixture | Pepita valódi payloadja |
| 269→241 termékszám régebbi dokumentumokban | **Szintetikus fixture szám, nem valós FN-DECO eredmény** — nem hivatkozható bizonyítékként |

**Éles üzem: HOLD.** Telepítés, élő Pepita-hívás és valódi rendelés tiltott, amíg a Pepita-egyeztetés és az FN-DECO staging elfogadás le nem zárul.
