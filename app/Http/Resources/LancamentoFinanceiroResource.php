<?php

namespace App\Http\Resources;

use BackedEnum;
use Illuminate\Http\Resources\Json\JsonResource;

class LancamentoFinanceiroResource extends JsonResource
{
    private function enumValue(mixed $v): ?string
    {
        if ($v instanceof BackedEnum) return $v->value;
        if ($v === null) return null;
        return (string) $v;
    }

    public function toArray($request): array
    {
        return [
            'id'              => (int) $this->id,
            'descricao'        => (string) $this->descricao,

            'tipo'             => $this->enumValue($this->tipo),
            'status'           => $this->enumValue($this->status),

            'valor'            => (string) $this->valor,
            'data_pagamento'   => optional($this->data_pagamento)->toISOString(),
            'data_movimento'   => optional($this->data_movimento)->toISOString(),
            'competencia'      => $this->competencia?->format('Y-m-d'),

            'categoria_id'     => $this->categoria_id ? (int) $this->categoria_id : null,
            'conta_id'         => $this->conta_id ? (int) $this->conta_id : null,
            'centro_custo_id'  => $this->centro_custo_id ? (int) $this->centro_custo_id : null,

            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id'   => $this->categoria->id ?? null,
                'nome' => $this->categoria->nome ?? null,
            ]),

            'conta' => $this->whenLoaded('conta', fn () => [
                'id'   => $this->conta->id ?? null,
                'nome' => $this->conta->nome ?? null,
            ]),

            'centro_custo' => $this->whenLoaded('centroCusto', fn () => [
                'id'   => $this->centroCusto->id ?? null,
                'nome' => $this->centroCusto->nome ?? null,
            ]),

            'criador' => $this->whenLoaded('criador', fn () => [
                'id'   => $this->criador->id ?? null,
                'nome' => $this->criador->nome ?? null,
            ]),

            'observacoes'      => $this->observacoes,

            'referencia_type'  => $this->referencia_type,
            'referencia_id'    => $this->referencia_id ? (int) $this->referencia_id : null,

            'pagamento_type'   => $this->pagamento_type,
            'pagamento_id'     => $this->pagamento_id ? (int) $this->pagamento_id : null,

            'created_at'       => optional($this->created_at)->toISOString(),
            'updated_at'       => optional($this->updated_at)->toISOString(),
        ];
    }
}
