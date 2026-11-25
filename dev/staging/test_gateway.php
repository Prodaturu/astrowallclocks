<?php
/** Synthetic HTTPS callback test for staging; no external payment API or mail. */
if (DB_NAME !== 'bgt9xah2o3eo_awstage'
    || get_option('siteurl') !== 'https://staging.astrowallclocks.com'
    || !\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    || !defined('EASEBUZZ_PLUGIN_DIR')) {
    throw new RuntimeException('Wrong site, HPOS, or gateway');
}
require_once EASEBUZZ_PLUGIN_DIR . 'easepay-lib.php';
$settings = get_option(EASEBUZZ_SETTINGS_KEY, []);
if (($settings['enabled'] ?? '') !== 'yes' || ($settings['test_mode'] ?? '') !== 'test'
    || !str_starts_with($settings['key_id'] ?? '', 'ASTRO_STAGE_DUMMY_')
    || !str_starts_with($settings['key_secret'] ?? '', 'ASTRO_STAGE_DUMMY_')) {
    throw new RuntimeException('Dummy-only gateway configuration required');
}
foreach (['https://pay.easebuzz.in/payment/initiateLink',
          'https://testpay.easebuzz.in/payment/initiateLink',
          'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc'] as $url) {
    if (!is_wp_error(apply_filters('pre_http_request', false, [], $url))) {
        throw new RuntimeException('Outbound service guard missing');
    }
}
if (!has_filter('pre_wp_mail')) {
    throw new RuntimeException('Mail suppression missing');
}

function stage_assert($condition, $label) {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
function stage_product() {
    $id = wc_get_product_id_by_sku('ASTRO-STAGING-EASEBUZZ-TEST');
    if ($id) {
        return wc_get_product($id);
    }
    $product = new WC_Product_Simple();
    $product->set_name('Easebuzz STAGING synthetic test item');
    $product->set_sku('ASTRO-STAGING-EASEBUZZ-TEST');
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price('100');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(30);
    $product->save();
    return $product;
}
function stage_order($product) {
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_payment_method('payeasebuzz');
    $order->set_billing_first_name('Synthetic');
    $order->set_billing_email('easebuzz-staging@example.test');
    $order->calculate_totals();
    $order->update_meta_data('_astro_easebuzz_expected_txnid', $order->get_id() . '-synthetic-attempt');
    $order->save();
    return $order;
}
function stage_payload($order, $status, $changes = []) {
    $settings = get_option(EASEBUZZ_SETTINGS_KEY, []);
    $data = [
        'key' => $settings['key_id'],
        'txnid' => $order->get_meta('_astro_easebuzz_expected_txnid', true),
        'amount' => wc_format_decimal($order->get_total(), 2),
        'firstname' => 'Synthetic',
        'email' => 'easebuzz-staging@example.test',
        'productinfo' => 'Order ' . $order->get_id(),
        'udf1' => (string) $order->get_id(),
        'udf2' => $order->get_order_key(),
        'status' => $status,
        'easepayid' => 'synthetic-staging-payment',
    ];
    $data = array_replace($data, $changes);
    $data['hash'] = easebuzz_compute_reverse_hash($data, $settings['key_secret']);
    return $data;
}
function stage_post($path, $data) {
    $response = wp_remote_post('https://staging.astrowallclocks.com' . $path, [
        'body' => $data,
        'timeout' => 20,
        'redirection' => 0,
        'sslverify' => true,
    ]);
    if (is_wp_error($response)) {
        throw new RuntimeException('Staging HTTPS callback request failed: ' . $response->get_error_code());
    }
    // The callback is a separate PHP process; discard this WP-CLI process's
    // cached order and product objects before reading its changes.
    wp_cache_flush();
    return wp_remote_retrieve_response_code($response);
}

$webhook = '/wp-content/plugins/woocommerce_easebuzz_plugin/update_webhook.php';
$product = stage_product();
$order = stage_order($product);
$valid = stage_payload($order, 'success');
$tampered = $valid;
$tampered['amount'] = '1.00';
stage_assert(stage_post($webhook, $tampered) === 400 && wc_get_order($order->get_id())->get_status() === 'pending', 'tampered webhook rejected');
foreach (['wrong amount' => ['amount' => '1.00'],
          'wrong transaction' => ['txnid' => 'other-attempt'],
          'wrong order key' => ['udf2' => 'other-key'],
          'wrong merchant key' => ['key' => 'other-key'],
          'wrong order ID' => ['udf1' => '99999999']] as $label => $change) {
    stage_assert(stage_post($webhook, stage_payload($order, 'success', $change)) === 400
        && wc_get_order($order->get_id())->get_status() === 'pending', "$label webhook rejected");
}
$stock_before = wc_get_product($product->get_id())->get_stock_quantity();
stage_assert(stage_post($webhook, $valid) === 200 && wc_get_order($order->get_id())->is_paid(), 'valid webhook pays HPOS order');
stage_assert(wc_get_product($product->get_id())->get_stock_quantity() === $stock_before - 1, 'valid payment reduces stock once');
stage_assert(stage_post($webhook, $valid) === 200
    && wc_get_product($product->get_id())->get_stock_quantity() === $stock_before - 1, 'duplicate success does not reduce stock');
stage_assert(stage_post($webhook, stage_payload($order, 'failed')) === 200
    && wc_get_order($order->get_id())->is_paid(), 'late failure cannot reverse paid order');
$refunded = wc_get_order($order->get_id());
$refunded->set_status('refunded');
$refunded->save();
stage_assert(stage_post($webhook, $valid) === 200
    && wc_get_order($order->get_id())->get_status() === 'refunded', 'success replay cannot re-pay refunded order');

foreach (['failed' => 'failed', 'usercancelled' => 'cancelled', 'pending' => 'pending'] as $incoming => $expected) {
    $next = stage_order($product);
    stage_assert(stage_post($webhook, stage_payload($next, $incoming)) === 200
        && wc_get_order($next->get_id())->get_status() === $expected
        && !wc_get_order($next->get_id())->is_paid(), "$incoming stays unpaid");
}
$redirect_order = stage_order($product);
$callback = '/?wc-api=WC_Easebuzz_Gateway';
stage_assert(stage_post($callback, stage_payload($redirect_order, 'success', ['amount' => '1.00'])) === 302
    && !wc_get_order($redirect_order->get_id())->is_paid(), 'redirect rejects signed amount mismatch');
stage_assert(stage_post($callback, stage_payload($redirect_order, 'success')) === 302
    && wc_get_order($redirect_order->get_id())->is_paid(), 'redirect accepts valid synthetic success');
stage_assert(stage_post($callback, stage_payload($redirect_order, 'failed')) === 302
    && wc_get_order($redirect_order->get_id())->is_paid(), 'redirect replay leaves paid order intact');
echo "PASS staging callback suite complete; no payment or shipment API contacted\n";
