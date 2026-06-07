<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoSimplificadoResource extends JsonResource
{
    public function toArray($request): array
    {
        $imagemPrincipalModel = $this->relationLoaded('imagemPrincipal')
            ? $this->imagemPrincipal
            : ($this->relationLoaded('imagens') ? $this->imagens->first() : null);

        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'codigo_produto' => $this->codigo_produto,
            'categoria'   => $this->categoria?->nome,
            'imagem'      => ProdutoImagem::normalizarUrlPublica($imagemPrincipalModel?->url),
            'ativo'       => (bool) $this->ativo,
            'variacoes'   => $this->variacoes->map(fn($v) => [
                'id'            => $v->id,
                'referencia'    => $v->referencia,
                'sku_interno'   => $v->sku_interno,
                'chave_variacao' => $v->chave_variacao,
                'codigo_barras' => $v->codigo_barras,
                'imagem_url'    => ProdutoImagem::normalizarUrlPublica($v->imagem?->url ?? $v->imagem_url),
                'atributos'     => $v->relationLoaded('atributos')
                    ? $v->atributos->map(fn($a) => ['atributo' => $a->atributo, 'valor' => $a->valor])
                    : [],
                'estoque' => $v->relationLoaded('estoque')
                    ? [
                        'quantidade'   => $v->estoque->quantidade ?? 0,
                        'deposito_id'  => $v->estoque->id_deposito ?? null,
                    ]
                    : null,
            ]),
        ];
    }
}
