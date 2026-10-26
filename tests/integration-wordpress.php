<?php
declare(strict_types=1);

/**
 * Author: Dhruba Poudel.
 * Real WordPress/WooCommerce integration checks for an explicitly disposable local site.
 * Load wp-load.php first, with WP_ENVIRONMENT_TYPE=local (or development), then:
 * define('COMMERCE_RECONCILE_DISPOSABLE_TEST', true);
 * require '/path/to/tests/integration-wordpress.php';
 *
 * This script creates and removes fictional local test users/orders. It must never run on
 * a business or production store. It does not activate plugins or call payment providers.
 */

if (!defined('COMMERCE_RECONCILE_DISPOSABLE_TEST') || COMMERCE_RECONCILE_DISPOSABLE_TEST !== true) {
    throw new RuntimeException('Set COMMERCE_RECONCILE_DISPOSABLE_TEST=true only in an isolated disposable local test site.');
}
if (!defined('ABSPATH') || !function_exists('wp_get_environment_type')) {
    throw new RuntimeException('Load the disposable WordPress wp-load.php before this test.');
}
if (!in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    throw new RuntimeException('Integration checks require WP_ENVIRONMENT_TYPE=local or development.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if (!is_plugin_active('commerce-reconcile/commerce-reconcile.php')) {
    throw new RuntimeException('Activate Commerce Reconcile in the disposable site before running integration checks.');
}

$crPluginDirectory = WP_PLUGIN_DIR . '/commerce-reconcile';
$crFixture = json_decode((string) file_get_contents($crPluginDirectory . '/fixtures/scenario.json'), true, 20, JSON_THROW_ON_ERROR);
$crOriginalUser = get_current_user_id();
$crUsers = [];
$crOrders = [];
$crResults = [];
$crMailFilter = static fn (mixed $return, array $attributes): bool => true;
add_filter('pre_wp_mail', $crMailFilter, 10, 2);

function cr_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cr_integration_check(string $name, callable $check): void
{
    global $crResults;
    try {
        $check();
        $crResults[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $error) {
        $crResults[] = ['name' => $name, 'status' => 'FAIL', 'message' => $error->getMessage()];
    }
}

function cr_integration_request(array $payload, ?string $nonce = null): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/commerce-reconcile/v1/analyse');
    $request->set_header('Content-Type', 'application/json');
    if ($nonce !== null) {
        $request->set_header('X-WP-Nonce', $nonce);
    }
    $request->set_body(wp_json_encode($payload));
    return rest_do_request($request);
}

function cr_integration_user(string $role): int
{
    global $crUsers;
    $suffix = bin2hex(random_bytes(6));
    $id = wp_insert_user([
        'user_login' => 'cr-test-' . $suffix,
        'user_pass' => wp_generate_password(40, true, true),
        'user_email' => 'cr-' . $suffix . '@example.test',
        'display_name' => 'Fictional Commerce Reconcile ' . $role,
        'role' => $role,
    ]);
    if (is_wp_error($id)) {
        throw new RuntimeException('Unable to prepare a disposable test user.');
    }
    $crUsers[] = $id;
    return $id;
}

$crCleanup = ['users' => 'NOT_RUN', 'orders' => 'NOT_RUN'];
try {
    $crAdmin = cr_integration_user('administrator');
    $crSubscriber = cr_integration_user('subscriber');
    $crSamplePayload = ['mode' => 'fixture', 'orders' => $crFixture['orders'], 'events' => $crFixture['events']];

    cr_integration_check('the plugin registers a real POST REST route with its permission callback', static function (): void {
        $routes = rest_get_server()->get_routes();
        cr_integration_assert(isset($routes['/commerce-reconcile/v1/analyse']), 'Expected review route is not registered.');
        $handlers = $routes['/commerce-reconcile/v1/analyse'];
        $matching = array_values(array_filter($handlers, static fn (mixed $handler): bool => is_array($handler) && isset($handler['callback'])));
        cr_integration_assert(count($matching) === 1, 'Expected one route handler.');
        cr_integration_assert(isset($matching[0]['permission_callback']), 'Route has no permission callback.');
        cr_integration_assert(!empty($matching[0]['methods']['POST']), 'POST method is not registered.');
    });

    cr_integration_check('anonymous WordPress REST dispatch cannot review snapshots', static function () use ($crSamplePayload): void {
        wp_set_current_user(0);
        cr_integration_assert(cr_integration_request($crSamplePayload)->get_status() === 403, 'Anonymous dispatch was not rejected.');
    });

    cr_integration_check('a real subscriber nonce does not substitute for the required capability', static function () use ($crSubscriber, $crSamplePayload): void {
        wp_set_current_user($crSubscriber);
        $response = cr_integration_request($crSamplePayload, wp_create_nonce('wp_rest'));
        cr_integration_assert($response->get_status() === 403, 'Subscriber access was not rejected.');
    });

    cr_integration_check('a capable administrator without a nonce is rejected', static function () use ($crAdmin, $crSamplePayload): void {
        wp_set_current_user($crAdmin);
        cr_integration_assert(cr_integration_request($crSamplePayload)->get_status() === 403, 'Missing nonce was not rejected.');
    });

    cr_integration_check('a capable administrator with an invalid nonce is rejected', static function () use ($crAdmin, $crSamplePayload): void {
        wp_set_current_user($crAdmin);
        cr_integration_assert(cr_integration_request($crSamplePayload, 'invalid-nonce')->get_status() === 403, 'Invalid nonce was not rejected.');
    });

    cr_integration_check('actual WordPress admin REST returns the reviewed baseline report', static function () use ($crAdmin, $crSamplePayload): void {
        wp_set_current_user($crAdmin);
        $response = cr_integration_request($crSamplePayload, wp_create_nonce('wp_rest'));
        cr_integration_assert($response->get_status() === 200, 'Valid administrator request did not succeed.');
        $data = $response->get_data();
        cr_integration_assert($data['summary'] === ['orders' => 9, 'events' => 14, 'matched' => 3, 'exceptions' => 9, 'ignored' => 2], 'Baseline counts changed.');
        cr_integration_assert($data['report_hash'] === 'f778c64d0541de0819a75943446aaa1a3de779eec274df32c1b09e70482879b9', 'Baseline evidence hash changed.');
        cr_integration_assert($response->get_headers()['Cache-Control'] === 'no-store, private', 'Response is missing its no-store header.');
    });

    cr_integration_check('actual WordPress dispatch reproduces the same report on retry', static function () use ($crAdmin, $crSamplePayload): void {
        wp_set_current_user($crAdmin);
        $nonce = wp_create_nonce('wp_rest');
        $first = cr_integration_request($crSamplePayload, $nonce);
        $second = cr_integration_request($crSamplePayload, $nonce);
        cr_integration_assert($first->get_status() === 200 && $second->get_status() === 200, 'A repeat review failed.');
        cr_integration_assert($first->get_data() === $second->get_data(), 'A repeat read-only review changed its output.');
    });

    cr_integration_check('WordPress dispatch rejects an arbitrary remote-URL field', static function () use ($crAdmin, $crSamplePayload): void {
        wp_set_current_user($crAdmin);
        $payload = $crSamplePayload;
        $payload['url'] = 'https://example.test/never-called';
        cr_integration_assert(cr_integration_request($payload, wp_create_nonce('wp_rest'))->get_status() === 400, 'Unexpected URL field was accepted.');
    });

    cr_integration_check('actual WordPress dispatch rejects JSON objects where order/event lists are required', static function () use ($crAdmin): void {
        wp_set_current_user($crAdmin);
        foreach ([['mode' => 'fixture', 'orders' => (object) [], 'events' => []], ['mode' => 'fixture', 'orders' => [], 'events' => (object) []], ['mode' => 'woocommerce', 'events' => (object) []]] as $payload) {
            cr_integration_assert(cr_integration_request($payload, wp_create_nonce('wp_rest'))->get_status() === 400, 'Object-shaped order/event container was accepted.');
        }
    });

    cr_integration_check('WordPress admin markup contains the sample and accessible output boundary', static function () use ($crAdmin, $crPluginDirectory): void {
        wp_set_current_user($crAdmin);
        $plugin = new DhrubaPoudel\CommerceReconcile\Plugin($crPluginDirectory . '/commerce-reconcile.php');
        ob_start();
        try {
            $plugin->renderAdmin();
            $markup = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        cr_integration_assert(str_contains($markup, 'id="cr-payload"'), 'Sample payload editor is missing.');
        cr_integration_assert(str_contains($markup, 'aria-live="polite"'), 'Status live region is missing.');
        cr_integration_assert(str_contains($markup, 'id="cr-results" hidden'), 'Results are not initially hidden.');
        cr_integration_assert(str_contains($markup, 'DEMO-8101'), 'Bundled fictional sample is missing.');
    });

    $crWooActive = function_exists('wc_get_order') && class_exists('WC_Order') && defined('WC_VERSION');
    if (!$crWooActive) {
        $crResults[] = ['name' => 'real WooCommerce order-adapter checks', 'status' => 'SKIP', 'message' => 'WooCommerce is not active in this test site.'];
        if (defined('COMMERCE_RECONCILE_REQUIRE_WOOCOMMERCE') && COMMERCE_RECONCILE_REQUIRE_WOOCOMMERCE === true) {
            $crResults[] = ['name' => 'required WooCommerce availability', 'status' => 'FAIL', 'message' => 'This run required WooCommerce.'];
        }
    } else {
        // Fixture setup only. These local fictional writes are outside the read-only review interval.
        wp_set_current_user($crAdmin);
        foreach ([['129.90', 'processing'], ['49.90', 'completed']] as [$amount, $status]) {
            $order = new WC_Order();
            $order->set_currency('AUD');
            $order->set_status($status);
            $order->set_total($amount);
            $order->set_customer_id(0);
            $order->save();
            $crOrders[] = $order->get_id();
        }

        cr_integration_check('real WooCommerce adapter compares exact fictional IDs without saving orders', static function () use ($crAdmin, $crOrders): void {
            wp_set_current_user($crAdmin);
            $before = [];
            $events = [];
            foreach ($crOrders as $position => $id) {
                $order = wc_get_order($id);
                $before[$id] = $order->get_data();
                $events[] = [
                    'event_id' => 'evt-wp-' . $id,
                    'order_id' => $id,
                    'reference' => (string) $order->get_order_number(),
                    'amount_minor' => $position === 0 ? 12990 : 5990,
                    'currency' => 'AUD',
                    'status' => (string) $order->get_status(),
                    'sequence' => 1,
                    'occurred_at' => '2026-10-27T00:00:00Z',
                ];
            }
            $writes = 0;
            $countWrite = static function () use (&$writes): void { $writes++; };
            add_action('woocommerce_update_order', $countWrite);
            add_action('woocommerce_new_order', $countWrite);
            try {
                $response = cr_integration_request(['mode' => 'woocommerce', 'events' => $events], wp_create_nonce('wp_rest'));
            } finally {
                remove_action('woocommerce_update_order', $countWrite);
                remove_action('woocommerce_new_order', $countWrite);
            }
            cr_integration_assert($response->get_status() === 200, 'WooCommerce review failed.');
            $data = $response->get_data();
            cr_integration_assert($data['summary']['orders'] === 2, 'Exact fixture orders were not loaded.');
            cr_integration_assert($data['summary']['matched'] === 1 && $data['summary']['exceptions'] === 1, 'Expected amount mismatch was not reported.');
            cr_integration_assert($data['rows'][1]['issues'] === ['amount_mismatch'], 'Amount mismatch reason changed.');
            cr_integration_assert($writes === 0, 'An order write hook fired during read-only review.');
            foreach ($crOrders as $id) {
                cr_integration_assert(wc_get_order($id)->get_data() == $before[$id], 'A fixture source order changed during review.');
            }
        });

        cr_integration_check('real WooCommerce path rejects caller-supplied order overrides', static function () use ($crAdmin): void {
            wp_set_current_user($crAdmin);
            $response = cr_integration_request(['mode' => 'woocommerce', 'orders' => [], 'events' => []], wp_create_nonce('wp_rest'));
            cr_integration_assert($response->get_status() === 400, 'Caller orders were accepted in WooCommerce mode.');
        });

        cr_integration_check('real shop manager can review and remains subject to nonce checks', static function () use ($crSamplePayload): void {
            $manager = cr_integration_user('shop_manager');
            wp_set_current_user($manager);
            cr_integration_assert(current_user_can('manage_woocommerce'), 'Test shop manager lacks the expected WooCommerce capability.');
            cr_integration_assert(cr_integration_request($crSamplePayload)->get_status() === 403, 'Shop manager bypassed the nonce requirement.');
            cr_integration_assert(cr_integration_request($crSamplePayload, wp_create_nonce('wp_rest'))->get_status() === 200, 'Authorised shop manager could not review.');
        });
    }
} catch (Throwable $error) {
    $crResults[] = ['name' => 'integration fixture setup', 'status' => 'FAIL', 'message' => $error->getMessage()];
} finally {
    wp_set_current_user($crOriginalUser);
    try {
        foreach ($crOrders as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $order->delete(true);
            }
        }
        $crCleanup['orders'] = 'PASS';
    } catch (Throwable $error) {
        $crCleanup['orders'] = 'FAIL';
        $crResults[] = ['name' => 'disposable order cleanup', 'status' => 'FAIL', 'message' => 'Unable to remove a fictional test order. Reset the disposable test site.'];
    }
    try {
        foreach ($crUsers as $id) {
            if (!wp_delete_user($id)) {
                throw new RuntimeException('Unable to delete a test user.');
            }
        }
        $crCleanup['users'] = 'PASS';
    } catch (Throwable $error) {
        $crCleanup['users'] = 'FAIL';
        $crResults[] = ['name' => 'disposable user cleanup', 'status' => 'FAIL', 'message' => 'Unable to remove a fictional test user. Reset the disposable test site.'];
    }
    remove_filter('pre_wp_mail', $crMailFilter, 10);
}

$crFailed = count(array_filter($crResults, static fn (array $check): bool => $check['status'] === 'FAIL'));
$crPassed = count(array_filter($crResults, static fn (array $check): bool => $check['status'] === 'PASS'));
$crSkipped = count(array_filter($crResults, static fn (array $check): bool => $check['status'] === 'SKIP'));

echo wp_json_encode([
    'author' => 'Dhruba Poudel',
    'status' => $crFailed === 0 ? 'PASS' : 'FAIL',
    'runtime' => ['php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null],
    'scope' => 'Real local WordPress REST dispatch, permissions/nonces, admin markup and optional real WooCommerce CRUD reads. HTTP login/cookie flow, browser admin interaction and production compatibility are separate checks.',
    'passed' => $crPassed,
    'failed' => $crFailed,
    'skipped' => $crSkipped,
    'cleanup' => $crCleanup,
    'checks' => $crResults,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
