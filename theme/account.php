<?php
/** Route public account forms to WooCommerce's existing customer account. */

add_filter('body_class', function ($classes) {
    if (is_page(array('login', 'register'))) {
        $classes[] = 'astro-auth-page';
    }
    return $classes;
});

function astro_account_destination_from_request(): string {
    $dashboard = wc_get_page_permalink('myaccount');
    $raw = isset($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to'])
        ? wp_unslash($_REQUEST['redirect_to'])
        : (isset($_POST['redirect']) && is_string($_POST['redirect']) ? wp_unslash($_POST['redirect']) : '');
    if ($raw === '' || str_starts_with($raw, '//')) {
        return $dashboard;
    }
    $parsed = wp_parse_url($raw);
    if (!is_array($parsed) || isset($parsed['user']) || isset($parsed['pass'])
        || (isset($parsed['host']) && !hash_equals(strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)), strtolower($parsed['host'])))) {
        return $dashboard;
    }
    $base = (string) wp_parse_url($dashboard, PHP_URL_PATH);
    $path = $parsed['path'] ?? '';
    if (array_intersect(array('.', '..'), explode('/', rawurldecode($path)))) {
        return $dashboard;
    }
    if ($path !== rtrim($base, '/') && !str_starts_with($path, trailingslashit($base))) {
        return $dashboard;
    }
    if (preg_match('~/(?:lost-password|customer-logout)(?:/|$)~', $path)) {
        return $dashboard;
    }
    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    return wp_validate_redirect(home_url($path . $query), $dashboard);
}

add_action('template_redirect', function () {
    if (!function_exists('wc_get_page_permalink')) {
        return;
    }
    if (is_page(array('login', 'register'))) {
        if (is_user_logged_in()) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
        return;
    }
    if (!is_page(wc_get_page_id('myaccount')) || is_user_logged_in()) {
        return;
    }
    // WooCommerce's native lost/reset-password flow must remain public.
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('lost-password')) {
        return;
    }
    // WooCommerce receives reset links on the dashboard URL before moving the
    // credentials into its protected lost-password flow (hook priority 10).
    if (isset($_GET['key']) && (isset($_GET['id']) || isset($_GET['login']))) {
        return;
    }
    if (isset($_GET['password-reset']) && $_GET['password-reset'] === 'true') {
        wp_safe_redirect(add_query_arg('password-reset', 'true', home_url('/login/')));
        exit;
    }
    $destination = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
        ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/my-account/';
    wp_safe_redirect(add_query_arg('redirect_to', $destination, home_url('/login/')));
    exit;
}, 1);

add_filter('woocommerce_login_redirect', function ($redirect) {
    if (!isset($_POST['astro_login_page'])) {
        return $redirect;
    }
    return astro_account_destination_from_request();
}, 10, 1);

add_filter('woocommerce_registration_redirect', function ($redirect) {
    return isset($_POST['astro_register_page']) ? wc_get_page_permalink('myaccount') : $redirect;
}, 10, 1);
