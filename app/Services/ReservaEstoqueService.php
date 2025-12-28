<?php

namespace App\Services;

use App\Models\EstoqueReserva;
use Illuminate\Support\Facades\DB;

class ReservaEstoqueService
{
    public function reservar(
        int $variacaoId,
        ?int $depositoId,
        int $quantidade,
        ?int $pedidoId = null,
        ?int $pedidoItemId = null,
        ?int $usuarioId = null,
        ?string $motivo = null
    ): EstoqueReserva
    {
        return DB::transaction(function () use (
            $variacaoId, $depositoId, $quantidade, $pedidoId, $pedidoItemId, $usuarioId, $motivo
        ) {
            return EstoqueReserva::create([
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
                'pedido_id'   => $pedidoId,
                'pedido_item_id' => $pedidoItemId,
                'id_usuario'  => $usuarioId,
                'quantidade'  => $quantidade,
                'motivo'      => $motivo ?? 'pedido_sem_movimentacao',
                'status'      => 'ativa',
            ]);
        });
    }

    public function reservasEmAbertoPorDeposito(int $variacaoId, ?int $depositoId): int
    {
        return EstoqueReserva::query()
            ->where('id_variacao', $variacaoId)
            ->when($depositoId, fn($q) => $q->where('id_deposito', $depositoId))
            ->where('status', 'ativa')
            ->where(function ($q) {
                $q->whereNull('data_expira')
                    ->orWhere('data_expira', '>', now());
            })
            ->sum(DB::raw('GREATEST(0, quantidade - quantidade_consumida)'));
    }

    public function cancelarPorPedido(int $pedidoId, ?int $usuarioId = null, ?string $motivo = null): void
    {
        EstoqueReserva::query()
            ->where('pedido_id', $pedidoId)
            ->where('status', 'ativa')
            ->update([
                'status' => 'cancelada',
                'motivo' => $motivo ?? 'pedido_cancelado',
                'updated_at' => now(),
            ]);
    }
}
