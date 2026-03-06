<?php

namespace App\Services\Dashboard\Queries;

use App\Enums\PedidoStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminDashboardQuery
{
    public function fetch(CarbonInterface $inicio, CarbonInterface $fim, ?int $depositoId = null): array
    {
        $pedidosBase = $this->basePedidosQuery($inicio, $fim, $depositoId);

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

        $itensEntregaPendenteQtd = $this->itensEntregaPendenteQtd($depositoId);
        $consignacoesVencendoQtd = $this->consignacoesVencendoQtd($depositoId);

        return [
            'kpis' => [
                'vendas_total' => $vendasTotal,
                'pedidos_total' => $pedidosTotal,
                'ticket_medio' => $ticketMedio,
                'clientes_unicos' => $clientesUnicos,
            ],
            'pendencias' => [
                'itens_entrega_pendente_qtd' => $itensEntregaPendenteQtd,
                'consignacoes_vencendo_qtd' => $consignacoesVencendoQtd,
                'pedidos_em_aberto_qtd' => $pedidosEmAbertoQtd,
                'pedidos_por_etapa' => $pedidosPorEtapa,
            ],
        ];
    }

    private function basePedidosQuery(CarbonInterface $inicio, CarbonInterface $fim, ?int $depositoId): Builder
    {
        $query = DB::table('pedidos')
            ->leftJoinSub($this->latestStatusSubquery(), 'status_atual', function ($join) {
                $join->on('status_atual.pedido_id', '=', 'pedidos.id');
            })
            ->whereBetween('pedidos.data_pedido', [$inicio->toDateTimeString(), $fim->toDateTimeString()]);

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

    private function itensEntregaPendenteQtd(?int $depositoId): int
    {
        $query = DB::table('pedido_itens')
            ->where('entrega_pendente', 1)
            ->whereNull('data_liberacao_entrega');

        if ($depositoId) {
            $query->where('id_deposito', $depositoId);
        }

        return (int) ($query->count('id') ?? 0);
    }

    private function consignacoesVencendoQtd(?int $depositoId): int
    {
        $dias = (int) config('dashboard.consignacoes.dias_vencendo', 2);
        $limite = now()->addDays($dias)->toDateString();

        $query = DB::table('consignacoes')
            ->where('status', 'pendente')
            ->whereDate('prazo_resposta', '<=', $limite);

        if ($depositoId) {
            $query->where('deposito_id', $depositoId);
        }

        return (int) ($query->count('id') ?? 0);
    }
}
