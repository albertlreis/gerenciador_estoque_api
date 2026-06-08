<?php

namespace App\Support\Auditoria;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class AuditoriaDiff
{
    /**
     * @param array<int,string> $fields
     * @return array<int,array{campo:string,old:mixed,new:mixed,value_type?:string}>
     */
    public static function modelChanges(Model|array|null $before, Model|array|null $after, array $fields): array
    {
        return self::changes(
            self::snapshot($before, $fields),
            self::snapshot($after, $fields)
        );
    }

    /**
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<int,array{campo:string,old:mixed,new:mixed,value_type?:string}>
     */
    public static function changes(array $old, array $new): array
    {
        $changes = [];
        $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));

        foreach ($keys as $key) {
            $oldValue = self::normalize($old[$key] ?? null);
            $newValue = self::normalize($new[$key] ?? null);

            if ($oldValue === $newValue && !AuditoriaRedactor::isSecretKey((string) $key)) {
                continue;
            }

            $changes[] = [
                'campo' => (string) $key,
                'old' => $oldValue,
                'new' => $newValue,
                'value_type' => self::typeFor($newValue),
            ];
        }

        return $changes;
    }

    /**
     * @param array<int,int|string> $old
     * @param array<int,int|string> $new
     * @return array<int,array{campo:string,old:mixed,new:mixed,value_type:string}>
     */
    public static function listChange(string $field, array $old, array $new): array
    {
        $old = self::normalizeList($old);
        $new = self::normalizeList($new);

        if ($old === $new) {
            return [];
        }

        return [[
            'campo' => $field,
            'old' => $old,
            'new' => $new,
            'value_type' => 'json',
        ]];
    }

    /**
     * @param array<int,string> $fields
     * @return array<string,mixed>
     */
    private static function snapshot(Model|array|null $source, array $fields): array
    {
        $snapshot = [];

        foreach ($fields as $field) {
            $value = $source instanceof Model
                ? $source->getAttribute($field)
                : ($source[$field] ?? null);

            $snapshot[$field] = self::normalize($value);
        }

        return $snapshot;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return AuditoriaRedactor::redact($value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return AuditoriaRedactor::redact($value);
    }

    /**
     * @param array<int,int|string> $items
     * @return array<int,string>
     */
    private static function normalizeList(array $items): array
    {
        $list = array_map(fn ($item) => (string) $item, $items);
        $list = array_values(array_unique(array_filter($list, fn ($item) => $item !== '')));
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    private static function typeFor(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_array($value) => 'json',
            $value === null => 'null',
            default => 'string',
        };
    }
}
