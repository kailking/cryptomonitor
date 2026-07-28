<?php

namespace App\Support;

final class CanonicalUserId
{
    private const MAX_VALUE = 2147483647;

    public static function parse($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= self::MAX_VALUE ? $value : null;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $max = (string) self::MAX_VALUE;
        if (
            strlen($value) > strlen($max)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)
        ) {
            return null;
        }

        return (int) $value;
    }
}
