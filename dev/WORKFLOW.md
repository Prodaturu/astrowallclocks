# Astro Wall Clocks development workflow

## Local

- Source: this repository, using feature branches from `master`.
- Runtime: `dev/compose.yaml` on `127.0.0.1:8088`; MariaDB is internal to Compose and uses a separate named volume.
- Fixtures: `dev/seed-local.php` creates only synthetic pages, product, and customer. Do not import production orders, customers, credentials, or database exports.
- The GitHub Pages build is a static design preview only. Never use its demo cart or mirrored checkout for commerce tests.
- Check design pages, WooCommerce product/cart/account routes, and Store API locally before requesting staging promotion. Local gateway payments are disabled.

## Staging (proposed; not created)

- Create `staging.astrowallclocks.com` as a separate cPanel domain with a distinct document root outside the production WordPress tree and a separate MySQL database/user. Never point staging at the production database.
- Verify DNS and a valid HTTPS certificate before logging in or testing payments. Restrict web access, disable indexing, suppress real customer email, and allow only the signed Easebuzz sandbox callback route through the access gate. Never expose Merchant Key or Salt in Git or browser code.
- Use synthetic products/customers/orders. Test Easebuzz sandbox success, failure, cancellation, redirect, callback/hash, duplicate notification, refund, and stock/status transitions; COD checkout; and Shiprocket in a non-live test mode or with any label/booking action disabled until a safe test path is confirmed.
- Test all public routes, same-origin cart/session behavior, checkout, account, order tracking, inventory, shipping, and SEO metadata. Record results before production approval.

## Production (approval required)

- Keep the existing database, historical HPOS orders, Razorpay, and current checkout in service until staging passes and the owner explicitly approves deployment.
- Immediately before cutover, take and verify recoverable file and database backups. Deploy only reviewed theme/gateway changes and retain a fast switch back to the prior theme/payment selection.
- If new live orders arrive, do not restore an old database snapshot over them. Roll back code/payment selection while retaining the current database; reconcile any in-flight payments and callbacks.
- Monitor orders, payment statuses, stock, checkout, COD, shipping, account access, and tracking after cutover.
