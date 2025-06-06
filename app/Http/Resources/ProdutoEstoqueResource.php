<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoEstoqueResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'produto_id' => $this->produto_id,
            'produto_nome' => $this->produto_nome,
            'deposito_nome' => $this->deposito_nome,
            'quantidade' => (int) $this->quantidade,
        ];
    }
}
