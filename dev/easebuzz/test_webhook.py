"""HTTP regression tests for the local-only Easebuzz webhook with fake values."""

import hashlib
import json
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ["docker", "compose", "-f", str(ROOT / "dev/compose.yaml"), "--profile", "tools"]
URL = "http://127.0.0.1:8088/wp-content/plugins/woocommerce_easebuzz_plugin/update_webhook.php"
KEY = "ASTRO_LOCAL_FAKE_KEY"
SALT = "ASTRO_LOCAL_FAKE_SALT"
HASH_FIELDS = "udf10 udf9 udf8 udf7 udf6 udf5 udf4 udf3 udf2 udf1 email firstname productinfo amount txnid key".split()


def wp(source):
    result = subprocess.run([*COMPOSE, "run", "--rm", "cli", "wp", "eval", source],
                            check=True, capture_output=True, text=True)
    return json.loads(next(line[5:] for line in result.stdout.splitlines() if line.startswith("DATA=")))


def make_order():
    return wp('''
        if (DB_NAME !== "astrowallclocks_test") throw new RuntimeException("Not local");
        $p = wc_get_product(wc_get_product_id_by_sku("ASTRO-LOCAL-TEST"));
        $o = wc_create_order();
        $o->add_product($p, 1);
        $o->set_payment_method("payeasebuzz");
        $o->set_billing_first_name("Synthetic");
        $o->set_billing_email("webhook-local@example.test");
        $o->calculate_totals();
        $tx = $o->get_id() . "-mock-webhook";
        $o->update_meta_data("_astro_easebuzz_expected_txnid", $tx);
        $o->save();
        echo "DATA=" . wp_json_encode(["id"=>$o->get_id(), "key"=>$o->get_order_key(), "txnid"=>$tx, "amount"=>wc_format_decimal($o->get_total(), 2)]) . PHP_EOL;
    ''')


def state(order_id):
    return wp(f'''
        if (DB_NAME !== "astrowallclocks_test") throw new RuntimeException("Not local");
        $o = wc_get_order({int(order_id)});
        echo "DATA=" . wp_json_encode(["status"=>$o->get_status(), "paid"=>$o->is_paid(), "transaction"=>$o->get_transaction_id()]) . PHP_EOL;
    ''')


def mark_refunded(order_id):
    return wp(f'''
        if (DB_NAME !== "astrowallclocks_test") throw new RuntimeException("Not local");
        $o = wc_get_order({int(order_id)});
        $o->set_status("refunded");
        $o->save();
        echo "DATA=" . wp_json_encode(["status"=>$o->get_status()]) . PHP_EOL;
    ''')


def payload(order, status):
    return {"key": KEY, "txnid": order["txnid"], "amount": order["amount"],
            "firstname": "Synthetic", "email": "webhook-local@example.test",
            "productinfo": f'Order {order["id"]}', "udf1": str(order["id"]),
            "udf2": order["key"], "status": status, "easepayid": "mock-webhook-payment"}


def sign(data):
    data = data.copy()
    data["hash"] = hashlib.sha512((SALT + "|" + data["status"] + "|"
                                      + "|".join(data.get(field, "") for field in HASH_FIELDS)).encode()).hexdigest()
    return data


def send(data):
    request = urllib.request.Request(URL, data=urllib.parse.urlencode(data).encode(), method="POST")
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def main():
    order = make_order()
    valid = sign(payload(order, "success"))
    tampered = valid.copy()
    tampered["amount"] = "1.00"
    assert send(tampered) == 400 and state(order["id"])["status"] == "pending"
    print("PASS tampered HTTP webhook rejected without order mutation")
    for label, change in [("amount", {"amount": "1.00"}),
                          ("transaction", {"txnid": "other-attempt"}),
                          ("order key", {"udf2": "other-order-key"})]:
        forged = payload(order, "success") | change
        assert send(sign(forged)) == 400 and state(order["id"])["status"] == "pending"
        print(f"PASS signed wrong {label} rejected by HTTP webhook")
    assert send(valid) == 200 and state(order["id"])["paid"]
    print("PASS valid success pays HPOS order through HTTP webhook")
    assert send(valid) == 200 and state(order["id"])["paid"]
    assert send(sign(payload(order, "failed"))) == 200 and state(order["id"])["paid"]
    print("PASS duplicate success and late failure leave paid order intact")
    assert mark_refunded(order["id"])["status"] == "refunded"
    assert send(valid) == 200 and state(order["id"])["status"] == "refunded"
    print("PASS success replay cannot re-pay a refunded order")
    for status, expected in [("failed", "failed"), ("usercancelled", "cancelled"), ("pending", "pending")]:
        other = make_order()
        assert send(sign(payload(other, status))) == 200
        after = state(other["id"])
        assert after["status"] == expected and not after["paid"]
        print(f"PASS {status} HTTP webhook leaves synthetic order unpaid")


if __name__ == "__main__":
    main()
