# M9-G — Feedvezérlés, Előnézet és Biztonságos Publikálás

**Státusz:** Tervezés és megvalósítás alatt  
**Alap:** M9-F lezárt HEAD (`310ca6ce14adbcf06b764ba880c44ec15b74f791`)
**Cél:** Publikálás előtti biztonsági kapu, részletes katalógus-differencia (Feed Preview), monitoring és riasztások, valamint off-peak ütemezés.

## 1. Részfeladatok és követelmények

### M9-G.1 — Feed előnézet és katalógus differencia (Catalog Diff)
- Bővített `Catalog_Diff` funkcionalitás streaming index olvasással:
  - Hozzáadott termékek (`added`)
  - Eltávolított termékek (`removed`)
  - Árváltozások (`price_changed`)
  - Készletváltozások (`stock_changed`: készleten/készlethiány, darabszám eltérés)
  - Kategóriaváltozások (`category_changed`)
  - Variáció-struktúra változások (`variation_changed`)
  - Minőségi kizárások miatti kiesések (`quality_excluded`)
  - Teljes hatásösszegzés (`overall_impact`: stabil / figyelmeztetés / kritikus / jóváhagyásra vár)
- Magyar nyelvű adminisztrációs felület az előnézethez és minták megtekintéséhez.
- Megőrzi az immutábilis generálási állapotfájlokat és a full/stock snapshot szinkront.

### M9-G.2 — Publikációs biztonsági kapu (Publication Safety Gate)
- Publikálás előtti ellenőrző mechanizmus:
  - Tömeges termékszám-csökkenés detektálása (`feed_safety_drop_threshold`)
  - Tömeges minőségi kizárások észlelése
  - Nem egyező full/stock tagság megállítása
  - Váratlan drasztikus árváltozások (pl. >50% tömeges áresés)
  - Sérült vagy hiányos generálási artifactok kiszűrése
- Kézi jóváhagyási folyamat (High-risk Manual Approval):
  - Kockázatos változások esetén a generált feed nem írja felül azonnal az éles feedet, hanem jóváhagyási várakozóba (`staged`) kerül.
  - Az adminisztrátor áttekintheti az eltéréseket, és jóváhagyhatja vagy elvetheti a publikálást.
  - Elvetés, lejárás vagy hiba esetén az utolsó ismert érvényes publikált feed marad kiszolgálva.

### M9-G.3 — Monitoring és értesítések
- Bővített Health és Scheduler ellenőrzések, feed frissességi állapot.
- Értesítési csatornák:
  - WordPress admin notice (kritikus állapotok, jóváhagyásra váró feedek)
  - Opcionális e-mail értesítés a megadott cím(ek)re
  - Opcionális biztonságos webhook riasztás (SSRF-védett, hitelesítő adat vagy ügyféladat nélkül)
- Duplikált értesítések elnyomása (rate limiting / transient alapú suppression).

### M9-G.4 — Ütemezés és off-peak ablak
- Opcionális éjszakai / terheléscsökkentett idősáv (off-peak window, pl. 01:00–05:00) a teljes feed újraépítéséhez.
- Action Scheduler és WP-Cron teljes kompatibilitás.
- Nem sérti a Pepita stock-snapshot követelményeket.

## 2. Biztonsági határok
- Nincs éles Pepita API hívás vagy rendelésfeldolgozás.
- Nincs staging vagy production telepítés.
- Webhook és külső hívások alapértelmezésben kikapcsolva, szigorú URL validációval (SSRF védelem, loopback és belső hálózati IP-k tiltása).
