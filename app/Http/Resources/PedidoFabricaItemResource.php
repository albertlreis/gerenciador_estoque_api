<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $produto_variacao_id
 * @property int $quantidade
 * @property int $quantidade_entregue
 * @property int|null $deposito_id
 * @property int|null $pedido_venda_id
 * @property string|null $observacoes
 */
class PedidoFabricaItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'produto_variacao_id'  => $this->produto_variacao_id,
            'quantidade'           => $this->quantidade,
            'quantidade_entregue'  => $this->quantidade_entregue,
            'deposito_id'          => $this->deposito_id,
            'pedido_venda_id'      => $this->pedido_venda_id,
            'observacoes'          => $this->observacoes,
            'variacao'             => [
                'id'            => $this->variacao?->id,
                'nome_completo' => $this->variacao?->nome_completo,
                'produto'       => [
                    'id'   => $this->variacao?->produto?->id,
                    'nome' => $this->variacao?->produto?->nome,
                ],
                'atributos'     => $this->variacao?->atributos?->map(fn($a) => ['nome' => $a->nome, 'valor' => $a->valor])->values() ?? [],
            ],
            'deposito'             => $this->deposito?->only(['id','nome']),
            'pedido_venda'         => [
                'id'             => $this->pedidoVenda?->id,
                'numero_externo' => $this->pedidoVenda?->numero_externo,
                'data_pedido'    => optional($this->pedidoVenda?->data_pedido)?->toDateString(),
                'cliente'        => [
                    'id'   => $this->pedidoVenda?->cliente?->id,
                    'nome' => $this->pedidoVenda?->cliente?->nome,
                ],
            ],
        ];
    }
}
