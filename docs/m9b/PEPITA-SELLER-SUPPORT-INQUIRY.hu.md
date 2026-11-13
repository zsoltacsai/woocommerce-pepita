# Pepita partner megkeresés — TERVEZET (NEM ELKÜLDVE)

> **Állapot:** tervezet. Nem lett elküldve; a küldés a tulajdonos döntése. A levél nem tartalmaz API-kulcsot, jelszót vagy más titkot — ilyet ne is illesszen be.
> **Címzett:** seller.support@pepita.hu
> **Tárgy:** Technikai egyeztetés – WooCommerce integráció: sandbox, feedek, rendelés webhook, státuszok, ÁFA, csomagcímke

Tisztelt Pepita Seller Support!

[Cégnév / Eladói azonosító: ………] webáruházunkhoz WooCommerce-alapú integrációt készítünk (termék- és készletfeed kiadása, rendelésfogadó webhook). Éles kapcsolatot nem hoztunk létre; a fejlesztést zárt tesztkörnyezetben, szintetikus adatokkal próbáljuk. Kérjük, írásban erősítsék meg az alábbiakat.

**1. Sandbox / teszt környezet**
- Van-e hivatalos sandbox vagy tesztfiók eladóknak? Hogyan igényelhető, és milyen korlátai vannak (adatmegőrzés, rendelésgenerálás, feed-ellenőrzés)?
- A hozzáférési adatokat kérjük biztonságos csatornán, nem e-mail szövegében.

**2. Termékfeed és teljes készletfeed**
- Hivatalos, géppel olvasható séma (XSD/dokumentáció) elérhető-e? Jelenleg specifikáció alapú szerkezeti ellenőrzést végzünk, mert hivatalos XSD-t nem találtunk.
- A készletfeed mindig a teljes aktuális kínálat pillanatképe (azonosító, ár, készlet) legyen, vagy elfogadnak delta-feedet is? Mi teljes pillanatképet adunk.
- Milyen gyakran olvassák be a feedeket, mi a javasolt frissítési gyakoriság, és hogyan jelzik a feldolgozási hibákat?
- Eltűnt termék kezelése: a nem szereplő termék automatikusan kikerül-e az ajánlatból?

**3. Hitelesítés**
- A rendelés webhook hitelesítése hogyan történik? Jelenleg megosztott API-kulcsot fogadunk (lekérdezési paraméter, `X-WPS-Api-Key` vagy `X-Api-Key` fejléc), összehasonlítás időzítés-biztos módon. Támogatnak-e kérés-aláírást (HMAC), fix forrás-IP címtartományt, vagy mTLS-t? Mely IP-címekről érkeznek a hívások?
- A feed lekéréséhez milyen hitelesítést alkalmaznak (token az URL-ben, fejléc)?

**4. Rendelés webhook szerződés és válaszok**
- Kérjük a hivatalos JSON mezőlistát és példa-payloadot (kötelező/opcionális mezők, pénznem, dátumformátum).
- Sikeres feldolgozás esetén jelenleg ilyen választ adunk: `{"isError":false,"responseCode":200,"message":"…","orderId":<szám>}`. Ez megfelel-e az elvárt formátumnak? Mit értékelnek sikernek (csak HTTP 200, vagy a törzs is számít)?
- Hibaválaszok: 401 (hibás kulcs), 422 (érvénytelen adat), 429, 503 – hogyan kezeli ezeket a Pepita?

**5. Ismételt kézbesítés és HTTP 409**
- Milyen ütemezéssel és hányszor ismétlik a sikertelen webhook-hívást? Meddig tart az újrapróbálkozás?
- Azonos azonosítójú és azonos tartalmú ismétlésre 200-at adunk (a meglévő rendelés azonosítójával). Eltérő tartalmú, azonos azonosítójú értesítésre 409-et adunk, és a rendelést nem módosítjuk. Átmeneti, feldolgozás alatti állapotban szintén 409-et adunk, ez *újrapróbálandó*.
- Hogyan kezeli a Pepita a 409-et: újrapróbálja, véglegesen hibásnak tekinti, vagy emberi beavatkozást kér? Különbséget tesz-e a két 409 jelentés között (újrapróbálandó / végleges konfliktus)?
- Az azonosító stabil marad-e újrakézbesítéskor? Változhat-e a payload ugyanazon azonosítóval (pl. státusz- vagy címmódosítás)?

**6. Rendelés életciklus**
- Mely státuszok léteznek (új, visszaigazolt, lemondott, visszaküldött, teljesített), és ezek hogyan jutnak el hozzánk (új webhook ugyanazon azonosítóval, külön végpont)?
- Van-e API a mi oldalunkról a státusz visszajelzésére (kiszállítva, lemondva, részleges teljesítés)? Ha igen, kérjük a dokumentációját.
- Lemondás/visszatérítés esetén mi a várt eljárás?

**7. ÁFA és adómentes tételek**
- A payloadban az ár bruttó vagy nettó? Az ÁFA-kulcsot százalékban vagy összegben küldik (27%, 18%, 5%, 0%)?
- Adómentes (pl. alanyi adómentes vagy tárgyi adómentes) tételek hogyan szerepelnek a payloadban (0% ÁFA, külön jelölő, mentességi kód)? A szállítási díj ÁFA-ja külön szerepel-e?
- Kedvezmények/kuponok elosztása tételenként vagy rendelés szinten érkezik?

**8. Csomagcímke és követés**
- A csomagcímke hogyan érkezik (base64 PDF a payloadban, letöltési URL, külön API)? Méret- és formátumkorlát? Meddig érvényes?
- Ki állítja elő a címkét, és ki választ futárt? A csomagkövetési szám visszajelzésére van-e végpont és kötelező formátum?

Köszönjük a segítségüket. Kérjük, az egyes pontokra számozva válaszoljanak, és jelezzék a dokumentáció elérhetőségét is.

Üdvözlettel,
[Név, beosztás, cég, telefonszám]
