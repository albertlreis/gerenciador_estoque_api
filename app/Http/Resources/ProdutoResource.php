<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();

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
            'ativo' => $this->ativo,
            'is_outlet' => $variacoes->contains(fn ($v) =>
                $v->relationLoaded('outlet') && $v->outlet?->quantidade_restante > 0
            ),
            'estoque_total' => $this->getEstoqueTotalAttributeSafely(),
            'estoque_outlet_total' => $this->getEstoqueOutletTotalAttributeSafely(),
            'imagem_principal' => $this->imagemPrincipal?->url,
            'data_ultima_saida' => $this->data_ultima_saida,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'fornecedor' => $this->fornecedor,
            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
        ];
    }

    private function getEstoqueTotalAttributeSafely(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        return $variacoes->sum(fn($v) => $v->getRelationValue('estoque')?->quantidade ?? 0);
    }

    private function getEstoqueOutletTotalAttributeSafely(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        return $variacoes->sum(fn($v) => $v->getRelationValue('outlets')?->sum('quantidade') ?? 0);
    }
}
