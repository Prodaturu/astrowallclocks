<?php
/** Isolated staging safety policy; not installed in production. */
if (DB_NAME !== 'bgt9xah2o3eo_awstage') { return; }
add_filter('pre_wp_mail', '__return_true', 1);
add_filter('pre_http_request', function ($preempt, $args, $url) {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    foreach (['shiprocket.in', 'shiprocket.com', 'razorpay.com', 'razorpay.in', 'pay.easebuzz.in', 'testpay.easebuzz.in'] as $blocked) {
        if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
            return new WP_Error('astro_staging_external_block', 'Payment and shipment calls are blocked while staging uses dummy credentials.');
        }
    }
    return $preempt;
}, 10, 3);
