<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $produto_id
 * @property string $nome
 * @property float $preco
 * @property float|null $preco_promocional
 * @property float $custo
 * @property string|null $referencia
 * @property string|null $codigo_barras
 */
class ProdutoVariacaoResource extends JsonResource
{
    /**
     * Transforma o recurso em um array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $preco = $this->preco;
        $precoPromocional = $this->preco_promocional;
        $temDesconto = $precoPromocional !== null && $precoPromocional < $preco;

        return [
            'id' => $this->id,
            'produto_id' => $this->produto_id,
            'nome' => $this->nome,
            'referencia' => $this->referencia,
            'codigo_barras' => $this->codigo_barras,
            'preco' => $preco,
            'preco_promocional' => $temDesconto ? $precoPromocional : null,
            'custo' => $this->custo,

            'estoque_total' => $this->whenLoaded('estoque', fn() => $this->estoque->quantidade ?? 0),
            'estoque_outlet_total' => $this->whenLoaded('outlets', fn() => $this->outlets->sum('quantidade')),
            'outlet_restante_total' => $this->whenLoaded('outlets', fn() => $this->outlets->sum('quantidade_restante')),

            'estoque' => $this->whenLoaded('estoque', fn() => [
                'quantidade' => $this->estoque->quantidade ?? 0,
            ]),

            'outlet' => $this->whenLoaded('outlet', fn() => $this->outlet ? [
                'id' => $this->outlet->id,
                'motivo' => $this->outlet->motivo,
                'quantidade' => $this->outlet->quantidade,
                'quantidade_restante' => $this->outlet->quantidade_restante,
                'percentual_desconto' => $this->outlet->percentual_desconto,
            ] : null),

            'outlets' => $this->whenLoaded('outlets', fn() => $this->outlets->map(fn($outlet) => [
                'id' => $outlet->id,
                'motivo' => $outlet->motivo,
                'quantidade' => $outlet->quantidade,
                'quantidade_restante' => $outlet->quantidade_restante,
                'percentual_desconto' => $outlet->percentual_desconto,
            ])
            ),

            'atributos' => ProdutoVariacaoAtributoResource::collection($this->whenLoaded('atributos')),

            'produto' => $this->whenLoaded('produto', fn () => [
                'id'     => $this->produto->id,
                'nome'   => $this->produto->nome,
                'imagem' => $this->produto->imagemPrincipal?->url,
            ]),
        ];
    }
}
