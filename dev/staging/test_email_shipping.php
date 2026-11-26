<?php
/** Render a synthetic WooCommerce email and prove shipping egress is blocked. */
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'bgt9xah2o3eo_awstage'
    || site_url() !== 'https://staging.astrowallclocks.com') {
    exit(2);
}
if (!has_filter('pre_wp_mail') || !has_filter('pre_http_request')) {
    exit(3);
}
$orders = wc_get_orders([
    'limit' => 20,
    'payment_method' => 'cod',
    'orderby' => 'date',
    'order' => 'DESC',
]);
$synthetic = null;
foreach ($orders as $order) {
    if (str_ends_with((string) $order->get_billing_email(), '@example.test')) {
        $synthetic = $order;
        break;
    }
}
if (!$synthetic) {
    exit(4);
}
$emails = WC()->mailer()->get_emails();
$email = $emails['WC_Email_Customer_Processing_Order'] ?? null;
if (!$email) {
    exit(5);
}
$email->object = $synthetic;
$rendered = $email->get_content_html();
if (strlen($rendered) < 500 || strpos($rendered, 'Astro Wall Clock') === false) {
    exit(6);
}
$preempt = apply_filters('pre_wp_mail', null, ['to' => 'synthetic@example.test']);
if ($preempt !== true) {
    exit(7);
}
$blocked = apply_filters(
    'pre_http_request',
    false,
    [],
    'https://apiv2.shiprocket.in/v1/external/woocommerce/courier/serviceability'
);
if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'astro_staging_external_block') {
    exit(8);
}
echo 'PASS synthetic customer email rendered; delivery suppressed; Shiprocket API blocked' . PHP_EOL;
