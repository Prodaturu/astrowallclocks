"""Local redirect and iframe callback checks with synthetic orders and fake signing."""

import json
import urllib.parse
import urllib.request

from test_webhook import KEY, SALT, make_order, payload, sign, state, wp


def redirect(data):
    req = urllib.request.Request(
        "http://127.0.0.1:8088/?wc-api=WC_Easebuzz_Gateway",
        data=urllib.parse.urlencode(data).encode(), method="POST")
    with urllib.request.urlopen(req, timeout=20) as response:
        return response.status


def iframe(data, nonce):
    form = {"action": "easebuzz_payment_response_iframe", "_nonce": nonce}
    form.update({f"response[{key}]": value for key, value in data.items()})
    req = urllib.request.Request("http://127.0.0.1:8088/wp-admin/admin-ajax.php",
                                 data=urllib.parse.urlencode(form).encode(), method="POST")
    with urllib.request.urlopen(req, timeout=20) as response:
        return json.load(response)


def main():
    order = make_order()
    valid = sign(payload(order, "success"))
    invalid = valid | {"amount": "1.00"}
    assert redirect(invalid) == 200 and not state(order["id"])["paid"]
    assert redirect(sign(payload(order, "success") | {"amount": "1.00"})) == 200
    assert not state(order["id"])["paid"]
    print("PASS redirect rejects tampered and signed wrong amount")
    assert redirect(valid) == 200 and state(order["id"])["paid"]
    assert redirect(sign(payload(order, "failed"))) == 200 and state(order["id"])["paid"]
    print("PASS redirect success pays; late failure cannot reverse it")

    nonce = wp('echo "DATA=" . wp_json_encode(wp_create_nonce("easebuzz_iframe_payment")) . PHP_EOL;')
    iframe_order = make_order()
    iframe_valid = sign(payload(iframe_order, "success"))
    assert iframe(iframe_valid | {"hash": "0" * 128}, nonce)["class"] == "error"
    assert not state(iframe_order["id"])["paid"]
    assert iframe(sign(payload(iframe_order, "success") | {"txnid": "wrong-attempt"}), nonce)["class"] == "error"
    assert not state(iframe_order["id"])["paid"]
    assert iframe(iframe_valid, nonce)["class"] == "success" and state(iframe_order["id"])["paid"]
    assert iframe(iframe_valid, nonce)["class"] == "success" and state(iframe_order["id"])["paid"]
    print("PASS iframe rejects tampering and mismatch; success and replay safe")


if __name__ == "__main__":
    main()
