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

            // Estoque e outlet
            'estoque_total' => $this->estoque_total,
            'estoque_outlet_total' => $this->estoque_outlet_total,
            'outlet_restante_total' => $this->outlet_restante_total,

            'estoque' => [
                'quantidade' => $this->estoque->quantidade ?? 0,
            ],

            'outlet' => $this->whenLoaded('outlet', function () {
                return $this->outlet ? [
                    'id' => $this->outlet->id,
                    'motivo' => $this->outlet->motivo,
                    'quantidade' => $this->outlet->quantidade,
                    'quantidade_restante' => $this->outlet->quantidade_restante,
                    'percentual_desconto' => $this->outlet->percentual_desconto,
                ] : null;
            }),

            'atributos' => ProdutoVariacaoAtributoResource::collection($this->whenLoaded('atributos')),
        ];
    }
}
