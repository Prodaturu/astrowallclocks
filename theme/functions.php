<?php
/** Existing Astro design with WooCommerce commerce pages. */

add_action('after_setup_theme', function () {
    add_theme_support('woocommerce');
    add_theme_support('title-tag');
});

require_once __DIR__ . '/commerce.php';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('astro-design', get_theme_file_uri('design/assets/modern.css'), [], '0.1.0');
    wp_enqueue_style('astro-commerce', get_theme_file_uri('commerce.css'), ['astro-design'], '0.1.0');
});
