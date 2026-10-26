<?php
declare(strict_types=1);

namespace DhrubaPoudel\CommerceReconcile;

/** Author: Dhruba Poudel. Stable JSON for evidence hashes, not a signing format. */
final class CanonicalJson
{
    public static function encode(array $value): string
    {
        return json_encode(self::normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalise($item);
        }
        return $value;
    }
}
