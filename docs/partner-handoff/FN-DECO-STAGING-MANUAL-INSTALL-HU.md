# FN-DECO staging — kézi telepítési ellenőrzőlista (RC2)

**Ki végzi:** az Owner. Az AI-ügynököknek nincs és nem lesz hozzáférésük a szerverhez, SSH-hoz, FTP-hez, adatbázishoz, Redis-hez vagy WordPress-adminhoz. Az ügynök utasításokat, ellenőrző szkriptet és javított ZIP-et ad; az elfogadást az Ön által küldött, **megtisztított** bizonyítékokból értékeli.

**Állapot:** FN-DECO staging előellenőrzés: **HOLD**, amíg az A. rész pontjai nincsenek igazolva. Kézi telepítés: **HOLD** az Owner kifejezett GO-jáig. Pepita élő kapcsolat: **HOLD**. Production: **SZIGORÚ HOLD** — production fájlhoz, adathoz vagy táblához nem nyúlunk.

**Fontos háttér:** a staging és a production **ugyanazt az adatbázist** használja, csak a táblaprefix különbözik. A plugin a saját tábláit és beállításait a WordPress aktuális prefixével hozza létre (kódból igazolt), de a közös adatbázis miatt minden lépés előtt győződjön meg róla, hogy **a staging admin felületén** van (URL: `…/staging/wp-admin`). **Egy mentés önmagában nem bizonyítja, hogy a visszaállítás működik** — a mentés megléte nem helyettesíti az alábbi rollback-lépéseket.

---

## A. Rövid előellenőrzés (WordPress-admin és tárhely-felület; mind „igen” kell legyen)

Jelölje be, és küldje vissza a válaszokat (jelszó/kulcs nélkül).

1. [ ] **Biztosan a stagingen vagyok:** a böngészőben a cím `https://fndeco.com/staging/wp-admin/…`, és *Beállítások → Általános* alatt a *WordPress cím* és *Webhely cím* a staging URL.
2. [ ] **Más a táblaprefix:** a tárhely adatbázis-kezelőjében (phpMyAdmin) a staging és a production táblái más előtaggal kezdődnek, és az egyik előtag nem része a másiknak (jó: `prd7_` / `stg7_`; **rossz:** `wp_` / `wp_stg_`). Írja le a két előtagot.
3. [ ] **A staging külön fájlrendszer:** a staging WordPress saját könyvtárban fut (saját `wp-config.php`, `wp-content`, `uploads`), nem a production szimbolikus linkje. *Eszközök → Webhely állapot → Infó → Könyvtárak és méretek* mutatja az útvonalakat; hasonlítsa össze a production útvonalaival.
4. [ ] **Nincs megosztott felhasználói tábla:** a staging admin felhasználói a staging prefixű `…users` táblában vannak (phpMyAdmin), és a hosting/wp-config nem használ közös felhasználói táblát.
5. [ ] **Gyorsítótár:** vagy nincs Redis/object cache (*Webhely állapot → Infó → Adatbázis/Gyorsítótár*, nincs `object-cache.php`), vagy a staging és a production eltérő kulcs-előtagot és Redis-adatbázist használ. Ha bizonytalan: **HOLD**.
6. [ ] **Kimenő integrációk kikapcsolva a stagingen** (mindegyiket nézze meg az admin felületen):
   - Stripe/fizetési modulok: **teszt mód**, nincs élő kulcs;
   - e-mail: a staging nem küld valódi ügyfeleknek (SMTP kikapcsolva vagy e-mail-csapda);
   - számlázás (pl. Számlázz.hu/Billingo): kikapcsolva vagy teszt;
   - szállítás (GLS/MPL stb.): kikapcsolva;
   - WooCommerce webhookok (*WooCommerce → Beállítások → Haladó → Webhookok*): nincs aktív élő webhook.
