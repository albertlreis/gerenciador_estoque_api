<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\EstoqueDisponibilidadeService;
use App\Services\EstoqueMovimentacaoService;
use App\Services\ReservaEstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PedidoEstoqueController extends Controller
{
    public function __construct(
        private readonly ReservaEstoqueService $reservas,
        private readonly EstoqueDisponibilidadeService $disp,
        private readonly EstoqueMovimentacaoService $mov,
    ) {}

    /**
     * Reserva estoque EM LOTE para todos os itens do pedido.
     */
    public function reservar(Pedido $pedido, Request $request): JsonResponse
    {
        $usuarioId = auth()->id();

        return DB::transaction(function () use ($pedido, $usuarioId) {

            $itens = $pedido->itens()->get();

            foreach ($itens as $item) {
                $depId = (int) ($item->id_deposito ?? 0);
                if (!$depId) {
                    throw ValidationException::withMessages([
                        'estoque' => ["Item {$item->id}: selecione um depósito antes de reservar."]
                    ]);
                }

                // Valida disponibilidade (saldo - reservas ativas)
                $disponivel = $this->disp->getDisponivel((int)$item->id_variacao, $depId);
                if ($disponivel < (int)$item->quantidade) {
                    throw ValidationException::withMessages([
                        'estoque' => ["Item {$item->id}: estoque insuficiente no depósito {$depId}. Disponível {$disponivel}, solicitado {$item->quantidade}."]
                    ]);
                }

                // Cria reserva vinculada ao pedido_item
                $this->reservas->reservar(
                    variacaoId: (int) $item->id_variacao,
                    depositoId: $depId,
                    quantidade: (int) $item->quantidade,
                    pedidoId: (int) $pedido->id,
                    pedidoItemId: (int) $item->id,
                    usuarioId: (int) $usuarioId,
                    motivo: 'pedido_reservado'
                );
            }

            return response()->json(['message' => 'Reserva criada com sucesso.']);
        });
    }

    /**
     * Expede EM LOTE: registra saída de estoque e consome reservas.
     */
    public function expedir(Pedido $pedido, Request $request): JsonResponse
    {
        $usuarioId = auth()->id();
        $loteId = (string) Str::uuid();

        return DB::transaction(function () use ($pedido, $usuarioId, $loteId) {

            $itens = $pedido->itens()->get();

            // 1) valida tudo antes (atomicidade)
            $erros = [];
            foreach ($itens as $item) {
                $depId = (int) ($item->id_deposito ?? 0);
                if (!$depId) {
                    $erros[] = "Item {$item->id}: selecione um depósito para expedir.";
                    continue;
                }
                $disponivel = $this->disp->getDisponivel((int)$item->id_variacao, $depId);
                if ($disponivel < (int)$item->quantidade) {
                    $erros[] = "Item {$item->id}: estoque insuficiente (dep {$depId}). Disponível {$disponivel}, solicitado {$item->quantidade}.";
                }
            }
            if ($erros) throw ValidationException::withMessages(['estoque' => $erros]);

            // 2) movimenta em lote
            foreach ($itens as $item) {
                $depId = (int) $item->id_deposito;

                $this->mov->registrarSaidaPedido(
                    variacaoId: (int) $item->id_variacao,
                    depositoSaidaId: (int) $item->id_deposito,
                    quantidade: (int) $item->quantidade,
                    usuarioId: (int) $usuarioId,
                    observacao: "Expedição Pedido #{$pedido->id}",
                    pedidoId: (int) $pedido->id,
                    pedidoItemId: (int) $item->id,
                    loteId: $loteId,
                );
            }

            return response()->json([
                'message' => 'Pedido expedido com sucesso.',
                'lote_id' => $loteId,
            ]);
        });
    }

    /**
     * Cancela reservas do pedido EM LOTE.
     */
    public function cancelarReservas(Pedido $pedido, Request $request): JsonResponse
    {
        $usuarioId = auth()->id();
        $motivo = $request->input('motivo') ?? 'pedido_cancelado';

        return DB::transaction(function () use ($pedido, $usuarioId, $motivo) {
            $this->reservas->cancelarPorPedido((int)$pedido->id, (int)$usuarioId, $motivo);
            return response()->json(['message' => 'Reservas canceladas com sucesso.']);
        });
    }
}
