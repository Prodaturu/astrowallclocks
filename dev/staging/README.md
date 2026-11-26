# Isolated Easebuzz staging

Staging uses the production account's existing GoDaddy hosting but a separate WordPress document root and MySQL database. The reviewed Easebuzz 5.8.0 package is active only here, configured with generated dummy values in test mode. The staging must-use safety plugin suppresses mail and blocks Razorpay, Shiprocket, and both live and sandbox Easebuzz HTTP destinations. No real payment or shipment request can be made by the installed gateway while this guard remains active.

The two POST-only exceptions in `callback-access.conf` sit inside staging's existing Basic-auth `<RequireAny>`. They allow the direct webhook and exact root `wc-api` callback across WordPress internal rewrites. Other methods, pages, REST routes, and admin AJAX remain authenticated. The gateway checks the response hash, order gateway, merchant key, order ID and key, amount, and current transaction ID before changing an order. Paid and refunded orders ignore replayed callbacks.

Run `test_gateway.php` with staging WP-CLI to create synthetic HPOS orders and send signed HTTPS callbacks to this same staging host. Copy `test_account_fixture.php` outside the web document root as `/home/bgt9xah2o3eo/.astro-stage-test-account-fixture.php`, then run `test_protected_pages.sh` on the staging host. It creates and removes a temporary Basic-auth user, checks the protected storefront and classic checkout, and submits synthetic customer login and guest tracking forms. The scripts refuse the production database. Do not put live credentials in these scripts or this repository.

Before any controlled live-payment test, obtain explicit owner approval for a real charge and possible fees, confirm Easebuzz permits the staging callback domain, record a staging backup, and agree on the test amount and refund method. Enter live credentials only in staging administration, temporarily allow only the required Easebuzz live host, verify one synthetic order and refund, then restore the dummy configuration and outbound block. Production Razorpay and checkout remain unchanged until a separately approved cutover.

## 2026-11-26 validation

The pre-deployment staging backup is private at `/home/bgt9xah2o3eo/.astro-stage-backups/20261126T021439Z` (files, SQL, checksums). `deploy_latest.sh` is pinned to this snapshot and the reviewed theme/plugin/public-image archives. It deploys only to the isolated staging document root and stage database. The latest storefront includes separate Login and Register pages; staging registration is enabled. The eight existing public design images were copied from this repository into staging uploads. Theme files and directories require web-readable permissions for CSS and images.

`test_gateway.php` covers signed redirects and webhooks, tampering, replay, refunds, failed and cancelled statuses. `test_recovery.php` covers mocked authenticated status lookups, missed callbacks, bounded retries, and stock idempotency. `test_protected_pages.sh` checks protected routes, registration, login, order history, tracking, assets, checkout, and a synthetic COD order. `visual_stage.mjs` captured desktop and mobile screenshots in `/private/tmp/astro-staging-visual` through temporary Basic auth; `temporary_visual_auth.sh` removed that account after QA.

Staging has `DISABLE_WP_CRON=true` and no account cron entries. `run_recovery.sh` is pinned to the stage database and URL, uses `flock`, and successfully ran manually with dummy credentials and outbound blocks. It is **not scheduled**: adding an account cron entry requires owner approval. Proposed entry (only after approval):

```cron
*/5 * * * * /usr/bin/bash /home/bgt9xah2o3eo/.astro-stage-deploy/run_recovery.sh >/dev/null 2>&1
```

The callback POST exceptions remain limited to the direct Easebuzz webhook and exact `wc-api` route. All other unauthenticated pages and routes return HTTP 401. Live Easebuzz acceptance, merchant-domain permission, real refunds, and real transaction-status responses remain untested.
