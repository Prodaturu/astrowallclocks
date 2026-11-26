<?php
/** One-time, staging-only checkout parity seed from reviewed production rules. */
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'bgt9xah2o3eo_awstage'
    || site_url() !== 'https://staging.astrowallclocks.com') {
    exit(2);
}

global $wpdb;
$product = wc_get_product(17);
if (!$product || $product->get_sku() !== 'ASTRO-STAGING-TEST'
    || wc_format_decimal($product->get_price(), 2) !== '4499.00') {
    exit(3);
}

$class = '18% GST';
if (!in_array($class, WC_Tax::get_tax_classes(), true)) {
    $created = WC_Tax::create_tax_class($class, '18-gst');
    if (is_wp_error($created)) {
        exit(4);
    }
}
if (!in_array('18-gst', WC_Tax::get_tax_class_slugs(), true)) {
    exit(5);
}

$states = ['AP', 'DL', 'KA', 'MH', 'TS'];
$existing = $wpdb->get_results(
    "SELECT tax_rate_country,tax_rate_state,tax_rate,tax_rate_name,tax_rate_priority,tax_rate_compound,tax_rate_shipping,tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates",
    ARRAY_A
);
if (!$existing) {
    foreach ($states as $state) {
        $id = WC_Tax::_insert_tax_rate([
            'tax_rate_country' => 'IN',
            'tax_rate_state' => $state,
            'tax_rate' => '18.0000',
            'tax_rate_name' => $class,
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 0,
            'tax_rate_class' => '18-gst',
        ]);
        if (!$id) {
            exit(6);
        }
    }
} else {
    $seen = [];
    foreach ($existing as $rate) {
        $seen[] = $rate['tax_rate_state'];
        if ($rate['tax_rate_country'] !== 'IN'
            || (float) $rate['tax_rate'] !== 18.0
            || $rate['tax_rate_name'] !== $class
            || (int) $rate['tax_rate_priority'] !== 1
            || (int) $rate['tax_rate_compound'] !== 0
            || (int) $rate['tax_rate_shipping'] !== 0
            || $rate['tax_rate_class'] !== '18-gst') {
            exit(7);
        }
    }
    sort($seen);
    if ($seen !== $states) {
        exit(7);
    }
}

$zone = new WC_Shipping_Zone(0);
$methods = array_values($zone->get_shipping_methods());
if (count($methods) !== 1 || $methods[0]->id !== 'flat_rate'
    || $methods[0]->enabled !== 'yes') {
    exit(8);
}
$shipping_key = $methods[0]->get_instance_option_key();
$shipping = get_option($shipping_key, []);
if (!is_array($shipping)) {
    exit(9);
}
$shipping['cost'] = '40';
update_option($shipping_key, $shipping);

foreach ([
    'woocommerce_calc_taxes' => 'yes',
    'woocommerce_enable_coupons' => 'no',
    'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
    'woocommerce_ship_to_countries' => 'specific',
    'woocommerce_specific_allowed_countries' => ['IN'],
    'woocommerce_specific_ship_to_countries' => ['IN'],
] as $key => $value) {
    update_option($key, $value);
}
$product->set_tax_status('none');
$product->set_tax_class('18-gst');
$product->save();
WC_Cache_Helper::invalidate_cache_group('taxes');
echo 'staging_checkout_parity_applied' . PHP_EOL;
