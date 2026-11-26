<?php
/** WooCommerce-backed values for the existing HTML design. */

function astro_featured_product(): ?WC_Product {
    if (!function_exists('wc_get_product')) {
        return null;
    }
    $id = (int) get_option('astro_featured_product_id', 0);
    $product = $id ? wc_get_product($id) : false;
    return $product instanceof WC_Product ? $product : null;
}

function astro_product_image_url(WC_Product $product): string {
    return wp_get_attachment_image_url($product->get_image_id(), 'full') ?: wc_placeholder_img_src();
}

function astro_product_price_markup(WC_Product $product): string {
    if ($product->get_price() === '') {
        return '<strong>Price unavailable</strong>';
    }
    $price = wc_price(wc_get_price_to_display($product));
    if ($product->is_on_sale() && $product->get_regular_price() !== '') {
        $regular = wc_price(wc_get_price_to_display($product, ['price' => $product->get_regular_price()]));
        return '<del>' . $regular . '</del><strong>' . $price . '</strong>';
    }
    return '<strong>' . $price . '</strong>';
}

function astro_product_stock_text(WC_Product $product): string {
    if (!$product->is_in_stock()) {
        return 'Out of stock';
    }
    $quantity = $product->get_stock_quantity();
    return $product->managing_stock() && $quantity !== null
        ? sprintf('%d in stock', (int) $quantity)
        : 'In stock';
}

function astro_can_add_to_cart(WC_Product $product): bool {
    return $product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock();
}

function astro_add_to_cart_url(WC_Product $product, bool $stay_on_product = false): string {
    if (!astro_can_add_to_cart($product)) {
        return get_permalink($product->get_id());
    }
    $destination = $stay_on_product ? get_permalink($product->get_id()) : wc_get_cart_url();
    return add_query_arg(['add-to-cart' => $product->get_id(), 'quantity' => 1], $destination);
}

function astro_shop_cards(): array {
    if (!function_exists('wc_get_products')) {
        return [];
    }
    $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);
    return array_values(array_filter($products, static function (WC_Product $product): bool {
        return in_array($product->get_catalog_visibility(), ['visible', 'catalog'], true);
    }));
}

function astro_render_shop_card(WC_Product $product): string {
    $name = esc_html($product->get_name());
    $url = esc_url(get_permalink($product->get_id()));
    $image = esc_url(astro_product_image_url($product));
    $description = esc_html(wp_strip_all_tags($product->get_short_description()));
    $price = astro_product_price_markup($product);
    $action = astro_can_add_to_cart($product)
        ? '<a class="button button-dark" href="' . esc_url(astro_add_to_cart_url($product)) . '">Add to cart <span aria-hidden="true">↗</span></a>'
        : '<span class="static-note">' . esc_html(astro_product_stock_text($product)) . '</span>';
    return '<article class="shop-product"><a class="shop-product-image" href="' . $url . '"><img src="' . $image . '" alt="' . $name . '"></a>'
        . '<div class="shop-product-copy"><span class="product-tag">EKSHAN ORIGINAL</span><h2>' . $name . '</h2><p>' . $description . '</p>'
        . '<div class="price">' . $price . '</div><div class="shop-actions">' . $action
        . '<a class="shop-detail-link" href="' . $url . '">Product details</a></div></div></article>';
}

function astro_replace_section(string $html, string $start_marker, string $end_marker, string $replacement): string {
    $start = strpos($html, $start_marker);
    $end = $start === false ? false : strpos($html, $end_marker, $start);
    if ($start === false || $end === false) {
        return $html;
    }
    return substr_replace($html, $replacement, $start, $end + strlen($end_marker) - $start);
}

/** Preserve Shiprocket's optional product checker in the custom product layout. */
function astro_shiprocket_product_checker(WC_Product $item): string {
    $settings = get_option('woocommerce_shiprocket_woocommerce_shipping_settings', []);
    if (!function_exists('shiprocket_show_check_pincode') || empty($settings['integration_id'])) {
        return '';
    }
    $previous = $GLOBALS['product'] ?? null;
    $GLOBALS['product'] = $item;
    ob_start();
    try {
        shiprocket_show_check_pincode();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
        $GLOBALS['product'] = $previous;
    }
}

