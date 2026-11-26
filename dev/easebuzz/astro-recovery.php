<?php
/** Recover a missed Easebuzz return using the authenticated transaction API. */

if (!defined('ABSPATH')) {
    exit;
}

add_action('astro_easebuzz_recover_order', 'astro_easebuzz_recover_order', 10, 2);

function astro_easebuzz_schedule_recovery($order_id, $attempt) {
    $delays = array(300, 900, 3600, 10800);
    if (!isset($delays[$attempt])) {
        return;
    }
    $args = array((int) $order_id, (int) $attempt);
    if (!wp_next_scheduled('astro_easebuzz_recover_order', $args)) {
        wp_schedule_single_event(time() + $delays[$attempt], 'astro_easebuzz_recover_order', $args);
    }
}

/** Return one transaction record only; ambiguous or incomplete responses fail closed. */
function astro_easebuzz_retrieve_transaction($txnid, $settings) {
    $key = trim((string) ($settings['key_id'] ?? ''));
    $salt = trim((string) ($settings['key_secret'] ?? ''));
    if ($key === '' || $salt === '' || $txnid === '') {
        return null;
    }
    $host = ($settings['test_mode'] ?? 'test') === 'prod'
        ? 'https://dashboard.easebuzz.in/' : 'https://testdashboard.easebuzz.in/';
    $response = wp_remote_post($host . 'transaction/v2/retrieve', array(
        'timeout' => 15,
        'redirection' => 0,
        'sslverify' => true,
        'body' => array(
            'key' => $key,
            'txnid' => $txnid,
            'hash' => hash('sha512', $key . '|' . $txnid . '|' . $salt),
        ),
    ));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !in_array($body['status'] ?? null, array(1, true), true)) {
        return null;
    }
    $rows = $body['msg'] ?? null;
    if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }
    return $rows[0];
}

function astro_easebuzz_recover_order($order_id, $attempt = 0) {
    $order = wc_get_order((int) $order_id);
    if (!$order || $order->get_payment_method() !== 'payeasebuzz'
        || !in_array($order->get_status(), array('pending', 'on-hold'), true)
        || $order->is_paid()) {
        return;
    }
    $settings = get_option(EASEBUZZ_SETTINGS_KEY, array());
    $txnid = (string) $order->get_meta('_astro_easebuzz_expected_txnid', true);
    $record = astro_easebuzz_retrieve_transaction($txnid, $settings);
    if ($record !== null && easebuzz_response_matches_order($order, $record, $settings)
        && strtolower((string) ($record['status'] ?? '')) === 'success'
        && !empty($record['easepayid'])) {
        $order->set_transaction_id(sanitize_text_field($record['easepayid']));
        $order->save();
        easebuzz_apply_payment_status($order, 'success', $record, 'verified lookup');
        return;
    }
    // A failed lookup or a non-success status never changes an order. Retry a
    // bounded number of times, then flag the order for manual reconciliation.
    if ((int) $attempt >= 3) {
        $order->add_order_note('Easebuzz status still unverified after recovery retries; reconcile in Easebuzz before fulfillment.');
        return;
    }
    astro_easebuzz_schedule_recovery((int) $order_id, (int) $attempt + 1);
}
