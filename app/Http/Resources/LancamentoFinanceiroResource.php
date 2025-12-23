<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LancamentoFinanceiroResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'descricao'      => $this->descricao,
            'tipo'           => $this->tipo,
            'status'         => $this->status,
            'valor'          => (string)$this->valor,

            'data_vencimento'=> optional($this->data_vencimento)->toISOString(),
            'data_pagamento' => optional($this->data_pagamento)->toISOString(),
            'competencia'    => $this->competencia?->format('Y-m-d'),

            'atrasado'       => (bool)($this->atrasado ?? false),

            'categoria' => $this->whenLoaded('categoria', function () {
                return [
                    'id'   => $this->categoria->id ?? null,
                    'nome' => $this->categoria->nome ?? null,
                ];
            }),

            'conta' => $this->whenLoaded('conta', function () {
                return [
                    'id'   => $this->conta->id ?? null,
                    'nome' => $this->conta->nome ?? null,
                ];
            }),

            'criador' => $this->whenLoaded('criador', function () {
                return [
                    'id'   => $this->criador->id ?? null,
                    'nome' => $this->criador->nome ?? null,
                ];
            }),

            'observacoes'    => $this->observacoes,

            'created_at'     => optional($this->created_at)->toISOString(),
            'updated_at'     => optional($this->updated_at)->toISOString(),
        ];
    }
}
