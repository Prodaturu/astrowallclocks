# Isolated local development

This setup uses the existing `site/` frontend as the design source. It runs WordPress and MariaDB in local containers. The database has no connection to GoDaddy and port 8088 binds only to 127.0.0.1. The `theme/` directory is a development scaffold; cart and checkout integration are not complete.

Requirements: a running Docker-compatible engine and Docker Compose. Colima with QEMU works on this Mac. No production dump, credentials, payment keys, or customer data belong here.

1. From `dev/`, create `.env` with two fresh random passwords. It is ignored by Git.
2. Run `docker compose up -d db wordpress`.
3. Run `docker compose --profile tools run --rm cli wp core install --url=http://127.0.0.1:8088 --title='Astro Wall Clocks LOCAL' --admin_user=astro_local_admin --admin_email=dev@example.test --prompt=admin_password` and enter a **local-only** password.
4. Run `docker compose --profile tools run --rm cli wp plugin install woocommerce --version=11.3.1 --activate`.
5. Run `docker compose --profile tools run --rm -v "$PWD/seed-local.php:/tmp/seed-local.php:ro" cli wp eval-file /tmp/seed-local.php`. The script refuses to run unless the database name and site URL match this local setup. It creates a test product and synthetic customer, and turns off search indexing.
6. Run `docker compose --profile tools run --rm cli wp rewrite structure '/%postname%/'`, then `docker compose --profile tools run --rm cli wp theme activate astro-rebuild`.
7. Run `python3 copy-design-assets.py` to copy only public images referenced by the frontend into local WordPress. This reads public image URLs but does not read records or change production.
8. Open `http://127.0.0.1:8088`. Use only test products and synthetic customer/order data.

The theme shows the existing frontend design with its demo cart script disabled. Product links use the synthetic local product, and WooCommerce handles cart and checkout. This is still a **development scaffold**: product data and some page content are static, and no payment or shipping gateway is installed locally. Keep Easebuzz test credentials only in the isolated WordPress administration once the gateway is installed for sandbox testing. Never place them in this repository.

For this existing local instance, the local administrator password is stored in the Git-ignored `dev/.admin-password` file with owner-only permissions. The database passwords are in the Git-ignored `dev/.env` file. Do not reuse these values elsewhere.

The existing static design preview remains at https://prodaturu.github.io/astrowallclocks/ and has no working checkout.

Stop the local site with `docker compose down`. This preserves the local test database. Do not use `down -v` unless intentionally discarding all local test data.
