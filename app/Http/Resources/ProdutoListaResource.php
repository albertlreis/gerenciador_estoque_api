<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoListaResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        $imagemPrincipal = $this->imagemPrincipal?->url ?? $this->imagemPrincipal?->url_completa;

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'id_categoria' => $this->id_categoria,
            'id_fornecedor' => $this->id_fornecedor,
            'altura' => $this->altura,
            'largura' => $this->largura,
            'profundidade' => $this->profundidade,
            'peso' => $this->peso,
            'ativo' => $this->ativo,
            'is_outlet' => $variacoes->contains(fn ($v) => (int) ($v->outlet_restante_total ?? 0) > 0),
            'imagem_principal' => $this->normalizarUrlImagem($imagemPrincipal),
            'variacoes' => $this->whenLoaded('variacoes', function () {
                return $this->variacoes->map(function ($v) {
                    return [
                        'id' => $v->id,
                        'produto_id' => $v->produto_id,
                        'referencia' => $v->referencia,
                        'preco' => (float) ($v->preco ?? 0),
                        'estoque_total' => (int) ($v->estoque_total ?? 0),
                        'outlet_restante_total' => (int) ($v->outlet_restante_total ?? 0),
                        'imagem_url' => $v->imagem_url,
                    ];
                })->values();
            }),
        ];
    }

    private function normalizarUrlImagem(?string $valor): ?string
    {
        return ProdutoImagem::normalizarUrlPublica($valor);
    }
}
