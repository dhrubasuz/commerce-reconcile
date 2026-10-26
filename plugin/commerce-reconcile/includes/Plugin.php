<?php
declare(strict_types=1);

namespace DhrubaPoudel\CommerceReconcile;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/** Author: Dhruba Poudel. WordPress UI/REST adapter, separate from the pure domain engine. */
final class Plugin
{
    public function __construct(private readonly string $pluginFile)
    {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function capability(): string
    {
        return function_exists('wc_get_order') ? 'manage_woocommerce' : 'manage_options';
    }

    public function allowed(): bool
    {
        return is_user_logged_in() && current_user_can($this->capability());
    }

    /** Nonces reduce CSRF; capability checks remain the authorisation boundary. */
    public function restPermission(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!$this->allowed()) {
            return new \WP_Error('cr_forbidden', 'You cannot access this review.', ['status' => 403]);
        }
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('cr_nonce', 'Refresh this admin page and try again.', ['status' => 403]);
        }
        return true;
    }

    public function registerRoutes(): void
    {
        register_rest_route('commerce-reconcile/v1', '/analyse', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'restAnalyse'],
            'permission_callback' => [$this, 'restPermission'],
        ]);
    }

    public function restAnalyse(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        // Defence in depth also protects direct callback use outside REST dispatch.
        $permission = $this->restPermission($request);
        if ($permission !== true) {
            return $permission;
        }
        if (strtolower(trim(explode(';', (string) $request->get_header('Content-Type'))[0])) !== 'application/json') {
            return new \WP_Error('cr_content_type', 'Use application/json.', ['status' => 415]);
        }
        try {
            $engine = new Reconciler();
            $payload = $engine->decode($request->get_body());
            $mode = $payload['mode'] ?? 'fixture';
            if (!is_string($mode) || !in_array($mode, ['fixture', 'woocommerce'], true)) {
                throw new InvalidArgumentException('Choose fixture or woocommerce mode.');
            }
            $allowed = $mode === 'fixture' ? ['mode', 'orders', 'events'] : ['mode', 'events'];
            if (array_diff(array_keys($payload), $allowed) || !isset($payload['events']) || !is_array($payload['events'])) {
                throw new InvalidArgumentException('Payload has unknown or missing fields.');
            }
            if ($mode === 'fixture') {
                if (!isset($payload['orders']) || !is_array($payload['orders'])) {
                    throw new InvalidArgumentException('Fixture mode requires an orders list.');
                }
                $orders = $payload['orders'];
            } else {
                // Validate and bound external events before touching the order data store.
                $engine->analyse([], $payload['events']);
                $ids = array_values(array_unique(array_column($payload['events'], 'order_id')));
                $orders = (new WooCommerceAdapter())->load($ids);
            }
            $report = $engine->analyse($orders, $payload['events']);
            $response = new \WP_REST_Response($report, 200);
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (JsonException|InvalidArgumentException $error) {
            return new \WP_Error('cr_invalid', $error->getMessage(), ['status' => 400]);
        } catch (RuntimeException $error) {
            return new \WP_Error('cr_unavailable', $error->getMessage(), ['status' => 409]);
        } catch (Throwable $error) {
            return new \WP_Error('cr_internal', 'Unable to prepare the review. Check the staging environment and retry.', ['status' => 500]);
        }
    }

    public function registerMenu(): void
    {
        add_management_page('Commerce Reconcile', 'Commerce Reconcile', $this->capability(), 'commerce-reconcile', [$this, 'renderAdmin']);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'tools_page_commerce-reconcile' || !$this->allowed()) {
            return;
        }
        wp_enqueue_script('commerce-reconcile-admin', plugins_url('assets/admin.js', $this->pluginFile), [], '1.0.0', true);
        wp_enqueue_style('commerce-reconcile-admin', plugins_url('assets/admin.css', $this->pluginFile), [], '1.0.0');
        wp_localize_script('commerce-reconcile-admin', 'CommerceReconcileConfig', [
            'endpoint' => esc_url_raw(rest_url('commerce-reconcile/v1/analyse')),
            'nonce' => wp_create_nonce('wp_rest'),
            'maxBytes' => Reconciler::MAX_JSON_BYTES,
        ]);
    }

    public function renderAdmin(): void
    {
        if (!$this->allowed()) {
            wp_die(esc_html__('You cannot access this review.', 'commerce-reconcile'), '', ['response' => 403]);
        }
        $fixture = json_decode((string) file_get_contents(dirname($this->pluginFile) . '/fixtures/scenario.json'), true, 20, JSON_THROW_ON_ERROR);
        $sample = ['mode' => 'fixture', 'orders' => $fixture['orders'], 'events' => $fixture['events']];
        ?>
        <div class="wrap cr-admin">
            <p class="cr-kicker"><?php echo esc_html__('Commerce Reconcile · Dhruba Poudel', 'commerce-reconcile'); ?></p>
            <h1><?php echo esc_html__('Review the differences. Keep the source intact.', 'commerce-reconcile'); ?></h1>
            <p><?php echo esc_html__('Compare a bounded external JSON snapshot with sample orders or exact WooCommerce order IDs. Reports stay in page memory. This plugin does not change orders, send payments, store credentials or call remote services.', 'commerce-reconcile'); ?></p>
            <div class="notice notice-info inline"><p><?php echo esc_html__('Start with the bundled fictional fixture. WooCommerce mode reads only the IDs in the events list, up to 100. A repeat analysis is read-only; event and sequence conflicts remain visible.', 'commerce-reconcile'); ?></p></div>
            <label for="cr-payload"><strong><?php echo esc_html__('Review payload (JSON, maximum 256 KiB)', 'commerce-reconcile'); ?></strong></label>
            <textarea id="cr-payload" rows="12" class="large-text code" spellcheck="false"><?php echo esc_textarea(wp_json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
            <p><button id="cr-analyse" type="button" class="button button-primary"><?php echo esc_html__('Analyse snapshot', 'commerce-reconcile'); ?></button> <button id="cr-reset" type="button" class="button"><?php echo esc_html__('Restore sample', 'commerce-reconcile'); ?></button></p>
            <p id="cr-status" role="status" aria-live="polite"></p>
            <section id="cr-results" hidden aria-labelledby="cr-results-title"><h2 id="cr-results-title"><?php echo esc_html__('Reconciliation report', 'commerce-reconcile'); ?></h2><div id="cr-summary"></div><p id="cr-hash" class="code"></p><div class="cr-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">Event</th><th scope="col">Order</th><th scope="col">Disposition</th><th scope="col">Reason</th></tr></thead><tbody id="cr-rows"></tbody></table></div></section>
        </div>
        <?php
    }
}
