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
id=$(wp --path="$stage" eval 'echo wc_get_product_id_by_sku("ASTRO-STAGING-TEST");' 2>/dev/null)
test "$id" -gt 0
fetch "/?add-to-cart=$id" add_cart
fetch /checkout/ checkout
grep -q 'payment_method_payeasebuzz' "$work/checkout"
grep -q 'payment_method_cod' "$work/checkout"
fetch /wp-content/themes/astro-rebuild/design/assets/modern.css responsive_css
grep -q '@media(max-width:760px)' "$work/responsive_css"
echo 'PASS authenticated staging home, shop, product, account, and tracking pages'
echo 'PASS classic checkout renders Easebuzz dummy gateway and COD over trusted HTTPS'
echo 'PASS mobile viewport and responsive stylesheet served; visual layout still requires browser review'
