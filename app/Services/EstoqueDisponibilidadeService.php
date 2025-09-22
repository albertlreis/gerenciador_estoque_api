<?php

namespace App\Services;

use App\Models\Estoque;

/**
 * Serviço de leitura de disponibilidade de estoque.
 *
 * Responsável por calcular a quantidade "realmente disponível"
 * (saldo - reservas) de uma variação em um depósito específico (ou em todos).
 */
final class EstoqueDisponibilidadeService
{
    public function __construct(
        private readonly ReservaEstoqueService $reservas
    ) {}

    /**
     * Retorna a quantidade disponível (saldo - reservas) para uma variação e depósito.
     *
     * @param  int      $variacaoId
     * @param  int|null $depositoId  Se null, soma de todos os depósitos.
     * @return int
     */
    public function getDisponivel(int $variacaoId, ?int $depositoId): int
    {
        $saldo = Estoque::query()
            ->where('id_variacao', $variacaoId)
            ->when($depositoId, fn($q) => $q->where('id_deposito', $depositoId))
            ->sum('quantidade');

        $reservado = $this->reservas->reservasEmAbertoPorDeposito($variacaoId, $depositoId);

        return (int) $saldo - (int) $reservado;
    }
}
