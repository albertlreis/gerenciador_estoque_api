<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'id_produto' => $this->id_produto,
            'nome' => $this->nome,
            'preco' => $this->preco,
            'custo' => $this->custo,
            'sku' => $this->sku,
            'codigo_barras' => $this->codigo_barras,
            'atributos' => ProdutoVariacaoAtributoResource::collection($this->whenLoaded('atributos')),
        ];
    }
}
