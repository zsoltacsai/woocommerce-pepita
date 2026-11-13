# WP Pepita Sync — M9-B Staging Acceptance (Corrected, Evidence-Reconciled)

**Supersedes** the locally generated report of 2026-11-13 15:15 (see §1). This report only claims what was verified; every row carries one of these statuses:

| Status | Meaning |
|---|---|
| **PASS** | Executed by us, with reproducible evidence recorded here |
| **CI COVERED** | Supported by automated tests that ran in GitHub Actions; **not** executed on a separate staging server. Never upgraded to PASS |
| **PENDING** | Not yet executed |
| **BLOCKED** | Cannot execute because of a verified dependency |
| **FAIL** | Executed and failed |

Evidence types are kept apart: **[E] executed**, **[CI] CI log**, **[S] static code reading**, **[SIM] simulation inside a test**, **[EXT] pending external validation**.

## 0. Headline

* **Staging gate: HOLD.** Nothing has been executed on a staging server, local Docker included. Everything functional is **CI COVERED** at best.
* **Production: STRICT NO-GO.**
* **Residual Risk 20: UNPROVEN** (never executed with concurrent workers; static reading raises open questions, §6).
* **Artifact: content identical to the candidate; default rebuild checksum differs** (§3) → release preparation stopped pending an owner decision.
* PR #2 verified **OPEN + Draft**, head `2a09c76850fb47090b70a693339cbf5e3dbf515b` [E]. The candidate is untouched; this report lives on branch `docs/m9b-evidence-reconciliation` (docs only).

## 1. The earlier report: location and corrections

[E] The earlier file was `/home/zsolt/Projects/woocommerce-pepita/M9-B-STAGING-ACCEPTANCE.md`, **untracked** (`git status`: `??`), 24 595 bytes, created 15:15 on branch `security/claude-audit-remediation`, with a copy under `~/.gemini/antigravity-cli/brain/…/M9-B-STAGING-ACCEPTANCE.md`. It is **not** in PR #2 (`refs/pull/2/head` = `2a09c76`, whose tree has no such file). It was never committed or pushed.

| # | Earlier claim | Reality (evidence) | Fix |
|---|---|---|---|
| 1 | "HMAC request signing", "unsigned/tampered webhooks rejected" | Shared API key, compared with `hash_equals()`; accepted from `apikey` query param, `X-WPS-Api-Key` or `X-Api-Key` (`class-api-controller.php:145-170`) [S]. No request signing exists | Described as API-key auth |
| 2 | Success body `{ "status": "created" }` / `{status:"duplicate", order_id}` | Success is `{isError:false, responseCode:200, message, orderId}`; errors `{isError:true, responseCode, message}` (`class-api-controller.php:568-584`; asserted by CI `07-idempotency`) [S][CI]. `created`/`duplicate` are internal result states | Corrected |
| 3 | Automated Docker runs presented as staging acceptance | They are GitHub-runner Docker containers (`localhost:8080/8081`, plain HTTP) [CI]. No separate staging server was used | Re-labelled CI COVERED |
| 4 | Reverse-proxy "enforcement active", email suppression PASS, private storage PASS, TLS | No TLS terminator or real proxy was exercised (CI uses `http://localhost`; no real proxy sits in front of the stores; trusted-proxy/HTTPS behaviour is only exercised against plain-HTTP requests inside the test harness) [CI][SIM]. Mail was only **logged** by the mu-plugin `wps-it-mail-log.php`, not intercepted at network level. No egress control tested | PENDING (§5) |
| 5 | RR20 "100 % prevented" | Static trace only (§6); no concurrent execution | UNPROVEN |
| 6 | Software versions "PHP 8.1.31, 8.2.26, 8.3.14, 8.4.1, 8.5.0RC1" | CI actually installed PHP 8.1.34 / 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 (integration image `wordpress:7.1.4-php8.5-apache`, WP 7.1.4, WC 11.3.0, MySQL 8.4) [CI]. The earlier numbers were not in any log | Corrected |
| 7 | ST-01…ST-11 definitions | Did not match the original acceptance plan (`M9-STAGING-READINESS.md` §ST table: e.g. earlier "ST-02 HMAC", "ST-07 HPOS", "ST-08 Legacy") | Re-done from the original plan (§4) |
| 8 | Cited `tests/cases/105-email-and-stock-test.php` | File does not exist (`105-order-tax-test.php` does) [E] | Removed |
| 9 | "Independent Security QA — Moody (Claude Haiku 5.5)" as independent sign-off | It is an AI model persona from the same vendor family, not a human or third-party audit; its output is review input, not evidence of execution | Stated as such |
| 10 | Partner address `marketplace@pepita.hu` | Owner specified **seller.support@pepita.hu** | Corrected (§8) |
| 11 | "Staging Gate CONDITIONAL GO… 100 % verified" | Not supported by the above | HOLD |

