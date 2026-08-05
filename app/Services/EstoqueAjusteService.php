<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstoqueAjusteService
{
    public function __construct(private readonly EstoqueMovimentacaoService $movimentacoes) {}

    /**
     * @return array{movimentacao:EstoqueMovimentacao,saldo_anterior:int,saldo_final:int,estoque_id:int}
     */
    public function ajustarSaldoFinal(
        int $variacaoId,
        int $depositoId,
        int $quantidadeFinal,
        ?int $usuarioId,
        ?string $observacao = null,
        ?string $loteId = null,
        string $refType = 'ajuste_manual',
        ?int $refId = null
    ): array {
        return DB::transaction(function () use (
            $variacaoId,
            $depositoId,
            $quantidadeFinal,
            $usuarioId,
            $observacao,
            $loteId,
            $refType,
            $refId
        ) {
            $estoque = $this->resolverEstoque($variacaoId, $depositoId);
            $quantidadeAtual = (int) $estoque->quantidade;
            $diferenca = $quantidadeFinal - $quantidadeAtual;

            if ($quantidadeFinal < 0) {
                throw ValidationException::withMessages([
                    'quantidade_final' => ['A quantidade final não pode ser negativa.'],
                ]);
            }

            if ($diferenca === 0) {
                throw ValidationException::withMessages([
                    'quantidade_final' => ['A quantidade final deve ser diferente da quantidade atual.'],
                ]);
            }

            $tipo = $diferenca > 0
                ? EstoqueMovimentacaoTipo::ENTRADA->value
                : EstoqueMovimentacaoTipo::SAIDA->value;
            $texto = "Ajuste manual de estoque. Saldo anterior: {$quantidadeAtual}. Saldo final: {$quantidadeFinal}.";
            if (trim((string) $observacao) !== '') {
                $texto .= ' '.trim((string) $observacao);
            }

            $movimentacao = $this->movimentacoes->registrarMovimentacaoManual([
                'id_variacao' => $variacaoId,
                'id_deposito_origem' => $diferenca < 0 ? $depositoId : null,
                'id_deposito_destino' => $diferenca > 0 ? $depositoId : null,
                'tipo' => $tipo,
                'quantidade' => abs($diferenca),
                'observacao' => $texto,
                'lote_id' => $loteId,
                'ref_type' => $refType,
                'ref_id' => $refId ?: (int) $estoque->id,
            ], $usuarioId);

            return [
                'movimentacao' => $movimentacao,
                'saldo_anterior' => $quantidadeAtual,
                'saldo_final' => $quantidadeFinal,
                'estoque_id' => (int) $estoque->id,
            ];
        });
    }

    private function resolverEstoque(int $variacaoId, int $depositoId): Estoque
    {
        $estoque = Estoque::query()
            ->where('id_variacao', $variacaoId)
            ->where('id_deposito', $depositoId)
            ->lockForUpdate()
            ->first();

        if ($estoque) {
            return $estoque;
        }

        try {
            return Estoque::query()->create([
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
                'quantidade' => 0,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
        }

        return Estoque::query()
            ->where('id_variacao', $variacaoId)
            ->where('id_deposito', $depositoId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
