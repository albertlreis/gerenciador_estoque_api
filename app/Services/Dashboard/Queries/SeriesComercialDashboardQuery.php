<?php

namespace App\Services\Dashboard\Queries;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SeriesComercialDashboardQuery
{
    public function fetch(
        CarbonInterface $inicio,
        CarbonInterface $fim,
        string $period,
        ?int $depositoId,
        ?int $usuarioId,
        bool $podeVisualizarTodos,
        bool $compare,
        ?array $periodoAnterior = null
    ): array {
        $granularity = $this->resolveGranularity($period, $inicio, $fim);

        $current = $this->querySerie($inicio, $fim, $granularity, $depositoId, $usuarioId, $podeVisualizarTodos);

        $series = [
            'granularity' => $granularity,
            'pedidos_serie' => $current['pedidos_serie'],
            'faturamento_serie' => $current['faturamento_serie'],
        ];

        if ($compare && $periodoAnterior) {
            $previous = $this->querySerie(
                $periodoAnterior['inicio'],
                $periodoAnterior['fim'],
                $granularity,
                $depositoId,
                $usuarioId,
                $podeVisualizarTodos
            );

            $series['compare'] = [
                'pedidos_serie_previous' => $previous['pedidos_serie'],
                'faturamento_serie_previous' => $previous['faturamento_serie'],
            ];
        }

        return $series;
    }

    private function querySerie(
        CarbonInterface $inicio,
        CarbonInterface $fim,
        string $granularity,
        ?int $depositoId,
        ?int $usuarioId,
        bool $podeVisualizarTodos
    ): array {
        $bucketExpr = $this->bucketExpression($granularity);

        $query = DB::table('pedidos')
            ->whereBetween('pedidos.data_pedido', [$inicio->toDateTimeString(), $fim->toDateTimeString()]);

        if ($usuarioId && !$podeVisualizarTodos) {
            $query->where('pedidos.id_usuario', $usuarioId);
        }

        if ($depositoId) {
            $query->whereExists(function ($sub) use ($depositoId) {
                $sub->selectRaw('1')
                    ->from('pedido_itens')
                    ->whereColumn('pedido_itens.id_pedido', 'pedidos.id')
                    ->where('pedido_itens.id_deposito', $depositoId);
            });
        }

        $rows = $query
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as pedidos_total, COALESCE(SUM(pedidos.valor_total), 0) as faturamento_total")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'pedidos_serie' => $rows
                ->map(fn ($row) => ['t' => $row->bucket, 'value' => (int) $row->pedidos_total])
                ->values()
                ->all(),
            'faturamento_serie' => $rows
                ->map(fn ($row) => ['t' => $row->bucket, 'value' => (float) $row->faturamento_total])
                ->values()
                ->all(),
        ];
    }

    private function resolveGranularity(string $period, CarbonInterface $inicio, CarbonInterface $fim): string
    {
        if (in_array($period, ['today', '7d', 'month'], true)) {
            return 'day';
        }

        if ($period === '6m') {
            return 'month';
        }

        $dias = $inicio->diffInDays($fim) + 1;

        if ($dias <= 45) {
            return 'day';
        }

        if ($dias <= 180) {
            return 'week';
        }

        return 'month';
    }

    private function bucketExpression(string $granularity): string
    {
        return match ($granularity) {
            'week' => "DATE_FORMAT(DATE_SUB(DATE(pedidos.data_pedido), INTERVAL WEEKDAY(pedidos.data_pedido) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT(DATE(pedidos.data_pedido), '%Y-%m-01')",
            default => "DATE_FORMAT(DATE(pedidos.data_pedido), '%Y-%m-%d')",
        };
    }
}