Items the earlier report got **right** and that stay: CI run `43437663970` succeeded with 8/8 jobs; 193 unit tests ×5 PHP versions and 71 integration scenarios on each of HPOS and legacy passed [CI]; and the claims table really is `{prefix}wps_pepita_orders` (`class-order-repository.php:37`, `uninstall.php:60`) [S].

**Errors in the candidate's own docs (not changed here, branch is frozen):** `M9-STAGING-READINESS.md` ST-01 names the table `wps_pepita_order_claims` (actual: `wps_pepita_orders`) and `utf8mb4_bin`; `M9-STAGING-EXECUTION.md` cites test files `03-feed-generation.php` and `09-scheduler.php` (actual `03-feed.php`, `08-scheduler.php`) and maps ST-06 to a test that is an unpaid *transfer* order. They should be corrected in a follow-up commit if the owner re-issues a candidate.

## 2. CI evidence reverified [E]

`gh run view 43437663970`: `success`, `pull_request` on `security/claude-audit-remediation`; jobs `unit (PHP 8.1/8.2/8.3/8.4/8.5)`, `integration-hpos`, `integration-legacy`, `lint (PHP 8.5)` — all ✓ (8/8). Logs: unit `193 passed, 0 failed, 0 skipped` ×5; `integration-hpos 71 passed, 0 failed`; `integration-legacy 71 passed, 0 failed`. Note: the CI tests the **source** (`setup.sh` installs `dist/wp-pepita-sync.zip` from the checkout), so the committed zip's contents are what ran.

## 3. Package verification [E]

| Check | Result |
|---|---|
| Candidate commit | `2a09c76850fb47090b70a693339cbf5e3dbf515b` = PR #2 head = `origin/security/claude-audit-remediation` |
| Committed `dist/wp-pepita-sync.zip` at that SHA (`git show HEAD:…`) | sha256 `4d19df0446a653335aa15b6f894e5b77dbd389b2d6d92179abb9453ead1a3302` — **matches expected** |
| Archive contents | 55 entries; plugin PHP, `includes/`, `assets/`, README, CHANGELOG, LICENSE, `uninstall.php`; no `tests/`, `bin/`, `dist/`, `.git`, `.env`, `*.log`, keys, `*.sql` |
| Credentials | Regex scan for private-key blocks, AWS key ids, `sk_live`, long bearer tokens: no hits. (Settings code mentions "api key"/"token" as option names — no values.) |
| **Fresh rebuild** from a clean clone at the SHA with `bash bin/build.sh` (TZ = CET) | **`2b983e65d03fa5dce03c3ab07094caa3b649adae3dfef379843eea024035fd58` — DIFFERENT** |
| Same, `TZ=UTC` | `7df55fe6…26269` — different again |
| Content comparison | File listing identical; extracted trees `diff -r` **identical** (all file bytes equal). Only ZIP entry timestamps differ |
| Cause | Committed zip's entry times are 12:53 CET = commit `99301b9` (`1794570814`), not the candidate commit (14:53 CET). A zip committed inside the commit it describes can never embed that commit's own time, and DOS zip timestamps are local-time. Build with `SOURCE_DATE_EPOCH=1794570814 TZ=Europe/Budapest` → **`4d19df04…a3302` exactly** (reproduced), with `TZ=UTC` → `f8f956c7…7161` |

