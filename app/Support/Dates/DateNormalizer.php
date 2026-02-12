<?php

namespace App\Support\Dates;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DateNormalizer
{
    /**
     * Normaliza datas em formatos comuns para um CarbonImmutable no timezone do app.
     *
     * @param ?string $value
     * @param string $fieldName
     * @return CarbonImmutable|null
     * @throws ValidationException
     */
    public static function normalizeDate(?string $value, string $fieldName = 'data'): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $tz = config('app.timezone') ?: 'UTC';

        $formats = [
            '/^\d{2}\/\d{2}\/\d{4}$/' => 'd/m/Y',
            '/^\d{2}\/\d{2}\/\d{2}$/' => 'd/m/y',
            '/^\d{2}\.\d{2}\.\d{4}$/' => 'd.m.Y',
            '/^\d{2}\.\d{2}\.\d{2}$/' => 'd.m.y',
            '/^\d{4}-\d{2}-\d{2}$/' => 'Y-m-d',
        ];

        foreach ($formats as $regex => $format) {
            if (!preg_match($regex, $raw)) {
                continue;
            }

            $dt = CarbonImmutable::createFromFormat($format, $raw, $tz);
            $errors = CarbonImmutable::getLastErrors();

            if ($dt !== false && ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $dt->startOfDay();
            }
        }

        // ISO 8601 e datas com hora
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $raw)) {
            try {
                $dt = CarbonImmutable::parse($raw, $tz);
                return $dt->setTimezone($tz);
            } catch (\Throwable) {
                // continua para erro
            }
        }

        throw ValidationException::withMessages([
            $fieldName => [
                'Data inválida. Formatos aceitos: DD/MM/AAAA, DD/MM/AA, DD.MM.AA, DD.MM.AAAA, YYYY-MM-DD ou ISO 8601.'
            ],
        ]);
    }
}
