<?php

namespace App\Integrations\ContaAzul\Support;

class ContaAzulMoney
{
    /**
     * Normaliza valores monetarios vindos da Conta Azul para decimal em reais.
     */
    public static function parse(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $negativeByParentheses = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = preg_replace('/[^\d,.\-]+/', '', $value) ?? '';
        if ($value === '' || $value === '-') {
            return null;
        }

        $isNegative = $negativeByParentheses || str_contains($value, '-');
        $value = str_replace('-', '', $value);

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $value);
            $last = end($parts);

            if (count($parts) > 2 || strlen((string) $last) === 3) {
                $value = str_replace('.', '', $value);
            }
        }

        if (!is_numeric($value)) {
            return null;
        }

        $parsed = round((float) $value, 2);

        return $isNegative ? -abs($parsed) : $parsed;
    }

    public static function parseOrZero(mixed $value): float
    {
        return self::parse($value) ?? 0.0;
    }

    /**
     * @param array<int, string> $keys
     */
    public static function parseFromPayload(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);
            if ($value !== null && $value !== '') {
                $parsed = self::parse($value);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return null;
    }
}
