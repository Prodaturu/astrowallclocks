<?php
/** Local theme scaffold; commerce integration is pending. */

add_action('after_setup_theme', function () {
    add_theme_support('woocommerce');
    add_theme_support('title-tag');
});
