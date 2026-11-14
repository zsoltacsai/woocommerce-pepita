# M9-F — Katalógusminőség: jelenlegi diagnosztikai pontszám

## Hatókör

Az opcionális pontszám a plugin már meglévő `Product_Issues` ellenőrzéseiből indul ki. Nem a Pepita rangsorolási mutatója, nem állítja, hogy minden kategóriaspecifikus követelményt lefed, és nem változtatja meg a feed-alkalmasságot vagy a kötelező validációt. A pontszám megjelenítése alapértelmezésben kikapcsolt; a WooCommerce-adatok javítása továbbra is kézi művelet.

Az ajánlások kizárólag a felismerhető issue-kódokhoz tartozó, nem mutáló teendők. GTIN/EAN/ISBN kódot a rendszer nem generál. A hiányzó SKU külön figyelmeztetés; önmagában nem zár ki terméket. A stock cap nem minőségi hiba.

## Belső pontszám képlete

Kezdőérték: 100. Minden egyedi issue-kód büntetése egyszer vonódik le; az eredmény minimuma 0. A duplikált issue-kód nem ad ismételt levonást. Ismeretlen blokkoló kód 10, ismeretlen nem blokkoló kód 2 pont levonást kap. Ez kizárólag belső diagnosztikai konvenció.

| Ismert issue-kód | Levonás |
|---|---:|
| `missing_product` | 100 |
| `image_dim`, `webp` | 25 |
| `invalid_image`, `missing_image` | 30 |
| `missing_name` | 15 |
| `missing_description` | 10 |
| `invalid_price` | 20 |
| `invalid_vat` | 15 |
| `missing_category` | 20 |
| `missing_gtin` | 15 |
| `missing_sku` (figyelmeztetés) | 3 |

A pontszám ne legyen feedvalidációs vagy termékkizárási döntés bemenete. A blokkoló issue-k és a kötelező feed-ellenőrzések maradnak a döntő kapuk.

## Még nem igazolt követelmények / folytatás

Márka, gyártó, attribútumok, szállítás és kategóriaspecifikus kötelező mezők csak ellenőrzött Pepita specifikációval tehetők blokkolóvá. A tárolt Seller Center spec a Brand és Manufacturer mezőket opcionálisnak mondja; az Attributes konténer opcionális, de attribútumokat javasol a termékek kereshetőségéhez. Ezért az attribútum-név megfeleltetés opcionális, és csak a feed kimeneti nevet módosítja: a beállításban soronként `WooCommerce név=Pepita név` adható meg (például `pa_color=Szín`), a termék attribútumértéke változatlan. Hibás sor kimarad, ugyanazon normalizált forrásnévhez az első szabály érvényesül. Márka/gyártó és attribútumhiány nem lesz blokkoló.

Az issue-admin lekérdezés maximum 50 terméket vizsgál kérésenként; a felület jelzi az aktuális oldalon ellenőrzött és a teljes keresésben szereplő termékek számát, és katalógusoldalakon lehet végiglapozni. A kért oldalszám maximum 1000. A név szerinti keresés a WooCommerce szöveges keresését használja, pontos SKU-keresés a WooCommerce SKU-indexét, kizárólag számjegyekből álló keresés pedig pontos termék-ID-t. A variáció pontos SKU-ja a quality centerben a szülőtermék sorát adja vissza. A termékjelöltek száma kérésenként korlátozott, de a WooCommerce teljes találatszámának lekérése nincs időkerettel korlátozva; az 1000. oldal 50-es oldalméretnél akár 49 950-es OFFSET-et jelent, a galéria-fallback pedig több képvalidációt futtathat termékenként. Ez nem háttérindex: az oldalak külön időpontban készülnek, ezért változó katalógus mellett nem tranzakciós snapshot. Nagy katalógusokhoz aszinkron index és terheléses költségmérés szükséges.

Az admin issue-vizsgálat nem módosít termékadatot, de a már meglévő képellenőrzés a WebP-hez JPEG-származékot generálhat vagy hibás gyorsítótárat cserélhet a védett `uploads/wps-derivatives/` könyvtárban. Emiatt a teljes vizsgálati útvonal nem fájlrendszer-szinten read-only; a felület kizárólag diagnosztikai, termékadatot nem javít automatikusan.