**Conclusion:** source-to-artifact **content identity: PASS**. **Default-command byte reproducibility: FAIL** (the expected checksum is only reproducible if two undocumented inputs are supplied). This is a build-process defect, not a content discrepancy, but per instructions **release preparation is stopped** until the owner decides: (a) accept the pinned-inputs reproduction and document it, or (b) fix `bin/build.sh` (fixed epoch + `TZ=UTC`, zip not committed) in a new commit, which creates a new candidate SHA/checksum, new 8-job CI and re-review. Nothing was changed on the candidate.

## 4. ST-01 … ST-11 reconciled against the original acceptance criteria

Criteria from `M9-STAGING-READINESS.md`. "Staging" column = execution on a separate staging server (none exists/was used): **PENDING** for every row. Candidate-level run = Docker integration on GitHub runners or unit tests on stubs.

| ST | Original criterion (short) | Actual test implementation | Status | Gaps / honesty notes |
|---|---|---|---|---|
| 01 | Clean install via admin upload; tables created, collation checked | `integration 02-schema`: activation creates both tables, columns, unique `external_id`, utf8mb4+InnoDB, `maybe_upgrade()` and `create_tables()` idempotent; store installed by `wp plugin install dist/zip` | **CI COVERED** | Install via wp-cli, not wp-admin upload UI. `utf8mb4_bin` collation (as written in the criteria) is not what CI asserts — CI asserts utf8mb4/InnoDB and case-sensitive ids |
| 02 | Full product feed valid, UTF-8 sanitized | `integration 03-feed` (seed catalog: simple/out-of-stock/variable, exact counters, valid XML), `04-feed-http`; unit `40-sanitizer`, `50-xml-writer` | **CI COVERED** | Structural validation only — **no official Pepita XSD/acceptance**. Tiny seed catalog; large-catalog behaviour only in unit `130-performance` |
| 03 | Complete stock feed, never delta | `03-feed`: stock feed matches full-feed signature, only documented stock elements, refuses without a full feed | **CI COVERED** | Same small catalog; memory claim only unit-level |
| 04 | 27 % VAT order, shipping, voucher; exact gross; label PDF | `08-tax` (27 % with prices incl./excl. tax, shipping tax), `06-order-import` asserts the saved label file is the fixture PDF | **CI COVERED** | A discount-voucher case was not identified in test names |
| 05 | 0 % / exempt order, no 422 | `08-tax`: taxes disabled, tax-exempt customer, 0 % payload on taxable product; incompatible VAT → 422 | **CI COVERED** | — |
| 06 | Unpaid COD → `on-hold`, notes recorded | `06-order-import`: unpaid **transfer** → on-hold; paid COD → processing | **CI COVERED (partial)** | An *unpaid COD* case is not tested directly |
| 07 | Identical replay: 200, original id, no new order, claim unchanged | `07-idempotency` first test: 200, same `orderId`, one order, total unchanged, `attempts` stays 1 | **CI COVERED** | — |
| 08 | Altered replay: 409, order untouched, note + `changed_payload_refused` log | `07-idempotency`: changed payload → 409 `isError:true`, order unchanged; unit `106` historical-hash cases | **CI COVERED** | Operator-note/log assertion not confirmed at integration level |
| 09 | Crash recovery: stale shell purged, exactly one order, 200 | `integration 07`: "mid-creation crash … discarded and cleanly rebuilt" (staleness by editing `updated_at`); unit `106` fencing cases on `Fake_WPDB` | **CI COVERED** | Single process; "concurrency" is sequential **[SIM]**. Does not cover RR20 |
| 10 | Maintenance run: locks cleared, payloads/labels purged | Unit `150-retention` on `Fake_WPDB`; integration `08-scheduler` runs only feed generation via Action Scheduler / WP-Cron | **CI COVERED (unit-level only)** | No integration run of the maintenance job against real MySQL/WP-Cron; the earlier "labels retained" and the criterion "purges labels for closed orders" disagree |
| 11 | Deactivate keeps data; reactivate resumes without error | Unit `140-bootstrap`: `Installer::deactivate()` keeps data | **CI COVERED (deactivate only, unit)** / reactivation + uninstall **PENDING** | No real-WP deactivate→reactivate test; `uninstall.php` untested |

