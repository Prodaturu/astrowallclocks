<?php
/**
 * Render the existing HTML design with WooCommerce product values.
 * Cart, checkout, and account use native WooCommerce shortcodes.
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
$product = function_exists('is_product') && is_product() ? wc_get_product(get_queried_object_id()) : null;
if ($product) {
    $path = 'product/astro-wall-clock/index.html';
}
if ($path) {
    $file = get_theme_file_path('design/' . $path);
    if (is_readable($file)) {
        $html = file_get_contents($file);
        // The repository's localStorage cart is only a static demo. Use WooCommerce instead.
        $html = str_replace('<script src="/assets/cart.js" defer></script>', '', $html);
        $html = str_replace('<link rel="stylesheet" href="/assets/modern.css">', '', $html);
        $page = $product ? 'product' : (is_front_page() ? 'home' : (function_exists('is_shop') && is_shop() ? 'shop' : $slug));
        $html = astro_design_html($html, $page, $product ?: null);
        $html = preg_replace('~<title>.*?</title>~s', '', $html, 1);
        ob_start();
        wp_head();
        $head = ob_get_clean();
        ob_start();
        wp_body_open();
        $body_open = ob_get_clean();
        ob_start();
        wp_footer();
        $footer = ob_get_clean();
        $html = str_replace('</head>', $head . '</head>', $html);
        $html = str_replace('<body>', '<body>' . $body_open, $html);
        $html = str_replace('</body>', $footer . '</body>', $html);
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