function astro_design_html(string $html, string $page, ?WC_Product $product = null): string {
    $featured = astro_featured_product();
    $cart_count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $html = str_replace('View demo cart', 'View cart', $html);
    $html = str_replace('<b class="cart-count" aria-hidden="true">0</b>', '<b class="cart-count" aria-hidden="true">' . (int) $cart_count . '</b>', $html);
    $html = str_replace('Free shipping across India', 'Shipping calculated at checkout', $html);
    if (function_exists('wc_get_page_permalink')) {
        $account_links = is_user_logged_in()
            ? '<a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">My Account</a><a href="' . esc_url(wc_logout_url(home_url('/login/'))) . '">Logout</a>'
            : '<a href="' . esc_url(home_url('/login/')) . '">Login</a><a href="' . esc_url(home_url('/register/')) . '">Register</a>';
        $html = str_replace('<a href="/track-order/">Track Order</a>', '<a href="/track-order/">Track Order</a>' . $account_links, $html);
    }
    $cod = function_exists('WC') ? (WC()->payment_gateways()->payment_gateways()['cod'] ?? null) : null;
    if (!$cod || $cod->enabled !== 'yes') {
        $html = str_replace(' · COD available', '', $html);
    }
    if ($featured) {
        $html = str_replace('/cart/?add-to-cart=516&amp;quantity=1', esc_url(astro_add_to_cart_url($featured, true)), $html);
    }

    $product = $product ?: $featured;
    if (!$product) {
        return $html;
    }
    $price = astro_product_price_markup($product);
    $html = preg_replace_callback('~<div class="price"><del>.*?</del><strong>.*?</strong></div>~us', fn() => '<div class="price">' . $price . '</div>', $html);
    $html = str_replace('/wp-content/uploads/2025/09/FOOTER.png', esc_url(astro_product_image_url($product)), $html);
    $gallery_ids = $product->get_gallery_image_ids();
    $gallery = $gallery_ids ? wp_get_attachment_image_url($gallery_ids[0], 'full') : false;
    if ($gallery) {
        $html = str_replace('/wp-content/uploads/2025/08/product1.png', esc_url($gallery), $html);
    }
    $html = str_replace('Limited stock. Next batch dispatches in 5 days.', esc_html(astro_product_stock_text($product)), $html);

    if ($page === 'home') {
        $html = str_replace('<p class="order-lede">A meaningful addition to your space, or a gift they’ll remember.</p>', '<p class="order-lede">' . esc_html($product->get_name()) . ' · ' . esc_html(wp_strip_all_tags($product->get_short_description())) . '</p>', $html);
    }

    if ($page === 'shop') {
        $products = astro_shop_cards();
        $cards = implode('', array_map('astro_render_shop_card', $products));
        $html = astro_replace_section($html, '<article class="shop-product">', '</article>', $cards);
        $html = str_replace('01 PRODUCT', sprintf('%02d %s', count($products), count($products) === 1 ? 'PRODUCT' : 'PRODUCTS'), $html);
    }

    if ($page === 'product') {
        $checker = astro_shiprocket_product_checker($product);
        if ($checker !== '') {
            $html = preg_replace_callback('~<form class="purchase-form".*?</form>~us',
                fn($match) => $match[0] . '<div class="astro-delivery-check">' . $checker . '</div>', $html, 1);
        }
        $name = esc_html($product->get_name());
        $description = esc_html(wp_strip_all_tags($product->get_short_description()));
        $html = str_replace('<h1>Astro Wall <em>Clock.</em></h1>', '<h1><em>' . $name . '</em></h1>', $html);
        $html = str_replace('A thoughtful companion for the time you see and the time you feel.', $description, $html);
        $html = str_replace('Ekshan combines a digital clock with astrological time insights, designed to keep meaningful moments in view throughout your day.', $description, $html);
        $html = preg_replace_callback('~<ul class="product-highlights">.*?</ul>~us', fn() => '<div class="product-description">' . wp_kses_post($product->get_description()) . '</div>', $html);
        $html = str_replace('<p class="static-note">This is a demo cart. It cannot check delivery pincodes or process payments.</p>', '<p class="static-note">' . esc_html(astro_product_stock_text($product)) . ' · Shipping calculated at checkout</p>', $html);
        $html = str_replace('name="add-to-cart" value="516"', 'name="add-to-cart" value="' . (int) $product->get_id() . '"', $html);
        $max = $product->get_max_purchase_quantity();
        if ($max > 0) {
            $html = str_replace('name="quantity" min="1"', 'name="quantity" min="1" max="' . (int) $max . '"', $html);
        }
        if (!astro_can_add_to_cart($product)) {
            $html = preg_replace_callback('~<form class="purchase-form".*?</form>~us', fn() => '<p class="static-note">' . esc_html(astro_product_stock_text($product)) . '</p>', $html);
        }
        $html = preg_replace_callback('~<title>.*?</title>~us', fn() => '<title>' . $name . ' | Astro Wall Clocks</title>', $html, 1);
    }
    return $html;
}
