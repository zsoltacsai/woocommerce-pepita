# M8 integration environment

This environment is disposable and isolated from production. It provisions two independent WordPress stores on MySQL 8.4: one with WooCommerce HPOS enabled and one using legacy order storage. Both use the pinned WordPress/PHP image, current WooCommerce version pin, WP-CLI, test-only secrets, and local HTTP ports.

## Run

1. Ensure Docker Engine and the Compose plugin are available to the current user.
2. Run `integration/scripts/setup.sh`. On first run it copies `.env.example` to an ignored `.env`, builds the plugin archive, installs WordPress and WooCommerce in both stores, activates the plugin, and seeds test products.
3. Run `integration/scripts/run.sh`. It executes the real HTTP, REST, database, migration, duplicate-order, feed, Action Scheduler, and WP-Cron cases against both storage configurations. The actual output is written under `integration/results/`.
4. Run `integration/scripts/teardown.sh` to remove only this Compose project and its named volumes. Result logs remain on disk.

The teardown command deletes the disposable test databases and store volumes. Never point this Compose project at a production database or reuse its test credentials.

The test cases use documented payload fixtures in `fixtures/`; they do not call Pepita, send orders, or send customer email. The order route is called only on the local containers. WP-Cron is disabled for ordinary page requests and explicitly invoked by the test harness.

## Verification status

Compose syntax and the integration PHP files can be checked without starting services. M8 is not accepted until `run.sh` has completed successfully on both stores. If Docker cannot be reached, `run.sh` records the daemon error and exits with status 2 before running any integration case.
