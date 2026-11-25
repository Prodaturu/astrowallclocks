#!/usr/bin/env bash
# Run on GoDaddy as the staging account; temporary Basic-auth user is removed.
set -euo pipefail
stage=/home/bgt9xah2o3eo/staging-astrowallclocks
auth=/home/bgt9xah2o3eo/.astro-staging-auth
host=staging.astrowallclocks.com
base="https://$host"
ip=132.148.179.26
test "$(wp --path="$stage" config get DB_NAME 2>/dev/null)" = bgt9xah2o3eo_awstage
work=$(mktemp -d)
user="astro_probe_$(date +%s)"
cleanup() {
    if [ -f "$auth" ]; then
        grep -v "^${user}:" "$auth" > "$work/auth-clean" || true
        cat "$work/auth-clean" > "$auth"
    fi
    rm -rf "$work"
}
trap cleanup EXIT
trap 'echo "FAIL staging page check at line ${LINENO}"' ERR
pass=$(openssl rand -hex 20)
hash=$(printf '%s\n' "$pass" | openssl passwd -apr1 -stdin)
printf '%s:%s\n' "$user" "$hash" >> "$auth"
printf 'user = "%s:%s"\n' "$user" "$pass" > "$work/curl-config"
chmod 600 "$work/curl-config"
: > "$work/cookies"
chmod 600 "$work/cookies"
fetch() {
    local path=$1 output=$2
    local status
    status=$(curl --silent --show-error --location --max-time 20 \
        --resolve "$host:443:$ip" --config "$work/curl-config" \
        --cookie "$work/cookies" --cookie-jar "$work/cookies" \
        --output "$work/$output" --write-out '%{http_code} %{ssl_verify_result}' "$base$path")
    test "$status" = '200 0'
}
fetch / home
grep -q 'name="viewport"' "$work/home"
grep -q '>My Account</a>' "$work/home"
fetch /shop/ shop
if ! grep -q 'shop-product' "$work/shop"; then
    echo "shop design marker missing; collection=$(grep -c 'THE COLLECTION' "$work/shop" || true), coming=$(grep -ci 'Great things are on the horizon' "$work/shop" || true)"
    grep -o -m1 '<title>[^<]*' "$work/shop" || true
    grep -o -m2 '<h1[^>]*>[^<]*' "$work/shop" || true
    exit 1
fi
fetch /product/astro-wall-clock/ product
grep -q 'name="quantity"' "$work/product"
grep -q 'product-gallery' "$work/product"
fetch /my-account/ account
grep -q 'woocommerce-form-login' "$work/account"
fetch /track-order/ tracking
grep -q 'track_order' "$work/tracking"
pass_customer=$(openssl rand -hex 20)
fixture=$(ASTRO_STAGE_CUSTOMER_PASS="$pass_customer" wp --path="$stage" eval-file /home/bgt9xah2o3eo/.astro-stage-test-account-fixture.php 2>/dev/null)
registered_order=$(printf '%s\n' "$fixture" | sed -n 's/^REGISTERED_ORDER_ID=//p')
guest_order=$(printf '%s\n' "$fixture" | sed -n 's/^GUEST_ORDER_ID=//p')
test "$registered_order" -gt 0
test "$guest_order" -gt 0
tracking_nonce=$(sed -n 's/.*name="woocommerce-order-tracking-nonce" value="\([^"]*\)".*/\1/p' "$work/tracking" | head -1)
test -n "$tracking_nonce"
printf 'orderid=%s&order_email=wrong%%40example.test&track=Track&woocommerce-order-tracking-nonce=%s&_wp_http_referer=%%2Ftrack-order%%2F' "$guest_order" "$tracking_nonce" > "$work/track-wrong-form"
status=$(curl --silent --show-error --location --max-time 20 --resolve "$host:443:$ip" \
    --config "$work/curl-config" --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --data-binary "@$work/track-wrong-form" --output "$work/track-wrong" --write-out '%{http_code} %{ssl_verify_result}' "$base/track-order/")
test "$status" = '200 0'
grep -q 'woocommerce-error' "$work/track-wrong"
fetch /track-order/ tracking
tracking_nonce=$(sed -n 's/.*name="woocommerce-order-tracking-nonce" value="\([^"]*\)".*/\1/p' "$work/tracking" | head -1)
printf 'orderid=%s&order_email=account-staging%%40example.test&track=Track&woocommerce-order-tracking-nonce=%s&_wp_http_referer=%%2Ftrack-order%%2F' "$guest_order" "$tracking_nonce" > "$work/track-good-form"
status=$(curl --silent --show-error --location --max-time 20 --resolve "$host:443:$ip" \
    --config "$work/curl-config" --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --data-binary "@$work/track-good-form" --output "$work/track-good" --write-out '%{http_code} %{ssl_verify_result}' "$base/track-order/")
test "$status" = '200 0'
grep -q "Order #.*$guest_order" "$work/track-good"
login_nonce=$(sed -n 's/.*name="woocommerce-login-nonce" value="\([^"]*\)".*/\1/p' "$work/account" | head -1)
test -n "$login_nonce"
printf 'username=astro_stage_customer_test&password=%s&login=Log+in&woocommerce-login-nonce=%s&_wp_http_referer=%%2Fmy-account%%2F' "$pass_customer" "$login_nonce" > "$work/login-form"
chmod 600 "$work/login-form"
status=$(curl --silent --show-error --location --max-time 20 --resolve "$host:443:$ip" \
    --config "$work/curl-config" --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --data-binary "@$work/login-form" --output "$work/dashboard" --write-out '%{http_code} %{ssl_verify_result}' "$base/my-account/")
test "$status" = '200 0'
grep -q 'woocommerce-MyAccount-navigation' "$work/dashboard"
fetch /my-account/orders/ orders
grep -q "$registered_order" "$work/orders"
id=$(wp --path="$stage" eval 'echo wc_get_product_id_by_sku("ASTRO-STAGING-TEST");' 2>/dev/null)
test "$id" -gt 0
fetch "/?add-to-cart=$id" add_cart
fetch /checkout/ checkout
grep -q 'payment_method_payeasebuzz' "$work/checkout"
grep -q 'payment_method_cod' "$work/checkout"
fetch /wp-content/themes/astro-rebuild/design/assets/modern.css responsive_css
grep -q '@media(max-width:760px)' "$work/responsive_css"
echo 'PASS authenticated staging home, shop, product, account, and tracking pages'
echo 'PASS synthetic account login, order history, and guest tracking with wrong-email denial'
echo 'PASS classic checkout renders Easebuzz dummy gateway and COD over trusted HTTPS'
echo 'PASS mobile viewport and responsive stylesheet served; visual layout still requires browser review'
