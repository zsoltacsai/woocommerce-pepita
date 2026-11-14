# M9-G — Feedvezérlés, Előnézet és Biztonságos Publikálás

**Státusz:** LEZÁRVA (PASS)  
**Alap:** M9-F lezárt HEAD (`f80241820ad868340430e2c81c3b28fbbc6e6daa`, PR #12)  
**Ág:** `feature/m9g-feed-control-preview-monitoring` (PR #13, Draft)  
**Cél:** Publikálás előtti biztonsági kapu, részletes katalógus-differencia (Feed Preview), monitoring és riasztások, valamint off-peak ütemezés.

## 1. Részfeladatok és megvalósítás

### M9-G.1 — Feed előnézet és katalógus differencia (Catalog Diff)
- Bővített `Catalog_Diff` funkcionalitás korlátos memóriájú streaming index összehasonlítással:
  - Nem tárol termékneveket vagy beágyazott variációs struktúrákat a memóriában.
  - A variációs identitást determinisztikus `crc32` ellenőrzőösszeg alapján hasonlítja össze.
  - A felületen megjelenő mintákhoz (maximum 50 elem) a fájlpozíció (`pos`) alapján igény szerint (`fseek`) olvassa be a nevet és cikkszámot a lemezről.
  - Statisztikák: hozzáadott (`added`), eltávolított (`removed`), árváltozások (`price_changed`), készletváltozások (`stock_changed`: elérhetőség és mennyiség), kategóriaváltozások (`category_changed`, üres/nem-üres átmenetet is kezelve), variáció-változások (`variation_changed`), minőségi kizárások (`quality_excluded`), áfa/pénznem változások (`tax_currency_changed`).
  - Összesített hatás (`overall_impact`): `safe`, `warn`, `critical`, `approval_required`.
- Magyar nyelvű adminisztrációs felület az előnézethez és a minták lenyitható megtekintéséhez.

### M9-G.2 — Publikációs biztonsági kapu (Publication Safety Gate)
- Kézi jóváhagyási folyamat (High-risk Manual Approval):
  - Kockázatos változások vagy figyelmeztetések esetén (`feed_safety_require_approval` aktív állapotban) a generált feed nem írja felül azonnal az éles feedet, hanem jóváhagyási várakozóba (`staged`) kerül (`Storage::stage_full()`).
  - Az adminisztrátor a Feeds menüpontban áttekintheti az eltéréseket, és jóváhagyhatja (`wps_pepita_approve_staged_feed`) vagy elvetheti (`wps_pepita_reject_staged_feed`) a publikálást.
  - **Atomikus rollback-védelem:** A jóváhagyás során a `publish_full()` a kizárólagos tárhely-zár (`acquire_lock()`) birtokában ellenőrzi, hogy a kiindulási generáció (`expected_base_gen`) egyezik-e a jelenleg aktív generációval; időközben publikált újabb verzió esetén a rollback biztonságosan megtagadásra kerül.
  - A jóváhagyás automatikusan ütemezi a készletfeed frissítését (`Scheduler::queue(Generator::TYPE_STOCK)`), garantálva a full és stock feed szinkronját.
  - Normál új generálás sikere esetén a feleslegessé vált staged fájlok automatikusan törlődnek.

### M9-G.3 — Monitoring és értesítések
- Értesítési csatornák:
  - WordPress admin notice figyelmeztetés jóváhagyásra váró feed észlelésekor.
  - Opcionális e-mail riasztás (`wp_mail`) beállított címre vagy az adminisztrátornak (`get_bloginfo('admin_email')`).
  - Opcionális szigorúan SSRF-védett webhook telemetria (`wp_safe_remote_post`):
    - RFC 1918, RFC 3927 (169.254/16), RFC 6598 (100.64/10), IPv6 loopback és privát tartományok, valamint nem-standard sémák és felhasználói hitelesítő adatok azonnali tiltása.
    - `reject_unsafe_urls => true`, `redirection => 0`, `blocking => false`.
    - Naplózásban csak a biztonságos hosztnév szerepel, titkos tokenek vagy érzékeny adatok soha.
  - 1 órás transient alapú elnyomás a duplikált riasztások megelőzésére.

### M9-G.4 — Ütemezés és off-peak ablak
- Opcionális terheléscsökkentett idősáv (`offpeak_schedule_enabled`, `offpeak_start_hour`, `offpeak_end_hour`) a teljes feed generálásához.
- Ablakon kívüli indításkor elhalasztja és a következő ablak kezdetére ütemezi az eseményt.
- A már folyamatban lévő kötegelt folytatásokat (`wps_pepita_generation_full`) és a készletfeedet nem korlátozza.

## 2. Biztonsági garanciák és határok
- Nincs éles Pepita API hívás vagy rendelésfeldolgozás.
- Nincs staging vagy production telepítés.
- Webhook hívások szigorúan SSRF-korlátozottak és alapértelmezésben inaktívak.
- Minden adminisztrátori művelet `manage_woocommerce` jogosultságot és `_wpnonce` CSRF tokent követel meg.
