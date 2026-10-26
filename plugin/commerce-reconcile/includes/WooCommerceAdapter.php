<?php
declare(strict_types=1);

namespace DhrubaPoudel\CommerceReconcile;

use InvalidArgumentException;
use RuntimeException;

/** Author: Dhruba Poudel. Read only, exact IDs; no customer/contact/payment credentials. */
final class WooCommerceAdapter
{
    public const MAX_IDS = 100;

    public function load(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > self::MAX_IDS) {
            throw new InvalidArgumentException('At most 100 exact order IDs may be compared.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647) {
                throw new InvalidArgumentException('Order IDs must be positive integers.');
            }
        }
        if (!function_exists('wc_get_order') || !function_exists('wc_get_price_decimals')) {
            throw new RuntimeException('WooCommerce is not available. Fixture mode remains usable.');
        }
        $orders = [];
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $precision = (int) wc_get_price_decimals();
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order || !is_object($order) || $order->get_type() !== 'shop_order') {
                continue;
            }
            $orders[] = [
                'order_id' => (int) $order->get_id(),
                'reference' => (string) $order->get_order_number(),
                'amount_minor' => self::decimalToMinor((string) $order->get_total(), $precision),
                'currency' => (string) $order->get_currency(),
                'status' => (string) $order->get_status(),
            ];
        }
        return $orders;
    }

    /** Decimal strings only: no floating-point multiplication or silent rounding. */
    public static function decimalToMinor(string $value, int $precision): int
    {
        if ($precision < 0 || $precision > 4 || !preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,4}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Unsupported amount format or store precision.');
        }
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > $precision && trim(substr($fraction, $precision), '0') !== '') {
            throw new InvalidArgumentException('Amount precision would require rounding.');
        }
        $minor = (int) $parts[1] * (10 ** $precision) + (int) str_pad(substr($fraction, 0, $precision), $precision, '0');
        if ($minor > 100000000) {
            throw new InvalidArgumentException('Amount exceeds the supported bound.');
        }
        return $minor;
    }
}
