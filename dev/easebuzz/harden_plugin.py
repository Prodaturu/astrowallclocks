"""Apply the reviewed payment validation patch to official Easebuzz 5.8.0.

Input is the extracted, unmodified official plugin directory. Refuse unknown
sources so an upstream update cannot silently bypass these checks.
"""

import hashlib
import shutil
import sys
from pathlib import Path


EXPECTED = {
    "easepay-lib.php": "e744f33d5780c87d7822a122661b3d9d6cc01f9f6d02b79edf58a86774ca66da",
    "woocommerce-gateway-easebuzz.php": "343b65e0e8788d51ed3016a5918d4bac9ae246d1cd4b1d5c58c8c899aaa33fac",
    "update_webhook.php": "31c2e4ca4fd0caa1ff3801f429e7b1c8850d7dd47bd464351322e9897ed1f849",
}


def replace_once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError(f"Expected one exact match, found {text.count(old)}")
    return text.replace(old, new)


def main(source, target):
    source = Path(source)
    target = Path(target)
    for name, expected in EXPECTED.items():
        actual = hashlib.sha256((source / name).read_bytes()).hexdigest()
        if actual != expected:
            raise RuntimeError(f"Unreviewed Easebuzz source: {name} ({actual})")
    if target.exists():
        raise RuntimeError("Target already exists")
    shutil.copytree(source, target)

    lib = target / "easepay-lib.php"
    text = lib.read_text()
    anchor = "/**\n * Apply an Easebuzz payment status to a WooCommerce order."
    guard = '''/**
 * Bind a signed response to the actual gateway, amount and current attempt.
 * The Easebuzz reverse hash proves integrity but not that this order initiated
 * this transaction. All entry points must call this after hash verification.
 */
function easebuzz_response_matches_order($order, $params, $settings)
{
    if (!$order || $order->get_payment_method() !== 'payeasebuzz') {
        return false;
    }
    $expected_txnid = (string) $order->get_meta('_astro_easebuzz_expected_txnid', true);
    $key = trim((string) ($settings['key_id'] ?? ''));
    if ($expected_txnid === '' || $key === '') {
        return false;
    }
    return hash_equals($key, (string) ($params['key'] ?? ''))
        && hash_equals($expected_txnid, (string) ($params['txnid'] ?? ''))
        && hash_equals((string) $order->get_order_key(), (string) ($params['udf2'] ?? ''))
        && hash_equals((string) $order->get_id(), (string) ($params['udf1'] ?? ''))
        && wc_format_decimal($params['amount'] ?? '', 2) === wc_format_decimal($order->get_total(), 2);
}

'''
    text = replace_once(text, anchor, guard + anchor)
    text = replace_once(text, "    $status = strtolower((string) $status);\n    $suffix", "    if ($order->is_paid()) {\n        return array('authorised' => true, 'state' => 'already_paid', 'message' => 'Order already paid.');\n    }\n    $status = strtolower((string) $status);\n    $suffix")
    lib.write_text(text)

    gateway = target / "woocommerce-gateway-easebuzz.php"
    text = gateway.read_text()
    text = replace_once(text, "            $response = easepay_page($payment_request_parameter, $salt, $env);", "            $order->update_meta_data('_astro_easebuzz_expected_txnid', $payment_request_parameter['txnid']);\n            $order->save();\n            $response = easepay_page($payment_request_parameter, $salt, $env);")
    text = replace_once(text, '''                // Already handled (duplicate/replayed callback) - do not reprocess.
                if (easebuzz_order_already_handled($order)) {
                    wp_safe_redirect($redirect_success_url);
                    exit;
                }

                $order->set_transaction_id(sanitize_text_field($params['easepayid']));
                $order->save();

''', '')
    text = replace_once(text, '''                    $order->add_order_note('Checksum ERROR: ' . wp_json_encode(array_map('sanitize_text_field', $params)));
                    $order->update_status('failed');''', "                    $this->log('Invalid Easebuzz response hash for order ' . $order_id);")
    text = replace_once(text, "                // Delegate the status state machine (shared, DRY).", '''                $settings = get_option(EASEBUZZ_SETTINGS_KEY, array());
                if (!easebuzz_response_matches_order($order, $params, $settings)) {
                    $this->log('Easebuzz response does not match order ' . $order_id);
                    wp_safe_redirect($redirect_fail_url);
                    exit;
                }
                if (easebuzz_order_already_handled($order) || $order->is_paid()) {
                    wp_safe_redirect($redirect_success_url);
                    exit;
                }
                $order->set_transaction_id(sanitize_text_field($params['easepayid']));
                $order->save();

                // Delegate the status state machine (shared, DRY).''')
    text = replace_once(text, '''    // Already handled (duplicate/replayed) - do not reprocess.
    if (easebuzz_order_already_handled($order)) {
        wp_send_json(array('class' => 'success', 'message' => __('Order already processed.', 'woo_payeasebuzz')));
    }

''', '')
    text = replace_once(text, '''        $order->add_order_note('Checksum ERROR (iframe): ' . wp_json_encode($response));
        $order->update_status('failed');
''', '')
    text = replace_once(text, "    // Delegate the status state machine (shared, DRY).\n    $result", '''    if (!easebuzz_response_matches_order($order, $response, $settings)) {
        wp_send_json(array('class' => 'error', 'message' => __('Payment response does not match order.', 'woo_payeasebuzz')));
    }
    if (easebuzz_order_already_handled($order) || $order->is_paid()) {
        wp_send_json(array('class' => 'success', 'message' => __('Order already processed.', 'woo_payeasebuzz')));
    }

    // Delegate the status state machine (shared, DRY).
    $result''')
    gateway.write_text(text)

    webhook = target / "update_webhook.php"
    text = webhook.read_text()
    text = replace_once(text, "    // Only process if order is in an updatable state (shared guard).", '''    if (!easebuzz_response_matches_order($order, $payload, $settings)) {
        easebuzz_webhook_response(400, 'Payment response does not match order.');
    }

    // Only process if order is in an updatable state (shared guard).''')
    text = replace_once(text, "    if (easebuzz_order_already_handled($order)) {", "    if (easebuzz_order_already_handled($order) || $order->is_paid()) {")
    webhook.write_text(text)
    print("Patched reviewed Easebuzz plugin for local testing")


if __name__ == "__main__":
    main(*sys.argv[1:])
