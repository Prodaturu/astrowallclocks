<?php
/**
 * Local-only guardrails. This file is mounted only by dev/compose.yaml.
 */

if (DB_NAME !== 'astrowallclocks_test') {
    return;
}

// WordPress and WooCommerce may render emails, but none leave this local site.
add_filter('pre_wp_mail', '__return_true', 1);

// No payment or shipping service should receive a request during local tests.
add_filter('pre_http_request', function ($preempt, $args, $url) {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    foreach (['easebuzz', 'razorpay', 'shiprocket'] as $provider) {
        if ($host === $provider . '.in' || str_ends_with($host, '.' . $provider . '.in')
            || $host === $provider . '.com' || str_ends_with($host, '.' . $provider . '.com')) {
            return new WP_Error('astro_local_external_block', 'External payment and shipment calls are blocked locally.');
        }
    }
    return $preempt;
}, 10, 3);
