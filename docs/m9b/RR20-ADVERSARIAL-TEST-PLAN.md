# Residual Risk 20 — Executable Adversarial Test Plan

**Candidate:** `2a09c76850fb47090b70a693339cbf5e3dbf515b` (unchanged)
**Status of RR20:** **UNPROVEN — NOT EXECUTED.** No concurrent test has been run. Nothing in this document is evidence of safety.
**Why not executed:** the author host has no permission to reach the Docker daemon (`/var/run/docker.sock` is `root:docker`, user is not in `docker`, `sudo` needs a password) and no local PHP. See `M9-B-STAGING-ACCEPTANCE.md` §5.

## 1. What existing tests do and do not prove

| Existing test | What it actually does | Proves concurrency safety? |
|---|---|---|
| `tests/cases/106-recovery-and-replay-test.php` (unit) | Runs on `Fake_WPDB` + stubbed WooCommerce. "Concurrent" cases are sequential code with a hook (`woocommerce_get_order`) that mutates state on the Nth call. | **No** — single process, simulated DB. |
| `integration/tests/cases/07-idempotency.php` | Real WP/WC/MySQL, but one PHP process; staleness simulated by editing `updated_at`; "concurrent claim → busy" is two sequential `claim()` calls. | **No** — no two workers in flight. |
| Previous report's "Phase 4" | Static line-trace (lines 921/927 of the importer). | **No** — static analysis only, and it only covered the claim row, not the WooCommerce order write or hooks. |

## 2. Static observations that motivate the test (hypotheses, NOT findings)

Read from `includes/orders/class-importer.php` and `class-order-repository.php` at the candidate SHA.

* **O1 — fenced claim write.** `Order_Repository::complete()` updates `WHERE attempts = %d`, so a stale worker cannot mark the claim completed. Likely safe for the claim row.
* **O2 — the WooCommerce save is not fenced.** In `create_order()`, `is_attempt_current()` is checked, then `delete_meta_data('_wps_pepita_in_progress')`, then `$order->save()`. The attempt check and the save are not atomic; a lease takeover between them is not detected by the save itself.
* **O3 — B may adopt, not delete.** `is_order_incomplete()` returns false once the shell has line items. By the time A reaches the final save A's order has items, so worker B (attempt 2) will most likely take the *"existing order"* branch (`handle_existing_order(..., reclaimed=true)`) and call `complete()` against A's order — **not** the purge branch the previous report described. Whether B's `complete()` succeeds while the order still carries `_wps_pepita_in_progress=1` and status `pending` is untested.
* **O4 — variant "A never resumes".** If B completes the claim and replies 200 while the order is still `pending` with the in-progress marker, and A is then killed, nothing in the code path re-examines that order (claim is `completed`; replays go through `handle_existing_order`, which does not finalize). The order could be stuck `pending`/unstocked while Pepita believes it was accepted.
* **O5 — ghost save.** If some other path (manual cleanup, order deletion between pause and resume) removes the order, A's `save()` runs against a deleted id; WooCommerce status-transition hooks and postmeta/order-meta writes for a non-existent order are possible. Third-party hooks on `woocommerce_order_status_*` could produce external side effects.

These are reasons to execute the test. They are not conclusions.

## 3. Pause point without changing the candidate

The importer has no hooks of its own between the lease check and the save. WooCommerce fires `woocommerce_before_order_object_save` inside `save()`, i.e. **after** the lease check and **before** the DB write. The draft mu-plugin `docs/m9b/rr20/wps-rr20-pause.php` pauses the first worker whose in-memory order has: `created_via=wps-pepita`, the in-progress marker removed, and `_wps_pepita_imported_at` set (the exact in-memory state set up just before the final `save()`).

Lease expiry is forced by `UPDATE … SET updated_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE` on the claim row — the same mechanism the production staleness check reads (`updated_at < now-15min`, `STALE_AFTER`). This is a real DB state change, not a code stub. Waiting 15 real minutes is also allowed and should be done once to confirm the two are equivalent.

## 4. Procedure (per store: HPOS and legacy; per variant: resume and kill)

Runner: `docs/m9b/rr20/run-rr20.sh <hpos|legacy> <resume|kill>`.

