<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoOutletResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
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