7. [ ] **Cron:** a stagingen a WordPress-cron nem végez éles műveletet. *WooCommerce → Állapot → Ütemezett műveletek* (Action Scheduler): nézze át a függőben lévő műveleteket; nincs fizetési/számlázási/szállítási/e-mail-kampány művelet. (Ha van WP Crontrol, a cron események is listázhatók.)
8. [ ] **Friss mentés van, és tudja, mit tartalmaz:** a mentés a staging prefixű táblákat **és** a staging fájlokat (wp-config, plugins, themes, uploads, mu-plugins) tartalmazza. Írja le a mentés azonosítóját/időpontját. *A visszaállítást ne a közös adatbázison próbálja ki.* (Lásd D. rész.)
9. [ ] **Plugin-állapot rögzítve:** *Bővítmények* → van-e már „WP Pepita Sync” (melyik verzió, aktív-e)? Írja le. Ha **már aktív** egy korábbi verzió, a ZIP cseréje azonnal az új kódot futtatja — ilyenkor a B. rész wp-config lépését **a feltöltés előtt** el kell végezni.

Ha bármelyik „nem” vagy „nem tudom”: **HOLD**. Írja meg, melyik, és javaslatot adunk.

---

## B. Telepítés előtt — kötelező biztonsági beállítások (az aktiválás előtt!)

1. [ ] A **staging** `wp-config.php` fájljában (a `/* That's all, stop editing! */` sor **elé**) legyen:
   ```php
   define( 'WPS_PEPITA_BETA_FEED_ONLY', true );
   ```
   Hatás: a rendelésfogadás **kikapcsolt (HTTP 503)**, a feed nem nyilvános, és csak a kifejezetten bekapcsolt kategóriák termékei exportálódnak.
2. [ ] Ellenőrizze, hogy ez a `wp-config.php` a **staging**é (nem a productioné).
3. [ ] A B/1 és az A/6 teljesült: **kimenő integrációk kikapcsolva**.
4. [ ] Az Owner kiadta a **kifejezett staging GO-t** (az ügynök nem telepít, és nem is kér hozzáférést).

---

## C. A ZIP ellenőrzése és feltöltése — **a feltöltés nem aktiválás**

**Csomag:** `dist/wp-pepita-sync.zip` a `release/pepita-rc2` ágon (a fájlt az ügynök adja át; a repóbeli út: `dist/wp-pepita-sync.zip`).

**Elvárt SHA-256:**
```
8b8c8f7878771ee76bb75f9610e573ce9672315089d7e9bb38996216680c1f93
```
(RC2 kód: `77c596eb46cedd5ef07cf977f78a2139a8f335c9`. A plugin fejléce `1.1.0`-t mutat — az RC2 azonos verziószámot használ, ezért a **kivonat** az azonosító, nem a verziószám.)

**Kivonat ellenőrzése a saját gépén:**
- Windows (PowerShell): `Get-FileHash .\wp-pepita-sync.zip -Algorithm SHA256`
- macOS: `shasum -a 256 wp-pepita-sync.zip`
- Linux: `sha256sum wp-pepita-sync.zip`

Ha az érték **nem egyezik**: **ne töltse fel**, jelezze.

**Feltöltés (még nem aktiválás):**
1. [ ] Staging admin → *Bővítmények → Új bővítmény → Bővítmény feltöltése* → válassza a ZIP-et → *Telepítés*.
2. [ ] Ha a WordPress rákérdez („a bővítmény már telepítve van”): **csak akkor** válassza a *Cseréje a feltöltöttel* lehetőséget, ha az A/9 pontban rögzítette a korábbi állapotot, és a B/1 lépés kész.
3. [ ] **Itt megáll:** a bővítmény *telepítve, de inaktív*. **Ne kattintson az „Aktiválás”-ra**, amíg az A–B rész teljesül és az Owner nem adott aktiválási GO-t.

---

## D. Visszaállítási terv (előbb ez, mint az aktiválás)

A cél **plugin-szintű visszalépés**, a production érintése nélkül. A teljes adatbázis-visszaállítás **tilos** (production adatvesztést okozna).

