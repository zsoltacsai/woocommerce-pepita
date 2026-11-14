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

Márka, gyártó, attribútumok, szállítás és kategóriaspecifikus kötelező mezők csak ellenőrzött Pepita specifikációval tehetők blokkolóvá. A teljes katalógust szinkron végigjáró admin issue-lekérdezés még nem skálázható; M9-F további munkája ezt háttérben frissülő vagy bounded indexelésre cseréli, az index frissességének és előrehaladásának egyértelmű jelzésével.
