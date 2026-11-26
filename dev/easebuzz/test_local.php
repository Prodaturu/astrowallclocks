<?php
/** Run with WP-CLI eval-file against the isolated local store only. */
if (DB_NAME !== 'astrowallclocks_test' || get_option('siteurl') !== 'http://127.0.0.1:8088') {
    throw new RuntimeException('Refusing a non-local store');
}
if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('HPOS is required');
}
if (!defined('EASEBUZZ_PLUGIN_DIR')) {
    throw new RuntimeException('Patched Easebuzz plugin is not active');
}
require_once EASEBUZZ_PLUGIN_DIR . 'easepay-lib.php';

function astro_assert($condition, $description) {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS $description\n";
}

$settings = [
    'enabled' => 'yes',
    'title' => 'Easebuzz LOCAL MOCK',
    'test_mode' => 'test',
    'key_id' => 'ASTRO_LOCAL_FAKE_KEY',
    'key_secret' => 'ASTRO_LOCAL_FAKE_SALT',
    'enable_iframe' => 'no',
];
update_option(EASEBUZZ_SETTINGS_KEY, $settings);
astro_assert(get_option(EASEBUZZ_SETTINGS_KEY)['test_mode'] === 'test', 'gateway configured in local test mode');

$product = wc_get_product(wc_get_product_id_by_sku('ASTRO-LOCAL-TEST'));
astro_assert($product instanceof WC_Product, 'synthetic product exists');

$order = wc_create_order();
$order->add_product($product, 1);
$order->set_billing_first_name('Synthetic');
$order->set_billing_last_name('Buyer');
$order->set_billing_email('easebuzz-local@example.test');
$order->set_billing_phone('9999999999');
$order->set_payment_method('payeasebuzz');
$order->calculate_totals();
$txnid = $order->get_id() . '-local-test-attempt';
$order->update_meta_data('_astro_easebuzz_expected_txnid', $txnid);
$order->save();

$params = [
    'key' => $settings['key_id'], 'txnid' => $txnid,
    'amount' => wc_format_decimal($order->get_total(), 2),
    'firstname' => 'Synthetic Buyer', 'email' => 'easebuzz-local@example.test',
    'phone' => '9999999999', 'udf1' => (string) $order->get_id(),
    'udf2' => $order->get_order_key(), 'productinfo' => 'Order ' . $order->get_id(),
    'surl' => 'http://127.0.0.1:8088/success/', 'furl' => 'http://127.0.0.1:8088/failure/',
];

// The mock intercepts the initiation request before any network I/O.
$called = false;
$mock = function($preempt, $args, $url) use (&$called) {
    $called = true;
    astro_assert(str_starts_with($url, 'https://testpay.easebuzz.in/'), 'initiation selects sandbox endpoint');
    astro_assert(($args['sslverify'] ?? false) === true, 'initiation requires TLS verification');
    astro_assert(($args['redirection'] ?? null) === 0, 'initiation refuses HTTP redirects');
    astro_assert(strtoupper((string) ($args['method'] ?? '')) === 'POST', 'initiation uses POST for staging allowlist');
    astro_assert(isset($args['body']['hash']), 'initiation includes signed request');
    return ['headers' => [], 'body' => wp_json_encode(['status' => 1, 'data' => 'mock-access']), 'response' => ['code' => 200]];
};
add_filter('pre_http_request', $mock, 1, 3);
$init = easepay_page($params, $settings['key_secret'], 'test');
remove_filter('pre_http_request', $mock, 1);
astro_assert($called && $init['status'] == 1 && is_string($init['data']) && str_contains($init['data'], 'mock-access'), 'mock initiation succeeded without egress: ' . wp_json_encode(['status' => $init['status'], 'type' => gettype($init['data'])]));

function astro_signed($params, $salt) {
    $params['hash'] = easebuzz_compute_reverse_hash($params, $salt);
    return $params;
}

$success = astro_signed($params + ['status' => 'success', 'easepayid' => 'mock-payment'], $settings['key_secret']);
astro_assert(hash_equals(easebuzz_compute_reverse_hash($success, $settings['key_secret']), $success['hash']), 'valid success signature');
astro_assert(easebuzz_response_matches_order($order, $success, $settings), 'valid response bound to HPOS order');

foreach ([
    'wrong amount' => ['amount' => '1.00'],
    'wrong transaction' => ['txnid' => 'another-attempt'],
    'wrong order key' => ['udf2' => 'other-order-key'],
    'wrong merchant key' => ['key' => 'other-key'],
] as $label => $change) {
    $forged = astro_signed(array_replace($success, $change), $settings['key_secret']);
    astro_assert(!easebuzz_response_matches_order($order, $forged, $settings), "signed $label rejected");
}

$tampered = $success;
$tampered['amount'] = '1.00';
astro_assert(!hash_equals(easebuzz_compute_reverse_hash($tampered, $settings['key_secret']), $tampered['hash']), 'tampered hash rejected');

$cod = wc_create_order();
$cod->add_product($product, 1);
$cod->set_payment_method('cod');
$cod->calculate_totals();
$cod->update_meta_data('_astro_easebuzz_expected_txnid', $txnid);
$cod->save();
astro_assert(!easebuzz_response_matches_order($cod, $success, $settings), 'COD order cannot be changed by gateway');

$stock_before = wc_get_product($product->get_id())->get_stock_quantity();
$success_result = easebuzz_apply_payment_status($order, 'success', $success, 'mock');
astro_assert($success_result['state'] === 'success' && wc_get_order($order->get_id())->is_paid(), 'verified success pays synthetic HPOS order');
astro_assert(wc_get_product($product->get_id())->get_stock_quantity() === $stock_before - 1, 'verified payment reduces stock once');
$paid = wc_get_order($order->get_id());
$paid_status = $paid->get_status();
astro_assert(easebuzz_apply_payment_status($paid, 'failed', $success, 'replay')['state'] === 'already_paid'
    && wc_get_order($order->get_id())->get_status() === $paid_status, 'late failure cannot reverse paid order');
astro_assert(easebuzz_apply_payment_status($paid, 'success', $success, 'replay')['state'] === 'already_paid', 'duplicate success is idempotent');
astro_assert(wc_get_product($product->get_id())->get_stock_quantity() === $stock_before - 1, 'replay does not reduce stock again');

foreach (['failed' => 'failed', 'usercancelled' => 'cancelled', 'pending' => 'pending'] as $input => $expected) {
    $test_order = wc_create_order();
    $test_order->add_product($product, 1);
    $test_order->set_payment_method('payeasebuzz');
    $test_order->calculate_totals();
    $test_order->save();
    $result = easebuzz_apply_payment_status($test_order, $input, $success, 'mock');
    astro_assert($result['state'] === $expected && !wc_get_order($test_order->get_id())->is_paid(), "$input remains unpaid");
}

astro_assert(has_filter('pre_wp_mail') !== false, 'outgoing customer email blocked');
echo "PASS local payment contract checks completed\n";
