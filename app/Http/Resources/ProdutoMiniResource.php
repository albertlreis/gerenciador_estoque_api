<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource mínimo de produto para buscas rápidas/autocomplete.
 */
class ProdutoMiniResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'nome'          => $this->nome,
            'categoria'     => $this->categoria?->nome,
            'imagem'        => $this->imagemPrincipal?->url_completa,
            'variacoes'     => $this->whenLoaded('variacoes', function () {
                return $this->variacoes->map(fn($v) => [
                    'id'            => $v->id,
                    'referencia'    => $v->referencia,
                    'codigo_barras' => $v->codigo_barras,
                    'nome_completo' => $v->nome_completo,
                    'imagem_url'    => $v->imagem_url,
                ]);
            }),
        ];
    }
}
