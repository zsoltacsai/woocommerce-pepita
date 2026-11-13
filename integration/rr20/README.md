# RR20 two-worker adversarial harness (test-only)

Residual Risk 20: a worker stalls during an order import, its 15-minute claim lease expires, a second
worker takes over, the first resumes (or dies). The harness drives **two genuinely concurrent HTTP
requests** against the disposable integration stores:

* `wps-rr20-pause.php` (mu-plugin, copied in and removed by the runner) pauses worker A inside
  `save()` (`woocommerce_before_order_object_save`) at the *early* (totals) or *final* save and traces
  every order hook / mail.
* `run-rr20.sh <hpos|legacy> <resume|kill> [final|early|control]` ages the claim lease in MySQL, runs
  worker B, then resumes or `kill -9`s A, replays once more, and snapshots claims / orders / stock /
  orphan rows after each step.
* `evaluate.py` checks the invariants (one finalized order, claim consistent, stock reduced once, no
  success answer without a finalized order, no orphan rows, no ghost side-effect hooks, no mail) and
  exits non-zero on any violation.

Run in CI: Actions -> CI -> "Run workflow" with `rr20 = true` (10 jobs: 2 stores x (2 variants x 2
modes + control)). Locally: provision with `integration/scripts/setup.sh`, then run the runner.
Touches only the `wps-pepita-integration` compose project, `127.0.0.1` and synthetic data.
