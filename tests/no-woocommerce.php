<?php
declare(strict_types=1);
// Author: Dhruba Poudel. Isolated process: WooCommerce functions must genuinely be absent.
require_once __DIR__ . '/wp-base-stubs.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/CanonicalJson.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/Reconciler.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/WooCommerceAdapter.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/Plugin.php';

use DhrubaPoudel\CommerceReconcile\Plugin;

function no_woo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    no_woo_assert(!function_exists('wc_get_order'), 'WooCommerce function leaked into isolated process.');
    $plugin = new Plugin(__DIR__ . '/../plugin/commerce-reconcile/commerce-reconcile.php');
    no_woo_assert($plugin->capability() === 'manage_options', 'No-Woo mode did not select manage_options.');
    $GLOBALS['cr_test_caps'] = ['manage_options' => false, 'manage_woocommerce' => true];
    no_woo_assert($plugin->allowed() === false, 'A Woo-only capability passed the no-Woo fallback.');
    $GLOBALS['cr_test_caps']['manage_options'] = true;
    no_woo_assert($plugin->allowed() === true, 'A capable administrator cannot use no-Woo mode.');
    $request = new WP_REST_Request('{"mode":"fixture","orders":[],"events":[]}', ['x-wp-nonce' => 'valid-nonce', 'content-type' => 'application/json']);
    $result = $plugin->restAnalyse($request);
    no_woo_assert($result instanceof WP_REST_Response && $result->status === 200 && $result->data['summary']['events'] === 0, 'No-Woo fixture REST mode failed.');
    $request = new WP_REST_Request('{"mode":"woocommerce","events":[]}', ['x-wp-nonce' => 'valid-nonce', 'content-type' => 'application/json']);
    $result = $plugin->restAnalyse($request);
    no_woo_assert($result instanceof WP_Error && $result->data['status'] === 409, 'Woo mode did not report its unavailable adapter.');
    echo json_encode(['status' => 'PASS', 'woocommerce_function_available' => false, 'checks' => 6], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
