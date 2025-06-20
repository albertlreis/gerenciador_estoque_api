<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        $preco = $this->preco;
        $promocional = $this->preco_promocional;

        return [
            'id' => $this->id,
            'produto_id' => $this->produto_id,
            'nome' => $this->nome,
            'preco' => $preco,
            'preco_promocional' => ($promocional !== null && $promocional < $preco) ? $promocional : null,
            'custo' => $this->custo,
            'referencia' => $this->referencia,
            'codigo_barras' => $this->codigo_barras,

            'estoque_total' => $this->getRelationValue('estoque')?->quantidade ?? 0,
            'estoque_outlet_total' => $this->getRelationValue('outlets')?->sum('quantidade') ?? 0,
            'outlet_restante_total' => $this->getRelationValue('outlets')?->sum('quantidade_restante') ?? 0,

            'estoque' => $this->whenLoaded('estoque', fn() => [
                'quantidade' => $this->estoque->quantidade ?? 0,
            ]),

            'outlet' => $this->whenLoaded('outlet', function () {
                return $this->outlet ? [
                    'id' => $this->outlet->id,
                    'motivo' => $this->outlet->motivo,
                    'quantidade' => $this->outlet->quantidade,
                    'quantidade_restante' => $this->outlet->quantidade_restante,
                    'percentual_desconto' => $this->outlet->percentual_desconto,
                ] : null;
            }),

            'outlets' => $this->whenLoaded('outlets', function () {
                return $this->outlets->map(function ($outlet) {
                    return [
                        'id' => $outlet->id,
                        'motivo' => $outlet->motivo,
                        'quantidade' => $outlet->quantidade,
                        'quantidade_restante' => $outlet->quantidade_restante,
                        'percentual_desconto' => $outlet->percentual_desconto,
                    ];
                });
            }),

            'atributos' => ProdutoVariacaoAtributoResource::collection($this->whenLoaded('atributos')),
        ];
    }
}

