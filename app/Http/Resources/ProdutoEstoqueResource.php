<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoEstoqueResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'produto_id' => $this->produto_id ?? $this->produto?->id,
            'produto_nome' => $this->nome_completo,
            'deposito_nome' => $this->estoque?->deposito?->nome ?? '—',
            'quantidade' => (int) $this->quantidade_estoque,
        ];
    }
}
