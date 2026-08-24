<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Models\CarrinhoItem;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use Illuminate\Support\Collection;

/**
 * Criação pura de Pedido, seus itens e status.
 */
final class PedidoFactory
{
    /**
     * @param  array{id_cliente:int,id_usuario:int,id_parceiro?:int|null,data_pedido:\DateTimeInterface,valor_total:numeric,observacoes?:string|null,prazo_dias_uteis?:int} $dados
     */
    public function criarPedido(array $dados): Pedido
    {
        return Pedido::create($dados);
    }

    /**
     * Cria itens do pedido com base nos itens do carrinho.
     *
     * @param  Pedido     $pedido
     * @param  Collection $itensCarrinho
     * @return void
     */
    public function criarItens(Pedido $pedido, Collection $itensCarrinho): Collection
    {
        return $itensCarrinho->mapWithKeys(function (CarrinhoItem $item) use ($pedido) {
            $precoOriginal = $this->resolverPrecoOriginal($item);
            $precoFinal = round((float) $item->preco_unitario, 2);

            $pedidoItem = PedidoItem::create([
                'id_pedido'      => $pedido->id,
                'id_carrinho_item' => $item->id,
                'id_variacao'    => $item->id_variacao,
                'quantidade'     => $item->quantidade,
                'preco_original' => $precoOriginal,
                'preco_unitario' => $precoFinal,
                'subtotal'       => round($precoFinal * (int) $item->quantidade, 2),
                'id_deposito'    => $item->id_deposito ?? null,
                'outlet_id' => $item->outlet_id,
                'outlet_pagamento_id' => $item->outlet_pagamento_id,
                'outlet_preco_base' => $item->outlet_preco_base,
                'outlet_forma_pagamento_id' => $item->outlet_forma_pagamento_id,
                'outlet_forma_pagamento' => $item->outlet_forma_pagamento,
                'outlet_percentual_desconto' => $item->outlet_percentual_desconto,
                'outlet_max_parcelas' => $item->outlet_max_parcelas,
                'outlet_preco_final' => $item->outlet_preco_final,
            ]);

            return [(int) $item->id => $pedidoItem];
        });
    }

    /**
     * Registra um status no histórico do pedido.
     *
     * @param  Pedido       $pedido
     * @param  PedidoStatus $status
     * @param  int          $usuarioId
     * @return void
     */
    public function registrarStatus(Pedido $pedido, PedidoStatus $status, int $usuarioId): void
    {
        PedidoStatusHistorico::create([
            'pedido_id'   => $pedido->id,
            'status'      => $status,
            'data_status' => now('America/Belem'),
            'usuario_id'  => $usuarioId,
        ]);
    }

    private function resolverPrecoOriginal(CarrinhoItem $item): float
    {
        if ($item->outlet_id) {
            return round((float) $item->outlet_preco_final, 2);
        }

        $precoBase = round((float) ($item->variacao?->preco ?? 0), 2);
        $percentualOutlet = round((float) ($item->outlet?->formasPagamento?->max('percentual_desconto') ?? 0), 2);

        if ($item->outlet_id && $percentualOutlet > 0) {
            return round($precoBase * (1 - ($percentualOutlet / 100)), 2);
        }

        return $precoBase;
    }
}
