<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ResumoEstoqueResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'totalProdutos' => (int) $this['totalProdutos'],
            'totalPecas' => (int) $this['totalPecas'],
            'totalDepositos' => (int) $this['totalDepositos'],
        ];
    }
}
