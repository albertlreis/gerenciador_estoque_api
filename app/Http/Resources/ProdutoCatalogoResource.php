<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoCatalogoResource extends JsonResource
{
    public function toArray($request): array
    {
        $imagemPrincipal = $this->relationLoaded('imagemPrincipal')
            ? ($this->imagemPrincipal?->url ?? $this->imagemPrincipal?->url_completa)
            : null;

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'codigo_produto' => $this->codigo_produto,
            'id_categoria' => $this->id_categoria,
            'id_fornecedor' => $this->id_fornecedor,
            'altura' => $this->altura,
            'largura' => $this->largura,
            'profundidade' => $this->profundidade,
            'peso' => $this->peso,
            'ativo' => (bool) $this->ativo,
            'imagem_principal' => ProdutoImagem::normalizarUrlPublica($imagemPrincipal),
            'imagens' => $this->whenLoaded('imagens', fn () => $this->imagens->map(fn ($imagem) => [
                'id' => $imagem->id,
                'url' => $imagem->url,
                'url_completa' => ProdutoImagem::normalizarUrlPublica($imagem->url ?? $imagem->url_completa),
                'principal' => (bool) $imagem->principal,
            ])->values()),
            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
        ];
    }
}
