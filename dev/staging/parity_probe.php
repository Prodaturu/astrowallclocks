<?php
/** Read-only, non-secret WordPress/WooCommerce release parity snapshot. Run with wp eval-file. */
if (!defined('WP_CLI') || !WP_CLI || !class_exists('WooCommerce')) {
    exit(2);
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$all_plugins = get_plugins();
$active_plugins = (array) get_option('active_plugins', []);
$plugins = [];
foreach ($all_plugins as $path => $details) {
    $name = dirname($path);
    if ($name === '.') {
        $name = basename($path, '.php');
    }
    $plugins[$name] = [
        'version' => (string) ($details['Version'] ?? ''),
        'active' => in_array($path, $active_plugins, true),
    ];
}
ksort($plugins);

$options = [];
foreach ([
    'woocommerce_currency',
    'woocommerce_calc_taxes',
    'woocommerce_enable_guest_checkout',
    'woocommerce_enable_myaccount_registration',
    'woocommerce_enable_signup_and_login_from_checkout',
    'woocommerce_manage_stock',
    'woocommerce_hold_stock_minutes',
    'woocommerce_ship_to_countries',
    'woocommerce_specific_allowed_countries',
    'woocommerce_specific_ship_to_countries',
    'woocommerce_ship_to_destination',
    'woocommerce_cart_redirect_after_add',
    'woocommerce_enable_coupons',
    'woocommerce_custom_orders_table_enabled',
    'woocommerce_custom_orders_table_data_sync_enabled',
] as $name) {
    $options[$name] = get_option($name, null);
}

$pages = [];
foreach (['shop', 'cart', 'checkout', 'myaccount'] as $name) {
    $id = wc_get_page_id($name);
    $page = $id > 0 ? get_post($id) : null;
    $pages[$name] = $page ? [
        'slug' => $page->post_name,
        'status' => $page->post_status,
        'classic_shortcode' => strpos((string) $page->post_content, '[woocommerce_') !== false,
        'blocks' => strpos((string) $page->post_content, '<!-- wp:woocommerce/') !== false,
    ] : null;
}

$gateways = [];
foreach (['cod', 'bacs', 'cheque', 'payeasebuzz', 'razorpay'] as $id) {
    $settings = get_option('woocommerce_' . $id . '_settings', []);
    if (is_array($settings) && array_key_exists('enabled', $settings)) {
        $gateways[$id] = $settings['enabled'];
    }
}

$zones = [];
foreach (WC_Shipping_Zones::get_zones() as $zone) {
    $methods = [];
    foreach ($zone['shipping_methods'] as $method) {
        $methods[] = ['id' => $method->id, 'enabled' => $method->enabled];
    }
    $zones[] = ['methods' => $methods];
}
$rest = new WC_Shipping_Zone(0);
$fallback = [];
foreach ($rest->get_shipping_methods() as $method) {
    $fallback[] = ['id' => $method->id, 'enabled' => $method->enabled];
}

$result = [
    'php_cli' => PHP_VERSION,
    'wordpress' => get_bloginfo('version'),
    'woocommerce' => WC()->version,
    'theme' => get_stylesheet(),
    'hpos_authoritative' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
    'site_scheme' => wp_parse_url(site_url(), PHP_URL_SCHEME),
    'home_scheme' => wp_parse_url(home_url(), PHP_URL_SCHEME),
    'wp_debug' => WP_DEBUG,
    'wp_debug_display' => WP_DEBUG_DISPLAY,
    'featured_product_id' => (int) get_option('astro_featured_product_id', 0),
    'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
    'mail_guard' => (bool) has_filter('pre_wp_mail'),
    'options' => $options,
    'pages' => $pages,
    'gateways' => $gateways,
    'shipping_zones' => $zones,
    'fallback_shipping_methods' => $fallback,
    'tax_classes' => WC_Tax::get_tax_classes(),
    'tax_rules' => $wpdb->get_results(
        "SELECT tax_rate_country,tax_rate_state,tax_rate,tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_state",
        ARRAY_A
    ),
    'plugins' => $plugins,
];
echo 'ASTRO_PARITY_JSON=' . wp_json_encode($result) . PHP_EOL;
