# FN-DECO staging elfogadási jelentés — RC2 (sablon)

**Kitöltés:** az ügynök, az Owner által küldött, megtisztított bizonyítékok alapján (képernyőképek, `full.xml`, `stock.xml`, az A. rész válaszai). Az ügynök a szerverhez nem fér hozzá; minden sor a **kapott bizonyítékra** hivatkozik. Bizonyíték nélküli sor = **HOLD**, soha nem PASS.

| Mező | Érték |
|---|---|
| Dátum | |
| Csomag SHA-256 (az Owner által megadott érték) | elvárt: `8b8c8f7878771ee76bb75f9610e573ce9672315089d7e9bb38996216680c1f93` |
| Kód (RC2) | `77c596eb46cedd5ef07cf977f78a2139a8f335c9` |
| Futtatott ellenőrző | `bin/feed-acceptance-check.py` (verzió: repó-commit) és kimenetének mentése |

## Kapuk

| # | Kapu | PASS feltétele | Bizonyíték | Eredmény |
|---|---|---|---|---|
| P1 | Staging azonosítása | A. rész 1. pont: a cím és a Webhely cím a staging | | |
| P2 | Prefixek és izoláció | A. rész 2–4. pont: különböző, egymásnak nem előtagja; külön fájlrendszer; nincs megosztott felhasználói tábla | | |
| P3 | Gyorsítótár | A. rész 5. pont | | |
| P4 | Kimenő integrációk | A. rész 6. pont mindegyik elem igazolt | | |
| P5 | Cron / Action Scheduler | A. rész 7. pont | | |
| P6 | Mentés és rollback | mentés azonosítva; **plugin-szintű rollback lépései** (D. rész) jóváhagyva; a mentés önmagában nem számít bizonyítéknak | | |
| P7 | Csomag | SHA-256 egyezik | | |
| I1 | Feed-only mód | *Beállítások* képernyő: béta/feed-only aktív; `WPS_PEPITA_BETA_FEED_ONLY` beállítva | | |
| I2 | Order API 503 | üres POST → 503; *Rendelés API* oldalon „kikapcsolva” | | |
| F1 | Teljes feed | `full.xml` jól formált (`xmllint --noout`), névtér `https://pepita.hu/feed/1.0` | | |
| F2 | Készletfeed | `stock.xml` letöltött, jól formált | | |
| F3 | Teljes/készlet konzisztencia | script: azonos ID-halmaz, mennyiség és ár | | |
| F4 | Valós termékszámok és kizárások | *Feed ellenőrzés* képernyő + script: publikált / exportált / kizárt, okokkal | | |
| F5 | Kategória | script: minden terméknek van kategóriája; 10 termék mintavétele a *Kategóriák* képernyővel | | |
| F6 | Képek és WebP | script: nincs nem-JPG/PNG kép; 10 WebP-forrású termék képe betölt | | |
| F7 | GTIN | script: nincs érvénytelen GTIN; a GTIN-kötelező kategóriák hibái a *Hibás termékek* listán | | |
| F8 | Azonosító-stabilitás | második generálás `--baseline`-nal: egyetlen azonosító sem tűnik el | | |
| F9 | Hibás termékek felület | képernyőkép: betölt, okok láthatók | | |
| F10 | Feed publikációs biztonsági kapu | beállítás BE + kis katalóguszsugorítás után a feed „jóváhagyásra vár”; jóváhagyás/elutasítás működik | | |
| F11 | Staging URL elkülönítve | script `staging_image_urls_products` > 0 → a feed **nem küldhető Pepitának**; élesre külön valós-URL-es feed kell | | |
| X1 | Nincs élő Pepita-kapcsolat | *Naplók*: nincs kimenő Pepita-hívás | | |

**Értékelési szabály:** PASS = a bizonyíték a feltételt kielégíti; **HOLD** = hiányzó, bizonytalan vagy nem a stagingről származó bizonyíték; **FAIL** = a bizonyíték hibát mutat (javítás és új ZIP szükséges). Bármelyik P-kapu HOLD/FAIL esetén az F-kapukat nem értékeljük.

## Mért adatok

| Adat | Érték |
|---|---|
| WooCommerce publikált termékek | |
| Exportált termékek (teljes feed) | |
| Készletfeed termékei | |
| Kizárt termékek és főbb okok | |
| Változós termékek / variációk | |
| Bekapcsolt kategóriák (béta mód) | |
| Képhosztok (script `image_hosts`) | |

## Eltérések, hibák, javítások

| # | Leírás | Súlyosság | Javítás / új ZIP SHA-256 | Újraellenőrzés |
|---|---|---|---|---|
| | | | | |

## Összegzés

- Staging elfogadás: **PASS / HOLD / FAIL**
- Pepita élő kapcsolat: **HOLD**
- Production: **SZIGORÚ HOLD**
