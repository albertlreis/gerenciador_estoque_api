<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProdutoResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        $variacoes->each(function ($v) {
            $v->loadMissing(['outlets.formasPagamento']);
        });

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
            'motivo_desativacao' => $this->motivo_desativacao,
            'estoque_minimo' => $this->estoque_minimo,
            'is_outlet' => $variacoes->contains(fn ($v) =>
                $v->relationLoaded('outlet') && $v->outlet?->quantidade_restante > 0
            ),
            'estoque_total' => $this->getEstoqueTotalAttributeSafely(),
            'estoque_outlet_total' => $this->getEstoqueOutletTotalAttributeSafely(),
            'imagem_principal' => $this->imagemPrincipal?->url_completa,
            'data_ultima_saida' => $this->data_ultima_saida,
            'manual_conservacao' => $this->manual_conservacao
                ? Storage::url($this->manual_conservacao)
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'fornecedor' => $this->fornecedor,
            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
            'imagens' => $this->imagens->map(function ($imagem) {
                return [
                    'id' => $imagem->id,
                    'url' => $imagem->url,
                    'url_completa' => $imagem->url_completa,
                    'principal' => $imagem->principal,
                ];
            }),
        ];
    }

    private function getEstoqueTotalAttributeSafely(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();

        return (int) $variacoes->sum(function ($v) {
            $estoques = $v->getRelationValue('estoques');
            if ($estoques instanceof \Illuminate\Support\Collection) {
                return (float) $estoques->sum('quantidade');
            }

            // fallback caso exista legado "estoque" (singular)
            $estoque = $v->getRelationValue('estoque');
            return (float) ($estoque?->quantidade ?? 0);
        });
    }

    private function getEstoqueOutletTotalAttributeSafely(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        return $variacoes->sum(fn($v) => $v->getRelationValue('outlets')?->sum('quantidade') ?? 0);
    }
}
