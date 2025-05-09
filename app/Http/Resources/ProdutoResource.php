<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'id_categoria' => $this->id_categoria,
            'categoria' => $this->whenLoaded('categoria'),
            'fabricante' => $this->fabricante,
            'ativo' => $this->ativo,
            'is_outlet' => $this->is_outlet,
            'data_ultima_saida' => $this->data_ultima_saida,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
        ];
    }
}
