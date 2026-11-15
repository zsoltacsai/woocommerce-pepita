# FN-DECO — RC2 staging preflight (közös MySQL-adatbázis)

> **Hozzáférési szabály (Owner döntés):** az AI-ügynökök semmilyen szerver-, SSH-, FTP-, adatbázis-, Redis- vagy WordPress-admin hozzáférést nem kapnak, és nem is kérnek. A telepítést és az ellenőrzéseket az Owner végzi. **Az Owner számára rövid, admin-felületes ellenőrzőlista: [FN-DECO-STAGING-MANUAL-INSTALL-HU.md](FN-DECO-STAGING-MANUAL-INSTALL-HU.md).** Ez a dokumentum a részletes, parancsos háttér (hosting-technikusnak vagy a tárhelyszolgáltatónak).

**Állapot: HOLD — a read-only bizonyítékok még nincsenek meg.** Ezt a dokumentumot a fejlesztő (Neville) készítette elő. A fejlesztői környezetből **nincs hozzáférés** az FN-DECO szerverhez, az adatbázishoz, a `wp-config.php`-hoz, a Redis-hez vagy a mentéshez, ezért a lenti 1–8. pont egyikét sem tudtam megerősíteni. Hozzáférést nem próbáltam szerezni, és az egyetlen helyben beállított SSH-hosthoz (`caregrid-staging`) nem nyúltam: az a WP CareGrid projekthez tartozik.

Amit a **kódból** bizonyítani tudtam (nem a szerverről):

| Állítás | Bizonyíték |
|---|---|
| A plugin minden táblanevet a `$wpdb->prefix`-ből képez; nincs beégetett `wp_` prefix és nincs `$wpdb->base_prefix` használat | `grep` az `includes/` alatt: nincs találat a `base_prefix`, `'wp_'` mintákra |
| A plugin nem hív `wp_cache_flush()`-t, így egy közös Redis más oldalának gyorsítótárát nem üríti | `grep` az `includes/` alatt: nincs találat |
| A plugin saját táblái: `{prefix}wps_pepita_orders`, `{prefix}wps_pepita_logs`; opciói `wps_pepita_*` | `includes/class-installer.php`, `uninstall.php` |
| A fájlok a staging `wp-content/uploads/wps-pepita-<kulcs>/` és `wps-derivatives/` alá kerülnek | `Storage::label_dir()`, `Image_Converter` |
| Order API alapból KI (503); a staging-feed-only beállítás a `WPS_PEPITA_BETA_FEED_ONLY` konstanssal kényszeríthető | RC2, `Settings::is_beta_feed_only()` |

A közös adatbázis miatt a fő kockázat nem a plugin kódja, hanem az, hogy a staging WordPress DB-felhasználója ugyanabban a sémában a production táblákat is eléri. Ezért az alábbi ellenőrzések kötelezők, mielőtt bármi települ.

---

## 1. Read-only staging ellenőrzések (parancsok és elfogadási feltétel)

Minden parancs csak olvas. Ahol jelszó vagy kulcs előfordulhat, a kimenetet **maszkolni** kell; titkot ne másoljon a jelentésbe. A `STG_` és `PROD_` jelölés a két táblaprefixet jelöli (a `wp-config.php` `$table_prefix` értéke).

### 1.1 Táblaprefix különbözik
```bash
grep -n '^\$table_prefix' /ELÉRÉSI/ÚT/staging/wp-config.php /ELÉRÉSI/ÚT/production/wp-config.php
```
**Elfogadás:** a két prefix különbözik, és **egyik sem a másik előtagja** (például a `wp_` és a `wp_stg_` nem elfogadható, mert a `wp_%` minta a staging táblákat is elkapná). Elfogadható például a `prd7_` és az `stg7_` pár; nem elfogadható a `wp_` és a `wp_stg_`.

