<?php
/** WP-CLI regression; synthetic orders and in-process HTTP mocks only. */
$local = DB_NAME === 'astrowallclocks_test' && get_option('siteurl') === 'http://127.0.0.1:8088';
$staging = DB_NAME === 'bgt9xah2o3eo_awstage' && get_option('siteurl') === 'https://staging.astrowallclocks.com';
if (!$local && !$staging) throw new RuntimeException('Refusing a non-test store');
if ($staging && (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    || !has_filter('pre_wp_mail'))) throw new RuntimeException('Staging safety or HPOS missing');
require_once EASEBUZZ_PLUGIN_DIR . 'easepay-lib.php';
$settings = $staging ? get_option(EASEBUZZ_SETTINGS_KEY, array())
    : array('key_id' => 'ASTRO_LOCAL_FAKE_KEY', 'key_secret' => 'ASTRO_LOCAL_FAKE_SALT', 'test_mode' => 'test');
if ($staging) {
    if (($settings['test_mode'] ?? '') !== 'test'
        || !str_starts_with($settings['key_id'] ?? '', 'ASTRO_STAGE_DUMMY_')
        || !str_starts_with($settings['key_secret'] ?? '', 'ASTRO_STAGE_DUMMY_')) {
        throw new RuntimeException('Staging requires dummy-only gateway configuration');
    }
} else {
    update_option(EASEBUZZ_SETTINGS_KEY, $settings);
}
$product = wc_get_product(wc_get_product_id_by_sku($staging ? 'ASTRO-STAGING-EASEBUZZ-TEST' : 'ASTRO-LOCAL-TEST'));
if (!$product) throw new RuntimeException('Synthetic product missing');

function recovery_assert($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}

function recovery_order($product) {
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_payment_method('payeasebuzz');
    $order->calculate_totals();
    $order->save();
    $order->update_meta_data('_astro_easebuzz_expected_txnid', $order->get_id() . '-missed');
    $order->save();
    return $order;
}

function recovery_record($order, $settings) {
    return array(
        'key' => $settings['key_id'], 'txnid' => $order->get_meta('_astro_easebuzz_expected_txnid'),
        'udf1' => (string) $order->get_id(), 'udf2' => $order->get_order_key(),
        'amount' => wc_format_decimal($order->get_total(), 2), 'status' => 'success',
        'easepayid' => 'fake-payment-' . $order->get_id(),
    );
}

function recovery_mock($record, $expected_key, $expected_salt) {
    return function($preempt, $args, $url) use ($record, $expected_key, $expected_salt) {
        recovery_assert($url === 'https://testdashboard.easebuzz.in/transaction/v2/retrieve', 'lookup uses test dashboard only');
        recovery_assert(($args['sslverify'] ?? false) === true && ($args['redirection'] ?? 1) === 0, 'lookup requires TLS and no redirects');
        recovery_assert($args['body']['key'] === $expected_key && $args['body']['hash'] === hash('sha512', $expected_key . '|' . $args['body']['txnid'] . '|' . $expected_salt), 'lookup request is signed');
        return array('headers' => array(), 'body' => wp_json_encode(array('status' => 1, 'msg' => array($record))), 'response' => array('code' => 200));
    };
}

$order = recovery_order($product);
$record = recovery_record($order, $settings);
$stock = wc_get_product($product->get_id())->get_stock_quantity();
astro_easebuzz_schedule_recovery($order->get_id(), 0);
recovery_assert(wp_next_scheduled('astro_easebuzz_recover_order', array($order->get_id(), 0)) !== false, 'missed callback recovery scheduled');
$mock = recovery_mock($record, $settings['key_id'], $settings['key_secret']);
add_filter('pre_http_request', $mock, 1, 3);
astro_easebuzz_recover_order($order->get_id(), 0);
remove_filter('pre_http_request', $mock, 1);
recovery_assert(wc_get_order($order->get_id())->is_paid(), 'authenticated successful lookup pays missed callback order');
recovery_assert(wc_get_product($product->get_id())->get_stock_quantity() === $stock - 1, 'recovery reduces stock once');
astro_easebuzz_recover_order($order->get_id(), 1);
recovery_assert(wc_get_product($product->get_id())->get_stock_quantity() === $stock - 1, 'replay cannot reduce stock twice');

foreach (array('key', 'txnid', 'udf1', 'udf2', 'amount', 'status', 'easepayid') as $field) {
    $other = recovery_order($product);
    $bad = recovery_record($other, $settings);
    $bad[$field] = $field === 'status' ? 'failed' : ($field === 'easepayid' ? '' : 'WRONG');
    $mock = recovery_mock($bad, $settings['key_id'], $settings['key_secret']);
    add_filter('pre_http_request', $mock, 1, 3);
    astro_easebuzz_recover_order($other->get_id(), 0);
    remove_filter('pre_http_request', $mock, 1);
    recovery_assert(!wc_get_order($other->get_id())->is_paid(), "lookup with wrong $field leaves order unpaid");
}

foreach (array('cancelled', 'failed', 'refunded') as $status) {
    $other = recovery_order($product);
    $other->set_status($status);
    $other->save();
    astro_easebuzz_recover_order($other->get_id(), 0);
    recovery_assert(wc_get_order($other->get_id())->get_status() === $status, "$status order cannot be revived by recovery");
}

$pending = recovery_order($product);
$record = recovery_record($pending, $settings);
$record['status'] = 'pending';
$mock = recovery_mock($record, $settings['key_id'], $settings['key_secret']);
add_filter('pre_http_request', $mock, 1, 3);
astro_easebuzz_recover_order($pending->get_id(), 0);
remove_filter('pre_http_request', $mock, 1);
recovery_assert(!wc_get_order($pending->get_id())->is_paid(), 'pending lookup leaves order unpaid');
recovery_assert(wp_next_scheduled('astro_easebuzz_recover_order', array($pending->get_id(), 1)) !== false, 'pending lookup is retried');
$gateway = WC()->payment_gateways()->payment_gateways()['payeasebuzz'];
$initiate = new ReflectionMethod($gateway, 'generate_payeasebuzz_form');
$blocked = $initiate->invoke($gateway, $pending->get_id());
recovery_assert(is_string($blocked) && str_contains($blocked, 'previous payment attempt'), 'pending attempt cannot be replaced by a second initiation');
$mock = recovery_mock($record, $settings['key_id'], $settings['key_secret']);
add_filter('pre_http_request', $mock, 1, 3);
astro_easebuzz_recover_order($pending->get_id(), 3);
remove_filter('pre_http_request', $mock, 1);
recovery_assert(wp_next_scheduled('astro_easebuzz_recover_order', array($pending->get_id(), 4)) === false, 'retries stop after bounded attempts');
$notes = wc_get_order_notes(array('order_id' => $pending->get_id()));
recovery_assert((bool) array_filter($notes, fn($note) => str_contains($note->content, 'reconcile in Easebuzz')), 'unverified order flagged for manual reconciliation');
