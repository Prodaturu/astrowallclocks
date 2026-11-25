<?php
/**
 * Preview the existing HTML design inside an isolated WordPress installation.
 * Commerce pages remain under WooCommerce until dedicated templates exist.
 */

if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
    get_header();
    while (have_posts()) {
        the_post();
        the_content();
    }
    get_footer();
    return;
}

$design_pages = [
    'about' => 'about/index.html',
    'contact-us' => 'contact-us/index.html',
    'shop' => 'shop/index.html',
    'blog' => 'blog/index.html',
];

$slug = get_post_field('post_name', get_queried_object_id());
$path = is_front_page() ? 'index.html' : ($design_pages[$slug] ?? null);
if (function_exists('is_product') && is_product() && $slug === 'astro-wall-clock') {
    $path = 'product/astro-wall-clock/index.html';
}
if ($path) {
    $file = get_theme_file_path('design/' . $path);
    if (is_readable($file)) {
        $html = file_get_contents($file);
        // The repository's localStorage cart is only a static demo. Use WooCommerce instead.
        $html = str_replace('<script src="/assets/cart.js" defer></script>', '', $html);
        $html = str_replace('href="/assets/', 'href="' . esc_url(get_theme_file_uri('design/assets/')), $html);
        $html = str_replace('src="/assets/', 'src="' . esc_url(get_theme_file_uri('design/assets/')), $html);
        if (function_exists('WC') && WC()->cart) {
            $html = str_replace('<b class="cart-count" aria-hidden="true">0</b>', '<b class="cart-count" aria-hidden="true">' . (int) WC()->cart->get_cart_contents_count() . '</b>', $html);
        }
        $test_product_id = (int) get_option('astro_local_test_product_id', 0);
        if ($test_product_id > 0 && DB_NAME === 'astrowallclocks_test') {
            $html = str_replace('add-to-cart=516', 'add-to-cart=' . $test_product_id, $html);
            $html = str_replace('name="add-to-cart" value="516"', 'name="add-to-cart" value="' . $test_product_id . '"', $html);
        }
        echo $html; // Existing trusted repository HTML, local preview only.
        return;
    }
}

get_header();
while (have_posts()) {
    the_post();
    the_content();
}
get_footer();
