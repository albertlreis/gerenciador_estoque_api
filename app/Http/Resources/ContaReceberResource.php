<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaReceberResource extends JsonResource
{
    public function toArray($request): array
    {
        $pedido = $this->relationLoaded('pedido') ? $this->pedido : null;
        $cliente = $pedido?->relationLoaded('cliente') ? $pedido->cliente : null;

        return [
            'id'                => $this->id,
            'parcelamento_id'   => $this->parcelamento_id ? (int) $this->parcelamento_id : null,
            'parcela_numero'    => $this->parcela_numero !== null ? (int) $this->parcela_numero : null,
            'parcelas_total'    => $this->parcelas_total !== null ? (int) $this->parcelas_total : null,
            'is_entrada'        => (bool) $this->is_entrada,
            'pedido_id'         => $this->pedido_id ? (int) $this->pedido_id : null,
            'pedido_numero'     => $pedido?->numero_externo,
            'cliente_id'        => $cliente?->id ? (int) $cliente->id : null,
            'cliente_nome'      => $cliente?->nome,
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
            'parcelamento' => $this->whenLoaded('parcelamento', fn() => [
                'id' => $this->parcelamento?->id,
                'tipo' => $this->parcelamento?->tipo,
                'descricao' => $this->parcelamento?->descricao,
                'valor_total' => (float) ($this->parcelamento?->valor_total ?? 0),
                'valor_entrada' => (float) ($this->parcelamento?->valor_entrada ?? 0),
                'quantidade_parcelas' => (int) ($this->parcelamento?->quantidade_parcelas ?? 0),
            ]),
            'observacoes'       => $this->observacoes,

            'pedido' => $this->whenLoaded('pedido', function () {
                $cliente = $this->pedido?->relationLoaded('cliente') ? $this->pedido->cliente : null;

                return [
                    'id' => $this->pedido->id,
                    'numero' => $this->pedido->numero_externo ?? null,
                    'numero_externo' => $this->pedido->numero_externo ?? null,
                    'data' => optional($this->pedido->data)->format('Y-m-d'),
                    'cliente' => $cliente?->nome,
                    'cliente_id' => $cliente?->id ? (int) $cliente->id : null,
                    'cliente_nome' => $cliente?->nome,
                ];
            }),

            'pagamentos' => $this->whenLoaded('pagamentos', function () {
                return $this->pagamentos->map(fn($p) => [
                    'id' => $p->id,
                    'data_pagamento' => optional($p->data_pagamento)->format('Y-m-d'),
                    'valor' => (float) $p->valor,
                    'forma_pagamento' => $p->forma_pagamento,
                    'comprovante_path' => $p->comprovante_path,
                    'comprovante_url' => $p->comprovante_path ? \Storage::url($p->comprovante_path) : null,
                    'observacoes' => $p->observacoes,
                    'conta_financeira' => $p->relationLoaded('contaFinanceira') ? [
                        'id' => $p->contaFinanceira?->id,
                        'nome' => $p->contaFinanceira?->nome,
                    ] : null,
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
