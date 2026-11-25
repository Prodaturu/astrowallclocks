<?php
/** Synthetic staging fixtures; password enters only through a temporary env var. */
if (DB_NAME !== 'bgt9xah2o3eo_awstage'
    || get_option('siteurl') !== 'https://staging.astrowallclocks.com'
    || !has_filter('pre_wp_mail')) {
    throw new RuntimeException('Wrong site or email guard missing');
}
$password = getenv('ASTRO_STAGE_CUSTOMER_PASS');
if (!is_string($password) || !preg_match('/^[0-9a-f]{40}$/', $password)) {
    throw new RuntimeException('Synthetic password missing');
}
$username = 'astro_stage_customer_test';
$id = username_exists($username);
if (!$id) {
    $id = wp_create_user($username, $password, 'account-staging@example.test');
}
if (is_wp_error($id)) {
    throw new RuntimeException('Synthetic customer creation failed');
}
wp_set_password($password, $id);
$user = new WP_User($id);
$user->set_role('customer');
$product = wc_get_product(wc_get_product_id_by_sku('ASTRO-STAGING-TEST'));
if (!$product) {
    throw new RuntimeException('Synthetic product missing');
}
function astro_stage_customer_order($product, $customer_id) {
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_payment_method('cod');
    $order->set_customer_id($customer_id);
    $order->set_billing_email('account-staging@example.test');
    $order->calculate_totals();
    $order->save();
    return $order->get_id();
}
$registered = astro_stage_customer_order($product, $id);
$guest = astro_stage_customer_order($product, 0);
echo "REGISTERED_ORDER_ID=$registered\nGUEST_ORDER_ID=$guest\n";
