# Autonóm fejlesztési napló — WP Pepita Sync

**Állapot:** folyamatban
**Utolsó frissítés:** 2026-11-14
**Biztonsági határ:** kizárólag repository-fejlesztés és izolált CI; staging/production telepítés, éles rendelés/API és merge nincs engedélyezve.

## Összesített mérföldkő-státusz

| Mérföldkő | Státusz | Branch / HEAD | PR | CI | QA / AppSec | Blokkoló / következő feladat |
|---|---|---|---|---|---|---|
| M9-E | PARTIAL | `feature/m9e-image-fallback-product-issues` / `ad31983ca05d3b5171d88d4bc4871b53d1993ae6` + helyi javítás | [#10](https://github.com/zsoltacsai/woocommerce-pepita/pull/10), Draft | `43567433296` exact `ad31983` HEAD-en **FAIL** (PHP syntax parse error; az erre épülő unit és integration hibák is); új javító commitra CI szükséges | QA exact `ad31983`: PASS; AppSec exact `ad31983`: CHANGES_REQUIRED (Imagick névtér szintaxis és erőforráskorlát); a hibák helyi javítása után új exact-head review kell | Szintaxis/resource és meglévő URL-teszt regresszió javítása, ZIP/CI, új QA és AppSec |
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
- A `43567433296` naplója a `ad31983`-on PHP parse errort igazolt az Imagick névtér kétszeres backslash-e miatt; a unit/integration hibák ennek következményei voltak. Ugyanezen futás a validator régi `.webp`-elfogadási tesztjét is hibásnak jelezte; a várt szerződés most JPEG/PNG, ezért a tesztet ennek megfelelően frissítettem.
- Javító munkafa: Imagick szintaxis helyesbítése, 32 MiB fájlméretkorlát, pixelszám-alapú dekódolási keret, Imagick memória/map/disk/area/thread limit és multi-frame elutasítás; a regressziós tesztek ezeket is fedik.
- Új tesztek fedik az unsupported GIF-et, MIME-ütközést, hiányzó/sérült/truncated/oversized/offloaded fájlt, hiányzó URL-t, metadata nélküli de fájlból igazolható méretet, WebP eredeti fallbackot, symlink cache-t és quality-excluded full XML-t.
- Tesztfuttatás helyben **NOT RUN**: a környezetben nincs PHP CLI, a Docker daemon nem hozzáférhető. `git diff --check` és reprodukálható ZIP PASS a korábbi fájlállapotra, de az Imagick hardening és új validator teszt után új csomag és exact-HEAD CI szükséges.

### Visszaállítási pont

- Utolsó korábbi, CI-vel igazolt M9-E kódpont: `426b6bf124cb8006a8b1dbfcd5dd860df701e11f` (a jelenlegi nem publikált képfájl-javításokat nem tartalmazza).
- A módosítások a meglévő M9-E Draft PR branchben maradnak; a védett `main` és a korábbi Draft PR-lánc érintetlen.

## Következő végrehajtási kapu

Az M9-E ág legfrissebb HEAD-jére teljes CI, majd külön Integration QA és AppSec exact-head PASS szükséges. Csak ezek után jelölhető M9-E PASS-ként, és kezdhető a függő M9-F munka külön, egymásra épülő Draft PR-ben. Partnerátadás, staging telepítés és production továbbra is külön kapu.
