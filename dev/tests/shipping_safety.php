<?php
/** Static filter contract check; never makes an HTTP request. */
if (DB_NAME !== 'astrowallclocks_test') {
    throw new RuntimeException('Not the isolated local store');
}
foreach (['https://apiv2.shiprocket.in/v1/external/orders/create/adhoc',
          'https://pay.easebuzz.in/payment/initiateLink',
          'https://api.razorpay.com/v1/orders'] as $url) {
    $result = apply_filters('pre_http_request', false, [], $url);
    if (!is_wp_error($result) || $result->get_error_code() !== 'astro_local_external_block') {
        throw new RuntimeException('Local egress guard failed');
    }
}
if (!has_filter('pre_wp_mail')) {
    throw new RuntimeException('Email suppression missing');
}
echo "PASS local payment and Shiprocket requests blocked before network I/O\n";
echo "PASS customer email suppressed locally\n";
