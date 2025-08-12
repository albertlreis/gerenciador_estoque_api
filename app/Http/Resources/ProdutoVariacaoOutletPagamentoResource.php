<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoOutletPagamentoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'forma_pagamento_id' => $this->forma_pagamento_id,
            'forma_pagamento' => $this->whenLoaded('formaPagamento', fn() => [
                'id'   => $this->formaPagamento->id,
                'slug' => $this->formaPagamento->slug,
                'nome' => $this->formaPagamento->nome,
                'max_parcelas_default' => $this->formaPagamento->max_parcelas_default,
                'percentual_desconto_default' => $this->formaPagamento->percentual_desconto_default,
            ]),
            'percentual_desconto' => (float) $this->percentual_desconto,
            'max_parcelas' => $this->max_parcelas ? (int)$this->max_parcelas : null,
        ];
    }
}
