<?php

namespace App\Services;

class MarketVolumeFreshness
{
    private const FUTURE_TOLERANCE_SECONDS = 300;

    /**
     * A blank, zero, negative, signed, exponent-form, or malformed value means
     * that volume filtering is disabled. Returning a decimal string keeps
     * comparisons exact even beyond PHP/JavaScript's 2^53 integer boundary.
     */
    public function threshold($value)
    {
        $threshold = $this->decimal($value);
        if ($threshold === null || $this->compareDecimals($threshold, '0') <= 0) {
            return null;
        }

        list($integer, $fraction) = $this->decimalParts($threshold);
        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    /** Validate the compact Go extreme-market contract: v/vu. */
    public function extreme(array $payload, $nowMs = null)
    {
        $volume = $this->decimal($this->value($payload, ['v', 'volume_24h_usdt']));
        $updatedAt = $this->milliseconds($this->value($payload, ['vu', 'volume_updated_at_ms']));

        if ($volume === null || !$this->isFresh($updatedAt, $nowMs)) {
            return $this->unavailableExtreme();
        }

        return [
            'volume_24h_usdt' => $volume,
            'volume_updated_at_ms' => $updatedAt,
            'volume_available' => true,
        ];
    }

    public function passesExtreme($volumeData, $threshold)
    {
        if ($threshold === null) {
            return true;
        }

        return !empty($volumeData['volume_available'])
            && isset($volumeData['volume_24h_usdt'])
            && $this->compareDecimals($volumeData['volume_24h_usdt'], $threshold) >= 0;
    }

    public function unavailableExtreme()
    {
        return [
            'volume_24h_usdt' => null,
            'volume_updated_at_ms' => null,
            'volume_available' => false,
        ];
    }

    private function isFresh($updatedAtMs, $nowMs)
    {
        if ($updatedAtMs === null) {
            return false;
        }

        $nowMs = $nowMs === null ? (int) floor(microtime(true) * 1000) : (int) $nowMs;
        $maxAgeMs = max(1, (int) config('market_volume.max_age_seconds', 1800)) * 1000;
        $futureToleranceMs = self::FUTURE_TOLERANCE_SECONDS * 1000;

        return $updatedAtMs > $nowMs - $maxAgeMs
            && $updatedAtMs <= $nowMs + $futureToleranceMs;
    }

    private function decimal($value)
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        if (is_float($value) && (!is_finite($value) || $value < 0)) {
            return null;
        }
        if (is_int($value) && $value < 0) {
            return null;
        }

        $decimal = (string) $value;
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $decimal) !== 1) {
            return null;
        }

        return $decimal;
    }

    private function milliseconds($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $milliseconds = (int) $value;
            return $milliseconds > 0 ? $milliseconds : null;
        }

        return null;
    }

    private function compareDecimals($left, $right)
    {
        list($leftInteger, $leftFraction) = $this->decimalParts($left);
        list($rightInteger, $rightFraction) = $this->decimalParts($right);

        $integerLengthComparison = strlen($leftInteger) <=> strlen($rightInteger);
        if ($integerLengthComparison !== 0) {
            return $integerLengthComparison;
        }
        $integerComparison = strcmp($leftInteger, $rightInteger);
        if ($integerComparison !== 0) {
            return $integerComparison < 0 ? -1 : 1;
        }

        $fractionLength = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $fractionLength, '0');
        $rightFraction = str_pad($rightFraction, $fractionLength, '0');
        $fractionComparison = strcmp($leftFraction, $rightFraction);
        if ($fractionComparison === 0) {
            return 0;
        }
        return $fractionComparison < 0 ? -1 : 1;
    }

    private function decimalParts($value)
    {
        $parts = explode('.', (string) $value, 2);
        $integer = ltrim($parts[0], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';

        return [$integer, $fraction];
    }

    private function value(array $payload, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }
}
