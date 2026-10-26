<?php
declare(strict_types=1);
// Author: Dhruba Poudel. Deliberately small test doubles, not a WordPress integration environment.
require_once __DIR__ . '/wp-base-stubs.php';
$GLOBALS['cr_test_order_reads'] = [];
$GLOBALS['cr_test_orders'] = [];
function wc_get_price_decimals(): int { return 2; }
function wc_get_order(int $id): object|false
{
    $GLOBALS['cr_test_order_reads'][] = $id;
    return $GLOBALS['cr_test_orders'][$id] ?? false;
}
final class ReadOnlyOrderDouble
{
    public function __construct(private array $row, private string $type = 'shop_order') {}
    public function get_type(): string { return $this->type; }
    public function get_id(): int { return $this->row['order_id']; }
    public function get_order_number(): string { return $this->row['reference']; }
    public function get_total(): string { return number_format($this->row['amount_minor'] / 100, 2, '.', ''); }
    public function get_currency(): string { return $this->row['currency']; }
    public function get_status(): string { return $this->row['status']; }
    public function __call(string $name, array $args): never { throw new RuntimeException("Unexpected mutator/accessor $name"); }
}
