<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaReceberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'pedido_id'         => $this->pedido_id ? (int) $this->pedido_id : null,
            'descricao'         => $this->descricao,
            'numero_documento'  => $this->numero_documento,
            'data_emissao'      => optional($this->data_emissao)->format('Y-m-d'),
            'data_vencimento'   => optional($this->data_vencimento)->format('Y-m-d'),
            'valor_bruto'       => (float) $this->valor_bruto,
            'desconto'          => (float) $this->desconto,
            'juros'             => (float) $this->juros,
            'multa'             => (float) $this->multa,
            'valor_liquido'     => (float) $this->valor_liquido,
            'valor_recebido'    => (float) $this->valor_recebido,
            'saldo_aberto'      => (float) $this->saldo_aberto,
            'status'            => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'forma_recebimento' => $this->forma_recebimento,
            'categoria_id'      => $this->categoria_id ? (int) $this->categoria_id : null,
            'centro_custo_id'   => $this->centro_custo_id ? (int) $this->centro_custo_id : null,
            'categoria' => $this->whenLoaded('categoria', fn() => [
                'id' => $this->categoria?->id,
                'nome' => $this->categoria?->nome,
                'tipo' => $this->categoria?->tipo,
            ]),
            'centro_custo' => $this->whenLoaded('centroCusto', fn() => [
                'id' => $this->centroCusto?->id,
                'nome' => $this->centroCusto?->nome,
            ]),
            'observacoes'       => $this->observacoes,

            'pedido' => $this->whenLoaded('pedido', function () {
                return [
                    'id' => $this->pedido->id,
                    'numero' => $this->pedido->numero ?? null,
                    'data' => optional($this->pedido->data)->format('Y-m-d'),
                    'cliente' => $this->pedido->cliente->nome ?? null,
                ];
            }),

            'pagamentos' => $this->whenLoaded('pagamentos', function () {
                return $this->pagamentos->map(fn($p) => [
                    'id' => $p->id,
                    'data_pagamento' => optional($p->data_pagamento)->format('Y-m-d'),
                    'valor' => (float) $p->valor,
                    'forma_pagamento' => $p->forma_pagamento,
                    'comprovante_path' => $p->comprovante_path,
                    'usuario' => $p->relationLoaded('usuario') ? [
                        'id' => $p->usuario?->id,
                        'nome' => $p->usuario?->nome,
                    ] : null,
                ]);
            }),


            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
