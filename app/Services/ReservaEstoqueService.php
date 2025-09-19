<?php

namespace App\Services;

use App\Models\EstoqueReserva;
use Illuminate\Support\Facades\DB;

class ReservaEstoqueService
{
    public function reservar(int $variacaoId, ?int $depositoId, int $quantidade, ?int $pedidoId = null, ?string $motivo = null): EstoqueReserva
    {
        return DB::transaction(function () use ($variacaoId, $depositoId, $quantidade, $pedidoId, $motivo) {
            return EstoqueReserva::create([
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
                'pedido_id'   => $pedidoId,
                'quantidade'  => $quantidade,
                'motivo'      => $motivo ?? 'pedido_sem_movimentacao',
            ]);
        });
    }

    public function reservasEmAbertoPorDeposito(int $variacaoId, ?int $depositoId): int
    {
        return EstoqueReserva::query()
            ->where('id_variacao', $variacaoId)
            ->when($depositoId, fn($q) => $q->where('id_deposito', $depositoId))
            ->sum('quantidade');
    }
}
