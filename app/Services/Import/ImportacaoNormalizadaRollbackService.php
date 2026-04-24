<?php

namespace App\Services\Import;

use App\Enums\ImportacaoNormalizadaStatus;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\ImportacaoNormalizada;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ImportacaoNormalizadaRollbackService
{
    private const REF_TYPE_LINHA_IMPORTACAO = 'importacao_normalizada_linha';

    public function __construct(
        private readonly EstoqueMovimentacaoService $estoqueMovimentacaoService
    ) {
    }

    public function dryRun(ImportacaoNormalizada $importacao): array
    {
        return $this->montarResumo($importacao, false);
    }

    public function reverter(ImportacaoNormalizada $importacao, ?int $usuarioId = null): array
    {
        return DB::transaction(function () use ($importacao, $usuarioId): array {
            /** @var ImportacaoNormalizada $locked */
            $locked = ImportacaoNormalizada::query()->lockForUpdate()->findOrFail($importacao->id);

            $resumoAntes = $this->montarResumo($locked, true);

            if ((int) ($resumoAntes['total_chaves_com_saldo_insuficiente'] ?? 0) > 0) {
                throw new RuntimeException(
                    sprintf(
                        'A reversão da importação #%d foi bloqueada porque existem %d chaves com saldo insuficiente para estorno.',
                        $locked->id,
                        (int) $resumoAntes['total_chaves_com_saldo_insuficiente']
                    )
                );
            }

            $movimentacoesAEstornar = collect($resumoAntes['movimentacoes_a_estornar'] ?? []);
            $movimentacoesRevertidas = 0;

            foreach ($movimentacoesAEstornar->sortByDesc('id') as $movimentacao) {
                $movimentacaoId = (int) ($movimentacao['id'] ?? 0);
                if ($movimentacaoId <= 0 || $this->jaFoiEstornada($movimentacaoId)) {
                    continue;
                }

                $this->estoqueMovimentacaoService->estornarMovimentacao(
                    $movimentacaoId,
                    $usuarioId,
                    sprintf(
                        'Reversão da importação normalizada #%d por duplicidade.',
                        $locked->id
                    )
                );

                $movimentacoesRevertidas++;
            }

            $resumoFinal = $this->montarResumo($locked, true);
            $completamenteRevertida = ((int) ($resumoFinal['total_movimentacoes_a_estornar'] ?? 0)) === 0
                && ((int) ($resumoFinal['total_movimentacoes'] ?? 0)) > 0;

            if ($completamenteRevertida && $locked->status !== ImportacaoNormalizadaStatus::CANCELADA) {
                $this->marcarImportacaoComoCancelada($locked, $movimentacoesRevertidas);
                $locked->refresh();
                $resumoFinal['status_importacao'] = $locked->status?->value ?? $locked->status;
            }

            return [
                'sucesso' => true,
                'idempotente' => $movimentacoesRevertidas === 0,
                'mensagem' => $movimentacoesRevertidas === 0
                    ? 'Nenhuma nova movimentação precisou ser estornada.'
                    : sprintf(
                        'Importação #%d revertida com sucesso. %d movimentações foram estornadas.',
                        $locked->id,
                        $movimentacoesRevertidas
                    ),
                'movimentacoes_revertidas' => $movimentacoesRevertidas,
                'importacao' => $locked->fresh(),
                'resumo' => $resumoFinal,
            ];
        });
    }

    private function montarResumo(ImportacaoNormalizada $importacao, bool $lockRows): array
    {
        $movimentacoes = $this->carregarMovimentacoesDaImportacao($importacao->id, $lockRows);
        $estornosPorMovimentacao = $this->carregarEstornosPorMovimentacao(
            $movimentacoes->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            $lockRows
        );

        $movimentacoesJaEstornadas = $movimentacoes
            ->filter(fn (EstoqueMovimentacao $movimentacao): bool => $estornosPorMovimentacao->has((int) $movimentacao->id))
            ->values();

        $movimentacoesAEstornar = $movimentacoes
            ->reject(fn (EstoqueMovimentacao $movimentacao): bool => $estornosPorMovimentacao->has((int) $movimentacao->id))
            ->values();

        $chavesAfetadas = $this->montarChavesAfetadas($movimentacoesAEstornar);
        $saldosInsuficientes = $this->montarSaldosInsuficientes($chavesAfetadas, $lockRows);

        return [
            'importacao_id' => $importacao->id,
            'status_importacao' => $importacao->status?->value ?? $importacao->status,
            'total_movimentacoes' => $movimentacoes->count(),
            'total_quantidade' => (int) $movimentacoes->sum('quantidade'),
            'total_movimentacoes_ja_estornadas' => $movimentacoesJaEstornadas->count(),
            'total_movimentacoes_a_estornar' => $movimentacoesAEstornar->count(),
            'total_quantidade_a_estornar' => (int) $movimentacoesAEstornar->sum('quantidade'),
            'total_chaves_afetadas' => $chavesAfetadas->count(),
            'total_chaves_com_saldo_insuficiente' => $saldosInsuficientes->count(),
            'apta_para_reversao' => $saldosInsuficientes->isEmpty(),
            'chaves_afetadas' => $chavesAfetadas->values()->all(),
            'saldo_insuficiente' => $saldosInsuficientes->values()->all(),
            'movimentacoes_a_estornar' => $movimentacoesAEstornar
                ->map(fn (EstoqueMovimentacao $movimentacao): array => $this->serializarMovimentacao($movimentacao))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, EstoqueMovimentacao>
     */
    private function carregarMovimentacoesDaImportacao(int $importacaoId, bool $lockRows): Collection
    {
        $query = EstoqueMovimentacao::query()
            ->where('ref_type', self::REF_TYPE_LINHA_IMPORTACAO)
            ->whereIn('ref_id', function ($subQuery) use ($importacaoId): void {
                $subQuery->select('id')
                    ->from('importacoes_normalizadas_linhas')
                    ->where('importacao_id', $importacaoId);
            })
            ->orderByDesc('id');

        if ($lockRows) {
            $query->lockForUpdate();
        }

        return $query->get([
            'id',
            'id_variacao',
            'id_deposito_origem',
            'id_deposito_destino',
            'tipo',
            'quantidade',
            'observacao',
            'ref_id',
        ]);
    }

    /**
     * @param list<int> $movimentacaoIds
     * @return Collection<int, EstoqueMovimentacao>
     */
    private function carregarEstornosPorMovimentacao(array $movimentacaoIds, bool $lockRows): Collection
    {
        if ($movimentacaoIds === []) {
            return collect();
        }

        $query = EstoqueMovimentacao::query()
            ->where('ref_type', 'estorno')
            ->whereIn('ref_id', $movimentacaoIds);

        if ($lockRows) {
            $query->lockForUpdate();
        }

        return $query->get(['id', 'ref_id'])->keyBy(
            static fn (EstoqueMovimentacao $movimentacao): int => (int) $movimentacao->ref_id
        );
    }

    /**
     * @param Collection<int, EstoqueMovimentacao> $movimentacoes
     * @return Collection<int, array<string, int>>
     */
    private function montarChavesAfetadas(Collection $movimentacoes): Collection
    {
        return $movimentacoes
            ->filter(static fn (EstoqueMovimentacao $movimentacao): bool => $movimentacao->id_deposito_destino !== null)
            ->groupBy(
                static fn (EstoqueMovimentacao $movimentacao): string => sprintf(
                    '%d:%d',
                    (int) $movimentacao->id_variacao,
                    (int) $movimentacao->id_deposito_destino
                )
            )
            ->map(function (Collection $grupo): array {
                /** @var EstoqueMovimentacao $primeira */
                $primeira = $grupo->first();

                return [
                    'id_variacao' => (int) $primeira->id_variacao,
                    'id_deposito' => (int) $primeira->id_deposito_destino,
                    'quantidade_a_estornar' => (int) $grupo->sum('quantidade'),
                ];
            })
            ->values();
    }

    /**
     * @param Collection<int, array<string, int>> $chavesAfetadas
     * @return Collection<int, array<string, int>>
     */
    private function montarSaldosInsuficientes(Collection $chavesAfetadas, bool $lockRows): Collection
    {
        if ($chavesAfetadas->isEmpty()) {
            return collect();
        }

        $variacaoIds = $chavesAfetadas
            ->pluck('id_variacao')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $depositoIds = $chavesAfetadas
            ->pluck('id_deposito')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $query = Estoque::query()
            ->whereIn('id_variacao', $variacaoIds)
            ->whereIn('id_deposito', $depositoIds);

        if ($lockRows) {
            $query->lockForUpdate();
        }

        $estoqueAtual = $query->get(['id_variacao', 'id_deposito', 'quantidade'])
            ->keyBy(static fn (Estoque $estoque): string => sprintf(
                '%d:%d',
                (int) $estoque->id_variacao,
                (int) $estoque->id_deposito
            ));

        return $chavesAfetadas
            ->map(function (array $chave) use ($estoqueAtual): ?array {
                $key = sprintf('%d:%d', (int) $chave['id_variacao'], (int) $chave['id_deposito']);
                /** @var Estoque|null $estoque */
                $estoque = $estoqueAtual->get($key);
                $quantidadeAtual = (int) ($estoque?->quantidade ?? 0);
                $quantidadeAEstornar = (int) ($chave['quantidade_a_estornar'] ?? 0);

                if ($quantidadeAtual >= $quantidadeAEstornar) {
                    return null;
                }

                return [
                    'id_variacao' => (int) $chave['id_variacao'],
                    'id_deposito' => (int) $chave['id_deposito'],
                    'quantidade_atual' => $quantidadeAtual,
                    'quantidade_a_estornar' => $quantidadeAEstornar,
                    'deficit' => $quantidadeAEstornar - $quantidadeAtual,
                ];
            })
            ->filter()
            ->values();
    }

    private function jaFoiEstornada(int $movimentacaoId): bool
    {
        return EstoqueMovimentacao::query()
            ->where('ref_type', 'estorno')
            ->where('ref_id', $movimentacaoId)
            ->exists();
    }

    private function marcarImportacaoComoCancelada(ImportacaoNormalizada $importacao, int $movimentacoesRevertidas): void
    {
        $nota = sprintf(
            'Reversão por duplicidade executada em %s. Movimentações revertidas nesta execução: %d.',
            now()->format('Y-m-d H:i:s'),
            $movimentacoesRevertidas
        );

        $observacoes = collect([
            $importacao->observacoes,
            $nota,
        ])->filter(static fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->implode("\n");

        $importacao->forceFill([
            'status' => ImportacaoNormalizadaStatus::CANCELADA,
            'observacoes' => $observacoes,
        ])->save();
    }

    private function serializarMovimentacao(EstoqueMovimentacao $movimentacao): array
    {
        return [
            'id' => (int) $movimentacao->id,
            'id_variacao' => (int) $movimentacao->id_variacao,
            'id_deposito_origem' => $movimentacao->id_deposito_origem !== null
                ? (int) $movimentacao->id_deposito_origem
                : null,
            'id_deposito_destino' => $movimentacao->id_deposito_destino !== null
                ? (int) $movimentacao->id_deposito_destino
                : null,
            'tipo' => (string) $movimentacao->tipo,
            'quantidade' => (int) $movimentacao->quantidade,
            'ref_id' => $movimentacao->ref_id !== null ? (int) $movimentacao->ref_id : null,
            'observacao' => $movimentacao->observacao,
        ];
    }
}
