# Astro Wall Clocks development workflow

## Local

- Source: this repository, using feature branches from `master`.
- Runtime: `dev/compose.yaml` on `127.0.0.1:8088`; MariaDB is internal to Compose and uses a separate named volume.
- Fixtures: `dev/seed-local.php` creates only synthetic pages, product, and customer. Do not import production orders, customers, credentials, or database exports.
- The GitHub Pages build is a static design preview only. Never use its demo cart or mirrored checkout for commerce tests.
- Run `python3 dev/tests/local_commerce.py` from the repository root for product/cart tests. `--place-order` creates one synthetic COD order and checks HPOS, stock, status, shipping, and totals. Local online gateways are absent; outbound mail and calls to payment/shipping providers are blocked.

## Staging (proposed; not created)

- The existing cPanel plan identifies as Deluxe1; it has a 25-database and 50-subdomain allowance, with 3 databases and 1 subdomain in use at assessment time. GoDaddy includes free AutoSSL with Deluxe. Create `staging.astrowallclocks.com` as a separate cPanel domain with a distinct document root outside the production WordPress tree and a separate MySQL database/user. Never point staging at the production database. Confirm quota and DNS again before creation.
- Verify DNS and a valid HTTPS certificate before logging in or testing payments. Restrict web access, disable indexing, suppress real customer email, and allow only the signed Easebuzz sandbox callback route through the access gate. Never expose Merchant Key or Salt in Git or browser code.
- The staging Basic-auth exception currently covers only `/wp-content/plugins/woocommerce_easebuzz_plugin/update_webhook.php`. Easebuzz's `?wc-api=WC_Easebuzz_Gateway` redirect callback is a separate route and remains protected; before sandbox testing, review a narrowly scoped POST-only exception for that route and confirm the patched plugin rejects unverified or mismatched responses. Do not activate the gateway on staging until the owner approves.
- Use synthetic products/customers/orders. Easebuzz's official 5.8.0 ZIP declares HPOS support but says WC tested through 11.0, while the current store uses 11.3.1. Its README requires PHP 8.2+ and classic `[woocommerce_checkout]` despite code metadata saying PHP 7.4+ and advertising blocks support. Validate the actual staging PHP patch level and test the classic checkout. Review callback/hash error paths and order association before enabling the gateway. Test sandbox success, failure, cancellation, redirect, webhook replay, refund, and stock/status transitions; COD checkout; and Shiprocket only with live account sync and label/booking actions disabled until a safe test path is confirmed.
- Test all public routes, same-origin cart/session behavior, checkout, account, order tracking, inventory, shipping, and SEO metadata. Record results before production approval.

## Production (approval required)

- Keep the existing database, historical HPOS orders, Razorpay, and current checkout in service until staging passes and the owner explicitly approves deployment.
- Immediately before cutover, take and verify recoverable file and database backups. Deploy only reviewed theme/gateway changes and retain a fast switch back to the prior theme/payment selection.
- If new live orders arrive, do not restore an old database snapshot over them. Roll back code/payment selection while retaining the current database; reconcile any in-flight payments and callbacks.
- Monitor orders, payment statuses, stock, checkout, COD, shipping, account access, and tracking after cutover.
