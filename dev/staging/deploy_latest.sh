#!/usr/bin/env bash
# Run on the existing GoDaddy staging account only, after a verified backup.
set -euo pipefail
umask 077
stage=/home/bgt9xah2o3eo/staging-astrowallclocks
bundle=/home/bgt9xah2o3eo/.astro-stage-deploy
backup=/home/bgt9xah2o3eo/.astro-stage-backups/20261126T021439Z
test "$(wp --path="$stage" config get DB_NAME --type=constant 2>/dev/null)" = bgt9xah2o3eo_awstage
test "$(wp --path="$stage" option get siteurl 2>/dev/null)" = https://staging.astrowallclocks.com
test -s "$backup/staging.sql.gz"
test -s "$backup/staging-files.tar.gz"
test -s "$bundle/astro-stage-theme-latest.tar.gz"
test -s "$bundle/woocommerce_easebuzz_plugin-hardened.zip"
test -s "$bundle/astro-stage-public-assets.tar.gz"
test "$(sha256sum "$bundle/astro-stage-theme-latest.tar.gz" | cut -d' ' -f1)" = 0e53f2234ad30bac38ca4ab8c15b1a638f9844c3dd410b7bf03a385558288d58
test "$(sha256sum "$bundle/woocommerce_easebuzz_plugin-hardened.zip" | cut -d' ' -f1)" = 2c99996115099d5aca65a78e5ad60a51bafaac62a9c55879cd42825e4343b7ed
test "$(sha256sum "$bundle/astro-stage-public-assets.tar.gz" | cut -d' ' -f1)" = 2b90a3e81fad0d14cb4be14adcde43624bec0bf940a18e9524984f8da8cf79b8
test -f "$stage/wp-content/themes/astro-rebuild/design/index.html"

# Stage-only outbound and mail protection precedes the gateway update.
install -m 600 "$bundle/astro-staging-safety.php" "$stage/wp-content/mu-plugins/astro-staging-safety.php"
tar -xzf "$bundle/astro-stage-theme-latest.tar.gz" -C "$stage/wp-content/themes/astro-rebuild"
find "$stage/wp-content/themes/astro-rebuild" -type d -exec chmod 755 {} +
find "$stage/wp-content/themes/astro-rebuild" -type f -exec chmod 644 {} +
mkdir -p "$bundle/public-assets-extracted"
tar -xzf "$bundle/astro-stage-public-assets.tar.gz" -C "$bundle/public-assets-extracted"
for rel in 2025/10/FEVICON-1-50x50.jpg 2025/10/Logo-scaled-1-300x54.png \
           2025/09/FOOTER.png 2025/08/product1.png 2026/02/Hindi.jpg \
           2026/02/Kannda.jpg 2026/02/Telugu.jpg 2026/02/English.jpg; do
    install -D -m 644 "$bundle/public-assets-extracted/$rel" "$stage/wp-content/uploads/$rel"
done
wp --path="$stage" plugin install "$bundle/woocommerce_easebuzz_plugin-hardened.zip" --force --activate --quiet 2>/dev/null

for slug in login register; do
    if ! wp --path="$stage" post list --post_type=page --post_status=any --field=post_name 2>/dev/null | grep -qx "$slug"; then
        wp --path="$stage" post create --post_type=page --post_status=publish --post_name="$slug" \
            --post_title="${slug^}" --post_content='[woocommerce_my_account]' --porcelain 2>/dev/null >/dev/null
    fi
done
wp --path="$stage" option update woocommerce_enable_myaccount_registration yes --quiet 2>/dev/null
wp --path="$stage" cache flush --quiet 2>/dev/null
test "$(wp --path="$stage" config get DB_NAME --type=constant 2>/dev/null)" = bgt9xah2o3eo_awstage
test "$(wp --path="$stage" theme list --status=active --field=name 2>/dev/null)" = astro-rebuild
echo 'PASS staging-only theme, plugin, login/register pages, and registration setting deployed'
