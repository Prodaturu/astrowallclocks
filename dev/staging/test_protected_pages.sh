#!/usr/bin/env bash
# Run on GoDaddy as the staging account; temporary Basic-auth user is removed.
set -Eeuo pipefail
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
    if [ "$status" != '200 0' ]; then
        echo "FAIL staging $path returned $status" >&2
        return 1
    fi
}
fetch / home
grep -q 'name="viewport"' "$work/home"
grep -q '>Login</a>' "$work/home"
grep -q '>Register</a>' "$work/home"
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
fetch /login/ login
grep -q 'woocommerce-form-login' "$work/login"
fetch /register/ register
grep -q 'woocommerce-form-register' "$work/register"
register_nonce=$(sed -n 's/.*name="woocommerce-register-nonce" value="\([^"]*\)".*/\1/p' "$work/register" | head -1)
test -n "$register_nonce"
register_user="astro_stage_signup_$(date +%s)"
register_email="${register_user}@example.test"
register_pass=$(openssl rand -hex 20)
printf 'username=%s&email=%s%%40example.test&password=%s&register=Register&astro_register_page=1&woocommerce-register-nonce=%s&_wp_http_referer=%%2Fregister%%2F' \
    "$register_user" "$register_user" "$register_pass" "$register_nonce" > "$work/register-form"
chmod 600 "$work/register-form"
status=$(curl --silent --show-error --location --max-time 20 --resolve "$host:443:$ip" \
    --config "$work/curl-config" --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --data-binary "@$work/register-form" --output "$work/register-result" \
    --write-out '%{http_code} %{ssl_verify_result}' "$base/register/")
test "$status" = '200 0'
test "$(wp --path="$stage" user get "$register_email" --field=ID 2>/dev/null)" -gt 0
: > "$work/cookies"
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
login_nonce=$(sed -n 's/.*name="woocommerce-login-nonce" value="\([^"]*\)".*/\1/p' "$work/login" | head -1)
test -n "$login_nonce"
printf 'username=astro_stage_customer_test&password=%s&login=Log+in&woocommerce-login-nonce=%s&_wp_http_referer=%%2Fmy-account%%2F' "$pass_customer" "$login_nonce" > "$work/login-form"
chmod 600 "$work/login-form"
status=$(curl --silent --show-error --location --max-time 20 --resolve "$host:443:$ip" \
    --config "$work/curl-config" --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --data-binary "@$work/login-form" --output "$work/dashboard" --write-out '%{http_code} %{ssl_verify_result}' "$base/login/")
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
grep -q 'woocommerce-checkout' "$work/checkout"
fetch /wp-content/themes/astro-rebuild/design/assets/modern.css responsive_css
grep -q '@media(max-width:760px)' "$work/responsive_css"
stock_before=$(wp --path="$stage" eval 'echo wc_get_product(wc_get_product_id_by_sku("ASTRO-STAGING-TEST"))->get_stock_quantity();' 2>/dev/null)
checkout_nonce=$(sed -n 's/.*name="woocommerce-process-checkout-nonce" value="\([^"]*\)".*/\1/p' "$work/checkout" | head -1)
test -n "$checkout_nonce"
shipping_method=$(sed -n 's/.*name="shipping_method\[0\]"[^>]*value="\([^"]*\)".*/\1/p' "$work/checkout" | head -1)
cat > "$work/cod-form" <<EOF
billing_first_name=Synthetic&billing_last_name=Customer&billing_country=IN&billing_address_1=1+Test+Lane&billing_city=Hyderabad&billing_state=TS&billing_postcode=500000&billing_phone=0000000000&billing_email=synthetic-checkout%40example.test&payment_method=cod&woocommerce-process-checkout-nonce=$checkout_nonce&_wp_http_referer=%2Fcheckout%2F&terms=on${shipping_method:+&shipping_method%5B0%5D=$shipping_method}
EOF
chmod 600 "$work/cod-form"
status=$(curl --silent --show-error --max-time 30 --resolve "$host:443:$ip" \
    --config "$work/curl-config" --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --header 'X-Requested-With: XMLHttpRequest' --data-binary "@$work/cod-form" \
    --output "$work/cod-result" --write-out '%{http_code} %{ssl_verify_result}' "$base/?wc-ajax=checkout")
test "$status" = '200 0'
order_id=$(python3 - "$work/cod-result" <<'PY'
import json, re, sys
response = json.load(open(sys.argv[1]))
if response.get('result') != 'success':
    raise SystemExit('FAIL synthetic COD checkout response')
match = re.search(r'order-received/(\d+)', response.get('redirect', ''))
if not match:
    raise SystemExit('FAIL COD order ID missing')
print(match.group(1))
PY
)
ASTRO_STAGE_COD_ORDER_ID="$order_id" ASTRO_STAGE_STOCK_BEFORE="$stock_before" \
    wp --path="$stage" eval-file /home/bgt9xah2o3eo/.astro-stage-deploy/verify_cod_order.php
echo 'PASS authenticated staging home, shop, product, login, register, account, and tracking pages'
echo 'PASS synthetic account login, order history, and guest tracking with wrong-email denial'
echo 'PASS native WooCommerce customer registration with synthetic account'
echo 'PASS classic checkout renders Easebuzz dummy gateway and COD over trusted HTTPS'
echo 'PASS mobile viewport and responsive stylesheet served'
