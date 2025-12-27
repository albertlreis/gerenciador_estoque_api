<?php

namespace App\Services;

use App\Enums\LancamentoStatus;
use App\Models\LancamentoFinanceiro;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class FinanceiroLedgerService
{
    public function criarLancamentoPorPagamento(
        string $tipo,
        string $descricao,
        float $valor,
        int $contaFinanceiraId,
        ?int $categoriaId,
        ?int $centroCustoId,
        DateTimeInterface $dataMovimento,
        Model $referencia,
        Model $pagamento
    ): LancamentoFinanceiro {

        return LancamentoFinanceiro::updateOrCreate(
            [
                'pagamento_type' => get_class($pagamento),
                'pagamento_id'   => (int) $pagamento->getKey(),
            ],
            [
                'descricao'       => $descricao,
                'tipo'            => $tipo,
                'status'          => LancamentoStatus::CONFIRMADO->value,

                'categoria_id'    => $categoriaId,
                'centro_custo_id' => $centroCustoId,
                'conta_id'        => $contaFinanceiraId,

                'valor'           => $valor,
                'data_movimento'  => $dataMovimento,
                'competencia'     => $dataMovimento->format('Y-m-01'),

                'referencia_type' => get_class($referencia),
                'referencia_id'   => (int) $referencia->getKey(),

                'created_by'      => auth()->id(),
            ]
        );
    }

    public function cancelarLancamentoPorPagamento(Model $pagamento): int
    {
        return LancamentoFinanceiro::query()
            ->where('pagamento_type', get_class($pagamento))
            ->where('pagamento_id', (int) $pagamento->getKey())
            ->where('status', '!=', LancamentoStatus::CANCELADO->value)
            ->update(['status' => LancamentoStatus::CANCELADO->value]);
    }
}