1. [ ] *Bővítmények* → **Deaktiválás**, majd **Törlés** (ha a *Beállítások → … → „Adatok törlése eltávolításkor”* ki van kapcsolva, a törlés a táblákat és a beállításokat **nem** törli — ez biztonságos).
2. [ ] Ha teljesen tiszta állapot kell: phpMyAdmin-ban **csak a staging prefixű** `…wps_pepita_orders` és `…wps_pepita_logs` táblákat törölje (nézze meg kétszer az előtagot; production tábla nem szerepelhet), és a staging `…options` táblából a `wps_pepita_%` nevű sorokat. A `wp-content/uploads/` alatt a `wps-pepita-…` és `wps-derivatives` mappákat törölje.
3. [ ] Távolítsa el a `WPS_PEPITA_BETA_FEED_ONLY` sort a `wp-config.php`-ból.
4. [ ] Ellenőrzés: a staging táblák száma egyezik a telepítés előtti számmal; a production táblák száma nem változott.
5. [ ] **Próba:** a mentés visszaállíthatóságát egy **külön, ideiglenes adatbázisban** kell ellenőrizni (a közös adatbázison soha). Ha ez nem megoldható, a mentést nem tekintjük bizonyítottnak — a fenti plugin-szintű rollback marad az egyetlen elfogadott visszaút.

---

## E. Aktiválás és első beállítás (csak aktiválási GO után)

1. [ ] *Bővítmények → WP Pepita Sync → Aktiválás*. Ellenőrizze: nincs hibaüzenet; a *Pepita* menü megjelent.
2. [ ] *Pepita → Beállítások*: látszik a „Béta feed-only” üzemmód. **A rendelés API ki van kapcsolva** — ne kapcsolja be.
3. [ ] **Ellenőrizze, hogy a feed-riasztás e-mail és webhook KI van** (a stagingről ne menjen külső értesítés).
4. [ ] *Pepita → Kategóriák*: kapcsoljon be **1–2 kisebb, ismert kategóriát** a kezdéshez (a béta módban csak a bekapcsolt kategóriák exportálódnak).
5. [ ] *Pepita → Feedek*: indítsa el a **teljes feed** generálását, majd a **készlet feedet**.
6. [ ] A feed fájlokat a *Letöltés* gombbal mentse le (ne a tokenes URL-t másolja; a token titok).

---

## F. Mit küldjön vissza (megtisztítva)

**Soha ne küldje:** jelszót, API-kulcsot, feed tokent (a *Feed URL*-ben van!), `wp-config.php` tartalmát, ügyfél-e-mailt/telefont/címet, az *API* oldalon látható „teljes URL a kulccsal” mezőt.

| # | Bizonyíték | Honnan |
|---|---|---|
| 1 | Az A. rész kitöltött válaszai | e-mail/üzenet |
| 2 | `full.xml` és `stock.xml` (a *Letöltés* gombbal mentett fájlok) | *Pepita → Feedek* |
| 3 | Képernyőkép: *Feedek* (futási számok; a Feed URL mezőt takarja ki) | *Pepita → Feedek* |
| 4 | Képernyőkép: *Import-előkészítés / Feed ellenőrzés* (lefedettség, kizárt/hibátlan darabszám) | *Pepita → Import-előkészítés* |
| 5 | Képernyőkép: *Hibás termékek* (lista, okok) | *Pepita → Hibás termékek* |
| 6 | Képernyőkép: *Rendelés API* oldal — **a kulcsot és a teljes URL-t takarja ki**; látszódjon a „kikapcsolva” jelzés | *Pepita → Rendelés API* |
| 7 | Az Order API válaszkódja: egy böngészőből/terminálból küldött üres POST a staging végpontra `…/staging/wp-json/wps/v1/pepita/order` → várt: **503** | saját gép |
| 8 | 10 WebP forrású termék: a feedben szereplő képURL megnyitható-e (kép betöltődik-e) | feed + böngésző |
| 9 | Képernyőkép: *Naplók* (nincs kimenő Pepita-hívás, nincs hiba) | *Pepita → Naplók* |

**Az ügynök ezután:** futtatja az offline ellenőrzőt a fájlokon (`python3 -I bin/feed-acceptance-check.py full.xml stock.xml --staging-host fndeco.com/staging --json run1.json`), elemzi a képernyőképeket, és kitölti az elfogadási jelentést (**PASS / HOLD** pontonként). Hiba esetén javított ZIP-et készít új SHA-256-tal.

---

## G. Mi az, amit ez a teszt **nem** bizonyít

- Hogy a Pepita elfogadja a feedet és a kategóriákat (ez a Pepita saját import-eredménye).
- Hogy a staging feed URL-jei használhatók élesben: a staging képURL-ek **nem mehetnek Pepitának**; élesre külön, valós URL-es feed kell.
- A rendelés API élő működését: az a stagingen kikapcsolva marad.
