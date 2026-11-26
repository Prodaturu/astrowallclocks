"""Synthetic local account login and WooCommerce order lookup regression."""

import http.cookiejar
import html
import json
import re
import secrets
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
        echo "DATA=" . wp_json_encode(["id"=>$order->get_id(), "guest_id"=>$guest->get_id(), "uid"=>$uid]) . PHP_EOL;
    '''
    result = subprocess.run([*COMPOSE, "run", "--rm", "cli", "wp", "eval", code],
                            capture_output=True, text=True, check=True)
    return json.loads(next(line[5:] for line in result.stdout.splitlines() if line.startswith("DATA=")))


def reset_fixture():
    username = "astro_local_reset_" + secrets.token_hex(4)
    code = f'''
        if (DB_NAME !== "astrowallclocks_test") throw new RuntimeException("Not local");
        $uid = wp_create_user("{username}", "LOCAL_ONLY_OLD_PASSWORD", "{username}@example.test");
        if (is_wp_error($uid)) throw new RuntimeException("Synthetic user creation failed");
        (new WP_User($uid))->set_role("customer");
        $key = get_password_reset_key(get_userdata($uid));
        if (is_wp_error($key)) throw new RuntimeException("Synthetic reset key failed");
        echo "DATA=" . wp_json_encode(["uid"=>$uid,"key"=>$key,"username"=>"{username}"]) . PHP_EOL;
    '''
    result = subprocess.run([*COMPOSE, "run", "--rm", "cli", "wp", "eval", code],
                            capture_output=True, text=True, check=True)
    return json.loads(next(line[5:] for line in result.stdout.splitlines() if line.startswith("DATA=")))


def set_registration(value):
    assert value in ("yes", "no")
    code = f'if (DB_NAME !== "astrowallclocks_test") throw new RuntimeException("Not local"); update_option("woocommerce_enable_myaccount_registration", "{value}");'
    subprocess.run([*COMPOSE, "run", "--rm", "cli", "wp", "eval", code],
                   capture_output=True, text=True, check=True)


def page(opener, path, data=None):
    request = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode() if data is not None else None)
    with opener.open(request, timeout=20) as response:
        return response.geturl(), response.read().decode("utf-8", "replace")


def post(opener, path, data):
    return page(opener, path, data)[1]


def get(opener, path):
    return page(opener, path)[1]


def nonce(body, name):
    match = re.search(r'name="' + re.escape(name) + r'" value="([^"]+)"', body)
    assert match, name
    return match.group(1)


def main():
    order = fixture()
    anon = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    for endpoint in ("/my-account/", "/my-account/orders/", "/my-account/edit-account/", f"/my-account/view-order/{order['id']}/"):
        url, body = page(anon, endpoint)
        assert url.startswith(BASE + "/login/?redirect_to=")
        assert "woocommerce-form-login" in body and "woocommerce-form-register" not in body
    assert "/my-account/orders/" in html.unescape(get(anon, "/login/?redirect_to=/my-account/orders/"))
    print("PASS protected account endpoints redirect to login with destination")

    login_only = get(anon, "/login/")
    register_only = get(anon, "/register/")
    assert "woocommerce-form-login login" in login_only and "woocommerce-form-register register" not in login_only
    assert "woocommerce-form-register register" in register_only and "woocommerce-form-login login" not in register_only
    assert "rememberme" in login_only and "lost-password" in login_only
    assert 'href="http://127.0.0.1:8088/register/"' in login_only
    assert 'href="http://127.0.0.1:8088/login/"' in register_only
    print("PASS separate native forms, Remember Me, and recovery links")

    registration = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    register_page = get(registration, "/register/")
    username = "astro_local_new_" + secrets.token_hex(4)
    registered_url, registered = page(registration, "/register/", {
        "username": username, "email": username + "@example.test", "password": "LOCAL_ONLY_ACCOUNT_TEST",
        "register": "Register", "woocommerce-register-nonce": nonce(register_page, "woocommerce-register-nonce"),
        "astro_register_page": "1", "_wp_http_referer": "/register/",
    })
    assert registered_url == BASE + "/my-account/" and "woocommerce-MyAccount-navigation" in registered
    assert username in get(registration, "/my-account/edit-account/")
    assert page(registration, "/login/")[0] == BASE + "/my-account/"
    other_order = get(registration, f"/my-account/view-order/{order['id']}/")
    assert "woocommerce-order-details" not in other_order
    print("PASS synthetic registration and authenticated account session")

    duplicate = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    duplicate_page = get(duplicate, "/register/")
    duplicate_result = post(duplicate, "/register/", {
        "username": username, "email": username + "@example.test", "password": "LOCAL_ONLY_ACCOUNT_TEST",
        "register": "Register", "woocommerce-register-nonce": nonce(duplicate_page, "woocommerce-register-nonce"),
        "astro_register_page": "1",
    })
    assert "woocommerce-error" in duplicate_result and "woocommerce-form-register" in duplicate_result
    print("PASS native duplicate-account validation")
    try:
        set_registration("no")
        unavailable = get(duplicate, "/register/")
        assert "Customer registration is currently unavailable" in unavailable
        assert "woocommerce-form-register register" not in unavailable
    finally:
        set_registration("yes")
    print("PASS registration page follows WooCommerce account-creation setting")

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

    customer_cookies = http.cookiejar.CookieJar()
    customer = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(customer_cookies))
    login = get(customer, "/login/?redirect_to=/my-account/orders/")
    invalid = post(customer, "/login/?redirect_to=/my-account/orders/", {
        "username": "astro_local_account_test", "password": "WRONG_LOCAL_PASSWORD", "login": "Log in",
        "woocommerce-login-nonce": nonce(login, "woocommerce-login-nonce"),
        "astro_login_page": "1", "redirect": BASE + "/my-account/orders/",
    })
    assert "woocommerce-error" in invalid and "woocommerce-form-login" in invalid
    login = get(customer, "/login/?redirect_to=/my-account/orders/")
    orders_url, orders = page(customer, "/login/?redirect_to=/my-account/orders/", {
        "username": "astro_local_account_test", "password": "LOCAL_ONLY_ACCOUNT_TEST", "rememberme": "forever",
        "login": "Log in", "woocommerce-login-nonce": nonce(login, "woocommerce-login-nonce"),
        "astro_login_page": "1", "redirect": BASE + "/my-account/orders/",
    })
    assert orders_url == BASE + "/my-account/orders/"
    assert any("wordpress_logged_in" in c.name and c.expires for c in customer_cookies)
    dashboard = get(customer, "/my-account/")
    assert "woocommerce-MyAccount-navigation" in dashboard and "astro_local_account_test" in dashboard
    assert str(order["id"]) in orders
    own_order = get(customer, f"/my-account/view-order/{order['id']}/")
    assert "woocommerce-order-details" in own_order
    for endpoint in ("downloads", "edit-address", "edit-account", "customer-logout"):
        assert "woocommerce-MyAccount-navigation-link--" + endpoint in dashboard
    assert "woocommerce-MyAccount-content" in get(customer, "/my-account/downloads/")
    assert "woocommerce-MyAccount-content" in get(customer, "/my-account/edit-address/")
    assert "astro_local_account_test" in get(customer, "/my-account/edit-account/")
    assert page(customer, "/register/")[0] == BASE + "/my-account/"
    print("PASS invalid login, Remember Me, destination, dashboard, orders, and details")

    logout_link = re.search(r'href="([^"]*customer-logout[^"]+)"', dashboard)
    assert logout_link
    logout_parts = urllib.parse.urlsplit(html.unescape(logout_link.group(1)))
    logout_url, _ = page(customer, logout_parts.path + ("?" + logout_parts.query if logout_parts.query else ""))
    assert logout_url.startswith(BASE + "/login/")
    assert page(customer, "/my-account/")[0].startswith(BASE + "/login/")
    print("PASS logout removes authenticated account access")

    recovery = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    lost = get(recovery, "/my-account/lost-password/")
    assert "woocommerce-lost-password-nonce" in lost
    recovered_url, recovered = page(recovery, "/my-account/lost-password/", {
        "user_login": "astro_local_account_test", "wc_reset_password": "Reset password",
        "woocommerce-lost-password-nonce": nonce(lost, "woocommerce-lost-password-nonce"),
    })
    assert "reset-link-sent=true" in recovered_url and "woocommerce-message" in recovered
    print("PASS native password-recovery request; local email suppressed")

    reset = reset_fixture()  # Key stays in this test process; never print it.
    reset_session = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    link = "/my-account/?" + urllib.parse.urlencode({"key": reset["key"], "id": reset["uid"]})
    reset_url, reset_form = page(reset_session, link)
    assert "show-reset-form=true" in reset_url and "woocommerce-reset-password-nonce" in reset_form
    assert "key=" not in reset_url
    finished_url, finished = page(reset_session, urllib.parse.urlsplit(reset_url).path + "?show-reset-form=true", {
        "password_1": "LOCAL_ONLY_NEW_PASSWORD", "password_2": "LOCAL_ONLY_NEW_PASSWORD",
        "reset_key": nonce(reset_form, "reset_key"), "reset_login": nonce(reset_form, "reset_login"),
        "wc_reset_password": "true", "woocommerce-reset-password-nonce": nonce(reset_form, "woocommerce-reset-password-nonce"),
    })
    assert finished_url.startswith(BASE + "/login/?password-reset=true")
    assert "Your password has been reset" in finished
    new_login = get(reset_session, "/login/")
    new_url, new_dashboard = page(reset_session, "/login/", {
        "username": reset["username"], "password": "LOCAL_ONLY_NEW_PASSWORD", "login": "Log in",
        "woocommerce-login-nonce": nonce(new_login, "woocommerce-login-nonce"), "astro_login_page": "1",
        "redirect": BASE + "/my-account/",
    })
    assert new_url == BASE + "/my-account/" and "woocommerce-MyAccount-navigation" in new_dashboard
    print("PASS native reset link, new password, and post-reset login")


if __name__ == "__main__":
    main()
