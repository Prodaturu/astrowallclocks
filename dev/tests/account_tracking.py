"""Synthetic local account login and WooCommerce order lookup regression."""

import http.cookiejar
import json
import re
import subprocess
import urllib.parse
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:8088"
COMPOSE = ["docker", "compose", "-f", str(ROOT / "dev/compose.yaml"), "--profile", "tools"]


def fixture():
    code = '''
        if (DB_NAME !== "astrowallclocks_test") throw new RuntimeException("Not local");
        $username = "astro_local_account_test";
        $uid = username_exists($username);
        if (!$uid) $uid = wp_create_user($username, "LOCAL_ONLY_ACCOUNT_TEST", "account-local@example.test");
        wp_set_password("LOCAL_ONLY_ACCOUNT_TEST", $uid);
        $user = new WP_User($uid);
        $user->set_role("customer");
        $product = wc_get_product(wc_get_product_id_by_sku("ASTRO-LOCAL-TEST"));
        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_customer_id($uid);
        $order->set_billing_email("account-local@example.test");
        $order->set_payment_method("cod");
        $order->calculate_totals();
        $order->save();
        $guest = wc_create_order();
        $guest->add_product($product, 1);
        $guest->set_billing_email("account-local@example.test");
        $guest->set_payment_method("cod");
        $guest->calculate_totals();
        $guest->save();
        echo "DATA=" . wp_json_encode(["id"=>$order->get_id(), "guest_id"=>$guest->get_id()]) . PHP_EOL;
    '''
    result = subprocess.run([*COMPOSE, "run", "--rm", "cli", "wp", "eval", code],
                            capture_output=True, text=True, check=True)
    return json.loads(next(line[5:] for line in result.stdout.splitlines() if line.startswith("DATA=")))


def post(opener, path, data):
    request = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode())
    with opener.open(request, timeout=20) as response:
        return response.read().decode("utf-8", "replace")


def get(opener, path):
    return opener.open(BASE + path, timeout=20).read().decode("utf-8", "replace")


def nonce(body, name):
    match = re.search(r'name="' + re.escape(name) + r'" value="([^"]+)"', body)
    assert match, name
    return match.group(1)


def main():
    order = fixture()
    guest = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    tracking = get(guest, "/track-order/")
    assert "track_order" in tracking
    bad = post(guest, "/track-order/", {
        "orderid": order["guest_id"], "order_email": "wrong@example.test", "track": "Track",
        "woocommerce-order-tracking-nonce": nonce(tracking, "woocommerce-order-tracking-nonce"),
        "_wp_http_referer": "/track-order/",
    })
    assert 'class="woocommerce-error"' in bad and "woocommerce-order-details" not in bad
    print("PASS tracking rejects wrong billing email")
    tracking = get(guest, "/track-order/")
    good = post(guest, "/track-order/", {
        "orderid": order["guest_id"], "order_email": "account-local@example.test", "track": "Track",
        "woocommerce-order-tracking-nonce": nonce(tracking, "woocommerce-order-tracking-nonce"),
        "_wp_http_referer": "/track-order/",
    })
    assert re.search(r"Order #\s*" + str(order["guest_id"]), re.sub(r"<[^>]+>", " ", good))
    print("PASS tracking returns matching synthetic order")

    customer = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    login = get(customer, "/my-account/")
    assert "woocommerce-form-login" in login
    dashboard = post(customer, "/my-account/", {
        "username": "astro_local_account_test", "password": "LOCAL_ONLY_ACCOUNT_TEST",
        "login": "Log in", "woocommerce-login-nonce": nonce(login, "woocommerce-login-nonce"),
        "_wp_http_referer": "/my-account/",
    })
    assert "woocommerce-MyAccount-navigation" in dashboard
    orders = get(customer, "/my-account/orders/")
    assert str(order["id"]) in orders
    print("PASS synthetic customer login and order history")


if __name__ == "__main__":
    main()
