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
            'sku' => $this->sku,
            'codigo_barras' => $this->codigo_barras,
            'atributos' => ProdutoVariacaoAtributoResource::collection($this->whenLoaded('atributos')),
        ];
    }
}
