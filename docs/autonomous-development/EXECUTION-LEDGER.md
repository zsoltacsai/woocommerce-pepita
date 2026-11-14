# Autonóm fejlesztési napló — WP Pepita Sync

**Állapot:** folyamatban
**Utolsó frissítés:** 2026-11-14
**Biztonsági határ:** kizárólag repository-fejlesztés és izolált CI; staging/production telepítés, éles rendelés/API és merge nincs engedélyezve.

## Összesített mérföldkő-státusz

| Mérföldkő | Státusz | Branch / HEAD | PR | CI | QA / AppSec | Blokkoló / következő feladat |
|---|---|---|---|---|---|---|
| M9-E | PASS (repo-kód és szintetikus elfogadás) | `feature/m9e-image-fallback-product-issues` / `a4854cdf9b20bcd54b0180fb2cc31b4bfa97aab9` | [#10](https://github.com/zsoltacsai/woocommerce-pepita/pull/10), OPEN/Draft | `43573024669` exact `a4854cd`: **SUCCESS**; PHP 8.1–8.5 unit (279/job), lint/reproducible ZIP, HPOS és legacy integráció PASS; RR20 experimental SKIPPED. ZIP SHA-256: `7eea712276d82c395ea1b0778b87a4d3bb361285f1d435cc0c5d8d941b71d2ef`, öt időzónás clean build egyezett. | Exact-head QA PASS (Gemini 3.8 Flash, inline report, nincs report-artifact hash); AppSec PASS (Gemini 3.8 Flash, report SHA-256 `95217ffd2ebfc9e33bbc9526007b17550206bf8d995ba07dbdb4025907de9057`), mindkettő base `0f0fe1c`. | Kód/CI kapu zárva. FN-DECO staging eredmény és valódi 269→241 katalógus nincs igazolva; merge és telepítés nem történt. |
| M9-F | PASS (repo-kód, keresés, attribútum-mapping, CI és független QA/AppSec lezárva) | Base `feature/m9f-intelligent-catalog-quality`; stacked Draft PR #12 `neville/pepita-m9f-handoff` / `f80241820ad868340430e2c81c3b28fbbc6e6daa` | Exact CI `43588942291`: **SUCCESS**; PHP 8.1–8.5 unit (284/job), lint/reproducible package, HPOS és legacy integráció PASS; RR20 SKIPPED. | Független AppSec PASS (Claude Sonnet 5.5); Integration QA PASS (Claude Opus 5.5). B1/F1 és A2 javítva és igazolva. | Kód kész: keresés (név/SKU/ID/variáció), lapozási limit (1000 oldal, max 50 termék/kérés), attribútumnév-mapping szabályok perzisztálással és statikus gyorsítótárral. ZIP SHA-256 `3423bc2486cedee0804b96d20431db76f5ed704856788b726c222d666fbe9d78`. |
| M9-G | HOLD | — | — | — | — | M9-E/M9-F függőségek után |
| M9-H | HOLD | — | — | — | — | M9-E–M9-G után |
| M10 | HOLD | — | — | — | — | Katalógus/feed-mérföldkövek után, csak szintetikus tesztekkel |
| M11 | HOLD | — | — | — | — | Korábbi minőségkapuk után |

## M9-E végrehajtási napló

### Már meglévő implementáció

- A munka a létező `#10` Draft PR ágon folytatódik, nem hoz létre párhuzamos M9-E ágat.
- A `72668e193f3fd440c83bdb537c7283e9dce112a4` tartalmaz WebP→JPEG származékot, sérült származék ellenőrzést, galériakép-fallbackot, minőségi hibajegyzéket és automatikus kizárást, `approved_parent` kategóriastratégiát és az FN-DECO 269 termékes szintetikus elfogadási esetet.
- Az ismert hivatalos képspecifikáció a standard JPG/PNG és a minimum 1200×630 képméret; forrás: [Pepita Seller Center — termékmegjelenítési követelmények](https://sellercenter.pepita.com/a-termekmegjelenites-tartalmi-kovetelmenyei/).

### Végzett ellenőrzések

- PR #10 nyitott, Draft; base `fix/m9d-fndeco-catalog-compatibility` (`0f0fe1c058090682f9507d06a9c1aa8116c46888`), HEAD `72668e193f3fd440c83bdb537c7283e9dce112a4`.
- CI run `43542471186` pontosan ezt a HEAD-et vizsgálta és sikeresen lezárult. PHP 8.1–8.5 unit, PHP 8.5 syntax/reproducible package, HPOS és legacy integrációs job PASS. Az opcionális RR20 experimental mátrix SKIPPED volt, nem PASS.
- A branch diff `git diff --check` ellenőrzése tiszta; a ZIP hash `756d3fe6738184464fdb9c5bf6b50d275870072fd912523edbe641264184552f`.
- Helyi futtatás nem volt lehetséges: a gépen nincs PHP CLI, a Docker daemon socket pedig nem hozzáférhető. A helyi tesztfuttatás ezért NOT RUN; a pontos HEAD GitHub CI-je szolgáltatott futási bizonyítékot.

### Jelenlegi korrekció

- A full/stock minőségi szabályváltási regressziós teszt továbbra is az M9-E kapu része: eltérő snapshot esetén a stock-only publikálás megáll, az előző stock feed megmarad, majd új full és matching stock generálás után az ID-k, árak és készletek egyeznek.
- A `426b6bf` exact-head független QA és AppSec review egyaránt blocking hibát talált: a nem-WebP képek validálás nélkül juthattak a feedbe, és hiányzó helyi fájlnál/metadata esetén a méret ellenőrzése UNKNOWN helyett compliant állapotot adott.
- Helyi javítás: JPEG/PNG MIME, URL, helyi fájl, tényleges dekódolhatóság és 1200×630 dimenzió fail-closed ellenőrzése; ellenőrzött eredeti WebP fallback; tartalomhash-alapú cache-kulcs; biztonságos temp fájl + atomic rename; sérült vagy symlink cache elutasítása; új blocking `invalid_image` adminhiba és automatikus kizárási teszt.
- A `43567433296` naplója a `ad31983`-on PHP parse errort igazolt az Imagick névtér kétszeres backslash-e miatt; az erre épülő teszthibák következményesek voltak. A validator régi `.webp`-elfogadási tesztjét is frissítettem az elvárt JPEG/PNG feed szerződéshez.
- A `43568016659`-ben a PHP syntax és reprodukálható ZIP check már PASS lett. A 251 sikeres mellett 26 unit teszt bukott; a friss QA a közös okot megtalálta: a teszt `wp_parse_url` stubja numerikus URL-komponenst próbált kulcsként olvasni a `parse_url()` string kulcsú eredményéből, ezért a JPEG/GIF teszt attachmentekhez nem állt be forrásfájl. A stub most a PHP URL componentet használja. A régi WebP cache teszt 1×1-es JPEG-et és régi mtime-alapú fájlnevet várt; helyére valid 1200×630 WebP/JPEG fixture és tartalomhash-kulcs került.
- Az AppSec limit finding javítva: a resource limit hiánya, exceptionje vagy `false` visszatérése most leállítja a dekódolást, és a már módosított limitet visszaállítja. Az unlimited `memory_limit=-1` 128 MiB-os biztonságos decode-keretet kap. A forrás attachment végső symlinkje is elutasított.
- Új munkafa tesztek: URL parser által feloldott fixture fájl ellenőrzése; WebP cache újrahasználata; Imagick-limit fail-closed elvárások a jelenlegi CI-ben még nem izoláltan teszteltek. A további exact-head CI és független review szükséges.
- Új tesztek fedik az unsupported GIF-et, MIME-ütközést, hiányzó/sérült/truncated/oversized/offloaded fájlt, hiányzó URL-t, metadata nélküli de fájlból igazolható méretet, WebP eredeti fallbackot, symlink cache-t és quality-excluded full XML-t.
- A frissített regressziós fixture valódi 1200×630 WebP forrást és tartalomhash-alapú, érvényes 1200×630 JPEG cache-t használ; a tesztstub a PHP URL-component szabályát követi. Az Imagick resource-limit bármely hiányzó konstans, kivétel vagy sikertelen setter esetén visszaállítja a részben módosított korlátokat és a dekódolást elutasítja.
- A `95bbfbc` unit CI-je egy teszt-fixture hibát mutatott: a `7302` URL kisméretű/undersized képet jelölt, de a stub egy 1200×630 JPEG-et társított hozzá. A tesztstub most valódi 500×500 fixture-t ad az undersized URL-hez. A minőségi kizárás OFF állapotú próbája helyesen azt várja, hogy a kép a hard image validation miatt továbbra se kerüljön feedbe, és emiatt a stock snapshot exportálható tagsága változatlan marad. Külön, valid 7303 termék kézi kizárás → engedélyezés valódi membership-változást modellez; a stock-only frissítésnek blokkolnia kell és a korábbi fájlt megőriznie, majd az új full+stock párosításnak helyre kell állítania az egyezést.
- A `95bbfbc` HPOS és legacy integrációs jobjai a seed adatbázis 16×16-os PNG-képével hibáztak, mert az új 1200×630 minimum mellett a szintetikus katalógus invalid lett, így a korábbi teljes feed előállítása is elbukott. A teszt-only korrekció valós 1200×630 JPEG-et használ az integrációs termékekhez; a produkciós validálás nem lazult.
- Tesztfuttatás helyben **NOT RUN**: nincs PHP CLI, a Docker daemon nem hozzáférhető. A `95bbfbc` ZIP SHA-256 `ba416d839c9649eb8f6d32a9dde9bef528726cc68c5d708add47775c7b142881`; az artifactot a munkafában újraépítettem és `unzip -tq` PASS. A `95bbfbc` exact clean-reproducibility ellenőrzését el kell végezni, majd a jelenlegi test-only javításokat commit/push után exact-head CI-vel ellenőrizni kell.

### Visszaállítási pont

- Utolsó korábbi, CI-vel igazolt M9-E kódpont: `426b6bf124cb8006a8b1dbfcd5dd860df701e11f` (a jelenlegi nem publikált képfájl-javításokat nem tartalmazza).
- A módosítások a meglévő M9-E Draft PR branchben maradnak; a védett `main` és a korábbi Draft PR-lánc érintetlen.

## Következő végrehajtási kapu

Az M9-E ág legfrissebb HEAD-jére teljes CI, majd külön Integration QA és AppSec exact-head PASS szükséges. Csak ezek után jelölhető M9-E PASS-ként, és kezdhető a függő M9-F munka külön, egymásra épülő Draft PR-ben. Partnerátadás, staging telepítés és production továbbra is külön kapu.

### M9-E regresszió-helyreállítás — 2026-11-14

- A legutóbbi távoli PR HEAD `75175682b7b5215435c393775d88ae35ad2b66ef`; a PR nyitott és Draft. A pontos HEAD CI run `43569281437` **FAIL**: mind az öt PHP unit job és a lint/reproducible-package job PASS, a HPOS és legacy integráció FAIL (mindkettő 68 PASS / 15 FAIL), az opcionális RR20 SKIPPED. A teljes eredmény: [CI run](https://github.com/zsoltacsai/woocommerce-pepita/actions/runs/43569281437).
- A CI integrációs logja azt bizonyítja, hogy a full feed 0 exportálható termék miatt biztonságosan megszakadt, majd ebből következtek a hiányzó feed, stock és HTTP hibák. A log nem tárta fel, melyik konkrét termékvalidáció ürítette ki a feedet. Ezért a seed most ellenőrzi a képet a plugin validátorával, és szükség esetén csak hibaazonosítókat, képszámot és szintetikus termékazonosítót naplóz; érzékeny útvonalat vagy URL-t nem.
- A `46b85d3` pontos HEAD CI-je megmagyarázta a fixture-regressziót: az integrációs seed 1200×630 JPEG-je is `invalid_image_file` lett a szigorú dekódernél; a seed ettől most már azelőtt hibával áll meg, hogy üres feedet generálna. A régi 134 bájtos teszt-base64 ugyancsak EOI nélküli volt, ezért nem érvényes pozitív fixture. Mindkét pozitív fixture-t szabványos, teljes RGB JPEG váltotta fel; a csonkolt JPEG negatív eset külön megmarad. A következő CI-nek kell igazolnia, hogy a platform GD dekódere elfogadja a teljes fixture-t.
- A pontos HEAD AppSec áttekintése két MEDIUM hibát talált: az Imagick limitek lazulhattak egy már szigorúbb pozitív korláthoz képest, illetve a dekóder elfogadta a 134 bájtnál csonkolt, EOI nélküli JPEG-et. A review státusza **CHANGES_REQUIRED**, a jelentés hash-e nem áll rendelkezésre; ez még nem végleges QA/AppSec kapu.
- A `46b85d3` kódjavítása committed/pushed: a JPEG dekódolás megköveteli a záró `FF D9` EOI markert és a GD figyelmeztetésmentes dekódolását; az Imagick kért limitje nem haladhatja meg a már aktív pozitív limitet, a setter/readback és a limit-visszaállítás eredménye ellenőrzött, sikertelen helyreállítás esetén nincs elfogadott kép vagy származék. A teszt fedezi a dekódolható fejléc utáni JPEG-csonkolást és a limit setter/visszaállítás hibáit. A `46b85d3` unit futásban a további 277 teszt PASS, de a régi pozitív 1×1 base64 fixture tesztje FAIL lett; ezért teljes 1200×630 fixture-re cseréltem.
- Build ellenőrzés: a módosított csomag ZIP-je CRC-tesztje PASS, jelenlegi munkafa hash `d5f3e35df17dc84b4525ff3bea7ee3a861f1c48c9b8ea16da4ca2c206e01d728`; ezt csak munkafa-artifactként kell kezelni, nem CI-vel igazolt release-ként. Az előző artifact hash `7bcc3…` a következő implementációs módosítás előtt keletkezett, ezért már elavult. `git diff --check` PASS. PHP CLI hiányában a PHP szintaxis/unit tesztek helyben **NOT RUN**; Docker daemon nem elérhető.
- Az új fixture-javítás még nincs az exact CI-vel értékelve. A munkafa/ág PR #10-en marad Draft; a `46b85d3` workflow teljes eredménye FAIL, benne PHP 8.1–8.5 unit-fixture hiba és mindkét integrációs setup seed-validációs FAIL. A pozitív image fixture most javított, a negatív csonkolási és Imagick hibatesztek megmaradtak. M9-E **HOLD**, amíg az új pontos HEAD minden kötelező workflow feladatán át nem megy, majd friss, független QA és AppSec PASS nem érkezik.
- Aktuális revízió: `08428ef1459ea5b9cd680ceece7f6425400bf142` a két teljes RGB JPEG fixture-javítással. Exact-SHA CI run `43570819758` futásban van; ez váltja az előző, már lezárt és sikertelen `43570530292` run-t. A ZIP csomagkódja nem változott; clean rebuild öt időzónában ugyanazt a `d5f3e35df17dc84b4525ff3bea7ee3a861f1c48c9b8ea16da4ca2c206e01d728` SHA-256-ot adta.
- A `43570819758` CI a következő, ledger-frissítő dispatch indításakor concurrency miatt részben CANCELLED lett. A befejezett egységjobok 277/278 PASS-t mutattak; az egyetlen teszthiba az volt, hogy az új RGB JPEG-ben a korábban hardcoded 134 bájtos csonkolás a JPEG képfőcímke előtt ért véget. A teszt most dinamikusan, az SOS szegmens hossza alapján vágja el a fájlt: a képfejléc olvasható marad, de a képadat és EOI hiányzik. A teljes aktuális HEAD-re új CI szükséges.
- A `7b8ffa5` exact-head workflow `43570996875` teljes: mind az öt PHP unit mátrix **278 PASS / 0 FAIL / 0 SKIPPED**, lint és reprodukálható ZIP PASS, opcionális RR20 SKIPPED. A HPOS és legacy job **seed provisioning** során FAIL: a helper-validátor mindkét esetben `invalid_image_file`-t adott a teljes RGB JPEG-re, így feed teszt nem futott. A unit és lintereprodukálhatóság átment, de az integrációs M9-E kapu továbbra is HOLD.
- A következő integrációs seed-módosítás csak szintetikus tesztkörnyezetben ír ki dekóder-diagnosztikát: fájlméret, képfőcím/MIME, EOI jelenléte, GD elérhetőség, dekódolás eredménye, dimenzió és figyelmeztetésszám; útvonal, URL, titok és termékadat nincs a logban. A cél az érvényesnek tűnő JPEG tényleges elutasítási okának izolálása. A 7b8ffa5 feletti munkafa módosítások még nincsenek commitolva; az új exact-head CI szükséges, utána friss független review-k következnek.
- A legfrissebb lekérdezés szerint PR #10 továbbra is OPEN/Draft, HEAD `d8c318019da426f5d01613eb5d440830c58d5fdc`, base `0f0fe1c058090682f9507d06a9c1aa8116c46888`. A 7b8ffa5 commiton 16 perc után nem volt külön check; a később indított `43572477152` workflow exact `d8c3180`-ra lefutott és FAIL-lel zárult. A két integrációs provision job ugyanazt mutatta: 16 556 bájtos, 1200×630 JPEG, JPEG MIME, EOI jelen van, GD elérhető, teljes dekódolás 1200×630 dimenzióval és warning nélkül sikerül, de a plugin validátor `invalid_image_file` eredményt ad. A seed fájltartalom és a GD dekódolás nem tűnik a hibának; az előzetes memória-budget kapu a fő gyanúsított. A korábbi kód `memory_get_usage(true)`-t vonta le, ami a PHP által ténylegesen használt memória helyett a rendszerből lefoglalt allocator oldalakat is számolja, ezért indokolatlanul nullára vagy túl alacsonyra csökkentheti a dekóderkeretet. Ez a logokból levont következtetés, még nem célzottan mérve; a memory-safe korlátot nem távolítottam el.
- Javítás előkészítve az exact CI-FAIL alapján: a GD és Imagick dekóder-budget most a PHP aktív használatából (`memory_get_usage(false)`) számít, továbbra is legfeljebb 128 MiB-ra korlátozva és a 8 bájt/pixel képméret-kapun keresztül. Hozzáadtam determinisztikus keretszámítási tesztet és kiegészítettem a kizárólag izolált CI seed-hibánál megjelenő, titokmentes diagnosztikát a PHP memory limit, aktív és lefoglalt memória értékével. E módosítások helyi PHP és Docker hiányában még nem futottak; a helyi teszt **NOT RUN**, `git diff --check` PASS. A módosítások új exact-head CI-t igényelnek, jelen állapotukban nem bizonyítják a regresszió javulását.
- Az új, munkafában létrehozott ZIP SHA-256: `7eea712276d82c395ea1b0778b87a4d3bb361285f1d435cc0c5d8d941b71d2ef`; 61 fájl, `unzip -tq` PASS. Ezt a hash-t az exact HEAD clean rebuildjének reprodukálhatósági ellenőrzése még nem igazolta; nem kezelhető stagingre elfogadott kiadásként.

### M9-E kapuk zárása és M9-F indítása — 2026-11-14

- A `a4854cdf9b20bcd54b0180fb2cc31b4bfa97aab9` exact HEAD-en a CI `43573024669` SUCCESS lett. PHP 8.1–8.5 unit mátrix (279/job), lint és reprodukálható ZIP, HPOS (83) és legacy (83) integráció PASS; opcionális RR20 experimental SKIPPED.
- Külön exact-head Integration QA: PASS, Gemini 3.8 Flash (Google Antigravity, High), inline review jelentés, külön artifact/hash nélkül. A review nem állít FN-DECO staging elfogadást.
- Külön exact-head AppSec: PASS, Gemini 3.8 Flash (Google Antigravity, High), jelentés SHA-256 `95217ffd2ebfc9e33bbc9526007b17550206bf8d995ba07dbdb4025907de9057`. Az Imagick limitmonotonitást/helyreállítást és a csonkolt JPEG elutasítását újraellenőrizte; deployment-ready minősítést nem adott.
- Ez az M9-E repository-kód és szintetikus tesztkapu lezárása; nem bizonyít élő FN-DECO staging eredményt, partner-importot vagy valós 269→241 exportot. PR #10 továbbra is OPEN/Draft, nem történt merge vagy telepítés.
- Külön `feature/m9f-intelligent-catalog-quality` ág indult az M9-E HEAD-ről. Első feltárási megállapítás: `Product_Issues::query()` `limit => -1`-gyel behozza a teljes katalógust és szinkron ellenőrzi a termékeket, majd csak utána lapoz. Ezt M9-F-ben bounded vagy háttérben frissülő indexelésre kell cserélni, amely az elavultságot és a feldolgozás előrehaladását is jelzi. M9-F implementáció még nem kezdődött.
- M9-F első kódmunkája a meglévő issue registryből származó, választható belső 0–100 pontszámot és issue-kód alapú, nem mutáló javítási javaslatokat ad hozzá. A pontszám expliciten nem hivatalos Pepita rangsor, nem módosít termékadatot és nem írja felül a kötelező validációt; a SKU ajánlás marad opcionális, GTIN-hez valódi kódot kér, nem generál értéket. A képernyőn a javaslatok csak a már ismert, verifikált validációs problémákhoz jelennek meg.
- A pontszám ki-/bekapcsolható beállítást kapott (alapértelmezés: ki), az issue registry megjeleníti az ismert hibák teendőit, és settings/default/sanitization, képlet, duplikált issue-kód, opcionális SKU, eligibility-változatlanság tesztjei készültek. A teljes katalógus unbounded lekérdezését maximum 50 jelölt/HTTP kérésre és katalógusoldal alapú lapozásra cseréltem; a felület jelzi, hogy az issue-találat csak az éppen vizsgált oldalt fedi, a lista oldalak között nem snapshot-konzisztens. Teszt került a page-bound és total/scanned jelentésre. A jelen módosítás utáni csomagot és exact CI-t még újra kell építeni/futtatni. M9-F továbbra is PARTIAL: gyártó/márka/attribútum vizsgálat, újrafelhasználható mapping szabályok és háttérindex freshess állapottal még nincs.
- PR #11 exact `381bc68` CI: `43576696539` **SUCCESS** (PHP 8.1–8.5 unit 281/job; HPOS és legacy 83/job; lint és reproducible ZIP PASS; RR20 SKIPPED). Független exact-head Integration QA PASS (Gemini 3.8 Flash, inline jelentés) és AppSec PASS (OpenAI gpt-6-astra, inline jelentés) érkezett erre a commitra. AppSec jelezte a nagy page offset/query-count teljesítménykockázatot és azt, hogy meglévő képfallback WebP-származékot írhat a uploads dedikált könyvtárába. Követő dokumentáció pontosítja, hogy a termékadat-vizsgálat nem módosít termékeket, de nem teljesen fájlrendszer-read-only; a numeric keresés most explicit ID-keresés, teszttel. Ezek a követő módosítások új HEAD-et és új exact CI/review kapukat igényelnek.
- Az `1fa9703` követő exact-head AppSec újraellenőrzése BLOCKED/PENDING lett, mert a CI akkor még futott; külön jelezte, hogy a numerikus ID-keresés javítása mellett a pontos SKU keresésnek is garantáltnak és teszteltnek kell lennie. A következő patch ezt exact WooCommerce SKU-azonosító lekéréssel és SKU-teszttel kezeli; az admin mező és dokumentáció pontos SKU-keresést ígér. A legfrissebb commit után teljes CI és exact-head QA/AppSec szükséges.
- A végső `532b3db` exact HEAD-en a CI (`43578951699`) SUCCESS; Integration QA (Gemini 3.8 Flash) PASS és AppSec (OpenAI gpt-6-astra) PASS. A review-k csak repository/CI szeletre érvényesek, staginget nem igazolnak. ZIP SHA-256 `81be6d270831c052286af2aa3a2ec4453705fc3f7f08fb7f03652d4dda7a381c`. Elkészült a `docs/autonomous-development/PEPITA-HANDOFF.md` külön felelősnek történő átadáshoz; a leírás és a ledger commitja után exact HEAD/CI változhat.

### Felelősség-átvétel és M9-F kereséslezárás — 2026-11-14

- ACK: repository `zsoltacsai/woocommerce-pepita`, branch `neville/pepita-m9f-handoff`, kezdeti HEAD `ab0c1d574cb5bb546c76849e3265059672b1b38b`, tiszta worktree. A scope kizárólag repository-fejlesztés és izolált CI; deployment, live API, valódi rendelési adat, merge és WP CareGrid kizárt.
- A handoff exact CI run `43580024938` az `ab0c1d5` HEAD-en SUCCESS. A GitHub-ellenőrzéskor PR #10 OPEN/Draft, head `a4854cdf9b20bcd54b0180fb2cc31b4bfa97aab9`; PR #11 OPEN/Draft, head `ab0c1d574cb5bb546c76849e3265059672b1b38b` volt.
- PR #12 OPEN/Draft, base `feature/m9f-intelligent-catalog-quality`, head `3ea995fe35e3d6cadf2459c20845e2085ec6cba3`. Ezzel a #11 Draft változatlan marad.
- Új M9-F integrációs eset teszteli a terméknév-, pontos SKU- és numeric-ID-keresést, a variation SKU parent-sorra irányítását és a szélsőséges `page` korlátozását. A `43580414437` HPOS és legacy esetei PASS; ugyanazon futás package jobja a korábbi ZIP hash miatt FAIL lett. A forráskód ezen run és a következő exact CI között nem változott.
- A distribution artifact és release epoch frissítése után az exact `3ea995fe35e3d6cadf2459c20845e2085ec6cba3` CI `43580583517` SUCCESS lett: PHP 8.1–8.5 unit, lint/reproducible ZIP, HPOS és legacy PASS; RR20 SKIPPED. SHA-256: `441d74e6eaa1658b2ae5a08da2062c593ff5ae759fdc9f7b9252d7745d8c3926`; öt időzónás clean build egyezett.
- A quality query egy kérésben max. 50 terméket inspectál. A teljes WooCommerce találatszám COUNT-költsége nincs időkerettel korlátozva; 50-es oldalmérettel a page 1000 max. offsetje 49 950; gallery fallback egy terméknél több lokális képfeldolgozást is kiválthat. Ezt pontosan dokumentáljuk, de nagy katalógus load-test és async index nélkül nem tekintjük lezárt teljesítménykapunak.
- A Pepita dokumentáció szerint Brand, Manufacturer és Attributes opcionális; az attribútumokból tetszőleges számú küldhető, és a mező kereshetőséget segíthet. Nincs alap a kötelező márka/gyártó mező kitalálására. Új exact-head QA/AppSec review, valamint reusable mapping-szabályok még nyitottak.

### M9-F attribútumnév-megfeleltetés és kódzárás — 2026-11-14

- Implementálva: opcionális, újrahasználható attribútumnév-megfeleltetés a Seller Center hivatalos specifikációjához igazodva.
- Beállítás: `attribute_name_map` az admin felületen (soronként `WooCommerce név=Pepita név`, pl. `pa_color=Szín`), legfeljebb 100 szabállyal és 8 KiB méretkorláttal szanitizálva.
- Mapping logika: `Product_Mapper::attribute_name_map()` és `Product_Mapper::resolve_attribute_name()` a standard termékattribútumok (`attributes()`) és variációs tengelyek (`variation_axes()`) exportcímkéit írja felül a leképezett névvel, miközben a forrás WooCommerce termék-, taxonómia- és variációadatok változatlanok maradnak.
- Tesztek: `tests/cases/10-settings-test.php` ellenőrzi a szanitizálást és hibás sorok elhagyását; `tests/cases/60-mapper-test.php` fedi a taxonómia alapú (`pa_color`), egyéni szöveges (`Anyag`), unmapped (`Garancia`) attribútumokat és a variációs tengely (`pa_size`) átnevezését.
- Build és csomag: release epoch `1794651600`; az újraépített `dist/wp-pepita-sync.zip` SHA-256 értéke `87f81e4df21d83756efe266f7fabd19eb1b853b18d2396e6174be6c9846feae3` (61 fájl, CRC PASS).
- Exact CI run `43584004363` az `1bd6503c94892cfa76e4c25f44e13cfda85beae4` HEAD-en: **SUCCESS** (8/8 aktív job PASS; PHP 8.1–8.5 unit, lint és 5 időzónás reprodukálható ZIP, HPOS és legacy integráció; RR20 SKIPPED).

### M9-F független review-k, javítások és lezárás — 2026-11-14

- Független felülvizsgálatok az `59c7d40c3b5811c1d448ec39e30ea2cd55f7a0c5` HEAD-en:
  - **AppSec Review**: PASS (Claude Sonnet 5.5, high effort). Biztonsági kockázat, injection vagy adatmutáció nem található. Jelezte az F1 funkcionális hibát és az A2 statikus gyorsítótárazási optimalizációt.
  - **Integration QA Review**: FAIL a B1 funkcionális hiba miatt (Claude Opus 5.5, high effort). A felületen mentett `wps[attribute_name_map]` POST adat elveszett, mert hiányzott az `Admin::settings_input()` `$scalars` fehérlistájából.
- Javítások:
  - `includes/admin/class-admin.php`: hozzáadva `'attribute_name_map'` a mentési `$scalars` tömbhöz, így az admin felület mentése ténylegesen perzisztálja a leképezési szabályokat.
  - `includes/catalog/class-product-mapper.php`: statikus `$cache_map` és `$cache_raw` bevezetve, megszüntetve a redundáns parszolási költséget katalógus-generálás közben.
  - `tests/cases/10-settings-test.php`: új reflection teszt igazolja a `settings_input` POST mező mentését.
- Build és csomag: release epoch `1794655200`; az újraépített `dist/wp-pepita-sync.zip` SHA-256 értéke `3423bc2486cedee0804b96d20431db76f5ed704856788b726c222d666fbe9d78` (61 fájl, CRC PASS).
- Megerősítő exact CI run `43588942291` az `f80241820ad868340430e2c81c3b28fbbc6e6daa` HEAD-en: **SUCCESS** (8/8 aktív job PASS; PHP 8.1–8.5 unit 284/job, lint és 5 időzónás reprodukálható ZIP, HPOS és legacy integráció; RR20 SKIPPED).
- Független re-check Integration QA (Claude Opus 5.5, high effort): **PASS**, B1/F1 és A2 javítását igazolta.
- Ezzel az M9-F minden korábbi hiányossága és review-megállapítása javítva; az M9-F mérföldkő repository- és CI-kapui lezárva (PASS). Nincs staging vagy éles telepítés/merge.

### M9-G Feedvezérlés, Előnézet és Monitoring fejlesztés és lezárás — 2026-11-14

- **Implementáció és architektúra (M9-G.1–M9-G.4)**:
  - `Catalog_Diff` és `Catalog_Index` korlátos memóriájú streaming összehasonlítással (`includes/feed/class-catalog-diff.php`): nem tárol neveket és variációs fát a memóriában; `crc32` variációs identitás-ellenőrzőösszeget használ; a felületi mintákhoz (max. 50 elem) igény szerint fájlpozíció (`pos`) alapján olvassa a lemezről a termékneveket és cikkszámokat.
  - Kategóriaváltozás detektálás (`$prev_cat !== $curr_cat`) üres és hozzárendelt kategóriák közötti átmenetekre is.
  - Publikációs biztonsági kapu és jóváhagyási munkafolyamat (`Storage::stage_full()`, `approve_staged_full()`, `discard_staged_full()`):
    - Kockázatos eltérések vagy figyelmeztetések esetén (`feed_safety_require_approval`) a feed staged állapotba kerül, megőrizve az éles feed zavartalan kiszolgálását.
    - **Atomikus rollback-védelem:** A `publish_full()` a kizárólagos tárhely-zár (`acquire_lock()`) alatt ellenőrzi az elvárt bázisgenerációt (`expected_base_gen`), kiküszöbölve a versenyhelyzeteket.
    - A jóváhagyás azonnal készletfeed-újragenerálást ütemez (`Scheduler::queue(TYPE_STOCK)`).
    - Normál publikáció automatikusan eltakarítja az elavult staged feedeket.
  - Szigorúan SSRF-védett telemetria webhook (`wp_safe_remote_post`): RFC 1918, RFC 3927 (169.254/16), RFC 6598 (100.64/10), IPv6 loopback és belső tartományok tiltása; `reject_unsafe_urls => true`, `redirection => 0`, `blocking => false`; csak hosztnév naplózása, érzékeny tokenek kizárva. E-mail riasztás `wp_mail`-en keresztül.
  - Off-peak ütemezés (`offpeak_schedule_enabled`): ablakon kívüli teljes feed generálás elhalasztása és átütemezése; a folyamatban lévő kötegek (`wps_pepita_generation_full`) és a készletfeed korlátozásmentes futása.
  - Magyar adminisztrációs felület a Feeds és Settings oldalakon, `manage_woocommerce` jogosultság-ellenőrzéssel és CSRF token védelemmel (`_wpnonce`).
- **Build és reprodukálhatóság**:
  - Release epoch: `1794658800`.
  - Csomag: `dist/wp-pepita-sync.zip` (62 fájl), SHA-256: `a6d8d16a7a81edc50b1c6a308dced1d7556cdd2db8ce58d11ed98b913c94f0bb`.
  - 5 időzónás tiszta újraépítés (`verify-reproducible.sh`): **PASS** (100% determinisztikus és bitről-bitre egyező).
- **Független felülvizsgálatok**:
  - **AppSec Review**: **PASS** (Claude Sonnet 5.5, high effort). SSRF védelem, atomikus publikációs lock, CSRF és jogosultság-ellenőrzések igazolva, blokkoló hiba nincs.
  - **Integration QA Review**: **PASS** (OpenAI gpt-6.1-sol). Streaming memóriakezelés, rollback guard atomicitás, scheduler folytatás és tesztfedettség igazolva.
- **CI Ellenőrzés**:
  - Workflow run `43594104082` és `43594400155`: mind a 8 kötelező job **PASS** (lint, unit PHP 8.1–8.5 296/job, integration-hpos, integration-legacy; RR20 experimental skipped).
- **Státusz**: M9-G mérföldkő repository- és CI-kapui lezárva (**PASS**). PR #13 Draftként megőrizve a stackben; nincs merge vagy éles telepítés.
