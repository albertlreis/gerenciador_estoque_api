<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoSimplificadoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'categoria'   => $this->categoria?->nome,
            'imagem'      => $this->imagemPrincipal?->url_completa,
            'ativo'       => (bool) $this->ativo,
            'variacoes'   => $this->variacoes->map(fn($v) => [
                'id'            => $v->id,
                'referencia'    => $v->referencia,
                'codigo_barras' => $v->codigo_barras,
                'imagem_url'    => $v->imagem_url,
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
