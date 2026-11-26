"""Exercise only the isolated localhost WooCommerce shop.

By default this checks product rendering and cart behavior. Pass --place-order
to create one synthetic COD order; no online gateway is contacted.
"""

import argparse
import html
import http.cookiejar
import json
import re
import subprocess
import urllib.parse
import urllib.request
from pathlib import Path


BASE = "http://127.0.0.1:8088"
REPO = Path(__file__).resolve().parents[2]
COMPOSE = ["docker", "compose", "-f", str(REPO / "dev" / "compose.yaml"), "--profile", "tools"]


def local_wp_eval(source):
    result = subprocess.run(
        [*COMPOSE, "run", "--rm", "cli", "wp", "eval", source],
        check=True, text=True, capture_output=True,
    )
    for line in result.stdout.splitlines():
        if line.startswith("ASTRO_TEST_JSON="):
            return json.loads(line.removeprefix("ASTRO_TEST_JSON="))
    raise AssertionError("Local WP-CLI did not return test data")


def request(opener, path, data=None, ajax=False):
    headers = {}
    if data is not None:
        data = urllib.parse.urlencode(data).encode()
        headers["Content-Type"] = "application/x-www-form-urlencoded; charset=UTF-8"
    if ajax:
        headers["X-Requested-With"] = "XMLHttpRequest"
    response = opener.open(urllib.request.Request(BASE + path, data=data, headers=headers), timeout=20)
    return response, response.read().decode("utf-8", "replace")


def input_value(markup, name):
    pattern = r'<input\b(?=[^>]*\bname=["\']' + re.escape(name) + r'["\'])[^>]*>'
    match = re.search(pattern, markup)
    if not match:
        raise AssertionError(f"Missing local form field: {name}")
    value = re.search(r'\bvalue=["\']([^"\']*)["\']', match.group())
    if not value:
        raise AssertionError(f"Missing local form value: {name}")
    return html.unescape(value.group(1))


def cart_data(opener):
    _, body = request(opener, "/wp-json/wc/store/v1/cart")
    return json.loads(body)


def set_local_fixture(name, price):
    source = f'''
        if (DB_NAME !== "astrowallclocks_test" || get_option("siteurl") !== "{BASE}") {{
            throw new RuntimeException("Not the isolated local store");
        }}
        $product = wc_get_product(wc_get_product_id_by_sku("ASTRO-LOCAL-TEST"));
        $product->set_name({json.dumps(name)});
        $product->set_regular_price({json.dumps(str(price))});
        $product->save();
        echo "ASTRO_TEST_JSON=" . wp_json_encode(["ok" => true]) . PHP_EOL;
    '''
    assert local_wp_eval(source)["ok"]