### 1.2 Nincs keresztreferencia
```sql
-- Melyik prefixek léteznek a sémában (minden WordPress-telepítésnek van egy <prefix>options táblája)
SHOW TABLES LIKE '%options';
-- Idegen kulcsok (WordPress/WooCommerce normál esetben nem használ)
SELECT table_name, referenced_table_name FROM information_schema.key_column_usage
 WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL;
-- A staging oldal URL-je
SELECT option_name, option_value FROM STG_options WHERE option_name IN ('siteurl','home');
```
Ellenőrizze továbbá, hogy a staging `wp-config.php` **nem** definiálja ezeket: `CUSTOM_USER_TABLE`, `CUSTOM_USER_META_TABLE`, `WP_SITEURL`/`WP_HOME` production értékre, és hogy nincs multisite (`MULTISITE`, `WP_ALLOW_MULTISITE`).
**Elfogadás:** nincs idegen kulcs a két prefix között; a felhasználói táblák nincsenek megosztva (különben a staging admin műveletei production felhasználókat érinthetnek); `siteurl`/`home` a staging URL.

### 1.3 `wp-config.php` és fájlrendszer elkülönül
```bash
# Csak a nem titkos azonosítók (a jelszavak és sók NEM kerülnek a kimenetbe):
grep -nE "^define\( *'(DB_NAME|DB_USER|DB_HOST|WP_CONTENT_DIR|WP_PLUGIN_DIR|UPLOADS|WP_CACHE_KEY_SALT|WP_REDIS_PREFIX|WP_REDIS_DATABASE|DISABLE_WP_CRON|WP_ENVIRONMENT_TYPE|WPS_PEPITA_BETA_FEED_ONLY)'" staging/wp-config.php
realpath staging/wp-content staging/wp-content/uploads production/wp-content
stat -c '%U:%G %a %n' staging/wp-config.php production/wp-config.php
```
**Elfogadás:** külön `wp-config.php`, külön `wp-content` és `uploads` könyvtár (nem szimbolikus link a productionre), eltérő sók/kulcsok, `WP_ENVIRONMENT_TYPE=staging`.
**Közös DB-felhasználó kockázata:** ha a staging és a production **ugyanazt a DB-felhasználót** használja, a staging kód írhat a production táblákba. Ez nem blokkoló, ha a mentés bizonyítottan visszaállítható (1.5–1.6), de erősen javasolt külön DB-felhasználó, amely csak a staging táblákra kap jogot (táblaszintű `GRANT`), amint a tábla-lista rögzített.

### 1.4 Object cache / Redis nem ütközik
```bash
ls -l staging/wp-content/object-cache.php production/wp-content/object-cache.php 2>&1
grep -nE "WP_CACHE_KEY_SALT|WP_REDIS_PREFIX|WP_REDIS_DATABASE" staging/wp-config.php production/wp-config.php
# Redis, csak számolás (nem listáz értékeket):
redis-cli -n <STAGING_DB_INDEX> --scan --count 1000 | head -c 0; redis-cli -n <STAGING_DB_INDEX> dbsize
redis-cli -n <PRODUCTION_DB_INDEX> dbsize
```
**Elfogadás:** a két oldal eltérő `WP_CACHE_KEY_SALT`/`WP_REDIS_PREFIX` **és** lehetőleg eltérő Redis adatbázis-index vagy példány. Egy másik oldal `wp_cache_flush()` hívása közös, prefix nélküli Redis esetén a production gyorsítótárát is törölheti. Ha nincs perzisztens object cache (nincs `object-cache.php`), az elem **nem releváns**, ezt rögzíteni kell.

