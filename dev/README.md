# Isolated local development

This setup uses the existing `site/` frontend as the design source. It runs WordPress and MariaDB in local containers. The database has no connection to GoDaddy and port 8088 binds only to 127.0.0.1. The `theme/` directory renders WooCommerce product values and uses native cart, classic checkout, account, and order tracking pages.

Requirements: a running Docker-compatible engine and Docker Compose. Colima with QEMU works on this Mac. No production dump, credentials, payment keys, or customer data belong here.

1. From `dev/`, create `.env` with two fresh random passwords. It is ignored by Git.
2. Run `docker compose up -d db wordpress`.
3. Run `docker compose --profile tools run --rm cli wp core install --url=http://127.0.0.1:8088 --title='Astro Wall Clocks LOCAL' --admin_user=astro_local_admin --admin_email=dev@example.test --prompt=admin_password` and enter a **local-only** password.
4. Run `docker compose --profile tools run --rm cli wp plugin install woocommerce --version=11.3.1 --activate`.
5. Run `docker compose --profile tools run --rm -v "$PWD/seed-local.php:/tmp/seed-local.php:ro" cli wp eval-file /tmp/seed-local.php`. The script refuses to run unless the database name and site URL match this local setup. It creates a test product and synthetic customer, and turns off search indexing.
6. Run `docker compose --profile tools run --rm cli wp wc hpos enable`, `docker compose --profile tools run --rm cli wp rewrite structure '/%postname%/'`, then `docker compose --profile tools run --rm cli wp theme activate astro-rebuild`.
7. Run `python3 copy-design-assets.py` to copy only public images referenced by the frontend into local WordPress. This reads public image URLs but does not read records or change production.
8. Open `http://127.0.0.1:8088`. Use only test products and synthetic customer/order data. Run `python3 tests/local_commerce.py` from `dev/` for product/cart checks; pass `--place-order` only when you intend to create another synthetic local COD order.

The theme preserves the existing frontend design while its product title, price, description, stock, images, and shop cards come from WooCommerce. It disables the static demo cart. WooCommerce handles cart quantities, shipping totals, classic checkout, COD, and HPOS orders. The local test shipping rate is ₹100. No online payment or shipment gateway is installed locally. A local-only must-use plugin suppresses outgoing mail and blocks requests to Easebuzz, Razorpay, and Shiprocket domains. Keep sandbox credentials only in the isolated staging administration when gateway testing starts; never place them in this repository.

For this existing local instance, the local administrator password is stored in the Git-ignored `dev/.admin-password` file with owner-only permissions. The database passwords are in the Git-ignored `dev/.env` file. Do not reuse these values elsewhere.

The existing static design preview remains at https://prodaturu.github.io/astrowallclocks/ and has no working checkout.

Stop the local site with `docker compose down`. This preserves the local test database. Do not use `down -v` unless intentionally discarding all local test data.
