<?php

namespace App\Services;

use App\Enums\PedidoStatus;
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
    public function criarItens(Pedido $pedido, Collection $itensCarrinho): void
    {
        foreach ($itensCarrinho as $item) {
            PedidoItem::create([
                'id_pedido'      => $pedido->id,
                'id_carrinho_item' => $item->id,
                'id_variacao'    => $item->id_variacao,
                'quantidade'     => $item->quantidade,
                'preco_unitario' => $item->preco_unitario,
                'subtotal'       => $item->subtotal,
                'id_deposito'    => $item->id_deposito ?? null,
            ]);
        }
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
}
