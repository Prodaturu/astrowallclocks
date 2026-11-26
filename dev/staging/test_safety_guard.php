<?php
/** Pure local contract test; captures the filter without loading WordPress or making requests. */
define('DB_NAME', 'bgt9xah2o3eo_awstage');
if (in_array('--approved-window', $argv, true)) {
    define('ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW', true);
    define('ASTRO_STAGE_EASEBUZZ_TESTER_USER_ID', 42);
}
class WP_Error {
    public function __construct(public string $code, public string $message) {}
}
function add_filter($name, $callback, $priority = 10, $args = 1) {
    if ($name === 'pre_http_request') {
        $GLOBALS['filter'] = $callback;
    } elseif ($name === 'woocommerce_available_payment_gateways') {
        $GLOBALS['gateway_filter'] = $callback;
    }
}
function is_user_logged_in() { return $GLOBALS['logged_in'] ?? false; }
function get_current_user_id() { return $GLOBALS['user_id'] ?? 0; }
function wp_parse_url($url, $part) {
    return parse_url($url, $part);
}
require __DIR__ . '/astro-staging-safety.php';
$filter = $GLOBALS['filter'];
$args = ['method' => 'POST', 'sslverify' => true, 'redirection' => 0];
$live = [
    'https://pay.easebuzz.in/payment/initiateLink',
    'https://dashboard.easebuzz.in/transaction/v2/retrieve',
];
foreach ($live as $url) {
    $result = $filter(false, $args, $url);
    $approved = defined('ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW');
    if ($approved ? $result !== false : !($result instanceof WP_Error)) {
        exit(2);
    }
    foreach ([
        ['method' => 'GET', 'sslverify' => true, 'redirection' => 0],
        ['method' => 'POST', 'sslverify' => false, 'redirection' => 0],
        ['method' => 'POST', 'sslverify' => true, 'redirection' => 1],
    ] as $unsafe) {
        if (!($filter(false, $unsafe, $url) instanceof WP_Error)) {
            exit(3);
        }
    }
    if (!($filter(false, $args, $url . '?extra=1') instanceof WP_Error)) {
        exit(4);
    }
}
foreach ([
    'https://apiv2.shiprocket.in/v1/open/courier/serviceability',
    'https://api.razorpay.com/v1/orders',
    'https://testpay.easebuzz.in/payment/initiateLink',
] as $url) {
    if (!($filter(false, $args, $url) instanceof WP_Error)) {
        exit(5);
    }
}
$gateways = ['payeasebuzz' => true, 'cod' => true];
if (defined('ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW')) {
    $GLOBALS['logged_in'] = true;
    $GLOBALS['user_id'] = 42;
    if (array_keys($GLOBALS['gateway_filter']($gateways)) !== ['payeasebuzz', 'cod']) {
        exit(6);
    }
    $GLOBALS['user_id'] = 43;
    if (array_keys($GLOBALS['gateway_filter']($gateways)) !== ['cod']) {
        exit(7);
    }
    $GLOBALS['logged_in'] = false;
    if (array_keys($GLOBALS['gateway_filter']($gateways)) !== ['cod']) {
        exit(8);
    }
} elseif (array_keys($GLOBALS['gateway_filter']($gateways)) !== ['payeasebuzz', 'cod']) {
    exit(9);
}
echo defined('ASTRO_STAGE_EASEBUZZ_LIVE_WINDOW')
    ? "PASS isolated approved-window allowlist contract\n"
    : "PASS default-closed staging egress contract\n";
