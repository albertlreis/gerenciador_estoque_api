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
            'id_fornecedor' => $this->id_fornecedor,
            'altura' => $this->altura,
            'largura' => $this->largura,
            'profundidade' => $this->profundidade,
            'peso' => $this->peso,
            'categoria' => $this->whenLoaded('categoria'),
            'fabricante' => $this->fabricante,
            'ativo' => $this->ativo,
            'is_outlet' => $this->variacoes->contains(function ($variacao) {
                return $variacao->outlet && $variacao->outlet->quantidade_restante > 0;
            }),
            'estoque_total' => $this->estoque_total,
            'estoque_outlet_total' => $this->estoque_outlet_total,
            'imagem_principal' => $this->imagemPrincipal?->url,
            'data_ultima_saida' => $this->data_ultima_saida,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
        ];
    }
}
