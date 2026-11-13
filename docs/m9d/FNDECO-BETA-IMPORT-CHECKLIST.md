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
- [ ] Amennyiben az FN-DECO áruházban a termékek árai bruttó fogyasztói árakként vannak rögzítve (pl. 1300 Ft bruttó 27% áfával):
  * Állítsa a mezőt **"A forrásár már bruttó (nem adódik hozzá újra az áfa)"** értékre.
  * Ezzel elkerülhető a kettős adóztatás (hogy a feedbe 1651 Ft kerüljön).
- [ ] Mentse a beállításokat a lap alján.

---

## 3. Kategória engedélyezési lista (Category Allowlist) beállítása
- [ ] Nyissa meg a **Pepita -> Kategóriák** menüpontot.
- [ ] Jelölje be **"Felvétel a feedbe"** (include) státuszra azokat a kategóriákat, amelyeket a béta teszt során exportálni kíván (pl. 9897 "Termékek" és/vagy 9901 "Dísztárgyak").
- [ ] Minden olyan kategória, amely nincs kifejezetten felvéve, béta módban automatikusan kimarad a feedből.

---

## 4. Elsődleges kategória meghatározása
- [ ] A **Pepita -> Beállítások -> Katalógus** szekcióban ellenőrizze az **Elsődleges kategória kiválasztási stratégia** beállítást (alapértelmezett: "Legmélyebb kategória").
- [ ] Ha egy konkrét terméknél felül szeretné bírálni a kategóriát:
  * Nyissa meg a termék szerkesztési felületét.
  * A jobb oldali **Pepita piactér** dobozban a **Pepita elsődleges kategória** legördülő menüből válassza ki a kívánt kategóriát.
  * Mentse a terméket.

---

## 5. Előzetes ellenőrzés futtatása (Preflight Audit)
- [ ] Nyissa meg a **Pepita -> Feed ellenőrzés** menüpontot.
- [ ] Tekintse át az összesítő KPI mutatókat:
  * **Feedbe felvett (jogosult) termékek száma**: megfelel a jóváhagyott kategóriák termékeinek?
  * **Készletkorlátozások száma (999 999 cap)**: a rendszer automatikusan kezelte a kiugró készletértékeket.
  * **WebP képek száma**: a rendszer JPEG-re konvertálja a WebP formátumokat.
  * **Felbontási figyelmeztetések (< 300x300)**: ellenőrizze a túl kicsi képpel rendelkező termékeket.
- [ ] Szükség esetén kattintson a **"Teljes ellenőrzési lista letöltése (CSV)"** gombra, és vizsgálja meg az esetleges cikkszám- vagy képhiányokat.

---

## 6. Feed generálás és éles tesztelés
- [ ] Nyissa meg a **Pepita -> Feedek** menüpontot.
- [ ] Kattintson a **"Teljes feed generálása"** gombra.
- [ ] Várja meg a generálás befejeződését, majd ellenőrizze a fájlméretet és az exportált termékek számát.
- [ ] Másolja ki a generált biztonságos feed URL-t (`?wps_feed=full&token=...`).
- [ ] Nyissa meg a linket böngészőben vagy XML nézegetőben, és ellenőrizze:
  * Pontosan 1 `<Categories>` blokk szerepel-e a termékeknél.
  * A `<Price>` érték helyesen 1300 Ft-e (nem 1651 Ft).
  * A `<ProductNumber>` tartalmazza-e a WooCommerce SKU-t (pl. `HDS15-016`).
  * A képek elérhetők-e és `.jpg` kiterjesztésűek.

---

## 7. Biztonsági szabályok hétfői átadásra
1. **Éles üzemmód STRICT NO-GO:** A bővítményt éles áruházban aktiválni tilos az Owner külön jóváhagyásáig.
2. **Rendelés API inaktív:** Rendelést a Pepita rendszeréből a béta fázisban nem fogadunk.
3. **Kifelé irányuló hívások tiltása:** A plugin semmilyen automatikus hívást nem indít a Pepita API felé.
