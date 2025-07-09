<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Métodos auxiliares para geração de estatísticas de pedidos.
 */
trait EstatisticaPedidoTrait
{
    /**
     * Gera a chave de cache com base no escopo e intervalo.
     *
     * @param int $intervalo
     * @param bool $admin
     * @param int $usuarioId
     * @return string
     */
    protected function gerarCacheKey(int $intervalo, bool $admin, int $usuarioId): string
    {
        return $admin
            ? "estatisticas_pedidos_admin_$intervalo"
            : "estatisticas_pedidos_usuario_{$usuarioId}_$intervalo";
    }

    /**
     * Retorna uma coleção de datas (1.º dia de cada mês) a partir do intervalo solicitado.
     *
     * @param int $meses
     * @return Collection<int, \Carbon\Carbon>
     */
    protected function gerarMesesReferencia(int $meses): Collection
    {
        return collect(range(0, $meses - 1))
            ->map(fn($i) => now()->subMonths($i)->startOfMonth())
            ->reverse()
            ->values();
    }

    /**
     * Consulta agregada de pedidos mensais.
     *
     * @param \Carbon\Carbon $dataInicio
     * @param bool $admin
     * @param int $usuarioId
     * @return Collection
     */
    protected function consultarDadosAgrupados(Carbon $dataInicio, bool $admin, int $usuarioId): Collection
    {
        $query = DB::table('pedidos')
            ->selectRaw("DATE_FORMAT(data_pedido, '%Y-%m-01') as mes, COUNT(*) as total, SUM(valor_total) as valor")
            ->where('data_pedido', '>=', $dataInicio->format('Y-m-d'))
            ->whereNotNull('data_pedido');

        if (!$admin) {
            $query->where('id_usuario', $usuarioId);
        }

        return $query->groupBy('mes')->orderBy('mes')->get();
    }
}
