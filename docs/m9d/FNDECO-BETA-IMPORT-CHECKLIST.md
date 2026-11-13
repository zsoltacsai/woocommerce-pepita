# WP Pepita Sync — FN-DECO Béta Import Ellenőrzőlista (Checklist)

**Cél:** Útmutató az FN-DECO kereskedő és az áruház üzemeltetői számára a béta feed sikeres beüzemeléséhez és ellenőrzéséhez.  
**Céldátum:** 2026. november 16. (Hétfő)  
**Környezet:** Staging (`https://fndeco.com/staging/`)  
**Státusz:** **Béta Feed-Only (Éles rendelésfeldolgozás: STRICT NO-GO)**

---

## 1. Telepítés és béta üzemmód ellenőrzése
- [ ] Töltse le a legfrissebb reprodukálható ZIP csomagot (`wp-pepita-sync.zip`).
- [ ] Telepítse és aktiválja a plugint a WordPress admin felületén (**Bővítmények -> Új hozzáadása -> Bővítmény feltöltése**).
- [ ] Nyissa meg a **Pepita -> Beállítások** menüpontot.
- [ ] Ellenőrizze, hogy a **Béta üzemmód (Feed-only)** jelölőnégyzet be van-e kapcsolva:
  * Ebben az üzemmódban a Rendelés API szigorúan inaktív (minden bejövő kérés HTTP 503 hibát kap).
  * A feed publikus hozzáférése le van tiltva (kizárólag a biztonsági tokennel érhető el).
  * Az általános termékfelvétel inaktív: kizárólag a jóváhagyott kategóriák termékei kerülhetnek a feedbe.

---

## 2. Ár és ÁFA forrásbeállítás (`price_tax_mode`)
- [ ] A **Pepita -> Beállítások -> Ár és áfa** szekcióban keresse meg a **WooCommerce termékárak értelmezése** mezőt.
- [ ] Az FN-DECO áruházban a termékek árai bruttó fogyasztói árakként vannak rögzítve (pl. 1300 Ft bruttó 27% áfával). A kereskedő ÁFA-alany (nem áfamentes):
  * Állítsa a mezőt **"A forrásár már bruttó (nem adódik hozzá újra az áfa)"** értékre.
  * Ezzel elkerülhető a kettős adóztatás (hogy a feedbe 1651 Ft kerüljön).
  * A feedben a `<Price>1300</Price>` és `<VatPercent>27</VatPercent>` értékek fognak szerepelni.
- [ ] Mentse a beállításokat a lap alján.

---

## 3. Kategória engedélyezési lista (Category Allowlist) beállítása
- [ ] Nyissa meg a **Pepita -> Kategóriák** menüpontot.
- [ ] Jelölje be **"Felvétel a feedbe"** (include) státuszra azokat a kategóriákat, amelyeket a béta teszt során exportálni kíván (pl. 87 "Webáruház" és 111 "Stencilek").
- [ ] Minden olyan kategória, amely nincs kifejezetten felvéve, béta módban automatikusan kimarad a feedből.

---

## 4. Elsődleges kategória meghatározása
- [ ] A **Pepita -> Beállítások -> Katalógus** szekcióban ellenőrizze az **Elsődleges kategória kiválasztási stratégia** beállítást (alapértelmezett: "Legmélyebb kategória").
- [ ] Ha egy konkrét terméknél felül szeretné bírálni a kategóriát (pl. #9897 "Stencil - Hópehely"):
  * Nyissa meg a termék szerkesztési felületét.
  * A jobb oldali **Pepita piactér** dobozban a **Pepita elsődleges kategória** legördülő menüből válassza ki a kívánt kategóriát (pl. 111 "Stencilek").
  * Mentse a terméket.
  * A feedben pontosan egy `<Categories>` hierarchia jelenik meg: `87 (Webáruház) -> 111 (Stencilek)`.

---

## 5. Előzetes ellenőrzés futtatása (Preflight Audit)
- [ ] Nyissa meg a **Pepita -> Feed ellenőrzés** menüpontot.
- [ ] Tekintse át az összesítő KPI mutatókat:
  * **Feedbe felvett (jogosult) termékek száma**: megfelel a jóváhagyott kategóriák termékeinek?
  * **Készletkorlátozások száma (999 999 cap)**: a rendszer automatikusan kezelte a kiugró készletértékeket.
  * **WebP képek száma**: a rendszer JPEG-re konvertálja a WebP formátumokat (`wps-derivatives`).
  * **Felbontási figyelmeztetések (< 1200x630)**: ellenőrizze a Pepita Seller Center elvárásnál kisebb képpel rendelkező termékeket (ezek nem blokkolják a feed exportot).
- [ ] Szükség esetén kattintson a **"Teljes ellenőrzési lista letöltése (CSV)"** gombra, és vizsgálja meg az esetleges cikkszám- vagy képhiányokat.

---

## 6. Feed generálás és éles tesztelés
- [ ] Nyissa meg a **Pepita -> Feedek** menüpontot.
- [ ] Kattintson a **"Teljes feed generálása"** gombra, majd azt követően a **"Készlet feed generálása"** gombra.
- [ ] Várja meg a generálás befejeződését, majd ellenőrizze a fájlméretet és az exportált termékek számát.
- [ ] Másolja ki a generált biztonságos feed URL-t (`?wps_feed=full&token=...`).
- [ ] Nyissa meg a linket böngészőben vagy XML nézegetőben, és ellenőrizze:
  * Pontosan 1 `<Categories>` blokk szerepel-e a termékeknél (pl. #9897 terméknél: `87 -> 111`).
  * A `<Price>` érték helyesen 1300 Ft-e (nem 1651 Ft), a `<VatPercent>` 27.
  * A `<ProductNumber>` tartalmazza-e a WooCommerce natív cikkszámot (pl. `HDS15-016`).
  * A `<Quantity>` értéke 999846 (nem haladja meg a 999 999 limitet).
  * A képek elérhetők-e nyilvános HTTPS-en és `.jpg` kiterjesztésűek.
- [ ] **Token rotáció:** A külső partneri átadás előtt forgassa le a feed biztonsági tokent a **Pepita -> Beállítások -> Feed hozzáférési token** újragenerálásával.

---

## 7. Biztonsági szabályok hétfői átadásra
1. **Éles üzemmód STRICT NO-GO:** A bővítményt éles áruházban aktiválni tilos az Owner külön jóváhagyásáig.
2. **Rendelés API inaktív:** Rendelést a Pepita rendszeréből a béta fázisban nem fogadunk.
3. **Kifelé irányuló hívások tiltása:** A plugin semmilyen automatikus hívást nem indít a Pepita API felé.
