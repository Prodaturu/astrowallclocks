<?php
/** Isolated staging safety policy; not installed in production. */
if (DB_NAME !== 'bgt9xah2o3eo_awstage') { return; }
add_filter('pre_wp_mail', '__return_true', 1);
add_filter('pre_http_request', function ($preempt, $args, $url) {
    // Permit in-process WP-CLI mocks only; web requests always hit the guard.
    if ($preempt !== false && defined('WP_CLI') && WP_CLI) { return $preempt; }
    // A separate, explicitly approved wp-config flag can open only the two
    // exact live POST endpoints needed for one controlled payment. Default is closed.
    $live_window = defined('ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW')
        && ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW === true;
    if ($live_window
        && in_array($url, [
            'https://pay.easebuzz.in/payment/initiateLink',
            'https://dashboard.easebuzz.in/transaction/v2/retrieve',
        ], true)
        && strtoupper((string) ($args['method'] ?? '')) === 'POST'
        && ($args['sslverify'] ?? false) === true
        && (int) ($args['redirection'] ?? -1) === 0) {
        return $preempt;
    }
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    foreach (['shiprocket.in', 'shiprocket.com', 'razorpay.com', 'razorpay.in', 'pay.easebuzz.in', 'testpay.easebuzz.in', 'dashboard.easebuzz.in', 'testdashboard.easebuzz.in'] as $blocked) {
        if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
            return new WP_Error('astro_staging_external_block', 'Payment and shipment calls are blocked while staging uses dummy credentials.');
        }
    }
    return $preempt;
}, 10, 3);

// During an approved live window, only one named WordPress tester may see or
// submit Easebuzz at checkout. A missing tester ID fails closed.
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (!defined('ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW')
        || ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW !== true) {
        return $gateways;
    }
    $tester = defined('ASTRO_STAGE_EASEBUZZ_TESTER_USER_ID')
        ? (int) ASTRO_STAGE_EASEBUZZ_TESTER_USER_ID : 0;
    if ($tester <= 0 || !is_user_logged_in() || get_current_user_id() !== $tester) {
        unset($gateways['payeasebuzz']);
    }
    return $gateways;
});