### 1.5 A mentés tartalmazza a szükséges staging táblákat és fájlokat
```bash
# SQL dump: a staging táblák megvannak-e (táblaszám, kulcsfontosságú táblák)
zcat backup.sql.gz | grep -c '^CREATE TABLE `STG_'
zcat backup.sql.gz | grep -E '^CREATE TABLE `STG_(posts|postmeta|options|usermeta|woocommerce_order_items|wc_product_meta_lookup|actionscheduler_actions)`'
# Fájlok:
tar -tzf backup-files.tar.gz | grep -E 'staging/(wp-config.php|wp-content/(plugins|themes|uploads|mu-plugins))' | head
sha256sum backup.sql.gz backup-files.tar.gz        # rögzítse a jelentésben
```
**Elfogadás:** a staging összes táblája (`STG_*`) és a staging fájlok (wp-config, plugins, themes, uploads, mu-plugins) szerepel; a mentés dátuma a telepítés előtti. **Próba-visszaállítás kötelező egy külön, ideiglenes sémába** (soha nem a közös adatbázisba): `CREATE DATABASE tmp_restore_check; … < backup.sql` majd táblaszám-összevetés, végül `DROP DATABASE tmp_restore_check`.

### 1.6 Szelektív staging-visszaállítás production adatvesztés nélkül
A visszaállítást **csak a staging prefixű táblákra** szabad végezni:
```bash
# 1) Csak a staging táblák kimentése (regexp pontos prefix-egyezéssel; az 1.1 szerinti nem-előtag prefix mellett biztonságos)
mysql -N -e "SELECT GROUP_CONCAT(table_name SEPARATOR ' ') FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name REGEXP '^STG_'" 
# 2) A kimenetet tábla-listaként add át a mysqldump-nak: mysqldump DB $(…) > staging-only.sql
# 3) Visszaállításkor csak a listában szereplő STG_ táblákat DROP-old és töltsd vissza (IDE SOHA nem kerül PROD_ tábla).
```
**Elfogadás:** a tábla-lista kézzel átnézve nem tartalmaz production táblát; a visszaállítási lépések leírva és egy másolaton kipróbálva. **Teljes adatbázis-visszaállítás tilos** (production adatvesztést okozna). A plugin-szintű rollback (4. fejezet) ezt a visszaállítást a legtöbb esetben nem teszi szükségessé.

### 1.7 Külső integrációk izolálhatók
```bash
wp --path=staging plugin list --status=active --fields=name,version          # read-only
wp --path=staging option get woocommerce_stripe_settings --format=json | jq '{enabled, testmode, has_live_publishable: ((.publishable_key // "")|startswith("pk_live")), has_live_secret: ((.secret_key // "")|startswith("sk_live"))}'
wp --path=staging db query "SELECT COUNT(*) AS active_webhooks FROM STG_wc_webhooks WHERE status='active'"
wp --path=staging option get blog_public
```
Ellenőrizendő még (kézzel, az admin felületen vagy konfigurációban, értékek nélkül): e-mail küldés letiltva vagy csapdába terelve (pl. `pre_wp_mail` mu-plugin, vagy SMTP-plugin teszt módban); számlázó (Számlázz.hu/Billingo stb.) **teszt/ki** módban; szállítás (GLS/MPL stb.) kikapcsolva; Stripe `testmode=true`, nincs `pk_live`/`sk_live` kulcs; élő webhookok a stagingen kikapcsolva vagy staging URL-re mutatnak; analitika/hirdetés-pixelek tiltva.
**Elfogadás:** egyetlen integráció sem rendelkezik élő kulccsal a stagingen, és a kimenő e-mail nem jut valódi címzetthez.

### 1.8 WP-Cron és Action Scheduler nem hajt végre éles műveletet
```bash
wp --path=staging config get DISABLE_WP_CRON 2>&1                       # elvárt: true
wp --path=staging cron event list --fields=hook,next_run_gmt --format=table
wp --path=staging db query "SELECT hook, status, COUNT(*) AS n FROM STG_actionscheduler_actions WHERE status IN ('pending','in-progress') GROUP BY hook, status ORDER BY n DESC LIMIT 30"
crontab -l 2>&1 | grep -i "staging\|wp-cron" || echo "nincs rendszer-cron a staginghez"
```
**Elfogadás:** átnézve nincs olyan ütemezett hook, amely külső szolgáltatást hív (fizetés, számlázás, szállítás, e-mail-kampány, szinkron). A Pepita plugin ütemezései (`wps_pepita_*`) feed-generálók, amelyek a staging feedet írják. Kivétel a feed-riasztás: ha az „e-mail értesítés” vagy a „webhook” riasztás be van kapcsolva (`feed_alert_email_enabled`, `feed_alert_webhook_enabled`), a plugin külső címre küldhet; ezeknek a stagingen **KI** kell lenniük (ellenőrizze a Beállítások képernyőn).

---

## 2. Feed-only biztonság (a telepítés része)

A `staging/wp-config.php`-ba, a `/* That's all, stop editing! */` sor **elé**:
```php
define( 'WPS_PEPITA_BETA_FEED_ONLY', true );
```
Hatása: az Order API 503 (minden kérés), `feed_public` kikapcsolva, és `default_included=false` — **csak a kifejezetten bekapcsolt kategóriák exportálódnak**. A valós termékszám ezért a kategória-beállításoktól függ; rögzítse a bekapcsolt kategóriákat az elfogadási jelentésben. A konstans elhagyása sem kapcsolja vissza az Order API-t (az RC2 a tárolt kapcsolót is KI-re írja). Élő Pepita-hívás és rendelésimport tilos.

---

## 3. Telepítési kapu (csak külön **staging GO** után)

Az RC2 telepítése **nem automatikus**. Az Owner külön staging GO-ját kell kérni, amikor az 1.1–1.8 pontok mindegyike **IGAZOLT**. Bármelyik bizonytalan pont = **HOLD**.

**Csomag ellenőrzése** (a telepítendő fájl az Owner által kapott `wp-pepita-sync.zip`):
```bash
sha256sum wp-pepita-sync.zip
# Elvárt (RC2, kód: 77c596eb46cedd5ef07cf977f78a2139a8f335c9):
# 8b8c8f7878771ee76bb75f9610e573ce9672315089d7e9bb38996216680c1f93
```
Ha az érték eltér, ne telepítse.

**Telepítés előtti állapotjelentés** (töltse ki és csatolja):

| Mező | Érték |
|---|---|
| Dátum/idő, végrehajtó | |
| Staging URL, `ABSPATH` | |
| Staging és production prefix | |
| WordPress / WooCommerce / PHP / MySQL verzió | |
| HPOS állapot (`woocommerce_custom_orders_table_enabled`) | |
| Aktív pluginek és verziók (lista) | |
| Meglévő Pepita-plugin verzió és `wps_pepita_*` opciók (érték nélkül, csak névlista) | |
| A mentés azonosítója, `sha256`, időpontja, próba-visszaállítás eredménye | |
| 1.1–1.8 pontok eredménye (IGAZOLT/HOLD) | |
| Termékszám a staging WooCommerce-ben (publikált) | |
| ZIP SHA-256 egyezés | |

**Izolált rollback (plugin-szint, production érintetlen):**
1. Plugin deaktiválása a stagingen, majd a plugin-könyvtár (`staging/wp-content/plugins/wp-pepita-sync`) eltávolítása.
2. Csak a staging táblák törlése: `STG_wps_pepita_orders`, `STG_wps_pepita_logs` (a pontos neveket előbb `SHOW TABLES LIKE 'STG\_wps\_pepita\_%'` listázza, **PROD_** tábla nem szerepelhet).
3. Csak a staging `STG_options` tábla `wps_pepita_%` opcióinak törlése (`DELETE FROM STG_options WHERE option_name LIKE 'wps\_pepita\_%'` — előbb `SELECT`-tel nézze át a sorokat).
4. A `staging/wp-content/uploads/wps-pepita-*` és `wps-derivatives/` könyvtárak eltávolítása.
5. A `WPS_PEPITA_BETA_FEED_ONLY` konstans törlése a `wp-config.php`-ból.
6. Ellenőrzés: `SHOW TABLES` a staging prefixen a telepítés előtti listával egyezik; a production táblák száma és checksumja nem változott.
Ha ez nem elég, a staging prefixű táblák szelektív visszaállítása (1.6) jön, **soha nem** a teljes adatbázis.

---

## 4. Telepítés utáni elfogadás (előkészítve, telepítés nélkül nem futtatható)

Az eredményeket táblázatban rögzítse; az alábbi „mért érték” mezőket a telepítés után kell kitölteni. A régi 269/241 szám **szintetikus**, nem hivatkozható.

| # | Ellenőrzés | Hogyan | Elvárás | Mért érték |
|---|---|---|---|---|
| 1 | Valós termékszámok és kizárások | *Pepita → Hibás termékek* és *Feed ellenőrzés* képernyők + CSV export | publikált / exportálható / kizárt darabszám és a kizárási okok rögzítve | |
| 2 | Teljes XML feed | feed letöltése a tokenes URL-ről: `curl -fsS -o full.xml '<teljes feed URL>'`, `xmllint --noout full.xml` | jól formált XML, névtér `https://pepita.hu/feed/1.0` | |
| 3 | Készletfeed | `curl -fsS -o stock.xml '<készlet feed URL>'` | letöltődik (ha 503/withheld: a teljes feed hiányzik vagy eltér) | |
| 4 | Teljes/készlet konzisztencia, azonosítók, GTIN, kategória, kép | `python3 -I bin/feed-acceptance-check.py full.xml stock.xml --staging-host fndeco.com/staging --json run1.json` | `RESULT: PASS`; azonos ID-halmaz, azonos mennyiség/ár, érvényes GTIN, minden terméknek van kategóriája, minden kép JPG/PNG | |
| 5 | Azonosító-stabilitás | második generálás után: `… --baseline run1.json` | egyetlen azonosító sem tűnik el vagy változik | |
| 6 | Kategórialeképezés (approved parent) | a kategóriabeállítások képernyő + a feed `<Categories>` szakaszának mintavétele 10 terméken | minden termék pontosan egy hierarchiában; a szülő-jóváhagyott kategória érvényesül | |
| 7 | Képek és WebP-konverzió | 10 WebP forrású termék: a feed `<Url>` JPEG/PNG származékra mutat; `curl -sI <url>` → `200`, `Content-Type: image/jpeg` | nincs `.webp` URL a feedben; a származék elérhető | |
| 8 | Staging és production URL megkülönböztetése | a script `staging_image_urls_products` mezője és `image_hosts` | a staging URL-ek azonosítva; ez a feed **nem küldhető Pepitának** | |
| 9 | Order API 503 | `curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' -d '{}' https://fndeco.com/staging/wp-json/wps/v1/pepita/order` | `503` (a staging saját végpontja, nem Pepita-hívás) | |
| 10 | Hibás termékek admin felülete | *Pepita → Hibás termékek* betöltődik hiba nélkül, szűrők és CSV | jelennek meg a kizárások okkal | |
| 11 | Feed publikációs biztonsági kapu | a staging beállításban `feed_safety_require_approval` BE; kis katalóguszsugorítás után új generálás | a feed „jóváhagyásra vár” állapotba kerül; jóváhagyás és elutasítás (rollback az előző feedre) működik | |
| 12 | Nincs élő Pepita-kapcsolat | a naplóban nincs kimenő Pepita-hívás; az Order API kapcsoló KI | igazolt | |

