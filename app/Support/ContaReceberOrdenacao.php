<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ContaReceberOrdenacao
{
    public const CAMPOS = [
        'id',
        'pedido',
        'cliente',
        'descricao',
        'data_vencimento',
        'valor_liquido',
        'saldo_aberto',
        'status',
    ];

    public const DIRECOES = ['asc', 'desc'];

    public static function padrao(): array
    {
        return [
            'sort_field' => 'data_vencimento',
            'sort_direction' => 'asc',
        ];
    }

    public static function normalizar(?array $ordenacao): ?array
    {
        $field = (string) ($ordenacao['sort_field'] ?? '');
        $direction = strtolower((string) ($ordenacao['sort_direction'] ?? ''));

        if (! in_array($field, self::CAMPOS, true) || ! in_array($direction, self::DIRECOES, true)) {
            return null;
        }

        return [
            'sort_field' => $field,
            'sort_direction' => $direction,
        ];
    }

    public static function aplicar(Builder $query, array $ordenacao): Builder
    {
        $ordenacao = self::normalizar($ordenacao) ?? self::padrao();
        $field = $ordenacao['sort_field'];
        $direction = $ordenacao['sort_direction'];
        $expression = self::prepararExpressao($query, $field);

        if ($field !== 'id') {
            $query->orderByRaw("CASE WHEN {$expression} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("{$expression} {$direction}")
                ->orderBy('contas_receber.id', $direction);

            return $query;
        }

        return $query->orderBy('contas_receber.id', $direction);
    }

    private static function prepararExpressao(Builder $query, string $field): string
    {
        if ($field === 'pedido') {
            $query->leftJoin('pedidos as ordenacao_pedidos', 'ordenacao_pedidos.id', '=', 'contas_receber.pedido_id')
                ->select('contas_receber.*');

            return 'ordenacao_pedidos.numero_externo';
        }

        if ($field === 'cliente') {
            $query->leftJoin('clientes as ordenacao_clientes_diretos', 'ordenacao_clientes_diretos.id', '=', 'contas_receber.cliente_id')
                ->leftJoin('pedidos as ordenacao_pedidos_cliente', 'ordenacao_pedidos_cliente.id', '=', 'contas_receber.pedido_id')
                ->leftJoin('clientes as ordenacao_clientes_pedido', 'ordenacao_clientes_pedido.id', '=', 'ordenacao_pedidos_cliente.id_cliente')
                ->select('contas_receber.*');

            return 'COALESCE(ordenacao_clientes_diretos.nome, ordenacao_clientes_pedido.nome)';
        }

        return 'contas_receber.' . $field;
    }
}
