<?php

namespace App\Support\Numbers;

final class DecimalNumberParser
{
    public static function toFloat(?string $value, float $default = 0.0): float
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return $default;
        }

        return (float) $normalized;
    }

    public static function normalize(?string $value, ?float $fallback = null): ?string
    {
        if ($value === null || trim($value) === '') {
            return $fallback === null ? null : self::format($fallback);
        }

        $raw = trim($value);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $raw) === 1) {
            return $raw;
        }

        $number = preg_replace('/[^\d,.\-]/', '', preg_replace('/\s+/', '', $raw) ?? '');
        if ($number === null || $number === '' || $number === '-') {
            return $fallback === null ? null : self::format($fallback);
        }

        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $number = str_replace('.', '', $number);
                $number = str_replace(',', '.', $number);
            } else {
                $number = str_replace(',', '', $number);
            }
        } elseif ($lastComma !== false) {
            $number = str_replace(',', '.', $number);
        } elseif (substr_count($number, '.') > 1) {
            $number = str_replace('.', '', $number);
        }

        if (!is_numeric($number)) {
            return $fallback === null ? null : self::format($fallback);
        }

        return self::format((float) $number);
    }

    private static function format(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
