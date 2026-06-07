<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource mínimo de produto para buscas rápidas/autocomplete.
 */
class ProdutoMiniResource extends JsonResource
{
    public function toArray($request): array
    {
        $imagemPrincipalModel = $this->relationLoaded('imagemPrincipal')
            ? $this->imagemPrincipal
            : ($this->relationLoaded('imagens') ? $this->imagens->first() : null);

        return [
            'id'            => $this->id,
            'nome'          => $this->nome,
            'codigo_produto' => $this->codigo_produto,
            'categoria'     => $this->categoria?->nome,
            'altura'        => $this->altura !== null ? (float) $this->altura : null,
            'largura'       => $this->largura !== null ? (float) $this->largura : null,
            'profundidade'  => $this->profundidade !== null ? (float) $this->profundidade : null,
            'imagem'        => ProdutoImagem::normalizarUrlPublica($imagemPrincipalModel?->url),
            'variacoes'     => $this->whenLoaded('variacoes', function () {
                return $this->variacoes->map(fn($v) => [
                    'id'            => $v->id,
                    'referencia'    => $v->referencia,
                    'sku_interno'   => $v->sku_interno,
                    'chave_variacao' => $v->chave_variacao,
                    'codigo_barras' => $v->codigo_barras,
                    'nome_completo' => $v->nome_completo,
                    'imagem_url'    => ProdutoImagem::normalizarUrlPublica($v->imagem?->url ?? $v->imagem_url),
                    'altura'        => $v->dimensao_3,
                    'largura'       => $v->dimensao_1,
                    'profundidade'  => $v->dimensao_2,
                    'acabamento_oficial' => $v->acabamento_oficial,
                    'material_oficial' => $v->material_oficial,
                    'estoque_total' => $v->relationLoaded('estoques') ? (int) $v->estoques->sum('quantidade') : 0,
                    'atributos' => $v->relationLoaded('atributos')
                        ? $v->atributos->map(fn ($attr) => [
                            'atributo' => $attr->atributo,
                            'valor' => $attr->valor,
                        ])->values()
                        : [],
                ]);
            }),
        ];
    }
}
