<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoOutletResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'motivo' => $this->motivo,
            'quantidade' => $this->quantidade,
            'quantidade_restante' => $this->quantidade_restante,
            'percentual_desconto' => $this->formasPagamento
                ->max(fn ($fp) => $fp->percentual_desconto ?? 0),
            'formas_pagamento' => ProdutoVariacaoOutletPagamentoResource::collection(
                $this->whenLoaded('formasPagamento')
            ),
        ];
    }
}
