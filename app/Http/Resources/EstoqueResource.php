<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EstoqueResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,

            // mantém compatibilidade com nomes antigos (se existirem)
            'produto_variacao_id' => $this->produto_variacao_id ?? $this->id_variacao ?? null,
            'deposito_id' => $this->deposito_id ?? $this->id_deposito ?? null,

            'quantidade' => (float) ($this->quantidade ?? 0),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),

            // ✅ só monta se vier carregado (evita null/pivot)
            'variacao' => $this->whenLoaded('variacao', function () {
                $atributos = $this->variacao->relationLoaded('atributos')
                    ? $this->variacao->atributos->map(function ($a) {
                        return [
                            'atributo' => $a->atributo ?? $a->nome ?? null,
                            'valor' => $a->valor ?? null,
                        ];
                    })->values()
                    : [];

                $produto = $this->variacao->relationLoaded('produto') ? $this->variacao->produto : null;
                $categoria = ($produto && $produto->relationLoaded('categoria')) ? $produto->categoria : null;

                return [
                    'id' => $this->variacao->id,
                    'referencia' => $this->variacao->referencia,
                    'atributos' => $atributos,
                    'produto' => $produto ? [
                        'id' => $produto->id,
                        'nome' => $produto->nome,
                        'categoria' => $categoria ? [
                            'id' => $categoria->id,
                            'nome' => $categoria->nome,
                        ] : null,
                    ] : null,
                ];
            }),

            'deposito' => $this->whenLoaded('deposito', fn () => [
                'id' => $this->deposito->id,
                'nome' => $this->deposito->nome,
            ]),
        ];
    }
}