A telepítés utáni jelentésbe kerüljön: a valós termékszámok, a script kimenete, az URL-ek (staging/production megkülönböztetve), az eltérések és a nyitott pontok. Ha bármelyik blokkoló, a rollback (3. fejezet) a következő lépés.

---

## 5. Pontos javítási teendők a HOLD feloldásához

1. **Hozzáférés/bizonyíték:** az Owner vagy az FN-DECO üzemeltetője futtassa az 1.1–1.8 parancsokat, és küldje vissza a maszkolt kimenetet (titok nélkül), vagy adjon a fejlesztőnek *csak olvasási* hozzáférést a stagingre.
2. **Prefixek:** erősítse meg, hogy a staging és a production prefix különbözik, és egyik sem előtagja a másiknak (1.1).
3. **Felhasználói táblák:** erősítse meg, hogy nincs megosztott `users`/`usermeta` (`CUSTOM_USER_TABLE` nincs) (1.2).
4. **DB-felhasználó:** javasolt külön, csak a staging táblákra jogosult DB-felhasználó (1.3).
5. **Redis:** külön kulcs-só/prefix és adatbázis-index, vagy igazolt „nincs perzisztens object cache” (1.4).
6. **Mentés:** adja meg a mentés azonosítóját, `sha256`-ját és a külön sémába végzett próba-visszaállítás eredményét (1.5).
7. **Integrációk:** adjon listát az aktív fizetési, e-mail, számlázó és szállítási pluginekről és azok teszt/élő állapotáról (1.7).
8. **Cron:** `DISABLE_WP_CRON` állapota és az ütemezett hookok listája (1.8).
9. Ezek után kérje az Owner a **staging GO**-t; a telepítés csak azután történhet.

**Production fájlokat, adatokat és táblákat a fejlesztő semmilyen körülmények között nem módosít.**