Environment checks from the M9-B brief, honestly:

| Check | Status | Note |
|---|---|---|
| PHP / WP / WC / MySQL versions | CI COVERED | WP 7.1.4, WC 11.3.0, MySQL 8.4, PHP 8.5.12 (integration); unit 8.1–8.5. Declared floor (WP 6.4, WC 8.2) is **not tested** |
| HPOS on/off | CI COVERED | Both stores, 71/71 each |
| REST access, API key, rate limit, IP allowlist | CI COVERED | `05-rest-api` |
| TLS, reverse proxy, trusted-proxy hardening | PENDING | No real proxy or TLS in any run; needs a real proxy (plan §6) |
| Private feed/label storage | CI COVERED (partial) | Token-gated feed route tested; direct-file web access on a real server not tested |
| Scheduled jobs | CI COVERED (feed only) | See ST-10 |
| Email interception | PENDING | CI logs `wp_mail`; no SMTP trap |
| Disabled payment/invoice/courier/ERP, egress restriction | PENDING | Not demonstrated anywhere |
| Backup/rollback | PENDING | Procedure in plan §8; none executed |
| No production Pepita credentials | PASS [E] | The zip and repo contain none; test keys only in git-ignored `integration/.env` |

## 5. Infrastructure decision [E]

* Workstation: Docker 29.9.2 / Compose 5.6.1 installed; `docker.service` **inactive**; `/var/run/docker.sock` is `root:docker`; user `zsolt` is **not** in `docker`; `sudo -n` requires a password; no PHP/composer locally. → **Option A is BLOCKED** on owner authorization; no privilege change was attempted.
* Option B: no dedicated staging server is known. `stg1.wpcaregrid.com` belongs to another project and was **not used or contacted**.
* Full plan, costs and the exact authorization wording: [`docs/m9b/STAGING-DEPLOYMENT-PLAN.md`](docs/m9b/STAGING-DEPLOYMENT-PLAN.md). Recommendation: A first (free), then B only for TLS/proxy/mail/cron/egress.

## 6. Residual Risk 20 — status UNPROVEN

**No concurrent-worker test has been executed** (BLOCKED by the same Docker dependency). Existing tests are sequential simulations on a fake DB or single-process integration (details: [`docs/m9b/RR20-ADVERSARIAL-TEST-PLAN.md`](docs/m9b/RR20-ADVERSARIAL-TEST-PLAN.md) §1).

Static reading [S] (hypotheses to be tested, **not** conclusions) that contradicts the earlier "100 % prevented":
* The claim write is fenced (`complete()` … `WHERE attempts=N`), but the WooCommerce `save()` after the lease re-check is not atomic with it.
* The earlier scenario ("B purges the shell and creates order 200") is probably the wrong path: A's order already has line items at that point, so `is_order_incomplete()` is false and B likely **adopts** A's order via `handle_existing_order`, completes the claim and answers 200 while the order may still be `pending` with `_wps_pepita_in_progress=1`. If A then dies, no path finalizes it. Needs execution to confirm or refute.
* The stale worker's late `save()` could touch an order that no longer exists (hooks / orphan meta).

