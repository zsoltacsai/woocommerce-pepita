# Isolated Staging — Deployment Plan (PROPOSAL, nothing deployed)

**Candidate:** `2a09c76850fb47090b70a693339cbf5e3dbf515b`
**Production:** STRICT NO-GO. **No deployment may start without the owner authorization in §10.**

## 1. Option decision

| | A. Disposable local Docker | B. Dedicated staging server |
|---|---|---|
| Exists today? | Docker 29.9.2 / Compose 5.6.1 installed on the author workstation, daemon **inactive**, socket `root:docker`, user not in `docker`, no passwordless sudo → **not usable until authorized** | **None identified.** No dedicated, owner-authorized staging host for this project |
| `stg1.wpcaregrid.com` | — | **Excluded.** Belongs to a different project (`wpcaregrid`), not authorized; not contacted, not touched |
| Proves | Behaviour on real WP/WC/MySQL, true two-process concurrency, HPOS + legacy, package install/uninstall | Additionally: real TLS termination, reverse proxy, real mail trap, real cron, host-level egress control |
| Cost | €0 (hardware on hand) | ≈ €5–12/month for a small VPS (see §9) |

**Recommendation:** do **A first** (unblocks ST re-execution and RR20 immediately, zero cost, lowest risk), then **B** only after A passes and only for the environmental items A cannot prove (TLS, proxy, mail, cron, egress). Local Docker results must never be reported as separate-server results.

## 2. Host / container requirements

* **A:** Linux workstation, Docker Engine ≥ 29 + Compose v2, ≥ 4 CPU, ≥ 4 GB free RAM, ≥ 10 GB disk, ports bound to `127.0.0.1` only (already the case in `integration/docker-compose.yml`).
* **B:** fresh Debian/Ubuntu LTS VM, 2 vCPU, 4 GB RAM, 40 GB SSD, dedicated to this test, no other sites, no shared DB, no production data, public DNS name used only for the test.

## 3. Software versions (match CI so results are comparable)

WordPress 7.1.4, WooCommerce 11.3.0 (HPOS on **and** off), MySQL 8.4 LTS (CI: 8.4.13), PHP 8.5 (CI integration image `wordpress:7.1.4-php8.5-apache`); unit matrix 8.1–8.5 stays in CI. Support floor stated by the plugin: PHP ≥ 8.1, WP ≥ 6.4, WC ≥ 8.2 — the lower bounds are **not integration-tested anywhere**; add one WP 6.4/WC 8.2/PHP 8.1 run if the owner wants that claim.

## 4. Minimum privileges

* **A:** one-time owner action — either add the operator to the `docker` group (equivalent to root on the host; use a rootless-Docker or a dedicated throwaway user if preferred) or provide a `sudo` window for `systemctl start docker` and the test run. Nothing else needs elevated rights.
* **B:** SSH key for one non-root `deploy-staging` user; `sudo` limited to `systemctl restart php-fpm/nginx`; no access to any other host, DB or cloud account; key revoked after the test.

## 5. Database isolation

Dedicated MySQL instance/schema and credentials per store (`wps_hpos`, `wps_legacy` in A). No production dump, no customer data; fixtures from `integration/fixtures` and `integration/tests/seed.php` only. Credentials are test-only values from `integration/.env` (git-ignored) and are never reused elsewhere.

## 6. TLS, proxy and private storage (B only; A cannot prove these)

* Real certificate (Let's Encrypt) on the test hostname; reverse proxy (nginx/Caddy) in front of PHP-FPM.
* Set `order_api_trusted_proxies` to the proxy IP only; test spoofed `X-Forwarded-For` from outside (must be ignored) and genuine forwarded requests (must be honoured). `order_api_require_https` **on**.
* Evidence required: `openssl s_client`/`curl -v` transcript, HSTS/redirect behaviour, proxy config, request logs.
* Private storage: confirm `wp-content/uploads/wps-pepita*` (feed, labels, logs) is not web-readable except through the tokenised feed route — `curl` the direct file paths and expect 403/404; keep the `.htaccess`/`index.html` guards **and** an nginx `deny` rule (nginx ignores `.htaccess`).

## 7. Mail interception, egress, disabled integrations

* **Mail:** Mailpit/MailHog as the only SMTP target; no real MX reachable. Evidence: mailbox contents for each scenario (CI only *logs* `wp_mail`; it does not intercept at the network layer).
* **Egress:** default-deny outbound firewall (A: Docker network `internal: true` or `iptables` DOCKER-USER rules; B: `ufw default deny outgoing` with allow for DNS and the package mirrors during install only). Evidence: attempted `curl https://example.org` from the PHP container fails; no connection to any `pepita.hu` host.
* **Disabled integrations:** only WooCommerce core and this plugin installed; no payment gateway other than BACS/COD set to test; no invoicing (Billingo/Számlázz), courier (GLS/Foxpost), ERP or marketing plugins. Evidence: `wp plugin list`, `wp option get woocommerce_gateway_order` and enabled-gateways list.
* **No Pepita credentials:** order API key is a random test value generated for the run; no feed URL is registered with Pepita.

## 8. Backup and rollback

Before each run: `wp db export` (both stores) and a volume snapshot; record sha256. Rollback tests to execute and record: (1) deactivate → tables/orders retained; (2) reactivate → resume; (3) delete plugin without `uninstall` data removal; (4) restore the DB export and verify identical row counts; (5) A: `docker compose down -v` to destroy everything; B: snapshot revert / VM destroy. Plugin `uninstall.php` data deletion must be verified **only** on the disposable store.

## 9. Resource and cost estimate

A: no cash cost; ≈ 2–3 GB RAM and ≈ 3 GB disk for the compose stack; first run ≈ 5–10 min image pull + provision. B: small VPS ≈ €5–12/month (pro-rated; one to three days of use ≈ €1), optional domain/DNS use of an existing domain at no extra cost; Let's Encrypt free. Estimates are indicative, not quotes.

## 10. Specific owner authorization required

The owner must reply, in writing, with the items they approve:

1. **Option A:** "I authorize enabling Docker for user `zsolt` (group `docker` / start `docker.service`) on this workstation for running the disposable integration stack and the RR20 test."
2. **Option B:** the named host (not `stg1.wpcaregrid.com`), who provisions it, SSH access scope, and that it holds no production data.
3. Approval to install the exact package `dist/wp-pepita-sync.zip` for candidate `2a09c76` (and the resolution of the checksum discrepancy in the acceptance report §3).
4. Confirmation that no live Pepita endpoint or credential will be configured.

Until these are given: no Docker start, no SSH to any host, no installation.
