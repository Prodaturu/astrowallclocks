<?php
/** Synthetic fixtures. Run only via the local compose WP-CLI service. */

if (DB_NAME !== 'astrowallclocks_test' || get_option('siteurl') !== 'http://127.0.0.1:8088') {
    throw new RuntimeException('Refusing to seed a non-local site.');
}
if (!class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active first.');
}

update_option('woocommerce_currency', 'INR');
update_option('woocommerce_default_country', 'IN:TG');
update_option('woocommerce_manage_stock', 'yes');
update_option('blog_public', '0');

foreach ([
    'shop' => ['Shop', '', 'woocommerce_shop_page_id'],
    'cart' => ['Cart', '[woocommerce_cart]', 'woocommerce_cart_page_id'],
    'checkout' => ['Checkout', '[woocommerce_checkout]', 'woocommerce_checkout_page_id'],
    'my-account' => ['My Account', '[woocommerce_my_account]', 'woocommerce_myaccount_page_id'],
    'about' => ['About', '', null],
    'contact-us' => ['Contact', '', null],
    'track-order' => ['Track Order', '[woocommerce_order_tracking]', null],
    'blog' => ['Blog', '', null],
] as $slug => [$title, $content, $option]) {
    $page = get_page_by_path($slug);
    $id = $page ? $page->ID : wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => $content,
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    if ($option) {
        update_option($option, $id);
    }
}

$product_id = wc_get_product_id_by_sku('ASTRO-LOCAL-TEST');
if (!$product_id) {
    $product = new WC_Product_Simple();
    $product->set_name('Astro Wall Clock LOCAL TEST');
    $product->set_sku('ASTRO-LOCAL-TEST');
    $product->set_status('publish');
    $product->set_slug('astro-wall-clock');
    $product->set_regular_price('4499');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $product->set_catalog_visibility('visible');
    $product_id = $product->save();
}
$product = wc_get_product($product_id);
$product->set_slug('astro-wall-clock');
$product->save();
update_option('astro_local_test_product_id', $product_id);

if (!username_exists('astro_synthetic_customer')) {
    $customer_id = wp_create_user('astro_synthetic_customer', wp_generate_password(40), 'astro-customer@example.test');
    if (is_wp_error($customer_id)) {
        throw new RuntimeException($customer_id->get_error_message());
    }
    (new WP_User($customer_id))->set_role('customer');
}

WP_CLI::success('Local-only pages, test product, and synthetic customer are ready.');
