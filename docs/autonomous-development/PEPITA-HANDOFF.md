# WP Pepita Sync — fejlesztési felelősség átadása

**Készült:** 2026-11-14  
**Állapot:** átadás előkészítve; új felelős elfogadása még szükséges  
**Hatókör:** repository-fejlesztés; telepítés, éles API és production továbbra is tiltott

## 1. Repository és biztonságos állapot

- Repository: `zsoltacsai/woocommerce-pepita`
- Projekt munkakönyvtár: `/home/zsolt/Projects/woocommerce-pepita`
- Aktív branch: `feature/m9f-intelligent-catalog-quality`
- Dokumentálás előtti utolsó ellenőrzött HEAD: `532b3dbe7fcede1cd2c10e0c2ccec85d0d5f46e0`
- A dokumentálás előtt a munkafa tiszta volt; nem volt félkész, nem commitolt kódváltozás.
- A handoff dokumentum és a ledger következő commitja módosítja ezt az ágat; ezért az új felelősnek ellenőriznie kell az utána kialakuló pontos HEAD-et, és az alábbi CI-állapotot nem szabad automatikusan az új dokumentációs commitra érvényesnek tekinteni.

## 2. Nyitott PR-ek és CI

| PR | Base | HEAD | Állapot | Exact-head CI |
|---|---|---|---|---|
| [#10](https://github.com/zsoltacsai/woocommerce-pepita/pull/10) M9-E | `0f0fe1c058090682f9507d06a9c1aa8116c46888` | `a4854cdf9b20bcd54b0180fb2cc31b4bfa97aab9` | OPEN / Draft | [43573024669](https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43573024669) — SUCCESS; PHP 8.1–8.5, lint/reproducible build, HPOS és legacy PASS; RR20 SKIPPED |
| [#11](https://github.com/zsoltacsai/woocommerce-pepita/pull/11) M9-F | `a4854cdf9b20bcd54b0180fb2cc31b4bfa97aab9` | `532b3dbe7fcede1cd2c10e0c2ccec85d0d5f46e0` | OPEN / Draft | [43578951699](https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43578951699) — SUCCESS; PHP 8.1–8.5 (281/job), HPOS/legacy (83/job), lint/reproducible build PASS; RR20 SKIPPED |

Ezek az eredmények a fenti SHA-kra szólnak. Új push után friss CI szükséges. Merge vagy Draftból kivétel nincs engedélyezve.

## 3. M9-E állapota

M9-E repository-kód és szintetikus elfogadás alapján PASS, staging/partner-elfogadás nélkül.

Meglévő, ellenőrzött funkciók:

- JPEG/PNG eredeti validáció; WebP→JPEG fallback és gyorsítótárazott, atomikusan írt származék; sérült cache felismerése.
- Képintegritás, MIME, méretkorlát és pixel/dekódolási erőforráskorlátok; csonkolt JPEG elutasítása; Imagick limitek szigorítása és visszaállítása.
- Galériakép-fallback, termékhibák listája és automatikus minőségi kizárás; forrás WooCommerce termékadat változatlan.
- Kézi termékkizárás megőrzése; optional SKU nem blokkoló; stock cap nem issue.
- `approved_parent` kategóriastratégia és béta allowlist kompatibilitása.
- Full/stock snapshot-egyezés és stock-only megállás eltérő termékkészlet esetén; teljes feed és matching stock újragenerálása.

Független exact-head QA és AppSec PASS a #10 `a4854cdf` HEAD-re; exact CI `43573024669` SUCCESS. Az FN-DECO tényleges 269→241 staging export és Pepita általi import **nincs** igazolva. A 269/241 adat csak szintetikus fixture, nem valódi staging bizonyíték.

## 4. M9-F eddigi munkája és review-k

Implementált részleges szelet:

- Opcionális belső 0–100 katalógusminőségi pontszám, alapértelmezésben kikapcsolva; nem Pepita-rangsor, nem befolyásolja a feed-jogosultságot.
- Már ismert validációs issue-kódokhoz nem mutáló javítási útmutatók; valódi GTIN-t kér, nem generál.
- Hibás termékek admin lekérdezése legfeljebb 50 jelöltet vizsgál kérésenként, katalógusoldalakat használ, és elérhetetlen lekérdezésnél UNKNOWN-ot mutat.
- Numerikus query pontos termék-ID-t, SKU query pontos WooCommerce SKU-indexet használ; keresési teszt van.
- Dokumentáció jelzi, hogy WebP ellenőrzése JPEG-származékot írhat/hibás cache-t cserélhet az uploads dedikált könyvtárában; termékadat nem módosul.

PR #11 exact `532b3db`-re:

- Integration QA: PASS, Google DeepMind Gemini 3.8 Flash (Antigravity), inline jelentés.
- AppSec: PASS, OpenAI gpt-6-astra, high, inline jelentés.
- CI: run `43578951699` SUCCESS.
- ZIP SHA-256: `81be6d270831c052286af2aa3a2ec4453705fc3f7f08fb7f03652d4dda7a381c`; a checksum sidecar egyezik, 61 fájlos CRC PASS, reprodukálható build CI-ben PASS.
- Helyi PHP teszt: NOT RUN, mert PHP CLI nem áll rendelkezésre.

Nyitott M9-F korlátok:

- Admin lapozás 50 jelöltre korlátozott, de a teljes találatszám lekérése és a nagy OFFSET költsége, valamint galériaképek vizsgálati költsége nincs teljesen korlátozva; nincs háttérindex vagy frissességi ledger.
- Még nincs Pepita-specifikációval megerősített kiterjesztett márka/gyártó/attribútum/szállítási minőségkövetelmény és újrafelhasználható mapping szabályrendszer.
- Numerikus SKU termék-ID-ként értelmezett. Variációs SKU nem feltétlenül jelenik meg a szülőtermékként. A név szerinti WooCommerce `s` keresés termelési adatstore viselkedése nincs külön integrációs teszttel rögzítve; a unit stub teszt nem bizonyítja.

## 5. Regressziók és sikertelen tesztek

- A korábbi #10 `d8c3180` körüli CI run `43572477152` elbukott: HPOS/legacy integrációkban 68 PASS / 15 FAIL volt, többek közt üres full feed és következményes stock/HTTP elvárások. A hiba pontos gyökéroka nem lett teljes bizonyossággal izolálva a rendelkezésre álló logokból. Későbbi képdekódolási memóriakeret-korrekció után a #10 `a4854cdf` CI teljesen PASS lett. Ezt ne állítsd bizonyított oksági javításnak.
- Korábbi M9-E AppSec review az Imagick korlátok enyhítését és hiányos JPEG elfogadását találta; ezeket későbbi commitok javították és későbbi review/CI lefedte.
- A legfrissebb M9-E/#10 és M9-F/#11 CI-k PASS. RR20 experimental SKIPPED, nem PASS.

## 6. Artifact és build

PR #12 csomag SHA-256: `3423bc2486cedee0804b96d20431db76f5ed704856788b726c222d666fbe9d78` (`dist/wp-pepita-sync.zip`). Sidecar: `dist/wp-pepita-sync.zip.sha256`. ZIP 61 fájlos, `unzip -tq` PASS. A `bin/verify-reproducible.sh` öt időzónán azonos hash-t igazolt. Új HEAD dokumentációs ZIP-frissítése után újra kell építeni/ellenőrizni; a ZIP-ben a docs nem szerepelnek, de minden forrásváltozáskor release epoch/build szabályt követni kell.

## 7. Átvétel utáni első feladat — lezárva

1. A kijelölt felelős elolvasta ezt a fájlt és az `EXECUTION-LEDGER.md`-t; ellenőrizte a repositoryt, branch-et, tiszta munkafát, exact CI-t, PR #10/#11 headet és státuszt.
2. A handoff HEAD `ab0c1d574cb5bb546c76849e3265059672b1b38b` exact CI-je (`43580024938`) **SUCCESS**.
3. M9-F keresési lefedés: valós WooCommerce HPOS és legacy integrációs teszt ellenőrzi a terméknév-, pontos SKU- és numeric-ID-keresést. A variation SKU a szülőtermék issue-center sorát adja vissza. A kért oldalszám 1000-re korlátozott; a kérés legfeljebb 50 termékjelöltet vizsgál.
4. A kód HEAD `3ea995fe35e3d6cadf2459c20845e2085ec6cba3` CI-je (`43580583517`), `fb4f0ef5be76f56aefbb72840bde0e15e2e1ef5c` CI-je (`43580895516`), `9e5857fa081cbf801991c6ac778d36c4523008e7` CI-je (`43581264829`), az attribútum-mapping `1bd6503c94892cfa76e4c25f44e13cfda85beae4` HEAD CI-je (`43584004363`), `59c7d40c3b5811c1d448ec39e30ea2cd55f7a0c5` CI-je (`43585503047`), valamint a végleges felülvizsgálati javításokat tartalmazó `f80241820ad868340430e2c81c3b28fbbc6e6daa` HEAD CI-je (`43588942291`) mind **SUCCESS**; PHP 8.1–8.5 (284 unit teszt/job), lint/reproducible package, HPOS és legacy PASS; RR20 SKIPPED. A ZIP SHA-256 `3423bc2486cedee0804b96d20431db76f5ed704856788b726c222d666fbe9d78`; öt időzónás reprodukálhatóság PASS.
5. Költséghatár: legfeljebb 50 termék issue-vizsgálata történik kérésenként, de a WooCommerce teljes találatszám-lekérése nincs időkerettel korlátozva, az 1000. oldal akár 49 950-es OFFSET-et jelent 50-es oldalméretnél, és a galéria-fallback több helyi képvalidációt futhat. Ezek megmaradó nagy katalógus-kockázatok; async index és mért load test nincs.
6. Fennmaradó M9-F lezárva: hivatalos Seller Center specifikáció alapján elkészült az opcionális attribútumnév-megfeleltetés (`attribute_name_map`), amely mind a normál termékattribútumokra, mind a variációs tengelyekre alkalmazható a forrásadatok megváltoztatása nélkül. Az AppSec (Claude Sonnet 5.5, PASS) és Integration QA (Claude Opus 5.5, PASS re-check) jóváhagyta; az F1/B1 (admin mentési whitelist) és A2 (statikus cache) észrevételek javítva, megerősítő CI `43588942291` SUCCESS. A PR #12 Draft lezárásra kész, PR #11 Draft megőrzésével.

## 8. Teljes roadmap és visszaállítási pont

Kötelező sorrend:

1. **M9-E** — kép fallback, hibás termékek, quality kizárás, kategóriamapping; kód/CI/review PASS, valódi staging elfogadás nyitott.
2. **M9-F** — quality center, megalapozott minőségellenőrzés, javítási javaslatok, attribute mapping, belső score; PASS (repo-kód, CI és független review lezárva; staging elfogadás nyitott).
3. **M9-G** — feed preview, biztonságos publikálási blokkolás és monitoring/értesítések; még nem kezdődött.
4. **M9-H** — piactéri készlet- és árkalkuláció, forrásadat-módosítás nélkül; még nem kezdődött.
5. **M10** — rendelésműveletek/idempotencia/HPOS diagnosztika csak szintetikus vagy igazolt sandbox tesztekkel; live order API tiltott.
6. **M11** — merchant profilok/importriportok/operációs riportok; titok- és személyesadat-mentes export.

Visszaállítási pontok: M9-E branch/PR #10 HEAD `a4854cdf9b20bcd54b0180fb2cc31b4bfa97aab9`; M9-F branch/PR #11 HEAD a handoff dokumentációs commit előtt `532b3dbe7fcede1cd2c10e0c2ccec85d0d5f46e0`. Nincs production/staging telepítési vagy visszaállítási művelet engedélyezve.

## 9. Biztonsági határok

- Csak `zsoltacsai/woocommerce-pepita` repository-fejlesztés a korábbi Owner GO keretében.
- Production deployment és staging telepítés: NO-GO; a staging telepítést a tulajdonos végzi.
- Nincs élő Pepita API, rendelésaktiválás, valódi rendelési/customer adat tesztben, adatbázis-migráció vagy production/staging konfigurációmódosítás.
- Nincs protected branch közvetlen módosítás, merge vagy CI/security gate megkerülés.
- WP CareGrid repository/infrastruktúra teljesen kívül esik a Pepita felelős scope-ján.
- Ne használd a staging/production titkokat, ne jelenítsd meg és ne rögzítsd őket.

## 10. Átadás állapota

Az átadás ACK állapotú a jelen beszélgetésben: a kijelölt fejlesztési felelős visszaigazolta a scope és a korlátozások átvételét. Az aktuális munka M9-F részleges folytatása; további mérföldkövek és review-k nyitottak.
