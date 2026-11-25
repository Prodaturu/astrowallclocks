<?php
/** Run only by WP-CLI against isolated staging. Never supply real credentials. */
if (DB_NAME !== 'bgt9xah2o3eo_awstage'
    || get_option('siteurl') !== 'https://staging.astrowallclocks.com'
    || !defined('EASEBUZZ_PLUGIN_DIR')) {
    throw new RuntimeException('Wrong site or gateway missing');
}
foreach (['https://testpay.easebuzz.in/payment/initiateLink',
          'https://pay.easebuzz.in/payment/initiateLink',
          'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc'] as $url) {
    $blocked = apply_filters('pre_http_request', false, [], $url);
    if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'astro_staging_external_block') {
        throw new RuntimeException('Staging outbound safety filter missing');
    }
}
if (!has_filter('pre_wp_mail')) {
    throw new RuntimeException('Staging email suppression missing');
}
$existing = get_option('woocommerce_payeasebuzz_settings', []);
if (!empty($existing['key_id']) || !empty($existing['key_secret'])) {
    throw new RuntimeException('Gateway has existing credentials; refusing to overwrite');
}
$settings = [
    'enabled' => 'yes',
    'title' => 'Easebuzz simulated payment (staging only)',
    'description' => 'Testing only. No payment will be charged.',
    'key_id' => 'ASTRO_STAGE_DUMMY_' . wp_generate_password(20, false, false),
    'key_secret' => 'ASTRO_STAGE_DUMMY_' . wp_generate_password(40, false, false),
    'test_mode' => 'test',
    'enable_iframe' => 'no',
    'redirect_page' => '0',
    'redirect_fail_page' => '0',
];
update_option('woocommerce_payeasebuzz_settings', $settings);
echo "PASS staging-only dummy Easebuzz test configuration; outbound gateway and shipping blocked\n";