0. **Calibrate** (mandatory first): run with a payload on an unarmed store, then armed; confirm in `trace.jsonl` that `final_save_reached` fires exactly once per import, after `is_attempt_current`, and that the worker really blocks (`entered` file appears, HTTP response is delayed). If the hook fires at the wrong save, fix the harness — never the assertions.
1. Record package sha256 + source SHA, product stock levels, claim/order/orphan snapshot (`0-before`).
2. **Worker A** — real HTTP POST; it blocks at the final save. Snapshot `1-A-paused`.
3. Age the claim lease 20 min. Snapshot `2-lease-aged`.
4. **Worker B** — second real HTTP POST, same external id and payload, while A is still blocked. Record status/body. Snapshot `3-B-done`.
5. **Variant resume:** release A. **Variant kill:** `kill -9` A's PHP worker (no resume).
6. Snapshot `4-A-finished`. Then **retry** the same payload once (Worker C) with no manual DB change. Snapshot `5-after-retry`.
7. Remove the harness. Archive the evidence directory with both SHAs.

Additional variants to run after the base two:
* **V3** lease aged *before* A reaches the pause (B starts while A is still building).
* **V4** B started *before* A's final save and A is paused for the full real 15 minutes (no SQL aging).
* **V5** three workers (A paused, B, C) to check the fence under more than one contender.

## 5. Invariants and how each is evidenced

| # | Invariant | Evidence (must be actual data) | FAIL if |
|---|---|---|---|
| I1 | No completed order is deleted | `woocommerce_delete_order`/`before_delete` trace events; order list before/after | any delete of an order that had reached `completed` claim state |
| I2 | Exactly one valid WooCommerce order per external id | `*.orders.json`: one row; status ∈ configured paid/unpaid status; `in_progress` empty; `imported_at` set; items = payload | 0 or ≥2 orders, or one order left `pending` with `in_progress=1` |
| I3 | No duplicate stock reduction | `*.stock.json` deltas equal payload quantities once; `_order_stock_reduced`; `woocommerce_reduce_order_stock` trace count | stock reduced twice, or order reduced but claim not completed, or never reduced |
| I4 | No premature/ghost payment, shipping, customer-notification hooks | `trace.jsonl`: every `woocommerce_order_status_*`, `payment_complete`, `wp_mail` event; `exists` must be true and the pid must be a worker that owns the lease | any such hook for an order id that does not exist, fires twice for the same transition, or `wp_mail` to the buyer |
| I5 | Claim ↔ order consistency | `*.claims.txt` vs `*.orders.json`: `status=completed`, `wc_order_id` = the one order, `attempts` as expected, payload_hash matches | claim completed pointing at a non-final order; claim points to deleted order; mismatched ids |
| I6 | Orphan rows | `*.orphans.txt` (postmeta without post; HPOS: order meta/addresses/operational data without order row) identical before/after | any new orphan rows |
| I7 | Retry self-heals | Worker C result with no manual DB edit; end state satisfies I1–I6; HTTP responses are 200/409-retry consistent with the documented contract | stays 409/500 indefinitely, or needs manual DB repair |
| I8 | Response honesty | Pepita-facing responses (A/B/C codes and bodies) vs final order state | 200 returned while the order is not final and nothing will finalize it (see O4) |

Result vocabulary: **PASS** (all invariants evidenced, both stores, both variants), **FAIL**, **BLOCKED**, **PENDING**. A missing artefact is PENDING/FAIL, never PASS.

## 6. If a destructive or financially inconsistent race is found

Gate becomes **BLOCKED**. Then, and only then: classify as production blocker; implement a targeted fix on a **separate** branch from the candidate (e.g. `fix/rr20-…`), keep the failing test in the repository (do not weaken it), run all **eight** CI jobs on the exact HEAD, and obtain an independent Claude exact-HEAD review. PR #2 stays Draft/unmerged and the candidate SHA is re-issued with a new package checksum.

Likely fix directions to evaluate (not decided): fence the final save by performing it under a compare-and-set on the claim (`UPDATE … WHERE attempts=N` first, then save), or make `handle_existing_order` refuse to adopt an order that still carries `_wps_pepita_in_progress` and instead finalize/repair it under the new lease.

## 7. Safety constraints for the run

* Only the disposable integration containers (`integration/docker-compose.yml`, bound to 127.0.0.1). Synthetic fixtures only, no customer data.
* No egress to Pepita; mails are observed by the harness and the store has no SMTP.
* The harness is test-only, lives outside the plugin package (`bin/build.sh` only packages `includes/`, `assets/` and top-level plugin files) and is removed at the end of each run.
* The runner and mu-plugin are **unexecuted drafts**; `bash -n` passed for the runner, the PHP file has **not** been syntax-checked (no PHP on the author host). CI `lint` does not cover `docs/`.
