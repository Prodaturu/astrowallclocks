<?php
/** Validate one synthetic browser checkout against the isolated HPOS store. */
if (DB_NAME !== 'bgt9xah2o3eo_awstage'
    || get_option('siteurl') !== 'https://staging.astrowallclocks.com'
    || !\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    || !has_filter('pre_wp_mail')) {
    throw new RuntimeException('Refusing a non-staging or unsafe store');
}
$id = (int) getenv('ASTRO_STAGE_COD_ORDER_ID');
$stock_before = (int) getenv('ASTRO_STAGE_STOCK_BEFORE');
$order = wc_get_order($id);
$product = wc_get_product(wc_get_product_id_by_sku('ASTRO-STAGING-TEST'));
if (!$order || !$product || $order->get_billing_email() !== 'synthetic-checkout@example.test') {
    throw new RuntimeException('Synthetic COD fixture missing');
}
global $wpdb;
$table = $wpdb->prefix . 'wc_orders';
$row = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id));
$items = $order->get_items();
$item = reset($items);
$checks = [
    'HPOS row' => (bool) $row,
    'COD gateway' => $order->get_payment_method() === 'cod',
    'status' => in_array($order->get_status(), ['processing', 'on-hold'], true),
    'quantity' => $item && $item->get_quantity() === 1,
    'stock' => (int) $product->get_stock_quantity() === $stock_before - 1,
    'total' => abs((float) $order->get_total() - ((float) $order->get_subtotal() + (float) $order->get_shipping_total())) <= 0.01,
];
foreach ($checks as $label => $ok) {
    if (!$ok) throw new RuntimeException('Synthetic COD mismatch: ' . $label);
}
echo 'PASS synthetic browser COD checkout: HPOS order, status, totals, stock reduction, no email' . PHP_EOL;
