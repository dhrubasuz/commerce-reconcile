<?php
declare(strict_types=1);
// Author: Dhruba Poudel. Minimal WordPress doubles; intentionally no WooCommerce functions.
class WP_Error
{
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WP_REST_Response
{
    public array $headers = [];
    public function __construct(public mixed $data, public int $status = 200) {}
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}
class WP_REST_Server { public const CREATABLE = 'POST'; }
class WP_REST_Request
{
    public function __construct(private string $body = '', private array $headers = []) {}
    public function get_body(): string { return $this->body; }
    public function get_header(string $key): string { return $this->headers[strtolower($key)] ?? ''; }
}
$GLOBALS['cr_test_user'] = true;
$GLOBALS['cr_test_caps'] = ['manage_woocommerce' => true, 'manage_options' => true];
function is_user_logged_in(): bool { return $GLOBALS['cr_test_user']; }
function current_user_can(string $cap): bool { return $GLOBALS['cr_test_caps'][$cap] ?? false; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid-nonce' && $action === 'wp_rest'; }
