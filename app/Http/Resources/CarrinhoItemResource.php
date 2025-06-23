<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $id_carrinho
 * @property int $id_variacao
 * @property int $quantidade
 * @property float $preco_unitario
 * @property float $subtotal
 * @property int|null $id_deposito
 * @property string|null $nome_produto
 * @property string|null $nome_completo
 */
class CarrinhoItemResource extends JsonResource
{
    /**
     * Transforma o recurso em um array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'id_carrinho'    => $this->id_carrinho,
            'id_variacao'    => $this->id_variacao,
            'quantidade'     => $this->quantidade,
            'preco_unitario' => $this->preco_unitario,
            'subtotal'       => $this->subtotal,
            'id_deposito'    => $this->id_deposito,
            'outlet_id'    => $this->outlet_id,
            'nome_produto'   => $this->nome_produto,
            'nome_completo'  => $this->nome_completo,
            'variacao'       => new ProdutoVariacaoResource($this->whenLoaded('variacao')),
            'deposito'       => $this->whenLoaded('deposito', fn () => [
                'id'   => $this->deposito->id,
                'nome' => $this->deposito->nome,
            ]),
        ];
    }
}
