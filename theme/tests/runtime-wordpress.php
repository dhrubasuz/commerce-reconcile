<?php
/**
 * Commerce Canvas integration checks against a real local WordPress/WooCommerce.
 * Author / Creator / Last Modified By: Dhruba Poudel. GPL-2.0-or-later.
 *
 * Usage: php [runtime extension flags] theme/tests/runtime-wordpress.php [wp-load.php]
 * Only the explicitly named local wp-test installation is accepted. Temporary
 * products are tagged by a unique SKU prefix and removed in finally. No settings,
 * credentials, existing products or orders are intentionally changed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function cc_runtime_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cc_runtime_document(string $html): DOMDocument
{
    $before = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($before);
    return $document;
}

function cc_runtime_order_snapshot(): array
{
    $records = [];
    foreach (['shop_order', 'shop_order_refund'] as $type) {
        $orders = wc_get_orders(['type' => $type, 'limit' => 1000, 'orderby' => 'ID', 'order' => 'ASC']);
        cc_runtime_check(count($orders) < 1000, 'Local order fixture bound exceeded; refusing a broad snapshot.');
        foreach ($orders as $order) {
            $records[(string) $order->get_id()] = $order->get_data();
        }
    }
    ksort($records, SORT_NUMERIC);
    // Only a fingerprint and count are retained; no order/customer data is logged.
    return ['count' => count($records), 'sha256' => hash('sha256', json_encode($records, JSON_THROW_ON_ERROR))];
}

$bootstrap = realpath($argv[1] ?? dirname(__DIR__, 4) . '/runtime/wp-test/wordpress/wp-load.php');
cc_runtime_check($bootstrap !== false, 'The local WordPress bootstrap was not found.');
$normalised = strtolower(str_replace('\\', '/', $bootstrap));
cc_runtime_check(str_contains($normalised, '/work/runtime/wp-test/wordpress/'), 'Only the designated local wp-test installation is allowed.');
cc_runtime_check(class_exists('DOMDocument'), 'PHP DOM is required for the escaping checks.');

$_SERVER['REQUEST_METHOD'] ??= 'GET';
$_SERVER['HTTP_HOST'] ??= '127.0.0.1';
$_SERVER['SERVER_NAME'] ??= '127.0.0.1';
$_SERVER['REQUEST_URI'] ??= '/';
$_SERVER['SCRIPT_NAME'] ??= '/index.php';
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
require $bootstrap;

$host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
cc_runtime_check(in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true), 'Only a loopback WordPress site is permitted.');
cc_runtime_check(function_exists('wc_get_products') && class_exists('WC_Product_Simple'), 'WooCommerce must be active.');
cc_runtime_check(get_stylesheet() === 'commerce-canvas', 'Commerce Canvas must already be active; this script does not switch themes.');
cc_runtime_check(function_exists('commerce_canvas_live_products') && function_exists('commerce_canvas_product_card'), 'Commerce Canvas helpers are unavailable.');
cc_runtime_check(current_theme_supports('woocommerce'), 'The active theme did not register WooCommerce support.');

$block_network = static fn() => new WP_Error('canvas_runtime_no_network', 'Network requests are disabled during this local integration check.');
add_filter('pre_http_request', $block_network, 999);
add_filter('pre_wp_mail', '__return_false', 999);
// Establish the real front-page query before rendering conditional WooCommerce UI.
wp();

$prefix = 'cc-theme-test-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 14);
$created_ids = [];
$visible_ids = [];
$hidden_ids = [];
$draft_ids = [];
$checks = [];
$mutations = [];
$query_probe = null;
$failure = null;
$cleanup_errors = [];
$order_before = cc_runtime_order_snapshot();
$report = ['author' => 'Dhruba Poudel', 'creator' => 'Dhruba Poudel', 'last_modified_by' => 'Dhruba Poudel', 'component' => 'Commerce Canvas', 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'scope' => 'Real local WordPress/WooCommerce; temporary fictional product fixtures; one prepared mix before the helper static cache is populated.'];

try {
    $create = static function (string $label, string $visibility, string $status, int $created_at) use ($prefix, &$created_ids): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name($prefix . ' ' . $label);
        $product->set_sku($prefix . '-' . count($created_ids));
        $product->set_status($status);
        $product->set_catalog_visibility($visibility);
        $product->set_regular_price('28.00');
        $product->set_price('28.00');
        $product->set_stock_status('instock');
        $product->set_manage_stock(false);
        $product->set_short_description('A <strong>fictional</strong> integration-test object.');
        $product->set_date_created($created_at);
        $id = $product->save();
        cc_runtime_check($id > 0, 'Could not create a temporary product fixture.');
        $created_ids[] = $id;
        return $product;
    };

    $now = time();
    $malicious_name = 'Published <img src=x onerror=alert(1)> & "quoted" <script>alert(2)</script> object';
    $malicious = $create($malicious_name, 'visible', 'publish', $now - 10);
    $visible_ids[] = $malicious->get_id();
    $catalogue_only = $create('Catalogue-only object', 'catalog', 'publish', $now - 5);
    $visible_ids[] = $catalogue_only->get_id();
    for ($i = 0; $i < 6; $i++) {
        $visible_ids[] = $create('Visible object ' . $i, 'visible', 'publish', $now - 60 - $i)->get_id();
    }
    // These are deliberately newer than the visible fixtures. Filtering after a
    // fixed 12-product window would incorrectly hide the whole real catalogue.
    for ($i = 0; $i < 15; $i++) {
        $hidden_ids[] = $create('Hidden object ' . $i, 'hidden', 'publish', $now - 1)->get_id();
    }
    for ($i = 0; $i < 2; $i++) {
        $hidden_ids[] = $create('Search-only object ' . $i, 'search', 'publish', $now - 2)->get_id();
    }
    $draft_ids[] = $create('Draft object', 'visible', 'draft', $now - 1)->get_id();

    global $wpdb;
    $tables = [$wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_order_addresses', $wpdb->prefix . 'wc_order_operational_data', $wpdb->prefix . 'wc_orders_meta', $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta'];
    $query_probe = static function (string $sql) use ($tables, &$mutations): string {
        foreach ($tables as $table) {
            $pattern = '/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+(?:INTO\s+)?|UPDATE|DELETE\s+FROM)\s+`?' . preg_quote($table, '/') . '`?(?:\s|\()/i';
            if (preg_match($pattern, $sql)) {
                $mutations[] = $table; // No SQL values or customer information are retained.
            }
        }
        return $sql;
    };
    add_filter('query', $query_probe, 999);

    $products = commerce_canvas_live_products();
    $returned_ids = array_map(static fn(WC_Product $product): int => $product->get_id(), $products);
    cc_runtime_check(count($products) === 6, 'Expected six visible products despite more than twelve newer hidden products.');
    foreach ($products as $product) {
        cc_runtime_check($product->get_status() === 'publish' && $product->is_visible(), 'The helper returned a non-published or invisible product.');
    }
    cc_runtime_check(array_intersect($returned_ids, $hidden_ids) === [], 'Hidden or search-only products entered the homepage catalogue.');
    cc_runtime_check(array_intersect($returned_ids, $draft_ids) === [], 'A draft entered the homepage catalogue.');
    cc_runtime_check(in_array($catalogue_only->get_id(), $returned_ids, true), 'The catalogue-only fixture was incorrectly excluded.');
    cc_runtime_check(in_array($malicious->get_id(), $returned_ids, true), 'The published escaping fixture did not reach the selected catalogue.');
    $checks[] = 'Six visible published products returned with fifteen newer hidden, two search-only and one draft fixture excluded; catalogue-only product retained.';

    ob_start();
    commerce_canvas_product_card(wc_get_product($malicious->get_id()), 0, false);
    $card = (string) ob_get_clean();
    $card_doc = cc_runtime_document('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $card . '</body></html>');
    $heading = $card_doc->getElementsByTagName('h3')->item(0);
    $stored_title = wc_get_product($malicious->get_id())->get_name();
    // WordPress may store an ampersand as &amp;. esc_html correctly preserves an
    // existing entity instead of double-encoding it; DOM text decodes it once.
    // Compare visible text with the ACTUAL stored title's entity decoding. Do not
    // strip tags here: the separate unsaved probe below must retain literal tags.
    $stored_visible_title = html_entity_decode($stored_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    cc_runtime_check($heading !== null && $heading->textContent === $stored_visible_title, 'The published product title did not round-trip to its expected visible text.');
    cc_runtime_check($card_doc->getElementsByTagName('script')->length === 0, 'Executable title markup entered a product card.');
    foreach ($card_doc->getElementsByTagName('*') as $element) {
        foreach ($element->attributes as $attribute) {
            cc_runtime_check(!str_starts_with(strtolower($attribute->name), 'on'), 'An event-handler attribute entered the rendered card.');
        }
    }
    $checks[] = 'A hostile title saved through WordPress is rendered as expected visible text after stored-entity normalisation, without script elements or event-handler attributes.';

    // WooCommerce/WordPress may also sanitise a stored title. An unsaved clone
    // ensures the theme's own escaping boundary sees the full hostile string.
    $unsaved = clone wc_get_product($malicious->get_id());
    $unsaved->set_name($malicious_name);
    ob_start();
    commerce_canvas_product_card($unsaved, 0, false);
    $hostile_card = (string) ob_get_clean();
    $hostile_doc = cc_runtime_document('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $hostile_card . '</body></html>');
    cc_runtime_check($hostile_doc->getElementsByTagName('h3')->item(0)?->textContent === $malicious_name, 'The full in-memory hostile title was not escaped as text.');
    cc_runtime_check($hostile_doc->getElementsByTagName('script')->length === 0, 'An unsaved hostile title created a script element.');
    foreach ($hostile_doc->getElementsByTagName('*') as $element) {
        foreach ($element->attributes as $attribute) {
            cc_runtime_check(!str_starts_with(strtolower($attribute->name), 'on'), 'An unsaved hostile title created an event-handler attribute.');
        }
    }
    $checks[] = 'The helper itself escapes an unsaved full hostile-title probe; that probe is never saved.';

    ob_start();
    require get_template_directory() . '/front-page.php';
    $front = (string) ob_get_clean();
    $front_doc = cc_runtime_document($front);
    $xpath = new DOMXPath($front_doc);
    cc_runtime_check($xpath->query('//*[@id="main"]')->length === 1, 'The actual homepage main landmark is missing or duplicated.');
    cc_runtime_check($xpath->query('//*[@id="canvas-navigation"]')->length === 1, 'The actual homepage navigation is missing or duplicated.');
    cc_runtime_check($xpath->query('//*[@aria-controls="canvas-navigation"]')->length === 1, 'The actual menu toggle is not linked to its navigation.');
    cc_runtime_check($xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " cc-product-card ")]')->length === 6, 'The actual homepage did not render six WooCommerce product cards.');
    cc_runtime_check(str_contains($front, 'Published WooCommerce catalogue'), 'The actual homepage selected fictional fallback rather than the published catalogue.');
    cc_runtime_check(str_contains($front, get_stylesheet_uri()), 'The actual homepage did not include the theme stylesheet.');
    cc_runtime_check(str_contains($front, get_template_directory_uri() . '/assets/main.js'), 'The actual footer did not include the theme interaction script.');
    cc_runtime_check(wp_style_is('commerce-canvas', 'enqueued') && wp_script_is('commerce-canvas', 'enqueued'), 'The theme asset handles were not enqueued.');
    $checks[] = 'The real front-page template renders the WooCommerce catalogue, one main landmark, linked menu controls, the local stylesheet and the local interaction script.';

    cc_runtime_check($mutations === [], 'The theme read/render phase attempted an order/post mutation.');
    $checks[] = 'The theme read/render phase produced zero mutations to order tables, posts or post metadata.';
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($query_probe !== null) {
        remove_filter('query', $query_probe, 999);
    }
    foreach (array_reverse($created_ids) as $id) {
        try {
            $product = wc_get_product($id);
            if ($product !== false) {
                cc_runtime_check(str_starts_with($product->get_sku(), $prefix . '-'), 'A cleanup candidate no longer matches the owned fixture prefix.');
                $product->delete(true);
            }
        } catch (Throwable $cleanup_error) {
            $cleanup_errors[] = $cleanup_error->getMessage();
        }
    }
    remove_filter('pre_http_request', $block_network, 999);
    remove_filter('pre_wp_mail', '__return_false', 999);
}

$order_after = cc_runtime_order_snapshot();
if ($order_after !== $order_before && $failure === null) {
    $failure = new RuntimeException('The order snapshot changed during the integration run.');
}
if ($cleanup_errors !== [] && $failure === null) {
    $failure = new RuntimeException('Temporary fixture cleanup did not complete.');
}
if ($order_after === $order_before) {
    $checks[] = 'Order data fingerprint and count are unchanged after the run and fixture cleanup.';
}
$report['checks_passed'] = $checks;
$report['temporary_products_created'] = count($created_ids);
$report['temporary_product_cleanup_errors'] = $cleanup_errors;
$report['theme_order_or_post_mutations'] = count($mutations);
$report['order_snapshot_unchanged'] = $order_after === $order_before;
$report['existing_orders_observed'] = $order_before['count'];
$report['status'] = $failure === null ? 'PASSED_REAL_WORDPRESS_WOOCOMMERCE_THEME_CHECKS' : 'FAILED';
if ($failure !== null) {
    $report['error'] = $failure->getMessage();
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failure === null ? 0 : 1);
