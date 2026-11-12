# BACKLOG — WP Pepita Sync

Items are ordered by priority. "Now" blocks the production-ready claim; "Next"
and "Later" are improvements, not defects.

## Now — required before production

1. **Staging validation.** Install on a real WordPress + WooCommerce staging
   store and complete `TESTING.md` §6 and `COMPATIBILITY-REPORT.md` §9. Record
   the version matrix.
2. **HPOS end-to-end.** Enable HPOS, import paid, unpaid, voucher and label
   orders, confirm totals, notes and meta.
3. **Cron reliability.** Verify Action Scheduler continuations and the WP-Cron
   fallback on a host with `DISABLE_WP_CRON` and a real system cron.
4. **Feed acceptance.** Confirm Pepita ingests both feeds, matches the stock feed
   to the full feed, and accepts the variation shape.
5. **Gateway mapping.** Choose a real WooCommerce gateway for `creditcard` and
   verify the order shows the expected payment method.
6. **Direct-file exposure test.** Request the private feed path directly over
   HTTP and confirm it is denied by the server.

## Next

7. **Variation-aware order lines.** If Pepita adds a variation identifier to the
   payload, resolve and import the concrete variation instead of rejecting the
   parent. Requires a documented field; do not guess.
8. **Per-feed product limit.** Optional cap for very large catalogs, with a
   clear report of what was omitted.
9. **Feed diff report.** Show added, removed and changed products between the
   current and previous full feed.
10. **Scheduled generation window.** Restrict automatic generation to off-peak
    hours.
11. **Admin bulk regeneration.** Re-export selected products without a full run.
12. **Health check for stale stock feed.** Warn when the stock feed is older than
    twice its interval.
13. **Log export.** CSV download of filtered logs for support.
14. **Order list column.** Show the Pepita order ID and import status in the
    WooCommerce orders list.

## Later

15. **Multisite network settings.** Shared configuration with per-site overrides.
16. **Additional product types.** Grouped and bundle support, if Pepita documents
    how they should appear.
17. **Feed compression.** Serve gzip when the client accepts it.
18. **Image validation probe.** Optional HEAD request to detect dead image URLs
    before export.
19. **Category mapping UI.** Map WooCommerce categories to Pepita category IDs if
    Pepita publishes a taxonomy.
20. **Metrics endpoint.** Counters for monitoring (generation duration, invalid
    products, import failures).

## Explicitly out of scope

- Sending orders from WooCommerce to Pepita — the documented API is intake only.
- Editing the Allegro reference plugin.
- Writing to WooCommerce core tables.
- Capturing payments, issuing invoices, or generating shipping labels beyond
  storing the PDF Pepita sends.
- Inventing Pepita fields, endpoints or authentication schemes.