def main(place_order):
    state = local_wp_eval('''
        $id = wc_get_product_id_by_sku("ASTRO-LOCAL-TEST");
        $product = $id ? wc_get_product($id) : false;
        echo "ASTRO_TEST_JSON=" . wp_json_encode([
            "site" => get_option("siteurl"), "db" => DB_NAME,
            "hpos" => \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled(),
            "cod" => WC()->payment_gateways()->payment_gateways()["cod"]->enabled,
            "mail_guard" => (bool) has_filter("pre_wp_mail"),
            "enabled_gateways" => array_values(array_map(fn($g) => $g->id, array_filter(WC()->payment_gateways()->payment_gateways(), fn($g) => $g->enabled === "yes"))),
            "id" => $id, "price" => $product ? $product->get_price() : null,
            "name" => $product ? $product->get_name() : null,
            "stock" => $product ? $product->get_stock_quantity() : null,
        ]) . PHP_EOL;
    ''')
    assert state["site"] == BASE and state["db"] == "astrowallclocks_test"
    assert state["hpos"] and state["cod"] == "yes" and state["mail_guard"]
    assert "cod" in state["enabled_gateways"]
    assert set(state["enabled_gateways"]).issubset({"cod", "payeasebuzz"})
    assert state["id"] and state["stock"] >= 2

    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    product_id = state["id"]
    for path in ("/", "/shop/", "/product/astro-wall-clock/"):
        response, body = request(opener, path)
        decoded = html.unescape(body)
        assert response.status == 200, path
        assert state["price"] in decoded.replace(",", ""), path
        assert "9,999" not in decoded, path
        assert "demo cart" not in decoded.lower(), path
    assert "Astro Wall Clock LOCAL TEST" in html.unescape(request(opener, "/shop/")[1])
    assert "THE COLLECTION" in request(opener, "/shop/")[1]
    assert "product-gallery" in request(opener, "/product/astro-wall-clock/")[1]
    home_buy = re.search(r'<a class="button button-gold" href="([^"]+add-to-cart=[^"]+)"', request(opener, "/")[1])
    assert home_buy, "Home Buy Now link missing"
    buy_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    response, _ = request(buy_opener, html.unescape(home_buy.group(1)).removeprefix(BASE))
    assert response.url.startswith(BASE + "/product/astro-wall-clock/")
    assert cart_data(buy_opener)["items_count"] == 1
    print("PASS homepage Buy Now lands on product and adds one item")

    # Prove the design reads current WooCommerce values instead of the static snapshot.
    dynamic_name = "Astro Dynamic Test Clock"
    dynamic_price = str(int(float(state["price"])) + 123)
    try:
        set_local_fixture(dynamic_name, dynamic_price)
        for path in ("/", "/shop/", "/product/astro-wall-clock/"):
            markup = html.unescape(request(opener, path)[1])
            assert dynamic_price in markup.replace(",", ""), path
            assert dynamic_name in markup, path
    finally:
        set_local_fixture(state["name"], state["price"])
    print("PASS product name and price follow WooCommerce changes")

    # Use the actual WooCommerce add-to-cart link, then navigate and refresh.
    request(opener, f"/product/astro-wall-clock/?add-to-cart={product_id}&quantity=1")
    assert cart_data(opener)["items_count"] == 1
    request(opener, "/shop/")
    request(opener, "/product/astro-wall-clock/")
    assert cart_data(opener)["items_count"] == 1
    home = request(opener, "/")[1]
    assert re.search(r'<b class="cart-count" aria-hidden="true">1</b>', home)
    assert '<a href="http://127.0.0.1:8088/my-account/">My Account</a>' in home

    cart_html = request(opener, "/cart/")[1]
    qty_field = re.search(r'name="(cart\[[^\"]+\]\[qty\])"', cart_html)
    assert qty_field, "WooCommerce quantity control missing"
    request(opener, "/cart/", {
        qty_field.group(1): "2",
        "woocommerce-cart-nonce": input_value(cart_html, "woocommerce-cart-nonce"),
        "update_cart": "Update cart",
    })
    cart = cart_data(opener)
    assert cart["items_count"] == 2, cart["items_count"]
    assert cart["items"][0]["quantity"] == 2
    assert int(cart["totals"]["total_items"]) == int(float(state["price"]) * 200)
    assert cart_data(opener)["items_count"] == 2

    print("PASS product data, real cart, quantity update, totals, navigation and refresh")
    if not place_order:
        return

    checkout = request(opener, "/checkout/")[1]
    assert "checkout woocommerce-checkout" in checkout
    shipping = re.search(r'value="(flat_rate:\d+)"', checkout)
    assert shipping, "Synthetic shipping method missing"
    fields = {
        "billing_first_name": "Synthetic",
        "billing_last_name": "Customer",
        "billing_country": "IN",
        "billing_address_1": "1 Test Lane",
        "billing_city": "Hyderabad",
        "billing_state": "TS",
        "billing_postcode": "500000",
        "billing_phone": "0000000000",
        "billing_email": "synthetic-checkout@example.test",
        "shipping_method[0]": shipping.group(1),
        "payment_method": "cod",
        "woocommerce-process-checkout-nonce": input_value(checkout, "woocommerce-process-checkout-nonce"),
        "_wp_http_referer": "/checkout/",
        "terms": "on",
    }
    _, body = request(opener, "/?wc-ajax=checkout", fields, ajax=True)
    result = json.loads(body)
    if result.get("result") != "success":
        raise AssertionError("Synthetic COD checkout failed: " + re.sub(r"<[^>]+>", " ", result.get("messages", ""))[:180])
    order_id_match = re.search(r"order-received/(\d+)", result["redirect"])
    assert order_id_match, "No WooCommerce order in checkout redirect"
    order_id = int(order_id_match.group(1))

    order = local_wp_eval(f'''
        $order = wc_get_order({order_id});
        $product = wc_get_product({product_id});
        global $wpdb;
        $table = $wpdb->prefix . "wc_orders";
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", {order_id}));
        $items = $order->get_items();
        $first_item = reset($items);
        echo "ASTRO_TEST_JSON=" . wp_json_encode([
            "exists" => (bool) $exists, "status" => $order->get_status(),
            "gateway" => $order->get_payment_method(), "total" => $order->get_total(),
            "shipping" => $order->get_shipping_total(), "stock" => $product->get_stock_quantity(),
            "quantity" => $first_item ? $first_item->get_quantity() : 0,
        ]) . PHP_EOL;
    ''')
    assert order["exists"] and order["gateway"] == "cod" and order["quantity"] == 2
    assert order["status"] in ("processing", "on-hold")
    assert int(order["stock"]) == int(state["stock"]) - 2
    assert float(order["total"]) == 2 * float(state["price"]) + float(order["shipping"])
    assert float(order["shipping"]) == 100.0
    assert cart_data(opener)["items_count"] == 0
    print(f"PASS synthetic COD order {order_id}: HPOS, status {order['status']}, total {order['total']}, stock {order['stock']}, no live gateway")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--place-order", action="store_true", help="Create one synthetic local COD order")
    main(parser.parse_args().place_order)