Delivered: an executable plan with 8 invariants, 5 variants, and an **unexecuted, uncalibrated draft** harness (`docs/m9b/rr20/wps-rr20-pause.php`, `run-rr20.sh`) that pauses a real HTTP worker at `woocommerce_before_order_object_save` (after the lease check, before the DB write) without modifying the candidate, ages the lease in MySQL, launches a second real worker, then resumes or kills the first and snapshots claims, orders, stock, orphans and a hook/mail trace. `run-rr20.sh` passes `bash -n`; the PHP file could not be syntax-checked (no PHP here). **Gate: PENDING**; becomes **BLOCKED** if any invariant fails, triggering the fix-branch / 8-job CI / independent exact-HEAD review procedure in plan §6. No fix was made because no failure was observed — and none could be.

## 7. Independent review

No code was changed, so no new code review was required. The earlier Claude Haiku "Moody" reviews are AI reviews of the candidate and are cited as such; they are not execution evidence.

## 8. Pepita partner dependencies [EXT — all PENDING]

Hungarian draft to `seller.support@pepita.hu` (NOT sent, contains no secrets): [`docs/m9b/PEPITA-SELLER-SUPPORT-INQUIRY.hu.md`](docs/m9b/PEPITA-SELLER-SUPPORT-INQUIRY.hu.md). It asks for: sandbox and credentials; product/full-stock feed ingestion and an official schema; authentication options for the webhook (today: shared API key only); the webhook contract and accepted responses (`isError/responseCode/message/orderId`); retry schedule and HTTP 409 semantics (we use 409 for both "changed payload" and "retry, in progress"); order lifecycle updates; VAT/tax-exempt payload semantics; package-label delivery and tracking updates. **No official Pepita feed acceptance is claimed.** No live endpoint was activated.

## 9. Rollback evidence

None executed. Procedure defined in plan §8. The only rollback-related evidence is CI-level (unit deactivation keeps data; idempotent table creation). **PENDING.**

## 10. Outstanding blockers

| ID | Blocker | Owner action |
|---|---|---|
| B1 | Docker not usable on the workstation; no staging server | Written authorization per plan §10 |
| B2 | Default `bin/build.sh` does not reproduce `4d19df04…`; reproducible only with `SOURCE_DATE_EPOCH=1794570814 TZ=Europe/Budapest` | Choose (a) accept-and-document or (b) fix in new candidate |
| B3 | RR20 not executed; stronger risk hypotheses (adoption of a non-final order) open | Run plan after B1 |
| B4 | ST-01–ST-11 never run on a staging host; ST-06/10/11 have test gaps | Run after B1; optionally add missing tests |
| B5 | TLS/proxy/mail/egress/backup/rollback not demonstrated | Option B host (plan) |
| B6 | Pepita answers (sandbox, auth, 409, VAT, labels) | Owner sends inquiry |
| B7 | Declared WP 6.4 / WC 8.2 / PHP 8.1 floor untested at integration level | Optional extra run |
| B8 | Candidate docs contain wrong table/test-file names | Fix in next candidate |

## 11. Recommendation

* **Production: STRICT NO-GO.**
* **External Pepita staging connectivity: HOLD.** Required first: B1 → run ST-01–ST-11 locally → execute RR20 on both stores → resolve B2.
* **Isolated local staging preparation: proceed on written Docker authorization** (plan §10). No deployment, no SSH, no live endpoint, no customer data until then.

## 12. Files on this branch (`docs/m9b-evidence-reconciliation`, docs only)

`M9-B-STAGING-ACCEPTANCE.md`, `docs/m9b/RR20-ADVERSARIAL-TEST-PLAN.md`, `docs/m9b/STAGING-DEPLOYMENT-PLAN.md`, `docs/m9b/PEPITA-SELLER-SUPPORT-INQUIRY.hu.md`, `docs/m9b/rr20/wps-rr20-pause.php`, `docs/m9b/rr20/run-rr20.sh`. The packaging script copies only `includes/`, `assets/` and top-level plugin files, so none of these enter the distribution zip.
