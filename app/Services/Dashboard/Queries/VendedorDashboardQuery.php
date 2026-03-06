<?php

namespace App\Services\Dashboard\Queries;

use App\Enums\PedidoStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class VendedorDashboardQuery
{
    public function fetch(
        CarbonInterface $inicio,
        CarbonInterface $fim,
        int $usuarioId,
        bool $podeVisualizarTodos,
        ?int $depositoId = null
    ): array {
        $pedidosBase = $this->basePedidosQuery($inicio, $fim, $usuarioId, $podeVisualizarTodos, $depositoId);

        $vendasTotal = (float) ((clone $pedidosBase)->sum('pedidos.valor_total') ?? 0);
        $pedidosTotal = (int) ((clone $pedidosBase)->count('pedidos.id') ?? 0);
        $ticketMedio = $pedidosTotal > 0 ? round($vendasTotal / $pedidosTotal, 2) : 0.0;

        $clientesUnicos = (int) ((clone $pedidosBase)
            ->whereNotNull('pedidos.id_cliente')
            ->distinct('pedidos.id_cliente')
            ->count('pedidos.id_cliente') ?? 0);

        $pedidosEmAbertoQtd = (int) ((clone $pedidosBase)
            ->where(function (Builder $query) {
                $query->whereNull('status_atual.status')
                    ->orWhere('status_atual.status', '!=', PedidoStatus::FINALIZADO->value);
            })
            ->count('pedidos.id') ?? 0);

        $pedidosPorEtapa = $this->pedidosPorEtapa($pedidosBase);

        $ultimosPedidos = (clone $pedidosBase)
            ->leftJoin('clientes', 'clientes.id', '=', 'pedidos.id_cliente')
            ->select([
                'pedidos.id',
                'pedidos.numero_externo',
                'pedidos.data_pedido',
                'pedidos.valor_total',
                'clientes.nome as cliente_nome',
                'status_atual.status as status_atual',
            ])
            ->orderByDesc('pedidos.data_pedido')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'numero_externo' => $row->numero_externo,
                'cliente_nome' => $row->cliente_nome,
                'data_pedido' => $row->data_pedido,
                'valor_total' => (float) $row->valor_total,
                'status_atual' => $row->status_atual,
            ])
            ->values()
            ->all();

        return [
            'kpis' => [
                'vendas_total' => $vendasTotal,
                'pedidos_total' => $pedidosTotal,
                'ticket_medio' => $ticketMedio,
                'clientes_unicos' => $clientesUnicos,
            ],
            'pendencias' => [
                'pedidos_em_aberto_qtd' => $pedidosEmAbertoQtd,
                'pedidos_por_etapa' => $pedidosPorEtapa,
                'ultimos_pedidos' => $ultimosPedidos,
            ],
        ];
    }

    private function basePedidosQuery(
        CarbonInterface $inicio,
        CarbonInterface $fim,
        int $usuarioId,
        bool $podeVisualizarTodos,
        ?int $depositoId
    ): Builder {
        $query = DB::table('pedidos')
            ->leftJoinSub($this->latestStatusSubquery(), 'status_atual', function ($join) {
                $join->on('status_atual.pedido_id', '=', 'pedidos.id');
            })
            ->whereBetween('pedidos.data_pedido', [$inicio->toDateTimeString(), $fim->toDateTimeString()]);

        if (!$podeVisualizarTodos) {
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

        return $query;
    }

    private function latestStatusSubquery(): Builder
    {
        $maxIdPorPedido = DB::table('pedido_status_historico')
            ->selectRaw('pedido_id, MAX(id) as max_id')
            ->groupBy('pedido_id');

        return DB::table('pedido_status_historico as psh')
            ->joinSub($maxIdPorPedido, 'latest', function ($join) {
                $join->on('latest.max_id', '=', 'psh.id');
            })
            ->select(['psh.pedido_id', 'psh.status']);
    }

    private function pedidosPorEtapa(Builder $pedidosBase): array
    {
        $statusRows = (clone $pedidosBase)
            ->selectRaw('status_atual.status as status, COUNT(*) as total')
            ->groupBy('status_atual.status')
            ->pluck('total', 'status');

        $groups = config('dashboard.status_groups', []);
        $output = [
            'criado' => 0,
            'fabrica' => 0,
            'recebimento' => 0,
            'envio_cliente' => 0,
            'consignacao' => 0,
            'finalizado' => 0,
        ];

        foreach ($groups as $etapa => $statuses) {
            $total = 0;
            foreach ($statuses as $status) {
                $total += (int) ($statusRows[$status] ?? 0);
            }
            if (array_key_exists($etapa, $output)) {
                $output[$etapa] = $total;
            }
        }

        return $output;
    }
}
