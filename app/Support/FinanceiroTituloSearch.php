<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FinanceiroTituloSearch
{
    public static function applyContaPagar(Builder $query, ?string $term): void
    {
        $valorLiquido = '(contas_pagar.valor_bruto - contas_pagar.desconto + contas_pagar.juros + contas_pagar.multa)';
        $valorPago = '(SELECT COALESCE(SUM(cpp.valor), 0) FROM contas_pagar_pagamentos cpp WHERE cpp.conta_pagar_id = contas_pagar.id)';
        $saldoAberto = "(CASE WHEN ({$valorLiquido} - {$valorPago}) < 0 THEN 0 ELSE ({$valorLiquido} - {$valorPago}) END)";

        self::apply(
            $query,
            $term,
            ['descricao', 'numero_documento', 'observacoes', 'forma_pagamento', 'status'],
            [
                'fornecedor' => ['nome', 'cnpj'],
                'categoria' => ['nome'],
                'centroCusto' => ['nome'],
                'pagamentos.contaFinanceira' => ['nome'],
                'parcelamento' => ['descricao', 'numero_documento'],
                'recorrencia' => ['descricao', 'numero_documento', 'tipo', 'frequencia', 'status', 'observacoes'],
            ],
            [$valorLiquido, $saldoAberto],
        );
    }

    public static function applyContaReceber(Builder $query, ?string $term): void
    {
        $valorLiquido = '(contas_receber.valor_bruto - contas_receber.desconto + contas_receber.juros + contas_receber.multa)';
        $valorRecebido = '(SELECT COALESCE(SUM(crp.valor), 0) FROM contas_receber_pagamentos crp WHERE crp.conta_receber_id = contas_receber.id)';
        $saldoAberto = "(CASE WHEN ({$valorLiquido} - {$valorRecebido}) < 0 THEN 0 ELSE ({$valorLiquido} - {$valorRecebido}) END)";

        self::apply(
            $query,
            $term,
            ['descricao', 'numero_documento', 'observacoes', 'forma_recebimento', 'status'],
            [
                'cliente' => ['nome', 'nome_fantasia', 'documento'],
                'pedido' => ['numero_externo'],
                'pedido.cliente' => ['nome', 'nome_fantasia', 'documento'],
                'categoria' => ['nome'],
                'centroCusto' => ['nome'],
                'pagamentos.contaFinanceira' => ['nome'],
                'parcelamento' => ['descricao', 'numero_documento'],
                'recorrencia' => ['descricao', 'numero_documento', 'tipo', 'frequencia', 'status', 'observacoes'],
            ],
            [$valorLiquido, $saldoAberto],
        );
    }

    public static function apply(
        Builder $query,
        ?string $term,
        array $columns,
        array $relations = [],
        array $moneyExpressions = [],
    ): void
    {
        $normalized = self::normalize($term);

        if ($normalized === null) {
            return;
        }

        $like = '%' . self::escapeLike($normalized) . '%';
        $moneyNeedle = self::normalizeMoneyNeedle($normalized);

        $query->where(function (Builder $where) use ($columns, $relations, $like, $moneyExpressions, $moneyNeedle): void {
            foreach ($columns as $column) {
                self::orWhereLike($where, $column, $like);
            }

            foreach ($relations as $relation => $relationColumns) {
                $where->orWhereHas($relation, function (Builder $relationQuery) use ($relationColumns, $like): void {
                    $relationQuery->where(function (Builder $relationWhere) use ($relationColumns, $like): void {
                        foreach ($relationColumns as $column) {
                            self::orWhereLike($relationWhere, $column, $like);
                        }
                    });
                });
            }

            if ($moneyNeedle !== null) {
                $moneyLike = '%' . self::escapeLike($moneyNeedle) . '%';

                foreach ($moneyExpressions as $expression) {
                    self::orWhereMoneyLike($where, $expression, $moneyLike);
                }
            }
        });
    }

    public static function normalize(?string $term): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $term) ?? '');

        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, 255, '');
    }

    public static function escapeLike(string $value, string $escapeChar = '\\'): string
    {
        return str_replace(
            [$escapeChar, '%', '_'],
            [$escapeChar . $escapeChar, $escapeChar . '%', $escapeChar . '_'],
            $value
        );
    }

    private static function normalizeMoneyNeedle(string $term): ?string
    {
        $candidate = preg_replace('/\s+/u', '', $term) ?? '';
        $candidate = preg_replace('/^R\$/iu', '', $candidate) ?? '';

        if (!preg_match('/^-?[0-9.,]+$/', $candidate) || !preg_match('/\d/', $candidate)) {
            return null;
        }

        $lastComma = strrpos($candidate, ',');
        $lastDot = strrpos($candidate, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $candidate = str_replace($thousandsSeparator, '', $candidate);
            $candidate = str_replace($decimalSeparator, '.', $candidate);
        } elseif ($lastComma !== false) {
            $candidate = self::normalizeSingleMoneySeparator($candidate, ',');
        } elseif ($lastDot !== false) {
            $candidate = self::normalizeSingleMoneySeparator($candidate, '.');
        }

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $candidate)) {
            return null;
        }

        [$integer, $decimal] = array_pad(explode('.', $candidate, 2), 2, null);
        $negative = str_starts_with($integer, '-');
        $integer = ltrim($negative ? substr($integer, 1) : $integer, '0');
        $integer = ($negative ? '-' : '') . ($integer === '' ? '0' : $integer);

        return $decimal === null ? $integer : "{$integer}.{$decimal}";
    }

    private static function normalizeSingleMoneySeparator(string $value, string $separator): string
    {
        $lastSeparator = strrpos($value, $separator);
        $digitsAfter = $lastSeparator === false ? 0 : strlen($value) - $lastSeparator - 1;

        if ($digitsAfter === 3) {
            return str_replace($separator, '', $value);
        }

        return str_replace($separator, '.', $value);
    }

    private static function orWhereLike(Builder $query, string $column, string $like): void
    {
        if ($query->getConnection()->getDriverName() !== 'mysql') {
            $query->orWhere($column, 'like', $like);
            return;
        }

        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);
        $collation = config('database.connections.mysql.collation', 'utf8mb4_0900_ai_ci');

        $query->orWhereRaw("{$wrappedColumn} COLLATE {$collation} LIKE ? ESCAPE '\\\\'", [$like]);
    }

    private static function orWhereMoneyLike(Builder $query, string $expression, string $like): void
    {
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            $query->orWhereRaw("printf('%.2f', ({$expression})) LIKE ? ESCAPE '\\'", [$like]);
            return;
        }

        if ($query->getConnection()->getDriverName() === 'mysql') {
            $query->orWhereRaw("CAST(CAST(ROUND(({$expression}), 2) AS DECIMAL(15,2)) AS CHAR) LIKE ? ESCAPE '\\\\'", [$like]);
            return;
        }

        $query->orWhereRaw("CAST(ROUND(({$expression}), 2) AS CHAR) LIKE ?", [$like]);
    }
}
