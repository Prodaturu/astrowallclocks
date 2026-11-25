<?php
/** Synthetic fixtures. Run only via the local compose WP-CLI service. */

if (DB_NAME !== 'astrowallclocks_test' || get_option('siteurl') !== 'http://127.0.0.1:8088') {
    throw new RuntimeException('Refusing to seed a non-local site.');
}
if (!class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active first.');
}

update_option('woocommerce_currency', 'INR');
update_option('woocommerce_default_country', 'IN:TS');
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_enable_shipping', 'yes');
update_option('blog_public', '0');
update_option('woocommerce_coming_soon', 'no');

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
    if ($content !== '' && get_post_field('post_content', $id) !== $content) {
        wp_update_post(['ID' => $id, 'post_content' => $content]);
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
    $product->set_short_description('Digital wall clock with astrological time insights.');
    $product->set_description('A digital wall clock designed for meaningful moments at home.');
    $product_id = $product->save();
}
$product = wc_get_product($product_id);
$product->set_slug('astro-wall-clock');
$product->set_short_description('Digital wall clock with astrological time insights.');
$product->set_description('A digital wall clock designed for meaningful moments at home.');

$uploads = wp_get_upload_dir();
foreach ([
    '2025/09/FOOTER.png' => 'astro_local_featured_image_id',
    '2025/08/product1.png' => 'astro_local_gallery_image_id',
] as $relative_path => $option) {
    $image_id = (int) get_option($option, 0);
    $filename = trailingslashit($uploads['basedir']) . $relative_path;
    if ($image_id === 0 && is_readable($filename)) {
        $image_id = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => 'LOCAL TEST ' . basename($filename),
            'post_status' => 'inherit',
        ], $filename, $product_id, true);
        if (!is_wp_error($image_id)) {
            update_attached_file($image_id, $filename);
            update_option($option, $image_id);
        }
    }
}
$featured_image_id = (int) get_option('astro_local_featured_image_id', 0);
if ($featured_image_id) {
    $product->set_image_id($featured_image_id);
}
$gallery_image_id = (int) get_option('astro_local_gallery_image_id', 0);
if ($gallery_image_id) {
    $product->set_gallery_image_ids([$gallery_image_id]);
}
$product->save();
update_option('astro_featured_product_id', $product_id);

$gateway = WC()->payment_gateways()->payment_gateways()['cod'] ?? null;
if ($gateway) {
    $gateway->update_option('enabled', 'yes');
}

$zone = new WC_Shipping_Zone(0);
$flat_rate = null;
foreach ($zone->get_shipping_methods() as $method) {
    if ($method->id === 'flat_rate') {
        $flat_rate = $method;
        break;
    }
}
$instance_id = $flat_rate ? $flat_rate->instance_id : $zone->add_shipping_method('flat_rate');
update_option('woocommerce_flat_rate_' . $instance_id . '_settings', [
    'title' => 'Local test shipping',
    'enabled' => 'yes',
    'cost' => '100',
]);

if (!username_exists('astro_synthetic_customer')) {
    $customer_id = wp_create_user('astro_synthetic_customer', wp_generate_password(40), 'astro-customer@example.test');
    if (is_wp_error($customer_id)) {
        throw new RuntimeException($customer_id->get_error_message());
    }
    (new WP_User($customer_id))->set_role('customer');
}

WP_CLI::success('Local-only pages, test product, and synthetic customer are ready.');
