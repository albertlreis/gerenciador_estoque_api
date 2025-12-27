<?php

namespace App\Domain\Financeiro\Contracts;

use App\Models\LancamentoFinanceiro;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

interface FinanceiroLedgerServiceContract
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
    ): LancamentoFinanceiro;

    public function cancelarLancamentoPorPagamento(Model $pagamento): int;
}
