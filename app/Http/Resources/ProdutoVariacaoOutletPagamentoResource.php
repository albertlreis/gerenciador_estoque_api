<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoOutletPagamentoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'forma_pagamento' => $this->forma_pagamento,
            'percentual_desconto' => (float) $this->percentual_desconto,
            'max_parcelas' => $this->max_parcelas,
        ];
    }
}
