<?php

namespace App\Services\Dashboard\Queries;

use App\Enums\PedidoStatus;
use App\Services\PedidoStatusFluxoService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminDashboardQuery
{
    /**
     * @param array<int> $categoriasTempoEstoqueOcultasIds
     */
    public function fetch(
        CarbonInterface $inicio,
        CarbonInterface $fim,
        ?int $depositoId = null,
        array $categoriasTempoEstoqueOcultasIds = []
    ): array
    {
        $pedidosBase = $this->basePedidosQuery($inicio, $fim, $depositoId);

        $vendasTotal = (float) ((clone $pedidosBase)->sum('pedidos.valor_total') ?? 0);
        $pedidosTotal = (int) ((clone $pedidosBase)->count('pedidos.id') ?? 0);
        $ticketMedio = $pedidosTotal > 0 ? round($vendasTotal / $pedidosTotal, 2) : 0.0;

        $clientesUnicos = (int) ((clone $pedidosBase)
            ->whereNotNull('pedidos.id_cliente')
            ->distinct('pedidos.id_cliente')
            ->count('pedidos.id_cliente') ?? 0);

        $pedidosOperacionais = $this->pedidosOperacionais($pedidosBase);
        $pedidosEmAbertoQtd = count($pedidosOperacionais);
        $pedidosFinalizadosQtd = $this->pedidosFinalizadosQtd($pedidosBase);
        $pedidosResumo = $this->pedidosResumo($pedidosOperacionais, $pedidosFinalizadosQtd);
        $pedidosPorEtapa = $this->pedidosPorEtapa($pedidosBase);

        $itensEntregaPendenteQtd = $this->itensEntregaPendenteQtd($depositoId);
        $consignacoesVencendoQtd = $this->consignacoesVencendoQtd($depositoId);
        $ultimosPedidos = $this->ultimosPedidos($pedidosBase);
        $tempoEstoqueCompleto = $this->produtosPorTempoEmEstoque($depositoId, $categoriasTempoEstoqueOcultasIds);
        $tempoEstoque = array_slice($tempoEstoqueCompleto, 0, 12);
        $tempoEstoqueResumo = $this->tempoEstoqueResumo($tempoEstoqueCompleto);

        return [
            'kpis' => [
                'vendas_total' => $vendasTotal,
                'pedidos_total' => $pedidosTotal,
                'ticket_medio' => $ticketMedio,
                'clientes_unicos' => $clientesUnicos,
            ],
            'pedidos_resumo' => $pedidosResumo,
            'pedidos_prioritarios' => array_slice($pedidosOperacionais, 0, 12),
            'tempo_estoque_resumo' => $tempoEstoqueResumo,
            'tempo_estoque' => $tempoEstoque,
            'pendencias' => [
                'itens_entrega_pendente_qtd' => $itensEntregaPendenteQtd,
                'consignacoes_vencendo_qtd' => $consignacoesVencendoQtd,
                'pedidos_em_aberto_qtd' => $pedidosEmAbertoQtd,
                'pedidos_por_etapa' => $pedidosPorEtapa,
                'ultimos_pedidos' => $ultimosPedidos,
                'tempo_estoque' => $tempoEstoque,
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
            ->select(['psh.pedido_id', 'psh.status', 'psh.data_status']);
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

    private function ultimosPedidos(Builder $pedidosBase): array
    {
        return (clone $pedidosBase)
            ->leftJoin('clientes', 'clientes.id', '=', 'pedidos.id_cliente')
            ->select([
                'pedidos.id',
                'pedidos.numero_externo',
                'pedidos.valor_total',
                'pedidos.data_pedido',
                'clientes.nome as cliente_nome',
                'status_atual.status',
            ])
            ->orderByDesc('pedidos.data_pedido')
            ->limit(8)
            ->get()
            ->map(fn ($pedido) => [
                'id' => (int) $pedido->id,
                'numero' => $pedido->numero_externo ?: ('#' . $pedido->id),
                'cliente' => $pedido->cliente_nome ?: 'Cliente não informado',
                'valor_total' => (float) ($pedido->valor_total ?? 0),
                'status' => $pedido->status ?: 'sem_status',
                'data_pedido' => $pedido->data_pedido,
            ])
            ->values()
            ->all();
    }

    private function pedidosFinalizadosQtd(Builder $pedidosBase): int
    {
        return (int) ((clone $pedidosBase)
            ->where('status_atual.status', PedidoStatus::FINALIZADO->value)
            ->count('pedidos.id') ?? 0);
    }

    private function pedidosOperacionais(Builder $pedidosBase): array
    {
        $pedidos = (clone $pedidosBase)
            ->leftJoin('clientes', 'clientes.id', '=', 'pedidos.id_cliente')
            ->select([
                'pedidos.id',
                'pedidos.numero_externo',
                'pedidos.valor_total',
                'pedidos.data_pedido',
                'pedidos.data_limite_entrega',
                'clientes.nome as cliente_nome',
                'status_atual.status',
                'status_atual.data_status',
            ])
            ->where(function (Builder $query) {
                $query->whereNull('status_atual.status')
                    ->orWhereNotIn('status_atual.status', [
                        PedidoStatus::FINALIZADO->value,
                        PedidoStatus::CANCELADO->value,
                    ]);
            })
            ->get();

        if ($pedidos->isEmpty()) {
            return [];
        }

        $pedidoIds = $pedidos->pluck('id')->map(fn ($id) => (int) $id)->all();
        $historicos = $this->historicosPorPedido($pedidoIds);
        $previsoesManuais = $this->previsoesManuaisPorPedido($pedidoIds);
        $statusFluxo = app(PedidoStatusFluxoService::class);
        $hoje = now()->startOfDay();

        return $pedidos
            ->map(function ($pedido) use ($historicos, $previsoesManuais, $statusFluxo, $hoje) {
                $pedidoId = (int) $pedido->id;
                $statusAtual = $pedido->status ?: 'sem_status';
                $datasHistorico = $historicos[$pedidoId] ?? [];
                $manuais = $previsoesManuais[$pedidoId] ?? [];
                $tipoFluxo = $statusFluxo->tipoFluxoPorStatus($statusAtual);
                $fluxo = $statusFluxo->fluxoDetalhadoPorTipo($tipoFluxo);
                $proximoStatus = $this->proximoStatusPendente($fluxo, array_keys($datasHistorico));
                $previsoes = $statusFluxo->previsoesPorTipo($tipoFluxo, $datasHistorico, $manuais);
                $dataPrevista = $proximoStatus ? ($previsoes[$proximoStatus['codigo']] ?? null) : null;
                $dataPrevista = $dataPrevista ? CarbonImmutable::parse($dataPrevista)->startOfDay() : null;
                $prioridade = $this->prioridadePedido($dataPrevista, $hoje);

                return [
                    'id' => $pedidoId,
                    'numero' => $pedido->numero_externo ?: ('#' . $pedidoId),
                    'cliente' => $pedido->cliente_nome ?: 'Cliente não informado',
                    'valor_total' => (float) ($pedido->valor_total ?? 0),
                    'status' => $statusAtual,
                    'status_label' => $this->statusLabel($statusAtual),
                    'proximo_status' => $proximoStatus['codigo'] ?? null,
                    'proximo_status_label' => $proximoStatus['label'] ?? null,
                    'data_pedido' => $pedido->data_pedido,
                    'data_prevista' => $dataPrevista?->toDateString(),
                    'dias_para_previsao' => $dataPrevista ? $hoje->diffInDays($dataPrevista, false) : null,
                    'prioridade' => $prioridade['key'],
                    'prioridade_label' => $prioridade['label'],
                    'prioridade_ordem' => $prioridade['ordem'],
                    'previsao_manual' => $proximoStatus ? array_key_exists($proximoStatus['codigo'], $manuais) : false,
                ];
            })
            ->sortBy([
                ['prioridade_ordem', 'asc'],
                fn ($a, $b) => strcmp($a['data_prevista'] ?? '9999-12-31', $b['data_prevista'] ?? '9999-12-31'),
                fn ($a, $b) => strcmp((string) ($a['data_pedido'] ?? ''), (string) ($b['data_pedido'] ?? '')),
            ])
            ->map(function (array $pedido) {
                unset($pedido['prioridade_ordem']);
                return $pedido;
            })
            ->values()
            ->all();
    }

    private function pedidosResumo(array $pedidosOperacionais, int $pedidosFinalizadosQtd): array
    {
        $porPrioridade = collect($pedidosOperacionais)->countBy('prioridade');

        return [
            'abertos' => count($pedidosOperacionais),
            'atrasados' => (int) ($porPrioridade['atrasado'] ?? 0),
            'vencem_hoje' => (int) ($porPrioridade['vence_hoje'] ?? 0),
            'vencem_7_dias' => (int) (($porPrioridade['vence_hoje'] ?? 0) + ($porPrioridade['vence_7_dias'] ?? 0)),
            'sem_previsao' => (int) ($porPrioridade['sem_previsao'] ?? 0),
            'finalizados_periodo' => $pedidosFinalizadosQtd,
        ];
    }

    private function historicosPorPedido(array $pedidoIds): array
    {
        return DB::table('pedido_status_historico')
            ->whereIn('pedido_id', $pedidoIds)
            ->orderBy('data_status')
            ->get(['pedido_id', 'status', 'data_status'])
            ->groupBy('pedido_id')
            ->map(fn ($rows) => $rows
                ->mapWithKeys(fn ($row) => [(string) $row->status => $row->data_status])
                ->all())
            ->all();
    }

    private function previsoesManuaisPorPedido(array $pedidoIds): array
    {
        return DB::table('pedido_status_previsoes')
            ->whereIn('pedido_id', $pedidoIds)
            ->whereNotNull('data_prevista')
            ->get(['pedido_id', 'status', 'data_prevista'])
            ->groupBy('pedido_id')
            ->map(fn ($rows) => $rows
                ->mapWithKeys(fn ($row) => [(string) $row->status => $row->data_prevista])
                ->all())
            ->all();
    }

    private function fluxoPedido(?string $statusAtual): array
    {
        $statusFluxo = app(PedidoStatusFluxoService::class);

        return $statusFluxo
            ->fluxoDetalhadoPorTipo($statusFluxo->tipoFluxoPorStatus($statusAtual))
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $fluxo
     * @param string[] $statusRegistrados
     */
    private function proximoStatusPendente(iterable $fluxo, array $statusRegistrados): ?array
    {
        foreach ($fluxo as $status) {
            if (!in_array($status['codigo'], $statusRegistrados, true)) {
                return $status;
            }
        }

        return null;
    }

    private function prioridadePedido(?CarbonImmutable $dataPrevista, CarbonInterface $hoje): array
    {
        if (!$dataPrevista) {
            return ['key' => 'sem_previsao', 'label' => 'Sem previsão', 'ordem' => 3];
        }

        $dias = $hoje->diffInDays($dataPrevista, false);

        if ($dias < 0) {
            return ['key' => 'atrasado', 'label' => 'Atrasado', 'ordem' => 0];
        }

        if ($dias === 0) {
            return ['key' => 'vence_hoje', 'label' => 'Vence hoje', 'ordem' => 1];
        }

        if ($dias <= 7) {
            return ['key' => 'vence_7_dias', 'label' => 'Próximos 7 dias', 'ordem' => 2];
        }

        return ['key' => 'normal', 'label' => 'No prazo', 'ordem' => 4];
    }

    private function statusLabel(?string $status): string
    {
        return app(PedidoStatusFluxoService::class)->statusMeta($status)['label']
            ?? ucfirst(str_replace('_', ' ', (string) ($status ?: 'sem_status')));
    }

    /**
     * @param array<int> $categoriasOcultasIds
     */
    private function produtosPorTempoEmEstoque(?int $depositoId, array $categoriasOcultasIds = []): array
    {
        $query = DB::table('estoque')
            ->join('produto_variacoes', 'produto_variacoes.id', '=', 'estoque.id_variacao')
            ->join('produtos', 'produtos.id', '=', 'produto_variacoes.produto_id')
            ->leftJoin('depositos', 'depositos.id', '=', 'estoque.id_deposito')
            ->where('estoque.quantidade', '>', 0)
            ->whereNotNull('estoque.data_entrada_estoque_atual')
            ->selectRaw('
                produto_variacoes.id as variacao_id,
                produtos.nome as produto_nome,
                produto_variacoes.referencia,
                produto_variacoes.sku_interno,
                produto_variacoes.acabamento_oficial,
                SUM(estoque.quantidade) as quantidade_total,
                MIN(estoque.data_entrada_estoque_atual) as data_entrada_mais_antiga,
                GROUP_CONCAT(DISTINCT depositos.nome ORDER BY depositos.nome SEPARATOR ", ") as depositos
            ')
            ->groupBy(
                'produto_variacoes.id',
                'produtos.nome',
                'produto_variacoes.referencia',
                'produto_variacoes.sku_interno',
                'produto_variacoes.acabamento_oficial'
            )
            ->orderBy('data_entrada_mais_antiga');

        $categoriasOcultasIds = array_values(array_unique(array_map('intval', $categoriasOcultasIds)));
        if ($categoriasOcultasIds !== []) {
            $query->whereNotIn('produtos.id_categoria', $categoriasOcultasIds);
        }

        if ($depositoId) {
            $query->where('estoque.id_deposito', $depositoId);
        }

        $hoje = now()->startOfDay();

        return $query->get()
            ->map(function ($row) use ($hoje) {
                $entrada = $row->data_entrada_mais_antiga
                    ? CarbonImmutable::parse($row->data_entrada_mais_antiga)->startOfDay()
                    : null;

                return [
                    'variacao_id' => (int) $row->variacao_id,
                    'produto_nome' => $row->produto_nome,
                    'referencia' => $row->sku_interno ?: $row->referencia,
                    'acabamento' => $row->acabamento_oficial,
                    'quantidade_total' => (int) $row->quantidade_total,
                    'data_entrada' => $entrada?->toDateString(),
                    'dias_em_estoque' => $entrada ? $entrada->diffInDays($hoje) : null,
                    'faixa' => $this->faixaTempoEstoque($entrada ? $entrada->diffInDays($hoje) : null),
                    'depositos' => $row->depositos,
                ];
            })
            ->values()
            ->all();
    }

    private function tempoEstoqueResumo(array $tempoEstoque): array
    {
        $resumo = [
            'ate_30' => ['label' => '0-30 dias', 'produtos_qtd' => 0, 'quantidade_total' => 0],
            'de_31_60' => ['label' => '31-60 dias', 'produtos_qtd' => 0, 'quantidade_total' => 0],
            'de_61_90' => ['label' => '61-90 dias', 'produtos_qtd' => 0, 'quantidade_total' => 0],
            'mais_90' => ['label' => '90+ dias', 'produtos_qtd' => 0, 'quantidade_total' => 0],
        ];

        foreach ($tempoEstoque as $item) {
            $faixa = $item['faixa'] ?? 'ate_30';
            if (!array_key_exists($faixa, $resumo)) {
                $faixa = 'ate_30';
            }

            $resumo[$faixa]['produtos_qtd']++;
            $resumo[$faixa]['quantidade_total'] += (int) ($item['quantidade_total'] ?? 0);
        }

        return $resumo;
    }

    private function faixaTempoEstoque(?int $dias): string
    {
        if ($dias === null || $dias <= 30) {
            return 'ate_30';
        }

        if ($dias <= 60) {
            return 'de_31_60';
        }

        if ($dias <= 90) {
            return 'de_61_90';
        }

        return 'mais_90';
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
