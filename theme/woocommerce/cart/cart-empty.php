<?php
/**
 * Empty WooCommerce cart, styled to match the Ekshan storefront.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 7.0.1
 */

defined('ABSPATH') || exit;

$featured = astro_featured_product();
$featured = $featured instanceof WC_Product && $featured->is_visible() ? $featured : null;
$shop_url = wc_get_page_id('shop') > 0
    ? apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))
    : home_url('/');
$shop_text = apply_filters('woocommerce_return_to_shop_text', __('Return to shop', 'woocommerce'));
?>
<div class="astro-empty-cart">
    <div class="astro-empty-cart-message">
        <div class="astro-empty-cart-symbol" aria-hidden="true">
            <svg viewBox="0 0 80 80" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M11 15h9l8 39h35l7-28H23" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="35" cy="65" r="3" fill="currentColor" stroke="none" />
                <circle cx="59" cy="65" r="3" fill="currentColor" stroke="none" />
            </svg>
        </div>
        <p class="astro-empty-cart-eyebrow">YOUR SELECTION</p>
        <?php do_action('woocommerce_cart_is_empty'); ?>
        <p class="astro-empty-cart-description">Take another look at the clock and add it when you’re ready.</p>
        <a class="astro-empty-cart-shop" href="<?php echo esc_url($shop_url); ?>">
            <?php echo esc_html($shop_text); ?> <span aria-hidden="true">↗</span>
        </a>
    </div>

    <?php if ($featured) : ?>
        <aside class="astro-empty-cart-product" aria-label="Featured product">
            <p class="astro-empty-cart-product-label">FEATURED CLOCK</p>
            <a class="astro-empty-cart-product-image" href="<?php echo esc_url(get_permalink($featured->get_id())); ?>">
                <img src="<?php echo esc_url(astro_product_image_url($featured)); ?>"
                     alt="<?php echo esc_attr($featured->get_name()); ?>" loading="lazy" />
            </a>
            <div class="astro-empty-cart-product-details">
                <div>
                    <h2><a href="<?php echo esc_url(get_permalink($featured->get_id())); ?>"><?php echo esc_html($featured->get_name()); ?></a></h2>
                    <p class="astro-empty-cart-product-price"><?php echo wp_kses_post(wc_price(wc_get_price_to_display($featured))); ?></p>
                </div>
                <?php if (astro_can_add_to_cart($featured)) : ?>
                    <a class="astro-empty-cart-add" href="<?php echo esc_url(astro_add_to_cart_url($featured)); ?>">
                        Add to cart <span aria-hidden="true">↗</span>
                    </a>
                <?php else : ?>
                    <span class="astro-empty-cart-stock"><?php echo esc_html(astro_product_stock_text($featured)); ?></span>
                <?php endif; ?>
            </div>
        </aside>
    <?php endif; ?>
</div>
