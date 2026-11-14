# Autonóm fejlesztési napló — WP Pepita Sync

**Állapot:** folyamatban
**Utolsó frissítés:** 2026-11-14
**Biztonsági határ:** kizárólag repository-fejlesztés és izolált CI; staging/production telepítés, éles rendelés/API és merge nincs engedélyezve.

## Összesített mérföldkő-státusz

| Mérföldkő | Státusz | Branch / HEAD | PR | CI | QA / AppSec | Blokkoló / következő feladat |
|---|---|---|---|---|---|---|
| M9-E | PARTIAL | `feature/m9e-image-fallback-product-issues` / `95bbfbc` + következő teszt-fixture javítások | [#10](https://github.com/zsoltacsai/woocommerce-pepita/pull/10), Draft | `43568016659` exact `d3e805f`: **FAIL** (lint + ZIP PASS; unit, HPOS, legacy FAIL; RR20 SKIPPED). `43568716180` exact `95bbfbc`: **FAIL** (lint PASS; PHP 8.1–8.5 unit FAIL: 276 PASS / 1 FAIL PHP 8.1-en; HPOS és legacy FAIL; RR20 SKIPPED). | QA és AppSec exact `d3e805f`: CHANGES_REQUIRED. A `95bbfbc` új reviewi elindultak, de CI újabb regressziót talált, ezért ez a HEAD még nem végső review-cél. | A helyes kis-képméretű unit- és integrációs fixture-ek, friss exact CI, aztán új független QA és AppSec |
| M9-F | HOLD | — | — | — | — | M9-E elfogadási kapui után indul |
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
- A pontos HEAD AppSec áttekintése két MEDIUM hibát talált: az Imagick limitek lazulhattak egy már szigorúbb pozitív korláthoz képest, illetve a dekóder elfogadta a 134 bájtnál csonkolt, EOI nélküli JPEG-et. A review státusza **CHANGES_REQUIRED**, a jelentés hash-e nem áll rendelkezésre; ez még nem végleges QA/AppSec kapu.
- Javítási munkafa (még nem commit/push): a JPEG dekódolás most megköveteli a záró `FF D9` EOI markert és a GD figyelmeztetésmentes dekódolását; az Imagick kért limitje nem haladhatja meg a már aktív pozitív limitet, a setter/readback és a limit-visszaállítás eredménye ellenőrzött, sikertelen helyreállítás esetén nincs elfogadott kép vagy származék. A teszt fedezi a dekódolható fejléc utáni JPEG-csonkolást és a limit setter/visszaállítás hibáit.
- Build ellenőrzés: a módosított csomag ZIP-je CRC-tesztje PASS, jelenlegi munkafa hash `d5f3e35df17dc84b4525ff3bea7ee3a861f1c48c9b8ea16da4ca2c206e01d728`; ezt csak munkafa-artifactként kell kezelni, nem CI-vel igazolt release-ként. Az előző artifact hash `7bcc3…` a következő implementációs módosítás előtt keletkezett, ezért már elavult. `git diff --check` PASS. PHP CLI hiányában a PHP szintaxis/unit tesztek helyben **NOT RUN**; Docker daemon nem elérhető.
- Aktuális munkafa módosításai: `class-image-converter.php`, M9-E regressziós teszt, integrációs seed diagnosztika, `bin/release-epoch`, ZIP és checksum. Minden új javítás és diagnosztikai eredmény a következő commit/push és exact-HEAD CI után értékelhető. M9-E **HOLD**, mert sem az új kód, sem a friss integrációs ok még nincs CI-vel megerősítve.
