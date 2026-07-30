<?php

namespace App\Support\Dates;

use Carbon\CarbonImmutable;

class BirthdayDateNormalizer
{
    /**
     * Normaliza datas vindas do front/legado para YYYY-MM-DD.
     */
    public static function normalize(null|string|\DateTimeInterface $value): ?string
    {
        return self::parse($value)?->toDateString();
    }

    public static function parse(null|string|\DateTimeInterface $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $raw)->startOfDay();
            } catch (\Throwable) {
            }
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
