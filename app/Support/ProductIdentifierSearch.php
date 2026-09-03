<?php

namespace App\Support;

use InvalidArgumentException;

final class ProductIdentifierSearch
{
    private const SEPARATORS_PATTERN = '/[\s\-\/_\.]+/u';

    private const SQL_SEPARATORS_PATTERN = '[[:space:]_./-]+';

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = preg_replace(self::SEPARATORS_PATTERN, '', mb_strtolower($value));

        return $normalized !== null && $normalized !== '' ? $normalized : null;
    }

    public static function normalizedLike(?string $value): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return null;
        }

        return '%'.self::escapeLike($normalized).'%';
    }

    /**
     * Adiciona um grupo de comparacoes normalizadas sobre identificadores conhecidos.
     * Os nomes das colunas devem ser definidos pelo codigo, nunca por entrada do usuario.
     */
    public static function whereAny($query, array $columns, ?string $term, string $boolean = 'and'): void
    {
        $like = self::normalizedLike($term);
        if ($columns === []) {
            return;
        }

        if ($like === null) {
            $method = strtolower($boolean) === 'or' ? 'orWhereRaw' : 'whereRaw';
            $query->{$method}('1 = 0');

            return;
        }

        foreach ($columns as $column) {
            if (! is_string($column) || ! preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
                throw new InvalidArgumentException('Coluna invalida para busca de identificador de produto.');
            }
        }

        $method = strtolower($boolean) === 'or' ? 'orWhere' : 'where';
        $query->{$method}(function ($nested) use ($columns, $like) {
            foreach (array_values($columns) as $index => $column) {
                $whereMethod = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $expression = "REGEXP_REPLACE(LOWER(COALESCE({$column}, '')), '".
                    self::SQL_SEPARATORS_PATTERN.
                    "', '') COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '\\\\'";
                $nested->{$whereMethod}($expression, [$like]);
            }
        });
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
