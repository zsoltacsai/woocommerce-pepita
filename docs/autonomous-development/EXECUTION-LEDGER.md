# Autonóm fejlesztési napló — WP Pepita Sync

**Állapot:** folyamatban
**Utolsó frissítés:** 2026-11-14
**Biztonsági határ:** kizárólag repository-fejlesztés és izolált CI; staging/production telepítés, éles rendelés/API és merge nincs engedélyezve.

## Összesített mérföldkő-státusz

| Mérföldkő | Státusz | Branch / HEAD | PR | CI | QA / AppSec | Blokkoló / következő feladat |
|---|---|---|---|---|---|---|
| M9-E | PARTIAL | `feature/m9e-image-fallback-product-issues` / `72668e193f3fd440c83bdb537c7283e9dce112a4` | [#10](https://github.com/zsoltacsai/woocommerce-pepita/pull/10), Draft | `43542471186` PASS ezen a HEAD-en; az új full/stock regressziós teszt még nincs lefuttatva | Független exact-head review még nincs | Új full/stock konzisztencia- és szabályváltási regressziós eset hozzáadása, friss CI és független review |
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

- Hozzáadva egy determinisztikus full/stock regressziós eset: a minőségi kizárás változása után stock-only publikálásnak meg kell tagadnia az eltérő termékkészletet, megőriznie az előző stock fájlt, majd új full és matching stock generálás után az ID-k, árak és készletek soronként egyeznek.
- Ez a módosítás a teljes CI és exact-head független review előtt még nem tekinthető ellenőrzöttnek.

### Visszaállítási pont

- Utolsó, CI-vel igazolt M9-E kódpont: `72668e193f3fd440c83bdb537c7283e9dce112a4`.
- A tesztváltozás elkülönített munkafája a meglévő M9-E feature branch. Sikertelen CI esetén a tesztcommit visszavonható új, nem force-pusholt revert commitként; a védett `main` és a korábbi Draft PR-lánc érintetlen.

## Következő végrehajtási kapu

Az M9-E ág legfrissebb HEAD-jére teljes CI, majd külön Integration QA és AppSec exact-head PASS szükséges. Csak ezek után jelölhető M9-E PASS-ként, és kezdhető a függő M9-F munka külön, egymásra épülő Draft PR-ben. Partnerátadás, staging telepítés és production továbbra is külön kapu.
