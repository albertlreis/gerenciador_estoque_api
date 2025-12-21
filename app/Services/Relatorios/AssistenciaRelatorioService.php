<?php

namespace App\Services\Relatorios;

use App\Enums\AssistenciaStatus;
use App\Models\AssistenciaChamado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssistenciaRelatorioService
{
    /**
     * Status que consideramos como "concluídos" para gerar a data de conclusão.
     * Ajuste aqui se a regra mudar (ex.: apenas ENTREGUE).
     */
    private const STATUS_FINAIS = [
        'reparo_concluido',
        'entregue',
        'cancelado',
    ];

    /**
     * Filtros aceitos:
     * - status?: string
     * - abertura_inicio?: string YYYY-MM-DD
     * - abertura_fim?: string YYYY-MM-DD
     * - conclusao_inicio?: string YYYY-MM-DD
     * - conclusao_fim?: string YYYY-MM-DD
     * - locais_reparo?: array|string (locais_reparo[])
     * - custo_resp?: string (cliente|loja)
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<string,mixed>}
     */
    public function listar(array $filtros): array
    {
        $query = AssistenciaChamado::query()
            ->with([
                'assistencia:id,nome',
                'pedido.cliente:id,nome',
                'pedido.parceiro:id,nome',
            ]);

        // Subquery de conclusão (último log em status final)
        $subConclusao = DB::table('assistencia_chamado_logs as l')
            ->selectRaw('l.chamado_id, MAX(l.created_at) as concluido_em')
            ->whereIn('l.status_para', self::STATUS_FINAIS)
            ->groupBy('l.chamado_id');

        $query->leftJoinSub($subConclusao, 'concl', function ($join) {
            $join->on('concl.chamado_id', '=', 'assistencia_chamados.id');
        });

        $query->select('assistencia_chamados.*');
        $query->addSelect(DB::raw('concl.concluido_em as concluido_em'));

        // ===== filtros =====
        if (!empty($filtros['status'])) {
            $query->where('assistencia_chamados.status', $filtros['status']);
        }

        // locais_reparo[]
        $locais = $filtros['locais_reparo'] ?? null;
        if (is_array($locais) && count($locais)) {
            $query->whereIn('assistencia_chamados.local_reparo', $locais);
        }

        // custo_resp -> custo_responsavel
        if (!empty($filtros['custo_resp'])) {
            $query->where('assistencia_chamados.custo_responsavel', $filtros['custo_resp']);
        }

        // abertura (created_at)
        if (!empty($filtros['abertura_inicio'])) {
            $query->whereDate('assistencia_chamados.created_at', '>=', Carbon::parse($filtros['abertura_inicio']));
        }
        if (!empty($filtros['abertura_fim'])) {
            $query->whereDate('assistencia_chamados.created_at', '<=', Carbon::parse($filtros['abertura_fim']));
        }

        // conclusão (concluido_em calculado)
        if (!empty($filtros['conclusao_inicio']) || !empty($filtros['conclusao_fim'])) {
            // quando filtrar conclusão, faz sentido exigir que exista data de conclusão
            $query->whereNotNull(DB::raw('concl.concluido_em'));
        }
        if (!empty($filtros['conclusao_inicio'])) {
            $query->whereDate(DB::raw('concl.concluido_em'), '>=', Carbon::parse($filtros['conclusao_inicio']));
        }
        if (!empty($filtros['conclusao_fim'])) {
            $query->whereDate(DB::raw('concl.concluido_em'), '<=', Carbon::parse($filtros['conclusao_fim']));
        }

        $query->orderByDesc('assistencia_chamados.id');

        $lista = $query->get();

        $linhas = $lista->map(function (AssistenciaChamado $c) {
            $concluidoEm = null;
            if (isset($c->concluido_em) && $c->concluido_em) {
                try {
                    $concluidoEm = Carbon::parse($c->concluido_em);
                } catch (Throwable) {
                    $concluidoEm = null;
                }
            }

            $statusVal = $c->status instanceof AssistenciaStatus ? $c->status->value : (string)($c->status ?? '');

            return [
                'id'              => $c->id,
                'numero'          => $c->numero,
                'status'          => $statusVal,
                'prioridade'      => $c->prioridade?->value ?? (string)($c->prioridade ?? null),
                'local_reparo'    => $c->local_reparo?->value ?? (string)($c->local_reparo ?? null),
                'custo_resp'      => $c->custo_responsavel?->value ?? (string)($c->custo_responsavel ?? null),

                'aberto_em'       => optional($c->created_at)->toDateString(),
                'aberto_em_br'    => optional($c->created_at)->format('d/m/Y'),
                'concluido_em'    => $concluidoEm?->toDateString(),
                'concluido_em_br' => $concluidoEm?->format('d/m/Y'),

                'sla_data_limite' => optional($c->sla_data_limite)->toDateString(),
                'assistencia'     => $c->assistencia?->nome ?? null,

                'pedido_id'     => $c->pedido_id,
                'pedido_numero' => $c->pedido?->numero_externo ?? null,
                'cliente'       => $c->pedido?->cliente?->nome ?? null,
                'fornecedor'    => $c->pedido?->parceiro?->nome ?? null,

                'observacoes'   => $c->observacoes,
            ];
        })->values()->toArray();

        $totais = [
            'total'      => count($linhas),
            'concluidos' => 0,
            'por_status' => [],
        ];

        foreach ($linhas as $ln) {
            $st = $ln['status'] ?? '—';
            $totais['por_status'][$st] = ($totais['por_status'][$st] ?? 0) + 1;
            if (!empty($ln['concluido_em'])) $totais['concluidos']++;
        }

        $totais['abertos'] = $totais['total'] - $totais['concluidos'];
        ksort($totais['por_status']);

        return [$linhas, $totais];
    }
}
