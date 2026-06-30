<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoOutletResource extends JsonResource
{
    public function toArray($request): array
    {
        $imagemSelecionada = $this->relationLoaded('imagemSelecionada') ? $this->imagemSelecionada : null;
        $variacao = $this->relationLoaded('variacao') ? $this->variacao : null;
        $imagemUrl = ProdutoImagem::normalizarUrlPublica(
            $imagemSelecionada?->url
            ?? $variacao?->imagem?->url
            ?? $variacao?->produto?->imagemPrincipal?->url
        );

        return [
            'id' => $this->id,
            'produto_variacao_imagem_id' => $this->produto_variacao_imagem_id,
            'imagem_url' => $imagemUrl,
            'motivo'   => $this->whenLoaded('motivo', fn() => [
                'id'   => $this->motivo->id,
                'slug' => $this->motivo->slug,
                'nome' => $this->motivo->nome,
            ]),
            'quantidade' => $this->quantidade,
            'quantidade_restante' => $this->quantidade_restante,
            'percentual_desconto' => $this->formasPagamento
                ->max(fn ($fp) => $fp->percentual_desconto ?? 0),
            'formas_pagamento' => ProdutoVariacaoOutletPagamentoResource::collection(
                $this->whenLoaded('formasPagamento')
            ),
            'usuario' => $this->whenLoaded('usuario', fn() => $this->usuario?->nome),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
